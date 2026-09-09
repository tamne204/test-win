/**
 * apps/capcut-v2/desktop/src/main/updater/auto_update_manager.js
 * 2TOOLNE AutoEdit V2 — Desktop Auto-Update Engine
 * 
 * Provides end-to-end self-update lifecycle:
 * CHECK -> DISCOVER -> DOWNLOAD (with real progress) -> VERIFY (SHA-256) -> STAGE -> INSTALL -> RESTART
 * 
 * Production security:
 * - Semantic version-aware comparison (prevents accidental downgrades)
 * - Cryptographic SHA-256 verification (rejects tampered / corrupted artifacts)
 * - Active job locks (prevents install/restart during Pipeline Queue or Flow execution)
 * - User data preservation (userData, ~/.2toolne, Keychain outside app bundle)
 */

const { app } = require('electron');
const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const { EventEmitter } = require('events');
const { execFile } = require('child_process');
const { promisify } = require('util');
const execFileAsync = promisify(execFile);

/**
 * Compare two semver strings (e.g. "2.0.9" vs "2.0.10", "2.0.0-rc.1" vs "2.0.0")
 * Returns:
 *  -1 if v1 < v2
 *   0 if v1 == v2
 *   1 if v1 > v2
 */
function semverCompare(v1, v2) {
  const clean = (v) => String(v || '').trim().replace(/^[vV]/, '');
  const s1 = clean(v1);
  const s2 = clean(v2);

  if (s1 === s2) return 0;

  const parseParts = (s) => {
    const [main, pre] = s.split('-');
    const nums = main.split('.').map((n) => parseInt(n, 10) || 0);
    while (nums.length < 3) nums.push(0);
    return { nums, pre };
  };

  const p1 = parseParts(s1);
  const p2 = parseParts(s2);

  for (let i = 0; i < 3; i++) {
    if (p1.nums[i] > p2.nums[i]) return 1;
    if (p1.nums[i] < p2.nums[i]) return -1;
  }

  // If main versions are equal, pre-release is lower than release
  if (p1.pre && !p2.pre) return -1;
  if (!p1.pre && p2.pre) return 1;
  if (p1.pre && p2.pre) {
    return p1.pre.localeCompare(p2.pre);
  }

  return 0;
}

class AutoUpdateManager extends EventEmitter {
  constructor(options = {}) {
    super();
    this.apiBase = options.apiBase || process.env.AUTOEDIT_API_BASE || 'https://www.2tamne.site';
    this.channel = options.channel || 'stable';
    this.pipelineQueue = options.pipelineQueue || null;
    this.flowBrowserManager = options.flowBrowserManager || null;
    this.mainWindow = options.mainWindow || null;

    this.options = options;
    this.targetAppPath = options.targetAppPath || null;

    // Directories
    this.baseDir = path.join(os.homedir(), '.2toolne', 'updates');
    this.cacheDir = path.join(this.baseDir, 'cache');
    this.stagingDir = path.join(this.baseDir, 'staging');
    this.backupDir = path.join(this.baseDir, 'backup');

    // State: IDLE, CHECKING, UP_TO_DATE, UPDATE_AVAILABLE, DOWNLOADING, DOWNLOADED, INSTALL_READY, INSTALLING, ERROR
    this.state = 'IDLE';
    this.currentVersion = options.currentVersion || (app && typeof app.getVersion === 'function' ? app.getVersion() : '2.0.0');
    this.availableUpdate = null; // update manifest
    this.downloadProgress = null; // { percent, bytesPerSecond, transferred, total }
    this.downloadedPackagePath = null;
    this.lastError = null;

    this.ensureDirs();
  }

  ensureDirs() {
    try {
      fs.mkdirSync(this.cacheDir, { recursive: true, mode: 0o700 });
      fs.mkdirSync(this.stagingDir, { recursive: true, mode: 0o700 });
      fs.mkdirSync(this.backupDir, { recursive: true, mode: 0o700 });
    } catch (e) {
      console.error('[AutoUpdateManager] Error creating directories:', e);
    }
  }

  setMainWindow(win) {
    this.mainWindow = win;
  }

  setJobProviders({ pipelineQueue, flowBrowserManager } = {}) {
    if (pipelineQueue) this.pipelineQueue = pipelineQueue;
    if (flowBrowserManager) this.flowBrowserManager = flowBrowserManager;
  }

  setState(newState, extra = {}) {
    this.state = newState;
    const payload = {
      state: this.state,
      currentVersion: this.currentVersion,
      availableUpdate: this.availableUpdate,
      downloadProgress: this.downloadProgress,
      lastError: this.lastError,
      ...extra,
    };
    this.emit('status-changed', payload);
    if (this.mainWindow && !this.mainWindow.isDestroyed()) {
      this.mainWindow.webContents.send('updater:status-changed', payload);
    }
  }

  emitProgress(progress) {
    this.downloadProgress = progress;
    this.emit('progress', progress);
    if (this.mainWindow && !this.mainWindow.isDestroyed()) {
      this.mainWindow.webContents.send('updater:progress', progress);
    }
  }

  getState() {
    return {
      state: this.state,
      currentVersion: this.currentVersion,
      availableUpdate: this.availableUpdate,
      downloadProgress: this.downloadProgress,
      lastError: this.lastError,
      channel: this.channel,
      isPackaged: app ? app.isPackaged : false,
      isWorkInProgress: this.isWorkInProgress(),
    };
  }

  /**
   * Check whether any critical user job is in progress
   */
  isWorkInProgress() {
    try {
      if (this.pipelineQueue && typeof this.pipelineQueue.getActiveJobs === 'function') {
        const activeJobs = this.pipelineQueue.getActiveJobs();
        if (activeJobs && activeJobs.length > 0) return true;
      }
      if (this.flowBrowserManager && typeof this.flowBrowserManager.isAutomationRunning === 'function') {
        if (this.flowBrowserManager.isAutomationRunning()) return true;
      }
    } catch (_) {}
    return false;
  }

  /**
   * Check for updates against update provider
   */
  async checkForUpdates({ manual = false } = {}) {
    if (this.state === 'CHECKING' || this.state === 'DOWNLOADING' || this.state === 'INSTALLING') {
      return { ok: false, message: `Update already in state: ${this.state}` };
    }

    this.setState('CHECKING', { manual });
    this.lastError = null;

    try {
      const platform = process.platform;
      const arch = process.arch;
      const url = new URL('/api/v1/update/check', this.apiBase);
      url.searchParams.set('app', 'autoedit');
      url.searchParams.set('platform', platform);
      url.searchParams.set('arch', arch);
      url.searchParams.set('version', this.currentVersion);
      url.searchParams.set('channel', this.channel);

      const res = await this._httpGetJson(url.toString(), 6000);
      if (!res.ok) {
        throw new Error(res.error || `Update server returned status ${res.statusCode}`);
      }

      const data = res.data;
      const latestVersion = data.latest_version || data.version || this.currentVersion;
      const isNewer = semverCompare(this.currentVersion, latestVersion) < 0;

      if (isNewer && data.has_update !== false) {
        this.availableUpdate = {
          version: latestVersion,
          channel: data.channel || this.channel,
          mandatory: !!data.mandatory,
          releaseNotes: data.release_notes || 'Phiên bản mới với nhiều cải tiến và sửa lỗi.',
          publishedAt: data.published_at || new Date().toISOString(),
          package: data.package || {
            url: data.download_url,
            sha256: data.sha256 || null,
            sizeBytes: (data.file_size_mb || 0) * 1024 * 1024,
            filename: data.filename || `2toolne-autoedit-${latestVersion}-${platform}-${arch}.zip`,
          },
        };
        this.setState('UPDATE_AVAILABLE');
        return {
          ok: true,
          hasUpdate: true,
          currentVersion: this.currentVersion,
          latestVersion,
          update: this.availableUpdate,
        };
      } else {
        this.availableUpdate = null;
        this.setState('UP_TO_DATE');
        return {
          ok: true,
          hasUpdate: false,
          currentVersion: this.currentVersion,
          latestVersion,
        };
      }
    } catch (err) {
      this.lastError = err.message;
      this.setState('ERROR', { error: err.message });
      return { ok: false, error: err.message };
    }
  }

  /**
   * Download the discovered update package
   */
  async downloadUpdate() {
    if (!this.availableUpdate || !this.availableUpdate.package || !this.availableUpdate.package.url) {
      throw new Error('No update package available to download');
    }

    if (this.state === 'DOWNLOADING') {
      return { ok: true, message: 'Already downloading' };
    }

    this.setState('DOWNLOADING');
    this.lastError = null;

    const pkg = this.availableUpdate.package;
    const targetFilename = pkg.filename || `update-${this.availableUpdate.version}.zip`;
    const destPath = path.join(this.cacheDir, targetFilename);
    const tempPath = `${destPath}.tmp-${Date.now()}`;

    try {
      await this._downloadFileWithProgress(pkg.url, tempPath, pkg.sizeBytes);

      // Verify artifact SHA-256 checksum / hash integrity if provided
      if (pkg.sha256) {
        const computedSha = await this._computeSha256(tempPath);
        const expectedSha = pkg.sha256.toLowerCase().trim();
        if (computedSha !== expectedSha) {
          try { fs.unlinkSync(tempPath); } catch (_) {}
          throw new Error(
            `Checksum verification failed: expected ${expectedSha}, got ${computedSha}`
          );
        }
      }

      // Rename temp file to final cache destination
      if (fs.existsSync(destPath)) {
        try { fs.unlinkSync(destPath); } catch (_) {}
      }
      fs.renameSync(tempPath, destPath);

      this.downloadedPackagePath = destPath;
      this.setState('DOWNLOADED');
      return {
        ok: true,
        packagePath: destPath,
        version: this.availableUpdate.version,
      };
    } catch (err) {
      try { if (fs.existsSync(tempPath)) fs.unlinkSync(tempPath); } catch (_) {}
      this.lastError = err.message;
      this.setState('ERROR', { error: err.message });
      throw err;
    }
  }

  /**
   * Install and restart application
   */
  async installAndRelaunch() {
    if (this.isWorkInProgress()) {
      return {
        ok: false,
        busy: true,
        message: 'Đang có tác vụ dựng video hoặc Flow automation đang xử lý. Vui lòng thử lại sau.',
      };
    }

    if (!this.downloadedPackagePath || !fs.existsSync(this.downloadedPackagePath)) {
      throw new Error('No downloaded update package ready for installation');
    }

    try {
      this.setState('INSTALLING');
      const updateVersion = (this.availableUpdate && this.availableUpdate.version) ? this.availableUpdate.version : 'latest';
      const stagedExtractDir = path.join(this.stagingDir, `v${updateVersion}`);
      try {
        await execFileAsync('rm', ['-rf', stagedExtractDir]);
      } catch (_) {}
      fs.mkdirSync(stagedExtractDir, { recursive: true });

      // Unpack the downloaded zip
      await this._unzip(this.downloadedPackagePath, stagedExtractDir);

      // Perform OS-specific application update via detached or synchronous helper
      const isPackaged = !!(app && app.isPackaged);
      await this._applyUpdate(stagedExtractDir, {
        detached: isPackaged,
        relaunch: true,
      });

      this.setState('INSTALL_READY');

      // If packaged and detached helper launched, exit current process cleanly
      if (isPackaged) {
        app.exit(0);
      } else {
        console.log('[AutoUpdateManager] In dev mode: update staged successfully.');
        return { ok: true, devMode: true, stagedExtractDir };
      }

      return { ok: true };
    } catch (err) {
      this.lastError = err.message;
      this.setState('ERROR', { error: err.message });
      throw err;
    }
  }

  /**
   * Creates Windows swap helper scripts (PowerShell .ps1 and wrapper .bat)
   * Handles PID termination waiting, backup, atomic replacement, and SWAP_FAILURE_ROLLBACK.
   */
  createWindowsSwapHelper(targetDir, sourceDir, backupDir, parentPid = process.pid, relaunch = true, exeName = '2TOOLNE AutoEdit.exe', swapLogPath = null) {
    const helperPsPath = path.join(this.cacheDir, 'swap_helper.ps1');
    const helperBatPath = path.join(this.cacheDir, 'swap_helper.bat');
    if (!swapLogPath) swapLogPath = path.join(this.cacheDir, 'swap.log');

    const psScript = `# Windows 2TOOLNE AutoEdit Swap Helper
param (
  [string]$TargetDir,
  [string]$SourceDir,
  [string]$BackupDir,
  [int]$ParentPid,
  [int]$RelaunchFlag,
  [string]$ExeName,
  [string]$LogFile
)

function Write-Log($msg) {
  $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
  "[$ts] $msg" | Out-File -FilePath $LogFile -Append -Encoding utf8
  Write-Host $msg
}

Write-Log "=== 2TOOLNE AUTOEDIT WINDOWS APP SWAP STARTED ==="
Write-Log "Target:       $TargetDir"
Write-Log "Source:       $SourceDir"
Write-Log "Backup:       $BackupDir"
Write-Log "Parent PID:   $ParentPid"
Write-Log "Relaunch:     $RelaunchFlag"
Write-Log "Exe Name:     $ExeName"

# 1. Wait for Parent PID termination (handle Windows file locking)
if ($ParentPid -gt 0) {
  Write-Log "Waiting for process $ParentPid to exit..."
  $timeout = 15
  $elapsed = 0
  while ((Get-Process -Id $ParentPid -ErrorAction SilentlyContinue) -and ($elapsed -lt $timeout)) {
    Start-Sleep -Milliseconds 250
    $elapsed += 0.25
  }
  Start-Sleep -Milliseconds 500
}

# 2. Step 1: Backup current installation
Write-Log "Backing up target to $BackupDir..."
if (Test-Path $BackupDir) {
  Remove-Item -Path $BackupDir -Recurse -Force -ErrorAction SilentlyContinue
}
try {
  Copy-Item -Path $TargetDir -Destination $BackupDir -Recurse -Force
  Write-Log "Backup completed successfully."
} catch {
  Write-Log "Warning: Backup had errors: $_"
}

# 3. Step 2: Atomic Swap (Copy updated files into target)
$swapSuccess = $false
try {
  Write-Log "Swapping files from $SourceDir to $TargetDir..."
  $robocopy = "robocopy.exe"
  if (Get-Command $robocopy -ErrorAction SilentlyContinue) {
    & $robocopy "$SourceDir" "$TargetDir" /E /R:3 /W:1 /NP /NFL /NDL | Out-Null
    if ($LASTEXITCODE -le 7) {
      $swapSuccess = $true
    }
  } else {
    Copy-Item -Path "$SourceDir\\*" -Destination "$TargetDir" -Recurse -Force
    $swapSuccess = $true
  }
} catch {
  Write-Log "Swap copy failed: $_"
  $swapSuccess = $false
}

$targetExe = Join-Path $TargetDir $ExeName
if (-not (Test-Path $targetExe)) {
  Write-Log "Error: Target executable $targetExe missing after swap!"
  $swapSuccess = $false
}

# 4. Step 3: Rollback on Failure (SWAP_FAILURE_ROLLBACK)
if (-not $swapSuccess) {
  Write-Log "CRITICAL: Swap failed. Performing automatic rollback from $BackupDir (SWAP_FAILURE_ROLLBACK)..."
  if (Test-Path $BackupDir) {
    try {
      Copy-Item -Path "$BackupDir\\*" -Destination "$TargetDir" -Recurse -Force
      Write-Log "Rollback successful."
    } catch {
      Write-Log "CRITICAL: Rollback failed: $_"
    }
  }
  exit 1
}

Write-Log "Swap completed successfully."

# 5. Step 4: Relaunch
if ($RelaunchFlag -eq 1) {
  Write-Log "Relaunching $targetExe..."
  Start-Process -FilePath $targetExe
}

Write-Log "=== 2TOOLNE AUTOEDIT WINDOWS APP SWAP FINISHED ==="
exit 0
`;
    fs.writeFileSync(helperPsPath, psScript, { encoding: 'utf8' });

    const batScript = `@echo off
set TARGET_DIR=%~1
set SOURCE_DIR=%~2
set BACKUP_DIR=%~3
set PARENT_PID=%~4
set RELAUNCH_FLAG=%~5
set EXE_NAME=%~6
set LOG_FILE=%~7

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0swap_helper.ps1" -TargetDir "%TARGET_DIR%" -SourceDir "%SOURCE_DIR%" -BackupDir "%BACKUP_DIR%" -ParentPid %PARENT_PID% -RelaunchFlag %RELAUNCH_FLAG% -ExeName "%EXE_NAME%" -LogFile "%LOG_FILE%"
exit /b %ERRORLEVEL%
`;
    fs.writeFileSync(helperBatPath, batScript, { encoding: 'utf8' });

    return { helperPsPath, helperBatPath, swapLogPath };
  }

  /**
   * Apply unpacked files to application location using an independent swap helper
   */
  async _applyUpdate(stagedExtractDir, { detached = false, relaunch = true } = {}) {
    const platform = process.platform;
    if (platform === 'darwin') {
      const entries = fs.readdirSync(stagedExtractDir);
      let appBundleName = entries.find((e) => e.endsWith('.app'));
      let sourceAppPath = appBundleName ? path.join(stagedExtractDir, appBundleName) : null;

      if (!sourceAppPath && fs.existsSync(path.join(stagedExtractDir, 'Contents'))) {
        sourceAppPath = stagedExtractDir;
      }

      let targetAppPath = this.options.targetAppPath || null;
      if (!targetAppPath && app && app.isPackaged) {
        const exePath = app.getPath('exe');
        const match = exePath.match(/^(.*?\.app)/);
        if (match) targetAppPath = match[1];
      }

      if (targetAppPath && sourceAppPath && fs.existsSync(targetAppPath)) {
        const backupPath = path.join(this.backupDir, `backup-${this.currentVersion}.app`);
        const helperScriptPath = path.join(this.cacheDir, 'swap_helper.sh');
        const swapLogPath = path.join(this.cacheDir, 'swap.log');

        const scriptContent = `#!/bin/bash
# 2TOOLNE AutoEdit macOS Atomic Swap Helper with Rollback
TARGET_APP="\$1"
SOURCE_APP="\$2"
BACKUP_APP="\$3"
PARENT_PID="\$4"
RELAUNCH_FLAG="\$5"
LOG_FILE="\$6"

exec > "\$LOG_FILE" 2>&1
echo "=== 2TOOLNE AUTOEDIT ATOMIC APP SWAP STARTED ==="
echo "Timestamp: \$(date)"
echo "Target App: \$TARGET_APP"
echo "Source App: \$SOURCE_APP"
echo "Backup App: \$BACKUP_APP"
echo "Parent PID: \$PARENT_PID"

# Step 0: Wait for parent process termination to release any locks
if [ -n "\$PARENT_PID" ] && [ "\$PARENT_PID" -gt 0 ]; then
  echo "Waiting for parent PID \$PARENT_PID to terminate..."
  TIMEOUT=15
  ELAPSED=0
  while kill -0 "\$PARENT_PID" 2>/dev/null; do
    sleep 0.25
    ELAPSED=\$(echo "\$ELAPSED + 0.25" | bc 2>/dev/null || true)
    if [ \${ELAPSED%.*} -ge \$TIMEOUT ]; then
      echo "Parent process did not exit within \$TIMEOUT s. Force killing..."
      kill -9 "\$PARENT_PID" 2>/dev/null || true
      break
    fi
  done
  sleep 0.5
fi

# Step 1: Backup current installation
echo "Creating backup at \$BACKUP_APP..."
rm -rf "\$BACKUP_APP"
if cp -a "\$TARGET_APP" "\$BACKUP_APP"; then
  echo "Backup successfully created."
else
  echo "Failed to create backup. Aborting swap to prevent corruption."
  exit 1
fi

# Step 2: Atomic Swap via temporary swap directory
TEMP_SWAP="\${TARGET_APP}.update_swap.\$\$"
rm -rf "\$TEMP_SWAP"
echo "Staging new app to \$TEMP_SWAP..."
if cp -a "\$SOURCE_APP" "\$TEMP_SWAP"; then
  echo "Staging successful. Swapping directories..."
  
  # Remove old app and move new app into target location
  if rm -rf "\$TARGET_APP" && mv "\$TEMP_SWAP" "\$TARGET_APP"; then
    echo "Directory swap succeeded."
    
    # Strip quarantine attribute to allow smooth relaunch
    xattr -rd com.apple.quarantine "\$TARGET_APP" 2>/dev/null || true
    echo "Removed quarantine attributes."
  else
    echo "CRITICAL: Swap operation failed during replacement! Initiating rollback..."
    rm -rf "\$TARGET_APP" "\$TEMP_SWAP"
    if cp -a "\$BACKUP_APP" "\$TARGET_APP"; then
      echo "Rollback succeeded. Original application restored."
    else
      echo "EMERGENCY: Rollback failed! Application might be corrupted."
    fi
    exit 2
  fi
else
  echo "Failed to stage update copy. Aborting swap."
  exit 3
fi

# Step 3: Relaunch Application if requested
if [ "\$RELAUNCH_FLAG" = "1" ]; then
  echo "Relaunching updated application: \$TARGET_APP..."
  open -n "\$TARGET_APP"
fi

echo "=== 2TOOLNE AUTOEDIT ATOMIC APP SWAP FINISHED SUCCESSFULLY ==="
exit 0
`;
        fs.writeFileSync(helperScriptPath, scriptContent, { encoding: 'utf8', mode: 0o755 });

        if (detached) {
          const { spawn } = require('child_process');
          const child = spawn(
            '/bin/bash',
            [
              helperScriptPath,
              targetAppPath,
              sourceAppPath,
              backupPath,
              String(process.pid),
              relaunch ? '1' : '0',
              swapLogPath,
            ],
            {
              detached: true,
              stdio: 'ignore',
            }
          );
          child.unref();
          return { detached: true, helperScriptPath, targetAppPath, backupPath };
        } else {
          // Synchronous execution for testing
          await execFileAsync('/bin/bash', [
            helperScriptPath,
            targetAppPath,
            sourceAppPath,
            backupPath,
            '0',
            relaunch ? '1' : '0',
            swapLogPath,
          ]);
          return { detached: false, helperScriptPath, targetAppPath, backupPath };
        }
      } else {
        console.log('[AutoUpdateManager] Staged update verified at:', stagedExtractDir);
      }
    } else if (platform === 'win32') {
      console.log('[AutoUpdateManager] Staged Windows update at:', stagedExtractDir);

      let targetAppPath = this.options.targetAppPath || null;
      let exeName = '2TOOLNE AutoEdit.exe';

      if (!targetAppPath && app && app.isPackaged) {
        const exePath = app.getPath('exe');
        targetAppPath = path.dirname(exePath);
        exeName = path.basename(exePath);
      } else if (!targetAppPath && process.env.LOCALAPPDATA) {
        targetAppPath = path.join(process.env.LOCALAPPDATA, 'Programs', '2toolne-autoedit');
      }

      // Determine source app path inside stagedExtractDir
      let sourceAppPath = stagedExtractDir;
      const subEntries = fs.readdirSync(stagedExtractDir);
      const winSubDir = subEntries.find((e) => ['win-unpacked', '2toolne-autoedit', 'app'].includes(e.toLowerCase()));
      if (winSubDir && fs.statSync(path.join(stagedExtractDir, winSubDir)).isDirectory()) {
        sourceAppPath = path.join(stagedExtractDir, winSubDir);
      }

      if (targetAppPath && sourceAppPath && fs.existsSync(targetAppPath)) {
        const backupPath = path.join(this.backupDir, `backup-${this.currentVersion}`);
        const { helperPsPath, helperBatPath, swapLogPath } = this.createWindowsSwapHelper(
          targetAppPath,
          sourceAppPath,
          backupPath,
          process.pid,
          relaunch,
          exeName
        );

        if (detached) {
          const { spawn } = require('child_process');
          const child = spawn(
            'cmd.exe',
            [
              '/c',
              'start',
              '""',
              '/b',
              'powershell.exe',
              '-NoProfile',
              '-ExecutionPolicy',
              'Bypass',
              '-File',
              helperPsPath,
              '-TargetDir',
              targetAppPath,
              '-SourceDir',
              sourceAppPath,
              '-BackupDir',
              backupPath,
              '-ParentPid',
              String(process.pid),
              '-RelaunchFlag',
              relaunch ? '1' : '0',
              '-ExeName',
              exeName,
              '-LogFile',
              swapLogPath,
            ],
            {
              detached: true,
              stdio: 'ignore',
              windowsHide: true,
            }
          );
          child.unref();
          return { detached: true, helperScriptPath: helperBatPath, helperPsPath, targetAppPath, backupPath };
        } else {
          // Synchronous execution for testing
          try {
            await execFileAsync('powershell.exe', [
              '-NoProfile',
              '-ExecutionPolicy',
              'Bypass',
              '-File',
              helperPsPath,
              '-TargetDir',
              targetAppPath,
              '-SourceDir',
              sourceAppPath,
              '-BackupDir',
              backupPath,
              '-ParentPid',
              '0',
              '-RelaunchFlag',
              relaunch ? '1' : '0',
              '-ExeName',
              exeName,
              '-LogFile',
              swapLogPath,
            ]);
          } catch (e) {
            console.warn('[AutoUpdateManager] Windows sync swap executed (or mock environment):', e.message);
          }
          return { detached: false, helperScriptPath: helperBatPath, helperPsPath, targetAppPath, backupPath };
        }
      }
    }
  }

  /**
   * Unzip archive using system unzip or ditto
   */
  async _unzip(archivePath, targetDir) {
    if (process.platform === 'darwin' || process.platform === 'linux') {
      try {
        await execFileAsync('unzip', ['-q', '-o', archivePath, '-d', targetDir]);
      } catch (err) {
        if (process.platform === 'darwin') {
          await execFileAsync('ditto', ['-x', '-k', archivePath, targetDir]);
        } else {
          throw err;
        }
      }
    } else {
      const psCmd = `Expand-Archive -LiteralPath "${archivePath}" -DestinationPath "${targetDir}" -Force`;
      await execFileAsync('powershell', ['-NoProfile', '-Command', psCmd]);
    }
  }

  /**
   * Stream download with byte counter and speed calculation
   */
  _downloadFileWithProgress(fileUrl, destPath, expectedTotalBytes = 0) {
    return new Promise((resolve, reject) => {
      const urlObj = new URL(fileUrl);
      const isHttps = urlObj.protocol === 'https:';
      const client = isHttps ? https : http;

      const req = client.get(fileUrl, { timeout: 30000 }, (res) => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          return resolve(this._downloadFileWithProgress(res.headers.location, destPath, expectedTotalBytes));
        }

        if (res.statusCode !== 200) {
          return reject(new Error(`Download failed with HTTP ${res.statusCode}`));
        }

        const totalBytes = parseInt(res.headers['content-length'] || expectedTotalBytes || 0, 10);
        let transferredBytes = 0;
        let lastReportTime = Date.now();
        let lastReportBytes = 0;

        const fileStream = fs.createWriteStream(destPath);

        res.on('data', (chunk) => {
          transferredBytes += chunk.length;
          const now = Date.now();
          const elapsed = (now - lastReportTime) / 1000;

          if (elapsed >= 0.2 || transferredBytes === totalBytes) {
            const bytesPerSecond = elapsed > 0 ? Math.round((transferredBytes - lastReportBytes) / elapsed) : 0;
            const percent = totalBytes > 0 ? Math.min(100, Math.round((transferredBytes / totalBytes) * 100)) : 0;

            this.emitProgress({
              percent,
              transferred: transferredBytes,
              total: totalBytes,
              bytesPerSecond,
            });

            lastReportTime = now;
            lastReportBytes = transferredBytes;
          }
        });

        res.pipe(fileStream);

        fileStream.on('finish', () => {
          fileStream.close(() => {
            this.emitProgress({
              percent: 100,
              transferred: transferredBytes,
              total: totalBytes || transferredBytes,
              bytesPerSecond: 0,
            });
            resolve();
          });
        });

        fileStream.on('error', (err) => {
          try { fs.unlinkSync(destPath); } catch (_) {}
          reject(err);
        });
      });

      req.on('timeout', () => {
        req.destroy();
        try { fs.unlinkSync(destPath); } catch (_) {}
        reject(new Error('Download connection timed out'));
      });

      req.on('error', (err) => {
        try { fs.unlinkSync(destPath); } catch (_) {}
        reject(err);
      });
    });
  }

  /**
   * Streaming SHA-256 calculation
   */
  _computeSha256(filePath) {
    return new Promise((resolve, reject) => {
      const hash = crypto.createHash('sha256');
      const stream = fs.createReadStream(filePath);
      stream.on('data', (chunk) => hash.update(chunk));
      stream.on('end', () => resolve(hash.digest('hex').toLowerCase()));
      stream.on('error', reject);
    });
  }

  /**
   * Helper HTTP GET JSON
   */
  _httpGetJson(urlStr, timeoutMs = 5000) {
    return new Promise((resolve) => {
      try {
        const urlObj = new URL(urlStr);
        const isHttps = urlObj.protocol === 'https:';
        const client = isHttps ? https : http;

        const req = client.get(urlStr, { timeout: timeoutMs }, (res) => {
          let data = '';
          res.on('data', (chunk) => (data += chunk));
          res.on('end', () => {
            if (res.statusCode < 200 || res.statusCode >= 300) {
              return resolve({ ok: false, statusCode: res.statusCode, error: `HTTP ${res.statusCode}` });
            }
            try {
              const json = JSON.parse(data);
              resolve({ ok: true, statusCode: res.statusCode, data: json });
            } catch (e) {
              resolve({ ok: false, statusCode: res.statusCode, error: 'Invalid JSON from update server' });
            }
          });
        });

        req.on('timeout', () => {
          req.destroy();
          resolve({ ok: false, error: 'Connection timed out' });
        });

        req.on('error', (err) => {
          resolve({ ok: false, error: err.message });
        });
      } catch (err) {
        resolve({ ok: false, error: err.message });
      }
    });
  }
}

module.exports = {
  AutoUpdateManager,
  semverCompare,
};
