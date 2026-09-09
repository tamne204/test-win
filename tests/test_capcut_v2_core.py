"""
tests/test_capcut_v2_core.py
Unit and integration tests for CapCut V2 Core, RuleEngine, TimelineBuilder,
and CapCut 9.3.0 Draft Adapter.
"""
from __future__ import annotations

import os
import sys
import json
import tempfile
import pytest
from PIL import Image

# Ensure apps/capcut-v2 is in python path
V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)


from capcut_version import get_version, get_product_id, get_app_name

from core.edit_plan import EditPlan, EditPlanProject, EditPlanClip, EditPlanAudio, EditPlanCaption
from core.rule_engine import (
    RuleEngine,
    Preset,
    PRESET_BASIC,
    PRESET_TIKTOK_FAST,
    MOTION_ZOOM_IN,
    MOTION_ZOOM_OUT,
    MOTION_PAN_LEFT,
    MOTION_PAN_RIGHT,
)
from core.timeline_builder import TimelineBuilder
from adapters.capcut.detector import CapCutDetector, STATUS_SUPPORTED
from adapters.capcut.adapter import CapCutAdapter
from adapters.capcut.version_9_3 import CapCutVersionAdapter_9_3


def test_v2_identity():
    """Verify V2 product identity and versioning are distinct."""
    assert get_product_id() == "2toolne.capcut.v2"
    assert get_app_name() == "2toolne AutoEdit for CapCut"
    assert get_version().startswith("2.")


def test_rule_engine_determinism():
    """Verify RuleEngine determinism: Clip 0 -> ZOOM_IN, 1 -> ZOOM_OUT, 2 -> PAN_LEFT, 3 -> PAN_RIGHT."""
    engine = RuleEngine(PRESET_BASIC)
    motions = [engine.assign_motion(i) for i in range(8)]
    assert motions == [
        MOTION_ZOOM_IN,
        MOTION_ZOOM_OUT,
        MOTION_PAN_LEFT,
        MOTION_PAN_RIGHT,
        MOTION_ZOOM_IN,
        MOTION_ZOOM_OUT,
        MOTION_PAN_LEFT,
        MOTION_PAN_RIGHT,
    ]


def test_rule_engine_parameters():
    """Verify keyframe parameter bounds for each motion type."""
    engine = RuleEngine(PRESET_BASIC)

    zi = engine.get_motion_parameters(MOTION_ZOOM_IN)
    assert zi["scale_start"] == 1.0
    assert zi["scale_end"] > 1.0

    zo = engine.get_motion_parameters(MOTION_ZOOM_OUT)
    assert zo["scale_start"] > 1.0
    assert zo["scale_end"] == 1.0

    pl = engine.get_motion_parameters(MOTION_PAN_LEFT)
    assert pl["pos_x_start"] > 0
    assert pl["pos_x_end"] < 0
    assert pl["scale_start"] > 1.0  # slight scale to prevent black borders

    pr = engine.get_motion_parameters(MOTION_PAN_RIGHT)
    assert pr["pos_x_start"] < 0
    assert pr["pos_x_end"] > 0


def test_edit_plan_serialization_and_validation():
    """Verify EditPlan to_dict, to_json, and round-trip from_dict."""
    project = EditPlanProject(name="Test", width=1080, height=1920, fps=60.0, duration_us=15000000)
    clip1 = EditPlanClip(
        clip_id="C1",
        media_path="/tmp/test1.png",
        media_type="image",
        start_us=0,
        duration_us=5000000,
        motion_type=MOTION_ZOOM_IN,
    )
    clip2 = EditPlanClip(
        clip_id="C2",
        media_path="/tmp/test2.png",
        media_type="image",
        start_us=5000000,
        duration_us=5000000,
        motion_type=MOTION_ZOOM_OUT,
    )
    plan = EditPlan(project=project, clips=[clip1, clip2])

    errors = plan.validate()
    assert len(errors) == 0

    d = plan.to_dict()
    roundtrip = EditPlan.from_dict(d)
    assert roundtrip.project.duration_us == 15000000
    assert len(roundtrip.clips) == 2
    assert roundtrip.clips[0].motion_type == MOTION_ZOOM_IN


def test_timeline_builder_deterministic_timing(tmp_path):
    """Verify TimelineBuilder calculates 5s non-overlapping segments at 60 FPS."""
    # Create 3 dummy test images
    img_paths = []
    for i in range(3):
        p = tmp_path / f"img_{i}.png"
        im = Image.new("RGB", (1080, 1920), color=(i * 60, 100, 150))
        im.save(p)
        img_paths.append(str(p))

    builder = TimelineBuilder(PRESET_BASIC)
    plan = builder.build(images=img_paths, project_name="POC Test")

    assert len(plan.clips) == 3
    assert plan.clips[0].start_us == 0
    assert plan.clips[0].duration_us == 5_000_000
    assert plan.clips[0].motion_type == MOTION_ZOOM_IN

    assert plan.clips[1].start_us == 5_000_000
    assert plan.clips[1].duration_us == 5_000_000
    assert plan.clips[1].motion_type == MOTION_ZOOM_OUT

    assert plan.clips[2].start_us == 10_000_000
    assert plan.clips[2].duration_us == 5_000_000
    assert plan.clips[2].motion_type == MOTION_PAN_LEFT

    assert plan.project.duration_us == 15_000_000
    assert plan.project.fps == 60.0
    assert plan.project.aspect_ratio == "9:16"


def test_capcut_detector_macos():
    """Verify CapCutDetector detects installed CapCut 9.3.0 on physical macOS workstation."""
    if sys.platform != "darwin":
        pytest.skip("Physical CapCut installation check is macOS-specific")
    detector = CapCutDetector()
    status = detector.detect()
    assert status.status == STATUS_SUPPORTED
    assert status.detected_version in ["9.3.0", "9.4.0"]
    assert status.draft_root_path is not None
    assert os.path.isdir(status.draft_root_path)


def test_capcut_adapter_generation_and_schema(tmp_path):
    """Verify CapCutAdapter generates a fully compliant 9.3.0 draft structure."""
    # 1. Create 3 test images and 1 test audio
    img_paths = []
    for i in range(3):
        p = tmp_path / f"test_{i}.png"
        im = Image.new("RGB", (1080, 1920), color=(50 * (i + 1), 80, 120))
        im.save(p)
        img_paths.append(str(p))

    aud_path = tmp_path / "audio.wav"
    with open(aud_path, "wb") as f:
        # Minimal dummy wav header (44 bytes)
        f.write(b"RIFF$\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00D\xac\x00\x00\x88X\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00")

    # 2. Build EditPlan
    builder = TimelineBuilder(PRESET_BASIC)
    plan = builder.build(
        images=img_paths,
        audio_path=str(aud_path),
        captions=[{"text": "UNIT TEST CAPTION", "start_s": 0.0, "duration_s": 5.0}],
        project_name="Unit Test Project",
    )

    # 3. Generate draft
    out_draft_dir = str(tmp_path / "test_draft_out")
    adapter = CapCutAdapter(target_version="9.3.0")
    result = adapter.generate(
        edit_plan=plan,
        target_dir=out_draft_dir,
        draft_root_path=str(tmp_path),
        allow_untested=True,
    )

    assert result["validated"] is True
    assert os.path.isfile(os.path.join(out_draft_dir, "draft_info.json"))
    assert os.path.isfile(os.path.join(out_draft_dir, "draft_meta_info.json"))
    assert os.path.isfile(os.path.join(out_draft_dir, "draft_cover.jpg"))

    # 4. Verify draft_info content
    with open(os.path.join(out_draft_dir, "draft_info.json"), "r", encoding="utf-8") as f:
        d_info = json.load(f)

    assert d_info["fps"] == 60.0
    assert d_info["duration"] == 15_000_000
    assert d_info["canvas_config"]["ratio"] == "9:16"
    assert len(d_info["tracks"]) >= 3  # Video, Audio, Text

    # Verify keyframes on video segments
    v_track = [t for t in d_info["tracks"] if t["type"] == "video"][0]
    assert len(v_track["segments"]) == 3

    # Segment 0: Zoom In
    seg0 = v_track["segments"][0]
    kf0 = seg0["common_keyframes"]
    assert len(kf0) >= 1
    assert kf0[0]["property_type"] == "KFTypeScaleX"
    assert kf0[0]["keyframe_list"][0]["values"] == [1.0]
    assert kf0[0]["keyframe_list"][1]["values"][0] > 1.0

    # Segment 1: Zoom Out
    seg1 = v_track["segments"][1]
    kf1 = seg1["common_keyframes"]
    assert len(kf1) >= 1
    assert kf1[0]["property_type"] == "KFTypeScaleX"
    assert kf1[0]["keyframe_list"][0]["values"][0] > 1.0
    assert kf1[0]["keyframe_list"][1]["values"] == [1.0]

    # Segment 2: Pan Left
    seg2 = v_track["segments"][2]
    kf2 = seg2["common_keyframes"]
    pos_kf = [k for k in kf2 if k["property_type"] == "KFTypePositionX"]
    assert len(pos_kf) == 1
    assert pos_kf[0]["keyframe_list"][0]["values"][0] > 0
    assert pos_kf[0]["keyframe_list"][1]["values"][0] < 0

    # Verify Audio Track
    a_track = [t for t in d_info["tracks"] if t["type"] == "audio"][0]
    assert len(a_track["segments"]) == 1

    # Verify Text Track
    t_track = [t for t in d_info["tracks"] if t["type"] == "text"][0]
    assert len(t_track["segments"]) == 1
    t_mat_id = t_track["segments"][0]["material_id"]
    t_mat = [m for m in d_info["materials"]["texts"] if m["id"] == t_mat_id][0]
    parsed_content = json.loads(t_mat["content"])
    assert parsed_content["text"] == "UNIT TEST CAPTION"

    # Verify Audio registered in draft_meta_info.json draft_materials
    meta_path = os.path.join(out_draft_dir, "draft_meta_info.json")
    with open(meta_path, "r", encoding="utf-8") as f:
        meta_json = json.load(f)
    aud_materials = [m for m in meta_json["draft_materials"] if m["type"] == 1]
    assert len(aud_materials) == 1
    assert len(aud_materials[0]["value"]) == 1
    assert aud_materials[0]["value"][0]["metetype"] == "music"


def test_capcut_installed_path_remapping(tmp_path):
    """Verify CapCutProjectManager remaps staging paths to installed target paths."""
    from adapters.capcut.project_manager import CapCutProjectManager

    img_p = tmp_path / "img.png"
    Image.new("RGB", (1080, 1920), color=(100, 100, 100)).save(img_p)
    aud_p = tmp_path / "aud.wav"
    with open(aud_p, "wb") as f:
        f.write(b"RIFF$\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00D\xac\x00\x00\x88X\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00")

    builder = TimelineBuilder(PRESET_BASIC)
    plan = builder.build(
        images=[str(img_p)],
        audio_path=str(aud_p),
        project_name="Remap Test",
    )

    staging_base = str(tmp_path / "staging")
    fake_capcut_root = str(tmp_path / "com.lveditor.draft")
    os.makedirs(fake_capcut_root, exist_ok=True)

    pm = CapCutProjectManager(staging_base_dir=staging_base)
    res = pm.create_project(
        edit_plan=plan,
        project_name="Remap Test",
        auto_install=True,
        override_draft_root=fake_capcut_root,
        allow_untested=True,
    )

    installed_dir = res["final_draft_dir"]
    assert installed_dir.startswith(fake_capcut_root)
    assert not installed_dir.startswith(staging_base)

    # Verify paths inside installed draft_info.json point to installed_dir, NOT staging
    with open(os.path.join(installed_dir, "draft_info.json"), "r", encoding="utf-8") as f:
        info_json = json.load(f)
    vid_path = info_json["materials"]["videos"][0]["path"]
    aud_path = info_json["materials"]["audios"][0]["path"]
    assert vid_path.startswith(installed_dir)
    assert not vid_path.startswith(staging_base)
    assert aud_path.startswith(installed_dir)
    assert not aud_path.startswith(staging_base)

    # Verify draft_meta_info.json
    with open(os.path.join(installed_dir, "draft_meta_info.json"), "r", encoding="utf-8") as f:
        meta_json = json.load(f)
    assert meta_json["draft_fold_path"] == installed_dir
    meta_vid = meta_json["draft_materials"][0]["value"][0]["file_Path"]
    meta_aud = meta_json["draft_materials"][1]["value"][0]["file_Path"]
    assert meta_vid.startswith(installed_dir)
    assert not meta_vid.startswith(staging_base)
    assert meta_aud.startswith(installed_dir)
    assert not meta_aud.startswith(staging_base)


def test_script_paragraphs_scene_boundaries():
    """Verify \n separates subtitle cues and \n\n separates image scenes."""
    from core.srt_timeline import SubtitleEntry, compute_script_paragraphs_scene_boundaries
    script = "Câu 1A\nCâu 1B\n\nCâu 2A\n\nCâu 3A\nCâu 3B"
    subtitles = [
        SubtitleEntry(1, 0, 2000000, "Câu 1A"),
        SubtitleEntry(2, 2000000, 4000000, "Câu 1B"),
        SubtitleEntry(3, 4000000, 7000000, "Câu 2A"),
        SubtitleEntry(4, 7000000, 9000000, "Câu 3A"),
        SubtitleEntry(5, 9000000, 11000000, "Câu 3B"),
    ]
    scenes = compute_script_paragraphs_scene_boundaries(script, subtitles)
    assert len(scenes) == 3
    # Scene 1 contains 2 subtitles
    assert len(scenes[0].subtitles) == 2
    assert scenes[0].start_us == 0
    assert scenes[0].duration_us == 4000000
    # Scene 2 contains 1 subtitle
    assert len(scenes[1].subtitles) == 1
    assert scenes[1].start_us == 4000000
    assert scenes[1].duration_us == 3000000
    # Scene 3 contains 2 subtitles
    assert len(scenes[2].subtitles) == 2
    assert scenes[2].start_us == 7000000
    assert scenes[2].duration_us == 4000000


def test_motion_weights_assignment():
    """Verify assign_motion respects user percentage weights."""
    from core.rule_engine import RuleEngine
    engine = RuleEngine()
    # 100% Zoom In
    m1 = engine.assign_motion(0, weights={"zoom_in": 100, "zoom_out": 0, "pan": 0, "tilt": 0})
    assert m1 == "ZOOM_IN"
    # 100% Zoom Out
    m2 = engine.assign_motion(1, weights={"zoom_in": 0, "zoom_out": 100, "pan": 0, "tilt": 0})
    assert m2 == "ZOOM_OUT"


