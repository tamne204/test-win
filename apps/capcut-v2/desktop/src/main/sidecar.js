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
  }

  /**
   * Determine path to sidecar executable or fallback python script in dev mode.
   */
  resolveSidecarTarget() {
    const isPackaged = app ? app.isPackaged : (process.mainModule && process.mainModule.filename.indexOf('app.asar') !== -1);
    const isWin = process.platform === 'win32';
    const binName = isWin ? 'autoedit-core.exe' : 'autoedit-core';

    // In development mode (unpackaged source run), always prioritize the active venv and Python scripts
    if (!isPackaged) {
      const repoRoot = path.resolve(__dirname, '../../../../..');
      const venvPythonMac = path.join(repoRoot, '.venv', 'bin', 'python3');
      const venvPythonWin = path.join(repoRoot, '.venv', 'Scripts', 'python.exe');
      const scriptPath = path.resolve(__dirname, '../../../desktop_bridge/sidecar_main.py');

      if (fs.existsSync(scriptPath)) {
        let pythonExec = 'python3';
        if (isWin && fs.existsSync(venvPythonWin)) {
          pythonExec = venvPythonWin;
        } else if (fs.existsSync(venvPythonMac)) {
          pythonExec = venvPythonMac;
        }
        return {
          command: pythonExec,
          args: [scriptPath],
          isBinary: false,
        };
      }
    }

    // 1. Packaged location (process.resourcesPath/autoedit-core/...)
    if (process.resourcesPath) {
      const packagedBin = path.join(process.resourcesPath, 'autoedit-core', binName);
      if (fs.existsSync(packagedBin)) {
        return { command: packagedBin, args: [], isBinary: true };
      }
      const packagedScript = path.join(process.resourcesPath, 'sidecar', 'desktop_bridge', 'sidecar_main.py');
      if (fs.existsSync(packagedScript)) {
        return {
          command: isWin ? 'python' : 'python3',
          args: [packagedScript],
          isBinary: false,
        };
      }
    }

    // 2. Local packaging output location
    const localDistBin = path.resolve(__dirname, '../../../packaging/dist/autoedit-core', binName);
    if (fs.existsSync(localDistBin)) {
      return { command: localDistBin, args: [], isBinary: true };
    }

    // 3. Fallback: Run Python directly with venv or system python
    const repoRoot = path.resolve(__dirname, '../../../../..');
    const venvPythonMac = path.join(repoRoot, '.venv', 'bin', 'python3');
    const venvPythonWin = path.join(repoRoot, '.venv', 'Scripts', 'python.exe');
    const scriptPath = path.resolve(__dirname, '../../../desktop_bridge/sidecar_main.py');

    let pythonExec = 'python3';
    if (isWin && fs.existsSync(venvPythonWin)) {
      pythonExec = venvPythonWin;
    } else if (fs.existsSync(venvPythonMac)) {
      pythonExec = venvPythonMac;
    }

    return {
      command: pythonExec,
      args: [scriptPath],
      isBinary: false,
    };
  }

  /**
   * Start the sidecar and perform handshake PING.
   */
  async start() {
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

    this.process = spawn(target.command, target.args, spawnOptions);

    this.process.on('error', (err) => {
      console.error('[Sidecar Process Error]', err);
    });

    this.process.on('exit', (code, signal) => {
      console.log(`[Sidecar] Exited with code=${code}, signal=${signal}`);
      this._rejectAllPending(new Error(`Sidecar exited prematurely (code: ${code}, signal: ${signal})`));
      this.process = null;
    });

    // Pipe stderr to main process console for diagnostics
    this.process.stderr.on('data', (data) => {
      process.stderr.write(`[Sidecar-Py] ${data.toString()}`);
    });

    // Set up line-by-line reading of stdout
    this.readline = readline.createInterface({
      input: this.process.stdout,
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
      console.log(`[Sidecar] Handshake success! Protocol v${pingRes.protocol}, Version: ${pingRes.version}`);
      return pingRes;
    } catch (err) {
      this.stop();
      throw new Error(`SIDECAR_START_FAILED: ${err.message}`);
    }
  }

  /**
   * Send an IPC command and await response.
   */
  send(method, params = {}, timeoutMs = 60000) {
    if (!this.process || !this.process.stdin.writable) {
      return Promise.reject(new Error('SIDECAR_NOT_RUNNING: Sidecar process is not active.'));
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
    const trimmed = line.trim();
    if (!trimmed) return;

    let msg;
    try {
      msg = JSON.parse(trimmed);
    } catch (err) {
      console.warn('[Sidecar] Failed parsing line as JSON:', trimmed);
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
    if (id && this.pendingRequests.has(id)) {
      const { resolve, reject, timer } = this.pendingRequests.get(id);
      clearTimeout(timer);
      this.pendingRequests.delete(id);

      if (msg.ok) {
        resolve(msg.result);
      } else {
        const errObj = msg.error || { code: 'UNKNOWN_ERROR', message: 'Sidecar returned false without error details' };
        const err = new Error(errObj.message || errObj.code);
        err.code = errObj.code;
        err.data = errObj.data;
        reject(err);
      }
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

    if (this.readline) {
      this.readline.close();
      this.readline = null;
    }

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
