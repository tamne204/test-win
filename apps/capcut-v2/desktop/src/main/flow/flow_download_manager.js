/**
 * apps/capcut-v2/desktop/src/main/flow/flow_download_manager.js
 * Controlled Download & Media Verification Manager for Google Flow.
 *
 * Enforces:
 * - Direct mapping: Flow attempt -> scene_id -> temp download -> media verify -> canonical rename
 * - Zero reliance on "newest file in Downloads"
 * - Deep media verification using ffprobe & binary headers (size > 0, width > 0, height > 0, duration > 0)
 * - Canonical filenames: 001-<slug>.png / 001-<slug>.mp4
 * - Rejection of HTML error pages disguised with media extensions.
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const https = require('https');
const http = require('http');
const crypto = require('crypto');
const { execFile } = require('child_process');
const { promisify } = require('util');
const execFileAsync = promisify(execFile);
const { getFfprobePath } = require('../bin_resolver');

class FlowDownloadManager {
  /**
   * @param {Object} [options]
   * @param {string} [options.tempDir] Custom temporary download directory
   */
  constructor(options = {}) {
    this.tempDir = options.tempDir || path.join(os.homedir(), '.2toolne', 'flow_downloads');
    this.activeAttempts = new Map(); // attempt_id -> attempt metadata
    this.operationToAttempt = new Map(); // operation_id -> attempt_id (Section 22)
    this.mediaToAttempt = new Map(); // media_id -> attempt_id (Section 22)
    this.pathToAttempt = new Map(); // final_path -> attempt_id (Section 22)
    this.pendingDownloads = new Map(); // download item url/filename -> attempt_id
    this.expectedAttemptId = null; // explicit target attempt ID before triggering download

    this.ensureTempDir();
  }

  ensureTempDir() {
    if (!fs.existsSync(this.tempDir)) {
      fs.mkdirSync(this.tempDir, { recursive: true });
    }
  }

  /**
   * Register an intended generation attempt before triggering download.
   * @param {Object} attempt
   * @param {string} attempt.attempt_id
   * @param {string} attempt.pipeline_job_id
   * @param {string} attempt.scene_id (e.g. "001")
   * @param {string} attempt.generation_type ("image" | "video" | "character_ref")
   * @param {string} [attempt.slug]
   * @param {string} attempt.target_dir
   * @param {string} [attempt.character_id]
   * @param {string} [attempt.operation_id]
   * @param {string} [attempt.media_id]
   * @param {string} [attempt.flow_download_resolution]
   */
  registerAttempt(attempt = {}) {
    const attemptId = attempt.attempt_id || `att_${Date.now()}_${crypto.randomBytes(3).toString('hex')}`;
    const record = {
      attempt_id: attemptId,
      pipeline_job_id: attempt.pipeline_job_id,
      scene_id: attempt.scene_id || '001',
      generation_type: attempt.generation_type || 'image',
      slug: attempt.slug || 'scene',
      target_dir: attempt.target_dir,
      character_id: attempt.character_id || null,
      operation_id: attempt.operation_id || null,
      media_id: attempt.media_id || null,
      flow_download_resolution: attempt.flow_download_resolution || '1080p',
      actual_flow_resolution: null,
      actual_downloaded_width: 0,
      actual_downloaded_height: 0,
      created_at: Date.now(),
      status: 'REGISTERED',
      temp_path: null,
      final_path: null,
    };
    this.activeAttempts.set(attemptId, record);

    if (record.operation_id) {
      this.operationToAttempt.set(record.operation_id, record.attempt_id);
    }
    if (record.media_id) {
      this.mediaToAttempt.set(record.media_id, record.attempt_id);
    }

    return record;
  }

  /**
   * Update correlation indices when operation_id or media_id is assigned (Section 21 & 22)
   */
  updateAttemptCorrelation(attemptId, { operation_id, media_id } = {}) {
    const attempt = this.activeAttempts.get(attemptId);
    if (!attempt) return null;

    if (operation_id) {
      attempt.operation_id = operation_id;
      this.operationToAttempt.set(operation_id, attemptId);
    }
    if (media_id) {
      attempt.media_id = media_id;
      this.mediaToAttempt.set(media_id, attemptId);
    }
    return attempt;
  }

  /**
   * Resolve attempt by any correlation key
   */
  getAttemptByCorrelation({ attempt_id, operation_id, media_id } = {}) {
    if (attempt_id && this.activeAttempts.has(attempt_id)) {
      return this.activeAttempts.get(attempt_id);
    }
    if (operation_id && this.operationToAttempt.has(operation_id)) {
      const attId = this.operationToAttempt.get(operation_id);
      return this.activeAttempts.get(attId) || null;
    }
    if (media_id && this.mediaToAttempt.has(media_id)) {
      const attId = this.mediaToAttempt.get(media_id);
      return this.activeAttempts.get(attId) || null;
    }
    return null;
  }

  getAttempt(attemptId) {
    return this.activeAttempts.get(attemptId) || null;
  }

  /**
   * Attach download listener to an Electron session to intercept downloads.
   * @param {import('electron').Session} session
   */
  attachToSession(session) {
    if (!session || typeof session.on !== 'function') return;
    this.session = session;

    session.on('will-download', (event, item, webContents) => {
      const url = item.getURL();
      const filename = item.getFilename();
      const ext = path.extname(filename) || '.mp4';

      // Correlate directly with active attempt using multi-key correlation (Section 22)
      let matchedAttempt = null;

      // 0. Explicitly expected attempt before triggering download
      if (this.expectedAttemptId && this.activeAttempts.has(this.expectedAttemptId)) {
        matchedAttempt = this.activeAttempts.get(this.expectedAttemptId);
        this.expectedAttemptId = null;
      }

      // 1. Check operation_id
      if (!matchedAttempt) {
        for (const [opId, attId] of this.operationToAttempt.entries()) {
          if (opId && (url.includes(opId) || filename.includes(opId))) {
            matchedAttempt = this.activeAttempts.get(attId);
            break;
          }
        }
      }

      // 2. Check media_id
      if (!matchedAttempt) {
        for (const [mId, attId] of this.mediaToAttempt.entries()) {
          if (mId && (url.includes(mId) || filename.includes(mId))) {
            matchedAttempt = this.activeAttempts.get(attId);
            break;
          }
        }
      }

      // 3. Check attempt_id
      if (!matchedAttempt) {
        for (const [attId, attempt] of this.activeAttempts.entries()) {
          if (url.includes(attId) || filename.includes(attId)) {
            matchedAttempt = attempt;
            break;
          }
        }
      }

      // 4. Safe single fallback
      if (!matchedAttempt) {
        const pending = Array.from(this.activeAttempts.values()).filter(a => a.status === 'REGISTERED' || a.status === 'DOWNLOADING');
        if (pending.length === 1) {
          matchedAttempt = pending[0];
        }
      }

      const attemptId = matchedAttempt ? matchedAttempt.attempt_id : `unregistered_${Date.now()}`;
      const tempFilename = `dl_${attemptId}${ext}`;
      const tempPath = path.join(this.tempDir, tempFilename);

      item.setSavePath(tempPath);
      if (matchedAttempt) {
        matchedAttempt.status = 'DOWNLOADING';
        matchedAttempt.temp_path = tempPath;
      }

      item.on('updated', (event, state) => {
        if (state === 'interrupted') {
          console.warn(`[FlowDownloadManager] Download interrupted for attempt ${attemptId}`);
        }
      });

      item.once('done', (event, state) => {
        if (state === 'completed') {
          if (matchedAttempt) {
            matchedAttempt.status = 'DOWNLOADED';
          }
        } else {
          if (matchedAttempt) {
            matchedAttempt.status = 'FAILED';
            matchedAttempt.error = `Download ${state}`;
          }
        }
      });
    });
  }

  /**
   * Download a file directly via HTTPS/HTTP into temporary storage.
   * @param {string} url
   * @param {string} attemptId
   * @param {string} [extension]
   * @returns {Promise<string>} Path to temporary downloaded file
   */
  async downloadUrl(url, attemptId, extension = null) {
    const attempt = this.activeAttempts.get(attemptId);
    let ext = extension;
    if (!ext) {
      try {
        const parsed = new URL(url);
        ext = path.extname(parsed.pathname) || (attempt?.generation_type === 'video' ? '.mp4' : '.png');
      } catch {
        ext = attempt?.generation_type === 'video' ? '.mp4' : '.png';
      }
    }

    const tempFilename = `direct_${attemptId}${ext}`;
    const tempPath = path.join(this.tempDir, tempFilename);

    let cookieHeader = '';
    if (this.session?.cookies) {
      try {
        const cookies = await this.session.cookies.get({ url: 'https://flow.google.com' });
        if (cookies && cookies.length > 0) {
          cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');
        }
      } catch (err) {
        console.warn('[FlowDownloadManager] Failed getting session cookies:', err.message);
      }
    }

    const headers = {
      'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
      'Referer': 'https://flow.google.com/',
    };
    if (cookieHeader) {
      headers['Cookie'] = cookieHeader;
    }

    await new Promise((resolve, reject) => {
      const file = fs.createWriteStream(tempPath);
      const client = url.startsWith('https') ? https : http;

      const req = client.get(url, { headers }, (res) => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          file.close();
          fs.unlink(tempPath, () => {});
          return this.downloadUrl(res.headers.location, attemptId, ext).then(resolve).catch(reject);
        }
        if (res.statusCode !== 200) {
          file.close();
          fs.unlink(tempPath, () => {});
          return reject(new Error(`Download failed with HTTP status ${res.statusCode}`));
        }
        res.pipe(file);
        file.on('finish', () => {
          file.close(resolve);
        });
      });

      req.on('error', (err) => {
        file.close();
        fs.unlink(tempPath, () => {});
        reject(err);
      });
      req.setTimeout(60000, () => {
        req.destroy();
        file.close();
        fs.unlink(tempPath, () => {});
        reject(new Error('Download timed out after 60 seconds'));
      });
    });

    if (attempt) {
      attempt.temp_path = tempPath;
      attempt.status = 'DOWNLOADED';
    }
    return tempPath;
  }

  /**
   * Check image binary header. Rejects HTML / text error bodies.
   * @param {string} filePath
   * @returns {boolean}
   */
  static isImageHeaderValid(filePath) {
    try {
      const fd = fs.openSync(filePath, 'r');
      const buf = Buffer.alloc(16);
      fs.readSync(fd, buf, 0, 16, 0);
      fs.closeSync(fd);

      // PNG: 89 50 4E 47
      if (buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4E && buf[3] === 0x47) return true;
      // JPEG: FF D8 FF
      if (buf[0] === 0xFF && buf[1] === 0xD8 && buf[2] === 0xFF) return true;
      // WEBP: RIFF .... WEBP
      if (buf.toString('ascii', 0, 4) === 'RIFF' && buf.toString('ascii', 8, 12) === 'WEBP') return true;

      // Reject HTML / Error signatures
      const head = buf.toString('utf8').toLowerCase();
      if (head.includes('<!doc') || head.includes('<html') || head.includes('{') || head.includes('error')) {
        return false;
      }
      return false;
    } catch {
      return false;
    }
  }

  /**
   * Deep media verification using ffprobe.
   * @param {string} filePath
   * @param {'image'|'video'} type
   * @returns {Promise<{valid: boolean, width: number, height: number, duration: number, error?: string}>}
   */
  async verifyMedia(filePath, type = 'image') {
    if (!fs.existsSync(filePath)) {
      return { valid: false, width: 0, height: 0, duration: 0, error: 'File does not exist' };
    }

    const stat = fs.statSync(filePath);
    if (stat.size <= 0) {
      return { valid: false, width: 0, height: 0, duration: 0, error: 'File is zero bytes' };
    }

    // Check image headers immediately for images
    if (type === 'image') {
      if (!FlowDownloadManager.isImageHeaderValid(filePath)) {
        return { valid: false, width: 0, height: 0, duration: 0, error: 'FLOW_MEDIA_INVALID: Not a valid image binary header' };
      }
    }

    try {
      const ffprobeBin = getFfprobePath();
      const { stdout } = await execFileAsync(ffprobeBin, [
        '-v', 'error',
        '-select_streams', 'v:0',
        '-show_entries', 'stream=width,height,duration,codec_name:format=duration,size',
        '-of', 'json',
        filePath,
      ], { timeout: 15000 });

      const parsed = JSON.parse(stdout);
      const stream = (parsed.streams && parsed.streams[0]) || {};
      const format = parsed.format || {};

      const width = Number(stream.width) || 0;
      const height = Number(stream.height) || 0;
      let duration = Number(stream.duration) || Number(format.duration) || 0;

      if (width <= 0 || height <= 0) {
        return { valid: false, width, height, duration, error: 'FLOW_MEDIA_INVALID: Invalid dimensions' };
      }

      if (type === 'video') {
        if (duration <= 0) {
          return { valid: false, width, height, duration, error: 'FLOW_MEDIA_INVALID: Video duration must be greater than 0' };
        }
      }

      return { valid: true, width, height, duration };
    } catch (err) {
      return {
        valid: false,
        width: 0,
        height: 0,
        duration: 0,
        error: `FLOW_MEDIA_INVALID: ffprobe verification failed: ${err.message}`,
      };
    }
  }

  /**
   * Finalize attempt: verify media and rename to canonical file path.
   * @param {string} attemptId
   * @param {string} [sourceFilePath] Optional explicit source file if not from direct download
   * @returns {Promise<{path: string, size: number, width: number, height: number, duration: number, checksum: string}>}
   */
  async finalizeAttempt(attemptId, sourceFilePath = null) {
    const attempt = this.activeAttempts.get(attemptId);
    if (!attempt) {
      throw new Error(`Attempt ${attemptId} not found in Download Manager`);
    }

    const srcPath = sourceFilePath || attempt.temp_path;
    if (!srcPath || !fs.existsSync(srcPath)) {
      throw new Error(`FLOW_DOWNLOAD_FAILED: Source file does not exist for attempt ${attemptId}`);
    }

    const isVideo = attempt.generation_type === 'video';
    const verifyType = isVideo ? 'video' : 'image';
    const check = await this.verifyMedia(srcPath, verifyType);
    if (!check.valid) {
      attempt.status = 'FAILED';
      attempt.error = check.error;
      throw new Error(check.error || 'FLOW_MEDIA_INVALID');
    }

    // Classify actual downloaded resolution from width & height
    const maxDim = Math.max(check.width, check.height);
    let actualFlowRes = '1080p';
    if (maxDim >= 3000) {
      actualFlowRes = '4K';
    } else if (maxDim >= 2000) {
      actualFlowRes = '2K';
    } else {
      actualFlowRes = '1080p';
    }
    attempt.actual_flow_resolution = actualFlowRes;
    attempt.actual_downloaded_width = check.width;
    attempt.actual_downloaded_height = check.height;

    // Determine canonical destination
    fs.mkdirSync(attempt.target_dir, { recursive: true });
    let finalFilename = '';
    const rawExt = (path.extname(srcPath) || '').replace('.', '').toLowerCase();
    const ext = isVideo ? 'mp4' : (['png', 'jpg', 'jpeg', 'webp'].includes(rawExt) ? (rawExt === 'jpeg' ? 'jpg' : rawExt) : 'png');

    if (attempt.generation_type === 'character_ref') {
      const charId = attempt.character_id || 'CHAR_001';
      finalFilename = `${charId}_ref_${attempt.attempt_id.slice(-6)}.${ext}`;
    } else {
      // Normal scene: 001-<slug>.ext
      const sceneId = String(attempt.scene_id).padStart(3, '0');
      const slug = (attempt.slug || 'scene').replace(/[^a-z0-9_-]/gi, '').toLowerCase();
      finalFilename = `${sceneId}-${slug}.${ext}`;
    }

    const finalPath = path.join(attempt.target_dir, finalFilename);

    // Atomically move/copy to final path
    fs.copyFileSync(srcPath, finalPath);
    try {
      fs.unlinkSync(srcPath);
    } catch {}

    const stat = fs.statSync(finalPath);
    const checksum = crypto.createHash('sha256').update(fs.readFileSync(finalPath)).digest('hex');

    attempt.final_path = finalPath;
    this.pathToAttempt.set(finalPath, attemptId);
    attempt.status = 'READY';

    return {
      ok: true,
      path: finalPath,
      filename: finalFilename,
      size: stat.size,
      width: check.width,
      height: check.height,
      duration: check.duration,
      checksum,
      actual_flow_resolution: actualFlowRes,
      requested_flow_resolution: attempt.flow_download_resolution || '1080p',
    };
  }
}

module.exports = { FlowDownloadManager };
