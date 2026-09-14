#!/usr/bin/env node
/**
 * scripts/verify_release_manifest.js
 *
 * RELEASE MANIFEST VERIFICATION (2TOOLNE Release Standard §19).
 *
 * Independently verifies a packaged integrity.manifest.json:
 *   1. The Ed25519 signature is valid for the supplied public key.
 *   2. Every protected file hash recorded in the manifest matches the file
 *      actually present in the packaged release tree.
 *
 * This runs in CI against the artifact built by the same workflow, using the
 * disposable CI trust pair (ephemeral Ed25519 key) — or the production public
 * key when a production signing key was available.
 *
 * Usage:
 *   node scripts/verify_release_manifest.js \
 *     --resources <packaged resources dir> \
 *     --public-key <base64 raw 32-byte Ed25519 public key | PEM path>
 *
 * Prints MANIFEST_SIGNATURE=PASS|FAIL and MANIFEST_HASH_COVERAGE=PASS|FAIL.
 * Exits non-zero if either check fails.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const REPO_ROOT = path.resolve(__dirname, '..');
const {
  CRITICAL_RELEASE_FILES,
  computeFileSha256,
  verifyManifestSignature,
} = require(path.join(REPO_ROOT, 'scripts', 'generate_integrity_manifest.js'));

function parseArgs(argv) {
  const args = { resources: null, publicKey: null };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--resources') args.resources = argv[++i];
    else if (argv[i] === '--public-key') args.publicKey = argv[++i];
  }
  return args;
}

/**
 * Resolves a protected file from the packaged tree.
 * Mirrors the resolution logic used at manifest generation time.
 */
function resolveProtectedFile(resourcesDir, relPath) {
  const normalizedRel = relPath.replace(/\\/g, '/');
  const candidates = [
    path.join(resourcesDir, normalizedRel),
    path.join(resourcesDir, 'app', normalizedRel),
    path.join(resourcesDir, 'resources', normalizedRel),
    // Packaged root directory (parent of resources) — e.g. 2toolne-runtime.exe on Windows
    path.join(resourcesDir, '..', normalizedRel),
    path.join(path.dirname(resourcesDir), normalizedRel),
    // macOS app bundle Contents/MacOS/
    path.join(resourcesDir, '..', 'MacOS', normalizedRel),
    path.join(path.dirname(resourcesDir), 'MacOS', normalizedRel),
  ];
  for (const c of candidates) {
    if (fs.existsSync(c)) return c;
  }
  return null;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));

  if (!args.resources || !args.publicKey) {
    console.error('Usage: node scripts/verify_release_manifest.js --resources <dir> --public-key <key>');
    process.exit(2);
  }

  console.log('==============================================================================');
  console.log('RELEASE MANIFEST VERIFICATION (Release Standard §19)');
  console.log('==============================================================================');
  console.log(`  Resources : ${args.resources}`);
  console.log(`  Public key: ${args.publicKey.slice(0, 24)}...`);

  const manifestPath = path.join(args.resources, 'integrity.manifest.json');
  if (!fs.existsSync(manifestPath)) {
    console.error(`\nMANIFEST_SIGNATURE=FAIL`);
    console.error(`Manifest not found at ${manifestPath}`);
    process.exit(1);
  }

  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  } catch (err) {
    console.error(`\nMANIFEST_SIGNATURE=FAIL`);
    console.error(`Manifest is not valid JSON: ${err.message}`);
    process.exit(1);
  }

  console.log(`  Version   : ${manifest.version}`);
  console.log(`  Algorithm : ${manifest.algorithm}`);
  console.log(`  Files     : ${Object.keys(manifest.files || {}).length}`);

  // ---------------------------------------------------------------------------
  // 1. Signature verification
  // ---------------------------------------------------------------------------
  let signatureOk = false;
  try {
    signatureOk = verifyManifestSignature(manifest, args.publicKey);
  } catch (err) {
    console.error(`  Signature check threw: ${err.message}`);
    signatureOk = false;
  }
  console.log(`\nMANIFEST_SIGNATURE=${signatureOk ? 'PASS' : 'FAIL'}`);

  // ---------------------------------------------------------------------------
  // 2. Hash coverage verification
  // ---------------------------------------------------------------------------
  let coverageOk = true;
  const fileEntries = Object.entries(manifest.files || {});

  if (fileEntries.length === 0) {
    console.error('  Manifest contains no file hashes — coverage cannot be proven.');
    coverageOk = false;
  }

  const expected = new Set(CRITICAL_RELEASE_FILES);

  for (const [relPath, expectedHash] of fileEntries) {
    const resolved = resolveProtectedFile(args.resources, relPath);
    if (!resolved) {
      console.error(`  [MISSING] ${relPath} — not found in packaged tree`);
      coverageOk = false;
      continue;
    }
    const actualHash = await computeFileSha256(resolved);
    if (actualHash !== expectedHash) {
      console.error(`  [MISMATCH] ${relPath}`);
      console.error(`      expected: ${expectedHash}`);
      console.error(`      actual  : ${actualHash}`);
      coverageOk = false;
    } else {
      console.log(`  ✓ ${relPath}`);
    }
    expected.delete(relPath);
  }

  // Every declared critical file must actually be covered by the manifest.
  if (expected.size > 0) {
    console.error(`  Manifest does not cover all critical release files. Missing entries:`);
    for (const relPath of expected) {
      console.error(`      - ${relPath}`);
    }
    coverageOk = false;
  }

  console.log(`\nMANIFEST_HASH_COVERAGE=${coverageOk ? 'PASS' : 'FAIL'}`);

  console.log('\n==============================================================================');
  if (signatureOk && coverageOk) {
    console.log('  MANIFEST VERIFICATION PASSED');
    console.log('==============================================================================');
    process.exit(0);
  }
  console.log('  MANIFEST VERIFICATION FAILED');
  console.log('==============================================================================');
  process.exit(1);
}

main().catch(err => {
  console.error(`Unexpected error: ${err.stack}`);
  process.exit(1);
});
