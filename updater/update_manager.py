"""
update_manager.py
Main orchestrator for checking, downloading, verifying, and applying updates.
"""

import os
import sys
import shutil
import zipfile
import requests
from typing import Dict, Any, Optional, Callable
from .version_manager import get_current_version, is_newer_version
from .github_release_client import fetch_latest_release
from .platform_detector import get_platform_tag
from .checksum import verify_sha256
from .rollback_manager import create_backup, restore_backup

# Cache last check result in memory
_last_check: Optional[Dict[str, Any]] = None


class UpdateManager:
    """Orchestrates application update lifecycle."""

    def __init__(self, root_dir: Optional[str] = None):
        self.root_dir = root_dir or os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        self.temp_dir = os.path.join(self.root_dir, "temp")
        self.backups_dir = os.path.join(self.root_dir, "backups")
        os.makedirs(self.temp_dir, exist_ok=True)
        os.makedirs(self.backups_dir, exist_ok=True)

    def is_dev_mode(self) -> bool:
        """
        Return True if running in active development environment (git repository or env flag).
        In dev mode, live code overwriting is protected.
        """
        if os.environ.get("SLIDESHOW_DEV_MODE", "").lower() in ("1", "true", "yes"):
            return True
        # If running inside a git working directory and not explicitly set to production
        if os.path.isdir(os.path.join(self.root_dir, ".git")) and not os.environ.get("SLIDESHOW_FORCE_PROD"):
            return True
        return False

    def check_for_updates(self, force: bool = False) -> Dict[str, Any]:
        """
        Check if a newer stable release is available on GitHub / Proxy.
        Returns:
            {
                "update_available": bool,
                "current_version": str,
                "latest_version": str,
                "release_notes": str,
                "release_url": str,
                "published_at": str,
                "asset": Optional[dict],
                "error": Optional[str],
                "is_dev_mode": bool
            }
        """
        global _last_check
        current_v = get_current_version()
        plat_tag = get_platform_tag()

        rel_data = fetch_latest_release()
        if not rel_data.get("ok"):
            res = {
                "update_available": False,
                "current_version": current_v,
                "latest_version": current_v,
                "release_notes": "",
                "release_url": "",
                "published_at": "",
                "asset": None,
                "error": rel_data.get("message", rel_data.get("error")),
                "is_dev_mode": self.is_dev_mode()
            }
            _last_check = res
            return res

        latest_v = rel_data.get("version", current_v)
        has_update = is_newer_version(latest_v, current_v)

        # Match best asset for current platform
        matched_asset = None
        for a in rel_data.get("assets", []):
            if a.get("platform_tag") == plat_tag:
                matched_asset = a
                break
        
        # Fallback to general update zip if platform-specific binary not found
        if not matched_asset:
            for a in rel_data.get("assets", []):
                if a.get("name", "").endswith(".zip") and "update" in a.get("name", "").lower():
                    matched_asset = a
                    break

        res = {
            "update_available": has_update,
            "current_version": current_v,
            "latest_version": latest_v,
            "release_notes": rel_data.get("release_notes", ""),
            "release_url": rel_data.get("html_url", ""),
            "published_at": rel_data.get("published_at", ""),
            "asset": matched_asset,
            "error": None,
            "is_dev_mode": self.is_dev_mode()
        }
        _last_check = res
        return res

    def download_update(
        self,
        download_url: str,
        dest_filename: str = "latest_update.zip",
        progress_callback: Optional[Callable[[int, str], None]] = None
    ) -> str:
        """
        Download update zip archive with progress callback.
        Returns absolute path to downloaded file.
        """
        dest_path = os.path.join(self.temp_dir, dest_filename)
        headers = {"User-Agent": "TamneSlideshowUpdater/2.0"}

        resp = requests.get(download_url, stream=True, headers=headers, timeout=30)
        resp.raise_for_status()

        total_bytes = int(resp.headers.get("content-length", 0))
        downloaded = 0

        with open(dest_path, "wb") as f:
            for chunk in resp.iter_content(chunk_size=65536):
                if not chunk:
                    continue
                f.write(chunk)
                downloaded += len(chunk)
                if progress_callback and total_bytes > 0:
                    pct = min(100, int((downloaded / total_bytes) * 100))
                    progress_callback(pct, f"Đang tải bản cập nhật: {pct}% ({downloaded // 1024} KB)")

        if progress_callback:
            progress_callback(100, "Tải bản cập nhật hoàn tất!")

        return dest_path

    def verify_and_stage_update(
        self,
        zip_path: str,
        expected_sha256: Optional[str] = None
    ) -> str:
        """
        Verify checksum and extract update into a clean staging folder.
        Returns path to staging folder.
        """
        if expected_sha256 and not verify_sha256(zip_path, expected_sha256):
            raise ValueError(f"Checksum SHA-256 mismatch! File might be corrupted.")

        staging_dir = os.path.join(self.temp_dir, "staging")
        if os.path.isdir(staging_dir):
            shutil.rmtree(staging_dir, ignore_errors=True)
        os.makedirs(staging_dir, exist_ok=True)

        with zipfile.ZipFile(zip_path, "r") as zf:
            zf.extractall(staging_dir)

        # Validate staging directory contains essential application entry points
        if not os.path.isfile(os.path.join(staging_dir, "app.py")) and not os.path.isfile(os.path.join(staging_dir, "version.py")):
            # Look inside single nested root folder if zip wrapped all files in a folder
            subdirs = [os.path.join(staging_dir, d) for d in os.listdir(staging_dir) if os.path.isdir(os.path.join(staging_dir, d))]
            if len(subdirs) == 1 and os.path.isfile(os.path.join(subdirs[0], "app.py")):
                return subdirs[0]
            raise ValueError("Invalid update package structure: app.py missing.")

        return staging_dir

    def apply_update_atomic(self, staging_dir: str) -> Dict[str, Any]:
        """
        Perform atomic copy from staging_dir into root_dir with rollback safeguard.
        """
        current_v = get_current_version()
        backup_path = create_backup(self.root_dir, current_v, self.backups_dir)

        try:
            # Copy all files from staging into root
            for item in os.listdir(staging_dir):
                if item in ("__pycache__", ".git", ".venv", "backups", "temp", "uploads", "outputs"):
                    continue
                s_item = os.path.join(staging_dir, item)
                d_item = os.path.join(self.root_dir, item)

                if os.path.isdir(s_item):
                    shutil.copytree(s_item, d_item, dirs_exist_ok=True)
                else:
                    shutil.copy2(s_item, d_item)

            return {
                "ok": True,
                "message": "Cập nhật thành công! Vui lòng khởi động lại ứng dụng.",
                "backup_path": backup_path
            }
        except Exception as e:
            # Rollback immediately on error
            restore_backup(backup_path, self.root_dir)
            return {
                "ok": False,
                "error": f"Lỗi trong quá trình cập nhật: {e}. Đã khôi phục phiên bản trước."
            }


def check_for_updates() -> Dict[str, Any]:
    """Helper function to check updates with default UpdateManager."""
    mgr = UpdateManager()
    return mgr.check_for_updates()
