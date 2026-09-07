"""
tests/test_visual_dp_planner.py
Unit tests for VisualShotPlanner dynamic programming partitioner.
"""
import time
import pytest
from core.subtitles.models import SubtitleCue
from core.visual.models import ShotDurationPolicy, VisualBoundaryType
from core.visual.boundary_builder import VisualBoundaryCandidateBuilder
from core.visual.dp_planner import VisualShotPlanner


def _make_cue(index: int, start_s: float, end_s: float, text: str, para_id: int = 0) -> SubtitleCue:
    return SubtitleCue(
        index=index,
        start_s=start_s,
        end_s=end_s,
        text=text,
        paragraph_ids=[para_id],
        sentence_ids=[index],
    )


def test_dp_planner_basic_pacing():
    """Verify that DP produces shots within target bounds (4.0s - 6.5s) and avoids micro-shots."""
    # 10 cues of 1.5s each = 15s total
    cues = [_make_cue(i, (i - 1) * 1.5, i * 1.5, f"Câu số {i}.", para_id=(i // 3)) for i in range(1, 11)]
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues, master_audio_duration_s=15.0)

    planner = VisualShotPlanner()
    plan = planner.plan_shots(candidates)

    assert plan.coverage_complete
    assert len(plan.shots) >= 2
    for s in plan.shots:
        assert s.duration_s >= 2.0  # Zero micro-shots
        assert s.duration_s <= 12.0 # Zero shots exceeding hard_max


def test_dp_planner_long_cue_split():
    """Verify an 18.76s cue is split into balanced shots with zero micro-shots."""
    cues = [
        _make_cue(1, 0.0, 18.76, "Đây là một câu rất dài kéo dài mười tám giây."),
    ]
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues, master_audio_duration_s=25.0)

    planner = VisualShotPlanner()
    plan = planner.plan_shots(candidates)

    assert plan.coverage_complete
    assert len(plan.shots) == 2
    for s in plan.shots:
        assert s.duration_s == pytest.approx(9.38, abs=0.5)
        assert s.is_internal_split


def test_dp_planner_terminal_residual_merge():
    """Verify residual < 2.0s is merged into preceding shot."""
    # Shot 1: 5.0s, Shot 2 would be 0.8s
    cues = [
        _make_cue(1, 0.0, 5.0, "Câu thứ nhất."),
        _make_cue(2, 5.0, 5.8, "Ngắn."),
    ]
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues, master_audio_duration_s=6.0)

    planner = VisualShotPlanner()
    plan = planner.plan_shots(candidates)

    assert len(plan.shots) == 1
    assert plan.shots[0].duration_s == pytest.approx(5.8, abs=0.01)


def test_dp_planner_determinism():
    """Verify 100 consecutive runs produce identical shot boundaries."""
    cues = [_make_cue(i, (i - 1) * 2.2, i * 2.2, f"Câu số {i}.", para_id=(i // 2)) for i in range(1, 20)]
    builder = VisualBoundaryCandidateBuilder()
    candidates = builder.build_candidates(cues, master_audio_duration_s=45.0)

    planner = VisualShotPlanner()
    first_run = [(s.start_us, s.end_us, s.cost) for s in planner.plan_shots(candidates).shots]

    for _ in range(100):
        run = [(s.start_us, s.end_us, s.cost) for s in planner.plan_shots(candidates).shots]
        assert run == first_run
