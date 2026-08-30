"""
test_packaging_validation.py
Packaging and Release Artifacts Regression Test Suite (Phase 3).
Verifies:
1. Release artifacts existence in dist/
2. Strict package content audit (zero developer files / test artifacts in release package)
3. Cryptographic SHA-256 consistency with release_metadata.json
4. Installer script syntax & Inno Setup configuration
5. Separation of user data (projects/outputs/license) from application code
"""

import os
import json
import zipfile
import hashlib
import pytest
from pathlib import Path

from version import __version__ as APP_VERSION

DIST_DIR = Path("/Users/2tamne/tool ffmpeg/dist")
WIN_ZIP = DIST_DIR / f"SlideshowBuilder_Windows_v{APP_VERSION}.zip"
METADATA_FILE = DIST_DIR / "release_metadata.json"
CHECKSUM_FILE = DIST_DIR / "checksums.sha256"


def test_release_artifacts_exist():
    assert DIST_DIR.is_dir()
    assert WIN_ZIP.is_file(), f"Expected release package {WIN_ZIP.name} to exist."
    assert WIN_ZIP.stat().st_size > 1024 * 1024  # Must be > 1MB
    assert METADATA_FILE.is_file()
    assert CHECKSUM_FILE.is_file()


def test_package_content_audit_inclusion():
    """Verify all critical runtime modules and assets are present in the package."""
    assert WIN_ZIP.is_file()
    with zipfile.ZipFile(WIN_ZIP, "r") as zf:
        namelist = set(zf.namelist())

    required_files = [
        "app.py",
        "camera_engine.py",
        "diagnostic_collector.py",
        "ffmpeg_utils.py",
        "forced_alignment_engine.py",
        "license_manager.py",
        "renderer_g.py",
        "renderer_e_engine.py",
        "subtitles_engine.py",
        "translation_utils.py",
        "tts_utils.py",
        "version.py",
        "requirements.txt",
        "start_windows.bat",
        "start_windows.ps1",
        "SlideshowStudio.vbs",
        "templates/index.html",
        "static/js/main.js",
        "installer/install_windows.bat",
        "installer/uninstall_windows.bat",
    ]

    for req in required_files:
        assert req in namelist, f"Critical file missing from package: {req}"


def test_package_content_audit_exclusions():
    """Verify zero developer artifacts, test videos, or git history exist in package."""
    with zipfile.ZipFile(WIN_ZIP, "r") as zf:
        namelist = zf.namelist()

    forbidden_patterns = [
        ".git/",
        ".pytest_cache/",
        "__pycache__/",
        ".DS_Store",
        "tests/",
        "temp/",
        "uploads/",
        "outputs/",
        "tts_outputs/",
    ]

    for name in namelist:
        for forbidden in forbidden_patterns:
            assert not name.startswith(forbidden) and forbidden not in name, f"Forbidden developer artifact found in package: {name}"
        assert not name.endswith(".mp4"), f"Unwanted video binary found in package: {name}"
        assert not name.endswith(".pyc"), f"Compiled bytecode found in package: {name}"


def test_checksum_and_metadata_consistency():
    """Verify cryptographic SHA-256 match between file, checksums.sha256 and metadata."""
    h = hashlib.sha256()
    with open(WIN_ZIP, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    real_sha256 = h.hexdigest().lower()

    with open(METADATA_FILE, "r", encoding="utf-8") as f:
        meta = json.load(f)

    assert meta["version"] == APP_VERSION
    assert meta["sha256"] == real_sha256

    with open(CHECKSUM_FILE, "r", encoding="utf-8") as f:
        chk_text = f.read()
    assert real_sha256 in chk_text


def test_inno_setup_and_installer_scripts():
    """Verify Inno Setup .iss script and batch scripts define correct version and paths."""
    iss_path = Path("/Users/2tamne/tool ffmpeg/installer/VibeCode_Setup.iss")
    assert iss_path.is_file()
    with open(iss_path, "r", encoding="utf-8") as f:
        iss_content = f.read()
    assert f'#define MyAppVersion "{APP_VERSION}"' in iss_content
    assert "SlideshowStudio.vbs" in iss_content
    assert "diagnostic_collector.py" in iss_content

    inst_bat = Path("/Users/2tamne/tool ffmpeg/installer/install_windows.bat")
    assert inst_bat.is_file()
    with open(inst_bat, "r", encoding="utf-8") as f:
        inst_content = f.read()
    assert "diagnostic_collector.py" in inst_content
    assert "%LOCALAPPDATA%\\Programs\\VibeCode" in inst_content
