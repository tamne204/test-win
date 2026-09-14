#!/usr/bin/env node
/**
 * apps/capcut-v2/desktop/tests/test_windows_black_screen_import.js
 *
 * P0 REGRESSION TEST — "Windows black screen after image import".
 *
 * DEFECT (proven against the shipped v2.1.0 / v2.1.1 artifacts):
 *   detectMissingIndices() derived its loop bounds from the LARGEST TRAILING DIGIT
 *   RUN found in any filename (extractFileIndex regex /(\d+)(?:\.[^.]+)?$/), with
 *   NO validation of the resulting span. Real-world photo names such as
 *   "mmexport1699999999999.jpg" or "IMG_20240115_143022.jpg" therefore produced
 *   min = 1 and max = 1.7e12, driving an effectively unbounded synchronous loop on
 *   the renderer MAIN THREAD:
 *
 *       for (let i = min; i <= max; i++) if (!present.has(i)) missing.push(i);
 *
 *   renderMediaGrid() calls updateMissingBanner() BEFORE the grid's first
 *   updateVisibleSlice(), so the main thread is starved before anything can be
 *   composited. The window then shows BrowserWindow backgroundColor '#0E0F11'
 *   i.e. a completely black window, and Windows paints "Not Responding".
 *
 *   Secondary O(span^2) variants live in applyMissingResolution() and
 *   assembleCurrentProjectPayload() (inner backward/forward scan per gap index).
 *
 * FIX UNDER TEST:
 *   One shared span cap, MEDIA_INDEX_MAX_SPAN, applied to the producer
 *   (detectMissingIndices) plus defence-in-depth on both consumers.
 *
 * NON-VACUITY (§28 — MANDATORY):
 *   Broken baseline (guard absent) -> this test MUST FAIL.
 *   Fixed source (guard present)   -> this test MUST PASS.
 *   The pathological probes run in a CHILD PROCESS with a hard wall-clock budget so
 *   that a main-thread hang is reported as a failure instead of hanging the suite.
 *
 * INVARIANTS ASSERTED:
 *   1. Pathological filename sets are rejected FAST (< 250 ms) and gap detection
 *      returns null.
 *   2. A genuine small "001..N" gap series is STILL detected with byte-identical
 *      min / max / missing semantics (ZERO behaviour change for real users).
 *   3. The span boundary is exact: span == cap accepted, span == cap + 1 rejected.
 *   4. applyMissingResolution() cannot materialise an unbounded path array.
 *   5. renderMediaGrid() still calls updateMissingBanner() before the grid paint —
 *      the fix must make that call CHEAP, not remove it.
 *   6. Degenerate inputs (0/1 file, no digits, non-finite) still return null.
 *   7. Locale / Unicode / timestamp filename shapes are handled without throwing.
 *   8. Non-vacuity: the guard is present exactly on the intended call paths.
 *
 * The defect was reported on Windows; the renderer logic is platform-independent,
 * so this suite runs on any host.
 */

'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { spawnSync } = require('child_process');

const APP_JS_PATH = path.join(__dirname, '../src/renderer/app.js');
const APP_JS = fs.readFileSync(APP_JS_PATH, 'utf8');

/* Stable source markers (verified present in the shipped + worktree app.js) */
const SECTION_START = '// Sequential Image (001-xxx) Gap Detection & Stretch Resolution';
const SECTION_END = '// Missing Images Modal Open & Action';

const GUARD_TOKEN = 'MEDIA_INDEX_MAX_SPAN';
const DEFAULT_CAP = 5000;
const PROBE_LIMIT_MS = Number(process.env.GAP_PROBE_LIMIT_MS || 60000);
const REJECT_DEADLINE_MS = 250;

const PROBE_BEGIN = '###GAP_PROBE_JSON_BEGIN###';
const PROBE_END = '###GAP_PROBE_JSON_END###';

/* ------------------------------------------------------------------ helpers */

let passCount = 0;
let failCount = 0;
const failures = [];

function check(cond, name, details = '') {
  if (cond) {
    passCount++;
    console.log(`  [PASS] ${name}${details ? ' (' + details + ')' : ''}`);
  } else {
    failCount++;
    failures.push(name);
    console.error(`  [FAIL] ${name}${details ? ' (' + details + ')' : ''}`);
  }
}

function section(title) {
  console.log('');
  console.log('='.repeat(78));
  console.log(title);
  console.log('='.repeat(78));
}

function range(from, to) {
  const r = [];
  for (let i = from; i <= to; i++) r.push(i);
  return r;
}

function extractGapDetectionSource(appJs) {
  const start = appJs.indexOf(SECTION_START);
  const end = appJs.indexOf(SECTION_END);
  if (start < 0) throw new Error(`MARKER_NOT_FOUND: ${SECTION_START}`);
  if (end < 0) throw new Error(`MARKER_NOT_FOUND: ${SECTION_END}`);
  if (end <= start) throw new Error('MARKERS_OUT_OF_ORDER');
  return appJs.slice(start, end);
}

/**
 * Load the REAL gap-detection functions verbatim from src/renderer/app.js into an
 * isolated vm context with minimal DOM/state stubs. No rewriting of the code under
 * test — this is what makes the result meaningful.
 */
function loadGapDetectionRuntime(appJs) {
  const src = extractGapDetectionSource(appJs);

  const state = { mediaList: [], lastMissingAnalysis: null };
  const sandbox = {
    state,
    console,
    DOM: {
      missingImagesBanner: { style: { display: '' } },
      missingImagesText: { textContent: '' },
    },
    renderMediaGrid: () => {
      state.__renderCount = (state.__renderCount || 0) + 1;
    },
    showAlert: (msg) => {
      state.__lastAlert = msg;
    },
  };

  vm.createContext(sandbox);
  // Export the real functions verbatim. Each export is guarded with `typeof` so the
  // probe still runs to completion on an UNFIXED baseline where MEDIA_INDEX_MAX_SPAN
  // does not yet exist — otherwise a bare reference would abort the child with a
  // ReferenceError before a single probe case executed, and the baseline "failure"
  // would be a harness crash rather than evidence of the real defect.
  vm.runInContext(
    src +
      '\nthis.MEDIA_INDEX_MAX_SPAN = typeof MEDIA_INDEX_MAX_SPAN !== "undefined" ? MEDIA_INDEX_MAX_SPAN : undefined;' +
      '\nthis.extractFileIndex = typeof extractFileIndex !== "undefined" ? extractFileIndex : undefined;' +
      '\nthis.detectMissingIndices = typeof detectMissingIndices !== "undefined" ? detectMissingIndices : undefined;' +
      '\nthis.updateMissingBanner = typeof updateMissingBanner !== "undefined" ? updateMissingBanner : undefined;' +
      '\nthis.applyMissingResolution = typeof applyMissingResolution !== "undefined" ? applyMissingResolution : undefined;',
    sandbox
  );
  return sandbox;
}

/* ------------------------------------------------------ pathological inputs */

function seqSeries(count, gapAt) {
  const out = [];
  for (let i = 1; i <= count; i++) {
    if (i === gapAt) continue;
    out.push('IMG_' + String(i).padStart(5, '0') + '.jpg');
  }
  return out;
}

const PROBE_CASES = [
  {
    id: 'pathological_epoch_filename',
    // mmexport<epoch-ms>.jpg -> trailing digits 1699999999999  (span ~1.7e12)
    files: ['IMG_0001.jpg', 'IMG_0002.jpg', 'mmexport1699999999999.jpg'],
    expect: 'REJECTED',
  },
  {
    id: 'pathological_large_counter',
    // photo_5000000.jpg -> span 5e6: blocks for hundreds of ms and builds a 5e6-element
    // array on the broken baseline, yet never throws -> the pure main-thread-block case.
    files: ['IMG_0001.jpg', 'IMG_0002.jpg', 'photo_5000000.jpg'],
    expect: 'REJECTED',
  },
  {
    id: 'pathological_camera_timestamp',
    files: ['IMG_0001.jpg', 'IMG_0002.jpg', 'IMG_20240115_143022.jpg'],
    expect: 'REJECTED',
  },
  {
    id: 'genuine_sequential_gap_must_still_work',
    files: ['001.jpg', '002.jpg', '004.jpg'],
    expect: 'DETECTED',
    expectedResult: { min: 1, max: 4, missing: [3] },
  },
  {
    id: 'genuine_sparse_series_must_still_work',
    files: ['photo_1.jpg', 'photo_2.jpg', 'photo_3.jpg', 'photo_10.jpg', 'photo_20.jpg'],
    expect: 'DETECTED',
    expectedResult: { min: 1, max: 20, missing: range(4, 9).concat(range(11, 19)) },
  },
  {
    id: 'boundary_span_equals_cap',
    files: seqSeries(DEFAULT_CAP, 2500),
    expect: 'DETECTED',
    expectedResult: { min: 1, max: DEFAULT_CAP, missing: [2500] },
  },
  {
    id: 'boundary_span_cap_plus_one',
    files: seqSeries(DEFAULT_CAP + 1, 2500),
    expect: 'REJECTED',
  },
  {
    id: 'degenerate_no_digits',
    files: ['alpha.jpg', 'beta.jpg'],
    expect: 'NULL_ANALYSIS',
  },
  {
    id: 'degenerate_single_file',
    files: ['only.jpg'],
    expect: 'NULL_ANALYSIS',
  },
  {
    id: 'locale_unicode_and_spaces',
    // "image 003 (1).jpg" is skipped by the trailing-digit regex (anchored before the
    // last dot), so the remaining three files form a genuine 1..5 series missing [3,4].
    files: ['ảnh_001.jpg', 'фото_002.jpg', 'image 003 (1).jpg', '写真_005.jpg'],
    expect: 'DETECTED',
    expectedResult: { min: 1, max: 5, missing: [3, 4] },
  },
];

/* ------------------------------------------------------------ child probe -- */

function runProbeChild() {
  const rt = loadGapDetectionRuntime(APP_JS);

  const report = {
    cap: typeof rt.MEDIA_INDEX_MAX_SPAN === 'number' ? rt.MEDIA_INDEX_MAX_SPAN : null,
    cases: [],
    applyResolution: null,
  };

  for (const c of PROBE_CASES) {
    const started = Date.now();
    const entry = { id: c.id, outcome: 'UNKNOWN', ms: 0, detail: '' };
    try {
      const analysis = rt.detectMissingIndices(c.files.slice());
      const ms = Date.now() - started;
      entry.ms = ms;
      if (!analysis) {
        entry.outcome = 'NULL_ANALYSIS';
      } else {
        entry.outcome = 'DETECTED';
        entry.min = analysis.min;
        entry.max = analysis.max;
        entry.missingLength = analysis.missing.length;
        entry.missing = analysis.missing.length <= 64 ? analysis.missing.slice() : undefined;
      }
      // Exercise the real consumer too: on a huge span this is where the mapped
      // string array is built, so it is part of the blocking envelope.
      rt.state.mediaList = c.files.slice();
      rt.state.lastMissingAnalysis = null;
      rt.updateMissingBanner();
      entry.bannerDisplay = rt.DOM.missingImagesBanner.style.display;
    } catch (err) {
      entry.outcome = 'THREW';
      entry.ms = Date.now() - started;
      entry.detail = `${err && err.constructor ? err.constructor.name : 'Error'}: ${err && err.message}`;
    }
    report.cases.push(entry);
    console.log(`  [probe] ${entry.id} -> ${entry.outcome} in ${entry.ms}ms ${entry.detail}`);
  }

  // Defence-in-depth: applyMissingResolution() must not materialise an unbounded
  // path array from a stale/serialised lastMissingAnalysis. span 6000 is just over
  // the cap: the guard must short-circuit and leave state.mediaList untouched.
  try {
    const started = Date.now();
    const before = ['a.jpg', 'b.jpg'].slice();
    rt.state.mediaList = before.slice();
    rt.state.lastMissingAnalysis = {
      min: 1,
      max: 6000,
      missing: [9],
      mapped: [{ num: 1, path: 'a.jpg' }, { num: 6000, path: 'b.jpg' }],
    };
    rt.applyMissingResolution('stretch_prev');
    report.applyResolution = {
      ms: Date.now() - started,
      resultingMediaListLength: rt.state.mediaList.length,
      inputWasReplaced: JSON.stringify(rt.state.mediaList) !== JSON.stringify(before),
      threw: false,
    };
  } catch (err) {
    report.applyResolution = {
      ms: 0,
      resultingMediaListLength: -1,
      inputWasReplaced: true,
      threw: true,
      detail: `${err && err.constructor ? err.constructor.name : 'Error'}: ${err && err.message}`,
    };
  }

  console.log(PROBE_BEGIN);
  console.log(JSON.stringify(report));
  console.log(PROBE_END);
  process.exit(0);
}

/* ----------------------------------------------------------- parent suite -- */

function runParent() {
  console.log('================================================================');
  console.log('2TOOLNE — P0 WINDOWS BLACK SCREEN AFTER IMAGE IMPORT');
  console.log('REGRESSION SUITE (gap-detection span guard)');
  console.log('================================================================');

  /* ---- §1 static: guard must exist and be sane -------------------------- */
  section('SECTION 1 — Source guard presence & sanity (static)');

  const guardOccurrences = APP_JS.split(GUARD_TOKEN).length - 1;
  check(guardOccurrences >= 3, 'MEDIA_INDEX_MAX_SPAN guard present on producer + both consumers',
    `occurrences=${guardOccurrences} (need >= 3)`);

  const capMatch = APP_JS.match(/const\s+MEDIA_INDEX_MAX_SPAN\s*=\s*(\d+)\s*;/);
  check(!!capMatch, 'MEDIA_INDEX_MAX_SPAN declared as an integer constant',
    capMatch ? `value=${capMatch[1]}` : 'declaration not found');
  if (capMatch) {
    const capValue = Number(capMatch[1]);
    check(Number.isFinite(capValue) && capValue > 0 && capValue <= 50000,
      'MEDIA_INDEX_MAX_SPAN is within a sane range (1..50000)', `value=${capValue}`);
    check(capValue === DEFAULT_CAP,
      `MEDIA_INDEX_MAX_SPAN matches the documented cap (${DEFAULT_CAP})`, `value=${capValue}`);
  }

  const producerSrc = extractGapDetectionSource(APP_JS);
  check(/max\s*-\s*min\s*\+\s*1\s*>\s*MEDIA_INDEX_MAX_SPAN/.test(producerSrc),
    'detectMissingIndices rejects spans beyond the cap');

  const applyIdx = APP_JS.indexOf('function applyMissingResolution');
  const payloadIdx = APP_JS.indexOf('function assembleCurrentProjectPayload');
  check(applyIdx > 0 && APP_JS.slice(applyIdx, applyIdx + 2000).includes(GUARD_TOKEN),
    'applyMissingResolution carries the defence-in-depth guard');
  check(payloadIdx > 0 && APP_JS.slice(payloadIdx, payloadIdx + 2500).includes(GUARD_TOKEN),
    'assembleCurrentProjectPayload carries the defence-in-depth guard');

  /* ---- §5 ordering invariant ------------------------------------------- */
  const renderIdx = APP_JS.indexOf('function renderMediaGrid()');
  const renderBody = APP_JS.slice(renderIdx, renderIdx + 1400);
  const bannerCallIdx = renderBody.indexOf('updateMissingBanner()');
  const sliceCallIdx = renderBody.indexOf('updateVisibleSlice(true)');
  check(bannerCallIdx > 0 && sliceCallIdx > 0 && bannerCallIdx < sliceCallIdx,
    'renderMediaGrid() calls updateMissingBanner() BEFORE the first grid paint',
    `banner@${bannerCallIdx} < paint@${sliceCallIdx}`);

  /* ---- §6 filename-only invariant untouched ----------------------------- */
  const gridSection = APP_JS.slice(renderIdx, renderIdx + 6000);
  check(!/createElement\(\s*['"]img['"]\s*\)/.test(gridSection) && !/new\s+Image\s*\(/.test(gridSection),
    'filename-only grid invariant intact (zero image decode in grid path)');

  /* ---- §2/§3/§4 dynamic: run the REAL functions in a guarded child ------ */
  section('SECTION 2 — Pathological inputs must be rejected FAST (dynamic, child process)');
  console.log(`  probe wall-clock budget = ${PROBE_LIMIT_MS}ms`);

  const child = spawnSync(process.execPath, [__filename, '--gap-probe'], {
    encoding: 'utf8',
    timeout: PROBE_LIMIT_MS,
    maxBuffer: 32 * 1024 * 1024,
    env: process.env,
  });

  const timedOut = child.status === null &&
    (child.signal != null || (child.error && child.error.code === 'ETIMEDOUT'));
  if (timedOut) {
    check(false, 'probe child completed within the wall-clock budget',
      `TIMED OUT after ${PROBE_LIMIT_MS}ms (signal=${child.signal}) — main thread starved`);
  } else {
    check(child.status === 0, 'probe child exited cleanly', `status=${child.status} signal=${child.signal}`);
  }

  const stdout = child.stdout || '';
  const bIdx = stdout.indexOf(PROBE_BEGIN);
  const eIdx = stdout.indexOf(PROBE_END);
  let report = null;
  if (bIdx >= 0 && eIdx > bIdx) {
    try {
      report = JSON.parse(stdout.slice(bIdx + PROBE_BEGIN.length, eIdx).trim());
    } catch (err) {
      report = null;
    }
  }

  check(!!report, 'probe child produced a parsable report');
  if (!report && stdout) {
    console.log('  --- child stdout (first 4000 chars) ---');
    console.log(stdout.slice(0, 4000));
  }

  if (report) {
    console.log('');
    console.log('  --- probe report (verbatim from the real functions) ---');
    console.log(`  MEDIA_INDEX_MAX_SPAN = ${report.cap}`);
    if (report.fatal) {
      check(false, 'probe child ran the real functions without a fatal loader error',
        `fatal=${report.fatal}`);
    }
    const cases = Array.isArray(report.cases) ? report.cases : [];
    if (!Array.isArray(report.cases)) {
      check(false, 'probe child emitted a full case report',
        `cases=${JSON.stringify(report.cases)}`);
    }
    for (const c of cases) {
      console.log(`   • ${c.id}: outcome=${c.outcome} ms=${c.ms}` +
        (c.outcome === 'DETECTED'
          ? ` min=${c.min} max=${c.max} missing.length=${c.missingLength}`
          : '') +
        (c.detail ? `  <${c.detail}>` : ''));
    }
    console.log('');

    for (const spec of PROBE_CASES) {
      const got = cases.find((c) => c.id === spec.id);
      if (!got) {
        check(false, `probe case ${spec.id} present`, 'missing from report');
        continue;
      }

      if (spec.expect === 'REJECTED') {
        check(got.outcome === 'NULL_ANALYSIS',
          `${spec.id}: pathological span rejected (null)`,
          `outcome=${got.outcome}${got.detail ? ' ' + got.detail : ''}`);
        check(got.ms < REJECT_DEADLINE_MS,
          `${spec.id}: rejection is fast (< ${REJECT_DEADLINE_MS}ms)`,
          `ms=${got.ms}`);
      } else if (spec.expect === 'DETECTED') {
        check(got.outcome === 'DETECTED', `${spec.id}: genuine series still detected`,
          `outcome=${got.outcome}${got.detail ? ' ' + got.detail : ''}`);
        if (got.outcome === 'DETECTED' && spec.expectedResult) {
          check(got.min === spec.expectedResult.min, `${spec.id}: min unchanged`,
            `expected=${spec.expectedResult.min} got=${got.min}`);
          check(got.max === spec.expectedResult.max, `${spec.id}: max unchanged`,
            `expected=${spec.expectedResult.max} got=${got.max}`);
          check(got.missingLength === spec.expectedResult.missing.length,
            `${spec.id}: missing.length unchanged`,
            `expected=${spec.expectedResult.missing.length} got=${got.missingLength}`);
          if (Array.isArray(got.missing)) {
            check(JSON.stringify(got.missing) === JSON.stringify(spec.expectedResult.missing),
              `${spec.id}: exact missing index list unchanged`,
              `expected=[${spec.expectedResult.missing}] got=[${got.missing}]`);
          }
        }
      } else if (spec.expect === 'NULL_ANALYSIS') {
        check(got.outcome === 'NULL_ANALYSIS', `${spec.id}: returns null safely`,
          `outcome=${got.outcome}`);
      }
    }

    /* ---- §4 consumer guard ------------------------------------------- */
    section('SECTION 3 — Consumer defence-in-depth (dynamic)');
    const ar = report.applyResolution;
    check(!!ar, 'applyMissingResolution probe reported');
    if (ar) {
      console.log(`  applyMissingResolution(span=6000): ms=${ar.ms} ` +
        `resultingMediaList.length=${ar.resultingMediaListLength} ` +
        `inputWasReplaced=${ar.inputWasReplaced} threw=${ar.threw}`);
      check(ar.threw === false, 'applyMissingResolution did not throw');
      check(ar.inputWasReplaced === false,
        'applyMissingResolution did NOT expand the media list from a stale oversized analysis',
        `inputWasReplaced=${ar.inputWasReplaced} length=${ar.resultingMediaListLength}`);
      check(ar.ms < REJECT_DEADLINE_MS,
        `applyMissingResolution returned fast (< ${REJECT_DEADLINE_MS}ms)`, `ms=${ar.ms}`);
    }
  }

  /* ---- summary --------------------------------------------------------- */
  section('RESULT');
  console.log(`  PASSED = ${passCount}`);
  console.log(`  FAILED = ${failCount}`);
  if (failCount > 0) {
    console.error('');
    console.error('  Failing checks:');
    for (const f of failures) console.error(`   - ${f}`);
    console.error('');
    console.error('  VERDICT: FAIL — the unbounded gap-fill span is still reachable.');
    console.error('  (On the unfixed baseline this is the EXPECTED, non-vacuous outcome.)');
    process.exit(1);
  }
  console.log('');
  console.log('  VERDICT: PASS — pathological filename-encoded spans are bounded;');
  console.log('  genuine sequential image series behave exactly as before.');
  process.exit(0);
}

/* ------------------------------------------------------------------- main -- */

if (process.argv.includes('--gap-probe')) {
  try {
    runProbeChild();
  } catch (err) {
    console.log(PROBE_BEGIN);
    console.log(JSON.stringify({ fatal: String(err && err.message ? err.message : err) }));
    console.log(PROBE_END);
    process.exit(3);
  }
} else {
  runParent();
}
