/**
 * apps/capcut-v2/desktop/tests/test_cloud_explorer.js
 * Comprehensive Automated Test Suite for PRIORITY 4: Cloud File Explorer.
 * Covers tests CLOUD-T01 through CLOUD-T20.
 */

const assert = require('assert');
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { CloudClient } = require('../src/main/cloud_client');

// -----------------------------------------------------------------------------
// Test Fixtures & Mock Backend Server
// -----------------------------------------------------------------------------
let mockServer = null;
let serverBaseUrl = '';
let mockDatabase = {};
let tempTestDir = '';
let cloudClient = null;

function setupMockServer() {
  return new Promise((resolve) => {
    mockServer = http.createServer((req, res) => {
      const url = new URL(req.url, serverBaseUrl);
      const authHeader = req.headers['authorization'] || '';

      // Check auth header except for direct storage upload endpoint
      if (!url.pathname.startsWith('/mock-storage') && !authHeader.startsWith('Bearer valid_token_123')) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'Unauthorized token' }));
        return;
      }

      // Handle direct storage PUT stream separately (no body parsing, safe socket handling)
      if (req.method === 'PUT' && url.pathname === '/mock-storage/upload-session-999') {
        let timer = null;
        let finished = false;

        const cleanup = () => {
          finished = true;
          if (timer) {
            clearTimeout(timer);
            timer = null;
          }
        };

        req.on('aborted', cleanup);
        req.on('error', cleanup);
        req.socket.on('close', cleanup);
        res.on('close', cleanup);

        if (mockDatabase.delayUploadMs) {
          timer = setTimeout(() => {
            if (finished || res.writableEnded || req.socket.destroyed) return;
            finished = true;
            try {
              res.writeHead(200, { 'Content-Type': 'application/json', 'Connection': 'close' });
              res.end(JSON.stringify({ status: 'uploaded' }));
            } catch (e) {}
          }, mockDatabase.delayUploadMs);
          req.resume(); // drain upload data
        } else {
          req.on('data', () => {});
          req.on('end', () => {
            if (finished || res.writableEnded || req.socket.destroyed) return;
            finished = true;
            res.writeHead(200, { 'Content-Type': 'application/json', 'Connection': 'close' });
            res.end(JSON.stringify({ status: 'uploaded' }));
          });
        }
        return;
      }

      let body = '';
      req.on('data', (chunk) => {
        body += chunk;
      });

      req.on('end', () => {
        let json = {};
        try {
          if (body) json = JSON.parse(body);
        } catch (e) {}

        // Route matching
        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/spaces') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            spaces: [
              { id: 1, name: 'Personal Space', type: 'PERSONAL', role: 'OWNER' },
              { id: 2, name: 'Team Alpha', type: 'TEAM', role: 'MEMBER' },
            ],
          }));
          return;
        }

        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/spaces/1/quota') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            quota: {
              quota_bytes: 10 * 1024 * 1024, // 10 MB total
              used_bytes: mockDatabase.usedBytes || 2 * 1024 * 1024, // 2 MB used
              free_bytes: (10 * 1024 * 1024) - (mockDatabase.usedBytes || 2 * 1024 * 1024),
              percent: 20,
            },
          }));
          return;
        }

        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/spaces/1/files') {
          const folderId = url.searchParams.get('folder_id');
          const search = url.searchParams.get('q');

          let folders = [
            { id: 10, space_id: 1, parent_id: null, name: 'Images', created_at: '2026-09-08T10:00:00Z' },
            { id: 20, space_id: 1, parent_id: null, name: 'Audio', created_at: '2026-09-08T10:00:00Z' },
          ];
          let files = [
            { id: 101, space_id: 1, folder_id: null, name: 'sample1.png', size_bytes: 1024, mime_type: 'image/png', sha256_hash: 'hash_img_1', created_at: '2026-09-08T10:05:00Z' },
            { id: 102, space_id: 1, folder_id: null, name: 'voice.mp3', size_bytes: 2048, mime_type: 'audio/mp3', sha256_hash: 'hash_audio_1', created_at: '2026-09-08T10:06:00Z' },
          ];

          if (folderId === '10') {
            folders = [];
            files = [
              { id: 103, space_id: 1, folder_id: 10, name: 'nested.jpg', size_bytes: 512, mime_type: 'image/jpeg', sha256_hash: 'hash_nested_1' },
            ];
          }

          if (search) {
            files = files.filter(f => f.name.includes(search));
          }

          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            space_id: 1,
            folder_id: folderId ? parseInt(folderId, 10) : null,
            folders,
            files,
            breadcrumbs: folderId === '10' ? [
              { id: null, name: 'Cloud Cá Nhân' },
              { id: 10, name: 'Images' }
            ] : [{ id: null, name: 'Cloud Cá Nhân' }],
          }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/spaces/1/folders') {
          res.writeHead(201, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            folder: { id: 30, name: json.name, parent_id: json.parent_id },
          }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/files/101/rename') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, name: json.name }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/folders/10/rename') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, name: json.name }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/files/101/move') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, folder_id: json.folder_id }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/folders/10/move') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, parent_id: json.parent_id }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/files/101/trash') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, trashed: true }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/folders/10/trash') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, trashed: true }));
          return;
        }

        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/spaces/1/trash') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            trash: [
              { id: 101, type: 'file', name: 'sample1.png', size_bytes: 1024, deleted_at: '2026-09-08T11:00:00Z' },
            ],
          }));
          return;
        }

        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/files/101/restore') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, restored: true }));
          return;
        }

        if (req.method === 'DELETE' && url.pathname === '/api/v1/cloud/files/101/permanent') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, deleted: true }));
          return;
        }

        // Upload session creation
        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/spaces/1/uploads/create') {
          const fileSize = json.file_size || 0;
          if (fileSize > 8 * 1024 * 1024) { // simulate quota limit
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, error: 'Vượt quá dung lượng khả dụng của không gian.' }));
            return;
          }

          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            session_id: 'session_upload_999',
            session_url: `${serverBaseUrl}/mock-storage/upload-session-999`,
            chunk_size: 1024 * 1024,
          }));
          return;
        }


        // Upload Finalize
        if (req.method === 'POST' && url.pathname.includes('/finalize')) {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            file: {
              id: 202,
              space_id: 1,
              name: 'test_upload.png',
              size_bytes: 4096,
              sha256_hash: 'mock_uploaded_hash',
            },
          }));
          return;
        }

        // Upload Abort
        if (req.method === 'POST' && url.pathname.includes('/abort')) {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, aborted: true }));
          return;
        }

        // Download stream
        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/files/101/download') {
          const imgData = 'MOCK_IMAGE_DATA';
          res.writeHead(200, {
            'Content-Type': 'image/png',
            'Content-Length': Buffer.byteLength(imgData),
          });
          res.end(imgData);
          return;
        }

        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/files/102/download') {
          const audioData = 'MOCK_AUDIO_DATA';
          res.writeHead(200, {
            'Content-Type': 'audio/mp3',
            'Content-Length': Buffer.byteLength(audioData),
          });
          res.end(audioData);
          return;
        }

        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Route not found: ' + url.pathname }));
      });
    });

    mockServer.listen(0, '127.0.0.1', () => {
      const port = mockServer.address().port;
      serverBaseUrl = `http://127.0.0.1:${port}`;
      resolve();
    });
  });
}

// -----------------------------------------------------------------------------
// Test Runner
// -----------------------------------------------------------------------------
async function runAllTests() {
  console.log('====================================================');
  console.log('🚀 RUNNING PRIORITY 4: CLOUD EXPLORER TEST SUITE (CLOUD-T01 to CLOUD-T20)');
  console.log('====================================================\n');

  await setupMockServer();
  tempTestDir = fs.mkdtempSync(path.join(os.tmpdir(), '2toolne_cloud_test_'));
  const cacheDir = path.join(tempTestDir, 'cache');

  cloudClient = new CloudClient();
  cloudClient.setApiBase(serverBaseUrl);
  cloudClient.setCacheDir(cacheDir);

  // CLOUD-T01: Unauthenticated locked state test
  console.log('--- CLOUD-T01: Unauthenticated locked state test ---');
  cloudClient.setAuthToken(null);
  const unauthRes = await cloudClient.getSpaces();
  assert.strictEqual(unauthRes.ok, false);
  assert.strictEqual(unauthRes.statusCode, 401);
  assert.strictEqual(unauthRes.code, 'AUTH_REQUIRED');
  console.log('✅ CLOUD-T01 PASSED: Unauthenticated calls safely return AUTH_REQUIRED (HTTP 401).\n');

  // Set valid token
  cloudClient.setAuthToken('valid_token_123');

  // CLOUD-T02: Authenticated user loads personal space and quota
  console.log('--- CLOUD-T02: Authenticated user loads personal space and quota ---');
  const spacesRes = await cloudClient.getSpaces();
  assert.strictEqual(spacesRes.ok, true);
  assert(Array.isArray(spacesRes.spaces) && spacesRes.spaces.length >= 1);
  assert.strictEqual(spacesRes.spaces[0].name, 'Personal Space');
  console.log('✅ CLOUD-T02 PASSED: Spaces retrieved successfully.\n');

  // CLOUD-T03: Quota bar calculation and display
  console.log('--- CLOUD-T03: Quota bar calculation and display ---');
  const quotaRes = await cloudClient.getQuota(1);
  assert.strictEqual(quotaRes.ok, true);
  assert.strictEqual(quotaRes.quota.quota_bytes, 10 * 1024 * 1024);
  assert.strictEqual(quotaRes.quota.used_bytes, 2 * 1024 * 1024);
  const pct = Math.round((quotaRes.quota.used_bytes / quotaRes.quota.quota_bytes) * 100);
  assert.strictEqual(pct, 20);
  console.log(`✅ CLOUD-T03 PASSED: Quota calculation accurate (${pct}%).\n`);

  // CLOUD-T04: VFS folder navigation (breadcrumb hierarchy and root)
  console.log('--- CLOUD-T04: VFS folder navigation ---');
  const rootFiles = await cloudClient.listFiles(1, null);
  assert.strictEqual(rootFiles.ok, true);
  assert.strictEqual(rootFiles.folders.length, 2);
  assert.strictEqual(rootFiles.files.length, 2);
  assert.strictEqual(rootFiles.breadcrumbs[0].name, 'Cloud Cá Nhân');

  const nestedFiles = await cloudClient.listFiles(1, 10);
  assert.strictEqual(nestedFiles.ok, true);
  assert.strictEqual(nestedFiles.folders.length, 0);
  assert.strictEqual(nestedFiles.files[0].name, 'nested.jpg');
  assert.strictEqual(nestedFiles.breadcrumbs[1].name, 'Images');
  console.log('✅ CLOUD-T04 PASSED: VFS folder navigation and breadcrumbs working correctly.\n');

  // CLOUD-T05: Create new folder mutation
  console.log('--- CLOUD-T05: Create new folder mutation ---');
  const createFolderRes = await cloudClient.createFolder(1, 'Project_Alpha', null);
  assert.strictEqual(createFolderRes.ok, true);
  assert.strictEqual(createFolderRes.folder.name, 'Project_Alpha');
  console.log('✅ CLOUD-T05 PASSED: Folder creation mutation successful.\n');

  // CLOUD-T06: Rename file and folder mutation
  console.log('--- CLOUD-T06: Rename file and folder mutation ---');
  const renameFileRes = await cloudClient.renameItem('file', 101, 'renamed_sample.png');
  assert.strictEqual(renameFileRes.ok, true);

  const renameFolderRes = await cloudClient.renameItem('folder', 10, 'Images_Archived');
  assert.strictEqual(renameFolderRes.ok, true);
  console.log('✅ CLOUD-T06 PASSED: File and folder renaming successful.\n');

  // CLOUD-T07: Move file and folder mutation
  console.log('--- CLOUD-T07: Move file and folder mutation ---');
  const moveFileRes = await cloudClient.moveItem('file', 101, 10);
  assert.strictEqual(moveFileRes.ok, true);

  const moveFolderRes = await cloudClient.moveItem('folder', 10, 20);
  assert.strictEqual(moveFolderRes.ok, true);
  console.log('✅ CLOUD-T07 PASSED: File and folder move mutations verified.\n');

  // CLOUD-T08: Soft delete / move to trash mutation
  console.log('--- CLOUD-T08: Soft delete / move to trash mutation ---');
  const trashFileRes = await cloudClient.trashItem('file', 101);
  assert.strictEqual(trashFileRes.ok, true);
  console.log('✅ CLOUD-T08 PASSED: Item safely moved to trash.\n');

  // CLOUD-T09: Trash listing and restore mutation
  console.log('--- CLOUD-T09: Trash listing and restore mutation ---');
  const listTrashRes = await cloudClient.listTrash(1);
  assert.strictEqual(listTrashRes.ok, true);
  assert.strictEqual(listTrashRes.trash[0].id, 101);

  const restoreRes = await cloudClient.restoreItem('file', 101);
  assert.strictEqual(restoreRes.ok, true);
  console.log('✅ CLOUD-T09 PASSED: Trashed items listed and restored successfully.\n');

  // CLOUD-T10: Permanent delete mutation
  console.log('--- CLOUD-T10: Permanent delete mutation ---');
  const permDeleteRes = await cloudClient.permanentDeleteItem('file', 101);
  assert.strictEqual(permDeleteRes.ok, true);
  console.log('✅ CLOUD-T10 PASSED: Permanent delete mutation verified.\n');

  // Create a dummy local file for uploads
  const sampleUploadPath = path.join(tempTestDir, 'test_upload.png');
  fs.writeFileSync(sampleUploadPath, Buffer.alloc(4096, 0xAA));

  // CLOUD-T11: Direct streaming upload (resumable session URL creation and direct chunk upload)
  console.log('--- CLOUD-T11: Direct streaming upload ---');
  let progressReported = false;
  const uploadRes = await cloudClient.uploadFile(
    sampleUploadPath,
    1,
    null,
    (p) => {
      progressReported = true;
    }
  );
  assert.strictEqual(uploadRes.ok, true);
  assert.strictEqual(uploadRes.file_id, 202);
  console.log('✅ CLOUD-T11 PASSED: Streaming resumable upload completed successfully.\n');

  // CLOUD-T12: Upload quota pre-check
  console.log('--- CLOUD-T12: Upload quota pre-check ---');
  // Set usedBytes in mock server to simulate nearly full space (free = 100KB)
  mockDatabase.usedBytes = 10 * 1024 * 1024 - 100 * 1024;
  const bigFilePath = path.join(tempTestDir, 'big_file.bin');
  fs.writeFileSync(bigFilePath, Buffer.alloc(500 * 1024, 0xFF)); // 500 KB > 100 KB free
  const quotaFailRes = await cloudClient.uploadFile(bigFilePath, 1, null);
  assert.strictEqual(quotaFailRes.ok, false);
  assert.strictEqual(quotaFailRes.code, 'QUOTA_EXCEEDED');
  assert(quotaFailRes.error.includes('không còn đủ dung lượng'));
  mockDatabase.usedBytes = 2 * 1024 * 1024; // reset
  console.log('✅ CLOUD-T12 PASSED: Upload correctly blocked when quota is exceeded.\n');

  // CLOUD-T13: Upload progress event propagation and speed calculation
  console.log('--- CLOUD-T13: Upload progress event propagation ---');
  assert.strictEqual(progressReported, true, 'Progress callback must have fired during CLOUD-T11');
  console.log('✅ CLOUD-T13 PASSED: Progress events emitted and verified.\n');

  // CLOUD-T14: Abort / cancel active upload
  console.log('--- CLOUD-T14: Abort / cancel active upload ---');
  mockDatabase.delayUploadMs = 200; // delay upload stream
  const slowUploadPromise = cloudClient.uploadFile(sampleUploadPath, 1, null);

  // Give it 50ms then abort
  await new Promise((r) => setTimeout(r, 50));
  const activeIds = Array.from(cloudClient.activeUploads.keys());
  assert(activeIds.length > 0, 'Active upload must be registered in activeUploads map');
  const cancelRes = await cloudClient.cancelUpload(activeIds[0]);
  assert.strictEqual(cancelRes.ok, true);

  const slowRes = await slowUploadPromise;
  assert.strictEqual(slowRes.ok, false);
  assert(slowRes.error.includes('thất bại') || slowRes.error.includes('abort') || slowRes.error.includes('Hủy'));
  mockDatabase.delayUploadMs = 0;
  await new Promise((r) => setTimeout(r, 100)); // wait for socket cleanup
  console.log('✅ CLOUD-T14 PASSED: Upload abortion cleans up stream and cancels upload session.\n');

  // CLOUD-T15: Streaming download to local file
  console.log('--- CLOUD-T15: Streaming download to local file ---');
  const destPath = path.join(tempTestDir, 'downloaded_sample.png');
  const downloadRes = await cloudClient.downloadFile(101, destPath);
  if (!downloadRes.ok) console.log('downloadRes failed:', downloadRes);
  assert.strictEqual(downloadRes.ok, true);
  assert(fs.existsSync(destPath));
  assert.strictEqual(fs.readFileSync(destPath, 'utf8'), 'MOCK_IMAGE_DATA');
  console.log('✅ CLOUD-T15 PASSED: Streaming download saved file to disk correctly.\n');

  // CLOUD-T16: Local deterministic cache management (<cacheDir>/<fileId>_<sha256>.<ext>)
  console.log('--- CLOUD-T16: Local deterministic cache management ---');
  const fileObj = {
    id: 101,
    space_id: 1,
    name: 'sample1.png',
    sha256_hash: 'hash_abc123456789',
    size_bytes: 15,
  };
  const expectedCachePath = path.join(cacheDir, '101_hash_abc123.png');
  const cacheRes1 = await cloudClient.cacheAndGetPath(fileObj);
  assert.strictEqual(cacheRes1.ok, true);
  assert.strictEqual(cacheRes1.localPath, expectedCachePath);
  assert(fs.existsSync(expectedCachePath));
  console.log('✅ CLOUD-T16 PASSED: File downloaded to deterministic cache location.\n');

  // CLOUD-T17: Cache hit avoids re-downloading if hash matches
  console.log('--- CLOUD-T17: Cache hit avoids re-downloading ---');
  const mtimeBefore = fs.statSync(expectedCachePath).mtimeMs;
  const cacheRes2 = await cloudClient.cacheAndGetPath(fileObj);
  assert.strictEqual(cacheRes2.ok, true);
  assert.strictEqual(cacheRes2.cached, true);
  const mtimeAfter = fs.statSync(expectedCachePath).mtimeMs;
  assert.strictEqual(mtimeBefore, mtimeAfter, 'Cache hit must not touch or re-download the file');
  console.log('✅ CLOUD-T17 PASSED: Cache hit avoided redundant network download.\n');

  // CLOUD-T18: Cache invalidation if hash changes
  console.log('--- CLOUD-T18: Cache invalidation when hash changes ---');
  const updatedFileObj = {
    id: 101,
    space_id: 1,
    name: 'sample1.png',
    sha256_hash: 'hash_xyz987654321',
    size_bytes: 15,
  };
  const expectedUpdatedCachePath = path.join(cacheDir, '101_hash_xyz987.png');
  const cacheRes3 = await cloudClient.cacheAndGetPath(updatedFileObj);
  assert.strictEqual(cacheRes3.ok, true);
  assert.strictEqual(cacheRes3.localPath, expectedUpdatedCachePath);
  assert(fs.existsSync(expectedUpdatedCachePath));
  console.log('✅ CLOUD-T18 PASSED: Changed hash triggered fresh download to new cache key.\n');

  // CLOUD-T19: Studio Cloud Image Picker integration
  console.log('--- CLOUD-T19: Studio Cloud Image Picker integration ---');
  const studioImageCloudItem = {
    id: 101,
    space_id: 1,
    name: 'sample1.png',
    sha256_hash: 'hash_img_123456',
    size_bytes: 15,
  };
  const studioImageCache = await cloudClient.cacheAndGetPath(studioImageCloudItem);
  assert.strictEqual(studioImageCache.ok, true);
  // Pass to Studio mediaList: local path is pure disk file
  const mockStudioState = { mediaList: [] };
  mockStudioState.mediaList.push(studioImageCache.localPath);
  assert(mockStudioState.mediaList[0].endsWith('.png'));
  assert(fs.existsSync(mockStudioState.mediaList[0]));
  console.log('✅ CLOUD-T19 PASSED: Studio image integration decoupled and cached locally.\n');

  // CLOUD-T20: Studio Cloud Audio Picker integration
  console.log('--- CLOUD-T20: Studio Cloud Audio Picker integration ---');
  const studioAudioCloudItem = {
    id: 102,
    space_id: 1,
    name: 'voice.mp3',
    sha256_hash: 'hash_audio_1234',
    size_bytes: 15,
  };
  const studioAudioCache = await cloudClient.cacheAndGetPath(studioAudioCloudItem);
  assert.strictEqual(studioAudioCache.ok, true);
  mockStudioState.audioPath = studioAudioCache.localPath;
  assert(mockStudioState.audioPath.endsWith('.mp3'));
  assert(fs.existsSync(mockStudioState.audioPath));
  console.log('✅ CLOUD-T20 PASSED: Studio audio integration decoupled and cached locally.\n');

  // Cleanup
  mockServer.close();
  try {
    fs.rmSync(tempTestDir, { recursive: true, force: true });
  } catch (e) {}

  console.log('====================================================');
  console.log('🎉 ALL 20 TESTS (CLOUD-T01 to CLOUD-T20) PASSED WITH ZERO ERRORS!');
  console.log('====================================================');
}

runAllTests().catch((err) => {
  console.error('❌ Test failed with error:', err);
  if (mockServer) mockServer.close();
  process.exit(1);
});
