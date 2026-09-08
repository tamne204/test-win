"""
apps/capcut-v2/core/edit_plan.py
NLE-independent intermediate format representing the complete video timeline.
This is the single source of truth for V2 timeline data.
"""
from __future__ import annotations

import os
import json
import uuid
from dataclasses import dataclass, field, asdict
from typing import List, Dict, Any, Optional

ERROR_EDIT_PLAN_INVALID = "EDIT_PLAN_INVALID"

SUPPORTED_ASPECT_RATIOS = ["9:16", "16:9", "1:1", "4:5", "21:9"]
SUPPORTED_FRAME_RATES = [23.976, 24.0, 25.0, 29.97, 30.0, 50.0, 59.94, 60.0]
SUPPORTED_MOTIONS = [
    "ZOOM_IN",
    "ZOOM_OUT",
    "PAN_LEFT",
    "PAN_RIGHT",
    "PAN_UP",
    "PAN_DOWN",
    "NONE",
]


@dataclass
class EditPlanProject:
    """Project-level dimensions, frame rate, and duration."""
    name: str = "AutoEdit Project"
    width: int = 1080
    height: int = 1920
    fps: float = 60.0
    duration_us: int = 0  # Total duration in microseconds

    @property
    def aspect_ratio(self) -> str:
        if self.width == 1080 and self.height == 1920:
            return "9:16"
        elif self.width == 1920 and self.height == 1080:
            return "16:9"
        elif self.width == 1080 and self.height == 1080:
            return "1:1"
        elif self.width == 1080 and self.height == 1350:
            return "4:5"
        elif self.width == 2560 and self.height == 1080:
            return "21:9"
        return f"{self.width}:{self.height}"


@dataclass
class EditPlanClip:
    """A single visual clip (image or video) on the video track."""
    clip_id: str
    media_path: str
    start_us: int  # Start position on timeline (microseconds)
    duration_us: int  # Duration on timeline (microseconds)
    media_type: str = "image"  # "image" or "video"
    motion_type: str = "NONE"  # One of SUPPORTED_MOTIONS
    keyframe_params: Dict[str, Any] = field(default_factory=dict)
    width: int = 0
    height: int = 0

    @property
    def end_us(self) -> int:
        return self.start_us + self.duration_us


@dataclass
class EditPlanAudio:
    """An audio element on an audio track."""
    audio_id: str
    audio_path: str
    start_us: int  # Start position on timeline
    duration_us: int  # Duration on timeline
    volume: float = 1.0
    category: str = "music"  # "music", "voice", "sfx"


@dataclass
class EditPlanCaption:
    """A text or subtitle element on a text track."""
    caption_id: str
    text: str
    start_us: int
    duration_us: int
    font_size: float = 8.0
    color_rgb: List[float] = field(default_factory=lambda: [1.0, 1.0, 1.0])
    position_x: float = 0.0
    position_y: float = -0.6  # Normalized Y position (-1.0 to 1.0)
    background_color: Optional[List[float]] = None
    stroke_color: Optional[List[float]] = None
    stroke_width: float = 0.0
    display_text: Optional[str] = None
    font_scale: float = 1.0
    line_count: int = 1


@dataclass
class EditPlan:
    """
    Composite Edit Plan containing all project elements.
    Source of truth for CapCut adapter.
    """
    project: EditPlanProject
    clips: List[EditPlanClip] = field(default_factory=list)
    audio: List[EditPlanAudio] = field(default_factory=list)
    captions: List[EditPlanCaption] = field(default_factory=list)
    metadata: Dict[str, Any] = field(default_factory=dict)

    def validate(self, check_files_exist: bool = False) -> List[str]:
        """
        Validate logical consistency and safety of the EditPlan.
        Returns list of error strings. If empty, the plan is valid.
        """
        errors: List[str] = []

        # 1. Project-level checks
        if self.project.width <= 0 or self.project.height <= 0:
            errors.append(f"Invalid canvas dimensions: {self.project.width}x{self.project.height}")

        if self.project.fps not in SUPPORTED_FRAME_RATES:
            errors.append(f"Invalid or unsupported FPS: {self.project.fps}. Must be one of {SUPPORTED_FRAME_RATES}")

        if not self.clips:
            errors.append("EditPlan must contain at least one visual clip.")

        # 2. Clips validation
        sorted_clips = sorted(self.clips, key=lambda c: c.start_us)
        for idx, clip in enumerate(sorted_clips):
            if clip.start_us < 0:
                errors.append(f"Clip {clip.clip_id} has negative start time: {clip.start_us} us")
            if clip.duration_us <= 0:
                errors.append(f"Clip {clip.clip_id} has zero or negative duration: {clip.duration_us} us")
            if clip.motion_type not in SUPPORTED_MOTIONS:
                errors.append(f"Clip {clip.clip_id} has invalid motion enum '{clip.motion_type}'. Must be one of {SUPPORTED_MOTIONS}")
            if not clip.media_path:
                errors.append(f"Clip {clip.clip_id} has empty media_path")
            elif check_files_exist and not os.path.exists(clip.media_path):
                errors.append(f"Clip {clip.clip_id} source media missing on disk: '{clip.media_path}'")

        # 3. Non-overlapping main track check
        for i in range(len(sorted_clips) - 1):
            curr = sorted_clips[i]
            nxt = sorted_clips[i + 1]
            if curr.end_us > nxt.start_us:
                errors.append(
                    f"Clips overlap detected on main track: clip {curr.clip_id} ends at {curr.end_us} us but clip {nxt.clip_id} starts at {nxt.start_us} us"
                )

        # 4. Audio validation
        for aud in self.audio:
            if aud.start_us < 0:
                errors.append(f"Audio {aud.audio_id} has negative start time: {aud.start_us} us")
            if aud.duration_us <= 0:
                errors.append(f"Audio {aud.audio_id} has zero or negative duration: {aud.duration_us} us")
            if not (0.0 <= aud.volume <= 2.0):
                errors.append(f"Audio {aud.audio_id} volume {aud.volume} out of range [0.0, 2.0]")
            if not aud.audio_path:
                errors.append(f"Audio {aud.audio_id} has empty audio_path")
            elif check_files_exist and not os.path.exists(aud.audio_path):
                errors.append(f"Audio {aud.audio_id} source file missing on disk: '{aud.audio_path}'")

        # 5. Captions validation
        for cap in self.captions:
            if cap.start_us < 0:
                errors.append(f"Caption {cap.caption_id} has negative start time: {cap.start_us} us")
            if cap.duration_us <= 0:
                errors.append(f"Caption {cap.caption_id} has zero or negative duration: {cap.duration_us} us")
            if not (-1.0 <= cap.position_y <= 1.0):
                errors.append(f"Caption {cap.caption_id} position_y {cap.position_y} out of bounds [-1.0, 1.0]")

        # 6. Validate & sync project duration
        computed_duration = max((c.end_us for c in self.clips), default=0)
        if self.audio:
            audio_max = max((a.start_us + a.duration_us for a in self.audio), default=0)
            computed_duration = max(computed_duration, audio_max)

        if self.project.duration_us < computed_duration:
            self.project.duration_us = computed_duration

        return errors

    def to_dict(self) -> Dict[str, Any]:
        """Serialize EditPlan to dictionary."""
        return {
            "project": asdict(self.project),
            "clips": [asdict(c) for c in self.clips],
            "audio": [asdict(a) for a in self.audio],
            "captions": [asdict(c) for c in self.captions],
            "metadata": self.metadata,
        }

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> EditPlan:
        """Deserialize EditPlan from dictionary."""
        project = EditPlanProject(**data["project"])
        clips = [EditPlanClip(**c) for c in data.get("clips", [])]
        audio = [EditPlanAudio(**a) for a in data.get("audio", [])]
        captions = [EditPlanCaption(**c) for c in data.get("captions", [])]
        return cls(
            project=project,
            clips=clips,
            audio=audio,
            captions=captions,
            metadata=data.get("metadata", {}),
        )

    def to_json(self, indent: int = 2) -> str:
        return json.dumps(self.to_dict(), indent=indent)
