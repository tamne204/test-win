"""
tests/test_a2_acceptance_matrix.py
Authoritative 17-Test Acceptance Matrix for Phase A2: Frame-Accurate Timeline Quantization.
Ratified by Directive: ANTIGRAVITY 2TOOLNE AUTOEDIT V2 FAST-TRACK PHASE A2 IMPLEMENTATION.
"""
from __future__ import annotations

import copy
import hashlib
import json
import math
import os
import sys
import time
from typing import List

import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.subtitles.models import SubtitleCue
from core.visual.models import (
    VisualShot,
    VisualPlannerEngine,
    VisualPlannerOptions,
    FrameQuantizationPolicy,
    rational_fps_from_float,
)
from core.visual.quantization import (
    FrameTimebase,
    FrameQuantizer,
    FrameAccuracyValidator,
    FrameValidationReport,
    QuantizationDiagnostics,
)
from core.visual.pipeline_adapter import VisualPipelineAdapter
from core.timeline_builder import TimelineBuilder
from PIL import Image
from adapters.capcut.adapter import CapCutAdapter



@pytest.fixture(scope="module")
def long_01_cues() -> List[SubtitleCue]:
    """Loads authoritative 641 A0 SubtitleCues from cached A0 golden output."""
    cache_path = os.path.join(
        os.path.dirname(__file__), "..", "reports", "accuracy", "a0", "long_01_subtitles_cache.json"
    )
    assert os.path.exists(cache_path), f"Cache file not found: {cache_path}"
    with open(cache_path, "r", encoding="utf-8") as f:
        data = json.load(f)
    cue_fields = {
        "index", "start_s", "end_s", "text", "paragraph_ids", "sentence_ids",
        "alignment_confidence", "source_token_start", "source_token_end"
    }
    return [SubtitleCue(**{k: v for k, v in d.items() if k in cue_fields}) for d in data]


@pytest.fixture(scope="module")
def long_01_images() -> List[str]:
    """Generates 278 image identifiers matching GOLDEN_LONG_01 inventory."""
    return [f"anh_kb{i:03d}.png" for i in range(1, 279)]


# ==============================================================================
# A2-T01: 24fps exact boundary quantization
# ==============================================================================
def test_a2_t01_24fps_exact_boundary():
    """A2-T01: Verify boundaries and timebase at 24fps."""
    policy = FrameQuantizationPolicy.from_fps(24.0)
    assert policy.fps_numerator == 24
    assert policy.fps_denominator == 1
    quantizer = FrameQuantizer(policy)
    tb = quantizer.timebase
    assert tb.frame_duration_us == 1_000_000.0 / 24.0

    # Test shots with arbitrary non-aligned microsecond boundaries
    raw_shots = [
        VisualShot(shot_id=1, start_us=0, end_us=3_123_456, duration_us=3_123_456, image_id="1", image_path="1.png"),
        VisualShot(shot_id=2, start_us=3_123_456, end_us=7_890_123, duration_us=4_766_667, image_id="2", image_path="2.png"),
    ]
    q_shots, diag = quantizer.quantize_shots(raw_shots)
    assert len(q_shots) == 2
    assert tb.is_on_grid(q_shots[0].start_us)
    assert tb.is_on_grid(q_shots[0].end_us)
    assert tb.is_on_grid(q_shots[1].start_us)
    assert tb.is_on_grid(q_shots[1].end_us)
    assert q_shots[0].end_us == q_shots[1].start_us


# ==============================================================================
# A2-T02: 25fps quantization
# ==============================================================================
def test_a2_t02_25fps():
    """A2-T02: Verify boundaries and timebase at 25fps (40,000us frame duration)."""
    policy = FrameQuantizationPolicy.from_fps(25.0)
    assert policy.fps_numerator == 25
    quantizer = FrameQuantizer(policy)
    tb = quantizer.timebase
    assert tb.frame_duration_us == 40_000.0

    raw_shots = [
        VisualShot(shot_id=1, start_us=0, end_us=2_515_000, duration_us=2_515_000, image_id="1", image_path="1.png"),
        VisualShot(shot_id=2, start_us=2_515_000, end_us=5_030_000, duration_us=2_515_000, image_id="2", image_path="2.png"),
    ]
    q_shots, diag = quantizer.quantize_shots(raw_shots)
    assert q_shots[0].start_us == 0
    assert q_shots[0].end_us == 2_520_000  # 63 frames * 40,000us
    assert q_shots[1].start_us == 2_520_000
    assert q_shots[1].end_us == 5_040_000  # 126 frames * 40,000us


# ==============================================================================
# A2-T03: 30fps quantization
# ==============================================================================
def test_a2_t03_30fps():
    """A2-T03: Verify boundaries and timebase at 30fps."""
    policy = FrameQuantizationPolicy.from_fps(30.0)
    assert policy.fps_numerator == 30
    quantizer = FrameQuantizer(policy)
    tb = quantizer.timebase

    raw_shots = [
        VisualShot(shot_id=1, start_us=0, end_us=3_000_500, duration_us=3_000_500, image_id="1", image_path="1.png"),
        VisualShot(shot_id=2, start_us=3_000_500, end_us=6_000_200, duration_us=2_999_700, image_id="2", image_path="2.png"),
    ]
    q_shots, diag = quantizer.quantize_shots(raw_shots)
    assert tb.is_on_grid(q_shots[0].end_us)
    assert q_shots[0].end_us == q_shots[1].start_us
    assert q_shots[0].duration_frames == 90  # 3.0s * 30fps


# ==============================================================================
# A2-T04: 60fps quantization
# ==============================================================================
def test_a2_t04_60fps():
    """A2-T04: Verify boundaries and timebase at 60fps (standard production default)."""
    policy = FrameQuantizationPolicy.from_fps(60.0)
    assert policy.fps_numerator == 60
    assert policy.fps_denominator == 1
    quantizer = FrameQuantizer(policy)
    tb = quantizer.timebase

    # 1 frame at 60fps is 16,667 us
    assert tb.us_to_frame_index(16_667) == 1
    assert tb.frame_index_to_us(1) == 16_667
    assert tb.frame_index_to_us(60) == 1_000_000

    raw_shots = [
        VisualShot(shot_id=1, start_us=0, end_us=4_008_333, duration_us=4_008_333, image_id="1", image_path="1.png"),
        VisualShot(shot_id=2, start_us=4_008_333, end_us=8_016_667, duration_us=4_008_334, image_id="2", image_path="2.png"),
    ]
    q_shots, diag = quantizer.quantize_shots(raw_shots)
    assert tb.is_on_grid(q_shots[0].end_us)
    assert q_shots[0].end_us == q_shots[1].start_us
    assert diag.offgrid_boundaries_after == 0


# ==============================================================================
# A2-T05: Rational NTSC fps (30000/1001 ~ 29.97fps)
# ==============================================================================
def test_a2_t05_rational_fps_30000_1001():
    """A2-T05: Verify rational fraction support for NTSC 29.97fps."""
    num, den = rational_fps_from_float(29.97)
    assert num == 30000
    assert den == 1001

    policy = FrameQuantizationPolicy.from_fps(29.97)
    quantizer = FrameQuantizer(policy)
    tb = quantizer.timebase
    assert abs(tb.fps - 29.97002997) < 0.001

    raw_shots = [
        VisualShot(shot_id=1, start_us=0, end_us=10_010_000, duration_us=10_010_000, image_id="1", image_path="1.png"),
    ]
    q_shots, diag = quantizer.quantize_shots(raw_shots)
    # 10,010,000 us is exactly 300 frames at 30000/1001 fps
    assert q_shots[0].duration_frames == 300
    assert tb.is_on_grid(q_shots[0].end_us)


# ==============================================================================
# A2-T06: Nearest-frame boundary rounding
# ==============================================================================
def test_a2_t06_nearest_frame_boundary():
    """A2-T06: Verify nearest-frame rounding error is <= 0.5 frame."""
    policy = FrameQuantizationPolicy.from_fps(60.0)
    quantizer = FrameQuantizer(policy)
    tb = quantizer.timebase
    frame_dur = tb.frame_duration_us

    # Test random arbitrary timestamps across timeline
    test_stamps = [123_456, 1_987_654, 5_432_100, 10_000_001]
    for ts in test_stamps:
        f = tb.us_to_frame_index(ts)
        q_us = tb.frame_index_to_us(f)
        err = abs(q_us - ts)
        err_frames = err / frame_dur
        assert err_frames <= 0.5001, f"Quantization error {err_frames} frames exceeds 0.5"


# ==============================================================================
# A2-T07: Collision handling when two boundaries round to same frame
# ==============================================================================
def test_a2_t07_collision_handling():
    """A2-T07: Deterministic collision repair ensures min_visual_frame_count."""
    policy = FrameQuantizationPolicy(fps_numerator=60, fps_denominator=1, min_visual_frame_count=1)
    quantizer = FrameQuantizer(policy)

    # Shot 1 and Shot 2 end only 5000us apart (both round to frame index 60 at 1,000,000us)
    raw_shots = [
        VisualShot(shot_id=1, start_us=0, end_us=1_000_000, duration_us=1_000_000, image_id="1", image_path="1.png"),
        VisualShot(shot_id=2, start_us=1_000_000, end_us=1_005_000, duration_us=5_000, image_id="2", image_path="2.png"),
        VisualShot(shot_id=3, start_us=1_005_000, end_us=3_000_000, duration_us=1_995_000, image_id="3", image_path="3.png"),
    ]
    q_shots, diag = quantizer.quantize_shots(raw_shots)
    assert diag.collisions_detected >= 1
    assert diag.collision_repairs >= 1
    # Shot 2 must have at least 1 frame
    assert q_shots[1].duration_frames >= 1
    assert q_shots[1].duration_us > 0
    assert q_shots[1].start_us < q_shots[1].end_us
    assert q_shots[0].end_us == q_shots[1].start_us
    assert q_shots[1].end_us == q_shots[2].start_us


# ==============================================================================
# A2-T08: Zero-frame prevention
# ==============================================================================
def test_a2_t08_zero_frame_prevention():
    """A2-T08: Zero-frame shots are strictly prevented and audited."""
    policy = FrameQuantizationPolicy.from_fps(60.0)
    quantizer = FrameQuantizer(policy)
    validator = FrameAccuracyValidator(policy)

    raw_shots = [
        VisualShot(shot_id=1, start_us=0, end_us=100, duration_us=100, image_id="1", image_path="1.png"),
    ]
    q_shots, diag = quantizer.quantize_shots(raw_shots)
    assert q_shots[0].duration_frames >= 1

    # Validator flags zero-frame shots if manually created
    bad_shot = VisualShot(shot_id=99, start_us=1000, end_us=1000, duration_us=0, image_id="x", image_path="x.png")
    report = validator.validate([bad_shot])
    assert not report.is_valid
    assert any(i.check_id == "FRAME-ERR-02" for i in report.issues)


# ==============================================================================
# A2-T09: No cumulative drift (Absolute boundary quantization)
# ==============================================================================
def test_a2_t09_no_cumulative_drift():
    """A2-T09: Adjacent shots share identical boundaries with zero cumulative drift."""
    policy = FrameQuantizationPolicy.from_fps(60.0)
    quantizer = FrameQuantizer(policy)
    tb = quantizer.timebase

    # Generate 100 sequential shots with non-aligned durations
    current_us = 0
    raw_shots = []
    for i in range(100):
        dur = 3_123_456  # Off-grid duration
        raw_shots.append(
            VisualShot(
                shot_id=i + 1,
                start_us=current_us,
                end_us=current_us + dur,
                duration_us=dur,
                image_id=f"{i}",
                image_path=f"{i}.png",
            )
        )
        current_us += dur

    q_shots, diag = quantizer.quantize_shots(raw_shots)
    assert len(q_shots) == 100

    # Verify zero gap / zero overlap between every adjacent pair
    for i in range(1, len(q_shots)):
        assert q_shots[i].start_us == q_shots[i - 1].end_us, f"Drift/gap at shot {i}"

    # Verify total quantized span equals nominal terminal frame
    final_f = tb.us_to_frame_index(current_us)
    expected_term_us = tb.frame_index_to_us(final_f)
    assert q_shots[-1].end_us == expected_term_us


# ==============================================================================
# A2-T10: 278-shot LONG_01 continuity & zero off-grid boundaries
# ==============================================================================
def test_a2_t10_278_shot_long01_continuity(long_01_cues, long_01_images):
    """A2-T10: Real GOLDEN_LONG_01 produces 278 shots with 0 offgrid boundaries."""
    opts = VisualPlannerOptions(
        quantization_policy=FrameQuantizationPolicy.from_fps(60.0)
    )
    adapter = VisualPipelineAdapter(options=opts)
    shots, report = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.2333333333334,
        options=opts,
    )
    assert len(shots) == 278
    assert report.is_valid
    assert not report.has_fatal

    frame_val = FrameAccuracyValidator(policy=opts.quantization_policy)
    f_rep = frame_val.validate(shots, master_audio_duration_us=1787233333)
    assert f_rep.is_valid
    assert f_rep.error_count == 0
    assert f_rep.fatal_count == 0


# ==============================================================================
# A2-T11: Keyframes on legal frame grid
# ==============================================================================
def test_a2_t11_keyframes_on_legal_grid(long_01_cues, long_01_images):
    """A2-T11: Emitted clips have start and end keyframes exactly on frame grid."""
    opts = VisualPlannerOptions(
        quantization_policy=FrameQuantizationPolicy.from_fps(60.0)
    )
    adapter = VisualPipelineAdapter(options=opts)
    shots, _ = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.2333333333334,
        options=opts,
    )
    clips = adapter.shots_to_editplan_clips(shots)
    assert len(clips) == 278

    tb = FrameTimebase(60, 1)
    for i, c in enumerate(clips):
        assert tb.is_on_grid(c.start_us), f"Clip {i} start_us off-grid"
        # For internal clips, end_us is on grid
        if i < len(clips) - 1:
            assert tb.is_on_grid(c.end_us), f"Clip {i} end_us off-grid"


# ==============================================================================
# A2-T12: Keyframe monotonicity
# ==============================================================================
def test_a2_t12_keyframe_monotonicity():
    """A2-T12: Keyframe timestamps must be strictly monotonic and within clip."""
    policy = FrameQuantizationPolicy.from_fps(60.0)
    validator = FrameAccuracyValidator(policy)

    # 1. Monotonic normal clip
    good_shot = VisualShot(
        shot_id=1,
        start_us=0,
        end_us=1_000_000,
        duration_us=1_000_000,
        image_id="1",
        image_path="1.png",
        motion_profile={
            "keyframe_list": [
                {"time_offset": 0, "val": 1.0},
                {"time_offset": 500_000, "val": 1.05},
                {"time_offset": 1_000_000, "val": 1.10},
            ]
        },
    )
    rep = validator.validate([good_shot])
    assert rep.is_valid

    # 2. Duplicate keyframe
    dup_shot = copy.deepcopy(good_shot)
    dup_shot.motion_profile["keyframe_list"][1]["time_offset"] = 0
    rep_dup = validator.validate([dup_shot])
    assert any(i.check_id == "FRAME-ERR-05" for i in rep_dup.issues)

    # 3. Non-monotonic keyframe
    non_mono = copy.deepcopy(good_shot)
    non_mono.motion_profile["keyframe_list"][1]["time_offset"] = 600_000
    non_mono.motion_profile["keyframe_list"][2]["time_offset"] = 400_000
    rep_nm = validator.validate([non_mono])
    assert any(i.check_id == "FRAME-ERR-07" for i in rep_nm.issues)

    # 4. Outside clip
    out_clip = copy.deepcopy(good_shot)
    out_clip.motion_profile["keyframe_list"][2]["time_offset"] = 1_500_000
    rep_out = validator.validate([out_clip])
    assert any(i.check_id == "FRAME-ERR-06" for i in rep_out.issues)


# ==============================================================================
# A2-T13: A1 image order unchanged
# ==============================================================================
def test_a2_t13_a1_image_order_unchanged(long_01_cues, long_01_images):
    """A2-T13: Quantization preserves exact image sequence allocated by A1."""
    opts = VisualPlannerOptions(
        quantization_policy=FrameQuantizationPolicy.from_fps(60.0)
    )
    adapter = VisualPipelineAdapter(options=opts)
    shots, _ = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.2333333333334,
        options=opts,
    )
    image_seq = [s.image_path for s in shots]
    assert len(image_seq) == 278
    # Image 1 to 278 allocated in sequence
    assert image_seq[0] == "anh_kb001.png"
    assert image_seq[-1] == "anh_kb278.png"


# ==============================================================================
# A2-T14: A0 subtitle unchanged (Bit-for-bit hash preservation)
# ==============================================================================
def test_a2_t14_a0_subtitle_unchanged(long_01_cues, long_01_images):
    """A2-T14: Subtitle cue timing and text remain identical before and after A2."""
    cues_before = copy.deepcopy(long_01_cues)
    hash_before = hashlib.sha256(
        "".join(f"{c.index}:{c.start_s:.3f}:{c.end_s:.3f}:{c.text}" for c in cues_before).encode("utf-8")
    ).hexdigest()

    opts = VisualPlannerOptions(
        quantization_policy=FrameQuantizationPolicy.from_fps(60.0)
    )
    adapter = VisualPipelineAdapter(options=opts)
    shots, _ = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.2333333333334,
        options=opts,
    )

    hash_after = hashlib.sha256(
        "".join(f"{c.index}:{c.start_s:.3f}:{c.end_s:.3f}:{c.text}" for c in long_01_cues).encode("utf-8")
    ).hexdigest()

    assert hash_before == hash_after, "A0 subtitle cues were mutated by A2 visual planning"


# ==============================================================================
# A2-T15: Tail coverage unchanged (zero black tail)
# ==============================================================================
def test_a2_t15_tail_coverage_unchanged(long_01_cues, long_01_images):
    """A2-T15: Terminal visual end matches master audio end; black tail = 0."""
    audio_dur_s = 1787.2333333333334
    audio_dur_us = 1787233333
    opts = VisualPlannerOptions(
        quantization_policy=FrameQuantizationPolicy.from_fps(60.0)
    )
    adapter = VisualPipelineAdapter(options=opts)
    shots, _ = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=audio_dur_s,
        options=opts,
    )
    assert shots[-1].end_us == audio_dur_us
    black_tail = max(0.0, (audio_dur_us - shots[-1].end_us) / 1_000_000.0)
    assert black_tail == 0.0


# ==============================================================================
# A2-T16: Determinism
# ==============================================================================
def test_a2_t16_determinism(long_01_cues, long_01_images):
    """A2-T16: Same inputs produce bit-for-bit identical frame indices across runs."""
    opts = VisualPlannerOptions(
        quantization_policy=FrameQuantizationPolicy.from_fps(60.0)
    )
    adapter = VisualPipelineAdapter(options=opts)
    shots1, _ = adapter.plan_visual_shots(long_01_cues, long_01_images, 1787.2333333333334, options=opts)
    shots2, _ = adapter.plan_visual_shots(long_01_cues, long_01_images, 1787.2333333333334, options=opts)

    assert len(shots1) == len(shots2) == 278
    for s1, s2 in zip(shots1, shots2):
        assert s1.start_us == s2.start_us
        assert s1.end_us == s2.end_us
        assert s1.duration_us == s2.duration_us
        assert s1.start_frame == s2.start_frame
        assert s1.end_frame == s2.end_frame
        assert s1.duration_frames == s2.duration_frames


# ==============================================================================
# A2-T17: Normalized Draft regression
# ==============================================================================
def test_a2_t17_normalized_draft_regression(long_01_cues, tmp_path):
    """A2-T17: Normalized CapCut draft contains 0 gaps, 0 overlaps, and valid frame grid."""
    dummy_img_path = tmp_path / "dummy_sample.png"
    im = Image.new("RGB", (1080, 1920), color=(100, 150, 200))
    im.save(dummy_img_path)
    real_images = [str(dummy_img_path)] * 278

    tb = TimelineBuilder()
    plan = tb.build(
        images=real_images,
        timing_mode="SRT_DRIVEN",
        subtitle_cues=long_01_cues,
        audio_duration_s=1787.2333333333334,
        visual_options=VisualPlannerOptions(
            quantization_policy=FrameQuantizationPolicy.from_fps(60.0)
        ),
    )
    assert len(plan.clips) == 278

    # Validate clips frame accuracy directly
    frame_val = FrameAccuracyValidator(policy=FrameQuantizationPolicy.from_fps(60.0))
    clip_rep = frame_val.validate_clips(plan.clips, master_audio_duration_us=1787233333)
    assert clip_rep.is_valid
    assert clip_rep.error_count == 0

    # Generate draft directory
    out_dir = str(tmp_path / "a2_capcut_draft")
    adapter = CapCutAdapter()
    gen_result = adapter.generate(plan, target_dir=out_dir, draft_root_path=str(tmp_path))
    assert gen_result["validated"] is True

    draft_info_path = os.path.join(out_dir, "draft_info.json")
    assert os.path.isfile(draft_info_path)
    with open(draft_info_path, "r", encoding="utf-8") as f:
        draft_dict = json.load(f)

    # Validate draft frame accuracy
    report = frame_val.validate_draft(draft_dict, master_audio_duration_us=1787233333)
    assert report.is_valid
    assert report.error_count == 0
    assert report.fatal_count == 0

    # Validate draft normalization
    from core.dev_tools.draft_normalizer import DraftNormalizer
    norm_dict = DraftNormalizer.normalize_draft_info(draft_dict)
    assert norm_dict is not None
    assert norm_dict["fps"] == 60.0

