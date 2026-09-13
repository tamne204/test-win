/**
 * apps/capcut-v2/desktop/src/main/sidecar.js
 * Manages the Python Core Sidecar lifecycle and line-delimited JSON IPC over stdin/stdout.
 * Handles spawning, heartbeat verification, correlation ID mapping, and graceful shutdown.
 */
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const readline = require('readline');
const { randomUUID } = require('crypto');
const { app } = require('electron');

class SidecarManager {
  constructor() {
    this.process = null;
    this.readline = null;
    this.pendingRequests = new Map(); // id -> { resolve, reject, timer }
    this.notificationHandlers = []; // Array of callbacks (event, data)
    this.isShuttingDown = false;
    this._startPromise = null;
    this._handshakeResult = null;
  }

  /**
   * Determine path and launch arguments for sidecar executable or dev script.
   * Prioritizes 2toolne-core over legacy autoedit-core.
   * Enforces fail-secure check in packaged mode (prohibits loose uncompiled Python script fallback).
   * In development mode, allows development script execution if configured, with explicit warning.
   */
  resolveSidecarTarget() {
    const isPackaged = (app && typeof app.isPackaged === 'boolean')
      ? app.isPackaged
      : Boolean(process.mainModule?.filename?.includes('app.asar') || __dirname.includes('app.asar'));
    const isWin = process.platform === 'win32';
    const primaryBinName = isWin ? '2toolne-core.exe' : '2toolne-core';
    const legacyBinName = isWin ? 'autoedit-core.exe' : 'autoedit-core';
    const archDir = isWin ? 'win-x64' : (process.arch === 'arm64' ? 'mac-arm64' : 'mac-x64');

    // 0. Explicit environment variable override (for CI testing, custom runtime packaging)
    const envSidecarPath = process.env.SIDECAR_EXE_PATH || process.env.AUTOEDIT_CORE_EXE;
    if (envSidecarPath && fs.existsSync(envSidecarPath)) {
      console.log(`[Sidecar] Using explicit environment override binary: ${envSidecarPath}`);
      return {
        command: envSidecarPath,
        args: [],
        isBinary: true,
      };
    }

    // 1. Packaged production execution (STRICT: Native binary only, zero loose scripts allowed)
    if (isPackaged) {
      const packagedCandidates = process.resourcesPath ? [
        // Priority 1: 2toolne-core (Canonical primary binary)
        path.join(process.resourcesPath, '2toolne-core', archDir, primaryBinName),
        path.join(process.resourcesPath, '2toolne-core', primaryBinName),
        path.join(process.resourcesPath, 'resources', '2toolne-core', archDir, primaryBinName),
        path.join(process.resourcesPath, 'resources', '2toolne-core', primaryBinName),
        // Priority 2: 2toolne-core binary placed inside legacy folder
        path.join(process.resourcesPath, 'autoedit-core', archDir, primaryBinName),
        path.join(process.resourcesPath, 'autoedit-core', primaryBinName),
        // Priority 3: Backward compatibility fallback (autoedit-core.exe / autoedit-core)
        path.join(process.resourcesPath, 'autoedit-core', archDir, legacyBinName),
        path.join(process.resourcesPath, 'autoedit-core', legacyBinName),
        path.join(process.resourcesPath, 'resources', 'autoedit-core', archDir, legacyBinName),
        path.join(process.resourcesPath, 'resources', 'autoedit-core', legacyBinName),
      ] : [];

      for (const cand of packagedCandidates) {
        if (fs.existsSync(cand)) {
          return { command: cand, args: [], isBinary: true };
        }
      }

      // FAIL-SECURE GUARD:
      // In packaged production, missing native binary is a fatal security error.
      // Loose Python scripts must NEVER be executed in production releases.
      const checkedPaths = packagedCandidates.length > 0
        ? packagedCandidates.map(p => `  - ${p}`).join('\n')
        : '  - (process.resourcesPath not defined)';
      const err = new Error(
        `MissingNativeBinaryError: Standalone core binary ('${primaryBinName}' or legacy '${legacyBinName}') not found in packaged application resources.\n` +
        `Checked candidate paths:\n${checkedPaths}\n` +
        `Execution rejected: Fallback to uncompiled Python scripts in packaged mode is strictly forbidden.`
      );
      err.code = 'ERR_NATIVE_BINARY_MISSING';
      throw err;
    }

    // 2. Local packaging output / build staging locations (unpackaged test/staging)
    const localCandidates = [
      // Priority 1: Primary binary in packaging output
      path.resolve(__dirname, '../../../packaging/dist/2toolne-core', primaryBinName),
      path.resolve(__dirname, '../../../packaging/dist/2toolne-core.dist', primaryBinName),
      path.resolve(__dirname, '../../resources/2toolne-core', archDir, primaryBinName),
      path.resolve(__dirname, '../../resources/2toolne-core', primaryBinName),
      path.resolve(__dirname, '../../dist/win-unpacked/resources/2toolne-core', archDir, primaryBinName),
      path.resolve(__dirname, '../../dist/win-unpacked/resources/2toolne-core', primaryBinName),
      // Priority 2: Primary binary in legacy paths
      path.resolve(__dirname, '../../../packaging/dist/autoedit-core', primaryBinName),
      path.resolve(__dirname, '../../resources/autoedit-core', archDir, primaryBinName),
      // Priority 3: Legacy binary in local paths
      path.resolve(__dirname, '../../../packaging/dist/autoedit-core', legacyBinName),
      path.resolve(__dirname, '../../resources/autoedit-core', archDir, legacyBinName),
      path.resolve(__dirname, '../../resources/autoedit-core', legacyBinName),
      path.resolve(__dirname, '../../dist/win-unpacked/resources/autoedit-core', legacyBinName),
    ];

    // If forced binary testing is requested in development mode
    const forceBinary = process.env.FORCE_SIDECAR_BINARY === '1' || process.env.USE_COMPILED_SIDECAR === '1';
    if (forceBinary) {
      for (const cand of localCandidates) {
        if (fs.existsSync(cand)) {
          console.log(`[Sidecar] FORCE_SIDECAR_BINARY active. Using local compiled binary: ${cand}`);
          return { command: cand, args: [], isBinary: true };
        }
      }
      const err = new Error(
        `FATAL_CONFIG_ERROR: FORCE_SIDECAR_BINARY was requested, but no compiled sidecar binary was found in local output paths.`
      );
      err.code = 'ERR_NATIVE_BINARY_MISSING';
      throw err;
    }

    // 3. Development mode fallback (Source script execution)
    // Allowed only when not packaged (!isPackaged) and not explicitly disabled
    const allowDevScript = process.env.ALLOW_DEV_SIDECAR_SCRIPT !== '0';
    const repoRoot = path.resolve(__dirname, '../../../../..');
    const venvPythonMac = path.join(repoRoot, '.venv', 'bin', 'python3');
    const venvPythonWin = path.join(repoRoot, '.venv', 'Scripts', 'python.exe');
    const scriptPath = path.resolve(__dirname, '../../../desktop_bridge/sidecar_main.py');

    if (allowDevScript && fs.existsSync(scriptPath)) {
      console.warn(
        `[Sidecar][DEV_WARNING] Running uncompiled Python script in development mode (${scriptPath}). Standalone native binary bypassed.`
      );

      let pythonExec = isWin ? 'python' : 'python3';
      if (isWin && fs.existsSync(venvPythonWin)) {
        pythonExec = venvPythonWin;
      } else if (!isWin && fs.existsSync(venvPythonMac)) {
        pythonExec = venvPythonMac;
      }

      return {
        command: pythonExec,
        args: [scriptPath],
        isBinary: false,
      };
    }

    // 4. If script does not exist or dev script is disabled, check local compiled binaries as last resort
    for (const cand of localCandidates) {
      if (fs.existsSync(cand)) {
        console.log(`[Sidecar] Using local compiled binary: ${cand}`);
        return { command: cand, args: [], isBinary: true };
      }
    }

    const err = new Error(
      `SIDECAR_NOT_FOUND: Unable to resolve sidecar target. Neither development script (${scriptPath}) nor compiled binary ('${primaryBinName}') exists.`
    );
    err.code = 'ERR_NATIVE_BINARY_MISSING';
    throw err;
  }

  /**
   * Helper / alias method to retrieve resolved sidecar executable or script command.
   * @returns {string} Path to executable or command
   */
  getSidecarPath() {
    const target = this.resolveSidecarTarget();
    return target.command;
  }

  /**
   * Start the sidecar and perform handshake PING.
   */
  async start() {
    if (this.process && !this.process.killed && this.process.exitCode === null) {
      if (this._startPromise) {
        return this._startPromise;
      }
      return this._handshakeResult || { pong: true, protocol: 1 };
    }

    if (this._startPromise) {
      return this._startPromise;
    }

    this._startPromise = (async () => {
      this.isShuttingDown = false;
      const target = this.resolveSidecarTarget();
      console.log(`[Sidecar] Spawning sidecar: ${target.command} ${target.args.join(' ')}`);

      const v2Root = path.resolve(__dirname, '../../..');
      const repoRoot = path.resolve(__dirname, '../../../../..');

      const spawnOptions = {
        stdio: ['pipe', 'pipe', 'pipe'],
        env: {
          ...process.env,
          PYTHONUNBUFFERED: '1',
          PYTHONPATH: [
            v2Root,
            repoRoot,
            process.env.PYTHONPATH || '',
          ].filter(Boolean).join(path.delimiter),
        },
        windowsHide: true, // Prevent console window on Windows
      };

      const child = spawn(target.command, target.args, spawnOptions);
      this.process = child;

      child.on('error', (err) => {
        if (this.process === child) {
          console.error('[Sidecar Process Error]', err);
        } else {
          console.warn('[Sidecar Exited Process Error]', err);
        }
      });

      child.on('exit', (code, signal) => {
        console.log(`[Sidecar] Exited with code=${code}, signal=${signal}`);
        if (this.process === child) {
          this._rejectAllPending(new Error(`Sidecar exited prematurely (code: ${code}, signal: ${signal})`));
          this.process = null;
        }
      });

      // Pipe stderr to main process console for diagnostics
      child.stderr.on('data', (data) => {
        process.stderr.write(`[Sidecar-Py] ${data.toString()}`);
      });

      // Set up line-by-line reading of stdout
      this.readline = readline.createInterface({
        input: child.stdout,
        terminal: false,
      });

      this.readline.on('line', (line) => {
        this._handleStdoutLine(line);
      });

      // Perform handshake PING
      try {
        const pingRes = await this.send('PING', {}, 10000);
        if (!pingRes.pong) {
          throw new Error('Invalid PING response from sidecar');
        }
        this._handshakeResult = pingRes;
        console.log(`[Sidecar] Handshake success! Protocol v${pingRes.protocol}, Version: ${pingRes.version}`);
        return pingRes;
      } catch (err) {
        if (this.process === child) {
          this.stop();
        } else {
          try {
            if (process.platform === 'win32' && child && child.pid) {
              const { spawnSync } = require('child_process');
              spawnSync('taskkill', ['/pid', String(child.pid), '/f', '/t'], { windowsHide: true });
            }
            child.kill('SIGTERM');
          } catch (e) {}
        }
        throw new Error(`SIDECAR_START_FAILED: ${err.message}`);
      }
    })().finally(() => {
      this._startPromise = null;
    });

    return this._startPromise;
  }

  /**
   * Send an IPC command and await response.
   */
  async send(method, params = {}, timeoutMs = 60000) {
    if (!this.process || !this.process.stdin.writable) {
      if (this._isRestarting) {
        return Promise.reject(new Error('SIDECAR_NOT_RUNNING: Sidecar restart in progress.'));
      }
      try {
        console.log(`[Sidecar] Process not active for method ${method}, auto-restarting sidecar...`);
        this._isRestarting = true;
        await this.start();
        this._isRestarting = false;
      } catch (restartErr) {
        this._isRestarting = false;
        return Promise.reject(new Error(`SIDECAR_NOT_RUNNING: Sidecar process is not active (${restartErr.message}).`));
      }
    }

    const id = randomUUID();
    const payload = {
      id,
      protocol: 1,
      method: method.toUpperCase(),
      params,
    };

    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pendingRequests.delete(id);
        reject(new Error(`SIDECAR_TIMEOUT: Request ${method} timed out after ${timeoutMs}ms`));
      }, timeoutMs);

      this.pendingRequests.set(id, { resolve, reject, timer, method });

      const line = JSON.stringify(payload) + '\n';
      this.process.stdin.write(line, (err) => {
        if (err) {
          clearTimeout(timer);
          this.pendingRequests.delete(id);
          reject(err);
        }
      });
    });
  }

  /**
   * Handle incoming line from sidecar stdout.
   */
  _handleStdoutLine(line) {
    try {
      if (typeof line !== 'string') return;
      const trimmed = line.trim();
      if (!trimmed) return;

      let msg;
      try {
        msg = JSON.parse(trimmed);
      } catch (err) {
        console.warn('[Sidecar] Failed parsing line as JSON:', trimmed);
        return;
      }

      // Defensive guard: reject null, primitives (number, string, boolean), and arrays
      if (!msg || typeof msg !== 'object' || Array.isArray(msg)) {
        return;
      }

      // Check if notification
      if (msg.type === 'notification') {
        for (const handler of this.notificationHandlers) {
          try {
            handler(msg.event, msg.data);
          } catch (e) {
            console.error('[Notification Handler Error]', e);
          }
        }
        return;
      }

      // Check if response
      const id = msg.id;
      if (id !== undefined && id !== null && this.pendingRequests.has(id)) {
        const { resolve, reject, timer } = this.pendingRequests.get(id);
        clearTimeout(timer);
        this.pendingRequests.delete(id);

        if (msg.ok) {
          resolve(msg.result);
        } else {
          const errObj = (msg.error && typeof msg.error === 'object')
            ? msg.error
            : { code: 'UNKNOWN_ERROR', message: String(msg.error || 'Sidecar returned false without error details') };
          const err = new Error(errObj.message || errObj.code);
          err.code = errObj.code;
          err.data = errObj.data;
          reject(err);
        }
      }
    } catch (unexpectedErr) {
      console.error('[Sidecar] Unexpected error processing stdout line:', unexpectedErr);
    }
  }

  onNotification(callback) {
    this.notificationHandlers.push(callback);
  }

  _rejectAllPending(err) {
    for (const [id, req] of this.pendingRequests.entries()) {
      clearTimeout(req.timer);
      req.reject(err);
    }
    this.pendingRequests.clear();
  }

  /**
   * Gracefully terminate the sidecar process.
   */
  stop() {
    if (this.isShuttingDown) return;
    this.isShuttingDown = true;
    console.log('[Sidecar] Stopping sidecar process...');

    this._handshakeResult = null;

    if (this.readline) {
      this.readline.close();
      this.readline = null;
    }

    this._rejectAllPending(new Error('Sidecar process stopped'));

    if (this.process) {
      try {
        if (this.process.stdin && this.process.stdin.writable) {
          this.process.stdin.end(); // triggers EOF
        }
      } catch (e) {}

      const proc = this.process;
      const killTimer = setTimeout(() => {
        try {
          if (!proc.killed) {
            if (process.platform === 'win32' && proc && proc.pid) {
              try {
                const { spawnSync } = require('child_process');
                spawnSync('taskkill', ['/pid', String(proc.pid), '/f', '/t'], { windowsHide: true });
              } catch (e) {}
            }
            proc.kill('SIGKILL');
          }
        } catch (e) {}
      }, 2000);
      if (typeof killTimer.unref === 'function') {
        killTimer.unref();
      }

      try {
        proc.kill('SIGTERM');
      } catch (e) {}

      proc.on('exit', () => {
        clearTimeout(killTimer);
      });
      this.process = null;
    }
  }
}

module.exports = { SidecarManager };
