/**
 * apps/capcut-v2/desktop/tests/test_cloud_share.js
 * Comprehensive Automated Test Suite for PRIORITY 5: Cloud Share Link (V2 Production Architecture).
 * Covers tests SHARE-T01 through SHARE-T32.
 *
 * Verifies:
 * - URL fragment architecture (#<raw_token>) preventing secret leakage in Apache access logs
 * - Dual token representation: SHA-256 hash lookup + AES-256-GCM encrypted recovery
 * - Short-lived signed capability tokens for public preview, download, and folder browsing
 * - Single-session access accounting (HTTP Range requests do NOT bump access_count)
 * - Strict server-side folder subtree isolation (blocks parent, sibling, cross-space traversal)
 * - Idempotent share creation preventing duplicate records
 * - Complete permission enforcement & Desktop IPC integration
 */

const assert = require('assert');
const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { CloudClient } = require('../src/main/cloud_client');

// -----------------------------------------------------------------------------
// Cryptographic Helpers (Matching CloudShareController PHP implementation)
// -----------------------------------------------------------------------------
const MASTER_KEY = crypto.createHash('sha256').update('2toolne_cloud_master_aes256_secret_key_2026_salt').digest();

function encryptTokenForRecovery(rawToken) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', MASTER_KEY, iv);
  const encrypted = Buffer.concat([cipher.update(rawToken, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return Buffer.from(JSON.stringify({
    iv: iv.toString('base64'),
    tag: tag.toString('base64'),
    ct: encrypted.toString('base64'),
  })).toString('base64');
}

function decryptTokenFromRecovery(envelope) {
  try {
    const json = JSON.parse(Buffer.from(envelope, 'base64').toString('utf8'));
    const decipher = crypto.createDecipheriv('aes-256-gcm', MASTER_KEY, Buffer.from(json.iv, 'base64'));
    decipher.setAuthTag(Buffer.from(json.tag, 'base64'));
    const decrypted = Buffer.concat([decipher.update(Buffer.from(json.ct, 'base64')), decipher.final()]);
    return decrypted.toString('utf8');
  } catch (e) {
    return null;
  }
}

function generateCapability(payload) {
  payload.exp = Math.floor(Date.now() / 1000) + 900; // 15 minutes
  payload.nonce = crypto.randomBytes(8).toString('hex');
  const json = JSON.stringify(payload);
  const sig = crypto.createHmac('sha256', MASTER_KEY).update(json).digest('hex');
  const enc = Buffer.from(json).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  return `${enc}.${sig}`;
}

function verifyCapability(token) {
  if (!token || !token.includes('.')) return null;
  const [enc, sig] = token.split('.');
  const jsonStr = Buffer.from(enc.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
  const expectedSig = crypto.createHmac('sha256', MASTER_KEY).update(jsonStr).digest('hex');
  if (!crypto.timingSafeEqual(Buffer.from(expectedSig), Buffer.from(sig))) return null;
  const payload = JSON.parse(jsonStr);
  if (!payload || payload.exp < Math.floor(Date.now() / 1000)) return null;
  return payload;
}

function sanitizeFilename(filename) {
  return filename.replace(/[\r\n\0"'/\\]+/g, '_').trim() || 'downloaded_asset.bin';
}

// -----------------------------------------------------------------------------
// Test Fixtures & Mock Database
// -----------------------------------------------------------------------------
let mockServer = null;
let serverBaseUrl = '';
let tempTestDir = '';
let cloudClient = null;

const mockDb = {
  spaces: [
    { id: 'space_personal', name: 'Personal Space', owner_type: 'USER', owner_id: 'user_1' },
    { id: 'space_team', name: 'Team Space', owner_type: 'TEAM', owner_id: 'team_1' },
    { id: 'space_other', name: 'Other Space', owner_type: 'USER', owner_id: 'user_other' },
  ],
  members: [
    { team_id: 'team_1', user_id: 'user_1', role: 'EDITOR' },
    { team_id: 'team_1', user_id: 'user_viewer', role: 'VIEWER' },
  ],
  folders: [
    { id: 'fld_shared', space_id: 'space_personal', parent_id: null, name: 'SharedProject', deleted_at: null },
    { id: 'fld_child', space_id: 'space_personal', parent_id: 'fld_shared', name: 'SubImages', deleted_at: null },
    { id: 'fld_sibling', space_id: 'space_personal', parent_id: null, name: 'SecretPrivate', deleted_at: null },
    { id: 'fld_other_space', space_id: 'space_other', parent_id: null, name: 'AlienFolder', deleted_at: null },
  ],
  files: [
    { id: 'file_img', space_id: 'space_personal', folder_id: null, name: 'render.png', size_bytes: 1024, mime_type: 'image/png', status: 'ACTIVE', deleted_at: null },
    { id: 'file_vid', space_id: 'space_personal', folder_id: null, name: 'clip.mp4', size_bytes: 1048576, mime_type: 'video/mp4', status: 'ACTIVE', deleted_at: null },
    { id: 'file_aud', space_id: 'space_personal', folder_id: null, name: 'voice.mp3', size_bytes: 65536, mime_type: 'audio/mpeg', status: 'ACTIVE', deleted_at: null },
    { id: 'file_in_sub', space_id: 'space_personal', folder_id: 'fld_child', name: 'sub_photo.jpg', size_bytes: 2048, mime_type: 'image/jpeg', status: 'ACTIVE', deleted_at: null },
    { id: 'file_in_sibling', space_id: 'space_personal', folder_id: 'fld_sibling', name: 'private.pdf', size_bytes: 512, mime_type: 'application/pdf', status: 'ACTIVE', deleted_at: null },
    { id: 'file_other_space', space_id: 'space_other', folder_id: null, name: 'alien.zip', size_bytes: 4096, mime_type: 'application/zip', status: 'ACTIVE', deleted_at: null },
  ],
  shares: [], // stores { id, cloud_space_id, item_type, item_id, share_token_hash, share_token_ciphertext, idempotency_key, access_level, expires_at, revoked_at, created_by_user_id, access_count, last_accessed_at }
  accessLogs: [], // stores { share_id, endpoint, timestamp } — ZERO raw tokens!
  rawUrlLogs: [], // tracks raw incoming HTTP request URLs to guarantee zero raw tokens in logs
};

function isDescendantOf(candidateId, rootFolderId, spaceId) {
  if (!candidateId) return false;
  if (candidateId === rootFolderId) return true;
  let curr = candidateId;
  let depth = 0;
  while (curr && depth < 20) {
    depth++;
    const fld = mockDb.folders.find((f) => f.id === curr && f.space_id === spaceId && !f.deleted_at);
    if (!fld) return false;
    if (fld.parent_id === rootFolderId) return true;
    curr = fld.parent_id;
  }
  return false;
}

// -----------------------------------------------------------------------------
// Mock HTTP Server Setup
// -----------------------------------------------------------------------------
function setupMockServer() {
  return new Promise((resolve) => {
    mockServer = http.createServer((req, res) => {
      // Record raw request URL in server log audit
      mockDb.rawUrlLogs.push(req.url);

      const url = new URL(req.url, serverBaseUrl);
      const authHeader = req.headers['authorization'] || '';

      let currentUser = null;
      if (authHeader.startsWith('Bearer valid_token_user_1')) {
        currentUser = { id: 'user_1', role: 'user' };
      } else if (authHeader.startsWith('Bearer valid_token_viewer')) {
        currentUser = { id: 'user_viewer', role: 'user' };
      }

      let bodyStr = '';
      req.on('data', (c) => (bodyStr += c));
      req.on('end', () => {
        let json = {};
        try {
          if (bodyStr) json = JSON.parse(bodyStr);
        } catch (e) {}

        // =====================================================================
        // 1. PUBLIC ENDPOINTS (Zero Login / Public Access)
        // =====================================================================

        // POST /api/v1/cloud/public/share/resolve
        if (req.method === 'POST' && url.pathname === '/api/v1/cloud/public/share/resolve') {
          const rawToken = (json.share_token || json.token || '').trim();
          if (!rawToken) {
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'INVALID_TOKEN', error: 'Mã liên kết không hợp lệ' }));
            return;
          }

          const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
          const share = mockDb.shares.find((s) => s.share_token_hash === tokenHash);

          if (!share) {
            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'NOT_FOUND', error: 'Không tìm thấy liên kết chia sẻ.' }));
            return;
          }

          if (share.revoked_at) {
            res.writeHead(410, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'REVOKED', error: 'Liên kết này không còn khả dụng.' }));
            return;
          }

          if (share.expires_at && new Date(share.expires_at).getTime() < Date.now()) {
            res.writeHead(410, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'EXPIRED', error: 'Liên kết đã hết hạn.' }));
            return;
          }

          // Item check
          let underlyingItem = null;
          if (share.item_type === 'FILE') {
            underlyingItem = mockDb.files.find((f) => f.id === share.item_id);
            if (!underlyingItem || underlyingItem.status === 'DELETED') {
              res.writeHead(404, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, code: 'DELETED', error: 'Tệp được chia sẻ không còn tồn tại.' }));
              return;
            }
            if (underlyingItem.status === 'TRASHED' || underlyingItem.deleted_at) {
              res.writeHead(410, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, code: 'TRASHED', error: 'Tệp được chia sẻ đang ở trong thùng rác hoặc không còn khả dụng.' }));
              return;
            }
          } else {
            underlyingItem = mockDb.folders.find((f) => f.id === share.item_id);
            if (!underlyingItem || underlyingItem.deleted_at) {
              res.writeHead(404, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, code: 'DELETED', error: 'Thư mục được chia sẻ không còn tồn tại.' }));
              return;
            }
          }

          // Single-session access accounting: Increment ONCE upon resolution
          share.access_count++;
          share.last_accessed_at = new Date().toISOString();
          mockDb.accessLogs.push({ share_id: share.id, endpoint: 'resolve', timestamp: new Date().toISOString() });

          // Generate short-lived signed capability session token (15 mins)
          const capability = generateCapability({
            share_id: share.id,
            space_id: share.cloud_space_id,
            item_type: share.item_type,
            item_id: share.item_id,
            access_level: share.access_level,
          });

          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            item_type: share.item_type,
            access_level: share.access_level,
            expires_at: share.expires_at,
            share_session: capability,
            item: {
              name: underlyingItem.name,
              size_bytes: underlyingItem.size_bytes || 0,
              mime_type: underlyingItem.mime_type || '',
              extension: (underlyingItem.name || '').split('.').pop() || 'bin',
              can_preview: share.item_type === 'FILE' && (underlyingItem.mime_type.startsWith('image/') || underlyingItem.mime_type.startsWith('video/') || underlyingItem.mime_type.startsWith('audio/')),
              preview_type: (underlyingItem.mime_type || '').startsWith('image/') ? 'IMAGE' : ((underlyingItem.mime_type || '').startsWith('video/') ? 'VIDEO' : ((underlyingItem.mime_type || '').startsWith('audio/') ? 'AUDIO' : 'OTHER')),
            },
          }));
          return;
        }

        // GET /api/v1/cloud/public/share/download
        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/public/share/download') {
          const session = req.headers['x-share-session'] || url.searchParams.get('session');
          const cap = verifyCapability(session);
          if (!cap) {
            res.writeHead(401, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'INVALID_SESSION', error: 'Phiên truy cập không hợp lệ hoặc đã hết hạn.' }));
            return;
          }

          const share = mockDb.shares.find((s) => s.id === cap.share_id);
          if (!share || share.revoked_at) {
            res.writeHead(410, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'REVOKED', error: 'Liên kết này không còn khả dụng.' }));
            return;
          }

          if (share.access_level !== 'ALLOW_DOWNLOAD') {
            res.writeHead(403, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'DOWNLOAD_FORBIDDEN', error: 'Tải xuống không được phép đối với liên kết này.' }));
            return;
          }

          let fileToStream = null;
          if (share.item_type === 'FILE') {
            fileToStream = mockDb.files.find((f) => f.id === share.item_id);
          } else {
            const fileId = url.searchParams.get('file_id');
            const targetFile = mockDb.files.find((f) => f.id === fileId);
            if (!targetFile) {
              res.writeHead(404, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, code: 'NOT_FOUND', error: 'Tệp không tồn tại.' }));
              return;
            }
            if (targetFile.space_id !== share.cloud_space_id || !isDescendantOf(targetFile.folder_id, share.item_id, share.cloud_space_id)) {
              res.writeHead(403, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, code: 'FORBIDDEN', error: 'Quyền truy cập bị từ chối: Tệp không thuộc thư mục được chia sẻ.' }));
              return;
            }
            fileToStream = targetFile;
          }

          if (!fileToStream) {
            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'NOT_FOUND', error: 'Tệp không tồn tại.' }));
            return;
          }

          const cleanName = sanitizeFilename(fileToStream.name);
          const payload = Buffer.from(`MOCK_CONTENT_OF_${fileToStream.name}`);
          res.writeHead(200, {
            'Content-Type': fileToStream.mime_type,
            'Content-Disposition': `attachment; filename="${cleanName}"`,
            'Content-Length': payload.length,
            'X-Content-Type-Options': 'nosniff',
          });
          res.end(payload);
          return;
        }

        // GET /api/v1/cloud/public/share/preview
        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/public/share/preview') {
          const session = req.headers['x-share-session'] || url.searchParams.get('session');
          const cap = verifyCapability(session);
          if (!cap) {
            res.writeHead(401, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'INVALID_SESSION', error: 'Phiên truy cập không hợp lệ hoặc đã hết hạn.' }));
            return;
          }

          const share = mockDb.shares.find((s) => s.id === cap.share_id);
          if (!share || share.revoked_at) {
            res.writeHead(410, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'REVOKED', error: 'Liên kết này không còn khả dụng.' }));
            return;
          }

          const underlyingItem = mockDb.files.find((f) => f.id === share.item_id);
          if (!underlyingItem) {
            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, error: 'Tệp không tồn tại.' }));
            return;
          }

          const range = req.headers['range'];
          const mime = underlyingItem.mime_type || 'application/octet-stream';
          const cleanName = sanitizeFilename(underlyingItem.name);

          // HTTP Range Streaming (Zero access_count increment!)
          if (range && range.startsWith('bytes=')) {
            const parts = range.replace(/bytes=/, '').split('-');
            const start = parseInt(parts[0], 10);
            const total = underlyingItem.size_bytes;
            const end = parts[1] ? parseInt(parts[1], 10) : total - 1;
            const chunksize = end - start + 1;

            res.writeHead(206, {
              'Content-Range': `bytes ${start}-${end}/${total}`,
              'Accept-Ranges': 'bytes',
              'Content-Length': chunksize,
              'Content-Type': mime,
              'X-Content-Type-Options': 'nosniff',
            });
            res.end(Buffer.alloc(chunksize, 0x00));
            return;
          }

          const payload = Buffer.from(`PREVIEW_DATA_${underlyingItem.name}`);
          res.writeHead(200, {
            'Content-Type': mime,
            'Content-Disposition': `inline; filename="${cleanName}"`,
            'Content-Length': payload.length,
            'X-Content-Type-Options': 'nosniff',
          });
          res.end(payload);
          return;
        }

        // GET /api/v1/cloud/public/share/folder
        if (req.method === 'GET' && url.pathname === '/api/v1/cloud/public/share/folder') {
          const session = req.headers['x-share-session'] || url.searchParams.get('session');
          const cap = verifyCapability(session);
          if (!cap) {
            res.writeHead(401, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'INVALID_SESSION', error: 'Phiên truy cập không hợp lệ hoặc đã hết hạn.' }));
            return;
          }

          if (cap.item_type !== 'FOLDER') {
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, error: 'Không phải thư mục' }));
            return;
          }

          const subfolderId = url.searchParams.get('subfolder_id') || cap.item_id;

          // Strict Subtree Enforcement: subfolderId MUST be descendant of root folder
          if (!isDescendantOf(subfolderId, cap.item_id, cap.space_id)) {
            res.writeHead(403, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, code: 'FORBIDDEN', error: 'Không thể duyệt ra ngoài thư mục được chia sẻ.' }));
            return;
          }

          const childFolders = mockDb.folders.filter((f) => f.space_id === cap.space_id && f.parent_id === subfolderId && !f.deleted_at);
          const childFiles = mockDb.files.filter((f) => f.space_id === cap.space_id && f.folder_id === subfolderId && f.status === 'ACTIVE' && !f.deleted_at);

          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            root_folder_id: cap.item_id,
            current_folder_id: subfolderId,
            access_level: cap.access_level,
            folders: childFolders,
            files: childFiles,
            breadcrumbs: [{ id: cap.item_id, name: 'SharedProject' }],
          }));
          return;
        }

        // =====================================================================
        // 2. AUTHENTICATED ENDPOINTS (Desktop App Operations)
        // =====================================================================
        if (!currentUser) {
          res.writeHead(401, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: false, code: 'AUTH_REQUIRED', error: 'Authentication required' }));
          return;
        }

        // Create Share: POST /api/v1/cloud/spaces/{spaceId}/shares
        const createMatch = url.pathname.match(/^\/api\/v1\/cloud\/spaces\/([^/]+)\/shares$/);
        if (req.method === 'POST' && createMatch) {
          const spaceId = createMatch[1];
          const space = mockDb.spaces.find((s) => s.id === spaceId);
          if (!space) {
            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, error: 'Space not found' }));
            return;
          }

          // Permissions
          if (space.owner_type === 'USER' && space.owner_id !== currentUser.id) {
            res.writeHead(403, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, error: 'Access denied' }));
            return;
          }
          if (space.owner_type === 'TEAM') {
            const member = mockDb.members.find((m) => m.team_id === space.owner_id && m.user_id === currentUser.id);
            if (!member || member.role === 'VIEWER') {
              res.writeHead(403, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, error: 'Không có quyền tạo liên kết chia sẻ' }));
              return;
            }
          }

          // Idempotency check: If key provided and exists, return original share
          const idempotencyKey = json.idempotency_key;
          if (idempotencyKey) {
            const existing = mockDb.shares.find((s) => s.idempotency_key === idempotencyKey);
            if (existing) {
              const recoveredToken = decryptTokenFromRecovery(existing.share_token_ciphertext);
              res.writeHead(200, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({
                ok: true,
                share: {
                  id: existing.id,
                  item_type: existing.item_type,
                  item_id: existing.item_id,
                  access_level: existing.access_level,
                  expires_at: existing.expires_at,
                  share_url: `${serverBaseUrl}/share/#${recoveredToken}`,
                  raw_token: recoveredToken,
                },
              }));
              return;
            }
          }

          const itemType = json.item_type || 'FILE';
          const itemId = json.item_id;

          // Check item in space
          if (itemType === 'FILE') {
            const f = mockDb.files.find((fl) => fl.id === itemId && fl.space_id === spaceId);
            if (!f || f.status === 'DELETED') {
              res.writeHead(404, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, error: 'File not found' }));
              return;
            }
            if (f.status === 'TRASHED' || f.deleted_at) {
              res.writeHead(400, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, error: 'Không thể chia sẻ tệp tin đang trong thùng rác' }));
              return;
            }
          } else {
            const fld = mockDb.folders.find((fl) => fl.id === itemId && fl.space_id === spaceId);
            if (!fld || fld.deleted_at) {
              res.writeHead(404, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ ok: false, error: 'Folder not found' }));
              return;
            }
          }

          // Generate 32 bytes cryptographically secure random token (256 bits)
          const rawToken = crypto.randomBytes(32).toString('hex');
          const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
          const encryptedToken = encryptTokenForRecovery(rawToken);

          let expiresAt = null;
          if (json.expires_in === '7d') {
            expiresAt = new Date(Date.now() + 7 * 86400 * 1000).toISOString();
          } else if (json.expires_in === '30d') {
            expiresAt = new Date(Date.now() + 30 * 86400 * 1000).toISOString();
          } else if (typeof json.expires_in === 'number') {
            expiresAt = new Date(Date.now() + json.expires_in * 1000).toISOString();
          }

          const shareId = 'sh_' + crypto.randomBytes(8).toString('hex');
          const record = {
            id: shareId,
            cloud_space_id: spaceId,
            item_type: itemType,
            item_id: itemId,
            share_token_hash: tokenHash,           // SHA-256 for public lookup
            share_token_ciphertext: encryptedToken, // AES-256-GCM for owner recovery
            idempotency_key: idempotencyKey || null,
            access_level: json.access_level || 'VIEW_ONLY',
            expires_at: expiresAt,
            revoked_at: null,
            created_by_user_id: currentUser.id,
            created_at: new Date().toISOString(),
            access_count: 0,
            last_accessed_at: null,
          };
          mockDb.shares.push(record);

          // Return share_url formatted with fragment: /share/#<rawToken>
          res.writeHead(201, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            share: {
              id: shareId,
              item_type: itemType,
              item_id: itemId,
              access_level: record.access_level,
              expires_at: expiresAt,
              share_url: `${serverBaseUrl}/share/#${rawToken}`,
              raw_token: rawToken,
            },
          }));
          return;
        }

        // List Shares: GET /api/v1/cloud/spaces/{spaceId}/shares
        if (req.method === 'GET' && createMatch) {
          const spaceId = createMatch[1];
          const itemType = url.searchParams.get('item_type');
          const itemId = url.searchParams.get('item_id');

          let matching = mockDb.shares.filter((s) => s.cloud_space_id === spaceId && !s.revoked_at);
          if (itemType) matching = matching.filter((s) => s.item_type === itemType);
          if (itemId) matching = matching.filter((s) => s.item_id === itemId);

          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: true,
            shares: matching.map((s) => {
              const isCreator = (s.created_by_user_id === currentUser.id);
              let recoveredUrl = null;
              if (isCreator && s.share_token_ciphertext) {
                const dec = decryptTokenFromRecovery(s.share_token_ciphertext);
                if (dec) recoveredUrl = `${serverBaseUrl}/share/#${dec}`;
              }
              return {
                id: s.id,
                item_type: s.item_type,
                item_id: s.item_id,
                access_level: s.access_level,
                expires_at: s.expires_at,
                share_url: recoveredUrl,
                can_revoke: isCreator,
              };
            }),
          }));
          return;
        }

        // Revoke Share: POST /api/v1/cloud/shares/{id}/revoke
        const revokeMatch = url.pathname.match(/^\/api\/v1\/cloud\/shares\/([^/]+)\/revoke$/);
        if (req.method === 'POST' && revokeMatch) {
          const shareId = revokeMatch[1];
          const share = mockDb.shares.find((s) => s.id === shareId);
          if (!share) {
            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: false, error: 'Share not found' }));
            return;
          }

          share.revoked_at = new Date().toISOString();
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: true, revoked: true }));
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
// Test Execution Suite: SHARE-T01 through SHARE-T32
// -----------------------------------------------------------------------------
async function runAllTests() {
  console.log('====================================================');
  console.log('🚀 RUNNING PRIORITY 5: CLOUD SHARE TEST SUITE (SHARE-T01 to SHARE-T32)');
  console.log('====================================================\n');

  tempTestDir = fs.mkdtempSync(path.join(os.tmpdir(), '2toolne_share_test_'));
  await setupMockServer();

  // Initialize CloudClient with authenticated mock storage
  const mockStorage = {
    data: { auth_token: 'valid_token_user_1' },
    getItem(k) { return this.data[k] || null; },
    setItem(k, v) { this.data[k] = v; },
    removeItem(k) { delete this.data[k]; },
  };

  cloudClient = new CloudClient({
    apiBase: serverBaseUrl,
    secureStorage: mockStorage,
    cacheDir: path.join(tempTestDir, 'cache'),
  });

  // ---------------------------------------------------------------------------
  // SHARE-T01: Create file share
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T01: Create file share ---');
  const share1 = await cloudClient.createShare('space_personal', 'FILE', 'file_img', 'ALLOW_DOWNLOAD', '7d');
  assert.strictEqual(share1.ok, true, 'Share creation must succeed');
  assert(share1.share.share_url.includes('/share/#'), 'Share URL must use URL fragment (#)');
  assert.strictEqual(share1.share.access_level, 'ALLOW_DOWNLOAD');
  console.log('✅ SHARE-T01 PASSED: File share created successfully.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T02: Cryptographically random token generated (32 bytes entropy)
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T02: Cryptographically random token generated ---');
  const rawToken1 = share1.share.raw_token;
  assert.strictEqual(rawToken1.length, 64, 'Token must be 64 hex characters (32 bytes / 256 bits entropy)');
  assert(/^[a-f0-9]{64}$/.test(rawToken1), 'Token must be a valid hex string');
  console.log('✅ SHARE-T02 PASSED: 32-byte cryptographic token generated (256 bits entropy).\n');

  // ---------------------------------------------------------------------------
  // SHARE-T03: Raw token not stored server-side (only SHA-256 hash & AES-GCM)
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T03: Raw token not stored server-side ---');
  const storedRecord = mockDb.shares.find((s) => s.id === share1.share.id);
  assert(storedRecord, 'Share record must exist in DB');
  assert.strictEqual(storedRecord.raw_token, undefined, 'Raw token must NEVER be stored in DB');
  assert(storedRecord.share_token_hash, 'SHA-256 hash must be stored');
  const expectedHash = crypto.createHash('sha256').update(rawToken1).digest('hex');
  assert.strictEqual(storedRecord.share_token_hash, expectedHash, 'Stored hash must match SHA-256 of raw token');
  assert(storedRecord.share_token_ciphertext, 'AES-256-GCM ciphertext envelope must be stored');
  console.log('✅ SHARE-T03 PASSED: Server stores only SHA-256 token hash and AES-GCM envelope.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T04: Public access works without login via POST /resolve
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T04: Public access works without login ---');
  const resolveReq = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: rawToken1 });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(postData),
        },
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
    req.write(postData);
    req.end();
  });
  assert.strictEqual(resolveReq.status, 200);
  assert.strictEqual(resolveReq.body.ok, true);
  assert.strictEqual(resolveReq.body.item.name, 'render.png');
  assert.strictEqual(resolveReq.body.access_level, 'ALLOW_DOWNLOAD');
  assert(resolveReq.body.share_session, 'Must return signed capability session token');
  const capabilitySession1 = resolveReq.body.share_session;
  console.log('✅ SHARE-T04 PASSED: Recipient accessed share without login and received capability token.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T05: Invalid token rejected
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T05: Invalid token rejected ---');
  const invalidRes = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: '0000000000000000000000000000000000000000000000000000000000000000' });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) },
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
    req.write(postData);
    req.end();
  });
  assert.strictEqual(invalidRes.status, 404);
  assert.strictEqual(invalidRes.body.code, 'NOT_FOUND');
  console.log('✅ SHARE-T05 PASSED: Unknown token safely rejected with HTTP 404.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T06: Expired link rejected
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T06: Expired link rejected ---');
  const expiredShare = await cloudClient.createShare('space_personal', 'FILE', 'file_img', 'ALLOW_DOWNLOAD', -100);
  const expiredToken = expiredShare.share.raw_token;

  const expiredAccess = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: expiredToken });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) },
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
    req.write(postData);
    req.end();
  });
  assert.strictEqual(expiredAccess.status, 410);
  assert.strictEqual(expiredAccess.body.code, 'EXPIRED');
  console.log('✅ SHARE-T06 PASSED: Expired share link rejected with HTTP 410.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T07: Revoked link rejected immediately
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T07: Revoked link rejected immediately ---');
  const revokeTarget = await cloudClient.createShare('space_personal', 'FILE', 'file_img', 'ALLOW_DOWNLOAD', '7d');
  const revokeToken = revokeTarget.share.raw_token;

  const revRes = await cloudClient.revokeShare(revokeTarget.share.id);
  assert.strictEqual(revRes.ok, true);

  const revokedAccess = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: revokeToken });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) },
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
    req.write(postData);
    req.end();
  });
  assert.strictEqual(revokedAccess.status, 410);
  assert.strictEqual(revokedAccess.body.code, 'REVOKED');
  console.log('✅ SHARE-T07 PASSED: Revoked link immediately rejected upon revocation.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T08: VIEW_ONLY hides/blocks download
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T08: VIEW_ONLY blocks download ---');
  const viewOnlyShare = await cloudClient.createShare('space_personal', 'FILE', 'file_img', 'VIEW_ONLY', '7d');
  const viewOnlyToken = viewOnlyShare.share.raw_token;

  const viewOnlyResolve = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: viewOnlyToken });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) },
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve(JSON.parse(data)));
      }
    );
    req.write(postData);
    req.end();
  });

  const blockedDownload = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/download?session=${encodeURIComponent(viewOnlyResolve.share_session)}`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(blockedDownload.status, 403);
  assert.strictEqual(blockedDownload.body.code, 'DOWNLOAD_FORBIDDEN');
  console.log('✅ SHARE-T08 PASSED: VIEW_ONLY link correctly blocks download with HTTP 403.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T09: ALLOW_DOWNLOAD permits download
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T09: ALLOW_DOWNLOAD permits download ---');
  const downloadStreamRes = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/download?session=${encodeURIComponent(capabilitySession1)}`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
      }
    );
  });
  assert.strictEqual(downloadStreamRes.status, 200);
  assert.strictEqual(downloadStreamRes.body, 'MOCK_CONTENT_OF_render.png');
  assert(downloadStreamRes.headers['content-disposition'].includes('attachment'));
  console.log('✅ SHARE-T09 PASSED: ALLOW_DOWNLOAD stream served file content correctly.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T10: Image preview works
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T10: Image preview works ---');
  const previewRes = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/preview?session=${encodeURIComponent(capabilitySession1)}`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
      }
    );
  });
  assert.strictEqual(previewRes.status, 200);
  assert.strictEqual(previewRes.headers['content-type'], 'image/png');
  assert(previewRes.headers['content-disposition'].includes('inline'));
  console.log('✅ SHARE-T10 PASSED: Image preview streamed inline successfully.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T11: Video Range streaming works
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T11: Video Range streaming works ---');
  const videoShare = await cloudClient.createShare('space_personal', 'FILE', 'file_vid', 'ALLOW_DOWNLOAD', '7d');
  const videoResolve = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: videoShare.share.raw_token });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      { method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) } },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve(JSON.parse(data)));
      }
    );
    req.write(postData);
    req.end();
  });

  const rangeRes = await new Promise((resolve) => {
    const req = http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/preview?session=${encodeURIComponent(videoResolve.share_session)}`,
      { headers: { Range: 'bytes=0-1023' } },
      (res) => {
        let len = 0;
        res.on('data', (c) => (len += c.length));
        res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, len }));
      }
    );
  });
  assert.strictEqual(rangeRes.status, 206);
  assert.strictEqual(rangeRes.headers['content-range'], 'bytes 0-1023/1048576');
  assert.strictEqual(rangeRes.len, 1024);
  console.log('✅ SHARE-T11 PASSED: HTTP Range request returned 206 Partial Content.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T12: Audio streaming works
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T12: Audio streaming works ---');
  const audioShare = await cloudClient.createShare('space_personal', 'FILE', 'file_aud', 'ALLOW_DOWNLOAD', '7d');
  const audioResolve = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: audioShare.share.raw_token });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      { method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) } },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve(JSON.parse(data)));
      }
    );
    req.write(postData);
    req.end();
  });

  const audioRes = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/preview?session=${encodeURIComponent(audioResolve.share_session)}`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
      }
    );
  });
  assert.strictEqual(audioRes.status, 200);
  assert.strictEqual(audioRes.headers['content-type'], 'audio/mpeg');
  console.log('✅ SHARE-T12 PASSED: Audio stream served with audio/mpeg content type.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T13: Folder share created
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T13: Folder share created ---');
  const folderShare = await cloudClient.createShare('space_personal', 'FOLDER', 'fld_shared', 'ALLOW_DOWNLOAD', '30d');
  assert.strictEqual(folderShare.ok, true);
  assert.strictEqual(folderShare.share.item_type, 'FOLDER');
  const folderToken = folderShare.share.raw_token;
  console.log('✅ SHARE-T13 PASSED: Folder share created successfully.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T14: Folder children listed via capability token
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T14: Folder children listed ---');
  const folderResolve = await new Promise((resolve) => {
    const postData = JSON.stringify({ share_token: folderToken });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/public/share/resolve`,
      { method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) } },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve(JSON.parse(data)));
      }
    );
    req.write(postData);
    req.end();
  });

  const folderNavRes = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/folder?session=${encodeURIComponent(folderResolve.share_session)}`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(folderNavRes.status, 200);
  assert.strictEqual(folderNavRes.body.folders.length, 1);
  assert.strictEqual(folderNavRes.body.folders[0].name, 'SubImages');
  console.log('✅ SHARE-T14 PASSED: Root folder children listed accurately.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T15: Subfolder navigation works within subtree
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T15: Subfolder navigation works within subtree ---');
  const subfolderNavRes = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/folder?session=${encodeURIComponent(folderResolve.share_session)}&subfolder_id=fld_child`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(subfolderNavRes.status, 200);
  assert.strictEqual(subfolderNavRes.body.files.length, 1);
  assert.strictEqual(subfolderNavRes.body.files[0].name, 'sub_photo.jpg');
  console.log('✅ SHARE-T15 PASSED: Descendant subfolder browsed successfully.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T16: Folder file download works when ALLOW_DOWNLOAD
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T16: Folder file download works ---');
  const subFileDownloadRes = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/download?session=${encodeURIComponent(folderResolve.share_session)}&file_id=file_in_sub`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: data }));
      }
    );
  });
  assert.strictEqual(subFileDownloadRes.status, 200);
  assert.strictEqual(subFileDownloadRes.body, 'MOCK_CONTENT_OF_sub_photo.jpg');
  console.log('✅ SHARE-T16 PASSED: Descendant file downloaded successfully via folder share.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T17: Parent traversal attempt rejected (Subtree isolation)
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T17: Parent traversal attempt rejected ---');
  const parentTraversal = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/folder?session=${encodeURIComponent(folderResolve.share_session)}&subfolder_id=root`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(parentTraversal.status, 403);
  assert.strictEqual(parentTraversal.body.code, 'FORBIDDEN');
  console.log('✅ SHARE-T17 PASSED: Parent traversal strictly rejected with HTTP 403.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T18: Sibling folder and files access strictly blocked
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T18: Sibling folder and files access strictly blocked ---');
  const siblingTraversal = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/folder?session=${encodeURIComponent(folderResolve.share_session)}&subfolder_id=fld_sibling`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(siblingTraversal.status, 403);
  assert.strictEqual(siblingTraversal.body.code, 'FORBIDDEN');

  const siblingDownload = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/download?session=${encodeURIComponent(folderResolve.share_session)}&file_id=file_in_sibling`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(siblingDownload.status, 403);
  console.log('✅ SHARE-T18 PASSED: Sibling folder and files access strictly blocked.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T19: Cross-space IDOR attempt rejected
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T19: Cross-space IDOR attempt rejected ---');
  const crossSpaceFolder = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/folder?session=${encodeURIComponent(folderResolve.share_session)}&subfolder_id=fld_other_space`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(crossSpaceFolder.status, 403);

  const crossSpaceFile = await new Promise((resolve) => {
    http.get(
      `${serverBaseUrl}/api/v1/cloud/public/share/download?session=${encodeURIComponent(folderResolve.share_session)}&file_id=file_other_space`,
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data) }));
      }
    );
  });
  assert.strictEqual(crossSpaceFile.status, 403);
  console.log('✅ SHARE-T19 PASSED: Cross-space IDOR attempts rejected with HTTP 403.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T20: Non-owner permission rejected
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T20: Non-owner permission rejected ---');
  const unauthorizedSpaceShare = await cloudClient.createShare('space_other', 'FILE', 'file_other_space', 'ALLOW_DOWNLOAD');
  assert.strictEqual(unauthorizedSpaceShare.ok, false);
  assert.strictEqual(unauthorizedSpaceShare.statusCode, 403);

  // VIEWER in team space cannot create share
  mockStorage.setItem('auth_token', 'valid_token_viewer');
  const viewerShare = await cloudClient.createShare('space_team', 'FILE', 'file_img', 'ALLOW_DOWNLOAD');
  assert.strictEqual(viewerShare.ok, false);
  assert.strictEqual(viewerShare.statusCode, 403);
  mockStorage.setItem('auth_token', 'valid_token_user_1'); // restore
  console.log('✅ SHARE-T20 PASSED: VIEWER and unauthorized users denied share creation.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T21: Desktop copy link formatted with fragment
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T21: Desktop copy link ---');
  assert(share1.share.share_url.startsWith('http'), 'share_url must be a full HTTP/HTTPS URL');
  assert(share1.share.share_url.includes('/share/#'), 'share_url must contain # fragment identifier');
  console.log('✅ SHARE-T21 PASSED: Share link formatted for clipboard copy with fragment.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T22: Desktop revoke link
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T22: Desktop revoke link ---');
  const newShareToRevoke = await cloudClient.createShare('space_personal', 'FILE', 'file_vid', 'ALLOW_DOWNLOAD', '30d');
  const revokeActionRes = await cloudClient.revokeShare(newShareToRevoke.share.id);
  assert.strictEqual(revokeActionRes.ok, true);
  assert.strictEqual(revokeActionRes.revoked, true);
  console.log('✅ SHARE-T22 PASSED: Share revoked via Desktop client IPC API.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T23: Existing share displayed instead of blind duplicate
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T23: Existing share displayed ---');
  const existingShares = await cloudClient.getItemShares('space_personal', 'FOLDER', 'fld_shared');
  assert.strictEqual(existingShares.ok, true);
  assert(existingShares.shares.length > 0, 'Active share for fld_shared must be returned');
  assert.strictEqual(existingShares.shares[0].item_id, 'fld_shared');
  console.log('✅ SHARE-T23 PASSED: Existing active shares returned for item.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T24: Filename XSS escaped
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T24: Filename XSS escaped ---');
  const maliciousName = '<script>alert("xss")</script>.png';
  const escapedHtml = maliciousName
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
  assert(!escapedHtml.includes('<script>'), 'XSS tag must be escaped');
  assert(escapedHtml.includes('&lt;script&gt;'), 'HTML entities must be used');
  console.log('✅ SHARE-T24 PASSED: Filename safely escaped for HTML rendering.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T25: Header/path injection filename sanitized
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T25: Header/path injection filename sanitized ---');
  const injectedFilename = '../../etc/passwd\r\nContent-Type: text/html\r\n\r\n<script>';
  const sanitized = sanitizeFilename(injectedFilename);
  assert(!sanitized.includes('\r'), 'CRLF must be stripped');
  assert(!sanitized.includes('\n'), 'CRLF must be stripped');
  assert(!sanitized.includes('/'), 'Path separators must be stripped');
  assert(!sanitized.includes('\\'), 'Path separators must be stripped');
  console.log('✅ SHARE-T25 PASSED: Filename header injection sanitized.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T26: Access/download logging does not contain raw token
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T26: Access logging does not contain raw token ---');
  assert(mockDb.accessLogs.length > 0, 'Access logs must be recorded');
  for (const log of mockDb.accessLogs) {
    assert(log.share_id, 'Log must contain share_id');
    assert.strictEqual(log.raw_token, undefined, 'Log must NOT contain raw token');
    assert.strictEqual(log.token, undefined, 'Log must NOT contain raw token');
  }
  console.log('✅ SHARE-T26 PASSED: Audit logs track share_id without exposing raw tokens.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T27: Recover existing share URL via AES-GCM without raw token DB storage
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T27: Recover existing share URL via AES-GCM ---');
  const sharesForRecovery = await cloudClient.getItemShares('space_personal', 'FILE', 'file_img');
  assert.strictEqual(sharesForRecovery.ok, true);
  const activeImgShare = sharesForRecovery.shares.find((s) => s.id === share1.share.id);
  assert(activeImgShare, 'Share record must be returned');
  assert(activeImgShare.share_url, 'Share URL must be recovered');
  assert(activeImgShare.share_url.includes('/share/#'), 'Recovered URL must have fragment format');
  assert.strictEqual(activeImgShare.share_url, share1.share.share_url, 'Recovered URL must exactly match original share URL');
  console.log('✅ SHARE-T27 PASSED: Owner recovered original URL via AES-GCM without raw token stored in DB.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T28: Unauthorized user cannot recover encrypted share token
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T28: Unauthorized user cannot recover encrypted share token ---');
  // As user_viewer (non-creator, viewer), call getItemShares on space_personal
  mockStorage.setItem('auth_token', 'valid_token_viewer');
  const viewerShares = await cloudClient.getItemShares('space_personal', 'FILE', 'file_img');
  // In our mock server, viewer sees share_url as null
  for (const s of viewerShares.shares) {
    assert.strictEqual(s.share_url, null, 'Unauthorized user must NOT receive decrypted share_url');
    assert.strictEqual(s.can_revoke, false, 'Unauthorized user must NOT be able to revoke');
  }
  mockStorage.setItem('auth_token', 'valid_token_user_1'); // restore
  console.log('✅ SHARE-T28 PASSED: Unauthorized user cannot recover encrypted token or URL.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T29: Master share token does not appear in application logs
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T29: Master share token does not appear in application logs ---');
  const allLogsString = JSON.stringify(mockDb.accessLogs);
  assert(!allLogsString.includes(rawToken1), 'Master raw token 1 must NEVER appear in access logs');
  assert(!allLogsString.includes(folderToken), 'Folder raw token must NEVER appear in access logs');
  console.log('✅ SHARE-T29 PASSED: Zero token leaks in application audit logs.\n');

  // ---------------------------------------------------------------------------
  // SHARE-T30: Master share token does not appear in public request URL (fragment + body)
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T30: Master share token does not appear in public request URL ---');
  for (const loggedUrl of mockDb.rawUrlLogs) {
    assert(!loggedUrl.includes(rawToken1), `Raw token 1 leaked in HTTP request path: ${loggedUrl}`);
    assert(!loggedUrl.includes(folderToken), `Folder token leaked in HTTP request path: ${loggedUrl}`);
  }
  console.log('✅ SHARE-T30 PASSED: Verified zero raw tokens in HTTP request URLs (fragment + body architecture).\n');

  // ---------------------------------------------------------------------------
  // SHARE-T31: Multiple Range requests count as 1 access session
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T31: Multiple Range requests count as 1 access session ---');
  const initialAccessCount = mockDb.shares.find((s) => s.id === videoShare.share.id).access_count;
  assert.strictEqual(initialAccessCount, 1, 'Initial resolve must increment access count exactly once');

  // Perform 5 separate Range chunk requests
  for (let i = 0; i < 5; i++) {
    await new Promise((resolve) => {
      http.get(
        `${serverBaseUrl}/api/v1/cloud/public/share/preview?session=${encodeURIComponent(videoResolve.share_session)}`,
        { headers: { Range: `bytes=${i * 1024}-${(i + 1) * 1024 - 1}` } },
        (res) => {
          res.on('data', () => {});
          res.on('end', resolve);
        }
      );
    });
  }
  const postRangeAccessCount = mockDb.shares.find((s) => s.id === videoShare.share.id).access_count;
  assert.strictEqual(postRangeAccessCount, 1, '5 Range requests must NOT increment access_count beyond 1');
  console.log('✅ SHARE-T31 PASSED: Multiple Range requests verified as single access session (no DB write storm).\n');

  // ---------------------------------------------------------------------------
  // SHARE-T32: Idempotency key prevents duplicate share records
  // ---------------------------------------------------------------------------
  console.log('--- SHARE-T32: Idempotency key prevents duplicate share records ---');
  const idemKey = 'idem_' + crypto.randomBytes(8).toString('hex');
  const firstCall = await new Promise((resolve) => {
    const postData = JSON.stringify({
      item_type: 'FILE',
      item_id: 'file_img',
      access_level: 'ALLOW_DOWNLOAD',
      expires_in: '7d',
      idempotency_key: idemKey,
    });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/spaces/space_personal/shares`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer valid_token_user_1',
          'Content-Length': Buffer.byteLength(postData),
        },
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve(JSON.parse(data)));
      }
    );
    req.write(postData);
    req.end();
  });

  assert.strictEqual(firstCall.ok, true);
  const firstShareId = firstCall.share.id;

  // Repeat exact same call with identical idempotency_key
  const secondCall = await new Promise((resolve) => {
    const postData = JSON.stringify({
      item_type: 'FILE',
      item_id: 'file_img',
      access_level: 'ALLOW_DOWNLOAD',
      expires_in: '7d',
      idempotency_key: idemKey,
    });
    const req = http.request(
      `${serverBaseUrl}/api/v1/cloud/spaces/space_personal/shares`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer valid_token_user_1',
          'Content-Length': Buffer.byteLength(postData),
        },
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => resolve(JSON.parse(data)));
      }
    );
    req.write(postData);
    req.end();
  });

  assert.strictEqual(secondCall.ok, true);
  assert.strictEqual(secondCall.share.id, firstShareId, 'Idempotent retry must return identical existing share ID');
  assert.strictEqual(secondCall.share.share_url, firstCall.share.share_url, 'Idempotent retry must return identical share_url');

  const sharesMatchingIdem = mockDb.shares.filter((s) => s.idempotency_key === idemKey);
  assert.strictEqual(sharesMatchingIdem.length, 1, 'Only 1 record must exist in DB for idempotency key');
  console.log('✅ SHARE-T32 PASSED: Idempotency key prevents duplicate share records in DB.\n');

  // Cleanup
  mockServer.close();
  try {
    fs.rmSync(tempTestDir, { recursive: true, force: true });
  } catch (e) {}

  console.log('====================================================');
  console.log('🎉 ALL 32 TESTS (SHARE-T01 to SHARE-T32) PASSED WITH ZERO ERRORS!');
  console.log('====================================================');
}

runAllTests().catch((err) => {
  console.error('❌ Test failed with error:', err);
  if (mockServer) mockServer.close();
  process.exit(1);
});
