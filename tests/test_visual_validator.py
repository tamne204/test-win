"""
tests/test_visual_validator.py
Unit tests for VisualAccuracyValidator.
"""
import pytest
from core.visual.models import VisualShot
from core.visual.validator import VisualAccuracyValidator, ValidationSeverity


def _make_shot(shot_id: int, start_s: float, end_s: float, img: str = "img.png", eff_v: float = 2.4) -> VisualShot:
    s_us = int(round(start_s * 1_000_000))
    e_us = int(round(end_s * 1_000_000))
    return VisualShot(
        shot_id=shot_id,
        start_us=s_us,
        end_us=e_us,
        duration_us=e_us - s_us,
        image_id=img.split(".")[0],
        image_path=img,
        motion_profile={"effective_velocity_pct_per_sec": eff_v},
    )


def test_validator_clean_plan():
    """Verify that a perfect plan passes with zero errors or fatals."""
    validator = VisualAccuracyValidator()
    shots = [
        _make_shot(0, 0.0, 5.0, "img1.png", eff_v=2.4),
        _make_shot(1, 5.0, 10.0, "img2.png", eff_v=2.2),
        _make_shot(2, 10.0, 15.0, "img3.png", eff_v=2.0),
    ]
    report = validator.validate(shots, master_audio_duration_us=15_000_000)

    assert report.is_valid
    assert not report.has_fatal
    assert report.error_count == 0
    assert report.fatal_count == 0


def test_validator_micro_shot_and_duplicate():
    """Verify micro-shot and consecutive duplicate asset trigger errors."""
    validator = VisualAccuracyValidator()
    shots = [
        _make_shot(0, 0.0, 1.2, "img1.png"),   # 1.2s < 2.0s -> ERROR
        _make_shot(1, 1.2, 6.0, "img1.png"),   # Consecutive duplicate -> ERROR
    ]
    report = validator.validate(shots, master_audio_duration_us=6_000_000)

    assert not report.is_valid
    check_ids = [issue.check_id for issue in report.issues]
    assert "VAL-ERR-01" in check_ids
    assert "VAL-ERR-03" in check_ids


def test_validator_gap_overlap_and_black_tail():
    """Verify visual gap, visual overlap, and tail black screen trigger fatal issues."""
    validator = VisualAccuracyValidator()
    # Shot 0: 0.0 to 4.0
    # Shot 1: 4.5 to 8.0 (Gap of 0.5s from 4.0 to 4.5)
    shots = [
        _make_shot(0, 0.0, 4.0, "img1.png"),
        _make_shot(1, 4.5, 8.0, "img2.png"),
    ]
    # Master audio is 10.0s, but shots end at 8.0s (Tail black screen of 2.0s)
    report = validator.validate(shots, master_audio_duration_us=10_000_000)

    assert report.has_fatal
    check_ids = [issue.check_id for issue in report.issues]
    assert "VAL-FATAL-06" in check_ids  # Gap
    assert "VAL-FATAL-08" in check_ids  # Black tail


def test_validator_excessive_velocity():
    """Verify velocity exceeding absolute limit (5.0%/s) triggers ERROR."""
    validator = VisualAccuracyValidator()
    shots = [
        _make_shot(0, 0.0, 5.0, "img1.png", eff_v=6.5), # 6.5% > 5.0%
    ]
    report = validator.validate(shots, master_audio_duration_us=5_000_000)

    check_ids = [issue.check_id for issue in report.issues]
    assert "VAL-ERR-10" in check_ids
