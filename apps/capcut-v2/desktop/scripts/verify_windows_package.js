/**
 * apps/capcut-v2/desktop/scripts/verify_windows_package.js
 * 
 * Verifies that all required Windows binaries are present, authentic PE32+ executables (magic bytes 0x4D 0x5A / 'MZ'),
 * strictly rejects non-Windows binaries (Mach-O, ELF), verifies Real-ESRGAN NCNN Vulkan engine and model weights,
 * and validates Windows-specific runtime mechanisms (swap helper rollback, binary resolver, safe paths).
 */

const fs = require('fs');
const path = require('path');

const DESKTOP_ROOT = path.resolve(__dirname, '..');
const RESOURCES_BIN = path.join(DESKTOP_ROOT, 'resources', 'bin', 'win-x64');
const RESOURCES_CORE = path.join(DESKTOP_ROOT, 'resources', 'autoedit-core', 'win-x64');
const RESOURCES_ENGINE = path.join(DESKTOP_ROOT, 'resources', 'engine', 'win-x64');
const DIST_WIN = path.join(DESKTOP_ROOT, 'dist', 'win-unpacked');

console.log('====================================================');
console.log('2TOOLNE AUTOEDIT V2 — WINDOWS PACKAGE VERIFICATION');
console.log('====================================================');

let totalChecks = 0;
let passedChecks = 0;
let failures = [];

function assert(condition, message) {
  totalChecks++;
  if (condition) {
    passedChecks++;
    console.log(`[PASS] ${message}`);
  } else {
    failures.push(message);
    console.error(`[FAIL] ${message}`);
  }
}

/**
 * Validates that file exists, is non-empty, has Windows PE magic header (0x4D, 0x5A = "MZ"),
 * and explicitly rejects Mach-O or ELF binaries.
 */
function verifyPeBinary(filePath, expectedName) {
  if (!fs.existsSync(filePath)) {
    assert(false, `Binary exists: ${expectedName} at ${filePath}`);
    return false;
  }

  const stat = fs.statSync(filePath);
  assert(stat.size > 1024, `Binary size check (> 1KB): ${expectedName} (${(stat.size / 1024 / 1024).toFixed(2)} MB)`);

  const fd = fs.openSync(filePath, 'r');
  const buffer = Buffer.alloc(4);
  fs.readSync(fd, buffer, 0, 4, 0);
  fs.closeSync(fd);

  // Reject Mach-O: 0xCF 0xFA 0xED 0xFE, 0xFE 0xED 0xFA 0xCF, 0xCE 0xFA 0xED 0xFE, 0xFE 0xED 0xFA 0xCE, 0xCA 0xFE 0xBA 0xBE
  const isMacho = (buffer[0] === 0xCF && buffer[1] === 0xFA && buffer[2] === 0xED && buffer[3] === 0xFE) ||
                  (buffer[0] === 0xFE && buffer[1] === 0xED && buffer[2] === 0xFA && buffer[3] === 0xCF) ||
                  (buffer[0] === 0xCE && buffer[1] === 0xFA && buffer[2] === 0xED && buffer[3] === 0xFE) ||
                  (buffer[0] === 0xCA && buffer[1] === 0xFE && buffer[2] === 0xBA && buffer[3] === 0xBE);
  assert(!isMacho, `Reject Mach-O magic in Windows slot: ${expectedName}`);

  // Reject ELF: 0x7F 0x45 0x4C 0x46 (\x7fELF)
  const isElf = buffer[0] === 0x7F && buffer[1] === 0x45 && buffer[2] === 0x4C && buffer[3] === 0x46;
  assert(!isElf, `Reject ELF magic in Windows slot: ${expectedName}`);

  const isMZ = buffer[0] === 0x4D && buffer[1] === 0x5A;
  assert(isMZ, `Binary PE Magic Header Check ('MZ' / 0x4D 0x5A): ${expectedName} (got 0x${buffer[0].toString(16)} 0x${buffer[1].toString(16)})`);
  return isMZ && !isMacho && !isElf;
}

/**
 * Recursively scans directory to ensure ZERO Mach-O binaries, ZERO ELF binaries,
 * and ZERO Darwin .dylib files exist in Windows packages.
 */
function auditDirectoryForCrossOsArtifacts(dirPath, label) {
  if (!fs.existsSync(dirPath)) return;
  let machoFound = 0;
  let elfFound = 0;
  let dylibFound = 0;

  function scan(currentPath) {
    const entries = fs.readdirSync(currentPath, { withFileTypes: true });
    for (const ent of entries) {
      const fullPath = path.join(currentPath, ent.name);
      if (ent.isDirectory()) {
        scan(fullPath);
      } else if (ent.isFile()) {
        if (ent.name.endsWith('.dylib')) {
          dylibFound++;
        }
        try {
          const stat = fs.statSync(fullPath);
          if (stat.size >= 4) {
            const fd = fs.openSync(fullPath, 'r');
            const buf = Buffer.alloc(4);
            fs.readSync(fd, buf, 0, 4, 0);
            fs.closeSync(fd);
            const isMacho = (buf[0] === 0xCF && buf[1] === 0xFA && buf[2] === 0xED && buf[3] === 0xFE) ||
                            (buf[0] === 0xFE && buf[1] === 0xED && buf[2] === 0xFA && buf[3] === 0xCF) ||
                            (buf[0] === 0xCE && buf[1] === 0xFA && buf[2] === 0xED && buf[3] === 0xFE) ||
                            (buf[0] === 0xCA && buf[1] === 0xFE && buf[2] === 0xBA && buf[3] === 0xBE);
            const isElf = buf[0] === 0x7F && buf[1] === 0x45 && buf[2] === 0x4C && buf[3] === 0x46;
            if (isMacho) machoFound++;
            if (isElf) elfFound++;
          }
        } catch (e) {}
      }
    }
  }

  scan(dirPath);
  assert(machoFound === 0, `Zero Mach-O binaries in ${label} (found: ${machoFound})`);
  assert(elfFound === 0, `Zero ELF binaries in ${label} (found: ${elfFound})`);
  assert(dylibFound === 0, `Zero Darwin .dylib files in ${label} (found: ${dylibFound})`);
}

console.log('\n--- 1. BUNDLED CORE & MEDIA BINARIES (win-x64) ---');
verifyPeBinary(path.join(RESOURCES_BIN, 'ffmpeg.exe'), 'ffmpeg.exe (resources)');
verifyPeBinary(path.join(RESOURCES_BIN, 'ffprobe.exe'), 'ffprobe.exe (resources)');
verifyPeBinary(path.join(RESOURCES_BIN, 'CapCutUiProbe.exe'), 'CapCutUiProbe.exe (resources)');
verifyPeBinary(path.join(RESOURCES_CORE, 'autoedit-core.exe'), 'autoedit-core.exe (resources)');
auditDirectoryForCrossOsArtifacts(RESOURCES_CORE, 'resources/autoedit-core');

console.log('\n--- 2. REAL AI UPSCALE ENGINE & MODEL WEIGHTS (win-x64) ---');
verifyPeBinary(path.join(RESOURCES_ENGINE, 'realesrgan-ncnn-vulkan.exe'), 'realesrgan-ncnn-vulkan.exe (resources)');
assert(fs.existsSync(path.join(RESOURCES_ENGINE, 'vcomp140.dll')), 'vcomp140.dll exists (resources)');
assert(!fs.existsSync(path.join(RESOURCES_ENGINE, 'vcomp140d.dll')), 'vcomp140d.dll pruned from resources (debug runtime rejected)');

const requiredModels = [
  'realesrgan-x4plus.bin', 'realesrgan-x4plus.param',
  'realesrgan-x4plus-anime.bin', 'realesrgan-x4plus-anime.param',
  'realesr-animevideov3-x2.bin', 'realesr-animevideov3-x2.param',
  'realesr-animevideov3-x3.bin', 'realesr-animevideov3-x3.param',
  'realesr-animevideov3-x4.bin', 'realesr-animevideov3-x4.param',
];

const modelsDir = path.join(RESOURCES_ENGINE, 'models');
assert(fs.existsSync(modelsDir), 'Real-ESRGAN models directory exists');
for (const mf of requiredModels) {
  const mfPath = path.join(modelsDir, mf);
  const exists = fs.existsSync(mfPath);
  const size = exists ? fs.statSync(mfPath).size : 0;
  assert(exists && size > 100, `Model file verified: ${mf} (${size} bytes)`);
}

console.log('\n--- 3. DIST / WIN-UNPACKED RESOURCES AUDIT ---');
if (fs.existsSync(DIST_WIN)) {
  const distBinDir = path.join(DIST_WIN, 'resources', 'bin', 'win-x64');
  const distCoreCandidates = [
    path.join(DIST_WIN, 'resources', 'autoedit-core', 'win-x64', 'autoedit-core.exe'),
    path.join(DIST_WIN, 'resources', 'autoedit-core', 'win-x64', '2toolne-core.exe'),
    path.join(DIST_WIN, 'resources', 'autoedit-core', 'autoedit-core.exe'),
    path.join(DIST_WIN, 'resources', 'autoedit-core', '2toolne-core.exe'),
  ];
  const distCoreExe = distCoreCandidates.find(c => fs.existsSync(c)) || distCoreCandidates[0];
  const distEngineDir = path.join(DIST_WIN, 'resources', 'engine', 'win-x64');

  verifyPeBinary(path.join(distBinDir, 'ffmpeg.exe'), 'ffmpeg.exe (dist win-unpacked)');
  verifyPeBinary(path.join(distBinDir, 'ffprobe.exe'), 'ffprobe.exe (dist win-unpacked)');
  verifyPeBinary(path.join(distBinDir, 'CapCutUiProbe.exe'), 'CapCutUiProbe.exe (dist win-unpacked)');
  verifyPeBinary(distCoreExe, `${path.basename(distCoreExe)} (dist win-unpacked)`);
  auditDirectoryForCrossOsArtifacts(path.join(DIST_WIN, 'resources', 'autoedit-core'), 'dist/win-unpacked/resources/autoedit-core');

  if (fs.existsSync(distEngineDir)) {
    verifyPeBinary(path.join(distEngineDir, 'realesrgan-ncnn-vulkan.exe'), 'realesrgan-ncnn-vulkan.exe (dist win-unpacked)');
    assert(fs.existsSync(path.join(distEngineDir, 'models', 'realesrgan-x4plus.bin')), 'realesrgan-x4plus.bin (dist win-unpacked)');
    assert(!fs.existsSync(path.join(distEngineDir, 'vcomp140d.dll')), 'vcomp140d.dll pruned from dist (win-unpacked)');
  }
} else {
  console.log('[SKIP] dist/win-unpacked directory not generated in current clean run. Skipping unpacked checks.');
}

console.log('\n--- 4. WINDOWS AUTO-UPDATE SWAP EXECUTOR INTEGRITY ---');
const autoUpdateManagerPath = path.join(DESKTOP_ROOT, 'src', 'main', 'updater', 'auto_update_manager.js');
assert(fs.existsSync(autoUpdateManagerPath), 'AutoUpdateManager file exists');

if (fs.existsSync(autoUpdateManagerPath)) {
  const code = fs.readFileSync(autoUpdateManagerPath, 'utf8');
  assert(code.includes('createWindowsSwapHelper'), 'createWindowsSwapHelper method exists');
  assert(code.includes('SWAP_FAILURE_ROLLBACK'), 'SWAP_FAILURE_ROLLBACK handling implemented');
  assert(code.includes('swap_helper.ps1') || code.includes('swap_helper.bat'), 'Windows PowerShell / Batch swap helper generator exists');
  assert(code.includes('Get-Process -Id $ParentPid'), 'PID termination wait loop in swap script');
  assert(code.includes('Copy-Item') || code.includes('robocopy'), 'Atomic replacement routine present');
}

console.log('\n--- 5. BINARY RESOLVER INTEGRITY ---');
const binResolverPath = path.join(DESKTOP_ROOT, 'src', 'main', 'bin_resolver.js');
assert(fs.existsSync(binResolverPath), 'bin_resolver.js exists');

if (fs.existsSync(binResolverPath)) {
  const resolver = require(binResolverPath);
  assert(typeof resolver.resolveFfmpeg === 'function', 'resolveFfmpeg exported');
  assert(typeof resolver.resolveFfprobe === 'function', 'resolveFfprobe exported');
  assert(typeof resolver.resolveCapCutUiProbe === 'function', 'resolveCapCutUiProbe exported');
  assert(typeof resolver.resolveRealEsrgan === 'function', 'resolveRealEsrgan exported');
  
  const capcutProbe = resolver.resolveCapCutUiProbe('win32');
  assert(capcutProbe && capcutProbe.path && fs.existsSync(capcutProbe.path), `resolveCapCutUiProbe resolves valid path (${capcutProbe ? capcutProbe.path : 'null'})`);

  const ffmpegObj = resolver.resolveFfmpeg('win32');
  assert(ffmpegObj && ffmpegObj.path && fs.existsSync(ffmpegObj.path), `resolveFfmpeg resolves valid path (${ffmpegObj ? ffmpegObj.path : 'null'})`);

  const realEsrganObj = resolver.resolveRealEsrgan('win32');
  assert(realEsrganObj && realEsrganObj.path && fs.existsSync(realEsrganObj.path), `resolveRealEsrgan resolves valid path (${realEsrganObj ? realEsrganObj.path : 'null'})`);
  assert(realEsrganObj && realEsrganObj.modelsDir && fs.existsSync(realEsrganObj.modelsDir), `resolveRealEsrgan modelsDir verified (${realEsrganObj ? realEsrganObj.modelsDir : 'null'})`);
}

console.log('\n--- 6. HARDCODED PATH AUDIT ---');
const mainIndexPath = path.join(DESKTOP_ROOT, 'src', 'main', 'index.js');
if (fs.existsSync(mainIndexPath)) {
  const mainCode = fs.readFileSync(mainIndexPath, 'utf8');
  const hasMacUserPath = mainCode.includes('/Users/2tamne/');
  assert(!hasMacUserPath, 'No hardcoded /Users/2tamne/ paths in src/main/index.js');
}

console.log('\n====================================================');
console.log(`SUMMARY: ${passedChecks}/${totalChecks} checks passed.`);
if (failures.length === 0) {
  console.log('RESULT: WINDOWS SOFTWARE PACKAGE VERIFICATION PASSED (PASS)');
  console.log('====================================================');
  process.exit(0);
} else {
  console.error(`RESULT: ${failures.length} CHECKS FAILED!`);
  failures.forEach(f => console.error(` - ${f}`));
  console.log('====================================================');
  process.exit(1);
}
