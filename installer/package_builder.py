"""
package_builder.py
Automated Production Packaging Pipeline for VibeCode Studio v2.2.3.18.
- Bundles all required runtime code, assets, platform scripts, updater, and documentation.
- Strictly filters out developer files, git history, cache, debug logs, and test videos.
- Computes cryptographic SHA-256 hashes and generates release_metadata.json.
"""

import os
import sys
import json
import hashlib
import zipfile
import shutil
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
DIST_DIR = ROOT_DIR / "dist"
DIST_DIR.mkdir(exist_ok=True)

sys.path.insert(0, str(ROOT_DIR))
from version import __version__ as APP_VERSION

CORE_FILES = [
    "app.py",
    "camera_engine.py",
    "diagnostic_collector.py",
    "ffmpeg_utils.py",
    "forced_alignment_engine.py",
    "generate_voice.py",
    "generate_warm_voice.py",
    "license_manager.py",
    "renderer_e_engine.py",
    "renderer_g.py",
    "subtitles_engine.py",
    "subpixel_affine_engine.py",
    "translation_utils.py",
    "tts_utils.py",
    "version.py",
    "requirements.txt",
    "start_windows.bat",
    "start_windows.ps1",
    "start_mac.command",
    "SlideshowStudio.vbs",
    "run.bat",
    "README.md",
    "CHANGELOG.md",
]

CORE_DIRS = [
    "templates",
    "static",
    "platform",
    "updater",
    "docs",
    "installer"
]

EXCLUDE_PATTERNS = {
    ".git",
    ".github",
    ".pytest_cache",
    "__pycache__",
    ".DS_Store",
    "Thumbs.db",
    "tests",
    "temp",
    "uploads",
    "outputs",
    "tts_outputs",
    "projects",
    "diagnostics",
    "website",
    "node_modules",
    ".venv",
}

WIN_ONLY_FILES = {
    "start_windows.bat",
    "start_windows.ps1",
    "SlideshowStudio.vbs",
    "run.bat",
}

MAC_ONLY_FILES = {
    "start_mac.command",
}


def compute_sha256(filepath: Path) -> str:
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest().lower()


def build_package():
    win_zip_name = f"SlideshowBuilder_Windows_v{APP_VERSION}.zip"
    win_zip_path = DIST_DIR / win_zip_name
    win_latest_path = DIST_DIR / "SlideshowBuilder_Windows_latest.zip"

    mac_zip_name = f"SlideshowBuilder_macOS_v{APP_VERSION}.zip"
    mac_zip_path = DIST_DIR / mac_zip_name
    mac_latest_path = DIST_DIR / "SlideshowBuilder_macOS_latest.zip"

    print(f"📦 [Package Builder] Packaging VibeCode Studio v{APP_VERSION}...")

    # 1. Build Windows ZIP (Excludes Mac-only scripts)
    win_core_files = [f for f in CORE_FILES if f not in MAC_ONLY_FILES]
    with zipfile.ZipFile(win_zip_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for fname in win_core_files:
            fpath = ROOT_DIR / fname
            if fpath.is_file():
                zf.write(fpath, arcname=fname)
        for dname in CORE_DIRS:
            dpath = ROOT_DIR / dname
            if dpath.is_dir():
                for root, dirs, files in os.walk(dpath):
                    dirs[:] = [d for d in dirs if d not in EXCLUDE_PATTERNS]
                    for file in files:
                        if file in EXCLUDE_PATTERNS or file.endswith((".pyc", ".mp4", ".wav", ".png.tmp")):
                            continue
                        full_p = Path(root) / file
                        rel_p = full_p.relative_to(ROOT_DIR)
                        zf.write(full_p, arcname=str(rel_p))

    # 2. Build macOS ZIP (Excludes Windows batch/vbs scripts)
    mac_core_files = [f for f in CORE_FILES if f not in WIN_ONLY_FILES]
    with zipfile.ZipFile(mac_zip_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for fname in mac_core_files:
            fpath = ROOT_DIR / fname
            if fpath.is_file():
                zf.write(fpath, arcname=fname)
        for dname in CORE_DIRS:
            dpath = ROOT_DIR / dname
            if dpath.is_dir():
                for root, dirs, files in os.walk(dpath):
                    dirs[:] = [d for d in dirs if d not in EXCLUDE_PATTERNS]
                    for file in files:
                        if file in EXCLUDE_PATTERNS or file.endswith((".pyc", ".mp4", ".wav", ".png.tmp", ".bat", ".vbs", ".ps1")):
                            continue
                        full_p = Path(root) / file
                        rel_p = full_p.relative_to(ROOT_DIR)
                        zf.write(full_p, arcname=str(rel_p))

    # Update latest aliases
    shutil.copy2(win_zip_path, win_latest_path)
    shutil.copy2(mac_zip_path, mac_latest_path)

    win_sha256 = compute_sha256(win_zip_path)
    win_size_mb = win_zip_path.stat().st_size / (1024 * 1024)

    mac_sha256 = compute_sha256(mac_zip_path)
    mac_size_mb = mac_zip_path.stat().st_size / (1024 * 1024)

    # Write checksums file
    checksum_file = DIST_DIR / "checksums.sha256"
    with open(checksum_file, "w", encoding="utf-8") as f:
        f.write(f"{win_sha256}  {win_zip_name}\n")
        f.write(f"{win_sha256}  SlideshowBuilder_Windows_latest.zip\n")
        f.write(f"{mac_sha256}  {mac_zip_name}\n")
        f.write(f"{mac_sha256}  SlideshowBuilder_macOS_latest.zip\n")

    # Write release metadata
    metadata = {
        "version": APP_VERSION,
        "release_tag": f"v{APP_VERSION}",
        "build_target": "Windows 10/11 x64 & macOS Darwin",
        "sha256": win_sha256,
        "packages": {
            "windows": {
                "package_filename": win_zip_name,
                "sha256": win_sha256,
                "size_mb": round(win_size_mb, 2),
                "size_bytes": win_zip_path.stat().st_size
            },
            "mac": {
                "package_filename": mac_zip_name,
                "sha256": mac_sha256,
                "size_mb": round(mac_size_mb, 2),
                "size_bytes": mac_zip_path.stat().st_size
            }
        },
        "renderer_default": "Renderer G (Glide GPU Subpixel)",
        "renderer_fallback": "Renderer D (Golden Baseline 4X)",
        "mandatory_license_gate": "ENABLED",
        "security_hardened": "ENABLED",
        "client_diagnostics": "ENABLED"
    }

    metadata_file = DIST_DIR / "release_metadata.json"
    with open(metadata_file, "w", encoding="utf-8") as f:
        json.dump(metadata, f, indent=2, ensure_ascii=False)

    print(f"✅ Windows Package built: {win_zip_name} ({win_size_mb:.2f} MB)")
    print(f"🔒 Win SHA-256: {win_sha256}")
    print(f"✅ macOS Package built: {mac_zip_name} ({mac_size_mb:.2f} MB)")
    print(f"🔒 Mac SHA-256: {mac_sha256}")
    print(f"📄 Metadata generated at: {metadata_file}")


if __name__ == "__main__":
    build_package()
