"""
tests/test_visual_image_and_tail.py
Unit tests for ImageAllocationPolicy and SilentTailAllocator.
"""
import pytest
from core.visual.models import (
    VisualBoundaryCandidate,
    VisualBoundaryType,
    ShotDurationPolicy,
    TailDurationPolicy,
)
from core.visual.dp_planner import PlannedShotInterval
from core.visual.image_allocator import ImageAllocationPolicy, ProjectValidationError
from core.visual.tail_allocator import SilentTailAllocator


def _make_planned_shot(index: int, start_s: float, end_s: float) -> PlannedShotInterval:
    c_start = VisualBoundaryCandidate(f"B_{index}_START", int(round(start_s * 1_000_000)), start_s, VisualBoundaryType.SUBTITLE_BOUNDARY)
    c_end = VisualBoundaryCandidate(f"B_{index}_END", int(round(end_s * 1_000_000)), end_s, VisualBoundaryType.SUBTITLE_BOUNDARY)
    return PlannedShotInterval(
        shot_index=index,
        start_candidate=c_start,
        end_candidate=c_end,
        start_us=c_start.timestamp_us,
        end_us=c_end.timestamp_us,
        duration_us=c_end.timestamp_us - c_start.timestamp_us,
        duration_s=end_s - start_s,
        cue_ids=[index],
        paragraph_ids=[index],
        sentence_ids=[index],
        cost=1.0,
    )


def test_zero_image_validation():
    """Verify that zero physical images raises ProjectValidationError."""
    allocator = ImageAllocationPolicy()
    shots = [_make_planned_shot(0, 0.0, 5.0)]
    with pytest.raises(ProjectValidationError, match="No physical visual assets supplied"):
        allocator.allocate_images(shots, [])


def test_normal_monotonic_allocation():
    """Verify images are assigned forward monotonically with surplus left for tail."""
    allocator = ImageAllocationPolicy()
    shots = [
        _make_planned_shot(0, 0.0, 5.0),
        _make_planned_shot(1, 5.0, 10.0),
    ]
    images = ["img1.png", "img2.png", "img3.png", "img4.png"]
    res = allocator.allocate_images(shots, images)

    assert len(res.visual_shots) == 2
    assert res.visual_shots[0].image_path == "img1.png"
    assert res.visual_shots[1].image_path == "img2.png"
    assert res.used_images == ["img1.png", "img2.png"]
    assert res.remaining_images == ["img3.png", "img4.png"]
    assert res.reuse_count == 0


def test_shortage_reuse_distance_and_no_adjacent():
    """Verify that image shortage reuses oldest images and enforces distance >= 60s."""
    allocator = ImageAllocationPolicy(min_reuse_distance_s=60.0)
    # 20 shots of 5.0s = 100s total
    shots = [_make_planned_shot(i, i * 5.0, (i + 1) * 5.0) for i in range(20)]
    # Only 5 images available
    images = [f"img_{i}.png" for i in range(5)]

    res = allocator.allocate_images(shots, images)

    assert len(res.visual_shots) == 20
    assert res.reuse_count == 15
    # Verify no two adjacent shots share the same image
    for i in range(len(res.visual_shots) - 1):
        assert res.visual_shots[i].image_path != res.visual_shots[i + 1].image_path


def test_silent_tail_case_a_sufficient_images():
    """Verify Case A: 6 remaining images cover 146.37s tail evenly with zero black screen."""
    tail_alloc = SilentTailAllocator()
    speech_end_us = 1640_860_000
    master_audio_end_us = 1787_233_333
    remaining = [f"tail_{i}.png" for i in range(6)]

    tail_shots = tail_alloc.allocate_tail(
        speech_end_us=speech_end_us,
        master_audio_duration_us=master_audio_end_us,
        last_speech_image="speech_last.png",
        remaining_images=remaining,
        start_shot_id=271,
    )

    assert len(tail_shots) == 6
    assert tail_shots[0].start_us == speech_end_us
    assert tail_shots[-1].end_us == master_audio_end_us
    # Check no gap between adjacent tail shots
    for i in range(len(tail_shots) - 1):
        assert tail_shots[i].end_us == tail_shots[i + 1].start_us
        assert tail_shots[i].image_path == remaining[i]
    # Check pacing is ~24.4s per shot
    for s in tail_shots:
        assert s.duration_s == pytest.approx(24.39, abs=0.5)


def test_silent_tail_case_c_zero_remaining_images():
    """Verify Case C: zero remaining images extends final image to audio end with zero black screen."""
    tail_alloc = SilentTailAllocator()
    speech_end_us = 10_000_000
    master_audio_end_us = 25_000_000

    tail_shots = tail_alloc.allocate_tail(
        speech_end_us=speech_end_us,
        master_audio_duration_us=master_audio_end_us,
        last_speech_image="final_pic.png",
        remaining_images=[],
        start_shot_id=5,
    )

    assert len(tail_shots) == 1
    assert tail_shots[0].start_us == speech_end_us
    assert tail_shots[0].end_us == master_audio_end_us
    assert tail_shots[0].image_path == "final_pic.png"
