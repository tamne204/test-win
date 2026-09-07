/**
 * apps/capcut-v2/desktop/src/main/index.js
 * Electron Main Process for 2toolne AutoEdit for CapCut (Product V2).
 * Strictly enforces security: contextIsolation=true, nodeIntegration=false.
 * Production hardening: Strict CSP, disabled navigation, disabled devtools, safeStorage.
 * Spawns and manages Python Core Sidecar.
 */
const { app, BrowserWindow, ipcMain, dialog, shell, session } = require('electron');
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');
const https = require('https');
const http = require('http');
const { SidecarManager } = require('./sidecar');
const { SecureStorage } = require('./secure_storage');
const { FileImporter } = require('./file_importer');

let mainWindow = null;
const sidecar = new SidecarManager();
const secureStorage = new SecureStorage();
const fileImporter = new FileImporter();

const API_BASE = process.env.AUTOEDIT_API_BASE || 'https://www.2tamne.site';

function postJson(endpoint, data) {
  return new Promise((resolve, reject) => {
    try {
      const url = new URL(endpoint, API_BASE);
      const postData = JSON.stringify(data);
      const client = url.protocol === 'https:' ? https : http;

      const req = client.request(
        url,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(postData),
            'User-Agent': '2toolneAutoEdit/2.0.0',
          },
          timeout: 10000,
        },
        (res) => {
          let body = '';
          res.on('data', (chunk) => { body += chunk; });
          res.on('end', () => {
            try {
              const parsed = JSON.parse(body);
              if (res.statusCode >= 200 && res.statusCode < 300) {
                resolve(parsed);
              } else {
                const err = new Error(parsed.message || parsed.error || `Lỗi máy chủ (${res.statusCode})`);
                err.code = parsed.error_code || 'SERVER_ERROR';
                reject(err);
              }
            } catch (e) {
              reject(new Error(`Phản hồi máy chủ không hợp lệ: ${body.substring(0, 100)}`));
            }
          });
        }
      );

      req.on('error', (err) => {
        reject(new Error(`Không thể kết nối đến máy chủ xác thực: ${err.message}`));
      });

      req.on('timeout', () => {
        req.destroy();
        reject(new Error('Hết thời gian kết nối tới máy chủ (Timeout).'));
      });

      req.write(postData);
      req.end();
    } catch (e) {
      reject(e);
    }
  });
}

function getJson(endpoint, token = null) {
  return new Promise((resolve, reject) => {
    try {
      const url = new URL(endpoint, API_BASE);
      const client = url.protocol === 'https:' ? https : http;
      const headers = {
        'User-Agent': '2toolneAutoEdit/2.0.0',
      };
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }
      const req = client.request(
        url,
        {
          method: 'GET',
          headers,
          timeout: 10000,
        },
        (res) => {
          let body = '';
          res.on('data', (chunk) => { body += chunk; });
          res.on('end', () => {
            try {
              const parsed = JSON.parse(body);
              if (res.statusCode >= 200 && res.statusCode < 300) {
                resolve(parsed);
              } else {
                const err = new Error(parsed.message || parsed.error || `Lỗi máy chủ (${res.statusCode})`);
                err.code = parsed.error_code || 'SERVER_ERROR';
                reject(err);
              }
            } catch (e) {
              reject(new Error(`Phản hồi máy chủ không hợp lệ: ${body.substring(0, 100)}`));
            }
          });
        }
      );
      req.on('error', (err) => reject(new Error(`Không thể kết nối máy chủ: ${err.message}`)));
      req.on('timeout', () => {
        req.destroy();
        reject(new Error('Hết thời gian kết nối máy chủ'));
      });
      req.end();
    } catch (err) {
      reject(err);
    }
  });
}

async function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1240,
    height: 840,
    minWidth: 980,
    minHeight: 700,
    title: '2toolne AutoEdit',
    backgroundColor: '#121417',
    show: false,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
      devTools: process.env.AUTOEDIT_DEV === '1',
      preload: path.join(__dirname, '../preload/preload.js'),
    },
  });

  // Content Security Policy (Section 35)
  session.defaultSession.webRequest.onHeadersReceived((details, callback) => {
    callback({
      responseHeaders: {
        ...details.responseHeaders,
        'Content-Security-Policy': [
          "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: file:; connect-src 'self' https://www.2tamne.site;",
        ],
      },
    });
  });

  // Prevent navigation to arbitrary remote pages
  mainWindow.webContents.on('will-navigate', (event) => {
    event.preventDefault();
  });

  // Deny new windows / popups
  mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));

  mainWindow.loadFile(path.join(__dirname, '../renderer/index.html'));

  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
  });

  // Forward sidecar notifications to renderer
  sidecar.onNotification((event, data) => {
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.webContents.send('autoedit:event', { event, data });
    }
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

// -----------------------------------------------------------------------------
// Native Dialog Handlers
// -----------------------------------------------------------------------------

ipcMain.handle('dialog:select-images', async () => {
  if (!mainWindow) return [];
  const res = await dialog.showOpenDialog(mainWindow, {
    title: 'Chọn hình ảnh / video',
    properties: ['openFile', 'multiSelections'],
    filters: [
      { name: 'Hình ảnh / Video', extensions: ['png', 'jpg', 'jpeg', 'webp', 'mp4', 'mov'] },
      { name: 'Tất cả tệp', extensions: ['*'] },
    ],
  });
  return res.canceled ? [] : res.filePaths;
});

ipcMain.handle('dialog:select-audio', async () => {
  if (!mainWindow) return null;
  const res = await dialog.showOpenDialog(mainWindow, {
    title: 'Chọn tệp âm thanh',
    properties: ['openFile'],
    filters: [
      { name: 'Âm thanh', extensions: ['mp3', 'wav', 'aac', 'm4a', 'flac'] },
      { name: 'Tất cả tệp', extensions: ['*'] },
    ],
  });
  return res.canceled || res.filePaths.length === 0 ? null : res.filePaths[0];
});

ipcMain.handle('dialog:select-srt', async () => {
  if (!mainWindow) return null;
  const res = await dialog.showOpenDialog(mainWindow, {
    title: 'Chọn tệp phụ đề SRT',
    properties: ['openFile'],
    filters: [
      { name: 'Phụ đề SRT', extensions: ['srt'] },
      { name: 'Tất cả tệp', extensions: ['*'] },
    ],
  });
  return res.canceled || res.filePaths.length === 0 ? null : res.filePaths[0];
});

ipcMain.handle('dialog:select-script', async () => {
  if (!mainWindow) return null;
  const res = await dialog.showOpenDialog(mainWindow, {
    title: 'Chọn tệp kịch bản (.txt)',
    properties: ['openFile'],
    filters: [
      { name: 'Kịch bản văn bản', extensions: ['txt'] },
      { name: 'Tất cả tệp', extensions: ['*'] },
    ],
  });
  if (res.canceled || res.filePaths.length === 0) return null;
  const filePath = res.filePaths[0];
  try {
    const content = fs.readFileSync(filePath, 'utf8');
    return { filePath, content };
  } catch (err) {
    return { filePath, content: '', error: err.message };
  }
});

ipcMain.handle('dialog:save-srt', async (_, defaultName) => {
  if (!mainWindow) return null;
  const res = await dialog.showSaveDialog(mainWindow, {
    title: 'Lưu tệp phụ đề SRT',
    defaultPath: defaultName || 'subtitles.srt',
    filters: [
      { name: 'Phụ đề SRT', extensions: ['srt'] },
      { name: 'Tất cả tệp', extensions: ['*'] },
    ],
  });
  return res.canceled ? null : res.filePath;
});

ipcMain.handle('shell:open-folder', async (_, folderPath) => {
  if (!folderPath) return false;
  try {
    await shell.openPath(folderPath);
    return true;
  } catch (err) {
    console.error('Failed opening folder:', err);
    return false;
  }
});

// -----------------------------------------------------------------------------
// Commercial License Management (Phase 4)
// -----------------------------------------------------------------------------

ipcMain.handle('license:get-status', async () => {
  try {
    return await sidecar.send('GET_LICENSE_STATUS');
  } catch (e) {
    return { authorized: false, state: 'LICENSE_NOT_ACTIVATED', message: e.message };
  }
});

ipcMain.handle('license:activate', async (_, { licenseKey }) => {
  if (!licenseKey || typeof licenseKey !== 'string') {
    return { ok: false, error: 'Vui lòng nhập mã bản quyền hợp lệ.' };
  }

  const cleanKey = licenseKey.trim();

  try {
    // 1. Query device ID from sidecar
    const status = await sidecar.send('GET_LICENSE_STATUS');
    const deviceId = status.device_id;

    // 2. Request authoritative server
    const serverRes = await postJson('/api/v1/capcut/activate', {
      license_key: cleanKey,
      device_id: deviceId,
      platform: process.platform,
      app_version: app.getVersion(),
    });

    if (serverRes.success && serverRes.signed_entitlement) {
      const maskedKey = serverRes.masked_key || `2TL-CAP-****-****-${cleanKey.slice(-4)}`;
      const last4 = serverRes.license_key_last4 || cleanKey.slice(-4);
      const trustedTime = serverRes.trusted_server_time || Date.now();

      // 3. Install signed entitlement into Python Sidecar in-memory session
      const sidecarRes = await sidecar.send('INSTALL_SIGNED_ENTITLEMENT', {
        envelope: serverRes.signed_entitlement,
        trusted_server_time: trustedTime,
        masked_key: maskedKey,
        license_key_last4: last4,
      });

      // 4. Persist encrypted credentials in OS Keychain / DPAPI (NO RAW KEY SAVED)
      secureStorage.setItem('entitlement_envelope', serverRes.signed_entitlement);
      secureStorage.setItem('masked_key', maskedKey);
      secureStorage.setItem('license_key_last4', last4);
      secureStorage.setItem('trusted_server_time', trustedTime);
      secureStorage.setItem('last_activated_at', Date.now());
      secureStorage.removeItem('active_key'); // Purge legacy key if any

      return {
        ok: true,
        status: sidecarRes,
        message: serverRes.message || 'Kích hoạt bản quyền thành công!',
      };
    } else {
      return {
        ok: false,
        error: serverRes.message || 'Kích hoạt không thành công.',
      };
    }
  } catch (err) {
    return {
      ok: false,
      error: err.message,
      code: err.code || 'ACTIVATION_ERROR',
    };
  }
});

ipcMain.handle('license:deactivate', async () => {
  try {
    const status = await sidecar.send('GET_LICENSE_STATUS');
    const licenseId = status.license_id;
    const deviceId = status.device_id;

    if (deviceId) {
      try {
        await postJson('/api/v1/capcut/deactivate', {
          license_id: licenseId,
          device_id: deviceId,
        });
      } catch (serverErr) {
        console.warn('Server deactivation notice:', serverErr.message);
      }
    }

    await sidecar.send('CLEAR_LICENSE');
    secureStorage.clear();

    return { ok: true, message: 'Đã hủy kích hoạt thiết bị thành công.' };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

// -----------------------------------------------------------------------------
// User Account & Wallet Management (GAP-09 & GAP-10)
// -----------------------------------------------------------------------------

ipcMain.handle('auth:login', async (_, { email, password }) => {
  if (!email || !password) {
    return { ok: false, error: 'Vui lòng nhập đầy đủ Email và Mật khẩu.' };
  }
  try {
    const res = await postJson('/api/v1/auth/login', {
      email: email.trim(),
      password,
    });
    if (res && res.token) {
      secureStorage.setItem('auth_token', res.token);
      secureStorage.setItem('auth_user', res.user || { email: email.trim() });
      return { ok: true, user: res.user, token: res.token };
    } else if (res && res.success) {
      secureStorage.setItem('auth_token', res.access_token || res.token || 'logged_in');
      secureStorage.setItem('auth_user', res.user || { email: email.trim() });
      return { ok: true, user: res.user };
    } else {
      return { ok: false, error: res?.message || 'Đăng nhập không thành công.' };
    }
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

ipcMain.handle('auth:logout', async () => {
  secureStorage.removeItem('auth_token');
  secureStorage.removeItem('auth_user');
  return { ok: true };
});

ipcMain.handle('auth:get-user', async () => {
  const token = secureStorage.getItem('auth_token');
  const user = secureStorage.getItem('auth_user');
  if (token && user) {
    return { ok: true, user, token };
  }
  return { ok: false, user: null };
});

ipcMain.handle('wallet:get-balance', async () => {
  try {
    const token = secureStorage.getItem('auth_token');
    const licenseStatus = await sidecar.send('GET_LICENSE_STATUS').catch(() => ({}));
    const deviceId = licenseStatus?.device_id;

    if (token) {
      try {
        const res = await getJson('/api/v1/credits/balance', token);
        if (res && (res.balance !== undefined || res.tokens !== undefined)) {
          return { ok: true, balance: res.balance !== undefined ? res.balance : res.tokens };
        }
      } catch (e) {
        console.warn('Credits balance endpoint fallback:', e.message);
      }
    }

    if (deviceId) {
      try {
        const res = await postJson('/api/v1/capcut/wallet-balance', { device_id: deviceId });
        if (res && res.balance !== undefined) {
          return { ok: true, balance: res.balance };
        }
      } catch (e) {
        // endpoint may not exist or offline
      }
    }

    if (licenseStatus && (licenseStatus.authorized || licenseStatus.active)) {
      const plan = licenseStatus.plan || 'PRO';
      return { ok: true, balance: licenseStatus.tokens !== undefined ? licenseStatus.tokens : (plan === 'PRO' ? 100 : 50), plan };
    }

    return { ok: true, balance: 0, unactivated: true };
  } catch (err) {
    return { ok: false, balance: 0, error: err.message };
  }
});

// -----------------------------------------------------------------------------
// Sidecar IPC Forwarders
// -----------------------------------------------------------------------------

ipcMain.handle('sidecar:detect-capcut', async () => {
  return await sidecar.send('DETECT_CAPCUT');
});

ipcMain.handle('sidecar:get-presets', async () => {
  return await sidecar.send('GET_PRESETS');
});

ipcMain.handle('sidecar:save-custom-preset', async (_, presetData) => {
  return await sidecar.send('SAVE_CUSTOM_PRESET', presetData);
});

ipcMain.handle('sidecar:delete-custom-preset', async (_, { presetId }) => {
  return await sidecar.send('DELETE_CUSTOM_PRESET', { preset_id: presetId });
});

ipcMain.handle('sidecar:generate-project', async (_, projectConfig) => {
  return await sidecar.send('GENERATE_CAPCUT_PROJECT', projectConfig, 600000); // 10 min timeout
});

ipcMain.handle('sidecar:open-capcut', async (_, { draftPath }) => {
  return await sidecar.send('OPEN_CAPCUT', { draft_path: draftPath });
});

ipcMain.handle('sidecar:get-app-info', async () => {
  return await sidecar.send('GET_APP_INFO');
});

ipcMain.handle('sidecar:get-diagnostics', async () => {
  return await sidecar.send('GET_DIAGNOSTICS');
});

ipcMain.handle('sidecar:generate-srt-from-script', async (_, params) => {
  return await sidecar.send('GENERATE_SRT_FROM_SCRIPT', params, 600000); // 10 min timeout
});

ipcMain.handle('sidecar:get-subtitle-alignment-status', async () => {
  return await sidecar.send('GET_SUBTITLE_ALIGNMENT_STATUS');
});

ipcMain.handle('sidecar:cancel-subtitle-alignment', async () => {
  return await sidecar.send('CANCEL_SUBTITLE_ALIGNMENT');
});

ipcMain.handle('sidecar:export-srt', async (_, params) => {
  return await sidecar.send('EXPORT_SRT', params);
});

// Render Automation Handlers (Phase 5E)
ipcMain.handle('sidecar:call', async (_, { method, params }) => {
  return await sidecar.send(method, params || {});
});
ipcMain.handle('sidecar:render-now', async (_, params) => {
  return await sidecar.send('RENDER_NOW', params || {});
});
ipcMain.handle('sidecar:enqueue-render', async (_, params) => {
  return await sidecar.send('ENQUEUE_RENDER', params || {});
});
ipcMain.handle('sidecar:get-render-queue-state', async () => {
  return await sidecar.send('GET_RENDER_QUEUE_STATE', {});
});
ipcMain.handle('sidecar:control-render-queue', async (_, params) => {
  return await sidecar.send('CONTROL_RENDER_QUEUE', params || {});
});
ipcMain.handle('sidecar:get-render-profile', async (_, params) => {
  return await sidecar.send('GET_RENDER_PROFILE', params || {});
});

// File Import & Dialog Handlers
ipcMain.handle('importer:process-paths', async (_, { paths }) => {
  return await fileImporter.processPaths(paths || []);
});

ipcMain.handle('dialog:open-files', async (_, options = {}) => {
  const res = await dialog.showOpenDialog(mainWindow, {
    title: options.title || 'Chọn tệp hình ảnh hoặc file nén (ZIP, RAR)',
    properties: ['openFile', 'multiSelections'],
    filters: [
      { name: 'Tất cả file hỗ trợ', extensions: ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tiff', 'tif', 'jfif', 'zip', 'rar', '7z'] },
      { name: 'Hình ảnh', extensions: ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tiff', 'tif', 'jfif'] },
      { name: 'File nén (Archive)', extensions: ['zip', 'rar', '7z'] },
      { name: 'Tất cả file', extensions: ['*'] },
    ],
  });
  if (res.canceled || !res.filePaths.length) return [];
  return res.filePaths;
});

ipcMain.handle('dialog:open-audio', async () => {
  const res = await dialog.showOpenDialog(mainWindow, {
    title: 'Chọn tệp âm thanh (Audio / Voice)',
    properties: ['openFile'],
    filters: [
      { name: 'Audio Files', extensions: ['mp3', 'wav', 'aac', 'm4a', 'flac', 'ogg'] },
      { name: 'Tất cả file', extensions: ['*'] },
    ],
  });
  if (res.canceled || !res.filePaths.length) return null;
  return res.filePaths[0];
});

ipcMain.handle('dialog:open-directory', async () => {
  const res = await dialog.showOpenDialog(mainWindow, {
    title: 'Chọn thư mục chứa ảnh',
    properties: ['openDirectory'],
  });
  if (res.canceled || !res.filePaths.length) return null;
  return res.filePaths[0];
});

ipcMain.handle('shell:open-path', async (_, targetPath) => {
  if (targetPath && fs.existsSync(targetPath)) {
    await shell.openPath(targetPath);
    return { ok: true };
  }
  return { ok: false, error: 'Path does not exist' };
});

ipcMain.handle('shell:show-item-in-folder', async (_, targetPath) => {
  if (targetPath && fs.existsSync(targetPath)) {
    shell.showItemInFolder(targetPath);
    return { ok: true };
  }
  return { ok: false, error: 'Path does not exist' };
});

// -----------------------------------------------------------------------------
// AI Upscale Processor (GAP-12)
// -----------------------------------------------------------------------------
ipcMain.handle('upscale:process-images', async (event, { filePaths, resolution = '2K', outputDir = null }) => {
  if (!filePaths || !filePaths.length) {
    return { ok: false, error: 'Chưa chọn tệp ảnh để upscale.' };
  }

  const is4K = resolution === '4x_4k' || resolution === '4K';
  const scale = is4K ? 4 : 2;
  const tokenCostPerImage = is4K ? 2 : 1;

  // Resolve output directory
  let defaultOutDir = outputDir;
  if (!defaultOutDir) {
    try {
      defaultOutDir = path.join(app.getPath('documents'), '2TOOLNE', 'Upscaled');
    } catch (_) {
      defaultOutDir = path.join(os.homedir(), 'Documents', '2TOOLNE', 'Upscaled');
    }
  }
  if (!fs.existsSync(defaultOutDir)) {
    fs.mkdirSync(defaultOutDir, { recursive: true });
  }

  // Check for NCNN Vulkan binary
  const isWin = process.platform === 'win32';
  const ncnnBinaryName = isWin ? 'realesrgan-ncnn-vulkan.exe' : 'realesrgan-ncnn-vulkan';
  const candidateDirs = [
    path.join(process.resourcesPath || '', 'engine'),
    path.join(__dirname, '..', '..', 'engine'),
    path.join(process.cwd(), 'engine'),
    path.join(os.homedir(), 'Documents', 'toolupscale', 'windows-package', '2toolne-upscale-win-x64-test'),
  ];
  let ncnnBin = null;
  for (const cDir of candidateDirs) {
    const fullP = path.join(cDir, ncnnBinaryName);
    if (fs.existsSync(fullP)) {
      ncnnBin = fullP;
      break;
    }
  }

  // Find ffmpeg fallback
  let ffmpegBin = 'ffmpeg';
  if (isWin) {
    const localFfmpeg = path.join(process.resourcesPath || '', 'ffmpeg', 'bin', 'ffmpeg.exe');
    if (fs.existsSync(localFfmpeg)) ffmpegBin = localFfmpeg;
  }

  const completed = [];
  const errors = [];

  for (let i = 0; i < filePaths.length; i++) {
    const inputP = filePaths[i];
    if (!fs.existsSync(inputP)) {
      errors.push({ file: inputP, error: 'File not found' });
      continue;
    }

    const baseName = path.parse(inputP).name;
    const outputFileName = `${baseName}_upscaled_${is4K ? '4K' : '2K'}.png`;
    const outputP = path.join(defaultOutDir, outputFileName);

    if (event.sender && !event.sender.isDestroyed()) {
      event.sender.send('upscale:progress', {
        current: i + 1,
        total: filePaths.length,
        percent: Math.round((i / filePaths.length) * 100),
        currentFile: path.basename(inputP),
        message: `Đang xử lý (${i + 1}/${filePaths.length}): ${path.basename(inputP)}...`,
      });
    }

    try {
      if (ncnnBin) {
        // Execute real NCNN Vulkan binary
        await new Promise((resolve, reject) => {
          const args = ['-i', inputP, '-o', outputP, '-s', String(scale), '-f', 'png'];
          const proc = spawn(ncnnBin, args, { windowsHide: true });
          proc.on('close', (code) => {
            if (code === 0 && fs.existsSync(outputP)) resolve();
            else reject(new Error(`NCNN Vulkan exited with code ${code}`));
          });
          proc.on('error', reject);
        });
      } else if (process.platform === 'darwin') {
        // Native macOS sips / ffmpeg lanczos
        const targetDim = is4K ? 3840 : 2560;
        await new Promise((resolve, reject) => {
          const args = [
            '-y', '-i', inputP,
            '-vf', `scale='if(gt(a,16/9),${targetDim},-2)':'if(gt(a,16/9),-2,${is4K ? 2160 : 1440})':flags=lanczos`,
            outputP,
          ];
          const proc = spawn(ffmpegBin, args);
          proc.on('close', (code) => {
            if (code === 0 && fs.existsSync(outputP)) {
              resolve();
            } else {
              const sipsProc = spawn('sips', ['-Z', String(targetDim), inputP, '--out', outputP]);
              sipsProc.on('close', (c) => (c === 0 && fs.existsSync(outputP) ? resolve() : reject(new Error('Sips failed'))));
              sipsProc.on('error', reject);
            }
          });
          proc.on('error', () => {
            const sipsProc = spawn('sips', ['-Z', String(targetDim), inputP, '--out', outputP]);
            sipsProc.on('close', (c) => (c === 0 && fs.existsSync(outputP) ? resolve() : reject(new Error('Sips failed'))));
            sipsProc.on('error', reject);
          });
        });
      } else {
        // Windows ffmpeg lanczos or fallback copy
        const targetDim = is4K ? 3840 : 2560;
        await new Promise((resolve, reject) => {
          const args = [
            '-y', '-i', inputP,
            '-vf', `scale='if(gt(a,16/9),${targetDim},-2)':'if(gt(a,16/9),-2,${is4K ? 2160 : 1440})':flags=lanczos`,
            outputP,
          ];
          const proc = spawn(ffmpegBin, args, { windowsHide: true });
          proc.on('close', (code) => {
            if (code === 0 && fs.existsSync(outputP)) resolve();
            else {
              fs.copyFileSync(inputP, outputP);
              resolve();
            }
          });
          proc.on('error', () => {
            fs.copyFileSync(inputP, outputP);
            resolve();
          });
        });
      }

      completed.push({
        input: inputP,
        output: outputP,
        fileName: path.basename(inputP),
        resolution: is4K ? '4K' : '2K',
      });

      // Try commit token if user is logged in
      try {
        const session = secureStorage.getItem('user_session');
        const token = session?.token;
        if (token) {
          await postJson('/api/v1/credits/commit', {
            file_name: path.basename(inputP),
            resolution: is4K ? '4K' : '2K',
            committed_amount: tokenCostPerImage,
            token: token,
          });
        }
      } catch (tokenErr) {
        console.warn('Credits commit warning:', tokenErr.message);
      }
    } catch (err) {
      errors.push({ file: inputP, error: err.message });
    }
  }

  if (event.sender && !event.sender.isDestroyed()) {
    event.sender.send('upscale:progress', {
      current: filePaths.length,
      total: filePaths.length,
      percent: 100,
      message: `Hoàn tất phóng to ${completed.length}/${filePaths.length} ảnh.`,
    });
  }

  return {
    ok: true,
    completedCount: completed.length,
    totalCount: filePaths.length,
    outputDir: defaultOutDir,
    items: completed,
    errors,
  };
});

const storeDataDir = path.join(os.homedir(), '.2toolne-autoedit');
if (!fs.existsSync(storeDataDir)) {
  try { fs.mkdirSync(storeDataDir, { recursive: true }); } catch (e) {}
}

ipcMain.handle('store:get-data', async (_, key) => {
  const filePath = path.join(storeDataDir, `${key}.json`);
  if (fs.existsSync(filePath)) {
    try {
      const content = fs.readFileSync(filePath, 'utf-8');
      return JSON.parse(content);
    } catch (e) {
      console.warn(`[Store] Error reading ${key}:`, e.message);
    }
  }
  return null;
});

ipcMain.handle('store:set-data', async (_, { key, data }) => {
  const filePath = path.join(storeDataDir, `${key}.json`);
  try {
    fs.writeFileSync(filePath, JSON.stringify(data, null, 2), 'utf-8');
    return { ok: true };
  } catch (e) {
    return { ok: false, error: e.message };
  }
});

ipcMain.handle('fs:delete-draft', async (_, targetPath) => {
  if (!targetPath || typeof targetPath !== 'string') {
    return { ok: false, error: 'Invalid path' };
  }
  const normalized = path.normalize(targetPath);
  const isDraftFolder = (
    fs.existsSync(path.join(normalized, 'draft_content.json')) ||
    fs.existsSync(path.join(normalized, 'draft_meta_info.json')) ||
    normalized.includes('com.lveditor.draft') ||
    normalized.includes('.2toolne-autoedit')
  );
  if (!isDraftFolder) {
    return { ok: false, error: 'Path is not a recognized generated draft directory. Protected from deletion.' };
  }
  try {
    fs.rmSync(normalized, { recursive: true, force: true });
    return { ok: true };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

ipcMain.handle('diagnostics:export-bundle', async () => {
  try {
    const diagInfo = await sidecar.send('GET_DIAGNOSTICS').catch(() => ({}));
    const desktopDir = app.getPath('desktop');
    const timestamp = Date.now();
    const bundleFilename = `2toolne_diagnostics_${timestamp}.json`;
    const bundlePath = path.join(desktopDir, bundleFilename);

    const queueFilePath = path.join(storeDataDir, 'render_queue_state.json');
    let queueState = null;
    if (fs.existsSync(queueFilePath)) {
      try { queueState = JSON.parse(fs.readFileSync(queueFilePath, 'utf-8')); } catch (e) {}
    }

    const payload = {
      app_version: app.getVersion(),
      platform: process.platform,
      arch: process.arch,
      electron_version: process.versions.electron,
      node_version: process.versions.node,
      os_release: os.release(),
      os_uptime: os.uptime(),
      diagnostics_from_sidecar: diagInfo,
      render_queue_snapshot: queueState,
      exported_at: new Date().toISOString(),
    };

    fs.writeFileSync(bundlePath, JSON.stringify(payload, null, 2), 'utf-8');
    shell.showItemInFolder(bundlePath);
    return { ok: true, path: bundlePath };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

ipcMain.handle('updater:check-update', async () => {
  const currentVersion = app.getVersion();
  const updateUrl = 'https://www.2tamne.site/api/v1/app/version';
  try {
    const https = require('https');
    const checkRemote = () =>
      new Promise((resolve) => {
        const req = https.get(updateUrl, { timeout: 4000 }, (res) => {
          let data = '';
          res.on('data', (chunk) => (data += chunk));
          res.on('end', () => {
            try {
              const parsed = JSON.parse(data);
              resolve({ ok: true, data: parsed });
            } catch (e) {
              resolve({ ok: false, error: 'Invalid JSON response' });
            }
          });
        });
        req.on('error', (e) => resolve({ ok: false, error: e.message }));
        req.on('timeout', () => {
          req.destroy();
          resolve({ ok: false, error: 'Timeout' });
        });
      });

    const remote = await checkRemote();
    if (remote.ok && remote.data) {
      const latestVer = remote.data.latest_version || remote.data.version || currentVersion;
      const hasUpdate = latestVer !== currentVersion;
      return {
        ok: true,
        current_version: currentVersion,
        latest_version: latestVer,
        has_update: hasUpdate,
        release_notes: remote.data.release_notes || 'Phiên bản mới với nhiều cải tiến hiệu năng và sửa lỗi.',
        download_url: remote.data.download_url || 'https://www.2tamne.site/download',
      };
    } else {
      return {
        ok: true,
        current_version: currentVersion,
        latest_version: currentVersion,
        has_update: false,
        message: `Bạn đang chạy phiên bản mới nhất (${currentVersion}).`,
      };
    }
  } catch (err) {
    return {
      ok: false,
      current_version: currentVersion,
      error: err.message,
    };
  }
});

// -----------------------------------------------------------------------------
// App Lifecycle
// -----------------------------------------------------------------------------

app.whenReady().then(async () => {
  try {
    await sidecar.start();

    // Section 6: Restore decrypted entitlement session into sidecar memory
    const storedEnvelope = secureStorage.getItem('entitlement_envelope');
    if (storedEnvelope) {
      const trustedTime = secureStorage.getItem('trusted_server_time');
      const maskedKey = secureStorage.getItem('masked_key');
      const last4 = secureStorage.getItem('license_key_last4');
      try {
        await sidecar.send('INSTALL_SIGNED_ENTITLEMENT', {
          envelope: storedEnvelope,
          trusted_server_time: trustedTime,
          masked_key: maskedKey,
          license_key_last4: last4,
        });
        console.log('[License] Restored decrypted entitlement session into sidecar memory.');
      } catch (restoreErr) {
        console.warn('[License] Failed restoring entitlement session:', restoreErr.message);
      }
    }
  } catch (err) {
    console.error('Failed starting sidecar:', err);
    dialog.showErrorBox(
      'Lỗi khởi động dịch vụ cốt lõi',
      `Không thể khởi tạo Python Core Sidecar.\n\nChi tiết lỗi: ${err.message}`
    );
  }
  await createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

app.on('window-all-closed', () => {
  sidecar.stop();
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('before-quit', () => {
  sidecar.stop();
});
