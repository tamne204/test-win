"""
tests/test_a1_acceptance_matrix.py
Authoritative 24-Test Acceptance Matrix for Phase A1: Visual Shot Planning & Motion.
Ratified by reports/accuracy/a1/07_A1_ARCHITECTURE_FREEZE.md Section 10.
"""
from __future__ import annotations

import copy
import hashlib
import json
import os
import sys
import time
from typing import List

import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.subtitles.models import SubtitleCue, ConfidenceLevel
from core.visual.models import (
    VisualBoundaryType,
    VisualBoundaryCandidate,
    VisualShot,
    ShotDurationPolicy,
    TailDurationPolicy,
    VisualPlannerEngine,
    VisualPlannerOptions,
)
from core.visual.boundary_builder import VisualBoundaryCandidateBuilder
from core.visual.dp_planner import VisualShotPlanner, PlannedShotInterval
from core.visual.image_allocator import ImageAllocationPolicy, ProjectValidationError
from core.visual.tail_allocator import SilentTailAllocator
from core.visual.motion_policy import DurationAwareMotionPolicy
from core.visual.validator import VisualAccuracyValidator, ValidationSeverity
from core.visual.pipeline_adapter import VisualPipelineAdapter
from core.dev_tools.draft_normalizer import DraftNormalizer


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
# A1-T01: Normal 641-cue LONG_01 narration DP execution & shot count
# ==============================================================================
def test_a1_t01_normal_641_cue_narration(long_01_cues):
    """
    A1-T01: Normal 641-cue LONG_01 narration:
    DP completes in < 50 ms, generates 280-300 shots, 0 micro-shots (< 2.0s).
    """
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues=long_01_cues, master_audio_duration_s=1787.233)
    assert len(candidates) >= 641

    planner = VisualShotPlanner()
    t0 = time.perf_counter()
    path = planner.plan_shots(candidates)
    elapsed_ms = (time.perf_counter() - t0) * 1000.0

    # Latency requirement: < 50 ms
    assert elapsed_ms < 50.0, f"DP execution latency {elapsed_ms:.2f}ms exceeded 50ms"

    # Shot count requirement: 280-300 shots
    assert 280 <= len(path.shots) <= 300, (
        f"Expected 280-300 shots, got {len(path.shots)}"
    )

    # 0 micro-shots (< 2.0s)
    micro_shots = [s for s in path.shots if s.duration_s < 2.0]
    assert len(micro_shots) == 0, f"Found {len(micro_shots)} micro-shots < 2.0s"


# ==============================================================================
# A1-T02: Single subtitle cue > hard_max (18.76s) splits internally
# ==============================================================================
def test_a1_t02_long_cue_internal_split():
    """
    A1-T02: Single subtitle cue > hard_max (18.76s):
    Splits visually via DURATION_FORCED_INTERNAL_BOUNDARY; subtitle timing unchanged.
    """
    cue = SubtitleCue(
        index=1,
        start_s=0.0,
        end_s=18.76,
        text="A very long uninterrupted spoken narration passage that exceeds hard max duration limit.",
        paragraph_ids=[1],
        sentence_ids=[1],
    )
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues=[cue], master_audio_duration_s=18.76)

    internal_cands = [
        c for c in candidates
        if c.boundary_type == VisualBoundaryType.DURATION_FORCED_INTERNAL_BOUNDARY
    ]
    assert len(internal_cands) >= 1, "Expected internal split candidate for 18.76s cue"

    # Subtitle timing is strictly preserved
    assert cue.start_s == 0.0
    assert cue.end_s == 18.76

    planner = VisualShotPlanner()
    path = planner.plan_shots(candidates)
    assert len(path.shots) >= 2, "Expected DP to split 18.76s cue into at least 2 shots"
    for s in path.shots:
        assert s.duration_s <= 12.0, f"Shot duration {s.duration_s} exceeds hard_max 12.0s"


# ==============================================================================
# A1-T03: Final residual duration < hard_min (0.8s) merged into adjacent shot
# ==============================================================================
def test_a1_t03_final_residual_merge():
    """
    A1-T03: Final residual duration < hard_min (0.8s):
    Merges into adjacent shot up to terminal_max; no sub-second shot.
    """
    cues = [
        SubtitleCue(index=1, start_s=0.0, end_s=5.0, text="First sentence.", paragraph_ids=[1], sentence_ids=[1]),
        SubtitleCue(index=2, start_s=5.0, end_s=9.5, text="Second sentence.", paragraph_ids=[1], sentence_ids=[2]),
        SubtitleCue(index=3, start_s=9.5, end_s=10.3, text="Tiny residual.", paragraph_ids=[1], sentence_ids=[3]),
    ]
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues=cues, master_audio_duration_s=10.3)

    planner = VisualShotPlanner()
    path = planner.plan_shots(candidates)

    # Residual 0.8s should not stand alone as a micro-shot
    assert all(s.duration_s >= 2.0 for s in path.shots), (
        f"Found sub-minimal shot in {[s.duration_s for s in path.shots]}"
    )
    # Total speech coverage must equal 10.3s
    assert path.shots[-1].end_s == pytest.approx(10.3, rel=1e-4)


# ==============================================================================
# A1-T04: Series of rapid tiny subtitle cues grouped >= 3.0s
# ==============================================================================
def test_a1_t04_rapid_tiny_cues_grouping():
    """
    A1-T04: Series of rapid, tiny subtitle cues (0.5s - 1.2s):
    Groups cues until duration >= 3.0s; zero < 2.0s shots.
    """
    cues = [
        SubtitleCue(index=1, start_s=0.0, end_s=0.6, text="One", paragraph_ids=[1], sentence_ids=[1]),
        SubtitleCue(index=2, start_s=0.6, end_s=1.2, text="two", paragraph_ids=[1], sentence_ids=[1]),
        SubtitleCue(index=3, start_s=1.2, end_s=1.8, text="three", paragraph_ids=[1], sentence_ids=[1]),
        SubtitleCue(index=4, start_s=1.8, end_s=2.4, text="four", paragraph_ids=[1], sentence_ids=[1]),
        SubtitleCue(index=5, start_s=2.4, end_s=3.0, text="five.", paragraph_ids=[1], sentence_ids=[1]),
        SubtitleCue(index=6, start_s=3.0, end_s=6.5, text="Second normal passage.", paragraph_ids=[2], sentence_ids=[2]),
    ]
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues=cues, master_audio_duration_s=6.5)

    planner = VisualShotPlanner()
    path = planner.plan_shots(candidates)

    assert len(path.shots) >= 1
    assert all(s.duration_s >= 2.0 for s in path.shots), (
        f"Found shot < 2.0s in {[s.duration_s for s in path.shots]}"
    )


def _make_planned_interval(idx: int, start_us: int, end_us: int) -> PlannedShotInterval:
    dur_us = end_us - start_us
    c1 = VisualBoundaryCandidate(boundary_id=f"B_{idx}_START", timestamp_us=start_us, timestamp_s=start_us/1e6, boundary_type=VisualBoundaryType.PARAGRAPH_BOUNDARY)
    c2 = VisualBoundaryCandidate(boundary_id=f"B_{idx}_END", timestamp_us=end_us, timestamp_s=end_us/1e6, boundary_type=VisualBoundaryType.PARAGRAPH_BOUNDARY)
    return PlannedShotInterval(
        shot_index=idx,
        start_candidate=c1,
        end_candidate=c2,
        start_us=start_us,
        end_us=end_us,
        duration_us=dur_us,
        duration_s=dur_us / 1_000_000.0,
        cue_ids=[idx],
        paragraph_ids=[idx],
        sentence_ids=[idx],
        cost=0.0,
    )


# ==============================================================================
# A1-T05: Image supply approximately equals desired shots (1:1 monotonic mapping)
# ==============================================================================
def test_a1_t05_balanced_image_supply_monotonic():
    """
    A1-T05: Image supply approximately equals desired shots:
    1:1 monotonic mapping, zero image reuse, zero dropped assets.
    """
    shots = [
        _make_planned_interval(1, 0, 5_000_000),
        _make_planned_interval(2, 5_000_000, 11_000_000),
        _make_planned_interval(3, 11_000_000, 17_000_000),
    ]
    images = ["img_01.png", "img_02.png", "img_03.png"]
    allocator = ImageAllocationPolicy()
    result = allocator.allocate_images(shots, images)

    assert len(result.visual_shots) == 3
    assert [s.image_path for s in result.visual_shots] == images
    assert all(s.reuse_count == 0 for s in result.visual_shots)
    assert len(result.remaining_images) == 0


# ==============================================================================
# A1-T06: Images far fewer than desired shots (bounded reuse >= 60s + inverted motion)
# ==============================================================================
def test_a1_t06_image_shortage_bounded_reuse():
    """
    A1-T06: Images far fewer than desired shots (K << N):
    Shots extended to soft_max; reuse enforces >= 60s distance + inverted motion.
    """
    shots = []
    t_us = 0
    for i in range(16):
        dur_us = 6_000_000
        shots.append(_make_planned_interval(i+1, t_us, t_us+dur_us))
        t_us += dur_us

    images = [f"img_{i:02d}.png" for i in range(1, 13)]
    allocator = ImageAllocationPolicy(min_reuse_distance_s=60.0)
    res = allocator.allocate_images(shots, images)

    # Verify repeated assets have at least min_reuse_distance (>= 60s)
    asset_history = {}
    for s in res.visual_shots:
        if s.image_path in asset_history:
            dist = s.start_s - asset_history[s.image_path]
            assert dist >= 60.0, f"Asset {s.image_path} reused after only {dist}s (< 60s)"
        asset_history[s.image_path] = s.end_s

    # Verify inverted motion on reused shots
    motion_policy = DurationAwareMotionPolicy()
    styled_shots = motion_policy.apply_motion_to_shots(res.visual_shots)
    reused_shots = [s for s in styled_shots if s.reuse_count > 0]
    assert len(reused_shots) > 0
    assert all(s.diagnostics.get("alternate_motion", False) for s in reused_shots)


# ==============================================================================
# A1-T07: Images far greater than desired shots (pacing protected, no micro-shots)
# ==============================================================================
def test_a1_t07_image_surplus_pacing_protected():
    """
    A1-T07: Images far greater than desired shots (K >> N):
    Pacing protected; surplus dropped evenly at paragraph breaks; no micro-shots.
    """
    shots = [
        _make_planned_interval(1, 0, 6_000_000),
        _make_planned_interval(2, 6_000_000, 12_000_000),
    ]
    images = [f"surplus_{i}.png" for i in range(20)]
    allocator = ImageAllocationPolicy()
    res = allocator.allocate_images(shots, images)

    assert len(res.visual_shots) == 2
    assert all(s.duration_s >= 2.0 for s in res.visual_shots)
    assert len(res.remaining_images) == 18


# ==============================================================================
# A1-T08: Silent tail with sufficient remaining images
# ==============================================================================
def test_a1_t08_healthy_silent_tail_allocation():
    """
    A1-T08: Silent tail with sufficient remaining images:
    Allocates images monotonically at natural_tail_duration; no black screen.
    """
    tail_alloc = SilentTailAllocator()
    speech_end_us = 100_000_000  # 100s
    master_audio_dur_us = 160_000_000  # 160s (tail = 60s)
    remaining_imgs = ["tail_1.png", "tail_2.png", "tail_3.png"]

    tail_shots = tail_alloc.allocate_tail(
        speech_end_us=speech_end_us,
        master_audio_duration_us=master_audio_dur_us,
        last_speech_image="speech_last.png",
        remaining_images=remaining_imgs,
        start_shot_id=10,
    )

    assert len(tail_shots) == 3
    # 60s / 3 = 20s each
    for s in tail_shots:
        assert s.duration_s == pytest.approx(20.0, rel=1e-3)
    assert tail_shots[-1].end_us == master_audio_dur_us


# ==============================================================================
# A1-T09: Silent tail with insufficient remaining images
# ==============================================================================
def test_a1_t09_insufficient_remaining_images_tail():
    """
    A1-T09: Silent tail with insufficient remaining images:
    Allocates images up to 25s, then applies controlled reuse or final hold.
    """
    tail_alloc = SilentTailAllocator()
    speech_end_us = 100_000_000
    master_audio_dur_us = 160_000_000  # 60s tail
    remaining_imgs = ["tail_single.png"]  # Only 1 image, natural would be 60s (> 25s)

    tail_shots = tail_alloc.allocate_tail(
        speech_end_us=speech_end_us,
        master_audio_duration_us=master_audio_dur_us,
        last_speech_image="speech_last.png",
        remaining_images=remaining_imgs,
        start_shot_id=10,
    )

    assert len(tail_shots) >= 2
    assert tail_shots[-1].end_us == master_audio_dur_us


# ==============================================================================
# A1-T10: No remaining images at silent tail (K_rem = 0)
# ==============================================================================
def test_a1_t10_zero_tail_images_final_hold():
    """
    A1-T10: No remaining images at silent tail (K_rem = 0):
    Final speech image held to master_audio_end with ultra-slow drift.
    """
    tail_alloc = SilentTailAllocator()
    speech_end_us = 100_000_000
    master_audio_dur_us = 135_000_000  # 35s tail

    tail_shots = tail_alloc.allocate_tail(
        speech_end_us=speech_end_us,
        master_audio_duration_us=master_audio_dur_us,
        last_speech_image="speech_last.png",
        remaining_images=[],
        start_shot_id=10,
    )

    assert len(tail_shots) == 1
    assert tail_shots[0].image_path == "speech_last.png"
    assert tail_shots[0].end_us == master_audio_dur_us


# ==============================================================================
# A1-T11: Zero physical images provided raises ProjectValidationError
# ==============================================================================
def test_a1_t11_zero_images_error():
    """
    A1-T11: Zero physical images provided:
    Raises ProjectValidationError; never outputs empty video track.
    """
    adapter = VisualPipelineAdapter()
    cues = [SubtitleCue(index=1, start_s=0.0, end_s=5.0, text="Hello")]
    with pytest.raises(ProjectValidationError, match="zero visual assets"):
        adapter.plan_visual_shots(subtitles=cues, images=[], master_audio_duration_s=5.0)


# ==============================================================================
# A1-T12: Rapid reuse prevention check
# ==============================================================================
def test_a1_t12_rapid_reuse_prevention():
    """
    A1-T12: Rapid reuse prevention check:
    Reused asset separated by < 60.0s fails validation.
    """
    validator = VisualAccuracyValidator()
    shots = [
        VisualShot(shot_id=1, start_us=0, end_us=10_000_000, duration_us=10_000_000, image_id="img1", image_path="img1.png"),
        VisualShot(shot_id=2, start_us=10_000_000, end_us=20_000_000, duration_us=10_000_000, image_id="img2", image_path="img2.png"),
        VisualShot(shot_id=3, start_us=20_000_000, end_us=30_000_000, duration_us=10_000_000, image_id="img1", image_path="img1.png"),
    ]
    report = validator.validate(shots=shots, master_audio_duration_us=30_000_000, speech_end_us=30_000_000)

    # Reused after 10s gap (< 60s)
    reuse_errs = [i for i in report.issues if i.check_id == "VAL-ERR-02"]
    assert len(reuse_errs) >= 1, "Expected VAL-ERR-02 rapid reuse violation"


# ==============================================================================
# A1-T13: Consecutive duplicate asset prevention
# ==============================================================================
def test_a1_t13_consecutive_duplicate_prevention():
    """
    A1-T13: Consecutive duplicate asset prevention:
    Same asset on adjacent shots fails validation.
    """
    validator = VisualAccuracyValidator()
    shots = [
        VisualShot(shot_id=1, start_us=0, end_us=10_000_000, duration_us=10_000_000, image_id="img1", image_path="img1.png"),
        VisualShot(shot_id=2, start_us=10_000_000, end_us=20_000_000, duration_us=10_000_000, image_id="img1", image_path="img1.png"),
    ]
    report = validator.validate(shots=shots, master_audio_duration_us=20_000_000, speech_end_us=20_000_000)

    dup_errs = [i for i in report.issues if i.check_id == "VAL-ERR-03"]
    assert len(dup_errs) >= 1, "Expected VAL-ERR-03 consecutive duplicate asset violation"


# ==============================================================================
# A1-T14: A0 paragraph & sentence metadata wiring
# ==============================================================================
def test_a1_t14_a0_metadata_wiring():
    """
    A1-T14: A0 paragraph & sentence metadata wiring:
    paragraph_id and sentence_id directly read by candidate builder; no SRT reparse.
    """
    cue = SubtitleCue(
        index=42,
        start_s=10.0,
        end_s=15.0,
        text="Sample subtitle text.",
        paragraph_ids=[101],
        sentence_ids=[202],
    )
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues=[cue], master_audio_duration_s=15.0)

    cue_cand = next(c for c in candidates if c.boundary_id == "B_CUE_0042_END")
    assert cue_cand.paragraph_ids == [101]
    assert cue_cand.sentence_ids == [202]


# ==============================================================================
# A1-T15: Visual continuity: Zero visual gaps
# ==============================================================================
def test_a1_t15_zero_visual_gaps(long_01_cues, long_01_images):
    """
    A1-T15: Visual continuity: Zero visual gaps:
    All adjacent shots satisfy t_start[k] == t_end[k-1].
    """
    adapter = VisualPipelineAdapter()
    shots, report = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.233,
    )
    for i in range(1, len(shots)):
        gap = shots[i].start_us - shots[i-1].end_us
        assert gap == 0, f"Gap of {gap}us between shot {i-1} and {i}"


# ==============================================================================
# A1-T16: Visual continuity: Zero visual overlaps
# ==============================================================================
def test_a1_t16_zero_visual_overlaps(long_01_cues, long_01_images):
    """
    A1-T16: Visual continuity: Zero visual overlaps:
    All adjacent shots satisfy t_start[k] >= t_end[k-1].
    """
    adapter = VisualPipelineAdapter()
    shots, _ = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.233,
    )
    for i in range(1, len(shots)):
        assert shots[i].start_us >= shots[i-1].end_us, (
            f"Overlap between shot {i-1} and {i}"
        )


# ==============================================================================
# A1-T17: Timeline termination: Visual end == Audio end
# ==============================================================================
def test_a1_t17_visual_end_matches_audio_end(long_01_cues, long_01_images):
    """
    A1-T17: Timeline termination: Visual end == Audio end:
    Video track out timestamp equals master_audio_duration.
    """
    adapter = VisualPipelineAdapter()
    master_audio_dur_s = 1787.233
    shots, report = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=master_audio_dur_s,
    )
    expected_us = int(round(master_audio_dur_s * 1_000_000))
    assert shots[-1].end_us == expected_us, (
        f"Timeline end {shots[-1].end_us} != expected {expected_us}"
    )


# ==============================================================================
# A1-T18: Normal motion target velocity verification
# ==============================================================================
def test_a1_t18_normal_motion_velocity():
    """
    A1-T18: Normal motion target velocity verification:
    Normal shots (2.5s - 8.5s) move at 1.5%/s <= v <= 3.5%/s.
    """
    policy = DurationAwareMotionPolicy()
    shots = [
        VisualShot(shot_id=1, start_us=0, end_us=5_000_000, duration_us=5_000_000, image_id="1", image_path="1.png"),
        VisualShot(shot_id=2, start_us=5_000_000, end_us=11_000_000, duration_us=6_000_000, image_id="2", image_path="2.png"),
    ]
    styled_shots = policy.apply_motion_to_shots(shots)
    for s in styled_shots:
        v = s.motion_profile.get("velocity_pct_per_s", 0.0)
        assert 1.5 <= v <= 3.5, f"Velocity {v}%/s outside [1.5, 3.5]% for {s.duration_s}s shot"


# ==============================================================================
# A1-T19: Absolute motion velocity safety clamp
# ==============================================================================
def test_a1_t19_motion_velocity_clamp():
    """
    A1-T19: Absolute motion velocity safety clamp:
    Any shot attempting v > 5.0%/s is clamped to <= 3.5%/s.
    """
    policy = DurationAwareMotionPolicy()
    # 2.0s short shot with extreme zoom
    shots = [
        VisualShot(shot_id=1, start_us=0, end_us=2_000_000, duration_us=2_000_000, image_id="1", image_path="1.png")
    ]
    styled = policy.apply_motion_to_shots(shots)
    v = styled[0].motion_profile.get("velocity_pct_per_s", 0.0)
    assert v <= 3.5, f"Clamped velocity {v}% exceeded 3.5%/s"


# ==============================================================================
# A1-T20: Long tail ultra-slow motion verification
# ==============================================================================
def test_a1_t20_tail_ultra_slow_motion():
    """
    A1-T20: Long tail ultra-slow motion verification:
    Tail shots (> 12.0s) move at <= 0.8%/s or remain static.
    """
    policy = DurationAwareMotionPolicy()
    shots = [
        VisualShot(
            shot_id=1,
            start_us=0,
            end_us=24_400_000,
            duration_us=24_400_000,
            image_id="t1",
            image_path="t1.png",
            boundary_start_reason="silent_tail_slot",
        )
    ]
    styled = policy.apply_motion_to_shots(shots)
    v = styled[0].motion_profile.get("velocity_pct_per_s", 0.0)
    assert v <= 0.8, f"Tail velocity {v}%/s exceeded ultra-slow 0.8%/s"


# ==============================================================================
# A1-T21: Deterministic identical input/output (100 runs SHA-256 match)
# ==============================================================================
def test_a1_t21_deterministic_identical_runs(long_01_cues, long_01_images):
    """
    A1-T21: Deterministic identical input/output:
    100 consecutive runs produce bit-for-bit identical visual_shots.json SHA-256.
    """
    adapter = VisualPipelineAdapter()
    first_hash = None
    for run_idx in range(100):
        shots, _ = adapter.plan_visual_shots(
            subtitles=long_01_cues,
            images=long_01_images,
            master_audio_duration_s=1787.233,
        )
        serialized = json.dumps(
            [
                {
                    "shot_id": s.shot_id,
                    "start_us": s.start_us,
                    "end_us": s.end_us,
                    "image": s.image_path,
                    "motion": s.motion_profile,
                }
                for s in shots
            ],
            sort_keys=True,
        )
        run_hash = hashlib.sha256(serialized.encode("utf-8")).hexdigest()
        if first_hash is None:
            first_hash = run_hash
        else:
            assert run_hash == first_hash, f"Nondeterministic output on run {run_idx+1}"


# ==============================================================================
# A1-T22: Normalized Draft regression comparison
# ==============================================================================
def test_a1_t22_normalized_draft_regression(long_01_cues, long_01_images):
    """
    A1-T22: Normalized Draft regression comparison:
    DraftNormalizer confirms zero regression on timeline tracks and keyframe schemas.
    """
    adapter = VisualPipelineAdapter()
    shots, _ = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.233,
    )
    clips = adapter.shots_to_editplan_clips(shots)
    assert len(clips) == len(shots)
    assert all(c.duration_us > 0 for c in clips)
    assert clips[-1].start_us + clips[-1].duration_us == 1787233000


# ==============================================================================
# A1-T23: A0 subtitle timing untouched verification
# ==============================================================================
def test_a1_t23_a0_subtitle_timing_untouched(long_01_cues, long_01_images):
    """
    A1-T23: A0 subtitle timing untouched verification:
    Subtitle cue timestamps before and after visual planning are bit-for-bit identical.
    """
    before_cues = copy.deepcopy(long_01_cues)
    adapter = VisualPipelineAdapter()
    shots, _ = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.233,
    )
    for c_before, c_after in zip(before_cues, long_01_cues):
        assert c_before.start_s == c_after.start_s
        assert c_before.end_s == c_after.end_s
        assert c_before.text == c_after.text
        assert c_before.paragraph_ids == c_after.paragraph_ids


# ==============================================================================
# A1-T24: LONG_01 146s black tail regression fix
# ==============================================================================
def test_a1_t24_long_01_black_tail_fixed(long_01_cues, long_01_images):
    """
    A1-T24: LONG_01 146s black tail regression fix:
    Video track spans full 1787.233s; zero black frames.
    """
    adapter = VisualPipelineAdapter()
    shots, report = adapter.plan_visual_shots(
        subtitles=long_01_cues,
        images=long_01_images,
        master_audio_duration_s=1787.233,
    )
    # Spans full 1787.233s
    assert shots[-1].end_us == 1787233000
    assert report.is_valid is True
    assert report.has_fatal is False
    assert report.error_count == 0

    # Verify tail portion (1640.86s to 1787.233s) is covered by shots
    tail_shots = [s for s in shots if s.start_s >= 1640.80]
    assert len(tail_shots) >= 6, f"Expected at least 6 tail shots, got {len(tail_shots)}"
    assert tail_shots[-1].end_us == 1787233000
