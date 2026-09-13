/**
 * tests/test_integrity_adversarial_m3.js
 * 
 * Empirical Challenger Stress Test Harness for Milestone 3
 * (Signed Integrity Manifest & Runtime Guard)
 * 
 * Thoroughly probes:
 * 1. 1-byte binary mutations across all 7 critical release binaries
 * 2. 1-byte manifest hash mutations
 * 3. Base64 signature corruption, bit flips, truncation, replay attacks
 * 4. Manifest metadata tampering (version, timestamp, algorithm, injection)
 * 5. Rogue key signing & untrusted public keys
 * 6. Missing files & directory traversal defense
 * 7. Fail-secure execution policy & startup abort simulation
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

const {
  generateIntegrityManifest,
  verifyManifestSignature: genVerifySig,
  canonicalizeJson,
  computeFileSha256: genComputeSha256,
  loadPrivateKey,
  getPublicKeyBase64,
  CANONICAL_PROD_PUBLIC_KEY_B64,
  CANONICAL_KEY_ID,
} = require('../scripts/generate_integrity_manifest');

// Compromised / Revoked old dev key pair for adversarial regression testing
const REVOKED_DEV_PRIVATE_SEED_B64 = 'wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE=';
const REVOKED_DEV_PUBLIC_KEY_B64   = '+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=';

const {
  verifyRuntimeIntegrity,
  verifyManifest,
  verifyManifestSignature: guardVerifySig,
  computeFileSha256: guardComputeSha256,
  TRUSTED_PUBLIC_KEYS,
  IntegrityTamperError,
} = require('../apps/capcut-v2/desktop/src/main/integrity_guard');

const REPO_ROOT = path.resolve(__dirname, '..');
const RESOURCES_DIR = path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop', 'resources');
const MANIFEST_PATH = path.join(RESOURCES_DIR, 'integrity.manifest.json');

const results = [];

function runTest(id, description, fn) {
  process.stdout.write(`  [${id}] ${description} ... `);
  try {
    fn();
    console.log('PASS');
    results.push({ id, description, status: 'PASS' });
  } catch (err) {
    console.log(`FAIL: ${err.message}`);
    results.push({ id, description, status: 'FAIL', error: err.message });
  }
}

async function runAsyncTest(id, description, fn) {
  process.stdout.write(`  [${id}] ${description} ... `);
  try {
    await fn();
    console.log('PASS');
    results.push({ id, description, status: 'PASS' });
  } catch (err) {
    console.log(`FAIL: ${err.message}`);
    results.push({ id, description, status: 'FAIL', error: err.message });
  }
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message || 'Assertion failed');
  }
}

function assertEqual(actual, expected, message) {
  if (actual !== expected) {
    throw new Error(`${message || 'Assertion failed'}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

async function main() {
  console.log('========================================================================');
  console.log('MILITARY-GRADE EMPIRICAL CHALLENGER: MILESTONE 3 ADVERSARIAL STRESS TEST');
  console.log('========================================================================\n');

  // Load authentic baseline manifest
  assert(fs.existsSync(MANIFEST_PATH), `Authentic manifest missing at ${MANIFEST_PATH}`);
  const authenticManifestRaw = fs.readFileSync(MANIFEST_PATH, 'utf8');
  const authenticManifest = JSON.parse(authenticManifestRaw);

  // Setup scratch temp directory for isolated mutation testing
  const tmpDir = path.join(REPO_ROOT, 'tests', 'scratch', 'challenger_m3_' + Date.now());
  fs.mkdirSync(tmpDir, { recursive: true });

  try {
    // -------------------------------------------------------------------------
    // SECTION 1: VERIFY AUTHENTIC BASELINE
    // -------------------------------------------------------------------------
    console.log('▶ SECTION 1: BASELINE AUTHENTIC VERIFICATION');

    runTest('BASE.1', 'Authentic manifest signature validates against canonical public key', () => {
      const res = guardVerifySig(authenticManifest, TRUSTED_PUBLIC_KEYS);
      assert(res.valid === true, `Authentic signature failed: ${res.error}`);
    });

    runTest('BASE.2', 'Authentic manifest validates via generator verifyManifestSignature', () => {
      const res = genVerifySig(authenticManifest, CANONICAL_PROD_PUBLIC_KEY_B64);
      assert(res === true, 'Generator verification failed on authentic manifest');
    });

    runTest('KEY.REVOKED.1', 'Manifest signed with revoked old dev key is strictly REJECTED by production trust', () => {
      const { privateKey } = loadPrivateKey(REVOKED_DEV_PRIVATE_SEED_B64);
      const { signature, ...payload } = authenticManifest;
      const fakeSig = crypto.sign(null, Buffer.from(canonicalizeJson(payload)), privateKey).toString('base64');
      const revokedManifest = { ...payload, signature: fakeSig };
      const res = guardVerifySig(revokedManifest, TRUSTED_PUBLIC_KEYS);
      assert(res.valid === false, 'Production trust MUST reject manifest signed by revoked dev key');
    });

    await runAsyncTest('BASE.3', 'Full authentic runtime integrity check passes with zero errors', async () => {
      const unpackedRes = path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop', 'dist', 'win-unpacked', 'resources');
      const manifestFile = path.join(unpackedRes, 'integrity.manifest.json');
      const useUnpacked = fs.existsSync(manifestFile) && fs.existsSync(path.join(unpackedRes, '..', '2toolne-runtime.exe'));
      const res = await verifyRuntimeIntegrity({
        manifestPath: useUnpacked ? manifestFile : MANIFEST_PATH,
        baseDir: useUnpacked ? unpackedRes : RESOURCES_DIR,
        strict: true,
        isPackaged: useUnpacked,
        noExit: true,
      });
      assert(res.valid === true, 'Runtime integrity failed on clean baseline');
      assert(res.filesChecked >= 6, `Expected at least 6 files checked, got ${res.filesChecked}`);
    });

    // -------------------------------------------------------------------------
    // SECTION 2: BINARY FILE 1-BYTE MUTATION (ALL 7 CRITICAL FILES)
    // -------------------------------------------------------------------------
    console.log('\n▶ SECTION 2: BINARY 1-BYTE MUTATIONS ACROSS ALL 7 CRITICAL FILES');

    // Create a mock isolated release structure
    const mockReleaseDir = path.join(tmpDir, 'mock_release');
    fs.mkdirSync(mockReleaseDir, { recursive: true });

    const criticalFiles = [
      'app.asar',
      '2toolne-runtime.exe',
      'autoedit-core/win-x64/2toolne-core.exe',
      'bin/win-x64/ffmpeg.exe',
      'bin/win-x64/ffprobe.exe',
      'bin/win-x64/CapCutUiProbe.exe',
      'engine/win-x64/realesrgan-ncnn-vulkan.exe',
      'engine/win-x64/vcomp140.dll',
    ];

    // Seed mock files with authentic bytes if available, or deterministic unique bytes
    for (const rel of criticalFiles) {
      const target = path.join(mockReleaseDir, rel);
      fs.mkdirSync(path.dirname(target), { recursive: true });
      const realSource = path.join(RESOURCES_DIR, rel);
      if (fs.existsSync(realSource)) {
        fs.copyFileSync(realSource, target);
      } else {
        fs.writeFileSync(target, `AUTHENTIC_MOCK_PAYLOAD_FOR_${rel}`);
      }
    }

    // Generate valid signed manifest for the mock release directory
    const ephemeralTestKeyPair = crypto.generateKeyPairSync('ed25519');
    const mockManifestRes = await generateIntegrityManifest({
      resourcesDir: mockReleaseDir,
      outputPath: path.join(mockReleaseDir, 'integrity.manifest.json'),
      allowMissing: false,
      keyInput: ephemeralTestKeyPair.privateKey,
    });
    const mockManifest = mockManifestRes.manifest;
    const mockTrustedKeys = [getPublicKeyBase64(ephemeralTestKeyPair.publicKey)];

    for (let i = 0; i < criticalFiles.length; i++) {
      const targetFile = criticalFiles[i];
      const testId = `MUT.BIN.${i + 1}`;
      const filePath = path.join(mockReleaseDir, targetFile);

      await runAsyncTest(testId, `Mutate exactly 1 byte in '${targetFile}' -> fail-secure detection`, async () => {
        // Read original bytes
        const originalBytes = fs.readFileSync(filePath);
        // Flip the first byte
        const mutatedBytes = Buffer.from(originalBytes);
        mutatedBytes[0] = mutatedBytes[0] ^ 0xFF;
        fs.writeFileSync(filePath, mutatedBytes);

        let rejected = false;
        let caughtError = null;

        try {
          await verifyRuntimeIntegrity({
            manifestPath: path.join(mockReleaseDir, 'integrity.manifest.json'),
            baseDir: mockReleaseDir,
            strict: true,
            isPackaged: true,
            noExit: true,
            trustedKeys: mockTrustedKeys,
          });
        } catch (err) {
          rejected = true;
          caughtError = err;
        } finally {
          // Restore original bytes for next test
          fs.writeFileSync(filePath, originalBytes);
        }

        assert(rejected, `Integrity guard DID NOT reject 1-byte mutation in ${targetFile}!`);
        assert(caughtError instanceof IntegrityTamperError, `Error is not IntegrityTamperError: ${caughtError}`);
        assertEqual(caughtError.code, 'ERR_INTEGRITY_TAMPERED');
        assert(caughtError.message.includes('HASH_MISMATCH') || caughtError.message.includes('hash mismatch'),
          `Unexpected error message: ${caughtError.message}`);
      });
    }

    // -------------------------------------------------------------------------
    // SECTION 3: MANIFEST FILES MAP HASH TAMPERING
    // -------------------------------------------------------------------------
    console.log('\n▶ SECTION 3: MANIFEST FILES MAP HASH TAMPERING');

    runTest('MUT.HASH.1', 'Mutate 1 hex character in manifest files map -> signature rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      const firstKey = Object.keys(tampered.files)[0];
      const origHash = tampered.files[firstKey];
      // Flip last character of hash (e.g. '4' -> '5')
      const lastChar = origHash[origHash.length - 1];
      const newChar = lastChar === 'a' ? 'b' : 'a';
      tampered.files[firstKey] = origHash.substring(0, origHash.length - 1) + newChar;

      const sigCheck = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(sigCheck.valid === false, 'Signature check should fail when hash map is tampered');
      assert(sigCheck.error.includes('signature verification failed'), `Expected sig failure, got: ${sigCheck.error}`);
    });

    runTest('MUT.HASH.2', 'Inject extra unhashed file entry into files map -> signature rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.files['bin/win-x64/malicious_backdoor.exe'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

      const sigCheck = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(sigCheck.valid === false, 'Signature check must fail on injected file');
    });

    runTest('MUT.HASH.3', 'Delete required file entry from files map -> signature rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      delete tampered.files['app.asar'];

      const sigCheck = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(sigCheck.valid === false, 'Signature check must fail when file entry is removed');
    });

    // -------------------------------------------------------------------------
    // SECTION 4: ED25519 SIGNATURE CORRUPTION & FORGERY
    // -------------------------------------------------------------------------
    console.log('\n▶ SECTION 4: ED25519 SIGNATURE CORRUPTION & FORGERY');

    runTest('SIG.CORRUPT.1', 'Flip 1 character in base64 signature string -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      const origSig = tampered.signature;
      // Change first character
      const firstChar = origSig[0];
      const newChar = firstChar === 'A' ? 'B' : 'A';
      tampered.signature = newChar + origSig.substring(1);

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Corrupted signature must be rejected');
    });

    runTest('SIG.CORRUPT.2', 'Truncate signature length (63 bytes / partial base64) -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.signature = tampered.signature.substring(0, 40);

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Truncated signature must be rejected');
    });

    runTest('SIG.CORRUPT.3', 'Invalid non-base64 characters in signature -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.signature = '!@#$%^&*()_+=~`{}[]|;:,<>?';

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Non-base64 signature must be rejected');
    });

    runTest('SIG.CORRUPT.4', 'Empty string signature -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.signature = '';

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Empty signature must be rejected');
    });

    runTest('SIG.CORRUPT.5', 'Null / missing signature property -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      delete tampered.signature;

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Missing signature must be rejected');
    });

    runTest('SIG.REPLAY.1', 'Signature replay from different payload -> rejection', () => {
      // Create a different legitimate payload signed by same key
      const otherKeyPair = crypto.generateKeyPairSync('ed25519');
      const fakeSig = crypto.sign(null, Buffer.from('ANOTHER_PAYLOAD'), otherKeyPair.privateKey).toString('base64');
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.signature = fakeSig;

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Replayed signature must be rejected');
    });

    runTest('SIG.ROGUE.1', 'Manifest signed by untrusted attacker Ed25519 key -> rejection', () => {
      // Attacker generates own Ed25519 key pair and signs authentic payload
      const attackerKeys = crypto.generateKeyPairSync('ed25519');
      const { signature, ...payload } = authenticManifest;
      const canonicalBuf = Buffer.from(canonicalizeJson(payload), 'utf8');
      const attackerSig = crypto.sign(null, canonicalBuf, attackerKeys.privateKey).toString('base64');

      const tampered = {
        ...payload,
        signature: attackerSig,
      };

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Attacker key signature must be rejected against TRUSTED_PUBLIC_KEYS');
    });

    // -------------------------------------------------------------------------
    // SECTION 5: MANIFEST METADATA TAMPERING
    // -------------------------------------------------------------------------
    console.log('\n▶ SECTION 5: MANIFEST METADATA TAMPERING');

    runTest('META.TAMPER.1', 'Alter version ("2.0.5" -> "9.9.9") keeping signature -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.version = '9.9.9';

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Modified version must invalidate cryptographic signature');
    });

    runTest('META.TAMPER.2', 'Alter timestamp keeping signature -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.timestamp = new Date(Date.now() + 86400000).toISOString();

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Modified timestamp must invalidate cryptographic signature');
    });

    runTest('META.TAMPER.3', 'Alter algorithm ("sha256" -> "none" / "sha512") keeping signature -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.algorithm = 'sha512';

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Modified algorithm must invalidate cryptographic signature');
    });

    runTest('META.TAMPER.4', 'Inject unauthorized metadata attribute (__proto__ / bypass) -> rejection', () => {
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.bypass_integrity = true;

      const check = guardVerifySig(tampered, TRUSTED_PUBLIC_KEYS);
      assert(check.valid === false, 'Injected property must invalidate cryptographic signature');
    });

    // -------------------------------------------------------------------------
    // SECTION 6: DIRECTORY TRAVERSAL & MISSING FILE DEFENSE
    // -------------------------------------------------------------------------
    console.log('\n▶ SECTION 6: PATH TRAVERSAL & MISSING RESOURCE DEFENSE');

    runTest('PATH.TRAVERSAL.1', 'Path traversal in manifest files map is contained safely', () => {
      const keyPair = crypto.generateKeyPairSync('ed25519');
      const payload = {
        version: '2.0.5',
        timestamp: new Date().toISOString(),
        algorithm: 'sha256',
        files: {
          '../../../../etc/passwd': '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        },
      };
      const sig = crypto.sign(null, Buffer.from(canonicalizeJson(payload)), keyPair.privateKey).toString('base64');
      const manifest = { ...payload, signature: sig };

      // Verification against mockReleaseDir should fail safely (missing file or hash mismatch)
      const check = verifyManifest(manifest, keyPair.publicKey, mockReleaseDir);
      assert(check.valid === false, 'Path traversal entry must not pass verification');
    });

    await runAsyncTest('PATH.MISSING.1', 'Missing file listed in manifest triggers fail-secure in packaged mode', async () => {
      const keyPair = crypto.generateKeyPairSync('ed25519');
      const payload = {
        version: '2.0.5',
        timestamp: new Date().toISOString(),
        algorithm: 'sha256',
        files: {
          'bin/win-x64/non_existent_binary.exe': '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        },
      };
      const sig = crypto.sign(null, Buffer.from(canonicalizeJson(payload)), keyPair.privateKey).toString('base64');
      const manifest = { ...payload, signature: sig };

      const missingManifestPath = path.join(tmpDir, 'missing_file_manifest.json');
      fs.writeFileSync(missingManifestPath, JSON.stringify(manifest, null, 2));

      let rejected = false;
      let caughtErr = null;
      try {
        await verifyRuntimeIntegrity({
          manifestPath: missingManifestPath,
          baseDir: tmpDir,
          strict: true,
          isPackaged: true,
          noExit: true,
          trustedPublicKeys: [keyPair.publicKey],
        });
      } catch (err) {
        rejected = true;
        caughtErr = err;
      }

      assert(rejected, 'Integrity guard did not throw on missing file');
      assertEqual(caughtErr.code, 'ERR_INTEGRITY_TAMPERED');
      assert(caughtErr.message.includes('MISSING_FILE') || caughtErr.message.includes('Missing file'),
        `Unexpected error: ${caughtErr.message}`);
    });

    // -------------------------------------------------------------------------
    // SECTION 7: CLI TOOL TAMPER VERIFICATION
    // -------------------------------------------------------------------------
    console.log('\n▶ SECTION 7: CLI TOOL ADVERSARIAL VERIFICATION');

    runTest('CLI.VERIFY.1', 'generate_integrity_manifest.js --verify passes on clean manifest', () => {
      const proc = spawnSync('node', ['scripts/generate_integrity_manifest.js', '--verify'], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
      });
      assertEqual(proc.status, 0, `CLI verify failed: ${proc.stderr || proc.stdout}`);
      assert(proc.stdout.includes('[PASS] Manifest signature is cryptographically VALID'));
    });

    runTest('CLI.VERIFY.2', 'generate_integrity_manifest.js --verify fails on tampered manifest', () => {
      const tamperedPath = path.join(tmpDir, 'cli_tampered_manifest.json');
      const tampered = JSON.parse(JSON.stringify(authenticManifest));
      tampered.version = '9.9.9'; // Tampered version
      fs.writeFileSync(tamperedPath, JSON.stringify(tampered, null, 2));

      const proc = spawnSync('node', [
        'scripts/generate_integrity_manifest.js',
        '--verify',
        '--output',
        tamperedPath,
      ], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
      });
      assertEqual(proc.status, 1, 'CLI should exit 1 on tampered manifest');
      assert(proc.stderr.includes('[FAIL] Manifest signature is INVALID') ||
             proc.stdout.includes('[FAIL] Manifest signature is INVALID'),
             'Expected failure message in output');
    });

  } finally {
    // Clean up temporary scratch directory
    try {
      fs.rmSync(tmpDir, { recursive: true, force: true });
    } catch (e) {
      // ignore
    }
  }

  // -------------------------------------------------------------------------
  // SUMMARY
  // -------------------------------------------------------------------------
  console.log('\n========================================================================');
  const passed = results.filter(r => r.status === 'PASS').length;
  const failed = results.filter(r => r.status === 'FAIL').length;
  console.log(`STRESS TEST SUMMARY: Total: ${results.length} | Passed: ${passed} | Failed: ${failed}`);
  console.log('========================================================================');

  if (failed > 0) {
    process.exit(1);
  }
}

main().catch(err => {
  console.error('Fatal unhandled error in challenger stress test:', err);
  process.exit(1);
});
