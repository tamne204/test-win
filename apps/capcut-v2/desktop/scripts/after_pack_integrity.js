#!/usr/bin/env node
/**
 * apps/capcut-v2/desktop/scripts/after_pack_integrity.js
 * 
 * electron-builder `afterPack` lifecycle hook for 2TOOLNE Desktop (Milestone M2)
 * 
 * Responsibilities:
 * 1. Executes immediately after electron-builder packages app.asar and stages extraResources.
 * 2. Computes streaming SHA-256 digests for sealed app.asar and all 6 native binaries in appOutDir.
 * 3. Derives application version dynamically from package.json (APP_VERSION == MANIFEST_VERSION).
 * 4. Signs integrity.manifest.json with Ed25519 and writes to <appOutDir>/resources/ ONLY.
 *    The manifest and app.asar are NEVER written back into the tracked source tree
 *    (Release Standard §12 — BUILD_DIRTIES_SOURCE_TREE must be NO).
 * 5. Decouples launcher:
 *    - Windows: Renames Electron executable (2TOOLNE AutoEdit.exe -> 2toolne-runtime.exe)
 *      and stages compiled native verifier binary as 2TOOLNE AutoEdit.exe.
 *    - macOS: Renames Electron executable (Contents/MacOS/2TOOLNE AutoEdit -> Contents/MacOS/2toolne-runtime)
 *      and stages compiled native verifier binary as Contents/MacOS/2TOOLNE AutoEdit.
 * 6. HARD GATE (Release Standard §7): in a production build, a missing native root launcher
 *    is a FATAL error, not a warning. Continuing would ship a renamed Electron runtime under
 *    the launcher name (ROOT_OF_TRUST_BYPASS=YES). Set NATIVE_ROOT_OPTIONAL=1 for local dev only.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

/**
 * Synchronous SHA-256 of a file, used for launcher/runtime identity assertions
 * inside the otherwise-synchronous decoupleLauncher step.
 */
function sha256FileSync(filePath) {
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function findRepoRoot() {
  let cur = __dirname;
  for (let i = 0; i < 6; i++) {
    if (fs.existsSync(path.join(cur, 'PROJECT.md')) || fs.existsSync(path.join(cur, 'apps', 'capcut-v2'))) {
      return cur;
    }
    cur = path.dirname(cur);
  }
  return path.resolve(__dirname, '../../../../');
}

const REPO_ROOT = findRepoRoot();
const DESKTOP_DIR = path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop');
const MANIFEST_GEN_PATH = path.join(REPO_ROOT, 'scripts', 'generate_integrity_manifest.js');

const {
  CRITICAL_RELEASE_FILES,
  computeFileSha256,
  loadPrivateKey,
  getPublicKeyBase64,
  signPayload,
  verifyManifestSignature,
} = require(MANIFEST_GEN_PATH);

/**
 * Resolves the dynamic version from desktop package.json.
 * Fails secure if missing or invalid.
 */
function resolveAppVersion() {
  const pkgPath = path.join(DESKTOP_DIR, 'package.json');
  if (!fs.existsSync(pkgPath)) {
    throw new Error(`[afterPack] Critical error: package.json missing at ${pkgPath}`);
  }
  const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
  if (!pkg || typeof pkg.version !== 'string' || !pkg.version.trim()) {
    throw new Error(`[afterPack] Critical error: invalid version in ${pkgPath}`);
  }
  return pkg.version.trim();
}

/**
 * Locates the resources directory inside context.appOutDir across platforms.
 */
function resolveResourcesDir(appOutDir) {
  // 1. Standard Windows / Linux layout
  const directResources = path.join(appOutDir, 'resources');
  if (fs.existsSync(directResources)) {
    return directResources;
  }

  // 2. macOS app bundle layout (<appOutDir>/*.app/Contents/Resources)
  if (fs.existsSync(appOutDir)) {
    const entries = fs.readdirSync(appOutDir);
    for (const entry of entries) {
      if (entry.endsWith('.app')) {
        const macResources = path.join(appOutDir, entry, 'Contents', 'Resources');
        if (fs.existsSync(macResources)) {
          return macResources;
        }
      }
    }
  }

  // Fallback to direct resources path even if not created yet
  return directResources;
}

/**
 * Decouples the Electron runtime and stages the native verifier binary as the launcher.
 */
function decoupleLauncher(appOutDir, electronPlatformName) {
  const isWindows = (electronPlatformName === 'win32') ||
    fs.existsSync(path.join(appOutDir, '2TOOLNE AutoEdit.exe')) ||
    fs.existsSync(path.join(appOutDir, '2toolne-runtime.exe'));

  if (isWindows) {
    const electronExe = path.join(appOutDir, '2TOOLNE AutoEdit.exe');
    const runtimeExe = path.join(appOutDir, '2toolne-runtime.exe');
    const nativeRootSrc = path.join(DESKTOP_DIR, 'dist', 'native_root', '2TOOLNE AutoEdit.exe');

    if (fs.existsSync(electronExe)) {
      const stats = fs.statSync(electronExe);
      // Only rename if it's the large Electron runtime (> 10 MB) or runtime doesn't exist yet
      if (!fs.existsSync(runtimeExe) || stats.size > 10 * 1024 * 1024) {
        if (fs.existsSync(runtimeExe)) {
          fs.unlinkSync(runtimeExe);
        }
        fs.renameSync(electronExe, runtimeExe);
        console.log(`[afterPack] Renamed Electron runtime -> ${path.basename(runtimeExe)} (${(stats.size / 1024 / 1024).toFixed(1)} MB)`);
      }
    }

    if (process.env.NATIVE_ROOT_OPTIONAL === '1') {
      // Explicit local-dev opt-out. Never valid for a release build.
      console.warn(`[afterPack][DEV-ONLY] Native root Windows binary not found at ${nativeRootSrc} (NATIVE_ROOT_OPTIONAL=1)`);
    } else if (fs.existsSync(nativeRootSrc)) {
      fs.copyFileSync(nativeRootSrc, electronExe);
      const stagedStats = fs.statSync(electronExe);
      console.log(`[afterPack] Staged native root launcher -> ${path.basename(electronExe)} (${(stagedStats.size / 1024 / 1024).toFixed(2)} MB)`);

      // Release Standard §8: launcher must equal the built native root byte-for-byte and the
      // runtime must be a DIFFERENT binary. Ship-blocking if either assertion fails.
      const nativeRootSha256 = sha256FileSync(nativeRootSrc);
      const packagedLauncherSha256 = sha256FileSync(electronExe);
      const electronRuntimeSha256 = fs.existsSync(runtimeExe) ? sha256FileSync(runtimeExe) : null;

      console.log(`[afterPack] NATIVE_ROOT_SHA256          = ${nativeRootSha256}`);
      console.log(`[afterPack] PACKAGED_LAUNCHER_SHA256   = ${packagedLauncherSha256}`);
      console.log(`[afterPack] ELECTRON_RUNTIME_SHA256    = ${electronRuntimeSha256 || '(absent)'}`);
      console.log(`[afterPack] NATIVE_ROOT_HASH_MATCH     = ${nativeRootSha256 === packagedLauncherSha256 ? 'YES' : 'NO'}`);
      console.log(`[afterPack] LAUNCHER_RUNTIME_SAME_BINARY = ${electronRuntimeSha256 && electronRuntimeSha256 === packagedLauncherSha256 ? 'YES' : 'NO'}`);

      if (nativeRootSha256 !== packagedLauncherSha256) {
        throw new Error('[afterPack] NATIVE_ROOT_HASH_MATCH=NO — staged launcher does not match the built native root.');
      }
      if (electronRuntimeSha256 && electronRuntimeSha256 === packagedLauncherSha256) {
        throw new Error('[afterPack] LAUNCHER_RUNTIME_SAME_BINARY=YES — launcher is the renamed Electron runtime, not the native root.');
      }
    } else {
      throw new Error(
        `[afterPack] Native root Windows binary not found at ${nativeRootSrc}. ` +
        'Production packaging requires the Go native root build (Release Standard §7). ' +
        'Refusing to ship a renamed Electron runtime as the launcher. ' +
        'Set NATIVE_ROOT_OPTIONAL=1 only for local development.'
      );
    }
  }

  // macOS app bundle layout
  if (fs.existsSync(appOutDir)) {
    const entries = fs.readdirSync(appOutDir);
    for (const entry of entries) {
      if (entry.endsWith('.app')) {
        const macOsDir = path.join(appOutDir, entry, 'Contents', 'MacOS');
        if (fs.existsSync(macOsDir)) {
          const electronBin = path.join(macOsDir, '2TOOLNE AutoEdit');
          const runtimeBin = path.join(macOsDir, '2toolne-runtime');
          const nativeRootSrc = path.join(DESKTOP_DIR, 'dist', 'native_root', '2TOOLNE AutoEdit');

          if (fs.existsSync(electronBin)) {
            const stats = fs.statSync(electronBin);
            if (!fs.existsSync(runtimeBin) || stats.size > 10 * 1024 * 1024) {
              if (fs.existsSync(runtimeBin)) {
                fs.unlinkSync(runtimeBin);
              }
              fs.renameSync(electronBin, runtimeBin);
              fs.chmodSync(runtimeBin, 0o755);
              console.log(`[afterPack] Renamed macOS Electron runtime -> ${path.basename(runtimeBin)} (${(stats.size / 1024 / 1024).toFixed(1)} MB)`);
            }
          }

          if (fs.existsSync(nativeRootSrc)) {
            fs.copyFileSync(nativeRootSrc, electronBin);
            fs.chmodSync(electronBin, 0o755);
            const stagedStats = fs.statSync(electronBin);
            console.log(`[afterPack] Staged native root macOS launcher -> ${path.basename(electronBin)} (${(stagedStats.size / 1024 / 1024).toFixed(2)} MB)`);
          } else if (process.env.NATIVE_ROOT_OPTIONAL === '1') {
            console.warn(`[afterPack][DEV-ONLY] Native root macOS binary not found at ${nativeRootSrc} (NATIVE_ROOT_OPTIONAL=1)`);
          } else {
            throw new Error(
              `[afterPack] Native root macOS binary not found at ${nativeRootSrc}. ` +
              'Production packaging requires the Go native root build (Release Standard §7). ' +
              'Refusing to ship a renamed Electron runtime as the launcher. ' +
              'Set NATIVE_ROOT_OPTIONAL=1 only for local development.'
            );
          }
        }
      }
    }
  }
}

/**
 * Main afterPack hook handler for electron-builder.
 * 
 * @param {object} context electron-builder pack context ({ appOutDir, packager, electronPlatformName, arch })
 */
async function afterPackIntegrity(context) {
  const appOutDir = context?.appOutDir || path.join(DESKTOP_DIR, 'dist', 'win-unpacked');
  const electronPlatformName = context?.electronPlatformName || (process.platform === 'win32' ? 'win32' : 'darwin');

  console.log('========================================================================');
  console.log('  2TOOLNE DESKTOP: AFTER-PACK INTEGRITY & LAUNCHER DECOUPLING HOOK');
  console.log('========================================================================');
  console.log(`Target Output Dir : ${appOutDir}`);
  console.log(`Target Platform   : ${electronPlatformName}`);

  if (!fs.existsSync(appOutDir)) {
    throw new Error(`[afterPack] Output directory does not exist: ${appOutDir}`);
  }

  const resourcesDir = resolveResourcesDir(appOutDir);
  console.log(`Resources Dir     : ${resourcesDir}`);
  if (!fs.existsSync(resourcesDir)) {
    throw new Error(`[afterPack] Resources directory not found in: ${appOutDir}`);
  }

  // 1. Derive Dynamic Version
  const appVersion = resolveAppVersion();
  console.log(`Dynamic Version   : ${appVersion} (APP_VERSION == MANIFEST_VERSION)`);

  // 2. Decouple Launcher FIRST (Rename Electron runtime and stage native root verifier)
  // Ensures 2toolne-runtime.exe and native launcher exist in their final positions before hashing/signing
  console.log('\nDecoupling launcher and staging native root verifier before hashing...');
  decoupleLauncher(appOutDir, electronPlatformName);

  // 3. Compute Streaming SHA-256 for app.asar, 2toolne-runtime.exe, and all critical native binaries
  console.log('\nComputing streaming SHA-256 for sealed packaged components (post-decoupling)...');
  const repoResourcesDir = path.join(DESKTOP_DIR, 'resources');
  const filesDigestMap = {};

  for (const relPath of CRITICAL_RELEASE_FILES) {
    let targetPath = path.join(resourcesDir, relPath);
    if (!fs.existsSync(targetPath)) {
      if (relPath === '2toolne-runtime.exe' || relPath === '2toolne-runtime') {
        const runtimeCandidates = [
          path.join(appOutDir, '2toolne-runtime.exe'),
          path.join(path.dirname(resourcesDir), '2toolne-runtime.exe'),
          path.join(appOutDir, 'Contents', 'MacOS', '2toolne-runtime'),
          path.join(DESKTOP_DIR, 'dist', 'win-unpacked', '2toolne-runtime.exe'),
          path.join(REPO_ROOT, 'apps', 'capcut-v2', 'desktop', 'dist', 'win-unpacked', '2toolne-runtime.exe'),
        ];
        for (const cand of runtimeCandidates) {
          if (fs.existsSync(cand)) {
            targetPath = cand;
            break;
          }
        }
      }
      if (!fs.existsSync(targetPath)) {
        // Check repository resources directory fallback if packaging cross-platform
        const repoPath = path.join(repoResourcesDir, relPath);
        if (fs.existsSync(repoPath)) {
          targetPath = repoPath;
        } else {
          throw new Error(`[afterPack] Critical release file missing: ${relPath} (checked ${targetPath} and ${repoPath})`);
        }
      }
    }

    const digest = await computeFileSha256(targetPath);
    filesDigestMap[relPath] = digest;
    const isPackagedAsar = (relPath === 'app.asar' && targetPath.startsWith(resourcesDir));
    console.log(`  ✓ ${relPath} -> ${digest} ${isPackagedAsar ? '[SEALED ASAR]' : ''}`);
  }

  // 4. Construct Canonical Payload and Sign with Ed25519
  const payload = {
    version: appVersion,
    timestamp: new Date().toISOString(),
    algorithm: 'sha256',
    files: filesDigestMap,
  };

  const { privateKey, isFallback } = loadPrivateKey();
  const publicKeyBase64 = getPublicKeyBase64(privateKey);
  const signature = signPayload(payload, privateKey);

  const manifest = {
    ...payload,
    signature,
  };

  // 5. Verify Signature
  const isValid = verifyManifestSignature(manifest, publicKeyBase64);
  if (!isValid) {
    throw new Error('[afterPack] Self-verification failed for generated manifest signature!');
  }

  // 6. Write Signed Manifest to Packaged Resources ONLY
  // The manifest/app.asar are generated build outputs. Writing them back into the tracked
  // source tree would dirty the working tree on every build (Release Standard §12).
  const manifestDestPackaged = path.join(resourcesDir, 'integrity.manifest.json');
  const manifestJsonStr = JSON.stringify(manifest, null, 2) + '\n';

  fs.writeFileSync(manifestDestPackaged, manifestJsonStr, 'utf8');
  console.log(`\n✓ Written signed manifest to: ${manifestDestPackaged}`);
  console.log('  (packaged artifact only — source tree intentionally left untouched)');

  console.log('========================================================================');
  console.log('  AFTER-PACK INTEGRITY SYNCHRONIZATION COMPLETED SUCCESSFULLY');
  console.log('========================================================================\n');

  return true;
}

// Standalone CLI invocation
if (require.main === module) {
  const args = process.argv.slice(2);
  let appOutDir = null;
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--appOutDir' || args[i] === '-d') {
      appOutDir = args[++i];
    }
  }
  afterPackIntegrity({ appOutDir })
    .then(() => process.exit(0))
    .catch((err) => {
      console.error(`\n[afterPack][FATAL ERROR] ${err.message}`);
      process.exit(1);
    });
}

module.exports = afterPackIntegrity;
