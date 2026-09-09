/**
 * verify_hardened_license_package.js
 * 
 * Verifies code contract for hardened license state synchronization inside 
 * packaged/unpacked Electron app or source tree:
 * 1. PACKAGED_LICENSE_AUTHORIZATION_GUARD: server activation success alone is not enough; sidecar authorized must be true
 * 2. PACKAGED_LICENSE_STATUS_EVENT: license status change event broadcast exists
 * 3. PACKAGED_LICENSE_RENDERER_REACTIVITY: preload bridge and renderer event listener exist
 */

const fs = require('fs');
const path = require('path');

function readAsarFile(header, baseOffset, fd, relativePath) {
  const parts = relativePath.split('/');
  let curr = header;
  for (const part of parts) {
    if (!curr.files || !curr.files[part]) {
      throw new Error(`File ${relativePath} not found in asar (missing ${part})`);
    }
    curr = curr.files[part];
  }
  const offset = baseOffset + parseInt(curr.offset);
  const buf = Buffer.alloc(curr.size);
  fs.readSync(fd, buf, 0, curr.size, offset);
  return buf.toString('utf8');
}

function verifyLicenseContract(appDir) {
  let mainContent = '';
  let preloadContent = '';
  let rendererContent = '';

  const asarPath = path.join(appDir, 'resources', 'app.asar');
  if (fs.existsSync(asarPath)) {
    const fd = fs.openSync(asarPath, 'r');
    try {
      const headerBuf = Buffer.alloc(16);
      fs.readSync(fd, headerBuf, 0, 16, 0);
      const jsonLen = headerBuf.readUInt32LE(12);
      const jsonBuf = Buffer.alloc(jsonLen);
      fs.readSync(fd, jsonBuf, 0, jsonLen, 16);
      const header = JSON.parse(jsonBuf.toString('utf8'));
      const baseOffset = 16 + jsonLen;

      mainContent = readAsarFile(header, baseOffset, fd, 'src/main/index.js');
      preloadContent = readAsarFile(header, baseOffset, fd, 'src/preload/preload.js');
      rendererContent = readAsarFile(header, baseOffset, fd, 'src/renderer/app.js');
    } finally {
      fs.closeSync(fd);
    }
  } else {
    // Unpacked or source tree
    let mainPath = path.join(appDir, 'resources', 'app', 'src', 'main', 'index.js');
    let preloadPath = path.join(appDir, 'resources', 'app', 'src', 'preload', 'preload.js');
    let rendererPath = path.join(appDir, 'resources', 'app', 'src', 'renderer', 'app.js');

    if (!fs.existsSync(mainPath)) {
      mainPath = path.join(appDir, 'src', 'main', 'index.js');
      preloadPath = path.join(appDir, 'src', 'preload', 'preload.js');
      rendererPath = path.join(appDir, 'src', 'renderer', 'app.js');
    }

    if (!fs.existsSync(mainPath)) {
      throw new Error(`Main file not found at ${mainPath}`);
    }
    mainContent = fs.readFileSync(mainPath, 'utf8');
    preloadContent = fs.readFileSync(preloadPath, 'utf8');
    rendererContent = fs.readFileSync(rendererPath, 'utf8');
  }

  // 1. Guard check: sidecar authorized must be true
  const hasAuthGuard = mainContent.includes('sidecarRes.authorized') && 
                       (mainContent.includes('!sidecarRes.authorized') || mainContent.includes('sidecarRes?.authorized'));
  
  // 2. Status event check: license:status-changed emitted
  const hasStatusEvent = mainContent.includes("'license:status-changed'") || mainContent.includes('"license:status-changed"');

  // 3. Renderer reactivity check: onLicenseChanged in preload and updateLicenseUI listener in renderer
  const hasPreloadBridge = preloadContent.includes('onLicenseChanged');
  const hasRendererListener = rendererContent.includes('onLicenseChanged') && rendererContent.includes('updateLicenseUI');

  console.log('==================================================');
  console.log('2TOOLNE LICENSE CODE CONTRACT ASSERTION');
  console.log('==================================================');

  if (hasAuthGuard) {
    console.log('PACKAGED_LICENSE_AUTHORIZATION_GUARD = PASS');
  } else {
    console.error('PACKAGED_LICENSE_AUTHORIZATION_GUARD = FAIL: Missing sidecar authorized check');
    process.exit(1);
  }

  if (hasStatusEvent) {
    console.log('PACKAGED_LICENSE_STATUS_EVENT = PASS');
  } else {
    console.error('PACKAGED_LICENSE_STATUS_EVENT = FAIL: Missing license:status-changed event');
    process.exit(1);
  }

  if (hasPreloadBridge && hasRendererListener) {
    console.log('PACKAGED_LICENSE_RENDERER_REACTIVITY = PASS');
  } else {
    console.error('PACKAGED_LICENSE_RENDERER_REACTIVITY = FAIL: Missing renderer listener bridge');
    process.exit(1);
  }

  console.log('ALL_LICENSE_PACKAGE_ASSERTIONS = PASS');
}

const targetDir = process.argv[2] || path.join(__dirname, '..');
verifyLicenseContract(targetDir);
