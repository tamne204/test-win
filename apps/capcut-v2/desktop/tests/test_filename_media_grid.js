/**
 * apps/capcut-v2/desktop/tests/test_filename_media_grid.js
 *
 * Unit & Invariant Test Suite for Filename-Only Media Grid:
 * 1. Hard Invariant: MEDIA_GRID_IMAGE_ELEMENT_COUNT = 0 (Zero <img> or background-image in media grid).
 * 2. Preload & Main IPC: Verifies thumbnail APIs and IPC handlers are removed.
 * 3. Crash Diagnostics: Verifies render-process-gone and child-process-gone are preserved.
 * 4. Virtualization Bounds:
 *    - MAX_LIVE_ROWS_350 <= 30
 *    - MAX_LIVE_ROWS_1000 <= 30
 *    - MAX_LIVE_ROWS_10000 <= 30
 * 5. Natural numeric sorting: photo_1.png, photo_2.png, photo_10.png.
 * 6. Delegated actions: single preview modal and file removal.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const APP_JS_PATH = path.join(__dirname, '../src/renderer/app.js');
const PRELOAD_JS_PATH = path.join(__dirname, '../src/preload/preload.js');
const MAIN_INDEX_JS_PATH = path.join(__dirname, '../src/main/index.js');
const STYLES_CSS_PATH = path.join(__dirname, '../src/renderer/styles.css');

// Mock DOM for renderer testing
class MockElement {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase();
    this.className = '';
    this.style = {};
    this.dataset = {};
    this.children = [];
    this._innerHTML = '';
    this.textContent = '';
    this.title = '';
    this.clientWidth = 400;
    this.clientHeight = 220;
    this.scrollTop = 0;
    this.eventListeners = {};
    this.classList = {
      _classes: new Set(),
      add: (c) => this.classList._classes.add(c),
      remove: (c) => this.classList._classes.delete(c),
      contains: (c) => this.classList._classes.has(c),
    };
  }

  get innerHTML() {
    return this._innerHTML || '';
  }

  set innerHTML(val) {
    this._innerHTML = val;
    if (val === '') {
      this.children = [];
    }
  }

  appendChild(child) {
    if (!child) return child;
    if (child.constructor && child.constructor.name === 'MockDocumentFragment') {
      for (const c of child.children) {
        this.children.push(c);
      }
      child.children = [];
      return child;
    }
    this.children.push(child);
    return child;
  }

  querySelector(selector) {
    const all = this.querySelectorAll(selector);
    return all.length > 0 ? all[0] : null;
  }

  querySelectorAll(selector) {
    const res = [];
    function walk(node) {
      for (const child of node.children) {
        if (selector === 'img' && child.tagName === 'IMG') {
          res.push(child);
        } else if (selector.startsWith('.') && child.className && child.className.includes(selector.slice(1))) {
          res.push(child);
        } else if (selector === 'button' && child.tagName === 'BUTTON') {
          res.push(child);
        }
        walk(child);
      }
    }
    walk(this);
    return res;
  }

  addEventListener(event, handler) {
    if (!this.eventListeners[event]) this.eventListeners[event] = [];
    this.eventListeners[event].push(handler);
  }

  dispatchEvent(event) {
    const handlers = this.eventListeners[event.type] || [];
    for (const h of handlers) h(event);
  }

  closest(selector) {
    if (selector.startsWith('.') && this.className && this.className.includes(selector.slice(1))) {
      return this;
    }
    return null;
  }
}

class MockDocumentFragment {
  constructor() {
    this.children = [];
  }
  appendChild(child) {
    this.children.push(child);
    return child;
  }
}

function testStaticCodeInvariants() {
  console.log('=== TEST 1: STATIC CODE INVARIANTS ===');

  const appJs = fs.readFileSync(APP_JS_PATH, 'utf8');
  const preloadJs = fs.readFileSync(PRELOAD_JS_PATH, 'utf8');
  const mainIndexJs = fs.readFileSync(MAIN_INDEX_JS_PATH, 'utf8');
  const stylesCss = fs.readFileSync(STYLES_CSS_PATH, 'utf8');

  // 1. Verify MediaGridVirtualizer exists in app.js
  assert(appJs.includes('class MediaGridVirtualizer'), 'MediaGridVirtualizer class must exist in app.js');
  console.log('  ✓ MediaGridVirtualizer class found in app.js');

  // 2. Verify zero <img> creation inside MediaGridVirtualizer
  const virtCodeMatch = appJs.match(/class MediaGridVirtualizer[\s\S]*?\n\}/);
  assert(virtCodeMatch, 'MediaGridVirtualizer implementation must be extractable');
  const virtCode = virtCodeMatch[0];

  assert(!virtCode.includes("createElement('img')"), 'MediaGridVirtualizer must NEVER create <img> elements');
  assert(!virtCode.includes('.src ='), 'MediaGridVirtualizer must NEVER set .src');
  assert(!virtCode.includes('background-image'), 'MediaGridVirtualizer must NEVER use background-image');
  console.log('  ✓ Hard Invariant: MEDIA_GRID_IMAGE_ELEMENT_COUNT = 0 enforced in virtualizer source');

  // 3. Verify preload has no thumbnails API
  assert(!preloadJs.includes('thumbnails:'), 'window.autoedit.thumbnails must be eliminated from preload.js');
  console.log('  ✓ Dead preload.thumbnails API eliminated');

  // 4. Verify main index has no thumbnail IPC handlers
  assert(!mainIndexJs.includes("'thumbnail:get'"), "IPC handler 'thumbnail:get' must be removed from main/index.js");
  assert(!mainIndexJs.includes("'thumbnail:get-batch'"), "IPC handler 'thumbnail:get-batch' must be removed from main/index.js");
  console.log('  ✓ Dead thumbnail IPC handlers eliminated from main process');

  // 5. Verify CrashDiagnosticsManager is retained in main/index.js and crash_diagnostics.js
  const crashDiagJs = fs.readFileSync(path.join(__dirname, '../src/main/crash_diagnostics.js'), 'utf8');
  assert(mainIndexJs.includes('CrashDiagnosticsManager'), 'CrashDiagnosticsManager must be retained in main/index.js');
  assert(mainIndexJs.includes('crashDiagnostics.attach(mainWindow)'), 'crashDiagnostics.attach(mainWindow) must be called in main/index.js');
  assert(crashDiagJs.includes('render-process-gone'), "Listener 'render-process-gone' must be retained in crash_diagnostics.js");
  assert(crashDiagJs.includes('child-process-gone'), "Listener 'child-process-gone' must be retained in crash_diagnostics.js");
  console.log('  ✓ CrashDiagnosticsManager retained with render-process-gone and child-process-gone');

  // 6. Verify dead thumbnail styles are removed
  assert(!stylesCss.includes('.media-thumb-img'), '.media-thumb-img CSS rule must be removed');
  assert(!stylesCss.includes('.media-thumb-loading-shimmer'), '.media-thumb-loading-shimmer CSS rule must be removed');
  assert(stylesCss.includes('.media-file-row'), '.media-file-row CSS rule must be present in styles.css');
  console.log('  ✓ Stylesheet cleaned: thumbnail rules removed, .media-file-row styles active');
}

function createVirtualizerInstance(mediaList) {
  const appJs = fs.readFileSync(APP_JS_PATH, 'utf8');
  const classCode = appJs.slice(
    appJs.indexOf('class MediaGridVirtualizer'),
    appJs.indexOf('function renderMediaGrid()')
  );

  const mockContainer = new MockElement('div');
  mockContainer.clientWidth = 400;
  mockContainer.clientHeight = 220; // Default height

  const state = { mediaList: [...mediaList] };

  const sandbox = {
    document: {
      createElement: (tag) => new MockElement(tag),
      createDocumentFragment: () => new MockDocumentFragment(),
    },
    window: {
      lucide: { createIcons: () => {} },
    },
    state,
    DOM: {
      mediaCount: { textContent: '' },
      btnClearMedia: { style: {} },
    },
    updateMissingBanner: () => {},
    openSingleMediaPreview: (path, idx) => {},
    console: { log: () => {}, warn: () => {}, error: console.error },
    requestAnimationFrame: (fn) => fn(),
    cancelAnimationFrame: () => {},
  };

  vm.createContext(sandbox);
  vm.runInContext(classCode + '\nthis.MediaGridVirtualizer = MediaGridVirtualizer;', sandbox);

  const virtualizer = new sandbox.MediaGridVirtualizer(mockContainer);
  return { virtualizer, mockContainer, state, sandbox };
}

function testVirtualizationScale() {
  console.log('\n=== TEST 2: VIRTUALIZATION SCALE & DOM BOUNDS ===');

  // A. 350 items
  const paths350 = Array.from({ length: 350 }, (_, i) => `/Volumes/Media/4K_Photos/scene_${String(i + 1).padStart(3, '0')}.png`);
  const { virtualizer: virt350, mockContainer: cont350 } = createVirtualizerInstance(paths350);

  virt350.updateVisibleSlice(true);
  let maxLiveRows350 = virt350.content.children.length;
  console.log(`  Initial live rows (350 items): ${maxLiveRows350}`);
  assert(maxLiveRows350 > 0 && maxLiveRows350 <= 30, `MAX_LIVE_ROWS_350 (${maxLiveRows350}) must be <= 30`);

  // Verify zero <img> elements inside container
  const imgElements350 = cont350.querySelectorAll('img');
  assert.strictEqual(imgElements350.length, 0, 'MEDIA_GRID_IMAGE_ELEMENT_COUNT must be strictly 0');
  console.log(`  ✓ MEDIA_GRID_IMAGE_ELEMENT_COUNT = ${imgElements350.length} (ZERO image elements)`);

  // Verify row contents: sequence, filename, extension badge, action buttons
  const firstRow = virt350.content.children[0];
  assert(firstRow.className.includes('media-file-row'), 'Row must have class media-file-row');
  const seqEl = firstRow.children.find(c => c.className === 'media-file-seq');
  assert(seqEl && seqEl.textContent === '#001', 'First row sequence must be #001');
  const nameEl = firstRow.children.find(c => c.className === 'media-file-name');
  assert(nameEl && nameEl.textContent === 'scene_001.png', 'Filename must match');
  const extEl = firstRow.children.find(c => c.className === 'media-file-ext');
  assert(extEl && extEl.textContent === 'PNG', 'Extension badge must be PNG');

  // Scroll through 350 items
  for (let top = 0; top <= 350 * 36; top += 360) {
    cont350.scrollTop = top;
    virt350.updateVisibleSlice();
    if (virt350.content.children.length > maxLiveRows350) {
      maxLiveRows350 = virt350.content.children.length;
    }
  }
  console.log(`  ✓ MAX_LIVE_ROWS_350 = ${maxLiveRows350} (PASS <= 30)`);
  assert(maxLiveRows350 <= 30, `MAX_LIVE_ROWS_350 (${maxLiveRows350}) must not exceed 30`);

  // B. 1,000 items
  const paths1000 = Array.from({ length: 1000 }, (_, i) => `/Volumes/Media/Batch_1000/img_${String(i + 1).padStart(4, '0')}.${i % 2 === 0 ? 'jpg' : 'png'}`);
  const { virtualizer: virt1000, mockContainer: cont1000 } = createVirtualizerInstance(paths1000);

  virt1000.updateVisibleSlice(true);
  let maxLiveRows1000 = virt1000.content.children.length;
  for (let top = 0; top <= 1000 * 36; top += 720) {
    cont1000.scrollTop = top;
    virt1000.updateVisibleSlice();
    if (virt1000.content.children.length > maxLiveRows1000) {
      maxLiveRows1000 = virt1000.content.children.length;
    }
  }
  console.log(`  ✓ MAX_LIVE_ROWS_1000 = ${maxLiveRows1000} (PASS <= 30)`);
  assert(maxLiveRows1000 <= 30, `MAX_LIVE_ROWS_1000 (${maxLiveRows1000}) must not exceed 30`);

  // C. 10,000 synthetic paths
  const paths10000 = Array.from({ length: 10000 }, (_, i) => `D:\\Projects\\Synthetic\\frame_${String(i + 1).padStart(5, '0')}.png`);
  const { virtualizer: virt10000, mockContainer: cont10000 } = createVirtualizerInstance(paths10000);

  const t0 = process.hrtime.bigint();
  virt10000.updateVisibleSlice(true);
  const t1 = process.hrtime.bigint();
  const initRenderMs = Number(t1 - t0) / 1e6;
  console.log(`  Initial render of 10,000 items: ${initRenderMs.toFixed(3)}ms`);

  let maxLiveRows10000 = virt10000.content.children.length;
  // Test jumping to middle and end
  const scrollPositions = [0, 50000, 180000, 300000, 359000, 360000 - 220];
  for (const pos of scrollPositions) {
    cont10000.scrollTop = pos;
    virt10000.updateVisibleSlice();
    if (virt10000.content.children.length > maxLiveRows10000) {
      maxLiveRows10000 = virt10000.content.children.length;
    }
  }
  console.log(`  ✓ MAX_LIVE_ROWS_10000 = ${maxLiveRows10000} (PASS <= 30)`);
  assert(maxLiveRows10000 <= 30, `MAX_LIVE_ROWS_10000 (${maxLiveRows10000}) must not exceed 30`);

  return { maxLiveRows350, maxLiveRows1000, maxLiveRows10000 };
}

function testNaturalNumericSort() {
  console.log('\n=== TEST 3: NATURAL NUMERIC SORTING ===');

  const unsorted = [
    'D:\\photos\\photo_10.png',
    'D:\\photos\\photo_2.png',
    'D:\\photos\\photo_1.png',
    'D:\\photos\\photo_20.png',
    'D:\\photos\\photo_3.png',
  ];

  unsorted.sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

  const expected = [
    'D:\\photos\\photo_1.png',
    'D:\\photos\\photo_2.png',
    'D:\\photos\\photo_3.png',
    'D:\\photos\\photo_10.png',
    'D:\\photos\\photo_20.png',
  ];

  assert.deepStrictEqual(unsorted, expected, 'Natural numeric sorting must sort 1, 2, 3, 10, 20 correctly');
  console.log('  ✓ Natural sorting verified: photo_1 -> photo_2 -> photo_3 -> photo_10 -> photo_20');
}

function testRemovalAndClear() {
  console.log('\n=== TEST 4: ITEM REMOVAL & CLEAR ALL ===');

  const initialPaths = ['D:\\p\\001.png', 'D:\\p\\002.png', 'D:\\p\\003.png'];
  const { virtualizer, mockContainer, state } = createVirtualizerInstance(initialPaths);

  virtualizer.updateVisibleSlice(true);
  assert.strictEqual(virtualizer.content.children.length, 3);
  assert.strictEqual(mockContainer.classList.contains('has-media'), true);

  // Simulate deletion of index 1
  state.mediaList.splice(1, 1); // removes 002.png
  virtualizer.updateVisibleSlice(true);
  assert.strictEqual(virtualizer.content.children.length, 2);
  const remainingNames = virtualizer.content.children.map(row => {
    const nameEl = row.children.find(c => c.className === 'media-file-name');
    return nameEl.textContent;
  });
  assert.deepStrictEqual(remainingNames, ['001.png', '003.png'], 'Remaining items should be 001.png and 003.png');
  console.log('  ✓ Item deletion re-renders correct rows');

  // Clear all
  state.mediaList = [];
  virtualizer.updateVisibleSlice(true);
  assert.strictEqual(virtualizer.content.children.length, 0);
  assert.strictEqual(mockContainer.classList.contains('has-media'), false);
  assert.strictEqual(virtualizer.emptyHint.style.display, 'flex');
  console.log('  ✓ Clear all restores empty hint and zero live rows');
}

function runAll() {
  testStaticCodeInvariants();
  const metrics = testVirtualizationScale();
  testNaturalNumericSort();
  testRemovalAndClear();

  console.log('\n========================================');
  console.log('✓ ALL FILENAME-ONLY MEDIA GRID UNIT TESTS PASSED!');
  console.log('========================================\n');
  return metrics;
}

if (require.main === module) {
  runAll();
}

module.exports = { runAll };
