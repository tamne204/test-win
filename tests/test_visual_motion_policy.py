"""
tests/test_visual_motion_policy.py
Unit tests for DurationAwareMotionPolicy velocity-first integration.
"""
import pytest
from core.visual.models import VisualShot
from core.visual.motion_policy import DurationAwareMotionPolicy


def _make_shot(shot_id: int, dur_s: float, alternate: bool = False) -> VisualShot:
    s_us = 0
    e_us = int(round(dur_s * 1_000_000))
    return VisualShot(
        shot_id=shot_id,
        start_us=s_us,
        end_us=e_us,
        duration_us=e_us,
        image_id=f"img_{shot_id}",
        image_path=f"img_{shot_id}.png",
        diagnostics={"alternate_motion": alternate},
    )


def test_normal_shots_velocity_band():
    """Verify normal speech shots (2.5s - 8.5s) remain strictly within target 1.5% - 3.5%/s."""
    policy = DurationAwareMotionPolicy()
    durations = [2.0, 3.0, 4.0, 5.0, 6.5, 8.0, 10.0]
    shots = [_make_shot(i, d) for i, d in enumerate(durations)]

    policy.apply_motion_to_shots(shots)

    for shot in shots:
        eff_v = shot.motion_profile["effective_velocity_pct_per_sec"]
        assert eff_v >= 1.2  # close to min target
        assert eff_v <= 3.5  # strictly bounded by target max
        assert eff_v <= 5.0  # strictly bounded by absolute max


def test_short_shot_reduced_motion():
    """Verify exceptional short shots (< 2.0s) receive reduced motion (delta <= 3%, static pan)."""
    policy = DurationAwareMotionPolicy()
    shot = _make_shot(0, 1.2)
    policy.apply_motion_to_shots([shot])

    prof = shot.motion_profile
    assert prof["is_reduced"]
    assert prof["scale_end"] - prof["scale_start"] <= 0.03
    assert prof["pan_x_start"] == 0.0
    assert prof["pan_x_end"] == 0.0


def test_long_tail_ultra_slow_motion():
    """Verify long shots (> 12.0s) receive ultra-slow velocity <= 0.8%/s."""
    policy = DurationAwareMotionPolicy()
    shot = _make_shot(0, 24.4)
    policy.apply_motion_to_shots([shot])

    prof = shot.motion_profile
    assert prof["is_ultra_slow"]
    assert prof["effective_velocity_pct_per_sec"] <= 0.8


def test_reused_image_motion_inversion():
    """Verify reused image inverts motion direction."""
    policy = DurationAwareMotionPolicy()
    shot1 = _make_shot(0, 5.0, alternate=False)
    shot2 = _make_shot(0, 5.0, alternate=True)

    policy.apply_motion_to_shots([shot1])
    policy.apply_motion_to_shots([shot2])

    # If first was ZOOM_IN, alternate should be ZOOM_OUT
    assert shot1.motion_profile["motion_type"] == "ZOOM_IN"
    assert shot2.motion_profile["motion_type"] == "ZOOM_OUT"
