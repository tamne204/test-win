/**
 * apps/capcut-v2/desktop/src/main/secure_storage.js
 * 
 * OS-level secure storage abstraction using Electron safeStorage (Milestone M3).
 * macOS: Apple Keychain
 * Windows: DPAPI (Data Protection API)
 * Plaintext storage is strictly prohibited.
 * 
 * Invariants:
 * 1. Lazy path resolution: Resolves userDataPath after app.whenReady(), with Windows %APPDATA% fallback.
 * 2. Fail-secure: In packaged mode (app.isPackaged), if safeStorage is unavailable, throws SecurityError.
 *    Never writes unencrypted base64 fallback in packaged mode!
 * 3. Auto-migration: If safeStorage.decryptString() fails on legacy base64 data, decodes and re-encrypts
 *    via safeStorage.encryptString() seamlessly.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');

let safeStorage = null;
let app = null;

try {
  const electron = require('electron');
  safeStorage = electron.safeStorage;
  app = electron.app;
} catch (_) {
  // Graceful fallback for non-Electron test harnesses
}

class SecurityError extends Error {
  constructor(message, code = 'ERR_SECURE_STORAGE_UNAVAILABLE') {
    super(message);
    this.name = 'SecurityError';
    this.code = code;
    this.securityViolation = true;
  }
}

class SecureStorage {
  constructor(options = {}) {
    this._customUserDataPath = options.userDataPath || null;
    this._app = options.app || app;
    this._safeStorage = options.safeStorage || safeStorage;
  }

  isPackaged() {
    try {
      return Boolean(this._app && this._app.isPackaged);
    } catch (_) {
      return false;
    }
  }

  /**
   * Lazily resolves the authoritative user data directory after app is ready.
   */
  _resolveUserDataPath() {
    if (this._customUserDataPath) {
      return this._customUserDataPath;
    }

    let userDataPath = null;
    try {
      const targetApp = this._app || app;
      if (targetApp && typeof targetApp.getPath === 'function') {
        userDataPath = targetApp.getPath('userData');
      }
    } catch (_) {}

    if (!userDataPath) {
      if (process.platform === 'win32') {
        const appData = process.env.APPDATA || path.join(process.env.USERPROFILE || 'C:\\', 'AppData', 'Roaming');
        userDataPath = path.join(appData, '2toolne-autoedit');
      } else {
        const homeDir = process.env.HOME || process.env.USERPROFILE || (typeof os.homedir === 'function' ? os.homedir() : '/tmp');
        userDataPath = path.join(homeDir, 'Library', 'Application Support', '2toolne-autoedit');
      }
    }

    return userDataPath;
  }

  /**
   * Dynamic getter for storage file path ensuring lazy resolution.
   */
  get storageFile() {
    const userDataDir = this._resolveUserDataPath();
    return path.join(userDataDir, 'secure_store.bin');
  }

  /**
   * Checks whether OS-level hardware-backed encryption is available.
   */
  isEncryptionAvailable() {
    try {
      const s = this._safeStorage || safeStorage;
      return Boolean(s && typeof s.isEncryptionAvailable === 'function' && s.isEncryptionAvailable());
    } catch {
      return false;
    }
  }

  _readStore() {
    const filePath = this.storageFile;
    if (!fs.existsSync(filePath)) {
      return {};
    }

    try {
      const buffer = fs.readFileSync(filePath);
      if (!buffer || buffer.length === 0) return {};

      if (this.isEncryptionAvailable()) {
        try {
          const s = this._safeStorage || safeStorage;
          const decryptedJson = s.decryptString(buffer);
          return JSON.parse(decryptedJson);
        } catch (decErr) {
          // Auto-migration: on read, if safeStorage.decryptString() fails, attempt legacy base64 decode.
          // If valid JSON is recovered, re-encrypt via safeStorage.encryptString() and save.
          try {
            const decoded = Buffer.from(buffer.toString('utf8'), 'base64').toString('utf8');
            const parsed = JSON.parse(decoded);
            if (parsed && typeof parsed === 'object') {
              try {
                this._writeStore(parsed);
              } catch (migErr) {
                console.warn('[SecureStorage] Auto-migration re-encryption warning:', migErr.message);
              }
              return parsed;
            }
          } catch (_) {
            // Attempt plaintext JSON parse if legacy store was unencoded
            try {
              const parsed = JSON.parse(buffer.toString('utf8'));
              if (parsed && typeof parsed === 'object') {
                try {
                  this._writeStore(parsed);
                } catch (_) {}
                return parsed;
              }
            } catch (_) {}
          }
          throw decErr;
        }
      } else {
        // Fail-secure check: In packaged production mode, OS encryption MUST be available!
        if (this.isPackaged()) {
          throw new SecurityError('ERR_SECURE_STORAGE_UNAVAILABLE: OS encryption unavailable');
        }
        // Fallback for unpackaged development / headless unit tests only
        try {
          const decoded = Buffer.from(buffer.toString('utf8'), 'base64').toString('utf8');
          return JSON.parse(decoded);
        } catch (_) {
          return JSON.parse(buffer.toString('utf8'));
        }
      }
    } catch (e) {
      if (e instanceof SecurityError || e.code === 'ERR_SECURE_STORAGE_UNAVAILABLE') {
        throw e;
      }
      console.warn('[SecureStorage] Failed to read secure store:', e.message);
      return {};
    }
  }

  _writeStore(data) {
    const encAvailable = this.isEncryptionAvailable();

    // Fail-secure: Never write unencrypted base64 fallback in packaged mode!
    if (this.isPackaged() && !encAvailable) {
      throw new SecurityError('ERR_SECURE_STORAGE_UNAVAILABLE: OS encryption unavailable');
    }

    const jsonStr = JSON.stringify(data);
    let buffer;
    if (encAvailable) {
      const s = this._safeStorage || safeStorage;
      buffer = s.encryptString(jsonStr);
    } else {
      // Unpackaged dev / test mode fallback only
      buffer = Buffer.from(Buffer.from(jsonStr, 'utf8').toString('base64'), 'utf8');
    }

    const filePath = this.storageFile;
    const dir = path.dirname(filePath);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }
    fs.writeFileSync(filePath, buffer);
  }

  setItem(key, value) {
    const store = this._readStore();
    store[key] = value;
    this._writeStore(store);
  }

  getItem(key) {
    const store = this._readStore();
    return store[key] !== undefined ? store[key] : null;
  }

  removeItem(key) {
    const store = this._readStore();
    if (key in store) {
      delete store[key];
      this._writeStore(store);
    }
  }

  clear() {
    const filePath = this.storageFile;
    if (fs.existsSync(filePath)) {
      try {
        fs.unlinkSync(filePath);
      } catch (e) {
        console.warn('[SecureStorage] Failed to unlink store file:', e.message);
      }
    }
  }
}

module.exports = {
  SecureStorage,
  SecurityError,
};
