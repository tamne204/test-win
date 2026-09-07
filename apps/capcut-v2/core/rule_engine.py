"""
apps/capcut-v2/core/rule_engine.py
Deterministic, rule-based editing logic.
No AI / LLM dependency.
Guaranteed: Same inputs + preset = identical output.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import List, Dict, Any, Optional

# Supported motion types
MOTION_ZOOM_IN = "ZOOM_IN"
MOTION_ZOOM_OUT = "ZOOM_OUT"
MOTION_PAN_LEFT = "PAN_LEFT"
MOTION_PAN_RIGHT = "PAN_RIGHT"
MOTION_PAN_UP = "PAN_UP"
MOTION_PAN_DOWN = "PAN_DOWN"
MOTION_NONE = "NONE"

ALL_SUPPORTED_MOTIONS = [
    MOTION_ZOOM_IN,
    MOTION_ZOOM_OUT,
    MOTION_PAN_LEFT,
    MOTION_PAN_RIGHT,
    MOTION_PAN_UP,
    MOTION_PAN_DOWN,
]


@dataclass
class Preset:
    """Legacy compatibility preset structure."""
    id: str = "basic"
    name: str = "Basic Auto Edit"
    description: str = "Standard 9:16 vertical slideshow with alternating zooms and pans"
    canvas_ratio: str = "9:16"
    width: int = 1080
    height: int = 1920
    fps: float = 60.0
    scene_duration_s: float = 5.0
    min_scene_duration_s: float = 3.0
    max_scene_duration_s: float = 8.0
    allowed_motion: List[str] = field(
        default_factory=lambda: [
            MOTION_ZOOM_IN,
            MOTION_ZOOM_OUT,
            MOTION_PAN_LEFT,
            MOTION_PAN_RIGHT,
        ]
    )
    motion_sequence: List[str] = field(
        default_factory=lambda: [
            MOTION_ZOOM_IN,
            MOTION_ZOOM_OUT,
            MOTION_PAN_LEFT,
            MOTION_PAN_RIGHT,
        ]
    )
    zoom_magnitude: float = 0.15
    pan_magnitude: float = 0.10
    caption_enabled: bool = True
    caption_position_y: float = -0.6
    caption_font_size: float = 8.0
    music_volume: float = 1.0
    voice_volume: float = 1.0


# Built-in fallback presets
PRESET_BASIC = Preset(
    name="Basic Auto Edit",
    description="Standard alternating zooms and pans at 60 FPS (9:16)",
    scene_duration_s=5.0,
)

PRESET_TIKTOK_FAST = Preset(
    name="TikTok Fast",
    description="High-tempo 9:16 vertical edit, 3.0s per clip",
    scene_duration_s=3.0,
    zoom_magnitude=0.20,
    pan_magnitude=0.12,
)

BUILTIN_PRESETS: Dict[str, Preset] = {
    "basic": PRESET_BASIC,
    "tiktok_fast": PRESET_TIKTOK_FAST,
}


class RuleEngine:
    """
    Deterministic rule engine that assigns motion types and parameters to visual clips.
    """

    def __init__(self, preset: Any = None):
        self.preset = preset or PRESET_BASIC

    def assign_motion(self, clip_index: int, weights: Optional[Dict[str, float]] = None) -> str:
        """
        Assign motion type based on clip index or random percentage weights.
        Supported weight keys: 'zoom_in', 'zoom_out', 'pan', 'tilt' (or exact motion names).
        """
        if weights and any(float(v) > 0 for v in weights.values()):
            candidates = []
            cand_weights = []

            mapping = {
                "zoom_in": MOTION_ZOOM_IN,
                "zoom_out": MOTION_ZOOM_OUT,
                "pan": [MOTION_PAN_LEFT, MOTION_PAN_RIGHT],
                "pan_left": MOTION_PAN_LEFT,
                "pan_right": MOTION_PAN_RIGHT,
                "tilt": [MOTION_PAN_UP, MOTION_PAN_DOWN],
                "pan_up": MOTION_PAN_UP,
                "pan_down": MOTION_PAN_DOWN,
                "ZOOM_IN": MOTION_ZOOM_IN,
                "ZOOM_OUT": MOTION_ZOOM_OUT,
                "PAN_LEFT": MOTION_PAN_LEFT,
                "PAN_RIGHT": MOTION_PAN_RIGHT,
                "PAN_UP": MOTION_PAN_UP,
                "PAN_DOWN": MOTION_PAN_DOWN,
            }

            for key, val in weights.items():
                try:
                    w = float(val)
                except (ValueError, TypeError):
                    continue
                if w <= 0:
                    continue
                mapped = mapping.get(key.lower(), mapping.get(key))
                if isinstance(mapped, list):
                    for sub_m in mapped:
                        candidates.append(sub_m)
                        cand_weights.append(w / len(mapped))
                elif mapped:
                    candidates.append(mapped)
                    cand_weights.append(w)

            if candidates:
                import random
                rng = random.Random(clip_index * 1337 + 42)
                return rng.choices(candidates, weights=cand_weights, k=1)[0]

        allowed = getattr(self.preset, "motion_sequence", None) or getattr(self.preset, "allowed_motion", None)
        if not allowed:
            return MOTION_NONE
        pattern_index = clip_index % len(allowed)
        return allowed[pattern_index]

    def get_motion_parameters(self, motion_type: str) -> Dict[str, Any]:
        """
        Return numeric keyframe parameters for a given motion type.
        Uses normalized coordinates and scale factors.
        """
        z_mag = getattr(self.preset, "zoom_magnitude", 0.15)
        p_mag = getattr(self.preset, "pan_magnitude", 0.10)

        if motion_type == MOTION_ZOOM_IN:
            return {
                "type": MOTION_ZOOM_IN,
                "scale_start": 1.0,
                "scale_end": round(1.0 + z_mag, 3),
                "pos_x_start": 0.0,
                "pos_x_end": 0.0,
                "pos_y_start": 0.0,
                "pos_y_end": 0.0,
            }
        elif motion_type == MOTION_ZOOM_OUT:
            return {
                "type": MOTION_ZOOM_OUT,
                "scale_start": round(1.0 + z_mag, 3),
                "scale_end": 1.0,
                "pos_x_start": 0.0,
                "pos_x_end": 0.0,
                "pos_y_start": 0.0,
                "pos_y_end": 0.0,
            }
        elif motion_type == MOTION_PAN_LEFT:
            return {
                "type": MOTION_PAN_LEFT,
                "scale_start": round(1.0 + z_mag * 0.5, 3),  # slight scale to avoid black border
                "scale_end": round(1.0 + z_mag * 0.5, 3),
                "pos_x_start": round(p_mag, 3),
                "pos_x_end": round(-p_mag, 3),
                "pos_y_start": 0.0,
                "pos_y_end": 0.0,
            }
        elif motion_type == MOTION_PAN_RIGHT:
            return {
                "type": MOTION_PAN_RIGHT,
                "scale_start": round(1.0 + z_mag * 0.5, 3),
                "scale_end": round(1.0 + z_mag * 0.5, 3),
                "pos_x_start": round(-p_mag, 3),
                "pos_x_end": round(p_mag, 3),
                "pos_y_start": 0.0,
                "pos_y_end": 0.0,
            }
        elif motion_type == MOTION_PAN_UP:
            return {
                "type": MOTION_PAN_UP,
                "scale_start": round(1.0 + z_mag * 0.5, 3),
                "scale_end": round(1.0 + z_mag * 0.5, 3),
                "pos_x_start": 0.0,
                "pos_x_end": 0.0,
                "pos_y_start": round(-p_mag, 3),
                "pos_y_end": round(p_mag, 3),
            }
        elif motion_type == MOTION_PAN_DOWN:
            return {
                "type": MOTION_PAN_DOWN,
                "scale_start": round(1.0 + z_mag * 0.5, 3),
                "scale_end": round(1.0 + z_mag * 0.5, 3),
                "pos_x_start": 0.0,
                "pos_x_end": 0.0,
                "pos_y_start": round(p_mag, 3),
                "pos_y_end": round(-p_mag, 3),
            }
        else:
            return {
                "type": MOTION_NONE,
                "scale_start": 1.0,
                "scale_end": 1.0,
                "pos_x_start": 0.0,
                "pos_x_end": 0.0,
                "pos_y_start": 0.0,
                "pos_y_end": 0.0,
            }
