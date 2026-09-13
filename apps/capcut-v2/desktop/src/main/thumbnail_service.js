/**
 * apps/capcut-v2/desktop/src/main/thumbnail_service.js
 *
 * High-Performance Bounded Thumbnail Service with Deterministic Disk Cache.
 *
 * Requirements:
 * 1. Generates aspect-ratio preserved thumbnails (max dimension 384px) for media grid.
 * 2. Strictly read-only on original media files (preserves original 4K integrity).
 * 3. Bounded concurrency (THUMBNAIL_CONCURRENCY = 3) prevents I/O saturation and memory spikes.
 * 4. Deterministic SHA-256 disk cache under ~/.2toolne-autoedit/thumbnails/.
 * 5. Bounded cache lifecycle with LRU cleanup (default 500 MB cap).
 * 6. Non-blocking asynchronous processing with incremental progress / ready events.
 * 7. Graceful error handling (corrupted images return null without failing entire import).
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const { spawn } = require('child_process');
const EventEmitter = require('events');

let binResolver = null;
try {
  binResolver = require('./bin_resolver');
} catch (e) {
  // May be required in test environment
}

class ThumbnailService extends EventEmitter {
  constructor(options = {}) {
    super();
    this.cacheDir = options.cacheDir || path.join(os.homedir(), '.2toolne-autoedit', 'thumbnails');
    this.maxDimension = options.maxDimension || 384;
    this.concurrency = options.concurrency || 3;
    this.maxCacheSizeBytes = options.maxCacheSizeBytes || 500 * 1024 * 1024; // 500 MB

    this.queue = [];
    this.activeWorkers = 0;
    this.inFlight = new Map(); // cacheKey -> Promise<{ originalPath, thumbnailPath, ... }>
    this.memoryCache = new Map(); // cacheKey -> { thumbnailPath, width, height }

    this.ensureCacheDir();
  }

  ensureCacheDir() {
    try {
      if (!fs.existsSync(this.cacheDir)) {
        fs.mkdirSync(this.cacheDir, { recursive: true });
      }
    } catch (err) {
      console.warn('[ThumbnailService] Failed to create cache directory:', err.message);
    }
  }

  getFfmpegPath() {
    if (binResolver && typeof binResolver.resolveFfmpeg === 'function') {
      try {
        const resolved = binResolver.resolveFfmpeg();
        if (resolved && resolved.path && fs.existsSync(resolved.path)) {
          return resolved.path;
        }
      } catch (e) {}
    }
    // Fallback to system ffmpeg if available
    return 'ffmpeg';
  }

  generateCacheKey(filePath, stat) {
    const resolved = path.resolve(filePath);
    const content = `${resolved}|${stat.size}|${stat.mtimeMs}`;
    return crypto.createHash('sha256').update(content).digest('hex');
  }

  async getThumbnail(originalPath) {
    if (!originalPath || typeof originalPath !== 'string') {
      return { originalPath, thumbnailPath: null, error: 'Invalid path' };
    }

    const resolved = path.resolve(originalPath);
    let stat;
    try {
      stat = fs.statSync(resolved);
      if (!stat.isFile()) {
        return { originalPath: resolved, thumbnailPath: null, error: 'Not a file' };
      }
    } catch (err) {
      return { originalPath: resolved, thumbnailPath: null, error: err.message };
    }

    const cacheKey = this.generateCacheKey(resolved, stat);
    const thumbFilename = `${cacheKey}.jpg`;
    const thumbPath = path.join(this.cacheDir, thumbFilename);

    // 1. Check memory cache
    if (this.memoryCache.has(cacheKey)) {
      const cached = this.memoryCache.get(cacheKey);
      if (fs.existsSync(cached.thumbnailPath)) {
        return {
          originalPath: resolved,
          thumbnailPath: cached.thumbnailPath,
          width: cached.width,
          height: cached.height,
          cacheKey,
        };
      }
    }

    // 2. Check disk cache
    if (fs.existsSync(thumbPath)) {
      try {
        const thumbStat = fs.statSync(thumbPath);
        if (thumbStat.size > 0) {
          // Touch atime for LRU
          const now = new Date();
          fs.utimesSync(thumbPath, now, thumbStat.mtime);
          const meta = {
            originalPath: resolved,
            thumbnailPath: thumbPath,
            width: this.maxDimension,
            height: this.maxDimension,
            cacheKey,
          };
          this.memoryCache.set(cacheKey, meta);
          return meta;
        }
      } catch (e) {}
    }

    // 3. Deduplicate in-flight generation
    if (this.inFlight.has(cacheKey)) {
      return await this.inFlight.get(cacheKey);
    }

    // 4. Queue for background bounded generation
    const promise = new Promise((resolve) => {
      this.queue.push({
        originalPath: resolved,
        thumbPath,
        cacheKey,
        resolve,
      });
      this.processQueue();
    });

    this.inFlight.set(cacheKey, promise);

    try {
      const result = await promise;
      return result;
    } finally {
      this.inFlight.delete(cacheKey);
    }
  }

  async getThumbnailsBatch(originalPaths) {
    if (!Array.isArray(originalPaths)) return [];
    return Promise.all(originalPaths.map((p) => this.getThumbnail(p)));
  }

  processQueue() {
    while (this.activeWorkers < this.concurrency && this.queue.length > 0) {
      const task = this.queue.shift();
      this.activeWorkers++;
      this.executeThumbnailGeneration(task).finally(() => {
        this.activeWorkers--;
        this.processQueue();
      });
    }
  }

  async executeThumbnailGeneration(task) {
    const { originalPath, thumbPath, cacheKey, resolve } = task;

    try {
      this.ensureCacheDir();
      const tmpThumbPath = `${thumbPath}.tmp_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;

      // Execute downscale via FFmpeg
      await this.runFfmpegDownscale(originalPath, tmpThumbPath);

      if (fs.existsSync(tmpThumbPath) && fs.statSync(tmpThumbPath).size > 0) {
        fs.renameSync(tmpThumbPath, thumbPath);

        const result = {
          originalPath,
          thumbnailPath: thumbPath,
          width: this.maxDimension,
          height: this.maxDimension,
          cacheKey,
        };

        this.memoryCache.set(cacheKey, result);
        this.emit('ready', result);
        resolve(result);

        // Opportunistic cache size check
        this.pruneCacheIfNeeded();
      } else {
        throw new Error('Thumbnail generation produced empty file');
      }
    } catch (err) {
      console.warn(`[ThumbnailService] Thumbnail failed for ${originalPath}:`, err.message);
      const failResult = {
        originalPath,
        thumbnailPath: null,
        error: err.message,
        cacheKey,
      };
      if (this.listenerCount('error') > 0) {
        this.emit('error', failResult);
      }
      this.emit('failed', failResult);
      resolve(failResult);
    }
  }

  runFfmpegDownscale(inputPath, outputPath) {
    return new Promise((resolve, reject) => {
      const ffmpegBin = this.getFfmpegPath();
      const maxDim = this.maxDimension;

      // Aspect ratio preserving downscale filter:
      // Fits within maxDim x maxDim without distortion or stretching
      const filter = `scale=${maxDim}:${maxDim}:force_original_aspect_ratio=decrease`;

      const args = [
        '-y',
        '-v', 'error',
        '-i', inputPath,
        '-vf', filter,
        '-q:v', '3', // High quality JPEG
        outputPath,
      ];

      const proc = spawn(ffmpegBin, args, { windowsHide: true });
      let stderr = '';

      proc.stderr.on('data', (d) => {
        stderr += d.toString();
      });

      proc.on('close', (code) => {
        if (code === 0 && fs.existsSync(outputPath)) {
          resolve();
        } else {
          // Attempt fallback to macOS sips if on darwin
          if (process.platform === 'darwin') {
            this.runSipsFallback(inputPath, outputPath).then(resolve).catch(reject);
          } else {
            reject(new Error(`FFmpeg exited with code ${code}: ${stderr}`));
          }
        }
      });

      proc.on('error', (err) => {
        if (process.platform === 'darwin') {
          this.runSipsFallback(inputPath, outputPath).then(resolve).catch(reject);
        } else {
          reject(err);
        }
      });
    });
  }

  runSipsFallback(inputPath, outputPath) {
    return new Promise((resolve, reject) => {
      const args = ['-s', 'format', 'jpeg', '-Z', String(this.maxDimension), inputPath, '--out', outputPath];
      const proc = spawn('sips', args, { windowsHide: true });
      proc.on('close', (code) => {
        if (code === 0 && fs.existsSync(outputPath)) {
          resolve();
        } else {
          reject(new Error(`sips fallback failed with exit code ${code}`));
        }
      });
      proc.on('error', reject);
    });
  }

  pruneCacheIfNeeded() {
    try {
      const files = fs.readdirSync(this.cacheDir);
      let totalBytes = 0;
      const fileStats = [];

      for (const file of files) {
        if (file.endsWith('.jpg') || file.endsWith('.jpeg')) {
          const filePath = path.join(this.cacheDir, file);
          try {
            const st = fs.statSync(filePath);
            totalBytes += st.size;
            fileStats.push({ filePath, size: st.size, atime: st.atimeMs || st.mtimeMs });
          } catch (e) {}
        }
      }

      if (totalBytes > this.maxCacheSizeBytes) {
        // Sort oldest accessed first (LRU)
        fileStats.sort((a, b) => a.atime - b.atime);

        let removedBytes = 0;
        const targetBytes = this.maxCacheSizeBytes * 0.8; // Prune to 80% capacity

        for (const item of fileStats) {
          if (totalBytes - removedBytes <= targetBytes) break;
          try {
            fs.unlinkSync(item.filePath);
            removedBytes += item.size;
          } catch (e) {}
        }
      }
    } catch (e) {
      console.warn('[ThumbnailService] Pruning cache error:', e.message);
    }
  }

  clearMemoryCache() {
    this.memoryCache.clear();
  }
}

module.exports = {
  ThumbnailService,
};
