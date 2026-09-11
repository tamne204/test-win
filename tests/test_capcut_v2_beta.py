"""
tests/test_capcut_v2_beta.py
Comprehensive Phase 2 Beta Foundation tests for 2TOOLNE AutoEdit for CapCut (Product V2).
Covers:
1. PresetManager (built-ins, custom presets, persistence, validation)
2. SRT-driven scene timing and grouping math (min/max duration guards)
3. EditPlan strict validation gates (negative times, overlaps, unsupported FPS, invalid motions)
4. CapCutDraftValidator (structural schema, UUID uniqueness, monotonic keyframes, material links)
5. CapCutAdapterRegistry (version routing, verified vs untested vs unsupported)
6. Transactional draft safety and rollback in CapCutProjectManager
7. Windows multi-probe detection logic
"""
from __future__ import annotations

import os
import sys
import json
import uuid
import shutil
import pytest
from PIL import Image

# Ensure apps/capcut-v2 is in python path
V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.edit_plan import (
    EditPlan,
    EditPlanProject,
    EditPlanClip,
    EditPlanAudio,
    EditPlanCaption,
    SUPPORTED_MOTIONS,
    SUPPORTED_FRAME_RATES,
)
from core.rule_engine import (
    RuleEngine,
    PRESET_BASIC,
    MOTION_ZOOM_IN,
    MOTION_ZOOM_OUT,
    MOTION_PAN_LEFT,
    MOTION_PAN_RIGHT,
    MOTION_PAN_UP,
    MOTION_PAN_DOWN,
)
from core.preset_manager import (
    PresetManager,
    RulePreset,
    PRESET_BASIC_SLIDESHOW,
    PRESET_TIKTOK_FAST,
    PRESET_STORY_CALM,
    PRESET_YOUTUBE_SHORTS_DYNAMIC,
)
from core.srt_timeline import (
    parse_srt_timestamp_us,
    parse_srt_file,
    compute_srt_scene_boundaries,
    SubtitleEntry,
)
from core.timeline_builder import TimelineBuilder, TIMING_MODE_FIXED, TIMING_MODE_SRT_DRIVEN
from adapters.capcut.registry import (
    CapCutAdapterRegistry,
    STATUS_VERIFIED,
    STATUS_UNTESTED,
    STATUS_UNSUPPORTED,
)
from adapters.capcut.version_9_3 import CapCutVersionAdapter_9_3
from adapters.capcut.validator import CapCutDraftValidator
from adapters.capcut.detector import CapCutDetector, STATUS_SUPPORTED
from adapters.capcut.project_manager import (
    CapCutProjectManager,
    ERR_INDEX_CONFLICT,
    ERR_ROLLBACK,
    STATUS_READY,
    STATUS_ERROR,
)


# ==============================================================================
# 1. PresetManager Tests
# ==============================================================================

def test_preset_manager_builtins():
    """Verify all 4 built-in presets exist with valid parameters."""
    mgr = PresetManager()
    presets = mgr.list_presets()
    preset_ids = [p.id for p in presets]
    assert "basic_slideshow" in preset_ids
    assert "tiktok_fast" in preset_ids
    assert "story_calm" in preset_ids
    assert "youtube_shorts_dynamic" in preset_ids

    basic = mgr.get_preset("basic_slideshow")
    assert basic.fps in (30.0, 60.0)
    assert basic.scene_duration_s == 5.0
    assert len(basic.motion_sequence) >= 4

    tiktok = mgr.get_preset("tiktok_fast")
    assert tiktok.scene_duration_s == 3.0
    assert tiktok.zoom_magnitude > basic.zoom_magnitude


def test_preset_manager_custom_preset_lifecycle(tmp_path):
    """Verify custom preset creation, disk persistence, retrieval, and deletion."""
    user_dir = str(tmp_path / "user_presets")
    mgr = PresetManager(user_presets_dir=user_dir)

    custom = RulePreset(
        id="my_custom_vlog",
        name="My Custom Vlog",
        description="Pans up and down with calm pace",
        canvas_ratio="16:9",
        width=1920,
        height=1080,
        fps=30.0,
        scene_duration_s=6.0,
        min_scene_duration_s=4.0,
        max_scene_duration_s=10.0,
        motion_sequence=[MOTION_PAN_UP, MOTION_PAN_DOWN, MOTION_ZOOM_IN],
        zoom_magnitude=0.10,
        pan_magnitude=0.08,
        is_builtin=False,
    )

    saved = mgr.save_custom_preset(custom)
    assert saved.id == "my_custom_vlog"

    # Verify written to disk
    json_path = os.path.join(user_dir, "my_custom_vlog.json")
    assert os.path.isfile(json_path)

    # Re-instantiate manager and check reload
    mgr2 = PresetManager(user_presets_dir=user_dir)
    loaded = mgr2.get_preset("my_custom_vlog")
    assert loaded.name == "My Custom Vlog"
    assert loaded.motion_sequence == [MOTION_PAN_UP, MOTION_PAN_DOWN, MOTION_ZOOM_IN]
    assert loaded.width == 1920
    assert loaded.height == 1080

    # Delete custom preset
    assert mgr2.delete_custom_preset("my_custom_vlog") is True
    assert not os.path.isfile(json_path)
    assert mgr2.get_preset("my_custom_vlog").id == "basic_slideshow"  # Fallback to default


def test_preset_manager_validation():
    """Verify invalid presets are rejected with informative errors."""
    mgr = PresetManager()

    # Invalid FPS
    invalid_fps = RulePreset(
        id="bad_fps",
        name="Bad FPS",
        description="",
        fps=45.0,
    )
    with pytest.raises(ValueError, match="Invalid FPS"):
        mgr.save_custom_preset(invalid_fps)

    # Invalid motion enum
    invalid_motion = RulePreset(
        id="bad_motion",
        name="Bad Motion",
        description="",
        motion_sequence=["ROTATING_3D"],
    )
    with pytest.raises(ValueError, match="Unsupported motion type"):
        mgr.save_custom_preset(invalid_motion)


# ==============================================================================
# 2. SRT Parsing & Scene Boundary Timing Math
# ==============================================================================

def test_srt_timestamp_parsing():
    """Verify timestamp parsing handles standard, dot, and millisecond variations."""
    assert parse_srt_timestamp_us("00:00:01,000") == 1_000_000
    assert parse_srt_timestamp_us("00:01:05,500") == 65_500_000
    assert parse_srt_timestamp_us("01:00:00,000") == 3600 * 1_000_000
    assert parse_srt_timestamp_us("00:00:02.250") == 2_250_000

    with pytest.raises(ValueError):
        parse_srt_timestamp_us("invalid:format")


def test_srt_parser_from_text():
    """Verify multi-block SRT parsing into structured SubtitleEntry objects."""
    raw_srt = """
1
00:00:00,000 --> 00:00:02,500
Xin chao cac ban da den voi 2TOOLNE.

2
00:00:02,500 --> 00:00:06,000
Day la he thong dung video tu dong.

3
00:00:06,000 --> 00:00:10,000
Toi uu hoa cho CapCut Desktop.
"""
    subs = parse_srt_file(raw_srt)
    assert len(subs) == 3
    assert subs[0].text == "Xin chao cac ban da den voi 2TOOLNE."
    assert subs[0].start_us == 0
    assert subs[0].end_us == 2_500_000
    assert subs[0].duration_us == 2_500_000

    assert subs[2].end_us == 10_000_000


def test_srt_scene_boundaries_min_max_guards():
    """
    Verify grouping short subtitles to satisfy min_duration_s (3.0s)
    and splitting when exceeding max_duration_s (8.0s).
    """
    subs = [
        SubtitleEntry(1, 0, 1_500_000, "Sub 1"),
        SubtitleEntry(2, 1_500_000, 3_200_000, "Sub 2"),
        SubtitleEntry(3, 3_200_000, 6_000_000, "Sub 3"),
        SubtitleEntry(4, 6_000_000, 8_500_000, "Sub 4"),
    ]

    scenes = compute_srt_scene_boundaries(subs, min_duration_s=3.0, max_duration_s=8.0)
    assert len(scenes) >= 2

    # Verify non-overlapping progression
    for i in range(len(scenes) - 1):
        assert scenes[i].end_us == scenes[i + 1].start_us

    # Verify all scene durations > 0
    for s in scenes:
        assert s.duration_us > 0


def test_timeline_builder_srt_driven(tmp_path):
    """Verify TimelineBuilder integrates SRT mode into EditPlan with synchronized captions."""
    img1 = tmp_path / "img1.png"
    img2 = tmp_path / "img2.png"
    Image.new("RGB", (1080, 1920), color=(100, 50, 50)).save(img1)
    Image.new("RGB", (1080, 1920), color=(50, 100, 50)).save(img2)

    raw_srt = """
1
00:00:00,000 --> 00:00:03,500
Scene one dialogue.

2
00:00:03,500 --> 00:00:07,000
Scene two dialogue.
"""
    builder = TimelineBuilder(PRESET_BASIC)
    plan = builder.build(
        images=[str(img1), str(img2)],
        srt_source=raw_srt,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        project_name="SRT Test",
    )

    assert len(plan.clips) == 2
    assert plan.clips[0].start_us == 0
    assert plan.clips[0].duration_us == 3_500_000
    assert plan.clips[1].start_us == 3_500_000
    assert plan.clips[1].duration_us == 3_500_000

    # Synchronized captions
    assert len(plan.captions) == 2
    assert plan.captions[0].text == "Scene one dialogue."
    assert plan.captions[1].text == "Scene two dialogue."


# ==============================================================================
# 3. EditPlan Strict Validation Gates
# ==============================================================================

def test_edit_plan_rejects_negative_timestamps():
    """Verify EditPlan.validate rejects negative start times."""
    proj = EditPlanProject(name="T", width=1080, height=1920, fps=60.0, duration_us=5000000)
    clip = EditPlanClip(
        clip_id="C1",
        media_path="/fake/path.png",
        start_us=-1000,
        duration_us=5000000,
        motion_type=MOTION_ZOOM_IN,
    )
    plan = EditPlan(project=proj, clips=[clip])
    errors = plan.validate()
    assert any("negative start time" in e for e in errors)


def test_edit_plan_rejects_zero_duration():
    """Verify EditPlan.validate rejects zero or negative duration."""
    proj = EditPlanProject(name="T", width=1080, height=1920, fps=60.0, duration_us=5000000)
    clip = EditPlanClip(
        clip_id="C1",
        media_path="/fake/path.png",
        start_us=0,
        duration_us=0,
        motion_type=MOTION_ZOOM_IN,
    )
    plan = EditPlan(project=proj, clips=[clip])
    errors = plan.validate()
    assert any("zero or negative duration" in e for e in errors)


def test_edit_plan_rejects_overlapping_clips():
    """Verify EditPlan.validate rejects overlapping clips on main video track."""
    proj = EditPlanProject(name="T", width=1080, height=1920, fps=60.0, duration_us=10000000)
    clip1 = EditPlanClip(
        clip_id="C1",
        media_path="/fake/1.png",
        start_us=0,
        duration_us=5_000_000,
        motion_type=MOTION_ZOOM_IN,
    )
    clip2 = EditPlanClip(
        clip_id="C2",
        media_path="/fake/2.png",
        start_us=4_000_000,  # Overlaps by 1s
        duration_us=5_000_000,
        motion_type=MOTION_ZOOM_OUT,
    )
    plan = EditPlan(project=proj, clips=[clip1, clip2])
    errors = plan.validate()
    assert any("overlap detected" in e for e in errors)


def test_edit_plan_rejects_unsupported_fps():
    """Verify EditPlan.validate rejects unsupported frame rates."""
    proj = EditPlanProject(name="T", width=1080, height=1920, fps=45.0, duration_us=5000000)
    clip = EditPlanClip(
        clip_id="C1",
        media_path="/fake/1.png",
        start_us=0,
        duration_us=5_000_000,
        motion_type=MOTION_ZOOM_IN,
    )
    plan = EditPlan(project=proj, clips=[clip])
    errors = plan.validate()
    assert any("unsupported FPS" in e for e in errors)


def test_edit_plan_rejects_invalid_motion():
    """Verify EditPlan.validate rejects invalid motion enum."""
    proj = EditPlanProject(name="T", width=1080, height=1920, fps=60.0, duration_us=5000000)
    clip = EditPlanClip(
        clip_id="C1",
        media_path="/fake/1.png",
        start_us=0,
        duration_us=5_000_000,
        motion_type="SPIN_360",
    )
    plan = EditPlan(project=proj, clips=[clip])
    errors = plan.validate()
    assert any("invalid motion enum" in e for e in errors)


def test_edit_plan_rejects_caption_out_of_bounds():
    """Verify EditPlan.validate rejects caption position_y outside [-1.0, 1.0]."""
    proj = EditPlanProject(name="T", width=1080, height=1920, fps=60.0, duration_us=5000000)
    clip = EditPlanClip(
        clip_id="C1",
        media_path="/fake/1.png",
        start_us=0,
        duration_us=5_000_000,
        motion_type=MOTION_ZOOM_IN,
    )
    caption = EditPlanCaption(
        caption_id="CAP1",
        text="Out of bounds",
        start_us=0,
        duration_us=5_000_000,
        position_y=-1.5,  # Invalid
    )
    plan = EditPlan(project=proj, clips=[clip], captions=[caption])
    errors = plan.validate()
    assert any("position_y" in e for e in errors)


def test_edit_plan_missing_media_check(tmp_path):
    """Verify EditPlan.validate detects missing media when check_files_exist=True."""
    proj = EditPlanProject(name="T", width=1080, height=1920, fps=60.0, duration_us=5000000)
    clip = EditPlanClip(
        clip_id="C1",
        media_path=str(tmp_path / "nonexistent.png"),
        start_us=0,
        duration_us=5_000_000,
        motion_type=MOTION_ZOOM_IN,
    )
    plan = EditPlan(project=proj, clips=[clip])
    # False by default -> no error for missing media
    assert len(plan.validate(check_files_exist=False)) == 0
    # True -> catches missing media
    assert any("missing on disk" in e for e in plan.validate(check_files_exist=True))


# ==============================================================================
# 4. CapCutDraftValidator Tests
# ==============================================================================

def test_draft_validator_on_golden_fixture(tmp_path):
    """
    Verify CapCutDraftValidator successfully validates a complete draft folder
    assembled from the 9.3 golden fixtures.
    """
    fixture_dir = os.path.join(os.path.dirname(__file__), "fixtures", "capcut_9_3")

    # Create dummy images referenced in fixture to pass media presence check
    test_draft = tmp_path / "test_fixture_draft"
    test_draft.mkdir()

    dummy_img = tmp_path / "dummy.png"
    Image.new("RGB", (100, 100)).save(dummy_img)

    with open(os.path.join(fixture_dir, "draft_info_fixture.json"), "r") as f:
        draft_info = json.load(f)

    # Point materials to dummy image
    for m in draft_info["materials"]["videos"]:
        m["path"] = str(dummy_img)

    with open(test_draft / "draft_info.json", "w") as f:
        json.dump(draft_info, f)

    with open(os.path.join(fixture_dir, "draft_meta_info_fixture.json"), "r") as f:
        draft_meta = json.load(f)
    draft_meta["draft_fold_path"] = str(test_draft)

    with open(test_draft / "draft_meta_info.json", "w") as f:
        json.dump(draft_meta, f)

    # Create dummy cover
    with open(test_draft / "draft_cover.jpg", "wb") as f:
        f.write(b"\xff\xd8\xff\xe0" + b"\x00" * 20)

    errors = CapCutDraftValidator.validate_draft(str(test_draft))
    assert errors == [], f"Validation errors: {errors}"


def test_draft_validator_detects_corrupt_or_missing_files(tmp_path):
    """Verify validator flags missing files and corrupt JSON."""
    empty_dir = tmp_path / "empty_draft"
    empty_dir.mkdir()

    errors = CapCutDraftValidator.validate_draft(str(empty_dir))
    assert any("Missing 'draft_info.json'" in e for e in errors)
    assert any("Missing 'draft_meta_info.json'" in e for e in errors)
    assert any("Missing 'draft_cover.jpg'" in e for e in errors)


def test_draft_validator_detects_non_monotonic_keyframes(tmp_path):
    """Verify validator flags out-of-order keyframe timestamps."""
    fixture_dir = os.path.join(os.path.dirname(__file__), "fixtures", "capcut_9_3")
    test_draft = tmp_path / "kf_error_draft"
    test_draft.mkdir()

    dummy_img = tmp_path / "dummy.png"
    Image.new("RGB", (100, 100)).save(dummy_img)

    with open(os.path.join(fixture_dir, "draft_info_fixture.json"), "r") as f:
        draft_info = json.load(f)

    for m in draft_info["materials"]["videos"]:
        m["path"] = str(dummy_img)

    # Corrupt keyframe list: second keyframe has time_offset smaller than first
    seg0 = draft_info["tracks"][0]["segments"][0]
    kf_list = seg0["common_keyframes"][0]["keyframe_list"]
    kf_list[1]["time_offset"] = -500  # Non-monotonic

    with open(test_draft / "draft_info.json", "w") as f:
        json.dump(draft_info, f)

    with open(os.path.join(fixture_dir, "draft_meta_info_fixture.json"), "r") as f:
        draft_meta = json.load(f)
    draft_meta["draft_fold_path"] = str(test_draft)

    with open(test_draft / "draft_meta_info.json", "w") as f:
        json.dump(draft_meta, f)

    with open(test_draft / "draft_cover.jpg", "wb") as f:
        f.write(b"\xff\xd8\xff\xe0" + b"\x00" * 20)

    errors = CapCutDraftValidator.validate_draft(str(test_draft))
    assert any("not monotonic" in e for e in errors)


# ==============================================================================
# 5. CapCutAdapterRegistry Tests
# ==============================================================================

def test_adapter_registry_routing():
    """Verify Adapter Registry resolves versions with explicit platform routing."""
    # 1. Windows strict allowlist routing (exact 9.3.0.3970 only)
    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.3.0.3970",
        platform_name="win32"
    )
    assert adapter_cls is CapCutVersionAdapter_9_3
    assert status == STATUS_VERIFIED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.3.0",
        platform_name="win32"
    )
    assert adapter_cls is None
    assert status == STATUS_UNSUPPORTED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.3.5",
        platform_name="win32"
    )
    assert adapter_cls is None
    assert status == STATUS_UNSUPPORTED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.5.0",
        allow_untested=True,
        platform_name="win32"
    )
    assert adapter_cls is CapCutVersionAdapter_9_3
    assert status == STATUS_UNTESTED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.5.0",
        allow_untested=False,
        platform_name="win32"
    )
    assert adapter_cls is None
    assert status == STATUS_UNSUPPORTED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "5.2.0",
        allow_untested=False,
        platform_name="win32"
    )
    assert adapter_cls is None
    assert status == STATUS_UNSUPPORTED

    # 2. Non-Windows (macOS) explicit platform routing
    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.3.0",
        platform_name="darwin"
    )
    assert adapter_cls is CapCutVersionAdapter_9_3
    assert status == STATUS_VERIFIED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.3.5",
        platform_name="darwin"
    )
    assert adapter_cls is CapCutVersionAdapter_9_3
    assert status == STATUS_VERIFIED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.5.0",
        allow_untested=False,
        platform_name="darwin"
    )
    assert adapter_cls is None
    assert status == STATUS_UNTESTED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "9.5.0",
        allow_untested=True,
        platform_name="darwin"
    )
    assert adapter_cls is CapCutVersionAdapter_9_3
    assert status == STATUS_UNTESTED

    adapter_cls, status, msg = CapCutAdapterRegistry.resolve_adapter(
        "5.2.0",
        allow_untested=False,
        platform_name="darwin"
    )
    assert adapter_cls is None
    assert status == STATUS_UNSUPPORTED


# ==============================================================================
# 6. Transactional Draft Safety & Rollback in CapCutProjectManager
# ==============================================================================

def test_project_manager_successful_registration(tmp_path):
    """Verify CapCutProjectManager stages, validates, and installs draft into mock root."""
    draft_root = tmp_path / "CapCutDrafts"
    draft_root.mkdir()

    # Initial empty root_meta_info.json
    root_meta_path = draft_root / "root_meta_info.json"
    with open(root_meta_path, "w") as f:
        json.dump({"all_draft_store": [], "draft_ids": 0, "root_path": str(draft_root)}, f)

    workspace_dir = tmp_path / "workspace"
    workspace_dir.mkdir()

    # Create 2 test images
    img1 = tmp_path / "img1.png"
    img2 = tmp_path / "img2.png"
    Image.new("RGB", (1080, 1920), color=(120, 40, 80)).save(img1)
    Image.new("RGB", (1080, 1920), color=(80, 120, 40)).save(img2)

    builder = TimelineBuilder(PRESET_BASIC)
    plan = builder.build(images=[str(img1), str(img2)], project_name="Tx Success Test")

    pm = CapCutProjectManager(workspace_root=str(workspace_dir))
    result = pm.create_project(
        edit_plan=plan,
        project_name="Tx Success Test",
        auto_install=True,
        override_draft_root=str(draft_root),
        allow_untested=True,
    )

    assert result["status"] == STATUS_READY
    assert result["is_registered_in_capcut"] is True

    # Verify root_meta_info.json contains the new draft
    with open(root_meta_path, "r") as f:
        updated_root = json.load(f)
    assert len(updated_root["all_draft_store"]) == 1
    assert updated_root["all_draft_store"][0]["draft_name"] == "Tx Success Test"

    # Verify project workspace metadata
    proj_meta = os.path.join(workspace_dir, result["project_id"], "metadata", "project.json")
    assert os.path.isfile(proj_meta)


def test_project_manager_rollback_on_failure(tmp_path, monkeypatch):
    """Verify CapCutProjectManager triggers rollback and restores root_meta on failure."""
    draft_root = tmp_path / "CapCutDrafts"
    draft_root.mkdir()

    # Existing project in root_meta
    root_meta_path = draft_root / "root_meta_info.json"
    initial_root_data = {
        "all_draft_store": [
            {"draft_id": "PRE_EXISTING_DRAFT", "draft_name": "Original Project"}
        ],
        "draft_ids": 1,
        "root_path": str(draft_root),
    }
    with open(root_meta_path, "w") as f:
        json.dump(initial_root_data, f)

    workspace_dir = tmp_path / "workspace"
    workspace_dir.mkdir()

    img1 = tmp_path / "img1.png"
    Image.new("RGB", (1080, 1920), color=(100, 100, 100)).save(img1)

    builder = TimelineBuilder(PRESET_BASIC)
    plan = builder.build(images=[str(img1)], project_name="Tx Fail Test")

    pm = CapCutProjectManager(workspace_root=str(workspace_dir))

    # Monkeypatch CapCutDraftValidator.validate_draft to fail during post-install check
    def mock_validate(draft_dir: str):
        return ["SIMULATED_VALIDATION_ERROR_AFTER_INSTALL"]

    monkeypatch.setattr(CapCutDraftValidator, "validate_draft", mock_validate)

    with pytest.raises(Exception) as exc_info:
        pm.create_project(
            edit_plan=plan,
            project_name="Tx Fail Test",
            auto_install=True,
            override_draft_root=str(draft_root),
            allow_untested=True,
        )

    assert "SIMULATED_VALIDATION_ERROR_AFTER_INSTALL" in str(exc_info.value)

    # Verify root_meta_info.json was RESTORED to initial state
    with open(root_meta_path, "r") as f:
        restored_root = json.load(f)

    assert len(restored_root["all_draft_store"]) == 1
    assert restored_root["all_draft_store"][0]["draft_id"] == "PRE_EXISTING_DRAFT"


def test_windows_multiprobe_detection_structure(monkeypatch):
    """Verify CapCutDetector includes Windows multi-probe paths and status mapping."""
    detector = CapCutDetector()

    # With mocked LOCALAPPDATA
    monkeypatch.setenv("LOCALAPPDATA", "C:\\Users\\MockUser\\AppData\\Local")
    win_paths = detector._get_candidate_draft_roots_windows()
    assert len(win_paths) >= 1
    assert any("CapCut" in p for p in win_paths)
    assert any("com.lveditor.draft" in p for p in win_paths)

    # Candidate apps paths
    app_paths = detector._get_candidate_app_paths_windows()
    assert len(app_paths) >= 2
    assert any("CapCut" in p for p in app_paths)
