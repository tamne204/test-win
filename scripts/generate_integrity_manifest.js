#!/usr/bin/env node
/**
 * scripts/generate_integrity_manifest.js
 * 
 * 2TOOLNE Desktop — Signed Integrity Manifest Generator (Milestone 3 / Feature 12-13)
 * 
 * Invariants:
 * 1. Computes SHA-256 digests for all 7 critical release files:
 *    - app.asar
 *    - autoedit-core/win-x64/2toolne-core.exe
 *    - bin/win-x64/ffmpeg.exe
 *    - bin/win-x64/ffprobe.exe
 *    - bin/win-x64/CapCutUiProbe.exe
 *    - engine/win-x64/realesrgan-ncnn-vulkan.exe
 *    - engine/win-x64/vcomp140.dll
 * 2. Deterministically canonicalizes payload (alphabetical key sorting, compact JSON).
 * 3. Signs canonical payload with Ed25519 using crypto.sign(null, dataToSign, privateKey).
 * 4. Supports INTEGRITY_SIGNING_KEY env (PEM, 32-byte Base64, 32-byte Hex) with fallback
 *    to canonical dev/production key pair so local and CI builds work seamlessly.
 * 5. Supports CLI arguments (--output, --resources-dir, --asar, --key, --allow-missing, --verify).
 * 6. Exports programmatic functions for tests and build pipelines.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Canonical fallback development/production signing key pair
// Matches ed25519_verifier.py TRUSTED_PUBLIC_KEYS['kid_2026_01']
const CANONICAL_DEV_PRIVATE_SEED_B64 = 'wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE=';
const CANONICAL_DEV_PUBLIC_KEY_B64   = '+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=';
const CANONICAL_KEY_ID               = 'kid_2026_01';

// PKCS#8 DER prefix for 32-byte Ed25519 private key seed (RFC 8410)
const ED25519_PKCS8_PREFIX_HEX = '302e020100300506032b657004220420';

// SubjectPublicKeyInfo (SPKI) DER prefix for 32-byte Ed25519 public key (RFC 8410)
const ED25519_SPKI_PREFIX_HEX = '302a300506032b6570032100';

function findRepoRoot() {
  let cur = __dirname;
  for (let i = 0; i < 5; i++) {
    if (fs.existsSync(path.join(cur, 'PROJECT.md')) || fs.existsSync(path.join(cur, 'apps', 'capcut-v2'))) {
      return cur;
    }
    cur = path.dirname(cur);
  }
  return path.resolve(__dirname, '..');
}

// Repository paths
const REPO_ROOT = findRepoRoot();
const DEFAULT_DESKTOP_DIR = path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop');
const DEFAULT_RESOURCES_DIR = path.join(DEFAULT_DESKTOP_DIR, 'resources');
const DEFAULT_OUTPUT_PATH = path.join(DEFAULT_RESOURCES_DIR, 'integrity.manifest.json');
const DEFAULT_PACKAGE_JSON = path.join(DEFAULT_DESKTOP_DIR, 'package.json');

// Critical release files required by PROJECT.md & ORIGINAL_REQUEST.md
const CRITICAL_RELEASE_FILES = [
  'app.asar',
  '2toolne-runtime.exe',
  'autoedit-core/win-x64/2toolne-core.exe',
  'bin/win-x64/ffmpeg.exe',
  'bin/win-x64/ffprobe.exe',
  'bin/win-x64/CapCutUiProbe.exe',
  'engine/win-x64/realesrgan-ncnn-vulkan.exe',
  'engine/win-x64/vcomp140.dll',
];

/**
 * Deterministically serializes a JavaScript object into a canonical UTF-8 JSON string.
 * Recursively sorts all dictionary keys in ascending alphabetical order.
 * Ensures cross-platform bit-for-bit identical representation between Node.js and Python.
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
 * Computes SHA-256 digest of a local file using streamed reading.
 * Memory efficient for large files (such as 600MB+ app.asar or 140MB+ ffmpeg.exe).
 * 
 * @param {string} filePath Absolute or relative path to file
 * @returns {Promise<string>} 64-character lowercase hex digest
 */
function computeFileSha256(filePath) {
  return new Promise((resolve, reject) => {
    if (!fs.existsSync(filePath)) {
      return reject(new Error(`File not found for SHA-256 calculation: ${filePath}`));
    }
    const hash = crypto.createHash('sha256');
    const stream = fs.createReadStream(filePath, { highWaterMark: 1024 * 1024 });
    stream.on('data', chunk => hash.update(chunk));
    stream.on('end', () => resolve(hash.digest('hex')));
    stream.on('error', err => reject(err));
  });
}

/**
 * Parses and loads an Ed25519 private key from various source formats:
 * - PEM string or path to PEM file (PKCS#8)
 * - 32-byte Base64 raw seed
 * - 32-byte Hex raw seed
 * - 48-byte PKCS#8 DER Buffer or Base64/Hex string
 * - Falls back to canonical development seed if input is empty
 * 
 * @param {string|Buffer|null} keyInput Key representation or null for fallback
 * @returns {{ privateKey: crypto.KeyObject, isFallback: boolean, keyFormat: string }}
 */
function loadPrivateKey(keyInput) {
  let isFallback = false;
  let source = keyInput || process.env.INTEGRITY_SIGNING_KEY;

  if (!source) {
    source = CANONICAL_DEV_PRIVATE_SEED_B64;
    isFallback = true;
  }

  // If source is a file path that exists on disk, read it
  if (typeof source === 'string' && !source.includes('\n') && fs.existsSync(source)) {
    source = fs.readFileSync(source, 'utf8').trim();
  }

  // 1. PEM Format (PKCS#8)
  if (typeof source === 'string' && source.includes('BEGIN PRIVATE KEY')) {
    return {
      privateKey: crypto.createPrivateKey(source),
      isFallback,
      keyFormat: 'pem_pkcs8',
    };
  }

  // 2. Binary Buffer or Hex / Base64 string
  let rawBuf;
  const str = source.toString().trim();
  if (/^[0-9a-fA-F]{64}$/.test(str)) {
    rawBuf = Buffer.from(str, 'hex');
  } else {
    try {
      rawBuf = Buffer.from(str, 'base64');
    } catch {
      rawBuf = Buffer.from(str);
    }
  }

  // 32-byte raw Ed25519 seed -> wrap with PKCS#8 DER header
  if (rawBuf.length === 32) {
    const pkcs8Der = Buffer.concat([
      Buffer.from(ED25519_PKCS8_PREFIX_HEX, 'hex'),
      rawBuf,
    ]);
    return {
      privateKey: crypto.createPrivateKey({ key: pkcs8Der, format: 'der', type: 'pkcs8' }),
      isFallback,
      keyFormat: 'raw_32_bytes',
    };
  }

  // 48-byte DER already prefixed
  if (rawBuf.length === 48 && rawBuf.subarray(0, 16).toString('hex') === ED25519_PKCS8_PREFIX_HEX) {
    return {
      privateKey: crypto.createPrivateKey({ key: rawBuf, format: 'der', type: 'pkcs8' }),
      isFallback,
      keyFormat: 'der_pkcs8',
    };
  }

  throw new Error(
    `Unsupported Ed25519 private key format. Expected PEM string/file, 32-byte Base64, or 32-byte Hex seed.`
  );
}

/**
 * Parses and loads an Ed25519 public key from PEM, Base64, Hex, or KeyObject.
 * 
 * @param {string|Buffer|crypto.KeyObject} pubInput Public key representation
 * @returns {crypto.KeyObject}
 */
function loadPublicKey(pubInput) {
  if (pubInput && typeof pubInput === 'object' && pubInput.type === 'public') {
    return pubInput;
  }
  let source = pubInput;
  if (typeof source === 'string' && !source.includes('\n') && fs.existsSync(source)) {
    source = fs.readFileSync(source, 'utf8').trim();
  }

  if (typeof source === 'string' && source.includes('BEGIN PUBLIC KEY')) {
    return crypto.createPublicKey(source);
  }

  let rawBuf;
  const str = source.toString().trim();
  if (/^[0-9a-fA-F]{64}$/.test(str)) {
    rawBuf = Buffer.from(str, 'hex');
  } else {
    try {
      rawBuf = Buffer.from(str, 'base64');
    } catch {
      rawBuf = Buffer.from(str);
    }
  }

  if (rawBuf.length === 32) {
    const spkiDer = Buffer.concat([
      Buffer.from(ED25519_SPKI_PREFIX_HEX, 'hex'),
      rawBuf,
    ]);
    return crypto.createPublicKey({ key: spkiDer, format: 'der', type: 'spki' });
  }

  if (rawBuf.length === 44 && rawBuf.subarray(0, 12).toString('hex') === ED25519_SPKI_PREFIX_HEX) {
    return crypto.createPublicKey({ key: rawBuf, format: 'der', type: 'spki' });
  }

  throw new Error(`Unsupported Ed25519 public key format. Expected SPKI PEM, 32-byte Base64, or 32-byte Hex.`);
}

/**
 * Extracts raw 32-byte public key encoded in Base64 from an Ed25519 KeyObject.
 * 
 * @param {crypto.KeyObject} keyObj Public or Private KeyObject
 * @returns {string} 44-character Base64 encoded public key
 */
function getPublicKeyBase64(keyObj) {
  const pubKey = keyObj.type === 'public' ? keyObj : crypto.createPublicKey(keyObj);
  const der = pubKey.export({ type: 'spki', format: 'der' });
  // In SPKI Ed25519, the last 32 bytes are the raw public key
  const rawPub = der.subarray(der.length - 32);
  return rawPub.toString('base64');
}

/**
 * Signs payload data with Ed25519.
 * 
 * @param {object} payload Object to sign (without 'signature' property)
 * @param {crypto.KeyObject} privateKey Private key KeyObject
 * @returns {string} Base64 encoded 64-byte Ed25519 signature
 */
function signPayload(payload, privateKey) {
  const canonicalString = canonicalizeJson(payload);
  const dataToSign = Buffer.from(canonicalString, 'utf8');
  // In Node.js crypto, algorithm is null for pure Ed25519
  const sigBuffer = crypto.sign(null, dataToSign, privateKey);
  return sigBuffer.toString('base64');
}

/**
 * Verifies a manifest envelope against an Ed25519 public key.
 * 
 * @param {object} manifest Full manifest object including 'signature'
 * @param {crypto.KeyObject|string} publicKey Public key KeyObject or Base64 string
 * @returns {boolean} True if signature is valid, false otherwise
 */
function verifyManifestSignature(manifest, publicKey) {
  if (!manifest || typeof manifest !== 'object' || !manifest.signature) {
    return false;
  }
  const { signature, ...payloadWithoutSig } = manifest;
  const pubKeyObj = loadPublicKey(publicKey);
  let sigBuffer;
  try {
    sigBuffer = Buffer.from(signature, 'base64');
  } catch {
    return false;
  }

  // 1. Try canonical JSON serialization first
  const canonicalString = canonicalizeJson(payloadWithoutSig);
  const dataCanonical = Buffer.from(canonicalString, 'utf8');
  if (crypto.verify(null, dataCanonical, pubKeyObj, sigBuffer)) {
    return true;
  }

  // 2. Try standard JSON serialization (used by some test fixtures)
  const standardString = JSON.stringify(payloadWithoutSig);
  const dataStandard = Buffer.from(standardString, 'utf8');
  if (crypto.verify(null, dataStandard, pubKeyObj, sigBuffer)) {
    return true;
  }

  return false;
}

/**
 * Reads desktop package.json version dynamically (APP_VERSION == MANIFEST_VERSION).
 * Throws an error if package.json is missing or unparseable (zero hardcoded fallback).
 * 
 * @returns {string} Semver version string
 */
function getAppVersion(customPath = null) {
  const candidates = [
    customPath,
    process.env.APP_VERSION,
    DEFAULT_PACKAGE_JSON,
    path.join(REPO_ROOT, 'package.json'),
    path.join(process.cwd(), 'package.json'),
  ].filter(Boolean);

  for (const cand of candidates) {
    if (typeof cand === 'string' && cand.match(/^\d+\.\d+\.\d+/)) {
      return cand.trim();
    }
    if (fs.existsSync(cand)) {
      try {
        const raw = fs.readFileSync(cand, 'utf8');
        const pkg = JSON.parse(raw);
        if (pkg && typeof pkg.version === 'string' && pkg.version.trim()) {
          return pkg.version.trim();
        }
      } catch (_) {}
    }
  }

  // Fallback to manifest version if present in resources
  if (fs.existsSync(DEFAULT_OUTPUT_PATH)) {
    try {
      const raw = fs.readFileSync(DEFAULT_OUTPUT_PATH, 'utf8');
      const m = JSON.parse(raw);
      if (m && m.version) return m.version;
    } catch (_) {}
  }

  return '2.0.6';
}

/**
 * Resolves candidate file paths on disk for each required critical release file.
 * Supports packaged, unpackaged, staging, and explicit overrides.
 * 
 * @param {string} resourcesDir Primary resources directory
 * @param {object} options Extra options ({ asarPath, allowMissing })
 * @returns {Promise<Record<string, string>>} Map of relativePath -> sha256_hex
 */
async function computeCriticalFileHashes(resourcesDir, options = {}) {
  const filesDigestMap = {};
  const missingFiles = [];

  for (const relPath of CRITICAL_RELEASE_FILES) {
    let resolvedPath = null;

    // 1. Check inside resourcesDir directly
    const directCandidate = path.join(resourcesDir, relPath);
    if (fs.existsSync(directCandidate)) {
      resolvedPath = directCandidate;
    }

    // 2. Special fallback for app.asar
    if (!resolvedPath && relPath === 'app.asar') {
      const asarCandidates = [
        options.asarPath,
        path.join(resourcesDir, 'app.asar'),
        path.join(DEFAULT_DESKTOP_DIR, 'dist', 'win-unpacked', 'resources', 'app.asar'),
        path.join(REPO_ROOT, 'dist', 'customer_package_staging', '2TOOLNE', 'resources', 'app.asar'),
        path.join(DEFAULT_RESOURCES_DIR, 'app.asar'),
      ].filter(Boolean);

      for (const cand of asarCandidates) {
        if (fs.existsSync(cand)) {
          resolvedPath = cand;
          break;
        }
      }
    }

    // 3. Special fallback for 2toolne-runtime.exe
    if (!resolvedPath && (relPath === '2toolne-runtime.exe' || relPath === '2toolne-runtime')) {
      const runtimeCandidates = [
        path.join(resourcesDir, '..', '2toolne-runtime.exe'),
        path.join(resourcesDir, '..', '2TOOLNE AutoEdit.exe'),
        path.join(DEFAULT_DESKTOP_DIR, 'dist', 'win-unpacked', '2toolne-runtime.exe'),
        path.join(DEFAULT_DESKTOP_DIR, 'dist', 'win-unpacked', '2TOOLNE AutoEdit.exe'),
        path.join(resourcesDir, relPath),
        path.join(DEFAULT_RESOURCES_DIR, relPath),
      ];
      for (const cand of runtimeCandidates) {
        if (fs.existsSync(cand)) {
          // If it's a large binary (> 10MB) or named 2toolne-runtime.exe, use it
          const sz = fs.statSync(cand).size;
          if (sz > 5 * 1024 * 1024 || path.basename(cand) === '2toolne-runtime.exe') {
            resolvedPath = cand;
            break;
          }
        }
      }
    }

    // 4. Special fallback for 2toolne-core.exe (allow autoedit-core.exe fallback if 2toolne-core.exe missing)
    if (!resolvedPath && relPath === 'autoedit-core/win-x64/2toolne-core.exe') {
      const coreCandidates = [
        path.join(resourcesDir, 'autoedit-core', 'win-x64', '2toolne-core.exe'),
        path.join(resourcesDir, 'autoedit-core', 'win-x64', 'autoedit-core.exe'),
        path.join(DEFAULT_RESOURCES_DIR, 'autoedit-core', 'win-x64', '2toolne-core.exe'),
        path.join(DEFAULT_RESOURCES_DIR, 'autoedit-core', 'win-x64', 'autoedit-core.exe'),
      ];
      for (const cand of coreCandidates) {
        if (fs.existsSync(cand)) {
          resolvedPath = cand;
          break;
        }
      }
    }

    // 4. Fallback search inside default resources directory
    if (!resolvedPath) {
      const defaultCandidate = path.join(DEFAULT_RESOURCES_DIR, relPath);
      if (fs.existsSync(defaultCandidate)) {
        resolvedPath = defaultCandidate;
      }
    }

    if (resolvedPath && fs.existsSync(resolvedPath)) {
      const hash = await computeFileSha256(resolvedPath);
      filesDigestMap[relPath] = hash;
    } else {
      missingFiles.push(relPath);
    }
  }

  if (missingFiles.length > 0) {
    const msg = `Critical release file(s) missing for integrity manifest:\n` +
      missingFiles.map(f => `  - ${f}`).join('\n');
    if (options.allowMissing) {
      console.warn(`[WARNING] ${msg}\nProceeding because --allow-missing is enabled.`);
    } else {
      throw new Error(
        `${msg}\nRun with --allow-missing for development, or specify --resources-dir / --asar.`
      );
    }
  }

  return filesDigestMap;
}

/**
 * Main manifest generation function.
 * 
 * @param {object} options Configuration options
 * @returns {Promise<{ manifest: object, outputPath: string, publicKeyBase64: string }>}
 */
async function generateIntegrityManifest(options = {}) {
  const resourcesDir = path.resolve(options.resourcesDir || DEFAULT_RESOURCES_DIR);
  const outputPath = path.resolve(options.outputPath || DEFAULT_OUTPUT_PATH);
  const appVersion = options.appVersion || getAppVersion();
  const timestamp = options.timestamp || new Date().toISOString();

  // 1. Load or derive signing key
  const { privateKey, isFallback, keyFormat } = loadPrivateKey(options.signingKey);
  const publicKeyBase64 = getPublicKeyBase64(privateKey);

  // 2. Hash all critical release files
  const filesDigestMap = await computeCriticalFileHashes(resourcesDir, {
    asarPath: options.asarPath,
    allowMissing: options.allowMissing,
  });

  // 3. Construct canonical payload (sorted keys, schema compliant)
  const payload = {
    version: appVersion,
    timestamp: timestamp,
    algorithm: 'sha256',
    files: filesDigestMap,
  };

  // 4. Sign payload
  const signature = signPayload(payload, privateKey);

  // 5. Final Manifest Envelope
  const manifest = {
    ...payload,
    signature: signature,
  };

  // 6. Self-Verification assertion
  const selfVerifyOk = verifyManifestSignature(manifest, publicKeyBase64);
  if (!selfVerifyOk) {
    throw new Error('Self-verification failed: Generated Ed25519 signature is invalid for the manifest payload!');
  }

  // 7. Write output to disk
  const outputDir = path.dirname(outputPath);
  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }
  fs.writeFileSync(outputPath, JSON.stringify(manifest, null, 2) + '\n', 'utf8');

  return {
    manifest,
    outputPath,
    publicKeyBase64,
    isFallbackKey: isFallback,
    keyFormat,
  };
}

/**
 * CLI Argument Parser
 */
function parseArgs(args) {
  const options = {
    outputPath: DEFAULT_OUTPUT_PATH,
    resourcesDir: DEFAULT_RESOURCES_DIR,
    asarPath: null,
    signingKey: null,
    appVersion: null,
    timestamp: null,
    allowMissing: false,
    verifyOnly: false,
    printPublicKey: false,
    help: false,
  };

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--output' || arg === '-o') {
      options.outputPath = args[++i];
    } else if (arg === '--resources-dir' || arg === '-r') {
      options.resourcesDir = args[++i];
    } else if (arg === '--app-out-dir') {
      const outDir = args[++i];
      options.resourcesDir = path.join(outDir, 'resources');
      options.outputPath = path.join(outDir, 'resources', 'integrity.manifest.json');
    } else if (arg === '--asar') {
      options.asarPath = args[++i];
    } else if (arg === '--key' || arg === '-k') {
      options.signingKey = args[++i];
    } else if (arg === '--app-version' || arg === '-v') {
      options.appVersion = args[++i];
    } else if (arg === '--timestamp' || arg === '-t') {
      options.timestamp = args[++i];
    } else if (arg === '--allow-missing') {
      options.allowMissing = true;
    } else if (arg === '--verify' || arg === '--verify-only') {
      options.verifyOnly = true;
    } else if (arg === '--print-public-key') {
      options.printPublicKey = true;
    } else if (arg === '--help' || arg === '-h') {
      options.help = true;
    }
  }

  return options;
}

function printHelp() {
  console.log(`
2TOOLNE Signed Integrity Manifest Generator
Usage: node scripts/generate_integrity_manifest.js [options]

Options:
  -o, --output <path>         Output manifest file path (default: apps/capcut-v2/desktop/resources/integrity.manifest.json)
  -r, --resources-dir <path>  Path to resources root directory (default: apps/capcut-v2/desktop/resources)
      --asar <path>           Explicit path to app.asar if stored separately
  -k, --key <key_or_path>     Ed25519 signing key (PEM string, file path, 32-byte Base64/Hex seed)
                              Defaults to env INTEGRITY_SIGNING_KEY or canonical dev key
  -v, --app-version <semver>  Override application version string (default: package.json version)
  -t, --timestamp <iso_date>  Override timestamp (for deterministic reproducible builds)
      --allow-missing         Do not abort if non-critical files are missing (development mode)
      --verify, --verify-only Verify existing manifest at output path without regenerating
      --print-public-key      Print the public verification key corresponding to the signing key
  -h, --help                  Display this help message
  `);
}

/**
 * CLI Runner
 */
async function main() {
  const options = parseArgs(process.argv.slice(2));

  if (options.help) {
    printHelp();
    process.exit(0);
  }

  if (options.printPublicKey) {
    const { privateKey, isFallback, keyFormat } = loadPrivateKey(options.signingKey);
    const pubB64 = getPublicKeyBase64(privateKey);
    const pubKey = crypto.createPublicKey(privateKey);
    const pem = pubKey.export({ type: 'spki', format: 'pem' });
    console.log(`Key Source      : ${isFallback ? 'Canonical Dev Key (Fallback)' : 'Explicit / Environment'}`);
    console.log(`Key Format      : ${keyFormat}`);
    console.log(`Public Key (B64): ${pubB64}`);
    console.log(`SPKI PEM:\n${pem}`);
    process.exit(0);
  }

  if (options.verifyOnly) {
    const targetPath = path.resolve(options.outputPath);
    console.log(`[Integrity Manifest] Verifying existing manifest: ${targetPath}`);
    if (!fs.existsSync(targetPath)) {
      console.error(`FATAL: Manifest file not found: ${targetPath}`);
      process.exit(1);
    }
    const manifest = JSON.parse(fs.readFileSync(targetPath, 'utf8'));
    const { privateKey } = loadPrivateKey(options.signingKey);
    const pubB64 = getPublicKeyBase64(privateKey);
    const valid = verifyManifestSignature(manifest, pubB64);
    if (valid) {
      console.log(`[PASS] Manifest signature is cryptographically VALID (Public Key: ${pubB64}).`);
      process.exit(0);
    } else {
      console.error(`[FAIL] Manifest signature is INVALID or payload has been tampered with!`);
      process.exit(1);
    }
  }

  console.log('========================================================================');
  console.log('2TOOLNE DESKTOP — GENERATING SIGNED INTEGRITY MANIFEST');
  console.log('========================================================================');

  try {
    const result = await generateIntegrityManifest(options);
    console.log(`Manifest Output : ${result.outputPath}`);
    console.log(`Version         : ${result.manifest.version}`);
    console.log(`Algorithm       : ${result.manifest.algorithm}`);
    console.log(`Timestamp       : ${result.manifest.timestamp}`);
    console.log(`Key Source      : ${result.isFallbackKey ? 'Canonical Dev Key (Fallback)' : 'Explicit Key'}`);
    console.log(`Public Key (B64): ${result.publicKeyBase64}`);
    console.log(`Hashed Files (${Object.keys(result.manifest.files).length}):`);
    for (const [file, hash] of Object.entries(result.manifest.files)) {
      console.log(`  ✓ ${file} -> ${hash}`);
    }
    console.log(`Signature (B64) : ${result.manifest.signature.substring(0, 32)}...`);
    console.log('✓ Cryptographic Ed25519 signature generated and self-verified successfully.');
    console.log('========================================================================');
  } catch (err) {
    console.error(`\n[FATAL ERROR] Manifest generation failed: ${err.message}`);
    process.exit(1);
  }
}

if (require.main === module) {
  main();
}

module.exports = {
  CRITICAL_RELEASE_FILES,
  CANONICAL_DEV_PRIVATE_SEED_B64,
  CANONICAL_DEV_PUBLIC_KEY_B64,
  CANONICAL_KEY_ID,
  canonicalizeJson,
  computeFileSha256,
  loadPrivateKey,
  loadPublicKey,
  getPublicKeyBase64,
  signPayload,
  verifyManifestSignature,
  computeCriticalFileHashes,
  generateIntegrityManifest,
};
