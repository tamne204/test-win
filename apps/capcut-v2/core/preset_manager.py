"""
apps/capcut-v2/core/preset_manager.py
Manages built-in and user-defined editing rule presets.
Presets define RuleEngine configurations independently from CapCut schemas.
Zero AI. Fully deterministic.
"""
from __future__ import annotations

import os
import json
import re
from dataclasses import dataclass, field, asdict
from typing import List, Dict, Any, Optional

from .rule_engine import (
    MOTION_ZOOM_IN,
    MOTION_ZOOM_OUT,
    MOTION_PAN_LEFT,
    MOTION_PAN_RIGHT,
    ALL_SUPPORTED_MOTIONS,
)


@dataclass
class RulePreset:
    """Editing style preset configuration."""
    id: str
    name: str
    description: str
    canvas_ratio: str = "9:16"  # "9:16", "16:9", "1:1"
    width: int = 1080
    height: int = 1920
    fps: float = 60.0
    scene_duration_s: float = 5.0  # Duration per image in seconds
    min_scene_duration_s: float = 3.0  # For SRT grouping
    max_scene_duration_s: float = 8.0  # For SRT grouping
    motion_sequence: List[str] = field(
        default_factory=lambda: [
            MOTION_ZOOM_IN,
            MOTION_ZOOM_OUT,
            MOTION_PAN_LEFT,
            MOTION_PAN_RIGHT,
        ]
    )
    zoom_magnitude: float = 0.15  # Scale delta (e.g. 1.0 -> 1.15)
    pan_magnitude: float = 0.10   # Normalized X delta
    caption_enabled: bool = True
    caption_position_y: float = -0.6  # -1.0 (bottom) to 1.0 (top)
    caption_font_size: float = 8.0
    music_volume: float = 1.0
    voice_volume: float = 1.0
    is_builtin: bool = True

    def to_dict(self) -> Dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> RulePreset:
        return cls(**data)


# ---------------------------------------------------------------------------
# Built-in Presets
# ---------------------------------------------------------------------------
PRESET_BASIC_SLIDESHOW = RulePreset(
    id="basic_slideshow",
    name="Basic Slideshow",
    description="Standard alternating zooms and pans at 60 FPS (9:16, 5s per clip)",
    canvas_ratio="9:16",
    width=1080,
    height=1920,
    fps=60.0,
    scene_duration_s=5.0,
    min_scene_duration_s=3.0,
    max_scene_duration_s=8.0,
    motion_sequence=[
        MOTION_ZOOM_IN,
        MOTION_ZOOM_OUT,
        MOTION_PAN_LEFT,
        MOTION_PAN_RIGHT,
    ],
    zoom_magnitude=0.15,
    pan_magnitude=0.10,
    caption_position_y=-0.6,
    is_builtin=True,
)

PRESET_TIKTOK_FAST = RulePreset(
    id="tiktok_fast",
    name="TikTok Fast",
    description="High-tempo 9:16 vertical edit at 60 FPS, 3.0s per clip with dynamic zoom",
    canvas_ratio="9:16",
    width=1080,
    height=1920,
    fps=60.0,
    scene_duration_s=3.0,
    min_scene_duration_s=2.0,
    max_scene_duration_s=5.0,
    motion_sequence=[
        MOTION_ZOOM_IN,
        MOTION_ZOOM_OUT,
        MOTION_PAN_LEFT,
        MOTION_PAN_RIGHT,
    ],
    zoom_magnitude=0.20,
    pan_magnitude=0.12,
    caption_position_y=-0.5,
    is_builtin=True,
)

PRESET_STORY_CALM = RulePreset(
    id="story_calm",
    name="Story Calm",
    description="Gentle story pacing at 30 FPS, 6.0s per clip with subtle motion",
    canvas_ratio="9:16",
    width=1080,
    height=1920,
    fps=30.0,
    scene_duration_s=6.0,
    min_scene_duration_s=4.0,
    max_scene_duration_s=10.0,
    motion_sequence=[
        MOTION_ZOOM_IN,
        MOTION_PAN_LEFT,
        MOTION_ZOOM_OUT,
        MOTION_PAN_RIGHT,
    ],
    zoom_magnitude=0.08,
    pan_magnitude=0.06,
    caption_position_y=-0.7,
    is_builtin=True,
)

PRESET_YOUTUBE_SHORTS_DYNAMIC = RulePreset(
    id="youtube_shorts_dynamic",
    name="YouTube Shorts Dynamic",
    description="Snappy 2.5s cuts at 60 FPS optimized for vertical short-form engagement",
    canvas_ratio="9:16",
    width=1080,
    height=1920,
    fps=60.0,
    scene_duration_s=2.5,
    min_scene_duration_s=1.8,
    max_scene_duration_s=4.5,
    motion_sequence=[
        MOTION_ZOOM_IN,
        MOTION_ZOOM_OUT,
        MOTION_ZOOM_IN,
        MOTION_PAN_RIGHT,
    ],
    zoom_magnitude=0.22,
    pan_magnitude=0.14,
    caption_position_y=-0.55,
    is_builtin=True,
)

BUILTIN_PRESETS: Dict[str, RulePreset] = {
    "basic_slideshow": PRESET_BASIC_SLIDESHOW,
    "tiktok_fast": PRESET_TIKTOK_FAST,
    "story_calm": PRESET_STORY_CALM,
    "youtube_shorts_dynamic": PRESET_YOUTUBE_SHORTS_DYNAMIC,
}


class PresetManager:
    """
    Manages built-in and user-saved presets.
    Persists custom presets in user config directory.
    """

    def __init__(
        self,
        custom_preset_dir: Optional[str] = None,
        user_presets_dir: Optional[str] = None,
    ):
        chosen_dir = custom_preset_dir or user_presets_dir
        if chosen_dir:
            self.custom_dir = chosen_dir
        else:
            self.custom_dir = os.path.expanduser("~/.2toolne/autoedit-capcut/presets")
        os.makedirs(self.custom_dir, exist_ok=True)

    def list_presets(self) -> List[RulePreset]:
        """Return all available presets (built-ins + custom)."""
        presets = list(BUILTIN_PRESETS.values())
        if os.path.isdir(self.custom_dir):
            for fn in os.listdir(self.custom_dir):
                if fn.endswith(".json"):
                    fp = os.path.join(self.custom_dir, fn)
                    try:
                        with open(fp, "r", encoding="utf-8") as f:
                            data = json.load(f)
                            preset = RulePreset.from_dict(data)
                            preset.is_builtin = False
                            presets.append(preset)
                    except Exception as e:
                        print(f"Warning: Failed loading custom preset {fp}: {e}")
        return presets

    def get_preset(self, preset_id: str) -> RulePreset:
        """Find a preset by ID, falling back to basic_slideshow if not found."""
        if preset_id in BUILTIN_PRESETS:
            return BUILTIN_PRESETS[preset_id]

        custom_fp = os.path.join(self.custom_dir, f"{preset_id}.json")
        if os.path.isfile(custom_fp):
            try:
                with open(custom_fp, "r", encoding="utf-8") as f:
                    data = json.load(f)
                    p = RulePreset.from_dict(data)
                    p.is_builtin = False
                    return p
            except Exception as e:
                print(f"Warning: Failed reading custom preset {preset_id}: {e}")

        return PRESET_BASIC_SLIDESHOW

    def save_custom_preset(
        self,
        name_or_preset: Any,
        scene_duration_s: Optional[float] = None,
        motion_sequence: Optional[List[str]] = None,
        canvas_ratio: str = "9:16",
        fps: float = 60.0,
        caption_position_y: float = -0.6,
        audio_volume: float = 1.0,
    ) -> RulePreset:
        """Create and persist a custom user preset."""
        if isinstance(name_or_preset, RulePreset):
            preset = name_or_preset
            preset.is_builtin = False
        else:
            name = str(name_or_preset)
            preset_slug = re.sub(r'[^A-Za-z0-9_\-]', '_', name.strip().lower()) or "custom"
            preset_id = f"custom_{preset_slug}"

            if canvas_ratio == "16:9":
                w, h = 1920, 1080
            elif canvas_ratio == "1:1":
                w, h = 1080, 1080
            else:
                w, h = 1080, 1920
                canvas_ratio = "9:16"

            preset = RulePreset(
                id=preset_id,
                name=name,
                description=f"Custom preset: {canvas_ratio} @ {fps} FPS, {scene_duration_s}s/clip",
                canvas_ratio=canvas_ratio,
                width=w,
                height=h,
                fps=fps,
                scene_duration_s=scene_duration_s or 5.0,
                motion_sequence=motion_sequence or [MOTION_ZOOM_IN, MOTION_ZOOM_OUT],
                caption_position_y=caption_position_y,
                music_volume=audio_volume,
                is_builtin=False,
            )

        # Validation
        if preset.fps not in (23.976, 24.0, 25.0, 29.97, 30.0, 50.0, 59.94, 60.0):
            raise ValueError(f"Invalid FPS: {preset.fps}")

        if not preset.motion_sequence:
            raise ValueError("Preset motion sequence cannot be empty")

        for m in preset.motion_sequence:
            if m not in ALL_SUPPORTED_MOTIONS:
                raise ValueError(f"Unsupported motion type: '{m}'. Must be one of {ALL_SUPPORTED_MOTIONS}")

        if preset.scene_duration_s <= 0:
            raise ValueError(f"Invalid scene duration: {preset.scene_duration_s}")

        fp = os.path.join(self.custom_dir, f"{preset.id}.json")
        with open(fp, "w", encoding="utf-8") as f:
            json.dump(preset.to_dict(), f, indent=2, ensure_ascii=False)

        return preset

    def delete_custom_preset(self, preset_id: str) -> bool:
        """Delete a custom preset by ID. Returns True if deleted, False otherwise."""
        fp = os.path.join(self.custom_dir, f"{preset_id}.json")
        if os.path.isfile(fp):
            os.unlink(fp)
            return True
        return False
