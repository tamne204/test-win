"""
apps/capcut-v2/adapters/capcut/version_9_3.py
Specialized adapter for CapCut Desktop Version 9.3.0 (macOS/Windows).
Converts EditPlan -> CapCut 9.3.0 draft structure.
"""
from __future__ import annotations

import os
import json
import time
import uuid
import shutil
from typing import Dict, Any, List, Tuple
from PIL import Image

try:
    from ...core.edit_plan import EditPlan, EditPlanClip, EditPlanAudio, EditPlanCaption
except (ImportError, ValueError):
    from core.edit_plan import EditPlan, EditPlanClip, EditPlanAudio, EditPlanCaption



class CapCutVersionAdapter_9_3:
    """
    Adapter implementing the empirical CapCut 9.3.0 draft schema.
    """

    @classmethod
    def generate_draft(
        cls,
        edit_plan: EditPlan,
        target_dir: str,
        draft_root_path: str,
    ) -> Dict[str, str]:
        """
        Generate complete CapCut 9.3.0 draft directory.

        Args:
            edit_plan: Validated EditPlan.
            target_dir: Absolute path to the output draft directory.
            draft_root_path: Parent draft directory path (e.g. com.lveditor.draft).

        Returns:
            Dict containing generated file paths.
        """
        os.makedirs(target_dir, exist_ok=True)
        media_dir = os.path.join(target_dir, "media")
        audio_dir = os.path.join(target_dir, "audio")
        os.makedirs(media_dir, exist_ok=True)
        os.makedirs(audio_dir, exist_ok=True)

        draft_id = str(uuid.uuid4()).upper()
        now_us = int(time.time() * 1_000_000)
        project_name = edit_plan.project.name

        # Staging: Copy source media into stable project directory
        staged_clips: List[Tuple[EditPlanClip, str]] = []
        for idx, clip in enumerate(edit_plan.clips):
            ext = os.path.splitext(clip.media_path)[1] or ".png"
            dest_name = f"clip_{idx:03d}{ext}"
            dest_path = os.path.join(media_dir, dest_name)
            if not os.path.exists(dest_path):
                shutil.copy2(clip.media_path, dest_path)
            staged_clips.append((clip, dest_path))

        staged_audio: List[Tuple[EditPlanAudio, str]] = []
        for idx, aud in enumerate(edit_plan.audio):
            ext = os.path.splitext(aud.audio_path)[1] or ".wav"
            dest_name = f"audio_{idx:03d}{ext}"
            dest_path = os.path.join(audio_dir, dest_name)
            if not os.path.exists(dest_path):
                shutil.copy2(aud.audio_path, dest_path)
            staged_audio.append((aud, dest_path))

        # Build Materials
        materials: Dict[str, List[Any]] = {
            "flowers": [],
            "videos": [],
            "tail_leaders": [],
            "audios": [],
            "images": [],
            "texts": [],
            "effects": [],
            "stickers": [],
            "canvases": [],
            "transitions": [],
            "audio_effects": [],
            "audio_fades": [],
            "beats": [],
            "material_animations": [],
            "placeholders": [],
            "placeholder_infos": [],
            "speeds": [],
            "common_mask": [],
            "chromas": [],
            "text_templates": [],
            "realtime_denoises": [],
            "audio_pannings": [],
            "audio_pitch_shifts": [],
            "video_trackings": [],
            "hsl": [],
            "drafts": [],
            "color_curves": [],
            "hsl_curves": [],
            "primary_color_wheels": [],
            "log_color_wheels": [],
            "video_effects": [],
            "audio_balances": [],
            "handwrites": [],
            "manual_deformations": [],
            "manual_beautys": [],
            "plugin_effects": [],
            "sound_channel_mappings": [],
            "green_screens": [],
            "shapes": [],
            "material_colors": [],
            "digital_humans": [],
            "digital_human_model_dressing": [],
            "smart_crops": [],
            "ai_translates": [],
            "audio_track_indexes": [],
            "loudnesses": [],
            "vocal_beautifys": [],
            "vocal_separations": [],
            "smart_relights": [],
            "time_marks": [],
            "multi_language_refs": [],
            "video_shadows": [],
            "video_strokes": [],
            "video_radius": [],
        }

        # Build Video Track & Segments
        video_segments: List[Dict[str, Any]] = []
        for idx, (clip, stable_path) in enumerate(staged_clips):
            video_mat_id = str(uuid.uuid4()).upper()

            # Read dimensions if possible
            w, h = 1080, 1920
            try:
                with Image.open(stable_path) as im:
                    w, h = im.size
            except Exception:
                pass

            # Register video/photo material
            materials["videos"].append({
                "id": video_mat_id,
                "unique_id": "",
                "type": "photo",
                "duration": clip.duration_us,
                "path": stable_path,
                "media_path": "",
                "local_id": "",
                "has_audio": False,
                "width": w,
                "height": h,
                "category_id": "",
                "category_name": "",
                "material_id": "",
                "material_name": os.path.basename(stable_path),
                "material_url": "",
                "crop": {
                    "upper_left_x": 0.0,
                    "upper_left_y": 0.0,
                    "upper_right_x": 1.0,
                    "upper_right_y": 0.0,
                    "lower_left_x": 0.0,
                    "lower_left_y": 1.0,
                    "lower_right_x": 1.0,
                    "lower_right_y": 1.0,
                },
                "crop_ratio": "free",
                "crop_scale": 1.0,
                "source": 0,
                "source_platform": 0,
                "check_flag": 62978047,
            })

            # Create supporting materials for segment
            speed_id = str(uuid.uuid4()).upper()
            materials["speeds"].append({
                "id": speed_id,
                "type": "speed",
                "mode": 0,
                "speed": 1.0,
                "curve_speed": None,
            })

            placeholder_id = str(uuid.uuid4()).upper()
            materials["placeholder_infos"].append({
                "id": placeholder_id,
                "type": "placeholder_info",
                "meta_type": "none",
                "res_path": "",
                "res_text": "",
                "error_path": "",
                "error_text": "",
            })

            canvas_id = str(uuid.uuid4()).upper()
            materials["canvases"].append({
                "id": canvas_id,
                "type": "canvas_color",
                "color": "",
                "blur": 0.0,
                "image": "",
                "album_image": "",
                "image_id": "",
                "image_name": "",
                "source_platform": 0,
                "team_id": "",
            })

            sound_chan_id = str(uuid.uuid4()).upper()
            materials["sound_channel_mappings"].append({
                "id": sound_chan_id,
                "type": "",
                "audio_channel_mapping": 0,
                "is_config_open": False,
            })

            mat_color_id = str(uuid.uuid4()).upper()
            materials["material_colors"].append({
                "id": mat_color_id,
                "is_color_clip": False,
                "is_gradient": False,
                "solid_color": "",
                "gradient_colors": [],
                "gradient_percents": [],
                "gradient_angle": 90.0,
                "width": 0.0,
                "height": 0.0,
            })

            vocal_sep_id = str(uuid.uuid4()).upper()
            materials["vocal_separations"].append({
                "id": vocal_sep_id,
                "type": "vocal_separation",
                "choice": 0,
                "removed_sounds": [],
                "time_range": None,
                "production_path": "",
                "final_algorithm": "",
                "enter_from": "",
            })

            # Generate Native Keyframes for Motion
            common_keyframes = cls._build_keyframes(clip)

            # Segment assembly
            seg_id = str(uuid.uuid4()).upper()
            video_segments.append({
                "id": seg_id,
                "source_timerange": {
                    "start": 0,
                    "duration": clip.duration_us,
                },
                "target_timerange": {
                    "start": clip.start_us,
                    "duration": clip.duration_us,
                },
                "render_timerange": {
                    "start": 0,
                    "duration": 0,
                },
                "desc": "",
                "state": 0,
                "speed": 1.0,
                "is_loop": False,
                "is_tone_modify": False,
                "reverse": False,
                "intensifies_audio": False,
                "cartoon": False,
                "volume": 1.0,
                "last_nonzero_volume": 1.0,
                "clip": {
                    "scale": {
                        "x": 1.0,
                        "y": 1.0,
                    },
                    "rotation": 0.0,
                    "transform": {
                        "x": 0.0,
                        "y": 0.0,
                    },
                    "flip": {
                        "vertical": False,
                        "horizontal": False,
                    },
                    "alpha": 1.0,
                },
                "uniform_scale": {
                    "on": True,
                    "value": 1.0,
                },
                "material_id": video_mat_id,
                "extra_material_refs": [
                    speed_id,
                    placeholder_id,
                    canvas_id,
                    sound_chan_id,
                    mat_color_id,
                    vocal_sep_id,
                ],
                "render_index": 0,
                "keyframe_refs": [],
                "visible": True,
                "common_keyframes": common_keyframes,
                "caption_info": None,
                "source": "segmentsourcenormal",
            })

        tracks: List[Dict[str, Any]] = [
            {
                "id": str(uuid.uuid4()).upper(),
                "type": "video",
                "flag": 0,
                "segments": video_segments,
            }
        ]

        # Build Audio Track & Segments
        if staged_audio:
            audio_segments: List[Dict[str, Any]] = []
            for aud, stable_path in staged_audio:
                audio_mat_id = str(uuid.uuid4()).upper()
                materials["audios"].append({
                    "id": audio_mat_id,
                    "type": "music",
                    "name": os.path.basename(stable_path),
                    "duration": aud.duration_us,
                    "path": stable_path,
                    "category_name": "",
                    "wave_points": [],
                    "check_flag": 1,
                })

                aud_speed_id = str(uuid.uuid4()).upper()
                materials["speeds"].append({
                    "id": aud_speed_id,
                    "type": "speed",
                    "mode": 0,
                    "speed": 1.0,
                    "curve_speed": None,
                })

                aud_placeholder_id = str(uuid.uuid4()).upper()
                materials["placeholder_infos"].append({
                    "id": aud_placeholder_id,
                    "type": "placeholder_info",
                    "meta_type": "none",
                    "res_path": "",
                    "res_text": "",
                    "error_path": "",
                    "error_text": "",
                })

                beats_id = str(uuid.uuid4()).upper()
                materials["beats"].append({
                    "id": beats_id,
                    "type": "beats",
                    "enable_ai_beats": False,
                    "gear": 404,
                    "gear_count": 0,
                    "mode": 404,
                    "user_beats": [],
                    "ai_beats": {"melody_url": "", "beats_url": ""},
                })

                aud_sound_chan_id = str(uuid.uuid4()).upper()
                materials["sound_channel_mappings"].append({
                    "id": aud_sound_chan_id,
                    "type": "",
                    "audio_channel_mapping": 0,
                    "is_config_open": False,
                })

                aud_vocal_sep_id = str(uuid.uuid4()).upper()
                materials["vocal_separations"].append({
                    "id": aud_vocal_sep_id,
                    "type": "vocal_separation",
                    "choice": 0,
                    "removed_sounds": [],
                    "time_range": None,
                    "production_path": "",
                    "final_algorithm": "",
                    "enter_from": "",
                })

                audio_segments.append({
                    "id": str(uuid.uuid4()).upper(),
                    "source_timerange": {
                        "start": 0,
                        "duration": aud.duration_us,
                    },
                    "target_timerange": {
                        "start": aud.start_us,
                        "duration": aud.duration_us,
                    },
                    "render_timerange": {
                        "start": 0,
                        "duration": 0,
                    },
                    "desc": "",
                    "state": 0,
                    "speed": 1.0,
                    "volume": aud.volume,
                    "last_nonzero_volume": aud.volume,
                    "material_id": audio_mat_id,
                    "extra_material_refs": [
                        aud_speed_id,
                        aud_placeholder_id,
                        beats_id,
                        aud_sound_chan_id,
                        aud_vocal_sep_id,
                    ],
                    "common_keyframes": [],
                })

            tracks.append({
                "id": str(uuid.uuid4()).upper(),
                "type": "audio",
                "flag": 0,
                "segments": audio_segments,
            })

        # Build Text Track & Segments
        if edit_plan.captions:
            text_segments: List[Dict[str, Any]] = []
            for cap in edit_plan.captions:
                text_mat_id = str(uuid.uuid4()).upper()
                render_text = cap.display_text if (getattr(cap, "display_text", None) is not None) else cap.text
                font_scale = getattr(cap, "font_scale", 1.0)
                effective_size = round(cap.font_size * font_scale, 3)

                style_obj: Dict[str, Any] = {
                    "fill": {
                        "content": {
                            "solid": {
                                "color": cap.color_rgb
                            }
                        },
                        "render_type": "solid"
                    },
                    "range": [0, len(render_text)],
                    "size": effective_size,
                    "useLetterColor": True,
                }
                if getattr(cap, "stroke_color", None) and getattr(cap, "stroke_width", 0.0) > 0:
                    style_obj["strokes"] = [{
                        "width": cap.stroke_width,
                        "mode": 0,
                        "content": {
                            "solid": {"color": cap.stroke_color},
                            "render_type": "solid"
                        }
                    }]

                content_payload = json.dumps({
                    "styles": [style_obj],
                    "text": render_text,
                })

                materials["texts"].append({
                    "id": text_mat_id,
                    "type": "text",
                    "content": content_payload,
                    "name": "",
                    "recognize_text": "",
                    "alignment": 1,
                    "line_max_width": 0.82,
                })

                pos_x = getattr(cap, "position_x", 0.0)
                pos_y = getattr(cap, "position_y", -0.6)

                text_segments.append({
                    "id": str(uuid.uuid4()).upper(),
                    "source_timerange": None,
                    "target_timerange": {
                        "start": cap.start_us,
                        "duration": cap.duration_us,
                    },
                    "render_timerange": {
                        "start": 0,
                        "duration": 0,
                    },
                    "desc": "",
                    "state": 0,
                    "speed": 1.0,
                    "volume": 1.0,
                    "clip": {
                        "scale": {"x": 1.0, "y": 1.0},
                        "rotation": 0.0,
                        "transform": {"x": pos_x, "y": pos_y},
                        "flip": {"vertical": False, "horizontal": False},
                        "alpha": 1.0,
                    },
                    "material_id": text_mat_id,
                    "extra_material_refs": [],
                    "common_keyframes": [],
                })


            tracks.append({
                "id": str(uuid.uuid4()).upper(),
                "type": "text",
                "flag": 0,
                "segments": text_segments,
            })

        # Assemble full draft_info.json
        draft_info = {
            "id": draft_id,
            "version": 360000,
            "new_version": "171.0.0",
            "name": project_name,
            "duration": edit_plan.project.duration_us,
            "create_time": now_us,
            "update_time": now_us,
            "fps": edit_plan.project.fps,
            "is_drop_frame_timecode": False,
            "color_space": -1,
            "config": {
                "video_mute": False,
                "maintrack_adsorb": True,
                "material_save_mode": 0,
                "multi_language_mode": "none",
            },
            "canvas_config": {
                "ratio": edit_plan.project.aspect_ratio,
                "width": edit_plan.project.width,
                "height": edit_plan.project.height,
                "background": None,
            },
            "tracks": tracks,
            "group_container": None,
            "materials": materials,
            "keyframes": {
                "videos": [],
                "audios": [],
                "texts": [],
                "stickers": [],
                "filters": [],
                "adjusts": [],
                "handwrites": [],
                "effects": [],
            },
            "keyframe_graph_list": [],
            "platform": {
                "os": "mac" if os.name != "nt" else "windows",
                "os_version": "26.1",
                "app_id": 359289,
                "app_version": "9.3.0",
                "app_source": "cc",
                "device_id": "e2ab75b240d25660de3fec347c70d10d",
                "hard_disk_id": "7ab4aebe9f07f43628c1ef7e4d0c2882",
                "mac_address": "6bc841bbeaf3481a13980270836c41db",
            },
            "last_modified_platform": {
                "os": "mac" if os.name != "nt" else "windows",
                "os_version": "26.1",
                "app_id": 359289,
                "app_version": "9.3.0",
                "app_source": "cc",
                "device_id": "e2ab75b240d25660de3fec347c70d10d",
                "hard_disk_id": "7ab4aebe9f07f43628c1ef7e4d0c2882",
                "mac_address": "6bc841bbeaf3481a13980270836c41db",
            },
            "draft_type": "video",
        }

        # Write draft_info.json
        info_path = os.path.join(target_dir, "draft_info.json")
        with open(info_path, "w", encoding="utf-8") as fp:
            json.dump(draft_info, fp, indent=2, ensure_ascii=False)

        # Assemble draft_meta_info.json
        draft_meta_info = {
            "cloud_draft_cover": False,
            "cloud_draft_sync": False,
            "draft_cover": "draft_cover.jpg",
            "draft_fold_path": target_dir,
            "draft_id": draft_id,
            "draft_materials": [
                {
                    "type": 0,
                    "value": [
                        {
                            "ai_group_type": "",
                            "create_time": int(now_us / 1_000_000),
                            "duration": clip.duration_us,
                            "enter_from": 0,
                            "extra_info": os.path.basename(s_path),
                            "file_Path": s_path,
                            "height": edit_plan.project.height,
                            "id": str(uuid.uuid4()),
                            "import_time": int(now_us / 1_000_000),
                            "import_time_ms": now_us,
                            "item_source": 1,
                            "md5": "",
                            "metetype": "photo",
                            "roughcut_time_range": {"duration": clip.duration_us, "start": 0},
                            "sub_time_range": {"duration": -1, "start": -1},
                            "type": 0,
                            "width": edit_plan.project.width,
                        }
                        for clip, s_path in staged_clips
                    ]
                },
                {
                    "type": 1,
                    "value": [
                        {
                            "ai_group_type": "",
                            "create_time": int(now_us / 1_000_000),
                            "duration": aud.duration_us,
                            "enter_from": 0,
                            "extra_info": os.path.basename(s_path),
                            "file_Path": s_path,
                            "height": 0,
                            "id": str(uuid.uuid4()),
                            "import_time": int(now_us / 1_000_000),
                            "import_time_ms": now_us,
                            "item_source": 1,
                            "md5": "",
                            "metetype": "music",
                            "roughcut_time_range": {"duration": aud.duration_us, "start": 0},
                            "sub_time_range": {"duration": -1, "start": -1},
                            "type": 1,
                            "width": 0,
                        }
                        for aud, s_path in staged_audio
                    ]
                },
                {"type": 2, "value": []},
                {"type": 3, "value": []},
                {"type": 6, "value": []},
                {"type": 7, "value": []},
                {"type": 8, "value": []},
            ],
            "draft_name": project_name,
            "draft_root_path": draft_root_path,
            "draft_timeline_materials_size_": 1024,
            "tm_draft_create": now_us,
            "tm_draft_modified": now_us,
            "tm_duration": edit_plan.project.duration_us,
        }

        meta_path = os.path.join(target_dir, "draft_meta_info.json")
        with open(meta_path, "w", encoding="utf-8") as fp:
            json.dump(draft_meta_info, fp, indent=2, ensure_ascii=False)

        # Generate draft_cover.jpg thumbnail
        cover_path = os.path.join(target_dir, "draft_cover.jpg")
        cls._generate_cover(staged_clips[0][1] if staged_clips else None, cover_path)

        # Optional companion files
        cls._write_companion_files(target_dir)

        return {
            "draft_id": draft_id,
            "draft_dir": target_dir,
            "draft_info_file": info_path,
            "draft_meta_file": meta_path,
            "draft_cover_file": cover_path,
        }

    @classmethod
    def _build_keyframes(cls, clip: EditPlanClip) -> List[Dict[str, Any]]:
        """Generate common_keyframes structure for native CapCut transforms."""
        params = clip.keyframe_params
        motion = clip.motion_type
        duration_us = clip.duration_us

        common_kf: List[Dict[str, Any]] = []

        if motion in ["ZOOM_IN", "ZOOM_OUT"]:
            s_start = params.get("scale_start", 1.0)
            s_end = params.get("scale_end", 1.15)
            common_kf.append({
                "id": str(uuid.uuid4()).upper(),
                "material_id": "",
                "property_type": "KFTypeScaleX",
                "keyframe_list": [
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": 0,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(s_start)],
                        "string_value": "",
                        "graphID": "",
                    },
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": duration_us,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(s_end)],
                        "string_value": "",
                        "graphID": "",
                    },
                ],
            })
        elif motion in ["PAN_LEFT", "PAN_RIGHT"]:
            s_scale = params.get("scale_start", 1.08)
            px_start = params.get("pos_x_start", 0.10)
            px_end = params.get("pos_x_end", -0.10)

            # Keep slight scale to prevent black borders during pan
            common_kf.append({
                "id": str(uuid.uuid4()).upper(),
                "material_id": "",
                "property_type": "KFTypeScaleX",
                "keyframe_list": [
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": 0,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(s_scale)],
                        "string_value": "",
                        "graphID": "",
                    },
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": duration_us,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(s_scale)],
                        "string_value": "",
                        "graphID": "",
                    },
                ],
            })

            # Pan Position X
            common_kf.append({
                "id": str(uuid.uuid4()).upper(),
                "material_id": "",
                "property_type": "KFTypePositionX",
                "keyframe_list": [
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": 0,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(px_start)],
                        "string_value": "",
                        "graphID": "",
                    },
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": duration_us,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(px_end)],
                        "string_value": "",
                        "graphID": "",
                    },
                ],
            })
        elif motion in ["PAN_UP", "PAN_DOWN"]:
            s_scale = params.get("scale_start", 1.08)
            py_start = params.get("pos_y_start", -0.10)
            py_end = params.get("pos_y_end", 0.10)

            common_kf.append({
                "id": str(uuid.uuid4()).upper(),
                "material_id": "",
                "property_type": "KFTypeScaleX",
                "keyframe_list": [
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": 0,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(s_scale)],
                        "string_value": "",
                        "graphID": "",
                    },
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": duration_us,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(s_scale)],
                        "string_value": "",
                        "graphID": "",
                    },
                ],
            })

            common_kf.append({
                "id": str(uuid.uuid4()).upper(),
                "material_id": "",
                "property_type": "KFTypePositionY",
                "keyframe_list": [
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": 0,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(py_start)],
                        "string_value": "",
                        "graphID": "",
                    },
                    {
                        "id": str(uuid.uuid4()).upper(),
                        "curveType": "Line",
                        "time_offset": duration_us,
                        "left_control": {"x": 0.0, "y": 0.0},
                        "right_control": {"x": 0.0, "y": 0.0},
                        "values": [float(py_end)],
                        "string_value": "",
                        "graphID": "",
                    },
                ],
            })

        return common_kf


    @classmethod
    def _generate_cover(cls, source_image: Optional[str], output_path: str):
        """Generate a 360x640 thumbnail JPEG."""
        if source_image and os.path.exists(source_image):
            try:
                with Image.open(source_image) as img:
                    img = img.convert("RGB")
                    img.thumbnail((360, 640))
                    img.save(output_path, "JPEG", quality=85)
                    return
            except Exception:
                pass

        # Fallback colored thumbnail if source image is not readable
        img = Image.new("RGB", (360, 640), color=(30, 30, 35))
        img.save(output_path, "JPEG")

    @classmethod
    def _write_companion_files(cls, target_dir: str):
        """Write auxiliary configuration files observed in CapCut drafts."""
        attach_path = os.path.join(target_dir, "attachment_editing.json")
        if not os.path.exists(attach_path):
            with open(attach_path, "w", encoding="utf-8") as f:
                json.dump({"edit_from": "home", "draft_extra_info": {}}, f)

        kv_path = os.path.join(target_dir, "key_value.json")
        if not os.path.exists(kv_path):
            with open(kv_path, "w", encoding="utf-8") as f:
                json.dump({"config": {}}, f)
