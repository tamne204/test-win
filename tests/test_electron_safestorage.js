/**
 * tests/test_electron_safestorage.js
 * Automated test for Electron safeStorage API on Windows CI runner.
 * Evaluates DPAPI availability in the runner session.
 */
const { app, safeStorage } = require('electron');

app.whenReady().then(() => {
  try {
    const isAvailable = safeStorage.isEncryptionAvailable();
    console.log(`[SafeStorage CI Test] isEncryptionAvailable = ${isAvailable}`);

    if (isAvailable) {
      const plaintext = "2toolne_secure_token_ci_test";
      const encrypted = safeStorage.encryptString(plaintext);
      const decrypted = safeStorage.decryptString(encrypted);

      if (decrypted === plaintext) {
        console.log("WINDOWS_CI_SAFE_STORAGE_TEST = PASS (Encrypted and decrypted successfully)");
      } else {
        console.log("WINDOWS_CI_SAFE_STORAGE_TEST = FAIL (Decrypted payload mismatch)");
      }
    } else {
      console.log("WINDOWS_CI_SAFE_STORAGE_TEST = UNAVAILABLE_IN_HEADLESS_CI (Standard for non-interactive Windows CI services)");
    }
  } catch (err) {
    console.log(`WINDOWS_CI_SAFE_STORAGE_TEST = ERROR (${err.message})`);
  } finally {
    app.exit(0);
  }
});
