"""
apps/capcut-v2/adapters/capcut/version_guard.py
Commercial Version Guard per Sections 7, 28, and 44 of CEO Master Directive.
Enforces strict binary identity locks for unattended CapCut render automation.
"""
from __future__ import annotations

import os
import sys
from typing import Dict, Any, Optional

try:
    from .render_profile import RenderProfile, RenderProfileRegistry, compute_file_sha256
except (ImportError, ValueError):
    from adapters.capcut.render_profile import RenderProfile, RenderProfileRegistry, compute_file_sha256


class VersionGuardResult:
    def __init__(
        self,
        is_supported: bool,
        error_code: Optional[str] = None,
        error_message: Optional[str] = None,
        detected_version: Optional[str] = None,
        detected_sha256: Optional[str] = None,
        profile: Optional[RenderProfile] = None,
    ):
        self.is_supported = is_supported
        self.error_code = error_code
        self.error_message = error_message
        self.detected_version = detected_version
        self.detected_sha256 = detected_sha256
        self.profile = profile

    def to_dict(self) -> Dict[str, Any]:
        return {
            "is_supported": self.is_supported,
            "error_code": self.error_code,
            "error_message": self.error_message,
            "detected_version": self.detected_version,
            "detected_sha256": self.detected_sha256,
            "profile_id": self.profile.profile_id if self.profile else None,
        }


class CapCutVersionGuard:
    """
    Enforces strict executable identity checks before allowing native render automation.
    """

    @classmethod
    def inspect_executable(cls, exe_path: str) -> VersionGuardResult:
        """
        Inspect physical executable on disk and verify matching RenderProfile.
        """
        if not os.path.exists(exe_path) or not os.path.isfile(exe_path):
            return VersionGuardResult(
                is_supported=False,
                error_code="CAPCUT_EXE_NOT_FOUND",
                error_message=f"CapCut executable not found at {exe_path}",
            )

        # On Windows, retrieve file version using win32api or PowerShell fallback
        detected_version = cls._get_file_version(exe_path)
        sha256_checksum = compute_file_sha256(exe_path)

        profile = RenderProfileRegistry.find_matching(detected_version, sha256_checksum)
        if not profile:
            # Check if version matches but SHA256 differs
            partial = RenderProfileRegistry.find_matching(detected_version, None)
            if partial:
                return VersionGuardResult(
                    is_supported=False,
                    error_code="CAPCUT_BUILD_CHECKSUM_MISMATCH",
                    error_message=(
                        f"CapCut version {detected_version} detected, but binary SHA256 ({sha256_checksum[:16]}...) "
                        f"does not match verified production baseline ({partial.sha256_checksum[:16]}...). "
                        f"Render automation is locked to the verified build."
                    ),
                    detected_version=detected_version,
                    detected_sha256=sha256_checksum,
                )

            return VersionGuardResult(
                is_supported=False,
                error_code="WRONG_CAPCUT_VERSION",
                error_message=(
                    f"CapCut version '{detected_version}' is not verified for unattended render automation. "
                    f"Only exact verified builds (e.g. 9.3.0.3970) are currently unlocked."
                ),
                detected_version=detected_version,
                detected_sha256=sha256_checksum,
            )

        return VersionGuardResult(
            is_supported=True,
            detected_version=detected_version,
            detected_sha256=sha256_checksum,
            profile=profile,
        )

    @classmethod
    def _get_file_version(cls, exe_path: str) -> str:
        """Extract file version string from executable path."""
        # Fast path from directory naming on Windows: AppData/Local/CapCut/Apps/<version>/CapCut.exe
        parent_dir = os.path.basename(os.path.dirname(exe_path))
        if parent_dir and parent_dir[0].isdigit() and "." in parent_dir:
            return parent_dir

        if sys.platform == "darwin":
            # Check Info.plist if inside an app bundle
            info_plist = os.path.join(os.path.dirname(os.path.dirname(exe_path)), "Info.plist")
            if os.path.isfile(info_plist):
                try:
                    import plistlib
                    with open(info_plist, "rb") as f:
                        plist_data = plistlib.load(f)
                        return plist_data.get("CFBundleShortVersionString") or plist_data.get("CFBundleVersion") or "Unknown"
                except Exception:
                    pass

        if sys.platform.startswith("win"):
            try:
                import win32api
                info = win32api.GetFileVersionInfo(exe_path, "\\")
                ms = info['FileVersionMS']
                ls = info['FileVersionLS']
                return f"{win32api.HIWORD(ms)}.{win32api.LOWORD(ms)}.{win32api.HIWORD(ls)}.{win32api.LOWORD(ls)}"
            except Exception:
                pass

        return parent_dir or "Unknown"
