/**
 * apps/capcut-v2/desktop/src/main/integrity_guard.js
 *
 * 2TOOLNE Desktop Runtime Integrity Guard (Milestone 3 / Feature 13-14)
 *
 * Provides startup anti-tamper verification across packaged and development environments:
 * 1. Safe 'original-fs' import to bypass Electron's virtual asar extraction and stream
 *    physical raw binary bytes of app.asar.
 * 2. Cryptographic Ed25519 digital signature verification against embedded TRUSTED_PUBLIC_KEYS.
 * 3. Asynchronous streaming SHA-256 computation via createReadStream for app.asar and all
 *    bundled native PE32+ binaries.
 * 4. Fail-Secure execution policy:
 *    - Packaged mode (app.isPackaged = true) or STRICT_INTEGRITY_CHECK=1: Fatal IntegrityTamperError,
 *      native showErrorBox, and immediate process termination (app.exit(1)).
 *    - Development mode (!app.isPackaged): Non-blocking warning logs unless STRICT_INTEGRITY_CHECK=1.
 */

'use strict';

const path = require('path');
const crypto = require('crypto');

// 1. Safe original-fs import
// In Electron, original-fs must be used to read app.asar as a raw binary file
// instead of Electron's virtual filesystem extracting or intercepting it.
let fsOriginal;
try {
  fsOriginal = require('original-fs');
} catch (e) {
  fsOriginal = require('fs');
}

// Safely obtain Electron app and dialog if running inside Electron runtime
let electronApp = null;
let electronDialog = null;
try {
  const electron = require('electron');
  electronApp = electron.app;
  electronDialog = electron.dialog;
} catch (e) {
  // Graceful fallback for standalone Node.js test harness
}

function findRepoRoot() {
  let cur = __dirname;
  for (let i = 0; i < 6; i++) {
    if (fsOriginal.existsSync(path.join(cur, 'PROJECT.md')) || fsOriginal.existsSync(path.join(cur, 'apps', 'capcut-v2'))) {
      return cur;
    }
    cur = path.dirname(cur);
  }
  return path.resolve(__dirname, '../../../../..');
}

const REPO_ROOT = findRepoRoot();

// 2. Canonical Public Verification Keys (Key Rotation kid_2026_02)
const TRUSTED_PUBLIC_KEYS = [
  // Authoritative Production Ed25519 Public Key (SPKI Base64)
  'MCowBQYDK2VwAyEA/fr4CkPhp7UyPstMS5WbXLOBulG8BQc+C6FPxokpP+w=',
  // Raw 32-byte Ed25519 Public Key (Base64)
  '/fr4CkPhp7UyPstMS5WbXLOBulG8BQc+C6FPxokpP+w=',
];

// SPKI header prefix for 32-byte Ed25519 public key (RFC 8410)
const ED25519_SPKI_PREFIX_HEX = '302a300506032b6570032100';

// Native Root of Trust Bootstrap Nonce Handshake constants (Milestone 1)
const BOOTSTRAP_SALT = '2TOOLNE_NATIVE_ROOT_BOOTSTRAP_HANDSHAKE_v2_2026';
const DEFAULT_BOOTSTRAP_TOKEN_TTL_MS = 30000; // 30 seconds (hardened TTL)

/**
 * Custom error class for integrity and tampering violations
 */
class IntegrityTamperError extends Error {
  constructor(message, details = {}) {
    super(message);
    this.name = 'IntegrityTamperError';
    this.code = details.code || (details.reason === 'ERR_INTEGRITY_VERSION_MISMATCH' || details.reason === 'VERSION_MISMATCH'
      ? 'ERR_INTEGRITY_VERSION_MISMATCH'
      : 'ERR_INTEGRITY_TAMPERED');
    this.details = details;
    this.securityViolation = true;
  }
}

/**
 * Deterministically serializes a JavaScript object into a canonical UTF-8 JSON string.
 * Recursively sorts all dictionary keys in ascending alphabetical order.
 * 
 * @param {*} obj Any JSON-serializable value
 * @returns {string} Canonical JSON string without extraneous whitespace
 */
function canonicalizeJson(obj) {
  if (obj === null || typeof obj !== 'object') {
    return JSON.stringify(obj);
  }
  if (Array.isArray(obj)) {
    return '[' + obj.map(canonicalizeJson).join(',') + ']';
  }
  const keys = Object.keys(obj).sort();
  return '{' + keys.map(k => JSON.stringify(k) + ':' + canonicalizeJson(obj[k])).join(',') + '}';
}

/**
 * Normalizes and converts various public key representations into a crypto.KeyObject
 * Supports:
 * - crypto.KeyObject
 * - PEM string ('-----BEGIN PUBLIC KEY-----...')
 * - SPKI DER base64 string ('MCowBQYDK2VwAyEA...')
 * - Raw 32-byte Ed25519 base64 string ('+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=')
 */
function parsePublicKey(keyInput) {
  if (keyInput && typeof keyInput === 'object' && keyInput.type === 'public') {
    return keyInput;
  }
  if (typeof keyInput === 'string') {
    const trimmed = keyInput.trim();
    if (trimmed.startsWith('-----BEGIN PUBLIC KEY-----')) {
      return crypto.createPublicKey(trimmed);
    }
    const buf = Buffer.from(trimmed, 'base64');
    if (buf.length === 32) {
      // Prepend standard SPKI Ed25519 header: 30 2a 30 05 06 03 2b 65 70 03 21 00 (12 bytes)
      const spkiDer = Buffer.concat([Buffer.from(ED25519_SPKI_PREFIX_HEX, 'hex'), buf]);
      return crypto.createPublicKey({ key: spkiDer, format: 'der', type: 'spki' });
    }
    if (buf.length === 44 && trimmed.startsWith('MCow')) {
      return crypto.createPublicKey({ key: buf, format: 'der', type: 'spki' });
    }
    return crypto.createPublicKey(trimmed);
  }
  throw new Error('Unsupported public key format');
}

/**
 * Asynchronously computes SHA-256 hex digest of a file via fsOriginal streaming
 * Memory-constant for large files (app.asar, ffmpeg.exe).
 */
function computeFileSha256(filePath) {
  return new Promise((resolve, reject) => {
    try {
      if (!fsOriginal.existsSync(filePath)) {
        return reject(new Error(`File not found: ${filePath}`));
      }
      const hash = crypto.createHash('sha256');
      const stream = fsOriginal.createReadStream(filePath, { highWaterMark: 1024 * 1024 });
      stream.on('data', (chunk) => hash.update(chunk));
      stream.on('end', () => resolve(hash.digest('hex')));
      stream.on('error', (err) => reject(err));
    } catch (err) {
      reject(err);
    }
  });
}

/**
 * Resolves the location of integrity.manifest.json across packaged and unpackaged environments
 */
function resolveManifestPath(explicitPath = null) {
  if (explicitPath && fsOriginal.existsSync(explicitPath)) {
    return explicitPath;
  }
  if (process.env.INTEGRITY_MANIFEST_PATH && fsOriginal.existsSync(process.env.INTEGRITY_MANIFEST_PATH)) {
    return process.env.INTEGRITY_MANIFEST_PATH;
  }

  const isPackaged = (electronApp && typeof electronApp.isPackaged === 'boolean')
    ? electronApp.isPackaged
    : (process.resourcesPath && !process.defaultApp);

  const candidates = [];

  // Packaged production locations
  if (isPackaged && process.resourcesPath) {
    candidates.push(path.join(process.resourcesPath, 'integrity.manifest.json'));
    candidates.push(path.join(process.resourcesPath, 'resources', 'integrity.manifest.json'));
  }

  // Development / staging locations
  candidates.push(path.resolve(__dirname, '../../resources/integrity.manifest.json'));
  candidates.push(path.resolve(__dirname, '../../../resources/integrity.manifest.json'));
  candidates.push(path.join(process.cwd(), 'apps/capcut-v2/desktop/resources/integrity.manifest.json'));
  candidates.push(path.join(process.cwd(), 'resources/integrity.manifest.json'));

  for (const cand of candidates) {
    if (fsOriginal.existsSync(cand)) {
      return cand;
    }
  }

  return null;
}

/**
 * Resolves the base directory from which manifest relative paths are evaluated
 */
function resolveBaseDirectory(manifestPath, explicitBaseDir = null) {
  if (explicitBaseDir && fsOriginal.existsSync(explicitBaseDir)) {
    return explicitBaseDir;
  }
  if (process.env.INTEGRITY_BASE_DIR && fsOriginal.existsSync(process.env.INTEGRITY_BASE_DIR)) {
    return process.env.INTEGRITY_BASE_DIR;
  }

  const isPackaged = (electronApp && typeof electronApp.isPackaged === 'boolean')
    ? electronApp.isPackaged
    : (process.resourcesPath && !process.defaultApp);

  if (isPackaged && process.resourcesPath) {
    return process.resourcesPath;
  }

  if (manifestPath) {
    return path.dirname(manifestPath);
  }

  return path.resolve(__dirname, '../../resources');
}

/**
 * Verifies manifest Ed25519 signature against trusted public keys
 */
function verifyManifestSignature(manifest, trustedKeys = TRUSTED_PUBLIC_KEYS) {
  if (!manifest || typeof manifest !== 'object') {
    return { valid: false, error: 'Manifest is missing or invalid' };
  }
  const { signature, ...manifestPayload } = manifest;
  if (!signature || typeof signature !== 'string') {
    return { valid: false, error: 'Manifest has no signature' };
  }

  let signatureBuffer;
  try {
    signatureBuffer = Buffer.from(signature, 'base64');
    if (signatureBuffer.length === 0) {
      return { valid: false, error: 'Manifest has no signature' };
    }
  } catch (e) {
    return { valid: false, error: 'Ed25519 signature verification failed: invalid base64' };
  }

  // 1. Canonical JSON serialized buffer
  const canonicalString = canonicalizeJson(manifestPayload);
  const canonicalBuffer = Buffer.from(canonicalString, 'utf8');

  // 2. Standard JSON serialized buffer (used by some test fixtures)
  const standardString = JSON.stringify(manifestPayload);
  const standardBuffer = Buffer.from(standardString, 'utf8');

  const keys = Array.isArray(trustedKeys) ? trustedKeys : [trustedKeys];
  let isSigValid = false;

  for (const keyCandidate of keys) {
    try {
      const pubKey = parsePublicKey(keyCandidate);
      // Try canonical serialization first
      if (crypto.verify(null, canonicalBuffer, pubKey, signatureBuffer)) {
        isSigValid = true;
        break;
      }
      // Try standard serialization
      if (crypto.verify(null, standardBuffer, pubKey, signatureBuffer)) {
        isSigValid = true;
        break;
      }
    } catch (e) {
      // Continue trying next candidate
    }
  }

  if (!isSigValid) {
    return { valid: false, error: 'Ed25519 signature verification failed' };
  }

  return { valid: true };
}

/**
 * Generates an ephemeral bootstrap handshake token bound to the manifest signature
 * 
 * @param {string} manifestSignature Base64 Ed25519 signature of the integrity manifest
 * @param {object} options Optional parameters (nonce, timestamp, salt)
 * @returns {string} Token formatted as "nonce.timestamp.hmac"
 */
function generateBootstrapToken(manifestSignature, options = {}) {
  if (!manifestSignature || typeof manifestSignature !== 'string') {
    throw new Error('Valid manifest signature string is required to generate bootstrap token');
  }
  const nonce = options.nonce || crypto.randomBytes(16).toString('hex');
  const timestamp = options.timestamp !== undefined ? String(options.timestamp) : String(Date.now());
  const salt = options.salt || BOOTSTRAP_SALT;

  const derivedKey = crypto.createHmac('sha256', salt).update(manifestSignature).digest();
  const tokenSig = crypto.createHmac('sha256', derivedKey).update(`${nonce}.${timestamp}`).digest('hex');

  return `${nonce}.${timestamp}.${tokenSig}`;
}

/**
 * Validates a bootstrap handshake token against the manifest signature and freshness window
 * 
 * @param {string} token Ephemeral bootstrap token string ("nonce.timestamp.hmac")
 * @param {string} manifestSignature Base64 Ed25519 signature of the integrity manifest
 * @param {object} options Optional validation settings (maxAgeMs, salt, ignoreExpiry)
 * @returns {{ valid: boolean, error?: string, details?: object }}
 */
// In-memory set of consumed nonces to strictly prevent bootstrap token replay attacks
const consumedBootstrapNonces = new Set();

function verifyBootstrapToken(token, manifestSignature, options = {}) {
  if (!token || typeof token !== 'string') {
    return { valid: false, error: 'Bootstrap token is missing or not a string' };
  }
  if (!manifestSignature || typeof manifestSignature !== 'string') {
    return { valid: false, error: 'Manifest signature is missing or invalid' };
  }

  const parts = token.split('.');
  if (parts.length !== 3) {
    return { valid: false, error: 'Malformed bootstrap token format (expected nonce.timestamp.sig)' };
  }

  const [nonce, timestampStr, providedSig] = parts;

  if (!nonce || nonce.length !== 32 || !providedSig || providedSig.length !== 64) {
    return { valid: false, error: 'Invalid bootstrap token component length' };
  }

  // Replay Attack Prevention
  if (!options.allowReplay && consumedBootstrapNonces.has(nonce)) {
    return { valid: false, error: 'Bootstrap token replay attack detected: nonce has already been consumed' };
  }

  const timestamp = parseInt(timestampStr, 10);
  if (isNaN(timestamp) || timestamp <= 0) {
    return { valid: false, error: 'Invalid bootstrap token timestamp format' };
  }

  // Verify freshness unless explicitly ignored by testing options
  if (!options.ignoreExpiry) {
    const maxAgeMs = options.maxAgeMs || DEFAULT_BOOTSTRAP_TOKEN_TTL_MS;
    const age = Math.abs(Date.now() - timestamp);
    if (age > maxAgeMs) {
      return { valid: false, error: `Bootstrap token expired (age: ${age}ms, max: ${maxAgeMs}ms)` };
    }
  }

  const salt = options.salt || BOOTSTRAP_SALT;
  const derivedKey = crypto.createHmac('sha256', salt).update(manifestSignature).digest();
  const expectedSig = crypto.createHmac('sha256', derivedKey).update(`${nonce}.${timestampStr}`).digest('hex');

  try {
    const isMatch = crypto.timingSafeEqual(
      Buffer.from(providedSig, 'hex'),
      Buffer.from(expectedSig, 'hex')
    );
    if (!isMatch) {
      return { valid: false, error: 'Bootstrap token cryptographic signature mismatch' };
    }
  } catch (err) {
    return { valid: false, error: `Bootstrap signature verification error: ${err.message}` };
  }

  if (!options.allowReplay) {
    consumedBootstrapNonces.add(nonce);
  }

  return {
    valid: true,
    nonce,
    timestamp,
  };
}

/**
 * Synchronous manifest verification helper adhering to IntegrityFixtures.verifyManifest interface
 */
function verifyManifest(manifest, publicKey, baseDir = '', options = {}) {
  const isPackaged = (options.isPackaged !== undefined)
    ? options.isPackaged
    : Boolean(electronApp && typeof electronApp.isPackaged === 'boolean' && electronApp.isPackaged);

  const sigCheck = verifyManifestSignature(manifest, publicKey || TRUSTED_PUBLIC_KEYS);
  if (!sigCheck.valid) {
    return sigCheck;
  }

  if (baseDir) {
    const { signature, ...manifestPayload } = manifest;
    for (const [relPath, expectedHash] of Object.entries(manifestPayload.files || {})) {
      const normalizedRel = relPath.replace(/\\/g, '/');
      let fullPath = path.join(baseDir, normalizedRel);
      if (!fsOriginal.existsSync(fullPath)) {
        const parentCandidate = path.join(baseDir, '..', normalizedRel);
        let foundAlt = false;
        if (fsOriginal.existsSync(parentCandidate)) {
          fullPath = parentCandidate;
          foundAlt = true;
        } else if (!isPackaged) {
          const altCandidates = [
            path.join(baseDir, '..', 'dist', 'win-unpacked', normalizedRel),
            path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop', 'dist', 'win-unpacked', normalizedRel),
          ];
          for (const alt of altCandidates) {
            if (fsOriginal.existsSync(alt)) {
              fullPath = alt;
              foundAlt = true;
              break;
            }
          }
        }
        if (!foundAlt) {
          return { valid: false, error: `Missing file in manifest: ${relPath}` };
        }
      }
      const data = fsOriginal.readFileSync(fullPath);
      const actualHash = crypto.createHash('sha256').update(data).digest('hex');
      if (actualHash.toLowerCase() !== expectedHash.toLowerCase()) {
        return {
          valid: false,
          error: `SHA-256 hash mismatch for ${relPath}: expected ${expectedHash}, got ${actualHash}`,
        };
      }
    }
  }

  return { valid: true };
}

/**
 * Fail-Secure Fatal Handler: logs, displays native dialog, and halts application
 */
function handleIntegrityViolation(message, reason = 'TAMPER_DETECTED', options = {}) {
  const errCode = (reason === 'ERR_INTEGRITY_VERSION_MISMATCH' || reason === 'VERSION_MISMATCH')
    ? 'ERR_INTEGRITY_VERSION_MISMATCH'
    : 'ERR_INTEGRITY_TAMPERED';

  const err = new IntegrityTamperError(`INTEGRITY_VIOLATION [${reason}]: ${message}`, {
    reason,
    code: errCode,
    details: message,
  });

  console.error(`[IntegrityGuard][FATAL] ${err.message}`);

  // Show native Electron error dialog if available
  if (electronDialog && typeof electronDialog.showErrorBox === 'function') {
    try {
      electronDialog.showErrorBox(
        'Lỗi bảo mật tính toàn vẹn (Security Integrity Error)',
        `Phát hiện ứng dụng hoặc tệp nhị phân hệ thống đã bị chỉnh sửa bất hợp pháp.\n\n` +
        `Mã lỗi: ${errCode}\n` +
        `Lý do: ${reason}\n\n` +
        `Chi tiết vi phạm: ${message}\n\n` +
        `Ứng dụng 2TOOLNE AutoEdit sẽ buộc đóng ngay lập tức để bảo vệ an toàn dữ liệu.`
      );
    } catch (dialogErr) {
      console.error('[IntegrityGuard] Failed to display error dialog:', dialogErr);
    }
  }

  // Force exit application process unless testing explicitly requests noExit
  const shouldExit = !options.noExit && process.env.INTEGRITY_TEST_NO_EXIT !== '1';
  if (shouldExit) {
    if (electronApp && typeof electronApp.exit === 'function') {
      electronApp.exit(1);
    } else if (process && typeof process.exit === 'function') {
      process.exit(1);
    }
  }

  throw err;
}

/**
 * Main Runtime Guard: Verifies manifest signature and streaming SHA-256 of all files
 * Invoked during Electron app.whenReady() before mounting UI or spawning sidecar.
 */
async function verifyRuntimeIntegrity(options = {}) {
  const isPackaged = (options.isPackaged !== undefined)
    ? options.isPackaged
    : ((electronApp && typeof electronApp.isPackaged === 'boolean')
        ? electronApp.isPackaged
        : (process.env.NODE_ENV === 'production' && !process.defaultApp));

  const isStrict = (options.strict !== undefined)
    ? options.strict
    : (process.env.STRICT_INTEGRITY_CHECK === '1');

  const manifestPath = resolveManifestPath(options.manifestPath);

  // Manifest presence check
  if (!manifestPath || !fsOriginal.existsSync(manifestPath)) {
    if (!isPackaged && !isStrict) {
      console.warn(
        '[IntegrityGuard][DEV_WARNING] integrity.manifest.json not found in development environment. Skipping integrity check.'
      );
      return { valid: true, devBypassed: true, reason: 'DEV_MANIFEST_NOT_FOUND' };
    }
    return handleIntegrityViolation(
      'Missing integrity manifest: resources/integrity.manifest.json not found in application package',
      'MISSING_MANIFEST',
      options
    );
  }

  // Load and parse manifest
  let manifest;
  try {
    const rawContent = fsOriginal.readFileSync(manifestPath, 'utf8');
    manifest = JSON.parse(rawContent);
  } catch (parseErr) {
    return handleIntegrityViolation(
      `Failed to parse integrity manifest: ${parseErr.message}`,
      'CORRUPTED_MANIFEST',
      options
    );
  }

  // Verify Ed25519 signature
  const trustedKeys = options.trustedPublicKeys || options.trustedKeys || TRUSTED_PUBLIC_KEYS;
  const sigResult = verifyManifestSignature(manifest, trustedKeys);
  if (!sigResult.valid) {
    if (!isPackaged && !isStrict) {
      console.warn(
        `[IntegrityGuard][DEV_WARNING] ${sigResult.error} in development mode. Bypassing check.`
      );
      return { valid: true, devBypassed: true, reason: 'DEV_INVALID_SIGNATURE' };
    }
    return handleIntegrityViolation(
      sigResult.error,
      'INVALID_SIGNATURE',
      options
    );
  }

  // Dynamic Version Match Assertion (Milestone M4)
  let currentAppVersion = options.appVersion;
  if (!currentAppVersion && electronApp && typeof electronApp.getVersion === 'function') {
    try {
      currentAppVersion = electronApp.getVersion();
    } catch (_) {}
  }
  if (!currentAppVersion && !isPackaged) {
    try {
      const desktopPkgPath = path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop', 'package.json');
      if (fsOriginal.existsSync(desktopPkgPath)) {
        const pkg = JSON.parse(fsOriginal.readFileSync(desktopPkgPath, 'utf8'));
        currentAppVersion = pkg.version;
      }
    } catch (_) {}
  }

  if (currentAppVersion && manifest.version && manifest.version !== currentAppVersion) {
    if (!isPackaged && !isStrict) {
      console.warn(
        `[IntegrityGuard][DEV_WARNING] Manifest version (${manifest.version}) does not match app version (${currentAppVersion}). Bypassing in dev.`
      );
    } else {
      return handleIntegrityViolation(
        `Manifest version mismatch: application version is ${currentAppVersion}, but manifest version is ${manifest.version}`,
        'ERR_INTEGRITY_VERSION_MISMATCH',
        options
      );
    }
  }

  // Native Root of Trust Bootstrap Handshake Verification (_2TOOLNE_BOOTSTRAP_TOKEN)
  const requireBootstrapToken = (options.requireBootstrapToken !== undefined)
    ? options.requireBootstrapToken
    : (isPackaged && (Boolean(electronApp) || process.env.REQUIRE_BOOTSTRAP_TOKEN === '1'));

  const tokenCandidate = options.bootstrapToken || process.env._2TOOLNE_BOOTSTRAP_TOKEN;

  if (requireBootstrapToken || tokenCandidate) {
    if (!tokenCandidate) {
      return handleIntegrityViolation(
        'Missing native root bootstrap handshake token (_2TOOLNE_BOOTSTRAP_TOKEN). ' +
        'In packaged production mode, Electron must be launched via the native root of trust (2TOOLNE AutoEdit).',
        'MISSING_BOOTSTRAP_TOKEN',
        options
      );
    }

    const tokenVerification = verifyBootstrapToken(tokenCandidate, manifest.signature, options);
    if (!tokenVerification.valid) {
      return handleIntegrityViolation(
        `Invalid native root bootstrap token: ${tokenVerification.error}`,
        'INVALID_BOOTSTRAP_TOKEN',
        options
      );
    }

    // Token successfully verified!
    // Sanitize ephemeral token from environment to prevent inspection by child processes
    if (isPackaged && process.env._2TOOLNE_BOOTSTRAP_TOKEN) {
      delete process.env._2TOOLNE_BOOTSTRAP_TOKEN;
    }
  }

  // Determine base directory for files listed in manifest
  const baseDir = resolveBaseDirectory(manifestPath, options.baseDir);
  const filesMap = manifest.files || {};
  const fileEntries = Object.entries(filesMap);

  let verifiedCount = 0;

  for (const [relPath, expectedHash] of fileEntries) {
    const normalizedRel = relPath.replace(/\\/g, '/');
    let fullPath = path.join(baseDir, normalizedRel);

    if (!fsOriginal.existsSync(fullPath)) {
      // In packaged production mode: only check the direct parent directory (e.g. appOutDir for 2toolne-runtime.exe)
      // Zero dev/repo fallbacks are allowed when packaged (Section 15: PRODUCTION_PATH_FALLBACK_COUNT=0)
      const parentCandidate = path.join(baseDir, '..', normalizedRel);
      let found = false;

      if (fsOriginal.existsSync(parentCandidate)) {
        fullPath = parentCandidate;
        found = true;
      } else if (!isPackaged) {
        // Development-only fallback candidates (isolated to unpackaged environments)
        const devCandidates = [
          path.join(baseDir, '..', 'dist', 'win-unpacked', normalizedRel),
          path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop', 'dist', 'win-unpacked', normalizedRel),
          path.resolve(baseDir, '..', '..', 'resources', normalizedRel),
          path.resolve(__dirname, '../../resources', normalizedRel),
          path.join(REPO_ROOT, 'dist', 'customer_package_staging', '2TOOLNE', 'resources', normalizedRel),
        ];
        for (const cand of devCandidates) {
          if (fsOriginal.existsSync(cand)) {
            fullPath = cand;
            found = true;
            break;
          }
        }
      }

      if (!found) {
        if (!isPackaged && !isStrict) {
          console.warn(
            `[IntegrityGuard][DEV_WARNING] File '${relPath}' listed in manifest was not found in dev mode. Bypassing.`
          );
          continue;
        }
        return handleIntegrityViolation(
          `Missing file in manifest: ${relPath}`,
          'MISSING_FILE',
          options
        );
      }
    }

    // Streaming SHA-256 digest computation
    let actualHash;
    try {
      actualHash = await computeFileSha256(fullPath);
    } catch (hashErr) {
      return handleIntegrityViolation(
        `Failed to compute SHA-256 for ${relPath}: ${hashErr.message}`,
        'HASH_COMPUTATION_FAILED',
        options
      );
    }

    if (actualHash.toLowerCase() !== String(expectedHash).toLowerCase()) {
      if (!isPackaged && !isStrict) {
        console.warn(
          `[IntegrityGuard][DEV_WARNING] SHA-256 mismatch for ${relPath} (expected ${expectedHash}, got ${actualHash}). Bypassing in dev.`
        );
        continue;
      }
      return handleIntegrityViolation(
        `SHA-256 hash mismatch for ${relPath}: expected ${expectedHash}, got ${actualHash}`,
        'HASH_MISMATCH',
        options
      );
    }

    verifiedCount++;
  }

  console.log(`[IntegrityGuard] Integrity check passed successfully (${verifiedCount} files verified, manifest v${manifest.version || 'unknown'}).`);
  return {
    valid: true,
    filesChecked: verifiedCount,
    version: manifest.version,
    timestamp: manifest.timestamp,
  };
}

module.exports = {
  verifyRuntimeIntegrity,
  verifyApplicationIntegrity: verifyRuntimeIntegrity,
  verifyManifest,
  verifyManifestSignature,
  computeFileSha256,
  resolveManifestPath,
  resolveBaseDirectory,
  parsePublicKey,
  canonicalizeJson,
  generateBootstrapToken,
  verifyBootstrapToken,
  BOOTSTRAP_SALT,
  TRUSTED_PUBLIC_KEYS,
  IntegrityTamperError,
};
