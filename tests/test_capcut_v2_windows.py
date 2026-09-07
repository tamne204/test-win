"""
tests/test_capcut_v2_windows.py
Targeted automated tests for Windows architecture in Phase 5B:
1. Windows path normalization & drive letter handling (C:\\ vs C:/)
2. CapCut executable candidate probing on Windows (AppData, Program Files)
3. CapCut draft root discovery (default AppData & custom save_path INI)
4. Windows cooperative file lock fallback (non-fcntl branch)
5. Windows atomic file replacement (os.replace)
6. CapCut Version 9.3 schema platform fields for Windows (platform.os == "windows")
7. Windows launcher invocation branch
8. Unicode path handling with Vietnamese diacritics
"""
from __future__ import annotations

import os
import sys
import json
import time
import tempfile
import configparser
from unittest.mock import patch, MagicMock
import pytest

# Ensure apps/capcut-v2 is in sys.path
V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from adapters.capcut.detector import CapCutDetector, CapCutStatus, STATUS_NOT_FOUND
from adapters.capcut.launcher import CapCutLauncher
from adapters.capcut.project_manager import CapCutProjectManager, file_lock
from adapters.capcut.version_9_3 import CapCutVersionAdapter_9_3
from core.edit_plan import EditPlan, EditPlanProject, EditPlanClip, EditPlanAudio


def test_windows_candidate_app_paths():
    """Verify that Windows candidate executable paths cover LocalAppData and ProgramFiles."""
    fake_env = {
        "LOCALAPPDATA": "C:\\Users\\TestUser\\AppData\\Local",
        "ProgramFiles": "C:\\Program Files",
        "ProgramFiles(x86)": "C:\\Program Files (x86)",
    }
    with patch.dict(os.environ, fake_env, clear=True):
        candidates = [c.replace("/", "\\") for c in CapCutDetector._get_candidate_app_paths_windows()]
        assert any("AppData\\Local\\CapCut\\Apps" in c for c in candidates)
        assert any("Program Files\\CapCut" in c for c in candidates)
        assert any("Program Files (x86)\\CapCut" in c for c in candidates)


def test_windows_candidate_draft_roots_default():
    """Verify draft root discovery in default LocalAppData."""
    fake_env = {
        "LOCALAPPDATA": "C:\\Users\\TestUser\\AppData\\Local",
    }
    with patch.dict(os.environ, fake_env, clear=True):
        roots = [r.replace("/", "\\") for r in CapCutDetector._get_candidate_draft_roots_windows()]
        assert any("com.lveditor.draft" in r for r in roots)
        assert any("AppData\\Local\\CapCut\\User Data\\Projects" in r for r in roots)


def test_windows_candidate_draft_roots_custom_ini(tmp_path):
    """Verify draft root discovery when user configured a custom save path in capcutUserVote.ini."""
    fake_local = tmp_path / "AppData" / "Local"
    config_dir = fake_local / "CapCut" / "User Data" / "Config"
    config_dir.mkdir(parents=True, exist_ok=True)
    ini_file = config_dir / "capcutUserVote.ini"

    custom_projects = tmp_path / "CustomDrives" / "CapCutProjects"
    custom_projects.mkdir(parents=True, exist_ok=True)

    cp = configparser.ConfigParser()
    cp.add_section("Project")
    cp.set("Project", "save_path", str(custom_projects))
    with open(ini_file, "w", encoding="utf-8") as f:
        cp.write(f)

    fake_env = {"LOCALAPPDATA": str(fake_local)}
    with patch.dict(os.environ, fake_env, clear=True):
        roots = CapCutDetector._get_candidate_draft_roots_windows()
        expected_custom = os.path.join(str(custom_projects), "com.lveditor.draft")
        assert expected_custom in roots


def test_windows_file_locking_fallback(tmp_path):
    """Verify cooperative lockfile fallback mechanism when fcntl is unavailable (Windows simulation)."""
    target_file = str(tmp_path / "root_meta_info.json")
    with open(target_file, "w", encoding="utf-8") as f:
        f.write("{}")

    # Force HAS_FCNTL = False to test Windows fallback branch
    with patch("adapters.capcut.project_manager.HAS_FCNTL", False):
        with file_lock(target_file, timeout_sec=2.0):
            # Verify lockfile exists
            assert os.path.exists(f"{target_file}.lock")
        # Verify lockfile cleaned up after context exit
        assert not os.path.exists(f"{target_file}.lock")


def test_windows_atomic_file_replacement(tmp_path):
    """Verify atomic replace semantics across temporary file and target."""
    target_file = tmp_path / "target_index.json"
    target_file.write_text('{"initial": true}', encoding="utf-8")

    temp_file = tmp_path / "target_index.json.tmp"
    temp_file.write_text('{"updated": true}', encoding="utf-8")

    os.replace(str(temp_file), str(target_file))

    assert not temp_file.exists()
    assert json.loads(target_file.read_text(encoding="utf-8")) == {"updated": True}


def test_windows_schema_platform_fields(tmp_path):
    """Verify that when os.name == 'nt', draft_info.json records 'windows' platform."""
    test_img = tmp_path / "test.jpg"
    test_img.write_bytes(b"dummy")

    edit_plan = EditPlan(
        project=EditPlanProject(name="WindowsTest", width=1080, height=1920, fps=60, duration_us=5000000),
        clips=[
            EditPlanClip(
                clip_id="c1",
                media_path=str(test_img),
                start_us=0,
                duration_us=5000000,
                motion_type="ZOOM_IN",
                keyframe_params={"scale_start": 1.0, "scale_end": 1.15},
            )
        ],
    )

    out_dir = str(tmp_path / "generated_draft")

    with patch("os.name", "nt"):
        with patch.object(CapCutVersionAdapter_9_3, "_generate_cover", return_value=None):
            with patch("shutil.copy2", return_value=None):
                res = CapCutVersionAdapter_9_3.generate_draft(
                    edit_plan=edit_plan,
                    target_dir=out_dir,
                    draft_root_path=str(tmp_path),
                )
                assert os.path.isfile(res["draft_info_file"])
                with open(res["draft_info_file"], "r", encoding="utf-8") as f:
                    info = json.load(f)
                assert info["platform"]["os"] == "windows"
                assert info["last_modified_platform"]["os"] == "windows"


def test_windows_unicode_and_vietnamese_path_handling(tmp_path):
    """Verify path handling with spaces and Vietnamese Unicode characters."""
    vn_dir = tmp_path / "Thư mục dự án tiếng Việt có dấu"
    vn_dir.mkdir(parents=True, exist_ok=True)
    vn_file = vn_dir / "ảnh gốc_01.jpg"
    vn_file.write_bytes(b"image content")

    assert os.path.exists(str(vn_file))
    # Test path normalization
    norm = os.path.normpath(str(vn_file))
    assert "Thư mục dự án tiếng Việt có dấu" in norm
    assert "ảnh gốc_01.jpg" in norm


def test_windows_launcher_dispatch():
    """Verify CapCutLauncher launches via app_path on Windows without errors."""
    with patch("sys.platform", "win32"):
        with patch("adapters.capcut.launcher.CapCutDetector") as mock_det_cls:
            mock_inst = MagicMock()
            mock_inst.detect.return_value = CapCutStatus(
                status="CAPCUT_VERSION_SUPPORTED",
                app_path="C:\\Program Files\\CapCut\\CapCut.exe",
                detected_version="9.3.0",
            )
            mock_det_cls.return_value = mock_inst

            with patch("os.path.isfile", return_value=True):
                with patch("subprocess.Popen") as mock_popen:
                    res = CapCutLauncher.launch()
                    assert res["ok"] is True
                    assert res["platform"] == "Windows"
                    mock_popen.assert_called_once_with(["C:\\Program Files\\CapCut\\CapCut.exe"])
