"""
rollback_manager.py
Manages automated local backups before updates and rolls back cleanly on failure.
"""

import os
import shutil
import zipfile
import time
from typing import List, Optional


BACKUP_INCLUDE_FILES = [
    "app.py",
    "version.py",
    "ffmpeg_utils.py",
    "subtitles_engine.py",
    "tts_utils.py",
    "translation_utils.py",
    "forced_alignment_engine.py",
    "license_manager.py",
    "start_windows.bat",
    "requirements.txt"
]

BACKUP_INCLUDE_DIRS = [
    "templates",
    "static",
    "updater"
]


def create_backup(source_dir: str, current_version: str, backups_dir: Optional[str] = None) -> str:
    """
    Create a zip backup of the active application source code.
    Returns path to the created backup zip.
    """
    if backups_dir is None:
        backups_dir = os.path.join(source_dir, "backups")
    os.makedirs(backups_dir, exist_ok=True)

    timestamp = time.strftime("%Y%m%d_%H%M%S")
    backup_filename = f"backup_v{current_version}_{timestamp}.zip"
    backup_path = os.path.join(backups_dir, backup_filename)

    with zipfile.ZipFile(backup_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        # 1. Add single files
        for f in BACKUP_INCLUDE_FILES:
            full_path = os.path.join(source_dir, f)
            if os.path.isfile(full_path):
                zf.write(full_path, arcname=f)

        # 2. Add directories
        for d in BACKUP_INCLUDE_DIRS:
            d_path = os.path.join(source_dir, d)
            if os.path.isdir(d_path):
                for root, _, files in os.walk(d_path):
                    if "__pycache__" in root or ".pytest_cache" in root:
                        continue
                    for file in files:
                        full_f = os.path.join(root, file)
                        rel_f = os.path.relpath(full_f, source_dir)
                        zf.write(full_f, arcname=rel_f)

    # Cleanup older backups (keep last 3)
    cleanup_old_backups(backups_dir, keep_count=3)
    return backup_path


def restore_backup(backup_path: str, target_dir: str) -> bool:
    """
    Extract all files from a backup zip back into target_dir to perform a rollback.
    """
    if not os.path.isfile(backup_path):
        return False
    try:
        with zipfile.ZipFile(backup_path, "r") as zf:
            for member in zf.infolist():
                target_p = os.path.abspath(os.path.join(target_dir, member.filename))
                if not target_p.startswith(os.path.abspath(target_dir)):
                    raise ValueError(f"Malicious zip file entry: {member.filename}")
            zf.extractall(target_dir)
        return True
    except Exception as e:
        print(f"[Rollback] Failed to restore backup {backup_path}: {e}")
        return False


def cleanup_old_backups(backups_dir: str, keep_count: int = 3) -> None:
    """Retain only the latest `keep_count` backup archives."""
    if not os.path.isdir(backups_dir):
        return
    try:
        files = [
            os.path.join(backups_dir, f)
            for f in os.listdir(backups_dir)
            if f.startswith("backup_v") and f.endswith(".zip")
        ]
        files.sort(key=lambda x: os.path.getmtime(x))
        while len(files) > keep_count:
            oldest = files.pop(0)
            try:
                os.remove(oldest)
            except Exception:
                pass
    except Exception:
        pass
