"""
tests/test_zoom_regression_golden.py
Regression tests ensuring Zoom In/Out camera trajectory, canvas supersampling,
center anchoring, and frame counts strictly match Windows 2.2.3.15 Golden Reference.
"""

import os
import pytest
from ffmpeg_utils import _zoompan_params, build_command, build_resolution

def test_legacy_zoom_in_expression():
    total_frames = 150
    NF = 149
    mag = 0.20
    params = _zoompan_params('zoom_in', mag, total_frames)
    
    assert params['z'] == f"1.0+{mag:.5f}*(on/{NF})"
    assert params['x'] == "(iw-iw/zoom)/2"
    assert params['y'] == "(ih-ih/zoom)/2"


def test_legacy_zoom_out_expression():
    total_frames = 150
    NF = 149
    mag = 0.20
    params = _zoompan_params('zoom_out', mag, total_frames)
    
    assert params['z'] == f"1.0+{mag:.5f}*(1.0-(on/{NF}))"
    assert params['x'] == "(iw-iw/zoom)/2"
    assert params['y'] == "(ih-ih/zoom)/2"


def test_legacy_4x_canvas_supersampling():
    cmd, dur = build_command(
        image_paths=['/tmp/camera_benchmark/diagnostic_pattern.png'],
        audio_path=None,
        output_path='/tmp/test_out.mp4',
        settings={
            'resolution': '1080p',
            'aspect_ratio': '16:9',
            'fps': 30,
            'duration_per_image': 5.0,
            'image_effects': ['zoom_in'],
            'zoom_magnitude': 0.20
        }
    )
    
    # Read filter_complex from cmd or written script file
    filter_content = ""
    for idx, arg in enumerate(cmd):
        if arg in ('-filter_complex', '-/filter_complex') and idx + 1 < len(cmd):
            target = cmd[idx + 1]
            if os.path.isfile(target):
                with open(target, 'r', encoding='utf-8') as f:
                    filter_content = f.read()
            else:
                filter_content = target
            break
            
    assert "scale=7680:4320" in filter_content
    assert "crop=7680:4320" in filter_content
    assert "s=1920x1080" in filter_content
    assert "zoompan=z='1.0+0.20000*(on/149)'" in filter_content