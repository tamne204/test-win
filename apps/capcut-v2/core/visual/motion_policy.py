"""
apps/capcut-v2/core/visual/motion_policy.py
Duration-aware Ken Burns motion policy using velocity-first integration.
Guarantees smooth cinematic velocity (1.5%/s - 3.5%/s target, 5.0%/s hard clamp).
"""
from __future__ import annotations

from typing import List, Dict, Any, Optional
from .models import VisualShot


class DurationAwareMotionPolicy:
    """
    Computes keyframe motion parameters based strictly on shot duration and velocity limits.
    """
    TARGET_MIN_VELOCITY_PCT_PER_SEC: float = 1.5
    TARGET_MAX_VELOCITY_PCT_PER_SEC: float = 3.5
    ABSOLUTE_MAX_VELOCITY_PCT_PER_SEC: float = 5.0
    ULTRA_SLOW_MAX_VELOCITY_PCT_PER_SEC: float = 0.8

    MOTION_CYCLE = ["ZOOM_IN", "PAN_LEFT", "ZOOM_OUT", "PAN_RIGHT"]

    def apply_motion_to_shots(self, shots: List[VisualShot]) -> List[VisualShot]:
        """
        Applies velocity-first motion profiles to all visual shots.
        """
        cycle_len = len(self.MOTION_CYCLE)

        for idx, shot in enumerate(shots):
            dur_s = shot.duration_s
            base_type = self.MOTION_CYCLE[idx % cycle_len]

            # Invert motion if reused asset
            if shot.diagnostics.get("alternate_motion", False):
                if base_type == "ZOOM_IN":
                    base_type = "ZOOM_OUT"
                elif base_type == "ZOOM_OUT":
                    base_type = "ZOOM_IN"
                elif base_type == "PAN_LEFT":
                    base_type = "PAN_RIGHT"
                elif base_type == "PAN_RIGHT":
                    base_type = "PAN_LEFT"

            profile = self._calculate_motion_profile(dur_s, base_type)
            shot.motion_profile = profile

        return shots

    def _calculate_motion_profile(self, dur_s: float, motion_type: str) -> Dict[str, Any]:
        """
        Calculates scale and pan vectors from duration using velocity integration.
        """
        # Case 1: Exceptional Short Shots (< 2.0s) -> Reduced or Static motion
        if dur_s < 2.0:
            scale_delta = min(0.03, 1.0 * dur_s / 100.0)
            eff_v = (scale_delta / max(0.1, dur_s)) * 100.0
            return {
                "motion_type": "REDUCED_ZOOM_IN",
                "scale_start": 1.0,
                "scale_end": round(1.0 + scale_delta, 4),
                "pan_x_start": 0.0,
                "pan_x_end": 0.0,
                "pan_y_start": 0.0,
                "pan_y_end": 0.0,
                "target_velocity_pct_per_sec": 1.0,
                "effective_velocity_pct_per_sec": round(eff_v, 2),
                "is_reduced": True,
                "is_ultra_slow": False,
            }

        # Case 2: Very Long / Tail Shots (> 12.0s) -> Ultra-Slow ambient drift
        if dur_s > 12.0:
            eff_v = min(self.ULTRA_SLOW_MAX_VELOCITY_PCT_PER_SEC, 0.25)
            scale_delta = min(0.06, (eff_v * dur_s) / 100.0)

            if "PAN" in motion_type:
                pan_delta = min(0.06, 0.002 * dur_s)
                pan_start = -pan_delta / 2.0 if "RIGHT" in motion_type else pan_delta / 2.0
                pan_end = pan_delta / 2.0 if "RIGHT" in motion_type else -pan_delta / 2.0
                scale_val = 1.05
                return {
                    "motion_type": f"ULTRA_SLOW_{motion_type}",
                    "scale_start": scale_val,
                    "scale_end": scale_val,
                    "pan_x_start": round(pan_start, 4),
                    "pan_x_end": round(pan_end, 4),
                    "pan_y_start": 0.0,
                    "pan_y_end": 0.0,
                    "target_velocity_pct_per_sec": round(eff_v, 2),
                    "effective_velocity_pct_per_sec": round(eff_v, 2),
                    "is_reduced": False,
                    "is_ultra_slow": True,
                }
            else:
                scale_start = 1.0 if "IN" in motion_type else round(1.0 + scale_delta, 4)
                scale_end = round(1.0 + scale_delta, 4) if "IN" in motion_type else 1.0
                return {
                    "motion_type": f"ULTRA_SLOW_{motion_type}",
                    "scale_start": scale_start,
                    "scale_end": scale_end,
                    "pan_x_start": 0.0,
                    "pan_x_end": 0.0,
                    "pan_y_start": 0.0,
                    "pan_y_end": 0.0,
                    "target_velocity_pct_per_sec": round(eff_v, 2),
                    "effective_velocity_pct_per_sec": round(eff_v, 2),
                    "is_reduced": False,
                    "is_ultra_slow": True,
                }

        # Case 3: Normal Speech Shots (2.0s <= dur <= 12.0s) -> Velocity-first integration
        desired_v = 2.4 * ((5.0 / dur_s) ** 0.35)
        clamped_v = max(self.TARGET_MIN_VELOCITY_PCT_PER_SEC, min(self.TARGET_MAX_VELOCITY_PCT_PER_SEC, desired_v))

        # Integrate total delta from velocity
        scale_delta = (clamped_v * dur_s) / 100.0
        # Clamp total delta for visual safety (3% to 16%)
        scale_delta = max(0.03, min(0.16, scale_delta))
        effective_v = (scale_delta / dur_s) * 100.0

        # Hard safety clamp
        if effective_v > self.ABSOLUTE_MAX_VELOCITY_PCT_PER_SEC:
            scale_delta = (self.TARGET_MAX_VELOCITY_PCT_PER_SEC * dur_s) / 100.0
            effective_v = (scale_delta / dur_s) * 100.0

        if "PAN" in motion_type:
            pan_delta = max(0.04, min(0.10, 0.015 * dur_s))
            pan_start = -pan_delta / 2.0 if "RIGHT" in motion_type else pan_delta / 2.0
            pan_end = pan_delta / 2.0 if "RIGHT" in motion_type else -pan_delta / 2.0
            scale_val = 1.10
            return {
                "motion_type": motion_type,
                "scale_start": scale_val,
                "scale_end": scale_val,
                "pan_x_start": round(pan_start, 4),
                "pan_x_end": round(pan_end, 4),
                "pan_y_start": 0.0,
                "pan_y_end": 0.0,
                "target_velocity_pct_per_sec": round(clamped_v, 2),
                "effective_velocity_pct_per_sec": round(effective_v, 2),
                "is_reduced": False,
                "is_ultra_slow": False,
            }
        else:
            scale_start = 1.0 if "IN" in motion_type else round(1.0 + scale_delta, 4)
            scale_end = round(1.0 + scale_delta, 4) if "IN" in motion_type else 1.0
            return {
                "motion_type": motion_type,
                "scale_start": scale_start,
                "scale_end": scale_end,
                "pan_x_start": 0.0,
                "pan_x_end": 0.0,
                "pan_y_start": 0.0,
                "pan_y_end": 0.0,
                "target_velocity_pct_per_sec": round(clamped_v, 2),
                "effective_velocity_pct_per_sec": round(effective_v, 2),
                "is_reduced": False,
                "is_ultra_slow": False,
            }
