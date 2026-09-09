/**
 * apps/capcut-v2/desktop/src/main/cloud_client.js
 * Production-ready Server-Backed Cloud Client for 2TOOLNE AutoEdit Desktop.
 * Handles authenticated Cloud VFS calls, auto-refresh on 401, bounded streaming uploads/downloads,
 * and deterministic local asset caching for Studio pair-programming.
 */

const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');
const crypto = require('crypto');
const { URL } = require('url');

class CloudClient {
  constructor(options = {}) {
    this.apiBase = options.apiBase || options.baseUrl || 'https://www.2tamne.site';
    this._explicitToken = options.token !== undefined ? options.token : undefined;
    this.secureStorage = options.secureStorage;
    this.cacheDir = options.cacheDir || path.join(process.cwd(), 'cache', 'cloud_assets');
    this.activeUploads = new Map(); // uploadId -> { req, aborted }

    // Ensure cache directory exists
    try {
      fs.mkdirSync(this.cacheDir, { recursive: true });
    } catch (e) {}
  }

  setCacheDir(dir) {
    this.cacheDir = dir;
    try {
      fs.mkdirSync(this.cacheDir, { recursive: true });
    } catch (e) {}
  }

  setApiBase(base) {
    this.apiBase = base;
  }

  setAuthToken(token) {
    this._explicitToken = token;
  }

  getAuthToken() {
    if (this._explicitToken !== undefined) return this._explicitToken;
    return this.secureStorage ? this.secureStorage.getItem('auth_token') : null;
  }

  getRefreshToken() {
    return this.secureStorage ? this.secureStorage.getItem('auth_refresh_token') : null;
  }

  /**
   * Core authenticated HTTP request with automatic token refresh on 401
   */
  async request(endpoint, options = {}, isRetry = false) {
    const token = this.getAuthToken();
    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers || {}),
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const targetUrl = endpoint.startsWith('http') ? endpoint : `${this.apiBase}${endpoint}`;
    const parsedUrl = new URL(targetUrl);
    const isHttps = parsedUrl.protocol === 'https:';
    const transport = isHttps ? https : http;

    return new Promise((resolve, reject) => {
      const req = transport.request(
        targetUrl,
        {
          method: options.method || 'GET',
          headers,
          timeout: options.timeout || 30000,
        },
        async (res) => {
          let body = '';
          res.on('data', (chunk) => (body += chunk));
          res.on('end', async () => {
            // Handle 401 Token Expiration with silent refresh
            if (res.statusCode === 401 && !isRetry) {
              const refreshed = await this.refreshSession();
              if (refreshed) {
                try {
                  const retryRes = await this.request(endpoint, options, true);
                  return resolve(retryRes);
                } catch (retryErr) {
                  return reject(retryErr);
                }
              } else {
                if (typeof this.onSessionExpired === 'function') {
                  try { this.onSessionExpired(); } catch (_) {}
                }
                return resolve({
                  ok: false,
                  statusCode: 401,
                  code: 'AUTH_REQUIRED',
                  error: 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.',
                });
              }
            }

            let parsed;
            try {
              parsed = JSON.parse(body);
            } catch (e) {
              parsed = { raw: body };
            }

            if (res.statusCode >= 200 && res.statusCode < 300) {
              resolve({ ok: true, statusCode: res.statusCode, status: res.statusCode, data: parsed });
            } else {
              const errMsg = parsed?.message || parsed?.error || `Máy chủ phản hồi mã lỗi ${res.statusCode}`;
              const errCode = parsed?.code || (res.statusCode === 403 ? 'FORBIDDEN' : res.statusCode === 404 ? 'NOT_FOUND' : 'ERROR');
              resolve({ ok: false, statusCode: res.statusCode, status: res.statusCode, code: errCode, error: errMsg, data: parsed });
            }
          });
        }
      );

      req.on('error', (err) => {
        resolve({
          ok: false,
          code: 'NETWORK_ERROR',
          error: `Không thể kết nối đến máy chủ Cloud: ${err.message}`,
        });
      });

      req.on('timeout', () => {
        req.destroy();
        resolve({
          ok: false,
          code: 'TIMEOUT',
          error: 'Kết nối máy chủ Cloud quá thời gian chờ (Timeout).',
        });
      });

      if (options.body) {
        req.write(typeof options.body === 'string' ? options.body : JSON.stringify(options.body));
      }
      req.end();
    });
  }

  /**
   * Silently refresh access token using auth_refresh_token
   */
  async refreshSession() {
    if (this._refreshPromise) return this._refreshPromise;
    this._refreshPromise = (async () => {
      const refreshToken = this.getRefreshToken();
      if (!refreshToken || !this.secureStorage) return false;

      try {
        const res = await this.request(
          '/api/v1/auth/refresh',
          {
            method: 'POST',
            body: { refresh_token: refreshToken },
          },
          true // isRetry = true to prevent infinite loop
        );

        if (res.ok && res.data && res.data.token) {
          this.secureStorage.setItem('auth_token', res.data.token);
          if (res.data.refresh_token) {
            this.secureStorage.setItem('auth_refresh_token', res.data.refresh_token);
          }
          if (res.data.user) {
            this.secureStorage.setItem('auth_user', res.data.user);
          }
          return true;
        }
      } catch (e) {
        console.warn('[CloudClient] Session refresh failed:', e.message);
      }
      return false;
    })();

    try {
      return await this._refreshPromise;
    } finally {
      this._refreshPromise = null;
    }
  }

  // ==========================================
  // Cloud Spaces & Quota
  // ==========================================

  async getSpaces() {
    const res = await this.request('/api/v1/cloud/spaces');
    if (!res.ok) return res;
    return { ok: true, spaces: res.data.spaces || [] };
  }

  async listSpaces() {
    return this.getSpaces();
  }

  async getQuota(spaceId) {
    const res = await this.request(`/api/v1/cloud/spaces/${encodeURIComponent(spaceId)}/quota`);
    if (!res.ok) return res;
    return { ok: true, quota: res.data.quota || {} };
  }

  // ==========================================
  // Virtual File System (Files & Folders)
  // ==========================================

  async listFiles(spaceId, folderId = null, search = null) {
    let q = '';
    const params = [];
    if (folderId) params.push(`folder_id=${encodeURIComponent(folderId)}`);
    if (search) params.push(`q=${encodeURIComponent(search)}`);
    if (params.length) q = '?' + params.join('&');

    const res = await this.request(`/api/v1/cloud/spaces/${encodeURIComponent(spaceId)}/files${q}`);
    if (!res.ok) return res;

    return {
      ok: true,
      space_id: res.data.space_id,
      folder_id: res.data.folder_id,
      breadcrumbs: res.data.breadcrumbs || [],
      folders: res.data.folders || [],
      files: res.data.files || [],
    };
  }

  async createFolder(spaceId, name, parentId = null) {
    const res = await this.request(`/api/v1/cloud/spaces/${encodeURIComponent(spaceId)}/folders`, {
      method: 'POST',
      body: { name, parent_id: parentId },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, folder: d.folder || res.data?.folder };
  }

  async renameItem(type, id, newName) {
    const endpoint = type === 'folder'
      ? `/api/v1/cloud/folders/${encodeURIComponent(id)}/rename`
      : `/api/v1/cloud/files/${encodeURIComponent(id)}/rename`;

    const res = await this.request(endpoint, {
      method: 'POST',
      body: { name: newName },
    });
    if (!res.ok) return res;
    return { ok: true, data: res.data };
  }

  async moveItem(type, id, targetFolderId) {
    const endpoint = type === 'folder'
      ? `/api/v1/cloud/folders/${encodeURIComponent(id)}/move`
      : `/api/v1/cloud/files/${encodeURIComponent(id)}/move`;

    const body = type === 'folder'
      ? { parent_id: targetFolderId }
      : { folder_id: targetFolderId };

    const res = await this.request(endpoint, {
      method: 'POST',
      body,
    });
    if (!res.ok) return res;
    return { ok: true, data: res.data };
  }

  async trashItem(type, id) {
    const endpoint = type === 'folder'
      ? `/api/v1/cloud/folders/${encodeURIComponent(id)}/trash`
      : `/api/v1/cloud/files/${encodeURIComponent(id)}/trash`;

    const res = await this.request(endpoint, { method: 'POST' });
    if (!res.ok) return res;
    return { ok: true, message: res.data.message };
  }

  async listTrash(spaceId) {
    const res = await this.request(`/api/v1/cloud/spaces/${encodeURIComponent(spaceId)}/trash`);
    if (!res.ok) return res;
    return { ok: true, trash: res.data.trash || [] };
  }

  async restoreItem(type, id) {
    const endpoint = type === 'folder'
      ? `/api/v1/cloud/folders/${encodeURIComponent(id)}/restore`
      : `/api/v1/cloud/files/${encodeURIComponent(id)}/restore`;

    const res = await this.request(endpoint, { method: 'POST' });
    if (!res.ok) return res;
    return { ok: true, message: res.data.message };
  }

  async permanentDeleteItem(type, id) {
    const endpoint = type === 'folder'
      ? `/api/v1/cloud/folders/${encodeURIComponent(id)}/permanent`
      : `/api/v1/cloud/files/${encodeURIComponent(id)}/permanent`;

    const res = await this.request(endpoint, { method: 'DELETE' });
    if (!res.ok) return res;
    return { ok: true, message: res.data.message };
  }

  // ==========================================
  // Large File Resumable Streaming Upload
  // ==========================================

  async uploadFile(filePath, spaceId, folderId = null, onProgress = null) {
    const fileName = path.basename(filePath);
    let stats;
    try {
      stats = await fs.promises.stat(filePath);
    } catch (e) {
      return { ok: false, error: `Không thể đọc tệp tin: ${e.message}` };
    }

    const fileSize = stats.size;

    // 1. Pre-flight Quota Verification
    const quotaRes = await this.getQuota(spaceId);
    if (quotaRes.ok && quotaRes.quota) {
      const free = quotaRes.quota.free_bytes !== undefined ? quotaRes.quota.free_bytes : 5368709120;
      if (fileSize > free) {
        const needMb = (fileSize / (1024 * 1024)).toFixed(1);
        const freeMb = (free / (1024 * 1024)).toFixed(1);
        return {
          ok: false,
          code: 'QUOTA_EXCEEDED',
          error: `Cloud không còn đủ dung lượng. Cần: ${needMb} MB, Hiện còn trống: ${freeMb} MB.`,
        };
      }
    }

    // 2. Request Resumable Upload Session from 2TOOLNE Backend
    const createRes = await this.request(`/api/v1/cloud/spaces/${encodeURIComponent(spaceId)}/uploads/create`, {
      method: 'POST',
      body: {
        file_name: fileName,
        file_size_bytes: fileSize,
        folder_id: folderId,
      },
    });

    if (!createRes.ok) {
      return createRes;
    }

    const uploadReservationId = createRes.data?.upload_reservation_id || createRes.data?.upload_id || createRes.data?.session_id || 'upload_session_1';
    const sessionUrl = createRes.data?.resumable_session_url || createRes.data?.session_url;
    let cloudFileId = createRes.data?.cloud_file_id || createRes.data?.file_id;

    if (!sessionUrl) {
      return { ok: false, error: 'Máy chủ không trả về URL phiên tải lên hợp lệ.' };
    }

    // 3. Stream Upload directly using fs.createReadStream (Bounded RAM)
    const uploadId = uploadReservationId;
    let uploadedBytes = 0;

    const parsedSession = new URL(sessionUrl);
    const transport = parsedSession.protocol === 'https:' ? https : http;
    try {
      const uploadResult = await new Promise((resolve, reject) => {
        const fileStream = fs.createReadStream(filePath);

        const putReq = transport.request(
          sessionUrl,
          {
            method: 'PUT',
            headers: {
              'Content-Length': fileSize,
              'Content-Type': 'application/octet-stream',
              'Connection': 'close',
            },
            agent: false,
          },
          (putRes) => {
            let resBody = '';
            putRes.on('data', (c) => (resBody += c));
            putRes.on('end', () => {
              this.activeUploads.delete(uploadId);
              if (putRes.statusCode >= 200 && putRes.statusCode < 300) {
                let parsedProvider = {};
                try {
                  parsedProvider = JSON.parse(resBody);
                } catch (e) {}
                resolve({ ok: true, providerFileId: parsedProvider.id || `uploaded_${uploadId}` });
              } else {
                resolve({ ok: false, error: `Lỗi upload lên bộ lưu trữ (${putRes.statusCode}): ${resBody}` });
              }
            });
          }
        );

        this.activeUploads.set(uploadId, { req: putReq, fileStream, aborted: false });

        putReq.on('error', (err) => {
          this.activeUploads.delete(uploadId);
          reject(err);
        });

        // Pipe file stream directly to request
        fileStream.on('data', (chunk) => {
          uploadedBytes += chunk.length;
          if (onProgress) {
            const pct = Math.min(100, Math.round((uploadedBytes / fileSize) * 100));
            onProgress({
              uploadId,
              fileName,
              uploadedBytes,
              totalBytes: fileSize,
              percent: pct,
              status: 'UPLOADING',
            });
          }
        });

        fileStream.on('error', (err) => {
          putReq.destroy();
          this.activeUploads.delete(uploadId);
          reject(err);
        });

        fileStream.pipe(putReq);
      });

      if (!uploadResult.ok) {
        await this.abortUpload(uploadId);
        return uploadResult;
      }

      // 4. Finalize upload with 2TOOLNE backend
      if (onProgress) {
        onProgress({
          uploadId,
          fileName,
          uploadedBytes: fileSize,
          totalBytes: fileSize,
          percent: 100,
          status: 'VERIFYING',
        });
      }

      const finRes = await this.request(`/api/v1/cloud/uploads/${encodeURIComponent(uploadId)}/finalize`, {
        method: 'POST',
        body: {
          provider_file_id: uploadResult.providerFileId,
        },
      });

      if (!finRes.ok) {
        return finRes;
      }

      if (finRes.data?.file?.id) {
        cloudFileId = finRes.data.file.id;
      } else if (finRes.data?.file_id) {
        cloudFileId = finRes.data.file_id;
      }

      if (onProgress) {
        onProgress({
          uploadId,
          fileName,
          uploadedBytes: fileSize,
          totalBytes: fileSize,
          percent: 100,
          status: 'DONE',
        });
      }

      return {
        ok: true,
        cloud_file_id: cloudFileId,
        file_id: cloudFileId,
        fileName,
        size_bytes: fileSize,
      };
    } catch (err) {
      this.activeUploads.delete(uploadId);
      await this.abortUpload(uploadId).catch(() => {});
      return { ok: false, error: `Quá trình tải lên thất bại: ${err.message}` };
    }
  }

  async cancelUpload(uploadId) {
    const item = this.activeUploads.get(uploadId);
    if (item) {
      item.aborted = true;
      if (item.fileStream) item.fileStream.destroy();
      if (item.req) item.req.destroy();
      this.activeUploads.delete(uploadId);
    }
    return this.abortUpload(uploadId);
  }

  async abortUpload(uploadId) {
    return this.request(`/api/v1/cloud/uploads/${encodeURIComponent(uploadId)}/abort`, {
      method: 'POST',
    });
  }

  // ==========================================
  // Streaming Download & Local Cache
  // ==========================================

  /**
   * Downloads a cloud file directly to a destination path
   */
  async downloadFile(fileId, destinationPath, onProgress = null) {
    const token = this.getAuthToken();
    const downloadUrl = `${this.apiBase}/api/v1/cloud/files/${encodeURIComponent(fileId)}/download`;
    const parsedUrl = new URL(downloadUrl);
    const transport = parsedUrl.protocol === 'https:' ? https : http;

    return new Promise((resolve) => {
      const headers = { Connection: 'close' };
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const req = transport.get(downloadUrl, { headers, agent: false }, (res) => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          // Handle redirect
          return resolve(this.downloadFromUrl(res.headers.location, destinationPath, onProgress));
        }

        if (res.statusCode !== 200) {
          return resolve({ ok: false, error: `Tải tệp không thành công (HTTP ${res.statusCode})` });
        }

        const totalBytes = parseInt(res.headers['content-length'] || '0', 10);
        let downloadedBytes = 0;

        const outStream = fs.createWriteStream(destinationPath);
        res.on('data', (chunk) => {
          downloadedBytes += chunk.length;
          if (onProgress && totalBytes > 0) {
            onProgress({
              fileId,
              downloadedBytes,
              totalBytes,
              percent: Math.min(100, Math.round((downloadedBytes / totalBytes) * 100)),
            });
          }
        });

        res.pipe(outStream);
        outStream.on('finish', () => {
          resolve({ ok: true, path: destinationPath });
        });
        outStream.on('error', (err) => {
          resolve({ ok: false, error: `Lỗi ghi file ra ổ đĩa: ${err.message}` });
        });
      });

      req.on('error', (err) => {
        resolve({ ok: false, error: `Lỗi kết nối tải file: ${err.message}` });
      });
    });
  }

  /**
   * Helper to download from external redirect (e.g. Google Drive stream)
   */
  async downloadFromUrl(urlStr, destinationPath, onProgress = null) {
    const parsed = new URL(urlStr);
    const transport = parsed.protocol === 'https:' ? https : http;

    return new Promise((resolve) => {
      const req = transport.get(urlStr, { headers: { Connection: 'close' }, agent: false }, (res) => {
        if (res.statusCode !== 200) {
          return resolve({ ok: false, error: `Tải tệp từ nguồn chuyển hướng thất bại (HTTP ${res.statusCode})` });
        }
        const totalBytes = parseInt(res.headers['content-length'] || '0', 10);
        let downloadedBytes = 0;
        const outStream = fs.createWriteStream(destinationPath);
        res.on('data', (c) => {
          downloadedBytes += c.length;
          if (onProgress && totalBytes > 0) {
            onProgress({
              downloadedBytes,
              totalBytes,
              percent: Math.min(100, Math.round((downloadedBytes / totalBytes) * 100)),
            });
          }
        });
        res.pipe(outStream);
        outStream.on('finish', () => resolve({ ok: true, path: destinationPath }));
        outStream.on('error', (err) => resolve({ ok: false, error: err.message }));
      });
      req.on('error', (err) => resolve({ ok: false, error: err.message }));
    });
  }

  /**
   * Deterministic local cache for Studio: <cacheDir>/<fileId>_<sha256>.<ext>
   */
  async cacheAndGetPath(file) {
    const fileId = file.id;
    const filename = file.name || file.filename || file.file_name || 'asset.bin';
    const ext = path.extname(filename) || '';
    const rawHash = file.sha256_hash || file.checksum_sha256 || file.hash || '';
    const hash = rawHash ? rawHash.substring(0, 11) : 'nohash';
    const cacheFilename = `${fileId}_${hash}${ext}`;
    const cachedFilePath = path.join(this.cacheDir, cacheFilename);

    try {
      if (fs.existsSync(cachedFilePath)) {
        const st = fs.statSync(cachedFilePath);
        if (st.size > 0 && (!file.size_bytes || st.size === parseInt(file.size_bytes, 10))) {
          return { ok: true, localPath: cachedFilePath, cached: true };
        }
      }
    } catch (e) {}

    // Download to cache
    const dlRes = await this.downloadFile(fileId, cachedFilePath);
    if (!dlRes.ok) {
      return dlRes;
    }
    return { ok: true, localPath: cachedFilePath, cached: false };
  }

  // ==========================================
  // Public Share Links (Priority 5)
  // ==========================================

  async createShare(spaceId, itemType, itemId, accessLevel = 'VIEW_ONLY', expiresIn = null) {
    const res = await this.request(`/api/v1/cloud/spaces/${encodeURIComponent(spaceId)}/shares`, {
      method: 'POST',
      body: {
        item_type: itemType,
        item_id: itemId,
        access_level: accessLevel,
        expires_in: expiresIn,
      },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, share: d.share || res.data?.share };
  }

  async getItemShares(spaceId, itemType = null, itemId = null) {
    let q = '';
    const params = [];
    if (itemType) params.push(`item_type=${encodeURIComponent(itemType)}`);
    if (itemId) params.push(`item_id=${encodeURIComponent(itemId)}`);
    if (params.length) q = '?' + params.join('&');

    const res = await this.request(`/api/v1/cloud/spaces/${encodeURIComponent(spaceId)}/shares${q}`);
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, shares: d.shares || res.data?.shares || [] };
  }

  async revokeShare(shareId) {
    const res = await this.request(`/api/v1/cloud/shares/${encodeURIComponent(shareId)}/revoke`, {
      method: 'POST',
    });
    if (!res.ok) return res;
    return { ok: true, revoked: true };
  }

  // ==========================================
  // Team & Workspaces (Priority 6)
  // ==========================================

  async createTeam(name) {
    const res = await this.request('/api/v1/teams', {
      method: 'POST',
      body: { name },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, team: d.team, space: d.space };
  }

  async getTeam(teamId) {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}`);
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, team: d.team };
  }

  async listTeamMembers(teamId) {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}/members`);
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, members: d.members || [] };
  }

  async createTeamInvitation(teamId, offeredRole = 'EDITOR', recipientEmail = '') {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}/invitations`, {
      method: 'POST',
      body: {
        offered_role: offeredRole,
        recipient_email: recipientEmail,
      },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return {
      ok: true,
      invite_id: d.invite_id,
      offered_role: d.offered_role,
      invite_url: d.invite_url,
      invite_token: d.invite_token,
      expires_at: d.expires_at,
    };
  }

  async acceptTeamInvitation(inviteToken) {
    const res = await this.request('/api/v1/teams/invitations/accept', {
      method: 'POST',
      body: { invite_token: inviteToken },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return {
      ok: true,
      team_id: d.team_id,
      team_name: d.team_name,
      role: d.role,
      message: d.message,
    };
  }

  async changeMemberRole(teamId, userId, role) {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}/members/${encodeURIComponent(userId)}/role`, {
      method: 'POST',
      body: { role },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, user_id: d.user_id, new_role: d.new_role };
  }

  async removeTeamMember(teamId, userId) {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}/members/${encodeURIComponent(userId)}`, {
      method: 'DELETE',
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, removed: true, message: d.message };
  }

  async listTeamSeats(teamId) {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}/seats`);
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return {
      ok: true,
      max_seats: d.max_seats,
      active_seats: d.active_seats,
      remaining: d.remaining,
      seats: d.seats || [],
    };
  }

  async activateTeamSeat(teamId, deviceId, deviceAlias = '', platform = 'mac-arm64') {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}/seats/activate`, {
      method: 'POST',
      body: {
        device_id: deviceId,
        device_alias: deviceAlias,
        platform,
      },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return {
      ok: true,
      seat_id: d.seat_id,
      status: d.status,
      signed_entitlement: d.signed_entitlement,
    };
  }

  async revokeTeamSeat(teamId, seatId) {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}/seats/${encodeURIComponent(seatId)}/revoke`, {
      method: 'POST',
    });
    if (!res.ok) return res;
    return { ok: true, revoked: true };
  }

  async deleteTeam(teamId) {
    const res = await this.request(`/api/v1/teams/${encodeURIComponent(teamId)}`, {
      method: 'DELETE',
    });
    if (!res.ok) return res;
    return { ok: true, archived: true };
  }

  // ==========================================
  // Token Wallets & Credit Reservations
  // ==========================================

  async getWalletBalance(teamId = null) {
    const q = teamId ? `?team_id=${encodeURIComponent(teamId)}` : '';
    const res = await this.request(`/api/v1/wallet/balance${q}`);
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, data: d, balance: d.balance, reserved: d.reserved || d.reserved_balance };
  }

  async reserveCredits(tokens, reason = '', idempotencyKey = null, workspaceId = null, teamId = null) {
    const res = await this.request('/api/v1/credits/reserve', {
      method: 'POST',
      body: {
        tokens_to_reserve: tokens,
        reason,
        idempotency_key: idempotencyKey,
        workspace_id: workspaceId,
        team_id: teamId,
      },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, data: d, reservation_id: d.reservation_id };
  }

  async commitCredits(reservationId) {
    const res = await this.request('/api/v1/credits/commit', {
      method: 'POST',
      body: { reservation_id: reservationId },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, data: d };
  }

  async releaseCredits(reservationId) {
    const res = await this.request('/api/v1/credits/release', {
      method: 'POST',
      body: { reservation_id: reservationId },
    });
    if (!res.ok) return res;
    const d = res.data?.data || res.data || {};
    return { ok: true, data: d };
  }

  // ==========================================
  // AI Access Keys (Phase 1)
  // ==========================================

  async listAiKeys() {
    const res = await this.request('/api/v1/ai/keys');
    if (!res.ok) return res;
    return { ok: true, keys: res.data?.keys || [] };
  }

  async createAiKey(displayName, workspaceId, expiresInDays = 365, scopes = null) {
    const body = {
      display_name: displayName,
      workspace_id: workspaceId,
      expires_in_days: expiresInDays,
    };
    if (scopes && Array.isArray(scopes)) {
      body.scopes = scopes;
    }
    const res = await this.request('/api/v1/ai/keys', {
      method: 'POST',
      body,
    });
    if (!res.ok) return res;
    return { ok: true, statusCode: res.statusCode || 201, ...res.data };
  }

  async revokeAiKey(keyId) {
    const res = await this.request(`/api/v1/ai/keys/${encodeURIComponent(keyId)}`, {
      method: 'DELETE',
    });
    if (!res.ok) return res;
    return { ok: true, ...res.data };
  }
}

module.exports = { CloudClient };
