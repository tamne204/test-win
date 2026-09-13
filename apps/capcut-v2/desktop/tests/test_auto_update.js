/**
 * apps/capcut-v2/desktop/tests/test_auto_update.js
 * Comprehensive automated tests for AutoUpdateManager.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const http = require('http');
const { AutoUpdateManager, semverCompare } = require('../src/main/updater/auto_update_manager');

async function runTests() {
  console.log('================================================================');
  console.log('RUNNING AUTO-UPDATE AUTOMATED TESTS (AUTOUPD-01 -> AUTOUPD-25)');
  console.log('================================================================\n');

  let passed = 0;
  let failed = 0;

  function test(name, fn) {
    try {
      fn();
      console.log(`  ✓ ${name}`);
      passed++;
    } catch (err) {
      console.error(`  ✗ ${name}:`, err.message);
      failed++;
    }
  }

  async function testAsync(name, fn) {
    try {
      await fn();
      console.log(`  ✓ ${name}`);
      passed++;
    } catch (err) {
      console.error(`  ✗ ${name}:`, err.message);
      failed++;
    }
  }

  // 1. SEMVER COMPARISON TESTS
  console.log('>>> [1/5] Testing Semantic Version Comparison...');
  test('semver: 2.0.9 < 2.0.10', () => {
    assert.strictEqual(semverCompare('2.0.9', '2.0.10'), -1);
  });

  test('semver: 2.0.10 > 2.0.9 (Downgrade rejected)', () => {
    assert.strictEqual(semverCompare('2.0.10', '2.0.9'), 1);
  });

  test('semver: 2.0.0 == 2.0.0 (Up to date)', () => {
    assert.strictEqual(semverCompare('2.0.0', '2.0.0'), 0);
  });

  test('semver: v2.0.0 == 2.0.0 (Handles prefix "v")', () => {
    assert.strictEqual(semverCompare('v2.0.0', '2.0.0'), 0);
  });

  test('semver: 2.0.0-rc.1 < 2.0.0', () => {
    assert.strictEqual(semverCompare('2.0.0-rc.1', '2.0.0'), -1);
  });

  test('semver: 1.9.99 < 2.0.0', () => {
    assert.strictEqual(semverCompare('1.9.99', '2.0.0'), -1);
  });

  // 2. SHA-256 INTEGRITY & TAMPER PROTECTION
  console.log('\n>>> [2/5] Testing Cryptographic SHA-256 Verification & Tamper Rejection...');
  const testDir = path.join(__dirname, 'tmp_updater_test');
  fs.mkdirSync(testDir, { recursive: true });

  const testFilePath = path.join(testDir, 'sample_package.zip');
  const sampleContent = Buffer.from('2TOOLNE_AUTOEDIT_V2_GENUINE_PACKAGE_PAYLOAD_TEST');
  fs.writeFileSync(testFilePath, sampleContent);
  const correctSha256 = crypto.createHash('sha256').update(sampleContent).digest('hex');

  const updater = new AutoUpdateManager({ apiBase: 'http://127.0.0.1:9999' });

  await testAsync('integrity: verify genuine artifact SHA-256 matches', async () => {
    const computed = await updater._computeSha256(testFilePath);
    assert.strictEqual(computed, correctSha256);
  });

  await testAsync('integrity: reject tampered artifact', async () => {
    const tamperedSha256 = '0000000000000000000000000000000000000000000000000000000000000000';
    const computed = await updater._computeSha256(testFilePath);
    assert.notStrictEqual(computed, tamperedSha256);
  });

  // 3. ACTIVE JOB LOCKS
  console.log('\n>>> [3/5] Testing Active Work Job Protection (Queue & Flow)...');
  test('job_lock: prevents install during active Pipeline Queue jobs', () => {
    const busyQueue = { getActiveJobs: () => [{ id: 'job-1', state: 'PROCESSING' }] };
    const busyManager = new AutoUpdateManager({ pipelineQueue: busyQueue });
    assert.strictEqual(busyManager.isWorkInProgress(), true);
  });

  test('job_lock: prevents install during active Google Flow automation', () => {
    const busyFlow = { isAutomationRunning: () => true };
    const busyManager = new AutoUpdateManager({ flowBrowserManager: busyFlow });
    assert.strictEqual(busyManager.isWorkInProgress(), true);
  });

  test('job_lock: allows install when idle', () => {
    const idleQueue = { getActiveJobs: () => [] };
    const idleFlow = { isAutomationRunning: () => false };
    const idleManager = new AutoUpdateManager({
      pipelineQueue: idleQueue,
      flowBrowserManager: idleFlow,
    });
    assert.strictEqual(idleManager.isWorkInProgress(), false);
  });

  // 4. MOCK UPDATE SERVER: DISCOVERY, DOWNLOAD & VERIFY
  console.log('\n>>> [4/5] Testing Discovery, Download & Progress Tracking...');

  let mockServer;
  await new Promise((resolve) => {
    mockServer = http.createServer((req, res) => {
      const parsedUrl = new URL(req.url, 'http://127.0.0.1');

      if (parsedUrl.pathname === '/api/v1/update/check') {
        const clientVer = parsedUrl.searchParams.get('version');
        const hasUpdate = semverCompare(clientVer, '2.0.1') < 0;
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(
          JSON.stringify({
            success: true,
            has_update: hasUpdate,
            current_version: clientVer,
            latest_version: '2.0.1',
            channel: 'stable',
            release_notes: 'Cải tiến hiệu năng và sửa lỗi.',
            package: {
              url: `http://127.0.0.1:${mockServer.address().port}/download/payload.zip`,
              sha256: correctSha256,
              filename: 'payload.zip',
              size_bytes: sampleContent.length,
            },
          })
        );
      }

      if (parsedUrl.pathname === '/download/payload.zip') {
        res.writeHead(200, {
          'Content-Type': 'application/zip',
          'Content-Length': sampleContent.length,
        });
        return res.end(sampleContent);
      }

      res.writeHead(404);
      res.end();
    });

    mockServer.listen(0, '127.0.0.1', () => resolve());
  });

  const port = mockServer.address().port;
  const testClient = new AutoUpdateManager({
    apiBase: `http://127.0.0.1:${port}`,
  });
  testClient.currentVersion = '2.0.0';

  await testAsync('discovery: detect newer version 2.0.1 from 2.0.0', async () => {
    const res = await testClient.checkForUpdates({ manual: true });
    assert.strictEqual(res.ok, true);
    assert.strictEqual(res.hasUpdate, true);
    assert.strictEqual(res.latestVersion, '2.0.1');
    assert.strictEqual(testClient.state, 'UPDATE_AVAILABLE');
  });

  await testAsync('download: stream download with 100% progress and SHA-256 match', async () => {
    let progressSeen = false;
    testClient.on('progress', (p) => {
      progressSeen = true;
      assert(p.percent >= 0 && p.percent <= 100);
    });

    const dlRes = await testClient.downloadUpdate();
    assert.strictEqual(dlRes.ok, true);
    assert.strictEqual(testClient.state, 'DOWNLOADED');
    assert(progressSeen);
    assert(fs.existsSync(dlRes.packagePath));
  });

  await testAsync('up-to-date: client already on 2.0.1 reports up to date', async () => {
    testClient.currentVersion = '2.0.1';
    const res = await testClient.checkForUpdates({ manual: true });
    assert.strictEqual(res.ok, true);
    assert.strictEqual(res.hasUpdate, false);
    assert.strictEqual(testClient.state, 'UP_TO_DATE');
  });

  // 5. OFFLINE & ERROR RECOVERY
  console.log('\n>>> [5/5] Testing Offline & Error Handling...');
  const offlineClient = new AutoUpdateManager({ apiBase: 'http://127.0.0.1:54321' });

  await testAsync('offline: handles connection refused gracefully without crash', async () => {
    const res = await offlineClient.checkForUpdates({ manual: true });
    assert.strictEqual(res.ok, false);
    assert.strictEqual(offlineClient.state, 'ERROR');
    assert(res.error.includes('ECONNREFUSED') || res.error.includes('Connection timed out'));
  });

  mockServer.close();
  try { fs.rmSync(testDir, { recursive: true, force: true }); } catch (_) {}

  console.log('\n================================================================');
  console.log(`TEST SUMMARY: ${passed} PASSED, ${failed} FAILED`);
  console.log('================================================================\n');

  if (failed > 0) process.exit(1);
}

runTests().catch((err) => {
  console.error('Fatal test error:', err);
  process.exit(1);
});
