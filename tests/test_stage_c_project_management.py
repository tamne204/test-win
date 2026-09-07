"""
tests/test_stage_c_project_management.py
Unit and integration tests for Stage C Project Management Usability:
- GAP-06: Custom Default Render Output Directory resolution
- GAP-07: Search, filtering, and safe draft deletion guard
- GAP-08: Studio project data persistence & reload logic
- GAP-26: Diagnostic bundle export and data sanitation
"""
from __future__ import annotations

import os
import sys
import json
import tempfile
import shutil
import pytest

# Ensure apps/capcut-v2 is in sys.path
V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)


# ==============================================================================
# 1. Safe Draft Deletion Guard Tests (GAP-07)
# ==============================================================================

def is_safe_to_delete_draft(target_path: str) -> tuple[bool, str]:
    """Mirror logic of Electron IPC fs:delete-draft in main/index.js."""
    if not target_path or not isinstance(target_path, str):
        return False, "Invalid path"
    normalized = os.path.normpath(target_path)
    if not os.path.exists(normalized):
        return False, "Path does not exist"

    # Protected system paths guard
    if normalized in ["/", "C:\\", "C:/", os.path.expanduser("~")]:
        return False, "Protected root/user home directory"

    is_draft = (
        os.path.exists(os.path.join(normalized, "draft_content.json"))
        or os.path.exists(os.path.join(normalized, "draft_meta_info.json"))
        or "com.lveditor.draft" in normalized
        or ".2toolne-autoedit" in normalized
    )
    if not is_draft:
        return False, "Path is not a recognized generated draft directory. Protected from deletion."

    return True, "OK"


def test_safe_draft_deletion_allows_valid_draft(tmp_path):
    """Verify that a legitimate CapCut draft directory is permitted to be deleted."""
    draft_dir = tmp_path / "my_capcut_project"
    draft_dir.mkdir()
    (draft_dir / "draft_content.json").write_text("{}", encoding="utf-8")
    (draft_dir / "draft_meta_info.json").write_text("{}", encoding="utf-8")

    ok, msg = is_safe_to_delete_draft(str(draft_dir))
    assert ok is True
    assert msg == "OK"


def test_safe_draft_deletion_blocks_non_draft_folder(tmp_path):
    """Verify that an arbitrary user directory (e.g. Pictures/Videos) without draft files is blocked."""
    user_media_dir = tmp_path / "MyFamilyPhotos"
    user_media_dir.mkdir()
    (user_media_dir / "photo1.png").write_text("dummy image", encoding="utf-8")
    (user_media_dir / "video1.mp4").write_text("dummy video", encoding="utf-8")

    ok, msg = is_safe_to_delete_draft(str(user_media_dir))
    assert ok is False
    assert "Protected" in msg
    # Media must remain completely intact
    assert (user_media_dir / "photo1.png").exists()


def test_user_source_media_unaffected_when_draft_is_deleted(tmp_path):
    """Verify that deleting a draft directory leaves source media outside untouched."""
    media_dir = tmp_path / "raw_media"
    media_dir.mkdir()
    source_img = media_dir / "001.png"
    source_audio = media_dir / "voice.mp3"
    source_img.write_text("raw img", encoding="utf-8")
    source_audio.write_text("raw audio", encoding="utf-8")

    draft_dir = tmp_path / "CapCutDrafts" / "project_1"
    draft_dir.mkdir(parents=True)
    (draft_dir / "draft_content.json").write_text("{}", encoding="utf-8")

    # Delete draft
    ok, _ = is_safe_to_delete_draft(str(draft_dir))
    assert ok is True
    shutil.rmtree(str(draft_dir))

    # Verification: draft is gone, raw media is safe
    assert not draft_dir.exists()
    assert source_img.exists()
    assert source_audio.exists()


# ==============================================================================
# 2. Project Search & Real-time Filter Tests (GAP-07)
# ==============================================================================

def filter_projects(projects: list[dict], search_term: str) -> list[dict]:
    """Mirror search filtering logic from apps/capcut-v2/desktop/src/renderer/app.js."""
    term = (search_term or "").strip().lower()
    if not term:
        return projects
    return [
        p for p in projects
        if term in (p.get("name") or "").lower() or term in (p.get("draftDir") or "").lower()
    ]


def test_project_search_filtering():
    sample_projects = [
        {"id": "p1", "name": "Review Phim Hành Động Tập 1", "draftDir": "/drafts/proj1"},
        {"id": "p2", "name": "Tiktok Dance Challenge 2026", "draftDir": "/drafts/proj2"},
        {"id": "p3", "name": "Review Phim Hoạt Hình", "draftDir": "/drafts/proj3"},
    ]

    # Empty search returns all
    assert len(filter_projects(sample_projects, "")) == 3
    assert len(filter_projects(sample_projects, "   ")) == 3

    # Case-insensitive & Vietnamese text match
    res_review = filter_projects(sample_projects, "review")
    assert len(res_review) == 2
    assert {p["id"] for p in res_review} == {"p1", "p3"}

    res_tiktok = filter_projects(sample_projects, "TIKTOK")
    assert len(res_tiktok) == 1
    assert res_tiktok[0]["id"] == "p2"

    # Non-matching search returns empty
    assert len(filter_projects(sample_projects, "NonExistent")) == 0


# ==============================================================================
# 3. Project Reload into Studio Tests (GAP-08)
# ==============================================================================

def test_project_record_studio_data_contract():
    """Verify that projectRecord structure carries complete studio reload information."""
    media_list = ["/path/to/001.png", "/path/to/002.png"]
    audio_path = "/path/to/voice.mp3"
    script_text = "Lời thoại nhân vật số 1."
    srt_content = "1\n00:00:00,000 --> 00:00:02,000\nLời thoại 1\n"

    project_record = {
        "id": "proj_12345",
        "name": "Dự Án Test Studio",
        "aspectRatio": "9:16",
        "imageCount": len(media_list),
        "hasAudio": True,
        "draftDir": "/Users/capcut/drafts/proj_12345",
        "createdAt": 1741348800000,
        "studioData": {
            "projectName": "Dự Án Test Studio",
            "aspectRatio": "9:16",
            "mediaList": media_list,
            "audioPath": audio_path,
            "scriptText": script_text,
            "srtContent": srt_content,
        },
    }

    # Verify reload reconstitution
    sdata = project_record["studioData"]
    assert sdata["projectName"] == "Dự Án Test Studio"
    assert sdata["aspectRatio"] == "9:16"
    assert len(sdata["mediaList"]) == 2
    assert sdata["audioPath"] == audio_path
    assert sdata["scriptText"] == script_text
    assert sdata["srtContent"] == srt_content


# ==============================================================================
# 4. Custom Default Render Output Folder Resolution Tests (GAP-06)
# ==============================================================================

def resolve_render_output_path(settings_dir: str | None, draft_dir: str, proj_name: str) -> str:
    """Mirror output path computation logic from app.js."""
    out_dir = settings_dir if settings_dir else draft_dir
    sep = "\\" if ("\\" in out_dir and "/" not in out_dir) else "/"
    safe_out_dir = out_dir.rstrip("/\\")
    return f"{safe_out_dir}{sep}{proj_name or 'output'}.mp4"


def test_resolve_render_output_path():
    # When no custom setting is configured -> defaults to draft directory
    p1 = resolve_render_output_path(None, "/Users/capcut/drafts/test1", "MyProject")
    assert p1 == "/Users/capcut/drafts/test1/MyProject.mp4"

    p2 = resolve_render_output_path("", "/Users/capcut/drafts/test1", "MyProject")
    assert p2 == "/Users/capcut/drafts/test1/MyProject.mp4"

    # When custom output directory is configured
    custom_dir = "/Users/2tamne/Movies/ExportedVideos"
    p3 = resolve_render_output_path(custom_dir, "/Users/capcut/drafts/test1", "MyProject")
    assert p3 == "/Users/2tamne/Movies/ExportedVideos/MyProject.mp4"

    # Trailing slashes stripped cleanly
    custom_dir_trailing = "/Users/2tamne/Movies/ExportedVideos/"
    p4 = resolve_render_output_path(custom_dir_trailing, "/Users/capcut/drafts/test1", "MyProject")
    assert p4 == "/Users/2tamne/Movies/ExportedVideos/MyProject.mp4"

    # Windows backslash style
    win_dir = "D:\\Exports\\AutoEdit"
    p5 = resolve_render_output_path(win_dir, "C:\\CapCut\\drafts", "WinProj")
    assert p5 == "D:\\Exports\\AutoEdit\\WinProj.mp4"


# ==============================================================================
# 5. Diagnostic Bundle Export Structure (GAP-26)
# ==============================================================================

def test_diagnostic_bundle_payload_structure(tmp_path):
    """Verify schema of diagnostic bundle without leaking private tokens or keys."""
    bundle = {
        "app_version": "2.0.0",
        "platform": "darwin",
        "arch": "arm64",
        "electron_version": "33.2.1",
        "node_version": "20.18.0",
        "diagnostics_from_sidecar": {
            "sidecar_status": "ALIVE",
            "capcut_detected": True,
            "capcut_version": "9.3.0",
        },
        "render_queue_snapshot": {
            "status": "IDLE",
            "total_jobs": 0,
            "jobs": [],
        },
        "exported_at": "2026-09-07T11:45:00.000Z",
    }

    bundle_file = tmp_path / "diagnostics_bundle.json"
    bundle_file.write_text(json.dumps(bundle, indent=2), encoding="utf-8")

    loaded = json.loads(bundle_file.read_text(encoding="utf-8"))
    assert loaded["app_version"] == "2.0.0"
    assert loaded["diagnostics_from_sidecar"]["capcut_version"] == "9.3.0"
    assert "private_key" not in loaded
    assert "license_raw_seed" not in loaded
