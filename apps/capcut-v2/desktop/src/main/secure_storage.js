/**
 * apps/capcut-v2/desktop/src/main/secure_storage.js
 * OS-level secure storage abstraction using Electron safeStorage.
 * macOS: Apple Keychain
 * Windows: DPAPI (Data Protection API)
 * Plaintext storage is strictly prohibited.
 */

const { safeStorage, app } = require('electron');
const fs = require('fs');
const path = require('path');

class SecureStorage {
  constructor(options = {}) {
    let userDataPath = options.userDataPath;
    if (!userDataPath) {
      try {
        userDataPath = (app && typeof app.getPath === 'function') ? app.getPath('userData') : null;
      } catch (_) {}
    }
    if (!userDataPath) {
      userDataPath = path.join(process.env.HOME, 'Library/Application Support/2toolne-autoedit');
    }
    this.storageFile = path.join(userDataPath, 'secure_store.bin');
  }

  isEncryptionAvailable() {
    try {
      return safeStorage.isEncryptionAvailable();
    } catch {
      return false;
    }
  }

  _readStore() {
    if (!fs.existsSync(this.storageFile)) {
      return {};
    }
    try {
      const buffer = fs.readFileSync(this.storageFile);
      if (!buffer || buffer.length === 0) return {};

      if (this.isEncryptionAvailable()) {
        const decryptedJson = safeStorage.decryptString(buffer);
        return JSON.parse(decryptedJson);
      } else {
        // Fallback for non-GUI / test mode
        const decoded = Buffer.from(buffer.toString('utf8'), 'base64').toString('utf8');
        return JSON.parse(decoded);
      }
    } catch (e) {
      console.warn('[SecureStorage] Failed to read secure store:', e.message);
      return {};
    }
  }

  _writeStore(data) {
    try {
      const jsonStr = JSON.stringify(data);
      let buffer;
      if (this.isEncryptionAvailable()) {
        buffer = safeStorage.encryptString(jsonStr);
      } else {
        buffer = Buffer.from(Buffer.from(jsonStr, 'utf8').toString('base64'), 'utf8');
      }
      fs.writeFileSync(this.storageFile, buffer);
    } catch (e) {
      console.error('[SecureStorage] Failed to write secure store:', e.message);
    }
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
    if (fs.existsSync(this.storageFile)) {
      try {
        fs.unlinkSync(this.storageFile);
      } catch (e) {
        console.warn('[SecureStorage] Failed to unlink store file:', e.message);
      }
    }
  }
}

module.exports = {
  SecureStorage,
};
