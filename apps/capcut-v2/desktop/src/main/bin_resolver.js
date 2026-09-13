/**
 * apps/capcut-v2/desktop/src/main/bin_resolver.js
 * Centralized binary resolver for bundled external tools:
 * - ffmpeg / ffmpeg.exe
 * - ffprobe / ffprobe.exe
 * - CapCutUiProbe.exe
 *
 * Resolution Priority:
 * 1. Bundled application resources (process.resourcesPath/bin/<platform-arch>/...)
 * 2. Local development project resources (__dirname/../../resources/bin/...)
 * 3. System PATH fallback (optional development fallback only)
 */

const path = require('path');
const fs = require('fs');

function getPlatformArchSubdir(targetPlatform = process.platform) {
  if (targetPlatform === 'win32') return 'win-x64';
  if (targetPlatform === 'darwin') return process.arch === 'arm64' ? 'mac-arm64' : 'mac-x64';
  return `${targetPlatform}-${process.arch}`;
}

/**
 * Resolve path to ffmpeg executable
 * @param {string} [targetPlatform=process.platform]
 * @returns {{ path: string, source: 'BUNDLED' | 'LOCAL_DEV' | 'SYSTEM_PATH' }}
 */
function resolveFfmpeg(targetPlatform = process.platform) {
  const isWin = targetPlatform === 'win32';
  const binName = isWin ? 'ffmpeg.exe' : 'ffmpeg';
  const subDir = getPlatformArchSubdir(targetPlatform);

  // 1. Packaged resources
  if (process.resourcesPath) {
    const p1 = path.join(process.resourcesPath, 'bin', subDir, binName);
    if (fs.existsSync(p1)) return { path: p1, source: 'BUNDLED' };

    const p2 = path.join(process.resourcesPath, 'bin', binName);
    if (fs.existsSync(p2)) return { path: p2, source: 'BUNDLED' };

    const p3 = path.join(process.resourcesPath, 'ffmpeg', 'bin', binName);
    if (fs.existsSync(p3)) return { path: p3, source: 'BUNDLED' };
  }

  // 2. Local project resources
  const dev1 = path.resolve(__dirname, '../../resources/bin', subDir, binName);
  if (fs.existsSync(dev1)) return { path: dev1, source: 'LOCAL_DEV' };

  const dev2 = path.resolve(__dirname, '../../dist/win-unpacked/resources/bin', subDir, binName);
  if (fs.existsSync(dev2)) return { path: dev2, source: 'LOCAL_DEV' };

  // 3. Fallback to system PATH
  return { path: binName, source: 'SYSTEM_PATH' };
}

/**
 * Resolve path to ffprobe executable
 * @param {string} [targetPlatform=process.platform]
 * @returns {{ path: string, source: 'BUNDLED' | 'LOCAL_DEV' | 'SYSTEM_PATH' }}
 */
function resolveFfprobe(targetPlatform = process.platform) {
  const isWin = targetPlatform === 'win32';
  const binName = isWin ? 'ffprobe.exe' : 'ffprobe';
  const subDir = getPlatformArchSubdir(targetPlatform);

  // 1. Packaged resources
  if (process.resourcesPath) {
    const p1 = path.join(process.resourcesPath, 'bin', subDir, binName);
    if (fs.existsSync(p1)) return { path: p1, source: 'BUNDLED' };

    const p2 = path.join(process.resourcesPath, 'bin', binName);
    if (fs.existsSync(p2)) return { path: p2, source: 'BUNDLED' };

    const p3 = path.join(process.resourcesPath, 'ffmpeg', 'bin', binName);
    if (fs.existsSync(p3)) return { path: p3, source: 'BUNDLED' };
  }

  // 2. Local project resources
  const dev1 = path.resolve(__dirname, '../../resources/bin', subDir, binName);
  if (fs.existsSync(dev1)) return { path: dev1, source: 'LOCAL_DEV' };

  const dev2 = path.resolve(__dirname, '../../dist/win-unpacked/resources/bin', subDir, binName);
  if (fs.existsSync(dev2)) return { path: dev2, source: 'LOCAL_DEV' };

  // 3. Fallback to system PATH
  return { path: binName, source: 'SYSTEM_PATH' };
}

/**
 * Resolve path to CapCutUiProbe.exe
 * @param {string} [targetPlatform=process.platform]
 * @returns {{ path: string | null, source: 'BUNDLED' | 'LOCAL_DEV' | 'NOT_FOUND' }}
 */
function resolveCapCutUiProbe(targetPlatform = process.platform) {
  const binName = 'CapCutUiProbe.exe';
  const subDir = 'win-x64';

  if (process.resourcesPath) {
    const p1 = path.join(process.resourcesPath, 'bin', subDir, binName);
    if (fs.existsSync(p1)) return { path: p1, source: 'BUNDLED' };

    const p2 = path.join(process.resourcesPath, 'bin', binName);
    if (fs.existsSync(p2)) return { path: p2, source: 'BUNDLED' };
  }

  const dev1 = path.resolve(__dirname, '../../resources/bin', subDir, binName);
  if (fs.existsSync(dev1)) return { path: dev1, source: 'LOCAL_DEV' };

  const dev2 = path.resolve(__dirname, '../../dist/win-unpacked/resources/bin', subDir, binName);
  if (fs.existsSync(dev2)) return { path: dev2, source: 'LOCAL_DEV' };

  return { path: null, source: 'NOT_FOUND' };
}

/**
 * Resolve path to Real-ESRGAN NCNN Vulkan binary and models directory
 * @param {string} [targetPlatform=process.platform]
 * @returns {{ path: string | null, modelsDir: string | null, source: 'BUNDLED' | 'LOCAL_DEV' | 'NOT_FOUND' }}
 */
function resolveRealEsrgan(targetPlatform = process.platform) {
  const isWin = targetPlatform === 'win32';
  const binName = isWin ? 'realesrgan-ncnn-vulkan.exe' : 'realesrgan-ncnn-vulkan';
  const subDir = getPlatformArchSubdir(targetPlatform);
  const os = require('os');

  // 1. Packaged resources
  if (process.resourcesPath) {
    const p1 = path.join(process.resourcesPath, 'engine', subDir, binName);
    const m1 = path.join(process.resourcesPath, 'engine', subDir, 'models');
    if (fs.existsSync(p1)) return { path: p1, modelsDir: fs.existsSync(m1) ? m1 : null, source: 'BUNDLED' };

    const p2 = path.join(process.resourcesPath, 'engine', binName);
    const m2 = path.join(process.resourcesPath, 'engine', 'models');
    if (fs.existsSync(p2)) return { path: p2, modelsDir: fs.existsSync(m2) ? m2 : null, source: 'BUNDLED' };
  }

  // 2. Local project resources
  const dev1 = path.resolve(__dirname, '../../resources/engine', subDir, binName);
  const mDev1 = path.resolve(__dirname, '../../resources/engine', subDir, 'models');
  if (fs.existsSync(dev1)) return { path: dev1, modelsDir: fs.existsSync(mDev1) ? mDev1 : null, source: 'LOCAL_DEV' };

  const dev2 = path.resolve(__dirname, '../../dist/win-unpacked/resources/engine', subDir, binName);
  const mDev2 = path.resolve(__dirname, '../../dist/win-unpacked/resources/engine', subDir, 'models');
  if (fs.existsSync(dev2)) return { path: dev2, modelsDir: fs.existsSync(mDev2) ? mDev2 : null, source: 'LOCAL_DEV' };

  // 3. Document toolupscale test package fallback (development)
  const dev3 = path.join(os.homedir(), 'Documents', 'toolupscale', 'windows-package', '2toolne-upscale-win-x64-test', 'runtime', binName);
  const mDev3 = path.join(os.homedir(), 'Documents', 'toolupscale', 'windows-package', '2toolne-upscale-win-x64-test', 'models');
  if (fs.existsSync(dev3)) return { path: dev3, modelsDir: fs.existsSync(mDev3) ? mDev3 : null, source: 'LOCAL_DEV' };

  return { path: null, modelsDir: null, source: 'NOT_FOUND' };
}

module.exports = {
  getFfmpegPath: (p) => resolveFfmpeg(p).path,
  getFfprobePath: (p) => resolveFfprobe(p).path,
  getCapCutUiProbePath: (p) => resolveCapCutUiProbe(p).path,
  getRealEsrganPath: (p) => resolveRealEsrgan(p).path,
  resolveFfmpeg,
  resolveFfprobe,
  resolveCapCutUiProbe,
  resolveRealEsrgan,
};

