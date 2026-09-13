/**
 * apps/capcut-v2/desktop/src/main/crash_diagnostics.js
 *
 * Real-Time Crash Diagnostics & Process Lifecycle Monitor for 2TOOLNE Desktop.
 * Monitors:
 *   - mainWindow.webContents.on('render-process-gone')
 *   - app.on('child-process-gone')
 *
 * Classifies:
 *   - RENDERER_OOM
 *   - RENDERER_CRASH
 *   - GPU_PROCESS_CRASH
 *   - GPU_OOM
 *   - OTHER
 *
 * Features:
 *   - Structured JSON crash reporting to ~/.2toolne-autoedit/diagnostics/
 *   - Captures process memory, GPU feature status, exit code, and timestamps
 *   - Safe recovery path with infinite reload loop prevention (max 2 attempts per 60s)
 *   - Distinguishable GPU child-process crash tracking (renderer survives)
 *   - Zero secret leakage
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const { app, dialog } = require('electron');

class CrashDiagnosticsManager {
  constructor(options = {}) {
    this.diagnosticsDir = options.diagnosticsDir || path.join(os.homedir(), '.2toolne-autoedit', 'diagnostics');
    this.maxReloads = options.maxReloads || 2;
    this.reloadWindowMs = options.reloadWindowMs || 60000;
    this.reloadTimestamps = [];
    this.history = [];
    this.gpuCrashes = [];
    this.rendererCrashes = [];

    this.ensureDiagnosticsDir();
  }

  ensureDiagnosticsDir() {
    try {
      if (!fs.existsSync(this.diagnosticsDir)) {
        fs.mkdirSync(this.diagnosticsDir, { recursive: true });
      }
    } catch (err) {
      console.warn('[CrashDiagnostics] Failed to create diagnostics dir:', err.message);
    }
  }

  attach(mainWindow) {
    this.mainWindow = mainWindow;

    if (mainWindow && mainWindow.webContents && typeof mainWindow.webContents.on === 'function') {
      mainWindow.webContents.on('render-process-gone', (event, details) => {
        this.handleRenderProcessGone(event, details);
      });
    }

    // Process-wide child process monitor
    if (app && typeof app.on === 'function') {
      app.on('child-process-gone', (event, details) => {
        this.handleChildProcessGone(event, details);
      });
    }
  }

  classifyRendererCrash(details) {
    const reason = String(details?.reason || '').toLowerCase();
    if (reason === 'oom' || reason === 'out-of-memory') {
      return 'RENDERER_OOM';
    }
    if (reason === 'crashed' || reason === 'killed' || details?.exitCode !== 0) {
      return 'RENDERER_CRASH';
    }
    return 'OTHER';
  }

  classifyChildProcessCrash(details) {
    const type = String(details?.type || '').toUpperCase();
    const reason = String(details?.reason || '').toLowerCase();

    if (type === 'GPU') {
      if (reason === 'oom' || reason === 'out-of-memory') {
        return 'GPU_OOM';
      }
      return 'GPU_PROCESS_CRASH';
    }
    return 'OTHER';
  }

  captureMemorySnapshot() {
    const snapshot = {
      timestamp: new Date().toISOString(),
      main_process: process.memoryUsage ? process.memoryUsage() : {},
      system_free_bytes: os.freemem(),
      system_total_bytes: os.totalmem(),
    };
    return snapshot;
  }

  captureGpuStatus() {
    try {
      if (typeof app?.getGPUFeatureStatus === 'function') {
        return app.getGPUFeatureStatus();
      }
    } catch (e) {
      return { error: e.message };
    }
    return {};
  }

  handleRenderProcessGone(event, details) {
    const timestamp = new Date().toISOString();
    const classification = this.classifyRendererCrash(details);
    const pid = this.mainWindow?.webContents?.getOSProcessId ? this.mainWindow.webContents.getOSProcessId() : null;

    const record = {
      event_type: 'RENDER_PROCESS_GONE',
      classification,
      process_type: 'renderer',
      reason: details?.reason || 'unknown',
      exit_code: details?.exitCode ?? null,
      renderer_pid: pid,
      timestamp,
      gpu_status: this.captureGpuStatus(),
      memory_snapshot: this.captureMemorySnapshot(),
    };

    this.rendererCrashes.push(record);
    this.history.push(record);

    console.error(`[CrashDiagnostics] [CRITICAL] Render process gone! Classification: ${classification}`, record);

    const reportPath = this.writeDiagnosticReport(record);

    // Controlled Recovery / Reload Guard
    this.executeControlledRecovery(classification, reportPath);
  }

  handleChildProcessGone(event, details) {
    const timestamp = new Date().toISOString();
    const classification = this.classifyChildProcessCrash(details);

    const record = {
      event_type: 'CHILD_PROCESS_GONE',
      classification,
      process_type: details?.type || 'unknown',
      service_name: details?.serviceName || details?.name || '',
      reason: details?.reason || 'unknown',
      exit_code: details?.exitCode ?? null,
      timestamp,
      gpu_status: this.captureGpuStatus(),
      memory_snapshot: this.captureMemorySnapshot(),
      renderer_alive: Boolean(this.mainWindow && !this.mainWindow.isDestroyed() && !this.mainWindow.webContents?.isCrashed?.()),
    };

    if (details?.type === 'GPU') {
      this.gpuCrashes.push(record);
    }
    this.history.push(record);

    console.warn(`[CrashDiagnostics] Child process gone! Type: ${details?.type}, Classification: ${classification}`, record);

    this.writeDiagnosticReport(record);
  }

  writeDiagnosticReport(record) {
    try {
      this.ensureDiagnosticsDir();
      const filename = `crash_report_${Date.now()}_${record.classification.toLowerCase()}.json`;
      const fullPath = path.join(this.diagnosticsDir, filename);
      fs.writeFileSync(fullPath, JSON.stringify(record, null, 2), 'utf8');
      console.log(`[CrashDiagnostics] Saved report to: ${fullPath}`);
      return fullPath;
    } catch (err) {
      console.error('[CrashDiagnostics] Failed to write report:', err.message);
      return null;
    }
  }

  executeControlledRecovery(classification, reportPath) {
    const now = Date.now();
    this.reloadTimestamps = this.reloadTimestamps.filter((t) => now - t < this.reloadWindowMs);

    if (this.reloadTimestamps.length >= this.maxReloads) {
      console.error('[CrashDiagnostics] Max reload threshold exceeded. Aborting automatic reload to prevent loop.');
      if (this.mainWindow && !this.mainWindow.isDestroyed() && dialog && typeof dialog.showErrorBox === 'function') {
        try {
          dialog.showErrorBox(
            '2TOOLNE — Lỗi Giao Diện Nghiêm Trọng',
            `Giao diện ứng dụng bị dừng đột ngột (${classification}).\n\n` +
            `Để bảo vệ dữ liệu, ứng dụng đã dừng tải lại tự động.\n` +
            `Báo cáo sự cố đã được lưu tại:\n${reportPath || 'Thư mục diagnostics'}\n\n` +
            `Vui lòng khởi động lại ứng dụng hoặc liên hệ hỗ trợ.`
          );
        } catch (dialogErr) {}
      }
      return;
    }

    this.reloadTimestamps.push(now);
    console.log(`[CrashDiagnostics] Attempting controlled reload (${this.reloadTimestamps.length}/${this.maxReloads})...`);

    if (this.mainWindow && !this.mainWindow.isDestroyed()) {
      setTimeout(() => {
        try {
          if (this.mainWindow && !this.mainWindow.isDestroyed()) {
            this.mainWindow.reload();
          }
        } catch (reloadErr) {
          console.error('[CrashDiagnostics] Failed to reload window:', reloadErr.message);
        }
      }, 500);
    }
  }

  getLastRendererCrash() {
    return this.rendererCrashes[this.rendererCrashes.length - 1] || null;
  }

  getLastGpuCrash() {
    return this.gpuCrashes[this.gpuCrashes.length - 1] || null;
  }
}

module.exports = {
  CrashDiagnosticsManager,
};
