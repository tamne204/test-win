"""
tests/test_a0_collapse_detector.py
Unit tests for Milestone M0-D: Region Health Diagnostics & Collapse Detector Gate.
"""
import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

import pytest
from core.subtitles.models import (
    SubtitleCue,
    RegionHealth,
    ConfidenceLevel,
)
from core.subtitles.collapse_detector import (
    CollapseDetector,
    CollapseInspectionResult,
    AlignmentCollapseError,
)


def _make_cues(cue_specs):
    """Helper: list of (start_s, end_s, text)"""
    res = []
    for i, (s, e, txt) in enumerate(cue_specs):
        res.append(
            SubtitleCue(
                index=i + 1,
                start_s=s,
                end_s=e,
                text=txt,
                confidence=ConfidenceLevel.HIGH,
            )
        )
    return res


def test_clean_cues_pass_collapse_detector():
    """Verify clean cues with healthy reading speed pass validation."""
    cues_data = [
        (0.0, 3.0, "Đây là câu mở đầu của video."),
        (3.2, 6.5, "Câu thứ hai tiếp diễn một cách nhịp nhàng."),
        (6.7, 10.0, "Nội dung truyền đạt rất rõ ràng và dễ đọc."),
    ]
    cues = _make_cues(cues_data)
    detector = CollapseDetector()
    result = detector.inspect(cues, allow_degraded=False)

    assert result.has_collapse is False
    assert len(result.violations) == 0
    assert result.micro_cue_count == 0


def test_critical_regression_44_sentences_in_19s():
    """
    CRITICAL REGRESSION FOR ERR-A0-01:
    44 complete narrative sentences compressed into ~19.6 seconds
    must NEVER silently produce valid high-confidence output.
    """
    # 44 cues in 19.6s (from 1767.6s to 1787.2s)
    # Average duration ~ 0.35s per sentence!
    cue_specs = []
    t_curr = 1767.6
    for i in range(44):
        dur = 0.35 if i % 2 == 0 else 0.40
        cue_specs.append((round(t_curr, 2), round(t_curr + dur, 2), f"Câu văn tường thuật hoàn chỉnh số {i+1} ở đây."))
        t_curr += dur

    cues = _make_cues(cue_specs)
    detector = CollapseDetector()

    # When allow_degraded=False, MUST raise AlignmentCollapseError
    with pytest.raises(AlignmentCollapseError) as exc_info:
        detector.inspect(cues, audio_duration_s=1787.2, allow_degraded=False)

    err = exc_info.value
    assert len(err.violations) > 0
    assert any("tail collapse" in v.lower() or "reading speed" in v.lower() for v in err.violations)

    # When allow_degraded=True, must flag has_collapse=True
    res = detector.inspect(cues, audio_duration_s=1787.2, allow_degraded=True)
    assert res.has_collapse is True
    assert res.micro_cue_count > 0


def test_micro_cue_cluster_detection():
    """Verify > 4 cues < 0.40s in a 5.0s window triggers collapse violation."""
    cue_specs = [
        (1.0, 1.25, "Một"),
        (1.3, 1.55, "Hai"),
        (1.6, 1.85, "Ba"),
        (1.9, 2.15, "Bốn"),
        (2.2, 2.45, "Năm"),
        (2.5, 2.75, "Sáu"),
    ]
    cues = _make_cues(cue_specs)
    detector = CollapseDetector()

    with pytest.raises(AlignmentCollapseError):
        detector.inspect(cues, allow_degraded=False)

    res = detector.inspect(cues, allow_degraded=True)
    assert res.has_collapse is True
    assert any("Micro-cue cluster" in v for v in res.violations)


def test_sustained_impossible_speed_detection():
    """Verify reading speed > 5.0 tokens/sec across 3 consecutive cues triggers collapse."""
    cue_specs = [
        (10.0, 11.0, "Đây là câu thứ nhất có rất nhiều từ ngữ trong một giây"),  # 13 words in 1s
        (11.0, 12.0, "Đây là câu thứ hai cũng có rất nhiều từ ngữ nhanh chóng"), # 13 words in 1s
        (12.0, 13.0, "Đây là câu thứ ba tiếp tục nói siêu tốc không thể đọc"),   # 13 words in 1s
    ]
    cues = _make_cues(cue_specs)
    detector = CollapseDetector()

    res = detector.inspect(cues, allow_degraded=True)
    assert res.has_collapse is True
    assert any("Sustained impossible reading speed" in v for v in res.violations)


def test_collapsed_region_flag_triggers_error():
    """Verify any region flagged as COLLAPSED by aligner causes inspection failure."""
    cues = _make_cues([(0.0, 3.0, "Bình thường.")])
    rh = RegionHealth(
        region_index=2,
        start_time_s=15.0,
        end_time_s=25.0,
        duration_s=10.0,
        script_token_count=80,
        asr_word_count=10,
        health_status="COLLAPSED",
    )
    detector = CollapseDetector()

    with pytest.raises(AlignmentCollapseError):
        detector.inspect(cues, region_health_list=[rh], allow_degraded=False)
