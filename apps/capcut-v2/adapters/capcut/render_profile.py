"""
apps/capcut-v2/adapters/capcut/render_profile.py
Verified RenderProfile model and registry per Section 28 of CEO Master Directive.
Defines modular automation profiles locked to physically verified CapCut builds.
"""
from __future__ import annotations

import os
import hashlib
from typing import Dict, Any, Optional, List


class RenderProfile:
    """
    Modular automation profile for an exact verified CapCut Desktop build.
    Never enable profile for unverified/wildcard builds.
    """

    def __init__(
        self,
        profile_id: str,
        platform_name: str,
        app_version: str,
        product_version: str,
        sha256_checksum: str,
        main_window_class: str,
        export_shortcut: str = "Ctrl+E",
        confirm_export_key: str = "Enter",
        dismiss_dialog_key: str = "Escape",
        architecture: str = "CASE_C_HYBRID_KEYBOARD_HEARTBEAT",
        heartbeat_poll_interval_sec: float = 1.0,
        stall_timeout_sec: float = 45.0,
        file_lock_timeout_sec: float = 15.0,
        absolute_safety_timeout_sec: float = 600.0,
        capability_flags: Optional[Dict[str, bool]] = None,
        notes: str = "",
    ):
        self.profile_id = profile_id
        self.platform_name = platform_name
        self.app_version = app_version
        self.product_version = product_version
        self.sha256_checksum = (sha256_checksum or "").upper()
        self.main_window_class = main_window_class
        self.export_shortcut = export_shortcut
        self.confirm_export_key = confirm_export_key
        self.dismiss_dialog_key = dismiss_dialog_key
        self.architecture = architecture
        self.heartbeat_poll_interval_sec = heartbeat_poll_interval_sec
        self.stall_timeout_sec = stall_timeout_sec
        self.file_lock_timeout_sec = file_lock_timeout_sec
        self.absolute_safety_timeout_sec = absolute_safety_timeout_sec
        self.capability_flags = capability_flags or {
            "keyboard_navigation": True,
            "uia_controls": False,
            "multi_signal_heartbeat": True,
            "ffprobe_verification": True,
            "background_operation": False,
        }
        self.notes = notes

    def matches(self, app_version: str, sha256: Optional[str] = None) -> bool:
        """Check if binary version and optional SHA256 match this profile exactly."""
        if app_version != self.app_version:
            return False
        if sha256 and sha256.upper() != self.sha256_checksum:
            return False
        return True

    def to_dict(self) -> Dict[str, Any]:
        return {
            "profile_id": self.profile_id,
            "platform": self.platform_name,
            "app_version": self.app_version,
            "product_version": self.product_version,
            "sha256_checksum": self.sha256_checksum,
            "main_window_class": self.main_window_class,
            "export_shortcut": self.export_shortcut,
            "confirm_export_key": self.confirm_export_key,
            "dismiss_dialog_key": self.dismiss_dialog_key,
            "architecture": self.architecture,
            "heartbeat_poll_interval_sec": self.heartbeat_poll_interval_sec,
            "stall_timeout_sec": self.stall_timeout_sec,
            "file_lock_timeout_sec": self.file_lock_timeout_sec,
            "absolute_safety_timeout_sec": self.absolute_safety_timeout_sec,
            "capability_flags": self.capability_flags,
            "notes": self.notes,
        }


class RenderProfileRegistry:
    """Registry of physically verified CapCut render automation profiles."""

    _profiles: Dict[str, RenderProfile] = {}

    @classmethod
    def register(cls, profile: RenderProfile) -> None:
        cls._profiles[profile.profile_id] = profile

    @classmethod
    def get(cls, profile_id: str) -> Optional[RenderProfile]:
        return cls._profiles.get(profile_id)

    @classmethod
    def find_matching(cls, app_version: str, sha256: Optional[str] = None) -> Optional[RenderProfile]:
        for profile in cls._profiles.values():
            if profile.matches(app_version, sha256):
                return profile
        return None

    @classmethod
    def get_all(cls) -> List[RenderProfile]:
        return list(cls._profiles.values())


# Physical Evidence Verified Profile: Windows CapCut 9.3.0.3970
WINDOWS_CAPCUT_9_3_0_3970 = RenderProfile(
    profile_id="windows_capcut_9_3_0_3970",
    platform_name="win32",
    app_version="9.3.0.3970",
    product_version="9.3.0.6ab91e2a",
    sha256_checksum="4A62EF77819DC40B710E52ECD6B2A665D31D54F606ABCA1CB7B443F4CB13CB93",
    main_window_class="CapCutMainWindow",
    export_shortcut="Ctrl+E",
    confirm_export_key="Enter",
    dismiss_dialog_key="Escape",
    architecture="CASE_C_HYBRID_KEYBOARD_HEARTBEAT",
    heartbeat_poll_interval_sec=1.0,
    stall_timeout_sec=45.0,
    file_lock_timeout_sec=15.0,
    absolute_safety_timeout_sec=600.0,
    capability_flags={
        "keyboard_navigation": True,
        "uia_controls": False,
        "multi_signal_heartbeat": True,
        "ffprobe_verification": True,
        "background_operation": False,
    },
    notes="Physical lab verified build on Windows 11 Home Single Language (Build 26200). DirectX canvas with 0 UIA elements.",
)

RenderProfileRegistry.register(WINDOWS_CAPCUT_9_3_0_3970)

# Physical Evidence Verified Profile: macOS CapCut 9.4.0
MACOS_CAPCUT_9_4_0 = RenderProfile(
    profile_id="macos_capcut_9_4_0",
    platform_name="darwin",
    app_version="9.4.0",
    product_version="9.4.0",
    sha256_checksum="554DFDA2B37A333513FB01B9C34B340F450CC53A29E865C8BB9ADF2B53743E0E",
    main_window_class="CapCut",
    export_shortcut="Cmd+E",
    confirm_export_key="Return",
    dismiss_dialog_key="Escape",
    architecture="CASE_C_HYBRID_KEYBOARD_HEARTBEAT",
    heartbeat_poll_interval_sec=1.0,
    stall_timeout_sec=45.0,
    file_lock_timeout_sec=15.0,
    absolute_safety_timeout_sec=600.0,
    capability_flags={
        "keyboard_navigation": True,
        "uia_controls": False,
        "multi_signal_heartbeat": True,
        "ffprobe_verification": True,
        "background_operation": False,
    },
    notes="Physical lab verified build on macOS (CapCut 9.4.0 Target /Applications/CapCut.app).",
)

RenderProfileRegistry.register(MACOS_CAPCUT_9_4_0)


def compute_file_sha256(file_path: str) -> str:
    """Compute uppercase SHA256 checksum of an executable."""
    hasher = hashlib.sha256()
    with open(file_path, "rb") as f:
        while chunk := f.read(65536):
            hasher.update(chunk)
    return hasher.hexdigest().upper()
