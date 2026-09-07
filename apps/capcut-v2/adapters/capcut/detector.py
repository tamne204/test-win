"""
apps/capcut-v2/adapters/capcut/detector.py
CapCut Desktop installation detector and version compatibility checker.
Supports macOS and multi-path probing on Windows.
"""
from __future__ import annotations

import os
import sys
import plistlib
import configparser
from typing import Dict, Any, Optional, List

try:
    from .registry import CapCutAdapterRegistry, STATUS_VERIFIED, STATUS_UNTESTED, STATUS_UNSUPPORTED
except (ImportError, ValueError):
    from adapters.capcut.registry import CapCutAdapterRegistry, STATUS_VERIFIED, STATUS_UNTESTED, STATUS_UNSUPPORTED

STATUS_SUPPORTED = STATUS_VERIFIED
STATUS_NOT_FOUND = "CAPCUT_NOT_FOUND"


class CapCutStatus:
    def __init__(
        self,
        status: str,
        detected_version: Optional[str] = None,
        app_path: Optional[str] = None,
        draft_root_path: Optional[str] = None,
        supported_adapter_version: Optional[str] = None,
        diagnostic_message: str = "",
    ):
        self.status = status
        self.detected_version = detected_version
        self.app_path = app_path
        self.draft_root_path = draft_root_path
        self.supported_adapter_version = supported_adapter_version or "None"
        self.diagnostic_message = diagnostic_message

    def to_dict(self) -> Dict[str, Any]:
        return {
            "status": self.status,
            "detected_version": self.detected_version,
            "app_path": self.app_path,
            "draft_root_path": self.draft_root_path,
            "supported_adapter_version": self.supported_adapter_version,
            "diagnostic_message": self.diagnostic_message,
        }


class CapCutDetector:
    """Detects CapCut Desktop application and draft storage location."""

    @staticmethod
    def detect() -> CapCutStatus:
        if sys.platform == "darwin":
            return CapCutDetector._detect_macos()
        elif sys.platform.startswith("win"):
            return CapCutDetector._detect_windows()
        else:
            return CapCutStatus(
                status=STATUS_NOT_FOUND,
                diagnostic_message=f"Operating system '{sys.platform}' is not supported for CapCut Desktop.",
            )

    @staticmethod
    def _detect_macos() -> CapCutStatus:
        mac_app_path = "/Applications/CapCut.app"
        info_plist_path = os.path.join(mac_app_path, "Contents", "Info.plist")

        if not os.path.exists(mac_app_path) or not os.path.exists(info_plist_path):
            return CapCutStatus(
                status=STATUS_NOT_FOUND,
                diagnostic_message="CapCut.app not found in /Applications.",
            )

        detected_version = None
        try:
            with open(info_plist_path, "rb") as fp:
                plist_data = plistlib.load(fp)
                detected_version = plist_data.get("CFBundleShortVersionString") or plist_data.get("CFBundleVersion")
        except Exception as e:
            detected_version = "Unknown"

        # Determine draft storage directory
        user_movies = os.path.expanduser("~/Movies/CapCut/User Data/Projects/com.lveditor.draft")
        draft_path = user_movies if os.path.isdir(user_movies) else None

        # Query Adapter Registry
        adapter_cls, status_code, diag_msg = CapCutAdapterRegistry.resolve_adapter(detected_version)

        return CapCutStatus(
            status=status_code,
            detected_version=detected_version,
            app_path=mac_app_path,
            draft_root_path=draft_path,
            supported_adapter_version=adapter_cls.__name__ if adapter_cls else None,
            diagnostic_message=diag_msg,
        )

    @staticmethod
    def _get_candidate_app_paths_windows() -> List[str]:
        local_app_data = os.environ.get("LOCALAPPDATA", "")
        if not local_app_data:
            user_profile = os.environ.get("USERPROFILE", "")
            if user_profile:
                local_app_data = os.path.join(user_profile, "AppData", "Local")

        prog_files = os.environ.get("ProgramFiles", "C:\\Program Files")
        prog_files_x86 = os.environ.get("ProgramFiles(x86)", "C:\\Program Files (x86)")
        candidates = []
        if local_app_data:
            candidates.append(os.path.join(local_app_data, "CapCut", "Apps"))
        candidates.append(os.path.join(prog_files, "CapCut"))
        candidates.append(os.path.join(prog_files_x86, "CapCut"))
        return candidates

    @staticmethod
    def _get_candidate_draft_roots_windows() -> List[str]:
        local_app_data = os.environ.get("LOCALAPPDATA", "")
        if not local_app_data:
            user_profile = os.environ.get("USERPROFILE", "")
            if user_profile:
                local_app_data = os.path.join(user_profile, "AppData", "Local")

        candidates = []
        if local_app_data:
            config_file = os.path.join(local_app_data, "CapCut", "User Data", "Config", "capcutUserVote.ini")
            if os.path.isfile(config_file):
                try:
                    cp = configparser.ConfigParser()
                    cp.read(config_file, encoding="utf-8")
                    custom_save = cp.get("Project", "save_path", fallback="")
                    if custom_save and os.path.isdir(custom_save):
                        candidates.append(os.path.join(custom_save, "com.lveditor.draft"))
                except Exception:
                    pass
            candidates.append(
                os.path.join(local_app_data, "CapCut", "User Data", "Projects", "com.lveditor.draft")
            )
        else:
            candidates.append(
                os.path.join("C:\\Users\\Default\\AppData\\Local", "CapCut", "User Data", "Projects", "com.lveditor.draft")
            )
        return candidates

    @staticmethod
    def _detect_windows() -> CapCutStatus:
        """
        Robust multi-probe detection for Windows CapCut Desktop.
        Probes User AppData, Program Files (64/32-bit), and config INI files.
        """
        app_path: Optional[str] = None
        detected_version: Optional[str] = None
        draft_path: Optional[str] = None

        app_candidates = CapCutDetector._get_candidate_app_paths_windows()

        # 1. Search for executable and version
        for base in app_candidates:
            if not os.path.isdir(base):
                continue
            subdirs = [d for d in os.listdir(base) if os.path.isdir(os.path.join(base, d))]
            if subdirs:
                subdirs.sort(reverse=True)
                for sd in subdirs:
                    exe_file = os.path.join(base, sd, "CapCut.exe")
                    if os.path.isfile(exe_file):
                        app_path = exe_file
                        detected_version = sd.split("-")[0]
                        break
            if app_path:
                break
            direct_exe = os.path.join(base, "CapCut.exe")
            if os.path.isfile(direct_exe):
                app_path = direct_exe
                detected_version = "Unknown"
                break

        if not app_path or not os.path.isfile(app_path):
            return CapCutStatus(
                status=STATUS_NOT_FOUND,
                diagnostic_message="CapCut Desktop executable not found in Windows search paths.",
            )

        # 2. Determine draft directory
        draft_candidates = CapCutDetector._get_candidate_draft_roots_windows()
        for dc in draft_candidates:
            if os.path.isdir(dc):
                draft_path = dc
                break

        adapter_cls, status_code, diag_msg = CapCutAdapterRegistry.resolve_adapter(detected_version)

        return CapCutStatus(
            status=status_code,
            detected_version=detected_version,
            app_path=app_path,
            draft_root_path=draft_path,
            supported_adapter_version=adapter_cls.__name__ if adapter_cls else None,
            diagnostic_message=diag_msg,
        )
