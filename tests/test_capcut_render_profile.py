"""
tests/test_capcut_render_profile.py
Unit tests for RenderProfile, RenderProfileRegistry, and CapCutVersionGuard.
"""
import os
import sys
import tempfile
import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.insert(0, V2_DIR)

from adapters.capcut.render_profile import (
    RenderProfile,
    RenderProfileRegistry,
    WINDOWS_CAPCUT_9_3_0_3970,
    compute_file_sha256,
)
from adapters.capcut.version_guard import CapCutVersionGuard


def test_verified_windows_profile_constants():
    profile = WINDOWS_CAPCUT_9_3_0_3970
    assert profile.profile_id == "windows_capcut_9_3_0_3970"
    assert profile.platform_name == "win32"
    assert profile.app_version == "9.3.0.3970"
    assert profile.product_version == "9.3.0.6ab91e2a"
    assert profile.sha256_checksum == "4A62EF77819DC40B710E52ECD6B2A665D31D54F606ABCA1CB7B443F4CB13CB93"
    assert profile.main_window_class == "CapCutMainWindow"
    assert profile.export_shortcut == "Ctrl+E"
    assert profile.confirm_export_key == "Enter"
    assert profile.dismiss_dialog_key == "Escape"
    assert profile.architecture == "CASE_C_HYBRID_KEYBOARD_HEARTBEAT"
    assert profile.capability_flags["keyboard_navigation"] is True
    assert profile.capability_flags["multi_signal_heartbeat"] is True


def test_render_profile_registry_lookup():
    p = RenderProfileRegistry.get("windows_capcut_9_3_0_3970")
    assert p is not None
    assert p.app_version == "9.3.0.3970"

    # Match exact
    match = RenderProfileRegistry.find_matching(
        "9.3.0.3970",
        "4A62EF77819DC40B710E52ECD6B2A665D31D54F606ABCA1CB7B443F4CB13CB93",
    )
    assert match is not None
    assert match.profile_id == "windows_capcut_9_3_0_3970"

    # Match exact macOS
    match_mac = RenderProfileRegistry.find_matching("9.4.0")
    assert match_mac is not None
    assert match_mac.profile_id == "macos_capcut_9_4_0"

    # Reject wildcard / mismatched version
    assert RenderProfileRegistry.find_matching("9.9.9") is None
    assert RenderProfileRegistry.find_matching("9.3.1") is None
    assert RenderProfileRegistry.find_matching("9.3.0.3970", "0000000000000000000000000000000000000000000000000000000000000000") is None


def test_compute_file_sha256():
    with tempfile.NamedTemporaryFile("wb", delete=False) as f:
        f.write(b"2TOOLNE_TEST_BINARY_PAYLOAD")
        tmp_name = f.name
    try:
        checksum = compute_file_sha256(tmp_name)
        assert len(checksum) == 64
        assert checksum.isupper()
    finally:
        if os.path.exists(tmp_name):
            os.remove(tmp_name)


def test_version_guard_inspection():
    # Non-existent file
    res = CapCutVersionGuard.inspect_executable("/non/existent/CapCut.exe")
    assert res.is_supported is False
    assert res.error_code == "CAPCUT_EXE_NOT_FOUND"

    # Unsupported version
    with tempfile.TemporaryDirectory() as tmp_dir:
        fake_exe_dir = os.path.join(tmp_dir, "9.4.0.1234")
        os.makedirs(fake_exe_dir)
        fake_exe = os.path.join(fake_exe_dir, "CapCut.exe")
        with open(fake_exe, "wb") as f:
            f.write(b"MOCK_EXE_DATA")

        res2 = CapCutVersionGuard.inspect_executable(fake_exe)
        assert res2.is_supported is False
        assert res2.error_code in ("WRONG_CAPCUT_VERSION", "CAPCUT_BUILD_CHECKSUM_MISMATCH")
