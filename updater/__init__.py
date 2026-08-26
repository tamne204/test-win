"""
updater package
Decoupled auto-update system for Slideshow Builder AI.
"""

from .version_manager import get_current_version, compare_versions, is_newer_version, get_app_version
from .platform_detector import get_platform_tag, detect_os, detect_arch
from .checksum import compute_sha256, verify_sha256
from .github_release_client import fetch_latest_release
from .rollback_manager import create_backup, restore_backup
from .update_manager import UpdateManager, check_for_updates

__all__ = [
    "get_current_version",
    "compare_versions",
    "is_newer_version",
    "get_app_version",
    "get_platform_tag",
    "detect_os",
    "detect_arch",
    "compute_sha256",
    "verify_sha256",
    "fetch_latest_release",
    "create_backup",
    "restore_backup",
    "UpdateManager",
    "check_for_updates"
]
