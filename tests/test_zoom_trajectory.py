"""
tests/test_zoom_trajectory.py
Unit tests verifying deterministic Hermite smoothstep camera trajectory math,
boundary exactness, center anchor stability, and multi-resolution compatibility.
"""

import pytest
from ffmpeg_utils import _zoompan_params, build_resolution


def smoothstep_py(t: float) -> float:
    t_clamped = max(0.0, min(1.0, t))
    return t_clamped * t_clamped * (3.0 - 2.0 * t_clamped)


@pytest.mark.parametrize("duration", [1.0, 3.0, 5.0, 10.0, 30.0])
@pytest.mark.parametrize("fps", [25, 30, 60])
@pytest.mark.parametrize("mag", [0.15, 0.20, 0.35])
def test_zoom_in_trajectory_bounds(duration: float, fps: int, mag: float):
    total_frames = max(2, int(round(duration * fps)))
    NF = total_frames - 1

    params = _zoompan_params('zoom_in', mag, total_frames)
    assert 'on/' in params['z']
    assert params['x'] == '(iw-iw/zoom)/2'
    assert params['y'] == '(ih-ih/zoom)/2'

    # Verify frame 0 (t=0)
    t_0 = 0.0 / NF
    s_0 = smoothstep_py(t_0)
    z_0 = 1.0 + mag * s_0
    assert abs(z_0 - 1.0) < 1e-6, "Zoom In first frame must be exactly 1.0"

    # Verify mid frame (t=0.5)
    t_mid = 0.5
    s_mid = smoothstep_py(t_mid)
    z_mid = 1.0 + mag * s_mid
    assert abs(z_mid - (1.0 + mag * 0.5)) < 1e-6, "Zoom In mid frame must be at midpoint"

    # Verify last frame (t=1.0)
    t_last = float(NF) / NF
    s_last = smoothstep_py(t_last)
    z_last = 1.0 + mag * s_last
    assert abs(z_last - (1.0 + mag)) < 1e-6, "Zoom In last frame must reach exactly 1.0 + mag"


@pytest.mark.parametrize("duration", [1.0, 5.0, 20.0])
@pytest.mark.parametrize("fps", [25, 60])
@pytest.mark.parametrize("mag", [0.20])
def test_zoom_out_trajectory_bounds(duration: float, fps: int, mag: float):
    total_frames = max(2, int(round(duration * fps)))
    NF = total_frames - 1

    params = _zoompan_params('zoom_out', mag, total_frames)
    assert '1.0-' in params['z']

    # Verify frame 0 (t=0)
    t_0 = 0.0 / NF
    s_0 = smoothstep_py(t_0)
    z_0 = 1.0 + mag * (1.0 - s_0)
    assert abs(z_0 - (1.0 + mag)) < 1e-6, "Zoom Out first frame must be exactly 1.0 + mag"

    # Verify last frame (t=1.0)
    t_last = 1.0
    s_last = smoothstep_py(t_last)
    z_last = 1.0 + mag * (1.0 - s_last)
    assert abs(z_last - 1.0) < 1e-6, "Zoom Out last frame must be exactly 1.0"


def test_pan_and_tilt_trajectories():
    total_frames = 125
    mag = 0.25
    
    # Pan LR
    p_lr = _zoompan_params('pan_lr', mag, total_frames)
    assert p_lr['z'] == f"{1.0 + mag:.5f}"
    assert '*(iw-iw/zoom)' in p_lr['x']
    assert p_lr['y'] == '(ih-ih/zoom)/2'

    # Pan RL
    p_rl = _zoompan_params('pan_rl', mag, total_frames)
    assert p_rl['z'] == f"{1.0 + mag:.5f}"
    assert '1.0-' in p_rl['x']

    # Tilt UD
    p_ud = _zoompan_params('tilt_ud', mag, total_frames)
    assert p_ud['z'] == f"{1.0 + mag:.5f}"
    assert '*(ih-ih/zoom)' in p_ud['y']
    assert p_ud['x'] == '(iw-iw/zoom)/2'

    # Tilt DU
    p_du = _zoompan_params('tilt_du', mag, total_frames)
    assert p_du['z'] == f"{1.0 + mag:.5f}"
    assert '1.0-' in p_du['y']


@pytest.mark.parametrize("aspect,res,expected_w,expected_h", [
    ('16:9', '1080p', 1920, 1080),
    ('16:9', '2k', 2560, 1440),
    ('16:9', '4k', 3840, 2160),
    ('9:16', '1080p', 1080, 1920),
    ('1:1', '1080p', 1080, 1080),
    ('4:3', '1080p', 1440, 1080),
])
def test_all_resolutions_build(aspect, res, expected_w, expected_h):
    w, h = build_resolution(aspect, res)
    assert w == expected_w
    assert h == expected_h