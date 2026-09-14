#!/usr/bin/env node
/**
 * scripts/guard_no_plaintext_python.js
 *
 * ZERO LOOSE PYTHON GATE (2TOOLNE Release Standard §11 / build mandate §5).
 *
 * Recursively inspects a release tree and fails the build if it finds:
 *   - *.py, *.pyc, *.pyo
 *   - __pycache__ directories
 *   - PyInstaller _internal directories (the signature of a forbidden PyInstaller build)
 *   - PyInstaller marker files (base_library.zip, PYZ-00.pyz)
 *
 * The production Python core MUST be a single Nuitka-compiled native binary
 * (CORE_PRODUCTION_PACKAGER=NUITKA, PYINSTALLER_PRODUCTION_USAGE=0).
 *
 * Usage:
 *   node scripts/guard_no_plaintext_python.js <dir> [<dir> ...]
 *   node scripts/guard_no_plaintext_python.js --self-test
 *
 * Exits 0 and prints ZERO_PLAINTEXT_PYTHON=PASS when clean.
 * Exits 1 and prints every violation when not.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const FORBIDDEN_EXTENSIONS = new Set(['.py', '.pyc', '.pyo']);
const FORBIDDEN_DIR_NAMES = new Set(['__pycache__', '_internal', '.pytest_cache']);
const PYINSTALLER_MARKERS = new Set(['base_library.zip', 'PYZ-00.pyz']);

/**
 * Walks a directory tree collecting every forbidden artifact.
 */
function scanTree(rootDir) {
  const violations = [];
  const rootAbs = path.resolve(rootDir);

  if (!fs.existsSync(rootAbs)) {
    return { violations, missing: rootAbs };
  }

  const stack = [rootAbs];
  while (stack.length > 0) {
    const current = stack.pop();
    let entries;
    try {
      entries = fs.readdirSync(current, { withFileTypes: true });
    } catch (err) {
      violations.push({ path: current, reason: `unreadable: ${err.message}` });
      continue;
    }

    for (const entry of entries) {
      const full = path.join(current, entry.name);

      if (entry.isDirectory()) {
        if (FORBIDDEN_DIR_NAMES.has(entry.name)) {
          violations.push({ path: full, reason: `forbidden directory '${entry.name}'` });
          // Do not descend: the whole directory is already a violation.
          continue;
        }
        stack.push(full);
        continue;
      }

      if (entry.isFile()) {
        const ext = path.extname(entry.name).toLowerCase();
        if (FORBIDDEN_EXTENSIONS.has(ext)) {
          violations.push({ path: full, reason: `forbidden extension '${ext}'` });
          continue;
        }
        if (PYINSTALLER_MARKERS.has(entry.name)) {
          violations.push({ path: full, reason: `PyInstaller marker '${entry.name}'` });
        }
      }
      // Symlinks and other node types are intentionally not followed.
    }
  }

  return { violations, missing: null };
}

/**
 * Builds synthetic trees that must FAIL and a clean tree that must PASS.
 * Returns true when the guard behaves correctly.
 */
function selfTest() {
  const os = require('os');
  const tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'guard-python-'));

  let ok = true;

  const assert = (label, condition) => {
    console.log(`  [${condition ? 'PASS' : 'FAIL'}] ${label}`);
    if (!condition) ok = false;
  };

  // --- Case 1: clean tree must PASS ---
  const cleanDir = path.join(tmpRoot, 'clean');
  fs.mkdirSync(path.join(cleanDir, 'resources', 'autoedit-core', 'win-x64'), { recursive: true });
  fs.writeFileSync(path.join(cleanDir, 'resources', 'autoedit-core', 'win-x64', '2toolne-core.exe'), 'MZ-binary');
  fs.writeFileSync(path.join(cleanDir, 'app.asar'), 'asar');
  const cleanResult = scanTree(cleanDir);
  assert('clean tree yields zero violations', cleanResult.violations.length === 0);

  // --- Case 2: planted loose .py must FAIL ---
  const pyDir = path.join(tmpRoot, 'loose-py');
  fs.mkdirSync(path.join(pyDir, 'deep', 'nested'), { recursive: true });
  fs.writeFileSync(path.join(pyDir, 'deep', 'nested', 'sidecar.py'), 'print(1)');
  const pyResult = scanTree(pyDir);
  assert('planted .py is detected', pyResult.violations.some(v => v.path.endsWith('sidecar.py')));

  // --- Case 3: planted __pycache__ must FAIL ---
  const cacheDir = path.join(tmpRoot, 'cache');
  fs.mkdirSync(path.join(cacheDir, 'pkg', '__pycache__'), { recursive: true });
  fs.writeFileSync(path.join(cacheDir, 'pkg', '__pycache__', 'mod.cpython-312.pyc'), 'bytecode');
  const cacheResult = scanTree(cacheDir);
  assert('planted __pycache__ is detected', cacheResult.violations.some(v => v.reason.includes('__pycache__')));

  // --- Case 4: planted PyInstaller _internal must FAIL ---
  const piDir = path.join(tmpRoot, 'pyinstaller');
  fs.mkdirSync(path.join(piDir, 'autoedit-core', '_internal'), { recursive: true });
  fs.writeFileSync(path.join(piDir, 'autoedit-core', '_internal', 'base_library.zip'), 'zip');
  const piResult = scanTree(piDir);
  assert('planted _internal is detected', piResult.violations.some(v => v.reason.includes('_internal')));

  // --- Case 5: missing directory is reported, not crashed on ---
  const missingResult = scanTree(path.join(tmpRoot, 'does-not-exist'));
  assert('missing directory is reported as missing',
    missingResult.missing !== null && missingResult.violations.length === 0);

  fs.rmSync(tmpRoot, { recursive: true, force: true });

  console.log(`\n  SELF-TEST VERDICT: ${ok ? 'PASS' : 'FAIL'}`);
  return ok;
}

function main() {
  const args = process.argv.slice(2);

  if (args.includes('--self-test')) {
    console.log('==============================================================================');
    console.log('ZERO LOOSE PYTHON GUARD — SELF-TEST');
    console.log('==============================================================================');
    process.exit(selfTest() ? 0 : 1);
  }

  const targets = args.filter(a => !a.startsWith('--'));
  if (targets.length === 0) {
    console.error('Usage: node scripts/guard_no_plaintext_python.js <dir> [<dir> ...]');
    console.error('       node scripts/guard_no_plaintext_python.js --self-test');
    process.exit(2);
  }

  console.log('==============================================================================');
  console.log('ZERO LOOSE PYTHON GATE (Release Standard §11)');
  console.log('==============================================================================');

  let allViolations = [];
  for (const target of targets) {
    const { violations, missing } = scanTree(target);
    if (missing) {
      console.log(`  [SKIP] ${target} — not present`);
      continue;
    }
    console.log(`  [SCAN] ${target} — ${violations.length} violation(s)`);
    allViolations = allViolations.concat(violations);
  }

  if (allViolations.length > 0) {
    console.log('\nVIOLATIONS FOUND:');
    for (const v of allViolations) {
      console.log(`  ${v.path}  (${v.reason})`);
    }
    console.log('\nZERO_PLAINTEXT_PYTHON=FAIL');
    process.exit(1);
  }

  console.log('\nZERO_PLAINTEXT_PYTHON=PASS');
  process.exit(0);
}

main();
