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
    this.pendingDownloads = new Map(); // download item url/filename -> attempt_id

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
   */
  registerAttempt(attempt) {
    if (!attempt.attempt_id) {
      throw new Error('Attempt ID is required to register download attempt');
    }
    const record = {
      attempt_id: attempt.attempt_id,
      pipeline_job_id: attempt.pipeline_job_id,
      scene_id: attempt.scene_id || '001',
      generation_type: attempt.generation_type || 'image',
      slug: attempt.slug || 'scene',
      target_dir: attempt.target_dir,
      character_id: attempt.character_id || null,
      created_at: Date.now(),
      status: 'REGISTERED',
      temp_path: null,
      final_path: null,
    };
    this.activeAttempts.set(attempt.attempt_id, record);
    return record;
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

    session.on('will-download', (event, item, webContents) => {
      const url = item.getURL();
      const filename = item.getFilename();
      const ext = path.extname(filename) || '.mp4';

      // Correlate with active attempt
      let matchedAttempt = null;
      for (const [attemptId, attempt] of this.activeAttempts.entries()) {
        if (attempt.status === 'REGISTERED' || attempt.status === 'DOWNLOADING') {
          matchedAttempt = attempt;
          break;
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

    await new Promise((resolve, reject) => {
      const file = fs.createWriteStream(tempPath);
      const client = url.startsWith('https') ? https : http;

      const req = client.get(url, { headers: { 'User-Agent': '2TOOLNE-Flow/2.0' } }, (res) => {
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

    // Determine canonical destination
    fs.mkdirSync(attempt.target_dir, { recursive: true });
    let finalFilename = '';
    const ext = isVideo ? 'mp4' : 'png';

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
    attempt.status = 'READY';

    return {
      path: finalPath,
      filename: finalFilename,
      size: stat.size,
      width: check.width,
      height: check.height,
      duration: check.duration,
      checksum,
    };
  }
}

module.exports = { FlowDownloadManager };
