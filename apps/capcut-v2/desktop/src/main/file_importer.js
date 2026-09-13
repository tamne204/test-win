/**
 * apps/capcut-v2/desktop/src/main/file_importer.js
 * Imports images, scans directories recursively, and extracts .zip / .rar / .7z archives.
 */
const fs = require('fs');
const path = require('path');
const os = require('os');
const { execFile } = require('child_process');

let path7za = null;
try {
  path7za = require('7zip-bin').path7za;
} catch (e) {
  console.warn('[FileImporter] 7zip-bin not found, fallback to system unzip if available');
}

const SUPPORTED_IMAGE_EXTS = new Set([
  '.jpg',
  '.jpeg',
  '.png',
  '.webp',
  '.bmp',
  '.tiff',
  '.tif',
  '.jfif',
]);

const ARCHIVE_EXTS = new Set([
  '.zip',
  '.rar',
  '.7z',
  '.tar',
  '.gz',
]);

class FileImporter {
  constructor(extractBaseDir) {
    this.extractBaseDir = extractBaseDir || path.join(os.homedir(), '.2toolne-autoedit', 'extracted');
    if (!fs.existsSync(this.extractBaseDir)) {
      try {
        fs.mkdirSync(this.extractBaseDir, { recursive: true });
      } catch (err) {
        console.warn('[FileImporter] Cannot create extractBaseDir:', err.message);
      }
    }
  }

  async processPaths(rawPaths) {
    const images = [];
    const visited = new Set();
    const errors = [];

    for (const rawPath of rawPaths) {
      if (!rawPath || !fs.existsSync(rawPath)) continue;

      try {
        const stat = fs.statSync(rawPath);
        if (stat.isDirectory()) {
          this.scanDirectory(rawPath, images, visited);
        } else if (stat.isFile()) {
          const ext = path.extname(rawPath).toLowerCase();
          if (SUPPORTED_IMAGE_EXTS.has(ext)) {
            const resolved = path.resolve(rawPath);
            if (!visited.has(resolved)) {
              visited.add(resolved);
              images.push(resolved);
            }
          } else if (ARCHIVE_EXTS.has(ext)) {
            try {
              const outDir = path.join(
                this.extractBaseDir,
                `${path.basename(rawPath, ext)}_${Date.now()}`
              );
              await this.extractArchive(rawPath, outDir);
              this.scanDirectory(outDir, images, visited);
            } catch (archiveErr) {
              errors.push(`Lỗi giải nén ${path.basename(rawPath)}: ${archiveErr.message}`);
            }
          }
        }
      } catch (err) {
        errors.push(`Lỗi đọc đường dẫn ${rawPath}: ${err.message}`);
      }
    }

    return {
      images,
      totalCount: images.length,
      errors,
    };
  }

  scanDirectory(dirPath, images, visited) {
    try {
      const entries = fs.readdirSync(dirPath, { withFileTypes: true });
      for (const entry of entries) {
        if (entry.name.startsWith('.')) continue; // ignore hidden
        const fullPath = path.join(dirPath, entry.name);
        if (entry.isDirectory()) {
          this.scanDirectory(fullPath, images, visited);
        } else if (entry.isFile()) {
          const ext = path.extname(entry.name).toLowerCase();
          if (SUPPORTED_IMAGE_EXTS.has(ext)) {
            const resolved = path.resolve(fullPath);
            if (!visited.has(resolved)) {
              visited.add(resolved);
              images.push(resolved);
            }
          }
        }
      }
    } catch (e) {
      console.warn(`[FileImporter] Cannot scan directory ${dirPath}:`, e.message);
    }
  }

  extractArchive(archivePath, targetDir) {
    return new Promise((resolve, reject) => {
      if (!fs.existsSync(targetDir)) {
        fs.mkdirSync(targetDir, { recursive: true });
      }

      if (path7za && fs.existsSync(path7za)) {
        execFile(path7za, ['x', '-y', `-o${targetDir}`, archivePath], { windowsHide: true }, (err, stdout, stderr) => {
          if (err) {
            reject(new Error(stderr || err.message));
          } else {
            resolve(targetDir);
          }
        });
      } else {
        // Fallback for macOS/Linux zip
        execFile('unzip', ['-o', archivePath, '-d', targetDir], { windowsHide: true }, (err, stdout, stderr) => {
          if (err) {
            reject(new Error(stderr || err.message));
          } else {
            resolve(targetDir);
          }
        });
      }
    });
  }
}

module.exports = {
  FileImporter,
  SUPPORTED_IMAGE_EXTS,
  ARCHIVE_EXTS,
};
