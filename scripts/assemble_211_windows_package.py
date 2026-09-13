#!/usr/bin/env python3
"""
scripts/assemble_211_windows_package.py
Packages apps/capcut-v2/desktop/dist/win-unpacked into dist/2toolne-autoedit-2.1.1-win-x64.zip
with standard deflate compression, computes SHA256 and byte size.
"""
import os
import sys
import zipfile
import hashlib
import shutil
import json

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SOURCE_DIR = os.path.join(REPO_ROOT, "apps", "capcut-v2", "desktop", "dist", "win-unpacked")
OUTPUT_ZIP = os.path.join(REPO_ROOT, "dist", "2toolne-autoedit-2.1.1-win-x64.zip")

if not os.path.isdir(SOURCE_DIR):
    print(f"Error: Source directory {SOURCE_DIR} does not exist!")
    sys.exit(1)

print(f"Packaging {SOURCE_DIR} -> {OUTPUT_ZIP}...")

# Ensure dist/ exists
os.makedirs(os.path.dirname(OUTPUT_ZIP), exist_ok=True)

# Also ensure 2TOOLNE.exe exists as alias for 2TOOLNE AutoEdit.exe if present
alias_exe = os.path.join(SOURCE_DIR, "2TOOLNE.exe")
main_exe = os.path.join(SOURCE_DIR, "2TOOLNE AutoEdit.exe")
if os.path.exists(main_exe) and not os.path.exists(alias_exe):
    shutil.copy2(main_exe, alias_exe)

# Regression Guard: Assert zero _internal, zero .py, zero .pyc, zero .pyo
internal_dirs = [d for root, dirs, files in os.walk(SOURCE_DIR) for d in dirs if d == "_internal"]
if internal_dirs:
    print(f"FATAL: Regression guard failed! Detected PyInstaller _internal directory in release source: {internal_dirs}")
    sys.exit(1)

with zipfile.ZipFile(OUTPUT_ZIP, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
    for root, dirs, files in os.walk(SOURCE_DIR):
        for file in files:
            if file.endswith((".py", ".pyc", ".pyo")):
                print(f"FATAL: Plaintext Python file detected in release package: {file}")
                sys.exit(1)
            abs_path = os.path.join(root, file)
            rel_path = os.path.relpath(abs_path, SOURCE_DIR)
            zf.write(abs_path, rel_path)

file_size = os.path.getsize(OUTPUT_ZIP)
h = hashlib.sha256()
with open(OUTPUT_ZIP, "rb") as f:
    while chunk := f.read(1024 * 1024):
        h.update(chunk)
sha256 = h.hexdigest()

print(f"✓ Successfully built: {OUTPUT_ZIP}")
print(f"  Size Bytes: {file_size}")
print(f"  Size MB   : {round(file_size / (1024*1024), 2)} MB")
print(f"  SHA-256   : {sha256}")

metadata = {
    "version": "2.1.1",
    "filename": os.path.basename(OUTPUT_ZIP),
    "size_bytes": file_size,
    "size_mb": round(file_size / (1024*1024), 2),
    "sha256": sha256
}

meta_path = os.path.join(REPO_ROOT, "dist", "metadata_2.1.1.json")
with open(meta_path, "w", encoding="utf-8") as f:
    json.dump(metadata, f, indent=2)

print(f"✓ Metadata saved to: {meta_path}")
