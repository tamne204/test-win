"""
camera_engine.py
Modular, Renderer-Independent Subpixel Camera Engine.
Provides CameraTransform abstraction and multi-trajectory motion synthesis.
Decoupled from FFmpeg, PyTorch, and Web Canvas backends.
"""
from __future__ import annotations

import math
from dataclasses import dataclass
from typing import List, Tuple, Dict, Any, Optional, Union, Callable, Set


@dataclass
class CameraTransform:
    """
    Continuous floating-point camera transformation state.
    All parameters are 64-bit/32-bit floats with subpixel precision.
    """
    zoom: float = 1.0
    x: float = 0.0          # Normalized horizontal displacement [-1.0, 1.0] (0 = centered)
    y: float = 0.0          # Normalized vertical displacement [-1.0, 1.0] (0 = centered)
    rotation: float = 0.0   # Rotation in degrees
    scale_x: float = 1.0
    scale_y: float = 1.0

    def to_affine_matrix_2x3(self) -> Tuple[Tuple[float, float, float], Tuple[float, float, float]]:
        """
        Convert camera transform to 2x3 affine matrix for GPU grid sampling.
        Maps destination output coordinate [-1, 1] back to source normalized coordinate.
        """
        rad = math.radians(self.rotation)
        cos_a = math.cos(rad)
        sin_a = math.sin(rad)
        
        inv_zx = 1.0 / (self.zoom * self.scale_x)
        inv_zy = 1.0 / (self.zoom * self.scale_y)
        
        # Affine matrix: R * S + T
        m00 = cos_a * inv_zx
        m01 = -sin_a * inv_zx
        m02 = -self.x * (1.0 - inv_zx) if abs(self.x) > 1e-7 else 0.0
        
        m10 = sin_a * inv_zy
        m11 = cos_a * inv_zy
        m12 = -self.y * (1.0 - inv_zy) if abs(self.y) > 1e-7 else 0.0
        
        return ((m00, m01, m02), (m10, m11, m12))

    def to_dict(self) -> Dict[str, float]:
        return {
            'zoom': round(self.zoom, 6),
            'x': round(self.x, 6),
            'y': round(self.y, 6),
            'rotation': round(self.rotation, 4),
            'scale_x': round(self.scale_x, 6),
            'scale_y': round(self.scale_y, 6)
        }


class CameraMotionEngine:
    """
    Computes camera trajectories for all motion presets with multiple easing curves.
    """
    SUPPORTED_TRAJECTORIES = ('linear', 'smoothstep', 'exponential', 'sine', 'crop_constant')

    @staticmethod
    def ease(t: float, curve: str = 'linear') -> float:
        """
        Map progress t in [0, 1] through easing curve.
        """
        t = max(0.0, min(1.0, float(t)))
        if curve == 'linear':
            return t
        elif curve == 'smoothstep':
            return t * t * (3.0 - 2.0 * t)
        elif curve == 'sine':
            return 0.5 * (1.0 - math.cos(math.pi * t))
        elif curve == 'exponential':
            # Perceptual logarithmic zoom progress: exp(t * ln(2)) - 1
            return (math.exp(t * 0.693147) - 1.0)
        elif curve == 'crop_constant':
            # Linearizes the visible crop area rate of change
            return t * (2.0 - t)
        return t

    @classmethod
    def get_transform(cls,
                      progress: float,
                      effect: str = 'zoom_in',
                      magnitude: float = 0.20,
                      curve: str = 'linear',
                      rotation_deg: float = 0.0) -> CameraTransform:
        """
        Evaluate camera transform at normalized progress t in [0.0, 1.0].
        """
        p = cls.ease(progress, curve)
        mag = max(0.01, min(float(magnitude), 1.0))
        
        if effect == 'zoom_in':
            z = 1.0 + mag * p
            return CameraTransform(zoom=z, x=0.0, y=0.0, rotation=rotation_deg * p)
            
        elif effect == 'zoom_out':
            z = 1.0 + mag * (1.0 - p)
            return CameraTransform(zoom=z, x=0.0, y=0.0, rotation=rotation_deg * (1.0 - p))
            
        elif effect == 'pan_lr':
            z = 1.0 + mag
            # x sweeps from -1.0 (left) to 1.0 (right)
            x_norm = -1.0 + 2.0 * p
            return CameraTransform(zoom=z, x=x_norm, y=0.0, rotation=rotation_deg * p)
            
        elif effect == 'pan_rl':
            z = 1.0 + mag
            x_norm = 1.0 - 2.0 * p
            return CameraTransform(zoom=z, x=x_norm, y=0.0, rotation=rotation_deg * p)
            
        elif effect == 'tilt_ud':
            z = 1.0 + mag
            y_norm = -1.0 + 2.0 * p
            return CameraTransform(zoom=z, x=0.0, y=y_norm, rotation=rotation_deg * p)
            
        elif effect == 'tilt_du':
            z = 1.0 + mag
            y_norm = 1.0 - 2.0 * p
            return CameraTransform(zoom=z, x=0.0, y=y_norm, rotation=rotation_deg * p)
            
        elif effect == 'ken_burns':
            # Combined zoom + diagonal drift
            z = 1.0 + mag * p
            x_norm = -0.5 + 1.0 * p
            y_norm = -0.3 + 0.6 * p
            return CameraTransform(zoom=z, x=x_norm, y=y_norm, rotation=rotation_deg * p)
            
        else: # static / none
            return CameraTransform(zoom=1.0, x=0.0, y=0.0, rotation=0.0)