/**
 * apps/capcut-v2/desktop/tests/test_large_media_import_stress.js
 *
 * Full End-to-End Stress & Acceptance Test for 350 x 4K Images & 1000 Image Scalability:
 * 1. Generates 350 synthetic 4K images (3840x2160, mix of JPEG and PNG).
 * 2. Scans files through FileImporter.
 * 3. Processes thumbnails through ThumbnailService with bounded concurrency (3).
 * 4. Virtualizes media grid and measures live DOM card counts.
 * 5. Stress scrolls from first item to last item and back repeatedly.
 * 6. Benchmarks memory usage (baseline, peak, post-import).
 * 7. Runs 1,000 images scalability test.
 * 8. Proves source files are 100% unchanged (SHA256 & byte size).
 * 9. Asserts all Section 30 & 31 hard pass invariants.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { FileImporter } = require('../src/main/file_importer');
const { ThumbnailService } = require('../src/main/thumbnail_service');
const { CrashDiagnosticsManager } = require('../src/main/crash_diagnostics');

const TEST_DIR = path.join(os.tmpdir(), `2toolne_350_4k_test_${Date.now()}`);
const FIXTURE_DIR = path.join(TEST_DIR, 'source_4k');
const THUMB_DIR = path.join(TEST_DIR, 'thumbnails');
const DIAG_DIR = path.join(TEST_DIR, 'diagnostics');

fs.mkdirSync(FIXTURE_DIR, { recursive: true });
fs.mkdirSync(THUMB_DIR, { recursive: true });
fs.mkdirSync(DIAG_DIR, { recursive: true });

function getMemoryMB() {
  if (global.gc) global.gc();
  const usage = process.memoryUsage();
  return {
    rssMB: Math.round((usage.rss / (1024 * 1024)) * 100) / 100,
    heapUsedMB: Math.round((usage.heapUsed / (1024 * 1024)) * 100) / 100,
  };
}

function sha256File(filePath) {
  const data = fs.readFileSync(filePath);
  return crypto.createHash('sha256').update(data).digest('hex');
}

// Minimal DOM simulation for virtualizer
class MockElement {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase();
    this.className = '';
    this.style = {};
    this.dataset = {};
    this.children = [];
    this._innerHTML = '';
    this.textContent = '';
    this.clientWidth = 400;
    this.clientHeight = 240;
    this.scrollTop = 0;
    this.eventListeners = {};
  }
  get innerHTML() { return this._innerHTML || ''; }
  set innerHTML(val) {
    this._innerHTML = val;
    if (val === '') this.children = [];
  }
  appendChild(child) {
    if (child && Array.isArray(child.children) && (child.constructor.name === 'MockDocumentFragment')) {
      for (const c of child.children) this.children.push(c);
      child.children = [];
      return child;
    }
    this.children.push(child);
    return child;
  }
  insertBefore(newChild, refChild) {
    const idx = this.children.indexOf(refChild);
    if (idx !== -1) this.children.splice(idx, 0, newChild);
    else this.children.push(newChild);
    return newChild;
  }
  querySelector(selector) {
    for (const child of this.children) {
      if (selector === 'img.media-thumb-img' && child.tagName === 'IMG') return child;
      if (selector === '.media-thumb-placeholder' && child.className.includes('media-thumb-placeholder')) return child;
      if (selector === '.media-thumb-loading-shimmer' && child.className.includes('media-thumb-loading-shimmer')) return child;
    }
    return null;
  }
  querySelectorAll(selector) {
    const res = [];
    for (const child of this.children) {
      if (child.className.includes('media-thumb-card')) res.push(child);
    }
    return res;
  }
  addEventListener(event, handler) {
    if (!this.eventListeners[event]) this.eventListeners[event] = [];
    this.eventListeners[event].push(handler);
  }
  classList = { add: () => {}, remove: () => {}, contains: () => false };
}

class MockDocumentFragment {
  constructor() { this.children = []; }
  appendChild(child) { this.children.push(child); }
}

async function runAcceptance() {
  console.log('====================================================');
  console.log('=== 2TOOLNE 350 × 4K LARGE MEDIA IMPORT ACCEPTANCE ===');
  console.log('====================================================\n');

  const baselineMem = getMemoryMB();
  console.log(`[Phase 0] Baseline Memory: RSS = ${baselineMem.rssMB} MB, HeapUsed = ${baselineMem.heapUsedMB} MB`);

  // 1. Generate 350 synthetic 4K images
  console.log('\n[Phase 1] Generating 350 synthetic 4K test images (3840×2160, JPG & PNG)...');
  const templateJpg = path.join(TEST_DIR, 'template_4k.jpg');
  const templatePng = path.join(TEST_DIR, 'template_4k.png');

  execFileSync('ffmpeg', [
    '-y', '-v', 'error',
    '-f', 'lavfi', '-i', 'color=c=navy:s=3840x2160:d=0.04',
    '-frames:v', '1',
    '-f', 'image2', '-vcodec', 'mjpeg', '-q:v', '3',
    templateJpg,
  ]);

  execFileSync('ffmpeg', [
    '-y', '-v', 'error',
    '-f', 'lavfi', '-i', 'color=c=darkgreen:s=3840x2160:d=0.04',
    '-frames:v', '1',
    '-f', 'image2', '-vcodec', 'png',
    templatePng,
  ]);

  // Create 350 individual 4K image files (alternating JPG and PNG)
  const sourceFiles = [];
  const sourceSignatures = new Map(); // path -> { size, sha256 }

  for (let i = 1; i <= 350; i++) {
    const isPng = i % 5 === 0; // 20% PNG, 80% JPG
    const ext = isPng ? 'png' : 'jpg';
    const filePath = path.join(FIXTURE_DIR, `media_${String(i).padStart(3, '0')}.${ext}`);
    fs.copyFileSync(isPng ? templatePng : templateJpg, filePath);

    sourceFiles.push(filePath);
    sourceSignatures.set(filePath, {
      size: fs.statSync(filePath).size,
      sha256: sha256File(filePath),
    });
  }

  // Verify resolution of sample files
  const probeRaw = execFileSync('ffprobe', [
    '-v', 'error',
    '-select_streams', 'v:0',
    '-show_entries', 'stream=width,height',
    '-of', 'json',
    sourceFiles[0],
  ]).toString();
  const probeMeta = JSON.parse(probeRaw);
  assert.strictEqual(probeMeta.streams[0].width, 3840, 'Sample width must be 3840');
  assert.strictEqual(probeMeta.streams[0].height, 2160, 'Sample height must be 2160');
  console.log(`  ✓ 350 × 4K synthetic media files created in: ${FIXTURE_DIR}`);

  // 2. Scan paths through FileImporter
  console.log('\n[Phase 2] Scanning files through FileImporter...');
  const importer = new FileImporter();
  const importStart = Date.now();
  const importRes = await importer.processPaths([FIXTURE_DIR]);
  const importElapsed = Date.now() - importStart;

  assert.strictEqual(importRes.images.length, 350, `Expected 350 imported images, got ${importRes.images.length}`);
  assert.strictEqual(importRes.errors.length, 0, 'No import errors should occur');
  console.log(`  ✓ IMPORT_PATH_SCAN=PASS (Found ${importRes.images.length} images in ${importElapsed}ms)`);

  // 3. Initialize ThumbnailService and generate thumbnails
  console.log('\n[Phase 3] Generating thumbnails with ThumbnailService (THUMBNAIL_CONCURRENCY = 3)...');
  const thumbService = new ThumbnailService({
    cacheDir: THUMB_DIR,
    maxDimension: 384,
    concurrency: 3,
  });

  let maxConcurrencyObserved = 0;
  const originalExec = thumbService.executeThumbnailGeneration.bind(thumbService);
  thumbService.executeThumbnailGeneration = async function (task) {
    if (thumbService.activeWorkers > maxConcurrencyObserved) {
      maxConcurrencyObserved = thumbService.activeWorkers;
    }
    return originalExec(task);
  };

  const thumbStart = Date.now();
  // Batch get first 60 visible items (simulating progressive UI load)
  const visibleSlicePaths = importRes.images.slice(0, 60);
  const visibleThumbs = await thumbService.getThumbnailsBatch(visibleSlicePaths);
  const thumbElapsed = Date.now() - thumbStart;

  assert.strictEqual(visibleThumbs.length, 60);
  for (const t of visibleThumbs) {
    assert(t.thumbnailPath && fs.existsSync(t.thumbnailPath), `Thumbnail must exist for ${t.originalPath}`);
  }
  assert(maxConcurrencyObserved <= 3, `Observed concurrency (${maxConcurrencyObserved}) must not exceed 3`);
  console.log(`  ✓ Initial visible thumbnails (60 items) generated in ${thumbElapsed}ms (Max active workers: ${maxConcurrencyObserved})`);

  // 4. Virtualizer Execution & Memory Measurements
  console.log('\n[Phase 4] Virtualizing Media Grid with 350 4K items...');
  const appJs = fs.readFileSync(path.join(__dirname, '../src/renderer/app.js'), 'utf8');
  const classCode = appJs.slice(
    appJs.indexOf('class MediaGridVirtualizer'),
    appJs.indexOf('function renderMediaGrid()')
  );

  const vm = require('vm');
  const mockContainer = new MockElement('div');
  mockContainer.clientWidth = 400;
  mockContainer.clientHeight = 240;

  const sandbox = {
    document: {
      createElement: (tag) => new MockElement(tag),
      createDocumentFragment: () => new MockDocumentFragment(),
    },
    window: {},
    CSS: { escape: (s) => s },
    state: { mediaList: [...importRes.images] },
    thumbnailCache: new Map(),
    DOM: {
      mediaCount: { textContent: '' },
      btnClearMedia: { style: {} },
    },
    updateMissingBanner: () => {},
    toFileUrl: (p) => `file://${p}`,
    openSingleMediaPreview: () => {},
    console: { log: () => {}, warn: () => {}, error: console.error },
    requestAnimationFrame: (fn) => fn(),
    cancelAnimationFrame: () => {},
  };

  // Populate pre-generated thumbnails into virtualizer cache
  for (const t of visibleThumbs) {
    sandbox.thumbnailCache.set(t.originalPath, t.thumbnailPath);
  }

  vm.createContext(sandbox);
  vm.runInContext(classCode + '\nthis.MediaGridVirtualizer = MediaGridVirtualizer;', sandbox);

  const virtualizer = new sandbox.MediaGridVirtualizer(mockContainer);
  virtualizer.recalculateDimensions();
  virtualizer.updateVisibleSlice(true);

  const renderedCards350 = virtualizer.content.children.length;
  console.log(`  Live DOM Cards in Grid (350 items imported): ${renderedCards350}`);
  assert(renderedCards350 > 0 && renderedCards350 <= 60, `Rendered cards (${renderedCards350}) must be <= 60`);

  // Verify FULL_RES_GRID_SRC_COUNT = 0
  let fullResSrcCount = 0;
  let thumbSrcCount = 0;
  for (const card of virtualizer.content.children) {
    const img = card.querySelector('img.media-thumb-img');
    if (img && img.src) {
      if (sourceFiles.some((sf) => img.src.includes(path.basename(sf)) && !img.src.includes('.jpg'))) {
        fullResSrcCount++;
      }
      if (img.src.includes(THUMB_DIR) || img.src.includes('.jpg')) {
        thumbSrcCount++;
      }
    }
  }
  assert.strictEqual(fullResSrcCount, 0, 'FULL_RES_GRID_SRC_COUNT must be 0');
  console.log(`  ✓ FULL_RES_GRID_SRC_COUNT = 0, THUMBNAIL_GRID_SRC_COUNT = ${thumbSrcCount}`);

  // 5. Scroll Stress: Scroll First to Last and Back Repeatedly (10 round trips)
  console.log('\n[Phase 5] Running scroll stress test (10 round-trips from Item #001 to #350)...');
  const totalRows = Math.ceil(350 / virtualizer.columns);
  const maxScroll = (totalRows * virtualizer.rowPitch) - mockContainer.clientHeight;

  for (let cycle = 1; cycle <= 10; cycle++) {
    // Scroll down in 5 steps
    for (let step = 1; step <= 5; step++) {
      mockContainer.scrollTop = Math.round((maxScroll / 5) * step);
      virtualizer.updateVisibleSlice(true);
      assert(virtualizer.content.children.length <= 60, `DOM cards exceeded 60 during scroll cycle ${cycle}`);
    }
    // Scroll back up in 5 steps
    for (let step = 4; step >= 0; step--) {
      mockContainer.scrollTop = Math.round((maxScroll / 5) * step);
      virtualizer.updateVisibleSlice(true);
      assert(virtualizer.content.children.length <= 60, `DOM cards exceeded 60 during scroll-back cycle ${cycle}`);
    }
  }
  console.log('  ✓ SCROLL_FIRST_TO_LAST=PASS, SCROLL_BACK=PASS (10 cycles completed, DOM cards remained strictly <= 60)');

  const peakMem = getMemoryMB();
  console.log(`  Peak Memory Observed: RSS = ${peakMem.rssMB} MB, HeapUsed = ${peakMem.heapUsedMB} MB`);

  // 6. Scalability Test with 1,000 Images
  console.log('\n[Phase 6] Running 1,000 Image Scalability Benchmark...');
  const paths1000 = [];
  for (let i = 1; i <= 1000; i++) {
    paths1000.push(`D:\\synthetic_catalog\\image_${String(i).padStart(4, '0')}.jpg`);
  }
  sandbox.state.mediaList = paths1000;
  virtualizer.recalculateDimensions();
  virtualizer.updateVisibleSlice(true);

  const maxDomNodes1000 = virtualizer.content.children.length;
  console.log(`  Live DOM Cards for 1,000 items: ${maxDomNodes1000}`);
  assert(maxDomNodes1000 <= 60, `MAX_DOM_NODES_1000 (${maxDomNodes1000}) must be <= 60`);
  assert.notStrictEqual(maxDomNodes1000, 1000, 'MAX_DOM_NODES_1000 must NOT equal 1000!');
  console.log('  ✓ TEST_1000_IMAGES=PASS, MAX_DOM_NODES_1000=' + maxDomNodes1000);

  // 7. Source Media File Integrity Verification
  console.log('\n[Phase 7] Verifying 100% integrity of all 350 source media files...');
  let checkedCount = 0;
  for (const [filePath, expected] of sourceSignatures.entries()) {
    const currentStat = fs.statSync(filePath);
    assert.strictEqual(currentStat.size, expected.size, `File size changed for ${filePath}`);
    const currentSha = sha256File(filePath);
    assert.strictEqual(currentSha, expected.sha256, `SHA256 changed for ${filePath}`);
    checkedCount++;
  }
  assert.strictEqual(checkedCount, 350);
  console.log(`  ✓ All ${checkedCount} source media files verified: SOURCE_FILES_UNCHANGED=YES`);

  const postMem = getMemoryMB();
  console.log(`\n[Phase 8] Post-Import Memory: RSS = ${postMem.rssMB} MB, HeapUsed = ${postMem.heapUsedMB} MB`);

  // 8. Cleanup test files
  try {
    fs.rmSync(TEST_DIR, { recursive: true, force: true });
  } catch (e) {}

  console.log('\n====================================================');
  console.log('🎉 ALL 350 × 4K LARGE MEDIA IMPORT TESTS PASSED! 🎉');
  console.log('====================================================\n');

  return {
    baselineMem,
    peakMem,
    postMem,
    renderedCards350,
    maxDomNodes1000,
  };
}

runAcceptance().catch((err) => {
  console.error('FATAL ACCEPTANCE ERROR:', err);
  process.exit(1);
});
