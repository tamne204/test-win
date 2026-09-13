"""
apps/capcut-v2/core/timeline_builder.py
Deterministic timeline builder supporting Fixed and SRT-Driven timing modes.
Builds an EditPlan from input media, audio, and style presets.
Zero AI. Fully deterministic.
"""
from __future__ import annotations

import os
import uuid
from typing import List, Optional, Dict, Any
from PIL import Image

from .edit_plan import (
    EditPlan,
    EditPlanProject,
    EditPlanClip,
    EditPlanAudio,
    EditPlanCaption,
)
from .preset_manager import RulePreset, PRESET_BASIC_SLIDESHOW
from .rule_engine import RuleEngine
from .srt_timeline import (
    parse_srt_file,
    compute_srt_scene_boundaries,
    compute_script_paragraphs_scene_boundaries,
    SubtitleEntry,
)
from .subtitles.models import SubtitleCue
from .subtitles.layout_engine import SubtitleLayoutEngine, LayoutOptions
from .visual import (
    VisualPlannerEngine,
    VisualPlannerOptions,
    VisualPipelineAdapter,
    FrameQuantizationPolicy,
)


TIMING_MODE_FIXED = "FIXED"
TIMING_MODE_SRT_DRIVEN = "SRT_DRIVEN"


class TimelineBuilder:
    """
    Constructs an EditPlan from input media according to deterministic rules.
    Supports fixed durations and subtitle-driven scene boundary segmentation.
    """

    def __init__(self, preset: Optional[RulePreset] = None):
        self.preset = preset or PRESET_BASIC_SLIDESHOW
        self.rule_engine = RuleEngine(self.preset)

    def build(
        self,
        images: List[str],
        audio_path: Optional[str] = None,
        srt_source: Optional[str] = None,
        captions: Optional[List[Dict[str, Any]]] = None,
        project_name: str = "AutoEdit Project",
        custom_clip_duration_s: Optional[float] = None,
        timing_mode: str = TIMING_MODE_FIXED,
        script_text: Optional[str] = None,
        motion_weights: Optional[Dict[str, float]] = None,
        aspect_ratio: Optional[str] = None,
        visual_options: Optional[VisualPlannerOptions] = None,
        subtitle_cues: Optional[List[SubtitleCue]] = None,
        audio_duration_s: Optional[float] = None,
        canvas_width: Optional[int] = None,
        canvas_height: Optional[int] = None,
        canvas_tier: Optional[str] = None,
        scenes: Optional[List[Dict[str, Any]]] = None,
        cross_fade_enabled: bool = False,
    ) -> EditPlan:
        """
        Build an EditPlan from provided image files, audio, and timing mode.

        Args:
            images: List of file paths to images.
            audio_path: Optional file path to audio file.
            srt_source: Optional path to SRT file or raw SRT string.
            captions: Optional manual captions list.
            project_name: Display name of the project.
            custom_clip_duration_s: Override default clip duration if in FIXED mode.
            timing_mode: TIMING_MODE_FIXED or TIMING_MODE_SRT_DRIVEN.
            script_text: Raw user script text with \n for cues and \n\n for scene breaks.
            motion_weights: Random weights for Ken Burns camera motions.
            aspect_ratio: Canvas aspect ratio ("9:16", "16:9", "1:1", "4:5", "21:9").
            visual_options: VisualPlannerOptions runtime configuration.
            subtitle_cues: Optional rich A0 SubtitleCue objects.
            audio_duration_s: Master audio duration in seconds.
            scenes: Optional scene metadata list from input bundle.
        """
        if not images:
            raise ValueError("TimelineBuilder requires at least one image.")

        clips: List[EditPlanClip] = []
        caption_list: List[EditPlanCaption] = []
        total_timeline_duration_us = 0
        shadow_metadata: Dict[str, Any] = {}
        if visual_options is None:

            v_opts = VisualPlannerOptions(
                quantization_policy=FrameQuantizationPolicy.from_fps(self.preset.fps)
            )
        else:
            v_opts = visual_options
            if v_opts.quantization_policy.fps == 60.0 and self.preset.fps != 60.0:
                v_opts.quantization_policy = FrameQuantizationPolicy.from_fps(self.preset.fps)


        # Branch 1: SRT-Driven Timing Mode
        if timing_mode == TIMING_MODE_SRT_DRIVEN and (srt_source or subtitle_cues):
            subtitles = parse_srt_file(srt_source) if srt_source else []
            if not subtitles and not subtitle_cues:
                # Fallback to fixed if SRT has no valid entries
                timing_mode = TIMING_MODE_FIXED
            else:
                # Construct or use canonical SubtitleCue objects for visual planning
                if subtitle_cues:
                    visual_cues = subtitle_cues
                else:
                    visual_cues = [
                        SubtitleCue(
                            index=sub.index,
                            start_s=sub.start_us / 1_000_000.0,
                            end_s=sub.end_us / 1_000_000.0,
                            text=sub.text,
                            paragraph_ids=[sub.paragraph_id] if getattr(sub, "paragraph_id", None) is not None else [],
                        )
                        for sub in subtitles
                    ]

                # Determine effective audio duration
                if audio_duration_s is not None and audio_duration_s > 0:
                    effective_audio_dur_s = audio_duration_s
                elif visual_cues:
                    effective_audio_dur_s = visual_cues[-1].end_s
                elif subtitles:
                    effective_audio_dur_s = subtitles[-1].end_us / 1_000_000.0
                else:
                    effective_audio_dur_s = 0.0

                if subtitles and len(images) == len(subtitles):
                    # Direct 1-to-1 alignment: each scene image maps strictly to its corresponding subtitle cue
                    for idx, sub in enumerate(subtitles):
                        img_path = images[idx]
                        motion = self.rule_engine.assign_motion(idx, weights=motion_weights)
                        params = self.rule_engine.get_motion_parameters(motion)
                        clip = EditPlanClip(
                            clip_id=str(uuid.uuid4()).upper(),
                            media_path=os.path.abspath(img_path),
                            media_type="image",
                            start_us=sub.start_us,
                            duration_us=sub.end_us - sub.start_us,
                            motion_type=motion,
                            keyframe_params=params,
                        )
                        clips.append(clip)
                    total_timeline_duration_us = clips[-1].end_us if clips else 0
                elif scenes and len(images) == len(scenes) and len(scenes) > 1:
                    # Partition total audio duration across all explicit scenes
                    audio_dur_us = int(effective_audio_dur_s * 1_000_000) if effective_audio_dur_s > 0 else (len(scenes) * 3_000_000)
                    step_us = audio_dur_us // len(scenes)
                    for idx in range(len(scenes)):
                        img_path = images[idx]
                        s_us = idx * step_us
                        e_us = audio_dur_us if idx == len(scenes) - 1 else (idx + 1) * step_us
                        motion = self.rule_engine.assign_motion(idx, weights=motion_weights)
                        params = self.rule_engine.get_motion_parameters(motion)
                        clip = EditPlanClip(
                            clip_id=str(uuid.uuid4()).upper(),
                            media_path=os.path.abspath(img_path),
                            media_type="image",
                            start_us=s_us,
                            duration_us=e_us - s_us,
                            motion_type=motion,
                            keyframe_params=params,
                        )
                        clips.append(clip)
                    total_timeline_duration_us = clips[-1].end_us if clips else 0
                elif v_opts.engine == VisualPlannerEngine.HIERARCHICAL_DP_V1:
                    adapter = VisualPipelineAdapter(options=v_opts)
                    shots, report = adapter.plan_visual_shots(
                        subtitles=visual_cues,
                        images=images,
                        master_audio_duration_s=effective_audio_dur_s,
                        options=v_opts,
                    )
                    clips = adapter.shots_to_editplan_clips(shots)
                    total_timeline_duration_us = clips[-1].end_us if clips else 0
                else:
                    # Legacy execution
                    if subtitles:
                        if script_text and script_text.strip():
                            scenes = compute_script_paragraphs_scene_boundaries(
                                script_text=script_text,
                                subtitles=subtitles,
                            )
                        else:
                            scenes = compute_srt_scene_boundaries(
                                subtitles=subtitles,
                                min_duration_s=self.preset.min_scene_duration_s,
                                max_duration_s=self.preset.max_scene_duration_s,
                            )
                    else:
                        scenes = []

                    for idx, scene in enumerate(scenes):
                        img_path = images[idx % len(images)]
                        motion = self.rule_engine.assign_motion(idx, weights=motion_weights)
                        params = self.rule_engine.get_motion_parameters(motion)

                        clip = EditPlanClip(
                            clip_id=str(uuid.uuid4()).upper(),
                            media_path=os.path.abspath(img_path),
                            media_type="image",
                            start_us=scene.start_us,
                            duration_us=scene.duration_us,
                            motion_type=motion,
                            keyframe_params=params,
                        )
                        clips.append(clip)

                    total_timeline_duration_us = clips[-1].end_us if clips else 0

                    # Shadow Mode Execution
                    if v_opts.shadow_mode and visual_cues:
                        try:
                            shadow_adapter = VisualPipelineAdapter(options=v_opts)
                            shadow_shots, shadow_report = shadow_adapter.plan_visual_shots(
                                subtitles=visual_cues,
                                images=images,
                                master_audio_duration_s=effective_audio_dur_s,
                                options=v_opts,
                            )
                            comparison = VisualPipelineAdapter.compute_shadow_comparison(
                                legacy_clips=clips,
                                v1_shots=shadow_shots,
                                master_audio_duration_s=effective_audio_dur_s,
                            )
                            shadow_metadata = {
                                "shadow_mode": True,
                                "shadow_shots_count": len(shadow_shots),
                                "shadow_comparison": comparison,
                                "shadow_report_summary": shadow_report.to_dict(),
                            }
                        except Exception as e:
                            shadow_metadata = {
                                "shadow_mode": True,
                                "shadow_error": str(e),
                            }

                # Generate synchronized captions from subtitles or visual_cues
                cues_for_captions = subtitles if subtitles else visual_cues
                for sub in cues_for_captions:
                    start_us = sub.start_us if hasattr(sub, "start_us") else int(round(sub.start_s * 1_000_000))
                    dur_us = sub.duration_us if hasattr(sub, "duration_us") else int(round((sub.end_s - sub.start_s) * 1_000_000))
                    caption_list.append(
                        EditPlanCaption(
                            caption_id=str(uuid.uuid4()).upper(),
                            text=sub.text,
                            start_us=start_us,
                            duration_us=dur_us,
                            font_size=getattr(self.preset, "caption_font_size", 8.0),
                            position_y=getattr(self.preset, "caption_position_y", -0.6),
                        )
                    )

        # Branch 2: Fixed Duration Timing Mode (Default / Fallback)
        if timing_mode == TIMING_MODE_FIXED or not clips:
            clip_duration_s = custom_clip_duration_s or self.preset.scene_duration_s
            clip_duration_us = int(clip_duration_s * 1_000_000)

            current_time_us = 0
            for idx, img_path in enumerate(images):
                motion = self.rule_engine.assign_motion(idx, weights=motion_weights)
                params = self.rule_engine.get_motion_parameters(motion)

                clip = EditPlanClip(
                    clip_id=str(uuid.uuid4()).upper(),
                    media_path=os.path.abspath(img_path),
                    media_type="image",
                    start_us=current_time_us,
                    duration_us=clip_duration_us,
                    motion_type=motion,
                    keyframe_params=params,
                )
                clips.append(clip)
                current_time_us += clip_duration_us

            total_timeline_duration_us = current_time_us

            # Manual captions if provided
            if captions:
                for cap in captions:
                    start_us = int(cap.get("start_s", 0.0) * 1_000_000)
                    dur_us = int(cap.get("duration_s", clip_duration_s) * 1_000_000)
                    caption_list.append(
                        EditPlanCaption(
                            caption_id=str(uuid.uuid4()).upper(),
                            text=cap.get("text", ""),
                            start_us=start_us,
                            duration_us=dur_us,
                            font_size=cap.get("font_size", getattr(self.preset, "caption_font_size", 8.0)),
                            position_y=cap.get("position_y", getattr(self.preset, "caption_position_y", -0.6)),
                        )
                    )
            elif not caption_list:
                caption_list.append(
                    EditPlanCaption(
                        caption_id=str(uuid.uuid4()).upper(),
                        text="2TOOLNE AUTOEDIT POC",
                        start_us=0,
                        duration_us=min(total_timeline_duration_us, 5_000_000),
                        font_size=getattr(self.preset, "caption_font_size", 8.0),
                        position_y=getattr(self.preset, "caption_position_y", -0.6),
                    )
                )

        # Audio track assembly
        audio_list: List[EditPlanAudio] = []
        if audio_path and os.path.isfile(audio_path):
            audio_dur_us = int(round(audio_duration_s * 1_000_000)) if (audio_duration_s and audio_duration_s > 0) else total_timeline_duration_us
            audio_list.append(
                EditPlanAudio(
                    audio_id=str(uuid.uuid4()).upper(),
                    audio_path=os.path.abspath(audio_path),
                    start_us=0,
                    duration_us=audio_dur_us,
                    volume=self.preset.music_volume,
                    category="music",
                )
            )

        # Resolve canvas dimensions strictly from project settings / preset, NOT source media dimensions
        if canvas_width and canvas_height and canvas_width > 0 and canvas_height > 0:
            w, h = canvas_width, canvas_height
            ratio = aspect_ratio or EditPlanProject(width=w, height=h).aspect_ratio
        else:
            ratio = aspect_ratio or getattr(self.preset, "canvas_ratio", "9:16")
            preset_w = getattr(self.preset, "width", 1080)
            preset_h = getattr(self.preset, "height", 1920)

            tier = canvas_tier
            if not tier:
                if preset_w == 4096 or preset_h == 4096:
                    tier = "DCI_4K"
                elif preset_w >= 3840 or preset_h >= 3840:
                    tier = "UHD_4K"
                elif preset_w == 2048 and preset_h == 1080:
                    tier = "DCI_2K"
                elif preset_w >= 2048 or preset_h >= 2048 or preset_w == 1440 or preset_h == 2560:
                    tier = "2K"
                elif (preset_w == 1280 and preset_h == 720) or (preset_w == 720 and preset_h == 1280):
                    tier = "720p"
                else:
                    tier = "1080p"

            if tier == "DCI_4K":
                if ratio == "16:9":
                    w, h = 4096, 2160
                elif ratio == "9:16":
                    w, h = 2160, 4096
                elif ratio == "1:1":
                    w, h = 3840, 3840
                elif ratio == "4:5":
                    w, h = 3072, 3840
                else:
                    w, h = 4096, 2160
            elif tier == "UHD_4K":
                if ratio == "16:9":
                    w, h = 3840, 2160
                elif ratio == "9:16":
                    w, h = 2160, 3840
                elif ratio == "1:1":
                    w, h = 3840, 3840
                elif ratio == "4:5":
                    w, h = 3072, 3840
                elif ratio == "21:9":
                    w, h = 5120, 2160
                else:
                    w, h = 3840, 2160
            elif tier == "DCI_2K":
                if ratio == "16:9":
                    w, h = 2048, 1080
                elif ratio == "9:16":
                    w, h = 1080, 2048
                elif ratio == "1:1":
                    w, h = 2048, 2048
                elif ratio == "4:5":
                    w, h = 2048, 2560
                else:
                    w, h = 2048, 1080
            elif tier in ["2K", "1440p"]:
                if ratio == "16:9":
                    w, h = 2560, 1440
                elif ratio == "9:16":
                    w, h = 1440, 2560
                elif ratio == "1:1":
                    w, h = 2048, 2048
                elif ratio == "4:5":
                    w, h = 2048, 2560
                elif ratio == "21:9":
                    w, h = 3440, 1440
                else:
                    w, h = 2560, 1440
            elif tier == "720p":
                if ratio == "16:9":
                    w, h = 1280, 720
                elif ratio == "9:16":
                    w, h = 720, 1280
                elif ratio == "1:1":
                    w, h = 720, 720
                elif ratio == "4:5":
                    w, h = 720, 900
                else:
                    w, h = 1280, 720
            else:
                # Standard 1080p resolution
                if ratio == "16:9":
                    w, h = 1920, 1080
                elif ratio == "9:16":
                    w, h = 1080, 1920
                elif ratio == "1:1":
                    w, h = 1080, 1080
                elif ratio == "4:5":
                    w, h = 1080, 1350
                elif ratio == "21:9":
                    w, h = 2560, 1080
                else:
                    w, h = 1080, 1920

        # Subtitle layout and safe area wrapping
        if caption_list:
            layout_engine = SubtitleLayoutEngine(
                LayoutOptions(
                    canvas_width=w,
                    canvas_height=h,
                    safe_width_ratio=0.82,
                    max_lines=2,
                    min_font_scale=0.85,
                    default_font_size=getattr(self.preset, "caption_font_size", 8.0),
                )
            )
            for cap in caption_list:
                layout_res = layout_engine.layout_caption(cap.text, font_size=cap.font_size)
                cap.display_text = layout_res.display_text
                cap.font_scale = layout_res.font_scale
                cap.line_count = layout_res.line_count

        project = EditPlanProject(
            name=project_name,
            width=w,
            height=h,
            fps=self.preset.fps,
            duration_us=total_timeline_duration_us,
        )

        plan = EditPlan(
            project=project,
            clips=clips,
            audio=audio_list,
            captions=caption_list,
            metadata={
                "preset_name": getattr(self.preset, "name", "Basic Auto Edit"),
                "preset_id": getattr(self.preset, "id", "basic"),
                "timing_mode": timing_mode,
                "clip_count": len(clips),
                "has_audio": bool(audio_list),
                "has_captions": bool(caption_list),
                "visual_planner_engine": v_opts.engine.value,
                "cross_fade_enabled": bool(cross_fade_enabled),
                **shadow_metadata,
            },
        )

        # Validate logic
        errors = plan.validate()
        if errors:
            raise ValueError(f"EditPlan validation failed: {'; '.join(errors)}")

        return plan
