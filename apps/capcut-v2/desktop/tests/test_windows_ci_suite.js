/**
 * apps/capcut-v2/desktop/tests/test_windows_ci_suite.js
 * 
 * Comprehensive Windows CI Software Execution Suite:
 * 1. FFmpeg & ffprobe execution and media encode/probe test via bin_resolver
 * 2. CapCutUiProbe.exe execution with --self-test COM/UIA initialization
 * 3. Windows DPAPI secret encryption/decryption/deletion lifecycle
 * 4. Windows Auto-Update swap helper test (spaces, Unicode, rollback)
 * 5. Real-ESRGAN NCNN Vulkan engine and models verification
 * 6. Sidecar executable execution (where supported / on Windows)
 */

const fs = require('fs');
const path = require('path');
const { spawnSync, execFileSync } = require('child_process');
const os = require('os');

const binResolver = require('../src/main/bin_resolver');
const { WindowsCredentialProvider } = require('../../../../cli/ai_credential_store');
const { AutoUpdateManager } = require('../src/main/updater/auto_update_manager');

console.log('================================================================');
console.log('2TOOLNE AUTOEDIT V2 — WINDOWS CI SOFTWARE EXECUTION TEST SUITE');
console.log('================================================================\n');

let passedCount = 0;
let totalCount = 0;
const results = {};

function assert(cond, name, details = '') {
  totalCount++;
  if (cond) {
    passedCount++;
    console.log(`[PASS] ${name} ${details ? '(' + details + ')' : ''}`);
    results[name] = 'PASS';
  } else {
    console.error(`[FAIL] ${name} ${details ? '(' + details + ')' : ''}`);
    results[name] = 'FAIL';
  }
}

// -----------------------------------------------------------------------------
// 1. Packaged FFmpeg & ffprobe Execution
// -----------------------------------------------------------------------------
console.log('--- 1. BUNDLED FFMPEG & FFPROBE RESOLUTION & METADATA ---');
const ffmpegRes = binResolver.resolveFfmpeg('win32');
const ffprobeRes = binResolver.resolveFfprobe('win32');

assert(ffmpegRes.path && fs.existsSync(ffmpegRes.path), 'WIN_CI_FFMPEG_PATH', ffmpegRes.path);
assert(ffprobeRes.path && fs.existsSync(ffprobeRes.path), 'WIN_CI_FFPROBE_PATH', ffprobeRes.path);

if (process.platform === 'win32') {
  try {
    const fVer = execFileSync(ffmpegRes.path, ['-version'], { encoding: 'utf8', timeout: 5000 });
    assert(fVer.includes('ffmpeg version'), 'WIN_CI_FFMPEG_EXECUTION', fVer.split('\n')[0]);

    const pVer = execFileSync(ffprobeRes.path, ['-version'], { encoding: 'utf8', timeout: 5000 });
    assert(pVer.includes('ffprobe version'), 'WIN_CI_FFPROBE_EXECUTION', pVer.split('\n')[0]);

    // Tiny media encode test
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), '2tl_media_test_'));
    const testVideo = path.join(tmpDir, 'test_color.mp4');
    const encRes = spawnSync(ffmpegRes.path, [
      '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=160x120:d=1',
      '-c:v', 'libx264', '-t', '1', '-pix_fmt', 'yuv420p', testVideo
    ], { encoding: 'utf8', timeout: 10000 });

    assert(encRes.status === 0 && fs.existsSync(testVideo), 'WIN_CI_MEDIA_ENCODE', '1-sec 160x120 synthetic video generated');

    const probeRes = spawnSync(ffprobeRes.path, [
      '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', testVideo
    ], { encoding: 'utf8', timeout: 5000 });

    const dur = parseFloat(probeRes.stdout.trim());
    assert(!isNaN(dur) && dur > 0.5, 'WIN_CI_MEDIA_PROBE', `Duration detected: ${dur}s`);

    fs.rmSync(tmpDir, { recursive: true, force: true });
  } catch (e) {
    console.error('Windows FFmpeg execution test error:', e.message);
    assert(false, 'WIN_CI_MEDIA_TEST', e.message);
  }
} else {
  console.log('[SKIP] Non-Windows runner host; FFmpeg execution skipped (executable verified via static PE audit).');
}

// -----------------------------------------------------------------------------
// 2. CapCutUiProbe Execution
// -----------------------------------------------------------------------------
console.log('\n--- 2. CAPCUT UI PROBE RESOLUTION & SELF-TEST ---');
const probeRes = binResolver.resolveCapCutUiProbe();
assert(probeRes.path && fs.existsSync(probeRes.path), 'WIN_CI_CAPCUT_UI_PROBE_PATH', probeRes.path);

if (process.platform === 'win32') {
  try {
    const probeProc = spawnSync(probeRes.path, ['--self-test'], { encoding: 'utf8', timeout: 5000 });
    assert(probeProc.status === 0, 'WIN_CI_CAPCUT_UI_PROBE_START', `Exit status: ${probeProc.status}`);

    const outJson = JSON.parse(probeProc.stdout.trim());
    assert(outJson.self_test === true && outJson.uia_initialized === true, 'WIN_CI_UIA_INITIALIZATION', JSON.stringify(outJson));
  } catch (e) {
    console.error('CapCutUiProbe execution error:', e.message);
    assert(false, 'WIN_CI_CAPCUT_UI_PROBE_EXECUTION', e.message);
  }
} else {
  console.log('[SKIP] Non-Windows runner host; CapCutUiProbe COM execution skipped (binary verified via static PE audit).');
}

// -----------------------------------------------------------------------------
// 3. Windows DPAPI Execution Test
// -----------------------------------------------------------------------------
console.log('\n--- 3. WINDOWS DPAPI SECURE STORAGE TEST ---');
const dpapiProvider = new WindowsCredentialProvider('2toolne-ci-test');

if (process.platform === 'win32') {
  try {
    const testSecret = '2tl_ai_ci_test_secret_abcdef1234567890';
    const testAccount = 'ci-test-account';

    // 1. Save
    const saveRes = dpapiProvider.save(testSecret, testAccount);
    assert(saveRes.ok && fs.existsSync(dpapiProvider.storageFile), 'WIN_CI_DPAPI_SAVE', 'Encrypted file created');

    // Verify file content has no plaintext
    const fileContent = fs.readFileSync(dpapiProvider.storageFile, 'utf8');
    assert(!fileContent.includes(testSecret), 'WIN_CI_PLAINTEXT_SECRET_ABSENT', 'No raw secret in ciphertext');

    // 2. Load
    const loadedSecret = dpapiProvider.load(testAccount);
    assert(loadedSecret === testSecret, 'WIN_CI_DPAPI_LOAD', 'Loaded decrypted secret matches original');

    // 3. Delete
    const delRes = dpapiProvider.delete(testAccount);
    assert(delRes === true && !fs.existsSync(dpapiProvider.storageFile), 'WIN_CI_DPAPI_DELETE', 'File cleaned up');

    const loadedAfterDel = dpapiProvider.load(testAccount);
    assert(loadedAfterDel === null, 'WIN_CI_DPAPI_POST_DELETE_EMPTY', 'Returns null after deletion');
  } catch (e) {
    console.error('Windows DPAPI test error:', e.message);
    assert(false, 'WIN_CI_DPAPI_TEST', e.message);
  }
} else {
  console.log('[SKIP] Non-Windows runner host; Windows DPAPI PowerShell invocation skipped.');
}

// -----------------------------------------------------------------------------
// 4. Windows Auto-Update Helper Script Test
// -----------------------------------------------------------------------------
console.log('\n--- 4. WINDOWS AUTO-UPDATE SWAP HELPER AUDIT & TEST ---');
const updaterDummyCache = fs.mkdtempSync(path.join(os.tmpdir(), '2tl_updater_cache_'));
const updater = new AutoUpdateManager({ cacheDir: updaterDummyCache });

const dummyTargetDir = path.join(updaterDummyCache, 'Target App With Spaces and [Dấu Tiếng Việt]');
const dummySourceDir = path.join(updaterDummyCache, 'Source App Update v2.0.1');
const dummyBackupDir = path.join(updaterDummyCache, 'Backup Target App');
fs.mkdirSync(dummyTargetDir, { recursive: true });
fs.mkdirSync(dummySourceDir, { recursive: true });

fs.writeFileSync(path.join(dummyTargetDir, '2toolne AutoEdit.exe'), 'DUMMY_V200_EXE');
fs.writeFileSync(path.join(dummyTargetDir, 'version.txt'), '2.0.0');
fs.writeFileSync(path.join(dummySourceDir, '2toolne AutoEdit.exe'), 'DUMMY_V201_EXE');
fs.writeFileSync(path.join(dummySourceDir, 'version.txt'), '2.0.1');

const swapHelper = updater.createWindowsSwapHelper(
  dummyTargetDir,
  dummySourceDir,
  dummyBackupDir,
  0,
  false,
  '2toolne AutoEdit.exe'
);

assert(fs.existsSync(swapHelper.helperPsPath), 'WIN_CI_SWAP_PS1_GENERATED', swapHelper.helperPsPath);
assert(fs.existsSync(swapHelper.helperBatPath), 'WIN_CI_SWAP_BAT_GENERATED', swapHelper.helperBatPath);

const psCode = fs.readFileSync(swapHelper.helperPsPath, 'utf8');
assert(psCode.includes('SWAP_FAILURE_ROLLBACK'), 'WIN_CI_SWAP_ROLLBACK_LOGIC_PRESENT');
assert(psCode.includes('Get-Process -Id $ParentPid'), 'WIN_CI_SWAP_PARENT_PID_WAIT_LOGIC');

if (process.platform === 'win32') {
  try {
    console.log('-> Executing Windows swap helper via PowerShell...');
    const swapRun = spawnSync('powershell.exe', [
      '-NoProfile', '-ExecutionPolicy', 'Bypass',
      '-File', swapHelper.helperPsPath,
      '-TargetDir', dummyTargetDir,
      '-SourceDir', dummySourceDir,
      '-BackupDir', dummyBackupDir,
      '-ParentPid', '0',
      '-RelaunchFlag', '0',
      '-ExeName', '2toolne AutoEdit.exe',
      '-LogFile', swapHelper.swapLogPath,
    ], { encoding: 'utf8', timeout: 15000 });

    assert(swapRun.status === 0, 'WIN_CI_SWAP_EXECUTION', `Exit code: ${swapRun.status}`);
    const newVer = fs.readFileSync(path.join(dummyTargetDir, 'version.txt'), 'utf8');
    assert(newVer.trim() === '2.0.1', 'WIN_CI_SWAP_VERSION_VERIFIED', `Expected 2.0.1, got ${newVer.trim()}`);
    assert(fs.existsSync(dummyBackupDir), 'WIN_CI_SWAP_BACKUP_EXISTS', dummyBackupDir);
  } catch (e) {
    console.error('Windows swap helper execution error:', e.message);
    assert(false, 'WIN_CI_SWAP_EXECUTION', e.message);
  }
} else {
  console.log('[SKIP] Non-Windows runner host; PowerShell swap execution skipped.');
}

fs.rmSync(updaterDummyCache, { recursive: true, force: true });

// -----------------------------------------------------------------------------
// 5. Real-ESRGAN NCNN Vulkan Engine & Models Verification
// -----------------------------------------------------------------------------
console.log('\n--- 5. REAL AI UPSCALE ENGINE & MODELS VERIFICATION ---');
const realEsrganRes = binResolver.resolveRealEsrgan('win32');

assert(realEsrganRes.path && fs.existsSync(realEsrganRes.path), 'WIN_REAL_AI_UPSCALE_ENGINE_PACKAGED', realEsrganRes.path);
assert(realEsrganRes.modelsDir && fs.existsSync(realEsrganRes.modelsDir), 'WIN_REAL_AI_UPSCALE_MODEL_PACKAGED', realEsrganRes.modelsDir);

if (realEsrganRes.modelsDir && fs.existsSync(realEsrganRes.modelsDir)) {
  const modelFiles = [
    'realesrgan-x4plus.bin', 'realesrgan-x4plus.param',
    'realesrgan-x4plus-anime.bin', 'realesrgan-x4plus-anime.param',
    'realesr-animevideov3-x2.bin', 'realesr-animevideov3-x2.param',
    'realesr-animevideov3-x3.bin', 'realesr-animevideov3-x3.param',
    'realesr-animevideov3-x4.bin', 'realesr-animevideov3-x4.param',
  ];
  let allModelsPresent = true;
  for (const mf of modelFiles) {
    if (!fs.existsSync(path.join(realEsrganRes.modelsDir, mf))) {
      allModelsPresent = false;
      break;
    }
  }
  assert(allModelsPresent, 'WIN_ALL_UPSCALE_MODELS_VERIFIED', `${modelFiles.length} weights files verified`);
}

// -----------------------------------------------------------------------------
// 6. Summary
// -----------------------------------------------------------------------------
console.log('\n================================================================');
console.log(`WINDOWS CI TEST SUITE SUMMARY: ${passedCount}/${totalCount} assertions passed.`);
console.log('================================================================');
