#!/usr/bin/env node
/**
 * tests/release_audit.js
 * 
 * 2TOOLNE Windows CI Lab - Release Manifest & Integrity Verification
 * Verifies Ed25519 manifest signature and multi-binary SHA-256 digests.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Canonical Production Ed25519 Public Key
const CANONICAL_ED25519_PUBLIC_KEY = '+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=';

const CRITICAL_FILES = [
  'app.asar',
  '2toolne-runtime.exe',
  'autoedit-core/win-x64/2toolne-core.exe',
  'bin/win-x64/ffmpeg.exe',
  'bin/win-x64/ffprobe.exe',
  'bin/win-x64/CapCutUiProbe.exe',
  'engine/win-x64/realesrgan-ncnn-vulkan.exe',
  'engine/win-x64/vcomp140.dll',
];

const args = process.argv.slice(2);
let resourcesDir = process.env.RESOURCES_DIR || null;
let outputFile = process.env.AUDIT_OUTPUT_FILE || 'manifest-audit.json';

for (let i = 0; i < args.length; i++) {
  if (args[i] === '--resources' || args[i] === '-r') resourcesDir = args[++i];
  if (args[i] === '--output' || args[i] === '-o') outputFile = args[++i];
}

if (!resourcesDir) {
  const candidates = [
    'resources',
    'win-unpacked/resources',
    'apps/capcut-v2/desktop/dist/win-unpacked/resources',
  ];
  for (const c of candidates) {
    if (fs.existsSync(c)) {
      resourcesDir = path.resolve(c);
      break;
    }
  }
}

if (!resourcesDir || !fs.existsSync(resourcesDir)) {
  console.error(`[ReleaseAudit][FATAL] Resources directory not found: ${resourcesDir || '(none)'}`);
  process.exit(1);
}

const manifestPath = path.join(resourcesDir, 'integrity.manifest.json');
if (!fs.existsSync(manifestPath)) {
  console.error(`[ReleaseAudit][FATAL] integrity.manifest.json missing at: ${manifestPath}`);
  process.exit(1);
}

function computeSha256(filePath) {
  return new Promise((resolve, reject) => {
    const hash = crypto.createHash('sha256');
    const stream = fs.createReadStream(filePath);
    stream.on('data', (d) => hash.update(d));
    stream.on('end', () => resolve(hash.digest('hex').toLowerCase()));
    stream.on('error', reject);
  });
}

function canonicalize(payload) {
  const keys = Object.keys(payload).sort();
  const sorted = {};
  for (const k of keys) {
    if (k === 'files') {
      const fKeys = Object.keys(payload[k]).sort();
      sorted[k] = {};
      for (const fk of fKeys) {
        sorted[k][fk] = payload[k][fk];
      }
    } else {
      sorted[k] = payload[k];
    }
  }
  return Buffer.from(JSON.stringify(sorted), 'utf8');
}

function verifySignature(manifest, pubKeyBase64) {
  try {
    const { signature, ...payload } = manifest;
    const rawPub = Buffer.from(pubKeyBase64, 'base64');
    let keyObject;

    if (rawPub.length === 32) {
      const spkiHeader = Buffer.from('302a300506032b6570032100', 'hex');
      const der = Buffer.concat([spkiHeader, rawPub]);
      keyObject = crypto.createPublicKey({ key: der, format: 'der', type: 'spki' });
    } else {
      keyObject = crypto.createPublicKey({ key: rawPub, format: 'der', type: 'spki' });
    }

    const data = canonicalize(payload);
    const sigBuf = Buffer.from(signature, 'base64');
    return crypto.verify(null, data, keyObject, sigBuf);
  } catch (err) {
    console.error(`[ReleaseAudit] Signature verification error: ${err.message}`);
    return false;
  }
}

async function main() {
  console.log('======================================================================');
  console.log('2TOOLNE WINDOWS CI LAB — RELEASE MANIFEST INTEGRITY AUDIT');
  console.log('======================================================================');
  console.log(`Resources Directory: ${resourcesDir}`);
  console.log(`Manifest File      : ${manifestPath}`);
  console.log('======================================================================\n');

  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const sigValid = verifySignature(manifest, CANONICAL_ED25519_PUBLIC_KEY);

  console.log(`Manifest Version    : ${manifest.version}`);
  console.log(`Algorithm           : ${manifest.algorithm}`);
  console.log(`Ed25519 Signature   : ${sigValid ? 'VALID [PASS]' : 'INVALID [FAIL]'}`);

  const table = [];
  let allMatched = true;

  const appOutDir = path.dirname(resourcesDir);

  for (const rel of CRITICAL_FILES) {
    let target = path.join(resourcesDir, rel);
    if (!fs.existsSync(target)) {
      if (rel === '2toolne-runtime.exe') {
        const cands = [
          path.join(appOutDir, '2toolne-runtime.exe'),
          path.join(resourcesDir, '..', '2toolne-runtime.exe'),
        ];
        for (const c of cands) {
          if (fs.existsSync(c)) {
            target = c;
            break;
          }
        }
      }
    }

    const exists = fs.existsSync(target);
    const expected = manifest.files[rel] || null;
    let actual = null;
    let match = false;

    if (exists) {
      actual = await computeSha256(target);
      match = (expected === actual);
      if (!match) allMatched = false;
    } else {
      allMatched = false;
    }

    table.push({
      file: rel,
      exists,
      expected_hash: expected,
      actual_hash: actual,
      match: match ? 'PASS' : 'FAIL',
      size_bytes: exists ? fs.statSync(target).size : 0,
    });

    console.log(`  [${match ? 'PASS' : 'FAIL'}] ${rel}`);
    console.log(`         Expected : ${expected}`);
    console.log(`         Actual   : ${actual || '(FILE MISSING)'}`);
  }

  const auditReport = {
    timestamp: new Date().toISOString(),
    resources_dir: resourcesDir,
    manifest_version: manifest.version,
    signature_valid: sigValid,
    total_manifest_files: Object.keys(manifest.files).length,
    critical_files_count: CRITICAL_FILES.length,
    all_matched: allMatched && sigValid,
    results: table,
  };

  fs.writeFileSync(outputFile, JSON.stringify(auditReport, null, 2), 'utf8');
  console.log(`\nReport written to: ${outputFile}`);
  console.log('======================================================================\n');

  process.exit(auditReport.all_matched ? 0 : 1);
}

main().catch((err) => {
  console.error('[ReleaseAudit][FATAL]', err);
  process.exit(1);
});
