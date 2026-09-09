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

function verifyLicenseContract(appDir) {
  let mainContent = '';
  let preloadContent = '';
  let rendererContent = '';

  const asarPath = path.join(appDir, 'resources', 'app.asar');
  if (fs.existsSync(asarPath)) {
    // Read from asar via asar module if available, or extract
    try {
      const asar = require('asar');
      mainContent = asar.extractFile(asarPath, 'src/main/index.js').toString('utf8');
      preloadContent = asar.extractFile(asarPath, 'src/preload/preload.js').toString('utf8');
      rendererContent = asar.extractFile(asarPath, 'src/renderer/app.js').toString('utf8');
    } catch (e) {
      // Fallback: search string directly in asar binary
      const buf = fs.readFileSync(asarPath);
      mainContent = buf.toString('utf8');
      preloadContent = mainContent;
      rendererContent = mainContent;
    }
  } else {
    // Unpacked or source tree
    const mainPath = path.join(appDir, 'src', 'main', 'index.js');
    const preloadPath = path.join(appDir, 'src', 'preload', 'preload.js');
    const rendererPath = path.join(appDir, 'src', 'renderer', 'app.js');

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
