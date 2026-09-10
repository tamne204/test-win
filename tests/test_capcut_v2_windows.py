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


def test_windows_capcut_exact_version_allowlist():
    """Verify strict Windows version allowlist: only 9.3.0.3970 supported."""
    from adapters.capcut.registry import CapCutAdapterRegistry

    # 1. Supported
    adapter_cls, status, _ = CapCutAdapterRegistry.resolve_adapter("9.3.0.3970", platform_name="win32")
    assert status == "CAPCUT_VERSION_SUPPORTED"
    assert adapter_cls.__name__ == "CapCutVersionAdapter_9_3"

    # 2. Exact version mismatches must be UNSUPPORTED
    unsupported_versions = ["9.3.0.3969", "9.3.1.0", "9.4.0.0", "10.0.0.0"]
    for v in unsupported_versions:
        adapter_cls, status, _ = CapCutAdapterRegistry.resolve_adapter(v, allow_untested=False, platform_name="win32")
        assert status == "CAPCUT_VERSION_UNSUPPORTED", f"Expected UNSUPPORTED for {v}, got {status}"
        assert adapter_cls is None

    # 3. Unknown must be UNTESTED/UNSUPPORTED
    adapter_cls, status, _ = CapCutAdapterRegistry.resolve_adapter("Unknown", allow_untested=False, platform_name="win32")
    assert status == "CAPCUT_VERSION_UNTESTED"
    assert adapter_cls is None


def test_windows_capcut_get_draft_root():
    """Verify get_draft_root resolves to %LOCALAPPDATA%\\CapCut\\User Data\\Projects\\com.lveditor.draft."""
    fake_env = {"LOCALAPPDATA": "C:\\Users\\hieun\\AppData\\Local"}
    with patch.dict(os.environ, fake_env, clear=True):
        with patch("sys.platform", "win32"):
            root = CapCutDetector.get_draft_root()
            expected = os.path.join("C:\\Users\\hieun\\AppData\\Local", "CapCut", "User Data", "Projects", "com.lveditor.draft")
            assert root == expected


def test_windows_filename_sanitization():
    """Verify Windows illegal characters and reserved names are safely sanitized."""
    from adapters.capcut.project_manager import sanitize_windows_filename
    assert sanitize_windows_filename('Test <1> : * ? " / \\ |') == "Test _1_ _ _ _ _ _ _ _"
    assert sanitize_windows_filename("CON") == "project_CON"
    assert sanitize_windows_filename("NUL") == "project_NUL"
    assert sanitize_windows_filename("ProjectName . . ") == "ProjectName"


def test_windows_unknown_version_blocks_project_installation(tmp_path):
    """Verify that unknown or unsupported version strictly blocks installation into draft root."""
    from PIL import Image
    fake_local = tmp_path / "AppData" / "Local"
    draft_root = fake_local / "CapCut" / "User Data" / "Projects" / "com.lveditor.draft"
    draft_root.mkdir(parents=True, exist_ok=True)

    test_img = tmp_path / "test.png"
    Image.new("RGB", (100, 100)).save(str(test_img))

    fake_env = {"LOCALAPPDATA": str(fake_local)}
    with patch.dict(os.environ, fake_env, clear=True):
        with patch("sys.platform", "win32"):
            # No CapCut.exe present -> status = CAPCUT_NOT_FOUND, version = Unknown
            pm = CapCutProjectManager(staging_base_dir=str(tmp_path / "staging"))
            assert pm.status.status == "CAPCUT_NOT_FOUND"
            assert pm.status.draft_root_path == str(draft_root)
            assert pm.status.supported_adapter_version is None

            plan = EditPlan(
                project=EditPlanProject(name="Blocked Proj", width=1080, height=1920, fps=60.0, duration_us=5000000),
                clips=[EditPlanClip(clip_id="c1", media_path=str(test_img), start_us=0, duration_us=5000000)]
            )
            with pytest.raises(RuntimeError) as exc_info:
                pm.create_project(edit_plan=plan, auto_install=True)
            assert "Installation blocked" in str(exc_info.value) or "CAPCUT_VERSION_UNTESTED" in str(exc_info.value)


def test_windows_project_creation_and_registration_in_draft_root(tmp_path):
    """Verify real project is generated directly inside com.lveditor.draft when 9.3.0.3970 is detected."""
    from PIL import Image
    fake_local = tmp_path / "AppData" / "Local"
    draft_root = fake_local / "CapCut" / "User Data" / "Projects" / "com.lveditor.draft"
    draft_root.mkdir(parents=True, exist_ok=True)

    # Place fake CapCut 9.3.0.3970 executable
    exe_dir = fake_local / "CapCut" / "Apps" / "9.3.0.3970"
    exe_dir.mkdir(parents=True, exist_ok=True)
    fake_exe = exe_dir / "CapCut.exe"
    fake_exe.write_text("dummy")

    test_img = tmp_path / "test.png"
    Image.new("RGB", (100, 100)).save(str(test_img))

    fake_env = {"LOCALAPPDATA": str(fake_local)}
    with patch.dict(os.environ, fake_env, clear=True):
        with patch("sys.platform", "win32"):
            pm = CapCutProjectManager(staging_base_dir=str(tmp_path / "staging"))
            assert pm.status.status == "CAPCUT_VERSION_SUPPORTED"
            assert pm.status.detected_version == "9.3.0.3970"

            plan = EditPlan(
                project=EditPlanProject(name="Test Real Project", width=1080, height=1920, fps=60.0, duration_us=5000000),
                clips=[EditPlanClip(clip_id="c1", media_path=str(test_img), start_us=0, duration_us=5000000)]
            )
            res = pm.create_project(edit_plan=plan, auto_install=True)
            assert res["status"] == "READY"
            assert res["is_registered_in_capcut"] is True
            assert "com.lveditor.draft" in res["final_draft_dir"]

            final_dir = res["final_draft_dir"]
            assert os.path.isfile(os.path.join(final_dir, "draft_info.json"))
            assert os.path.isfile(os.path.join(final_dir, "draft_meta_info.json"))
            assert os.path.isfile(os.path.join(final_dir, "draft_cover.jpg"))
            assert os.path.isfile(os.path.join(str(draft_root), "root_meta_info.json"))


