"""
tests/test_stage_d_packaging_and_updater.py
Unit and integration tests for Stage D:
- GAP-04: Windows NSIS Packaging & electron-builder configuration
- GAP-05: Update checking manifest & version comparator
"""
from __future__ import annotations

import os
import json
import yaml
import pytest
from pathlib import Path


DESKTOP_DIR = Path(__file__).resolve().parent.parent / "apps" / "capcut-v2" / "desktop"


def test_electron_builder_nsis_configuration():
    """Verify electron-builder.yml configuration has complete NSIS installer parameters."""
    config_file = DESKTOP_DIR / "electron-builder.yml"
    assert config_file.is_file(), "electron-builder.yml must exist"

    with open(config_file, "r", encoding="utf-8") as f:
        config = yaml.safe_load(f)

    # Windows target
    assert "win" in config
    win_targets = [t["target"] for t in config["win"]["target"]]
    assert "nsis" in win_targets, "Windows targets must include nsis"
    assert "portable" in win_targets, "Windows targets must include portable"

    # NSIS parameters
    assert "nsis" in config
    nsis = config["nsis"]
    assert nsis["oneClick"] is False, "Installer must allow custom wizard options"
    assert nsis["allowToChangeInstallationDirectory"] is True, "User must be able to choose install path"
    assert nsis["createDesktopShortcut"] is True, "Desktop shortcut must be generated"
    assert nsis["createStartMenuShortcut"] is True, "Start menu shortcut must be generated"
    assert "shortcutName" in nsis
    assert "${productName}" in nsis["artifactName"] or "Setup" in nsis["artifactName"]


def test_package_json_build_scripts():
    """Verify package.json defines all required packaging scripts."""
    pkg_file = DESKTOP_DIR / "package.json"
    assert pkg_file.is_file()

    with open(pkg_file, "r", encoding="utf-8") as f:
        pkg = json.load(f)

    scripts = pkg.get("scripts", {})
    assert "build:win" in scripts
    assert "build:win:nsis" in scripts
    assert "build:win:portable" in scripts


def compare_versions(current: str, remote: str) -> bool:
    """True if remote is newer than current, otherwise False."""
    def parse_ver(v: str):
        clean = v.lstrip("v").split("-")[0]
        return [int(x) for x in clean.split(".") if x.isdigit()]

    try:
        curr_parts = parse_ver(current)
        remote_parts = parse_ver(remote)
        return remote_parts > curr_parts
    except Exception:
        return remote != current


def test_update_version_comparison():
    # Same version
    assert compare_versions("2.0.0", "2.0.0") is False
    assert compare_versions("v2.0.0", "2.0.0") is False

    # Older version
    assert compare_versions("2.0.1", "2.0.0") is False

    # Newer versions
    assert compare_versions("2.0.0", "2.0.1") is True
    assert compare_versions("2.0.0", "2.1.0") is True
    assert compare_versions("2.0.0", "3.0.0") is True


def test_update_response_schema():
    """Verify mock update checker response format."""
    current_version = "2.0.0"
    mock_payload = {
        "latest_version": "2.1.0",
        "release_notes": "Added new transition pack",
        "download_url": "https://www.2tamne.site/download/latest",
    }

    latest_ver = mock_payload.get("latest_version")
    has_update = compare_versions(current_version, latest_ver)

    response = {
        "ok": True,
        "current_version": current_version,
        "latest_version": latest_ver,
        "has_update": has_update,
        "release_notes": mock_payload["release_notes"],
        "download_url": mock_payload["download_url"],
    }

    assert response["ok"] is True
    assert response["has_update"] is True
    assert response["latest_version"] == "2.1.0"
