"""
tests/test_visual_boundary_builder.py
Unit tests for VisualBoundaryCandidateBuilder and VisualBoundaryCandidate contracts.
"""
import pytest
from core.subtitles.models import SubtitleCue, AlignedToken, ScriptToken
from core.visual.models import (
    VisualBoundaryCandidate,
    VisualBoundaryType,
    ShotDurationPolicy,
)
from core.visual.boundary_builder import VisualBoundaryCandidateBuilder


def _make_cue(
    index: int,
    start_s: float,
    end_s: float,
    text: str,
    para_id: int = 0,
    sent_id: int = 0,
) -> SubtitleCue:
    return SubtitleCue(
        index=index,
        start_s=start_s,
        end_s=end_s,
        text=text,
        paragraph_ids=[para_id],
        sentence_ids=[sent_id],
    )


def test_visual_boundary_builder_basic():
    builder = VisualBoundaryCandidateBuilder()
    cues = [
        _make_cue(1, 0.5, 3.5, "Câu thứ nhất.", para_id=1, sent_id=1),
        _make_cue(2, 3.8, 7.2, "Câu thứ hai trong đoạn mới.", para_id=2, sent_id=2),
    ]
    candidates = builder.build_candidates(cues, master_audio_duration_s=10.0)

    assert len(candidates) >= 4
    # B0 at 0.0
    assert candidates[0].timestamp_us == 0
    assert candidates[0].boundary_type == VisualBoundaryType.SPEECH_START

    # Check paragraph transition between cue 1 and 2
    cue1_end_cand = [c for c in candidates if c.left_cue_id == 1 and not c.is_internal][0]
    assert cue1_end_cand.boundary_type == VisualBoundaryType.PARAGRAPH_BOUNDARY
    assert cue1_end_cand.structural_strength == 1.0

    # Last candidate at master audio end
    assert candidates[-1].boundary_type == VisualBoundaryType.MASTER_AUDIO_END
    assert candidates[-1].timestamp_s == 10.0


def test_long_cue_internal_split():
    """Verify an 18.76s cue generates internal candidates while subtitle remains intact."""
    builder = VisualBoundaryCandidateBuilder()
    long_cue = _make_cue(641, 1622.10, 1640.86, "Đây là một câu rất dài kéo dài mười tám giây liên tục.", para_id=252, sent_id=471)
    assert long_cue.duration_s == pytest.approx(18.76, abs=0.01)

    candidates = builder.build_candidates([long_cue], master_audio_duration_s=1787.233)

    # Check that at least one DURATION_FORCED_INTERNAL_BOUNDARY exists
    internal_cands = [c for c in candidates if c.is_internal]
    assert len(internal_cands) >= 1
    for ic in internal_cands:
        assert ic.boundary_type == VisualBoundaryType.DURATION_FORCED_INTERNAL_BOUNDARY
        assert ic.left_cue_id == 641
        assert ic.right_cue_id == 641
        # Balanced midpoint around 9.38s into the cue
        assert ic.timestamp_s > long_cue.start_s + 4.0
        assert ic.timestamp_s < long_cue.end_s - 4.0

    # Ensure original cue timing was NOT modified
    assert long_cue.start_s == 1622.10
    assert long_cue.end_s == 1640.86


def test_deduplication():
    builder = VisualBoundaryCandidateBuilder()
    cues = [
        _make_cue(1, 0.0, 4.0, "Câu một.", para_id=0, sent_id=0),
    ]
    candidates = builder.build_candidates(cues, master_audio_duration_s=4.0)

    # Verify no two candidates share the exact timestamp
    timestamps = [c.timestamp_us for c in candidates]
    assert len(timestamps) == len(set(timestamps))
