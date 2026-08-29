"""
tests/test_camera_engine.py
Unit tests for CameraTransform abstraction, CameraMotionEngine trajectory math,
GPU capability detection, and fallback safety.
"""

import pytest
import math
from camera_engine import CameraTransform, CameraMotionEngine
from renderer_e_engine import get_gpu_capabilities


def test_camera_transform_defaults():
    ct = CameraTransform()
    assert ct.zoom == 1.0
    assert ct.x == 0.0
    assert ct.y == 0.0
    assert ct.rotation == 0.0
    
    matrix = ct.to_affine_matrix_2x3()
    assert len(matrix) == 2
    assert len(matrix[0]) == 3
    assert abs(matrix[0][0] - 1.0) < 1e-6
    assert abs(matrix[1][1] - 1.0) < 1e-6


def test_camera_transform_affine_zoom_and_pan():
    ct = CameraTransform(zoom=2.0, x=0.5, y=-0.5, rotation=0.0)
    matrix = ct.to_affine_matrix_2x3()
    
    # Scale factor is 1/zoom = 0.5
    assert abs(matrix[0][0] - 0.5) < 1e-6
    assert abs(matrix[1][1] - 0.5) < 1e-6
    
    # Translation
    assert abs(matrix[0][2] - (-0.5 * (1.0 - 0.5))) < 1e-6


@pytest.mark.parametrize("effect", ['zoom_in', 'zoom_out', 'pan_lr', 'pan_rl', 'tilt_ud', 'tilt_du', 'ken_burns'])
def test_camera_motion_engine_bounds(effect):
    # Progress 0.0
    t0 = CameraMotionEngine.get_transform(0.0, effect=effect, magnitude=0.20)
    assert t0.zoom >= 1.0
    
    # Progress 0.5
    t_mid = CameraMotionEngine.get_transform(0.5, effect=effect, magnitude=0.20)
    assert t_mid.zoom >= 1.0
    
    # Progress 1.0
    t1 = CameraMotionEngine.get_transform(1.0, effect=effect, magnitude=0.20)
    assert t1.zoom >= 1.0


@pytest.mark.parametrize("curve", ['linear', 'smoothstep', 'sine', 'exponential', 'crop_constant'])
def test_all_easing_curves_range(curve):
    assert abs(CameraMotionEngine.ease(0.0, curve) - 0.0) < 1e-5
    assert abs(CameraMotionEngine.ease(1.0, curve) - 1.0) < 1e-5
    
    mid = CameraMotionEngine.ease(0.5, curve)
    assert 0.0 <= mid <= 1.0


def test_gpu_capabilities_detection():
    caps = get_gpu_capabilities()
    assert 'has_torch' in caps
    assert 'gpu_backend' in caps
    assert 'encoders' in caps
    assert len(caps['encoders']) > 0
    assert 'libx264' in caps['encoders']