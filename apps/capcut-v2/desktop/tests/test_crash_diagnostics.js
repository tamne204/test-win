/**
 * apps/capcut-v2/desktop/tests/test_crash_diagnostics.js
 *
 * Test suite for CrashDiagnosticsManager:
 * 1. Correctly classifies RENDERER_OOM, RENDERER_CRASH, GPU_PROCESS_CRASH, GPU_OOM, OTHER.
 * 2. Writes structured JSON reports with memory and GPU snapshots.
 * 3. Prevents infinite reload loops when crash threshold is exceeded.
 * 4. Distinguishes between GPU process death and fatal renderer death.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { CrashDiagnosticsManager } = require('../src/main/crash_diagnostics');

const TEST_DIAG_DIR = path.join(os.tmpdir(), `2toolne_diag_test_${Date.now()}`);

function runTests() {
  console.log('=== RUNNING CRASH DIAGNOSTICS TESTS ===\n');

  const manager = new CrashDiagnosticsManager({
    diagnosticsDir: TEST_DIAG_DIR,
    maxReloads: 2,
    reloadWindowMs: 60000,
  });

  // Mock window
  let reloadCount = 0;
  const mockWindow = {
    isDestroyed: () => false,
    reload: () => { reloadCount++; },
    webContents: {
      getOSProcessId: () => 12345,
      isCrashed: () => false,
    },
  };
  manager.attach(mockWindow);

  // 1. Classification Tests
  console.log('[Test 1] Verifying crash classifications...');
  assert.strictEqual(manager.classifyRendererCrash({ reason: 'oom' }), 'RENDERER_OOM');
  assert.strictEqual(manager.classifyRendererCrash({ reason: 'out-of-memory' }), 'RENDERER_OOM');
  assert.strictEqual(manager.classifyRendererCrash({ reason: 'crashed', exitCode: 1 }), 'RENDERER_CRASH');
  assert.strictEqual(manager.classifyRendererCrash({ reason: 'killed', exitCode: -9 }), 'RENDERER_CRASH');
  assert.strictEqual(manager.classifyRendererCrash({ reason: 'clean-exit', exitCode: 0 }), 'OTHER');

  assert.strictEqual(manager.classifyChildProcessCrash({ type: 'GPU', reason: 'oom' }), 'GPU_OOM');
  assert.strictEqual(manager.classifyChildProcessCrash({ type: 'GPU', reason: 'crashed' }), 'GPU_PROCESS_CRASH');
  assert.strictEqual(manager.classifyChildProcessCrash({ type: 'Utility', reason: 'crashed' }), 'OTHER');
  console.log('  ✓ All 7 classifications verified');

  // 2. Trigger Renderer OOM Event & Check Report Generation
  console.log('[Test 2] Triggering simulated RENDERER_OOM event...');
  manager.handleRenderProcessGone({}, { reason: 'oom', exitCode: 137 });

  const lastCrash = manager.getLastRendererCrash();
  assert(lastCrash, 'Renderer crash record must exist');
  assert.strictEqual(lastCrash.classification, 'RENDERER_OOM');
  assert.strictEqual(lastCrash.renderer_pid, 12345);
  assert(lastCrash.memory_snapshot.main_process, 'Memory snapshot must be captured');

  // Verify diagnostic file was written
  const diagFiles = fs.readdirSync(TEST_DIAG_DIR).filter((f) => f.endsWith('.json'));
  assert.strictEqual(diagFiles.length, 1, 'Diagnostic JSON file must be generated');

  const fileContent = JSON.parse(fs.readFileSync(path.join(TEST_DIAG_DIR, diagFiles[0]), 'utf8'));
  assert.strictEqual(fileContent.classification, 'RENDERER_OOM');
  assert.strictEqual(fileContent.event_type, 'RENDER_PROCESS_GONE');
  console.log('  ✓ Structured crash report generated and verified:', diagFiles[0]);

  // 3. Trigger GPU Child Process Gone (Renderer Survives)
  console.log('[Test 3] Triggering simulated GPU child-process gone event...');
  manager.handleChildProcessGone({}, { type: 'GPU', reason: 'crashed', exitCode: 1, serviceName: 'GPU Process' });

  const lastGpu = manager.getLastGpuCrash();
  assert(lastGpu, 'GPU crash record must exist');
  assert.strictEqual(lastGpu.classification, 'GPU_PROCESS_CRASH');
  assert.strictEqual(lastGpu.renderer_alive, true, 'Renderer must be reported alive during GPU child-process exit');
  console.log('  ✓ Distinguishable GPU child process exit recorded');

  // 4. Infinite Reload Loop Guard
  console.log('[Test 4] Verifying reload loop threshold (max 2 reloads)...');
  // Crash 1 was already triggered (reloadTimestamps.length = 1)
  // Trigger Crash 2:
  manager.handleRenderProcessGone({}, { reason: 'crashed', exitCode: 1 });
  // Trigger Crash 3 (should hit threshold and NOT reload):
  manager.handleRenderProcessGone({}, { reason: 'crashed', exitCode: 1 });

  assert.strictEqual(manager.reloadTimestamps.length, 2, 'Reload timestamps must not exceed maxReloads (2)');
  console.log('  ✓ Loop guard verified: prevented infinite reload cycle');

  // Cleanup
  try {
    fs.rmSync(TEST_DIAG_DIR, { recursive: true, force: true });
  } catch (e) {}

  console.log('\n✓ ALL CRASH DIAGNOSTICS TESTS PASSED!\n');
}

runTests();
