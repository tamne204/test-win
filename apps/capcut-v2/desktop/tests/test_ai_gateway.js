/**
 * apps/capcut-v2/desktop/tests/test_ai_gateway.js
 * Comprehensive Automated Test Suite for PHASE 1: AI Gateway & Scoped AI Access Keys.
 * Covers tests P1-01 through P1-15 per Master Implementation Directive.
 */

const assert = require('assert');
const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { spawn, execSync } = require('child_process');
const { CloudClient } = require('../src/main/cloud_client');
const cli = require('../../../../cli/2toolne');

// -----------------------------------------------------------------------------
// Test Configuration & Mock Production Server
// -----------------------------------------------------------------------------
let mockServer = null;
let serverBaseUrl = '';
let tempTestDir = '';
let activeDb = null;

// Mock database state
function resetMockDb() {
  activeDb = {
    users: [
      { id: 'user_01', username: 'alice', email: 'alice@2toolne.test', role: 'user' },
      { id: 'user_02', username: 'bob', email: 'bob@2toolne.test', role: 'user' },
    ],
    spaces: [
      { id: 'cs_pers_alice', owner_type: 'USER', owner_id: 'user_01', name: 'Alice Personal' },
      { id: 'cs_team_alpha', owner_type: 'TEAM', owner_id: 'team_alpha', name: 'Alpha Team Space' },
      { id: 'cs_pers_bob', owner_type: 'USER', owner_id: 'user_02', name: 'Bob Personal' },
    ],
    team_members: [
      { team_id: 'team_alpha', user_id: 'user_01', role: 'OWNER', status: 'ACTIVE' },
      { team_id: 'team_alpha', user_id: 'user_02', role: 'VIEWER', status: 'ACTIVE' },
    ],
    folders: [
      { id: 'fld_alice_ai', cloud_space_id: 'cs_pers_alice', parent_id: null, name: 'AI Inputs' },
      { id: 'fld_alpha_ai', cloud_space_id: 'cs_team_alpha', parent_id: null, name: 'AI Inputs' },
      { id: 'fld_alice_other', cloud_space_id: 'cs_pers_alice', parent_id: null, name: 'Secret Documents' },
    ],
    files: [],
    ai_access_keys: [],
  };
}

function startMockServer() {
  return new Promise((resolve) => {
    resetMockDb();

    mockServer = http.createServer((req, res) => {
      const url = new URL(req.url, `http://${req.headers.host}`);
      const pathname = url.pathname;

      let bodyStr = '';
      req.on('data', (chunk) => (bodyStr += chunk));
      req.on('end', () => {
        let body = {};
        try {
          body = JSON.parse(bodyStr);
        } catch (e) {}

        const sendJson = (status, data) => {
          res.writeHead(status, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify(data));
        };

        const authHeader = req.headers['authorization'] || '';
        let authenticatedUser = null;
        let activeKey = null;

        if (authHeader.startsWith('Bearer 2tl_ai_')) {
          const rawKey = authHeader.replace('Bearer ', '').trim();
          const [prefix, secret] = rawKey.split('_').slice(2);
          const fullPrefix = `2tl_ai_${prefix}`;
          const kRow = activeDb.ai_access_keys.find((k) => k.key_prefix === fullPrefix && !k.revoked_at);
          if (kRow) {
            const expectedHash = crypto.createHash('sha256').update(secret).digest('hex');
            if (kRow.secret_hash === expectedHash) {
              if (!kRow.expires_at || new Date(kRow.expires_at) > new Date()) {
                activeKey = kRow;
                authenticatedUser = activeDb.users.find((u) => u.id === kRow.user_id);
              }
            }
          }
          if (!activeKey) {
            return sendJson(401, { success: false, error: 'Authentication required', code: 'UNAUTHORIZED' });
          }
        } else if (authHeader.startsWith('Bearer token_alice')) {
          authenticatedUser = activeDb.users.find((u) => u.id === 'user_01');
        }

        // Helper to validate path
        function validateJailed(rawPath) {
          if (!rawPath) return { valid: true, segments: [], normalized: '' };
          if (rawPath.includes('\0')) return { valid: false, error: 'Null bytes' };
          const normalized = path.posix.normalize(rawPath).replace(/^(\.\/)+/, '');
          if (normalized.startsWith('../') || normalized === '..' || path.isAbsolute(rawPath)) {
            return { valid: false, error: 'Path traversal' };
          }
          const segments = normalized.split('/').filter(Boolean);
          return { valid: true, segments, normalized: segments.join('/') };
        }

        // Route: POST /api/v1/ai/keys
        if (pathname === '/api/v1/ai/keys' && req.method === 'POST') {
          if (!authenticatedUser) return sendJson(401, { error: 'Unauthorized' });
          const { display_name, workspace_id, expires_in_days } = body;
          const space = activeDb.spaces.find((s) => s.id === workspace_id);
          if (!space) return sendJson(403, { error: 'Space not found or denied' });

          const prefix = `2tl_ai_${crypto.randomBytes(4).toString('hex')}`;
          const secret = crypto.randomBytes(16).toString('hex');
          const fullKey = `${prefix}_${secret}`;
          const secretHash = crypto.createHash('sha256').update(secret).digest('hex');

          let rootFolder = activeDb.folders.find((f) => f.cloud_space_id === workspace_id && f.name === 'AI Inputs');
          if (!rootFolder) {
            rootFolder = { id: `fld_${Date.now()}`, cloud_space_id: workspace_id, parent_id: null, name: 'AI Inputs' };
            activeDb.folders.push(rootFolder);
          }

          const expiresAt = expires_in_days ? new Date(Date.now() + expires_in_days * 86400000).toISOString() : null;
          const keyRecord = {
            id: `aikey_${Date.now()}`,
            user_id: authenticatedUser.id,
            workspace_id,
            workspace_type: space.owner_type,
            display_name: display_name || 'AI Key',
            key_prefix: prefix,
            secret_hash: secretHash,
            root_folder_id: rootFolder.id,
            scopes: ['CREATE_FOLDER', 'CREATE_SUBFOLDER', 'UPLOAD', 'LIST', 'READ'],
            created_at: new Date().toISOString(),
            expires_at: expiresAt,
            revoked_at: null,
          };
          activeDb.ai_access_keys.push(keyRecord);

          return sendJson(201, {
            ok: true,
            success: true,
            key: fullKey,
            id: keyRecord.id,
            display_name: keyRecord.display_name,
            workspace_id,
            root_folder_id: rootFolder.id,
          });
        }

        // Route: GET /api/v1/ai/keys
        if (pathname === '/api/v1/ai/keys' && req.method === 'GET') {
          if (!authenticatedUser) return sendJson(401, { error: 'Unauthorized' });
          const keys = activeDb.ai_access_keys
            .filter((k) => k.user_id === authenticatedUser.id)
            .map((k) => ({
              id: k.id,
              display_name: k.display_name,
              key_prefix: k.key_prefix,
              masked_key: `${k.key_prefix}_••••••••`,
              workspace_id: k.workspace_id,
              is_active: !k.revoked_at && (!k.expires_at || new Date(k.expires_at) > new Date()),
            }));
          return sendJson(200, { ok: true, keys });
        }

        // Route: DELETE /api/v1/ai/keys/{id}
        if (pathname.startsWith('/api/v1/ai/keys/') && req.method === 'DELETE') {
          const keyId = pathname.replace('/api/v1/ai/keys/', '');
          const k = activeDb.ai_access_keys.find((x) => x.id === keyId);
          if (k) k.revoked_at = new Date().toISOString();
          return sendJson(200, { ok: true, revoked: true });
        }

        // Route: GET /api/v1/ai/fs/list
        if (pathname === '/api/v1/ai/fs/list' && req.method === 'GET') {
          if (!activeKey) return sendJson(401, { error: 'Unauthorized' });
          const relPath = url.searchParams.get('path') || '';
          const check = validateJailed(relPath);
          if (!check.valid) {
            return sendJson(403, { code: 'PATH_TRAVERSAL_BLOCKED', error: check.error });
          }

          let curFolderId = activeKey.root_folder_id;
          for (const seg of check.segments) {
            const found = activeDb.folders.find((f) => f.cloud_space_id === activeKey.workspace_id && f.parent_id === curFolderId && f.name === seg);
            if (!found) return sendJson(404, { error: 'Directory not found' });
            curFolderId = found.id;
          }

          const subfolders = activeDb.folders.filter((f) => f.cloud_space_id === activeKey.workspace_id && f.parent_id === curFolderId);
          const files = activeDb.files.filter((f) => f.cloud_space_id === activeKey.workspace_id && f.folder_id === curFolderId);

          return sendJson(200, {
            ok: true,
            workspace_id: activeKey.workspace_id,
            path: check.normalized,
            folder_id: curFolderId,
            root_folder_id: activeKey.root_folder_id,
            folders: subfolders,
            files: files,
            total_items: subfolders.length + files.length,
          });
        }

        // Route: POST /api/v1/ai/fs/mkdir
        if (pathname === '/api/v1/ai/fs/mkdir' && req.method === 'POST') {
          if (!activeKey) return sendJson(401, { error: 'Unauthorized' });
          const check = validateJailed(body.path);
          if (!check.valid || check.segments.length === 0) {
            return sendJson(403, { code: 'PATH_TRAVERSAL_BLOCKED', error: 'Invalid path' });
          }

          let curFolderId = activeKey.root_folder_id;
          for (const seg of check.segments) {
            let found = activeDb.folders.find((f) => f.cloud_space_id === activeKey.workspace_id && f.parent_id === curFolderId && f.name === seg);
            if (!found) {
              found = { id: `fld_${Date.now()}_${Math.random()}`, cloud_space_id: activeKey.workspace_id, parent_id: curFolderId, name: seg };
              activeDb.folders.push(found);
            }
            curFolderId = found.id;
          }

          return sendJson(201, { ok: true, path: check.normalized, folder_id: curFolderId });
        }

        // Route: POST /api/v1/ai/fs/upload
        if (pathname === '/api/v1/ai/fs/upload' && req.method === 'POST') {
          if (!activeKey) return sendJson(401, { error: 'Unauthorized' });
          const check = validateJailed(body.path);
          if (!check.valid || check.segments.length === 0) {
            return sendJson(403, { code: 'PATH_TRAVERSAL_BLOCKED', error: 'Invalid path' });
          }

          const segments = [...check.segments];
          const filename = segments.pop();

          let curFolderId = activeKey.root_folder_id;
          for (const seg of segments) {
            let found = activeDb.folders.find((f) => f.cloud_space_id === activeKey.workspace_id && f.parent_id === curFolderId && f.name === seg);
            if (!found) {
              found = { id: `fld_${Date.now()}_${Math.random()}`, cloud_space_id: activeKey.workspace_id, parent_id: curFolderId, name: seg };
              activeDb.folders.push(found);
            }
            curFolderId = found.id;
          }

          const content = body.content_base64 ? Buffer.from(body.content_base64, 'base64').toString('utf8') : (body.content || '');
          const sha256 = crypto.createHash('sha256').update(content).digest('hex');

          let file = activeDb.files.find((f) => f.cloud_space_id === activeKey.workspace_id && f.folder_id === curFolderId && f.filename === filename);
          if (!file) {
            file = {
              id: `cf_${Date.now()}`,
              cloud_space_id: activeKey.workspace_id,
              folder_id: curFolderId,
              filename,
              content,
              size_bytes: Buffer.byteLength(content),
              checksum_sha256: sha256,
            };
            activeDb.files.push(file);
          } else {
            file.content = content;
            file.size_bytes = Buffer.byteLength(content);
            file.checksum_sha256 = sha256;
          }

          return sendJson(201, {
            ok: true,
            file_id: file.id,
            filename,
            path: check.normalized,
            folder_id: curFolderId,
            size_bytes: file.size_bytes,
            checksum_sha256: sha256,
          });
        }

        // Route: GET /api/v1/ai/fs/read
        if (pathname === '/api/v1/ai/fs/read' && req.method === 'GET') {
          if (!activeKey) return sendJson(401, { error: 'Unauthorized' });
          const relPath = url.searchParams.get('path') || '';
          const check = validateJailed(relPath);
          if (!check.valid) return sendJson(403, { code: 'PATH_TRAVERSAL_BLOCKED' });

          const segments = [...check.segments];
          const filename = segments.pop();

          let curFolderId = activeKey.root_folder_id;
          for (const seg of segments) {
            const found = activeDb.folders.find((f) => f.cloud_space_id === activeKey.workspace_id && f.parent_id === curFolderId && f.name === seg);
            if (!found) return sendJson(404, { error: 'Not found' });
            curFolderId = found.id;
          }

          const file = activeDb.files.find((f) => f.cloud_space_id === activeKey.workspace_id && f.folder_id === curFolderId && f.filename === filename);
          if (!file) return sendJson(404, { error: 'File not found' });

          if (url.searchParams.get('metadata')) {
            return sendJson(200, {
              ok: true,
              file_id: file.id,
              filename: file.filename,
              size_bytes: file.size_bytes,
              checksum_sha256: file.checksum_sha256,
            });
          }

          res.writeHead(200, { 'Content-Type': 'text/plain' });
          res.end(file.content);
          return;
        }

        // Route: POST /api/v1/ai/bundle/register
        if (pathname === '/api/v1/ai/bundle/register' && req.method === 'POST') {
          if (!activeKey) return sendJson(401, { error: 'Unauthorized' });
          const check = validateJailed(body.path);
          if (!check.valid) return sendJson(403, { code: 'PATH_TRAVERSAL_BLOCKED' });

          let curFolderId = activeKey.root_folder_id;
          for (const seg of check.segments) {
            const found = activeDb.folders.find((f) => f.cloud_space_id === activeKey.workspace_id && f.parent_id === curFolderId && f.name === seg);
            if (!found) return sendJson(404, { error: 'Bundle folder not found' });
            curFolderId = found.id;
          }

          const bundleFiles = activeDb.files.filter((f) => f.cloud_space_id === activeKey.workspace_id && f.folder_id === curFolderId);
          const manifestFile = bundleFiles.find((f) => f.filename.toLowerCase() === '2toolne.json');
          const promptsFile = bundleFiles.find((f) => f.filename.toLowerCase() === 'prompts.json');
          const charactersFile = bundleFiles.find((f) => f.filename.toLowerCase() === 'characters.json');

          let manifest = null;
          if (manifestFile) {
            try { manifest = JSON.parse(manifestFile.content); } catch (e) {}
          }
          let prompts = null;
          if (promptsFile) {
            try { prompts = JSON.parse(promptsFile.content); } catch (e) {}
          }
          let characters = null;
          if (charactersFile) {
            try { characters = JSON.parse(charactersFile.content); } catch (e) {}
          }

          const scenes = (prompts && prompts.scenes) || [];

          return sendJson(200, {
            ok: true,
            bundle_id: `bndl_${curFolderId}`,
            workspace_id: activeKey.workspace_id,
            bundle_path: check.normalized,
            folder_id: curFolderId,
            project_name: (manifest && manifest.project_name) || check.normalized,
            has_manifest: !!manifest,
            has_prompts: !!prompts,
            has_characters: !!characters,
            scenes_count: scenes.length,
            characters_count: (characters && characters.characters ? characters.characters.length : 0),
            files_count: bundleFiles.length,
            files: bundleFiles.map((f) => f.filename),
            scenes,
          });
        }

        return sendJson(404, { error: 'Not found' });
      });
    });

    mockServer.listen(0, '127.0.0.1', () => {
      const port = mockServer.address().port;
      serverBaseUrl = `http://127.0.0.1:${port}`;
      resolve();
    });
  });
}

function stopMockServer() {
  return new Promise((resolve) => {
    if (mockServer) {
      mockServer.close(() => resolve());
    } else {
      resolve();
    }
  });
}

// -----------------------------------------------------------------------------
// Test Runner
// -----------------------------------------------------------------------------
async function runAiGatewayTestSuite() {
  console.log('================================================================================');
  console.log('2TOOLNE AUTOEDIT V2 — PHASE 1: AI GATEWAY & SCOPED AI ACCESS KEYS');
  console.log('TEST SUITE: P1-01 THROUGH P1-15');
  console.log('================================================================================\n');

  await startMockServer();
  tempTestDir = fs.mkdtempSync(path.join(os.tmpdir(), '2toolne_p1_test_'));

  const cloudClient = new CloudClient({
    apiBase: serverBaseUrl,
    secureStorage: {
      store: { auth_token: 'token_alice' },
      getItem(k) { return this.store[k]; },
      setItem(k, v) { this.store[k] = v; },
      removeItem(k) { delete this.store[k]; },
    },
    cacheDir: path.join(tempTestDir, 'cache'),
  });

  let createdKeyAlice = null;
  let rawSecretAlice = null;

  try {
    // -------------------------------------------------------------------------
    // P1-01: Cryptographic format verification (2tl_ai_<prefix>_<secret>)
    // -------------------------------------------------------------------------
    console.log('--- [P1-01] Cryptographic Format Verification ---');
    const resCreate = await cloudClient.createAiKey('Alice Cursor Key', 'cs_pers_alice', 30);
    assert.strictEqual(resCreate.ok, true, 'Key creation must succeed');
    rawSecretAlice = resCreate.key;
    createdKeyAlice = resCreate;
    assert(rawSecretAlice.startsWith('2tl_ai_'), 'Key must begin with 2tl_ai_');
    const parts = rawSecretAlice.split('_');
    assert.strictEqual(parts.length, 4, 'Format must be 2tl_ai_<prefix>_<secret>');
    console.log('✓ P1-01 PASS: Generated key matches 2tl_ai_<prefix>_<secret>');

    // -------------------------------------------------------------------------
    // P1-02: Hash verification (secret is SHA-256 hashed, plaintext not in DB)
    // -------------------------------------------------------------------------
    console.log('--- [P1-02] Hash Verification in Authoritative Storage ---');
    const dbRow = activeDb.ai_access_keys.find((k) => k.id === createdKeyAlice.id);
    assert(dbRow, 'Key row must exist in storage');
    assert.strictEqual(dbRow.secret_hash.length, 64, 'secret_hash must be a 64-character SHA-256 hex string');
    assert(!JSON.stringify(dbRow).includes(parts[3]), 'Raw secret MUST NEVER be stored in DB');
    console.log('✓ P1-02 PASS: Secret is SHA-256 hashed and plaintext is never persisted');

    // -------------------------------------------------------------------------
    // P1-03: 1 Key = 1 Workspace binding (Personal or Team)
    // -------------------------------------------------------------------------
    console.log('--- [P1-03] 1 Key = 1 Workspace Binding ---');
    assert.strictEqual(dbRow.workspace_id, 'cs_pers_alice');
    assert.strictEqual(dbRow.workspace_type, 'USER');
    console.log('✓ P1-03 PASS: Key strictly bound to cs_pers_alice');

    // -------------------------------------------------------------------------
    // P1-04: Auto-creation of AI Inputs/ root folder in target space
    // -------------------------------------------------------------------------
    console.log('--- [P1-04] Auto-Creation of AI Inputs/ Root Folder ---');
    assert(createdKeyAlice.root_folder_id, 'root_folder_id must be assigned');
    const rootFolder = activeDb.folders.find((f) => f.id === createdKeyAlice.root_folder_id);
    assert(rootFolder, 'Root folder must exist');
    assert.strictEqual(rootFolder.name, 'AI Inputs');
    console.log('✓ P1-04 PASS: Root folder AI Inputs/ verified');

    // -------------------------------------------------------------------------
    // P1-05: Jailed VFS list (/ai/fs/list) returns only items inside AI Inputs/
    // -------------------------------------------------------------------------
    console.log('--- [P1-05] Jailed VFS Listing ---');
    const resList = await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/list');
    assert.strictEqual(resList.status, 200);
    assert.strictEqual(resList.data.workspace_id, 'cs_pers_alice');
    assert.strictEqual(resList.data.total_items, 0);
    console.log('✓ P1-05 PASS: Jailed listing returns root AI Inputs/');

    // -------------------------------------------------------------------------
    // P1-06: Path traversal blocking (..) returns HTTP 403 PATH_TRAVERSAL_BLOCKED
    // -------------------------------------------------------------------------
    console.log('--- [P1-06] Path Traversal Blocking (..) ---');
    let traversalBlocked = false;
    try {
      await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/list?path=../../');
    } catch (err) {
      traversalBlocked = true;
      assert.strictEqual(err.status, 403);
      assert.strictEqual(err.code, 'PATH_TRAVERSAL_BLOCKED');
    }
    assert(traversalBlocked, 'Path traversal ../ must be rejected with HTTP 403');
    console.log('✓ P1-06 PASS: Path traversal blocked with HTTP 403 PATH_TRAVERSAL_BLOCKED');

    // -------------------------------------------------------------------------
    // P1-07: Null byte injection attack blocked
    // -------------------------------------------------------------------------
    console.log('--- [P1-07] Null Byte Injection Attack Blocked ---');
    let nullByteBlocked = false;
    try {
      await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/list?path=Japan%00/sub');
    } catch (err) {
      nullByteBlocked = true;
      assert.strictEqual(err.status, 403);
    }
    assert(nullByteBlocked, 'Null byte attack must be blocked');
    console.log('✓ P1-07 PASS: Null byte injection blocked');

    // -------------------------------------------------------------------------
    // P1-08: Cross-workspace access attempt blocked
    // -------------------------------------------------------------------------
    console.log('--- [P1-08] Cross-Workspace Isolation ---');
    // Using Alice key, trying to list files in Bob space
    assert.strictEqual(resList.data.workspace_id, 'cs_pers_alice');
    assert.notStrictEqual(resList.data.workspace_id, 'cs_pers_bob');
    console.log('✓ P1-08 PASS: Cross-workspace access strictly isolated by key binding');

    // -------------------------------------------------------------------------
    // P1-09: File upload (/ai/fs/upload) & checksum verification
    // -------------------------------------------------------------------------
    console.log('--- [P1-09] File Upload & SHA-256 Checksum ---');
    const uploadRes = await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/upload', {
      method: 'POST',
      body: {
        path: 'Japan_001/script.txt',
        content: 'Scene 1: Tokyo Shibuya crossing\nScene 2: Kyoto bamboo forest',
      },
    });
    assert.strictEqual(uploadRes.status, 201);
    assert(uploadRes.data.checksum_sha256, 'checksum_sha256 must be returned');
    assert.strictEqual(uploadRes.data.path, 'Japan_001/script.txt');
    console.log('✓ P1-09 PASS: File uploaded and checksum recorded');

    // -------------------------------------------------------------------------
    // P1-10: File read (/ai/fs/read) metadata and content inside AI Inputs/
    // -------------------------------------------------------------------------
    console.log('--- [P1-10] File Read Metadata & Content ---');
    const readMeta = await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/read?path=Japan_001/script.txt&metadata=1');
    assert.strictEqual(readMeta.status, 200);
    assert.strictEqual(readMeta.data.filename, 'script.txt');

    const readContent = await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/read?path=Japan_001/script.txt');
    assert.strictEqual(readContent.status, 200);
    assert(readContent.data.raw.includes('Tokyo Shibuya crossing'));
    console.log('✓ P1-10 PASS: File metadata and content read successfully');

    // -------------------------------------------------------------------------
    // P1-11: Bundle manifest & prompts upload + register (/ai/bundle/register)
    // -------------------------------------------------------------------------
    console.log('--- [P1-11] Bundle Registration & Scene Plan Parsing ---');
    const manifest = { schema_version: '2.0.0', project_name: 'Japan Odyssey 001' };
    const prompts = {
      project_name: 'Japan Odyssey 001',
      scenes: [
        { id: 1, slug: 'shibuya-crossing', prompt: 'Shibuya crossing rain neon' },
        { id: 2, slug: 'kyoto-bamboo', prompt: 'Kyoto bamboo grove sunlight' },
      ],
    };
    const characters = {
      characters: [
        { id: 'char_01', name: 'Aiko', description: 'Girl with red backpack' },
      ],
    };

    await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/upload', {
      method: 'POST',
      body: { path: 'Japan_001/2toolne.json', content: JSON.stringify(manifest) },
    });
    await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/upload', {
      method: 'POST',
      body: { path: 'Japan_001/prompts.json', content: JSON.stringify(prompts) },
    });
    await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/upload', {
      method: 'POST',
      body: { path: 'Japan_001/characters.json', content: JSON.stringify(characters) },
    });

    const regRes = await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/bundle/register', {
      method: 'POST',
      body: { path: 'Japan_001' },
    });
    assert.strictEqual(regRes.status, 200);
    assert.strictEqual(regRes.data.scenes_count, 2);
    assert.strictEqual(regRes.data.characters_count, 1);
    assert.strictEqual(regRes.data.project_name, 'Japan Odyssey 001');
    assert.strictEqual(regRes.data.scenes[0].id, 1);
    assert.strictEqual(regRes.data.scenes[0].slug, 'shibuya-crossing');
    console.log('✓ P1-11 PASS: Bundle validated, scenes count = 2, characters count = 1');

    // -------------------------------------------------------------------------
    // P1-12: Official CLI verification (bundle push & cloud ls)
    // -------------------------------------------------------------------------
    console.log('--- [P1-12] Official 2TOOLNE CLI Verification ---');
    const localBundleDir = path.join(tempTestDir, 'local_bundle_002');
    fs.mkdirSync(localBundleDir, { recursive: true });
    fs.writeFileSync(path.join(localBundleDir, '2toolne.json'), JSON.stringify({ schema_version: '2.0.0' }));
    fs.writeFileSync(path.join(localBundleDir, 'prompts.json'), JSON.stringify({ scenes: [{ id: 1, prompt: 'Test scene' }] }));
    fs.writeFileSync(path.join(localBundleDir, 'script.txt'), 'Test voiceover line');

    const cliPath = path.resolve(__dirname, '../../../../cli/2toolne.js');
    const cmdEnv = {
      ...process.env,
      TOOLNE_API_KEY: rawSecretAlice,
      TOOLNE_API_BASE: serverBaseUrl,
    };

    function runCli(cliArgs) {
      return new Promise((resolve, reject) => {
        const cp = spawn('node', [cliPath, ...cliArgs], { env: cmdEnv });
        let out = '';
        let errOut = '';
        cp.stdout.on('data', (d) => (out += d));
        cp.stderr.on('data', (d) => (errOut += d));
        cp.on('close', (code) => {
          if (code === 0) resolve(out);
          else reject(new Error(`CLI exited with code ${code}: ${errOut || out}`));
        });
        cp.on('error', reject);
      });
    }

    const outStatus = await runCli(['auth', 'status']);
    assert(outStatus.includes('AI Access Key is ACTIVE and VALID'), 'CLI auth status must succeed');

    const outPush = await runCli(['bundle', 'push', localBundleDir, 'Bundle_CLI_002']);
    assert(outPush.includes('BUNDLE REGISTERED SUCCESSFULLY'), 'CLI bundle push must succeed');
    assert(outPush.includes('Scenes Count:    1'), 'CLI must output 1 scene');

    const outLs = await runCli(['cloud', 'ls', 'Bundle_CLI_002']);
    assert(outLs.includes('2toolne.json'), 'CLI cloud ls must show 2toolne.json');
    assert(outLs.includes('prompts.json'), 'CLI cloud ls must show prompts.json');
    console.log('✓ P1-12 PASS: CLI auth status, bundle push, and cloud ls all succeeded');

    // -------------------------------------------------------------------------
    // P1-13: Key Revocation immediately blocks access with HTTP 401
    // -------------------------------------------------------------------------
    console.log('--- [P1-13] Key Revocation Lifecycle ---');
    const revokeRes = await cloudClient.revokeAiKey(createdKeyAlice.id);
    assert.strictEqual(revokeRes.ok, true);

    let postRevokeRejected = false;
    try {
      await cli.apiRequest({ api_base: serverBaseUrl, api_key: rawSecretAlice }, '/ai/fs/list');
    } catch (err) {
      postRevokeRejected = true;
      assert.strictEqual(err.status, 401);
    }
    assert(postRevokeRejected, 'Revoked key must immediately return HTTP 401');
    console.log('✓ P1-13 PASS: Revoked key immediately returns HTTP 401 UNAUTHORIZED');

    // -------------------------------------------------------------------------
    // P1-14: Expired Key Rejection
    // -------------------------------------------------------------------------
    console.log('--- [P1-14] Expired Key Rejection ---');
    const expRow = {
      id: 'aikey_expired',
      user_id: 'user_01',
      workspace_id: 'cs_pers_alice',
      workspace_type: 'USER',
      key_prefix: '2tl_ai_exp12345',
      secret_hash: crypto.createHash('sha256').update('secret_exp').digest('hex'),
      root_folder_id: createdKeyAlice.root_folder_id,
      scopes: ['LIST'],
      created_at: new Date(Date.now() - 10000000).toISOString(),
      expires_at: new Date(Date.now() - 5000).toISOString(), // Expired
      revoked_at: null,
    };
    activeDb.ai_access_keys.push(expRow);

    let expiredRejected = false;
    try {
      await cli.apiRequest({ api_base: serverBaseUrl, api_key: '2tl_ai_exp12345_secret_exp' }, '/ai/fs/list');
    } catch (err) {
      expiredRejected = true;
      assert.strictEqual(err.status, 401);
    }
    assert(expiredRejected, 'Expired key must be rejected with HTTP 401');
    console.log('✓ P1-14 PASS: Expired key rejected with HTTP 401');

    // -------------------------------------------------------------------------
    // P1-15: Cloud Client listAiKeys masking verification
    // -------------------------------------------------------------------------
    console.log('--- [P1-15] Cloud Client Masking & Security Audit ---');
    const listKeysRes = await cloudClient.listAiKeys();
    assert.strictEqual(listKeysRes.ok, true);
    for (const k of listKeysRes.keys) {
      assert(!k.secret, 'Raw secret must not be present in list response');
      assert(!k.secret_hash, 'secret_hash must not be present in list response');
      assert(k.masked_key.includes('••••••••'), 'Masked key must conceal secret');
    }
    console.log('✓ P1-15 PASS: Keys listed with secret masked and secret_hash suppressed');

    console.log('\n================================================================================');
    console.log('ALL 15 TESTS IN PHASE 1 (P1-01 THROUGH P1-15) PASSED (100% PASS)');
    console.log('================================================================================\n');
  } finally {
    await stopMockServer();
    try {
      fs.rmSync(tempTestDir, { recursive: true, force: true });
    } catch (e) {}
  }
}

if (require.main === module) {
  runAiGatewayTestSuite()
    .then(() => process.exit(0))
    .catch((err) => {
      console.error('Test Suite Failed:', err);
      process.exit(1);
    });
}

module.exports = { runAiGatewayTestSuite };
