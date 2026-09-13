"""
apps/capcut-v2/adapters/capcut/validator.py
Strict validator for CapCut project drafts.
Verifies structural schema, UUID uniqueness, material references,
timeline non-overlap, keyframe monotonicity, and file presence.
"""
from __future__ import annotations

import os
import json
import math
from typing import List, Dict, Any, Set
from PIL import Image


class CapCutDraftValidator:
    """
    Validates structural correctness and logical consistency of CapCut drafts.
    """

    REQUIRED_TOP_LEVEL_KEYS = [
        "id",
        "version",
        "fps",
        "duration",
        "canvas_config",
        "tracks",
        "materials",
    ]

    REQUIRED_META_KEYS = [
        "draft_id",
        "draft_name",
        "draft_fold_path",
        "tm_duration",
    ]

    @classmethod
    def validate_draft(
        cls,
        draft_dir: str,
        verify_file_dimensions: bool = False,
        cross_fade_enabled: Optional[bool] = None,
    ) -> List[str]:
        """
        Validate a draft folder on disk. Returns list of error strings (empty if valid).
        """
        errors: List[str] = []

        if not os.path.isdir(draft_dir):
            return [f"Draft directory does not exist: {draft_dir}"]

        info_file = os.path.join(draft_dir, "draft_info.json")
        meta_file = os.path.join(draft_dir, "draft_meta_info.json")
        cover_file = os.path.join(draft_dir, "draft_cover.jpg")

        # 1. File existence
        if not os.path.isfile(info_file):
            errors.append("Missing 'draft_info.json'")
        if not os.path.isfile(meta_file):
            errors.append("Missing 'draft_meta_info.json'")
        if not os.path.isfile(cover_file):
            errors.append("Missing 'draft_cover.jpg'")

        if errors:
            return errors

        # 2. JSON parse check
        info_data: Dict[str, Any] = {}
        meta_data: Dict[str, Any] = {}

        try:
            with open(info_file, "r", encoding="utf-8") as f:
                info_data = json.load(f)
        except Exception as e:
            errors.append(f"draft_info.json is corrupted or invalid JSON: {e}")

        try:
            with open(meta_file, "r", encoding="utf-8") as f:
                meta_data = json.load(f)
        except Exception as e:
            errors.append(f"draft_meta_info.json is corrupted or invalid JSON: {e}")

        if errors:
            return errors

        # 3. Top-level key validation
        for k in cls.REQUIRED_TOP_LEVEL_KEYS:
            if k not in info_data:
                errors.append(f"draft_info.json missing required key: '{k}'")

        for k in cls.REQUIRED_META_KEYS:
            if k not in meta_data:
                errors.append(f"draft_meta_info.json missing required key: '{k}'")

        if errors:
            return errors

        # 3b. Canvas Config Validation
        canvas_cfg = info_data.get("canvas_config", {})
        if not isinstance(canvas_cfg, dict):
            errors.append("draft_info.json 'canvas_config' must be a dictionary")
        else:
            cw = canvas_cfg.get("width")
            ch = canvas_cfg.get("height")
            if not isinstance(cw, (int, float)) or cw <= 0 or math.isnan(cw) or math.isinf(cw):
                errors.append(f"canvas_config.width must be a positive finite number, got: {cw}")
            if not isinstance(ch, (int, float)) or ch <= 0 or math.isnan(ch) or math.isinf(ch):
                errors.append(f"canvas_config.height must be a positive finite number, got: {ch}")

        # 4. UUID Uniqueness & Material Indexing
        seen_uuids: Set[str] = set()
        material_ids_by_type: Dict[str, Set[str]] = {}
        all_material_ids: Set[str] = set()

        materials_dict = info_data.get("materials", {})
        if not isinstance(materials_dict, dict):
            errors.append("materials must be a dictionary")
            return errors

        for mat_type, mat_list in materials_dict.items():
            material_ids_by_type[mat_type] = set()
            if not isinstance(mat_list, list):
                continue
            for item in mat_list:
                if isinstance(item, dict) and "id" in item:
                    m_id = str(item["id"])
                    if m_id in seen_uuids:
                        errors.append(f"Duplicate UUID detected in materials: {m_id}")
                    seen_uuids.add(m_id)
                    all_material_ids.add(m_id)
                    material_ids_by_type[mat_type].add(m_id)

                    # Check media file existence and dimension integrity for videos
                    if mat_type == "videos":
                        m_path = item.get("path", "")
                        width = item.get("width", 0)
                        height = item.get("height", 0)
                        if not isinstance(width, (int, float)) or width <= 0 or math.isnan(width) or math.isinf(width):
                            errors.append(f"Material {m_id} in 'videos' has invalid width: {width}")
                        if not isinstance(height, (int, float)) or height <= 0 or math.isnan(height) or math.isinf(height):
                            errors.append(f"Material {m_id} in 'videos' has invalid height: {height}")
                        if not m_path:
                            errors.append(f"Material {m_id} in 'videos' has empty 'path'")
                        elif not os.path.exists(m_path):
                            errors.append(f"Media file missing on disk for {m_id}: {m_path}")
                        elif verify_file_dimensions:
                            # If media file exists and is an image, verify dimensions match
                            try:
                                with Image.open(m_path) as im:
                                    if (im.size[0], im.size[1]) != (width, height):
                                        errors.append(
                                            f"Material {m_id} dimension mismatch: material declared {width}x{height}, but file is {im.size[0]}x{im.size[1]}"
                                        )
                            except Exception:
                                pass

                        # Check crop values are finite and within expected [0, 1] domain
                        crop_dict = item.get("crop")
                        if isinstance(crop_dict, dict):
                            for ck in [
                                "upper_left_x", "upper_left_y", "upper_right_x", "upper_right_y",
                                "lower_left_x", "lower_left_y", "lower_right_x", "lower_right_y",
                            ]:
                                cv = crop_dict.get(ck)
                                if cv is not None:
                                    if not isinstance(cv, (int, float)) or math.isnan(cv) or math.isinf(cv) or not (0.0 <= cv <= 1.0):
                                        errors.append(f"Material {m_id} crop.{ck} invalid or out of domain [0, 1]: {cv}")


                    if mat_type == "audios":
                        m_path = item.get("path", "")
                        if not m_path:
                            errors.append(f"Material {m_id} in 'audios' has empty 'path'")
                        elif not os.path.exists(m_path):
                            errors.append(f"Media file missing on disk for {m_id}: {m_path}")

                    # Check text content validity
                    if mat_type == "texts":
                        raw_content = item.get("content", "")
                        try:
                            t_json = json.loads(raw_content)
                            if "text" not in t_json:
                                errors.append(f"Text material {m_id} missing 'text' field in content JSON")
                        except Exception as e:
                            errors.append(f"Text material {m_id} has invalid content JSON: {e}")

        # 4b. Meta Material Consistency Validation
        draft_materials = meta_data.get("draft_materials", [])
        if isinstance(draft_materials, list):
            for group in draft_materials:
                if not isinstance(group, dict):
                    continue
                for item in group.get("value", []):
                    if not isinstance(item, dict):
                        continue
                    m_type = item.get("metetype", "")
                    m_width = item.get("width", 0)
                    m_height = item.get("height", 0)
                    m_path = item.get("file_Path", "")

                    if m_type == "photo":
                        if m_width <= 0 or m_height <= 0:
                            errors.append(
                                f"draft_meta_info photo material {item.get('id')} has non-positive dimensions: {m_width}x{m_height}"
                            )

                        rc = item.get("roughcut_time_range")
                        if rc and isinstance(rc, dict):
                            if rc.get("duration", -1) != -1 or rc.get("start", -1) != -1:
                                errors.append(
                                    f"draft_meta_info photo material {item.get('id')} has invalid roughcut_time_range: {rc} (must be -1, -1 for photo)"
                                )

                        # Match with draft_info materials['videos']
                        if m_path:
                            base_name = os.path.basename(m_path)
                            matching_vids = [
                                v for v in materials_dict.get("videos", [])
                                if os.path.basename(v.get("path", "")) == base_name
                            ]
                            if matching_vids:
                                v_mat = matching_vids[0]
                                if (v_mat.get("width"), v_mat.get("height")) != (m_width, m_height):
                                    errors.append(
                                        f"Metadata dimension mismatch for {base_name}: draft_meta_info has {m_width}x{m_height}, draft_info has {v_mat.get('width')}x{v_mat.get('height')}"
                                    )

        # 5. Track & Segment Validation
        tracks = info_data.get("tracks", [])
        if not tracks or not isinstance(tracks, list):
            errors.append("tracks must be a non-empty list")
            return errors

        max_clip_end_us = 0

        for t_idx, track in enumerate(tracks):
            track_id = track.get("id")
            if track_id:
                if track_id in seen_uuids:
                    errors.append(f"Duplicate track UUID: {track_id}")
                seen_uuids.add(track_id)

            t_type = track.get("type", "")
            segments = track.get("segments", [])

            # Check segments non-overlap
            sorted_segs = []
            for s_idx, seg in enumerate(segments):
                seg_id = seg.get("id")
                if seg_id:
                    if seg_id in seen_uuids:
                        errors.append(f"Duplicate segment UUID: {seg_id}")
                    seen_uuids.add(seg_id)

                target_range = seg.get("target_timerange", {})
                if not target_range:
                    errors.append(f"Segment {seg_id} in track {t_idx} missing target_timerange")
                    continue

                start = target_range.get("start", -1)
                duration = target_range.get("duration", 0)

                if start < 0:
                    errors.append(f"Segment {seg_id} has negative start time: {start}")
                if duration <= 0:
                    errors.append(f"Segment {seg_id} has invalid duration: {duration}")

                seg_end = start + duration
                if seg_end > max_clip_end_us:
                    max_clip_end_us = seg_end

                sorted_segs.append((start, seg_end, seg_id))

                # Validate material_id reference
                mat_id = seg.get("material_id")
                if t_type == "video":
                    if mat_id not in material_ids_by_type.get("videos", set()):
                        errors.append(f"Video segment {seg_id} references missing video material: {mat_id}")
                    # Transform, scale, position, and intersection validation
                    clip_obj = seg.get("clip")
                    if not isinstance(clip_obj, dict):
                        errors.append(f"Video segment {seg_id} missing or invalid 'clip' object")
                    else:
                        scale = clip_obj.get("scale", {})
                        if isinstance(scale, dict):
                            sx = scale.get("x")
                            sy = scale.get("y")
                            if not isinstance(sx, (int, float)) or math.isnan(sx) or math.isinf(sx) or sx <= 0:
                                errors.append(f"Video segment {seg_id} has invalid scale.x: {sx}")
                            if not isinstance(sy, (int, float)) or math.isnan(sy) or math.isinf(sy) or sy <= 0:
                                errors.append(f"Video segment {seg_id} has invalid scale.y: {sy}")

                        tf = clip_obj.get("transform", {})
                        if isinstance(tf, dict):
                            tx = tf.get("x")
                            ty = tf.get("y")
                            if not isinstance(tx, (int, float)) or math.isnan(tx) or math.isinf(tx):
                                errors.append(f"Video segment {seg_id} has invalid transform.x: {tx}")
                            if not isinstance(ty, (int, float)) or math.isnan(ty) or math.isinf(ty):
                                errors.append(f"Video segment {seg_id} has invalid transform.y: {ty}")

                            # Segment must remain intersecting visible canvas (normalized bounds: |tx| <= 2.0, |ty| <= 2.0)
                            if isinstance(tx, (int, float)) and abs(tx) > 2.0:
                                errors.append(f"Video segment {seg_id} transform.x ({tx}) places media completely off-canvas")
                            if isinstance(ty, (int, float)) and abs(ty) > 2.0:
                                errors.append(f"Video segment {seg_id} transform.y ({ty}) places media completely off-canvas")
                elif t_type == "audio":
                    if mat_id not in material_ids_by_type.get("audios", set()):
                        errors.append(f"Audio segment {seg_id} references missing audio material: {mat_id}")
                elif t_type == "text":
                    if mat_id not in material_ids_by_type.get("texts", set()):
                        errors.append(f"Text segment {seg_id} references missing text material: {mat_id}")

                # Validate extra_material_refs
                extra_refs = seg.get("extra_material_refs", [])
                for ref_id in extra_refs:
                    if ref_id not in all_material_ids:
                        errors.append(f"Segment {seg_id} extra_material_ref not found in materials: {ref_id}")

                # Validate keyframes monotonicity
                for kf_group in seg.get("common_keyframes", []):
                    kf_prop = kf_group.get("property_type")
                    kf_list = kf_group.get("keyframe_list", [])
                    prev_offset = -1
                    for kf in kf_list:
                        kf_offset = kf.get("time_offset", 0)
                        if kf_offset < prev_offset:
                            errors.append(
                                f"Segment {seg_id} keyframe offsets not monotonic for {kf_prop}: {prev_offset} > {kf_offset}"
                            )
                        if kf_offset > duration:
                            errors.append(
                                f"Segment {seg_id} keyframe offset exceeds segment duration ({kf_offset} > {duration})"
                            )
                        prev_offset = kf_offset

            # Non-overlap check for main video track
            if t_type == "video" and len(sorted_segs) > 1:
                sorted_segs.sort(key=lambda x: x[0])
                for i in range(len(sorted_segs) - 1):
                    c_start, c_end, c_id = sorted_segs[i]
                    n_start, n_end, n_id = sorted_segs[i + 1]
                    if c_end > n_start:
                        errors.append(f"Track {t_idx} clips overlap between {c_id} (ends {c_end}) and {n_id} (starts {n_start})")

        # 6. Overall duration check
        proj_duration = info_data.get("duration", 0)
        if proj_duration < max_clip_end_us:
            errors.append(
                f"Project duration mismatch: draft_info duration is {proj_duration} us, but timeline reaches {max_clip_end_us} us"
            )

        # 7. Transition Validation (Cross Fade contract)
        transitions = materials_dict.get("transitions", [])
        video_track = next((t for t in tracks if t.get("type") == "video"), None)
        video_segments = video_track.get("segments", []) if video_track else []
        num_v_segs = len(video_segments)

        # Build mapping of transition refs in video segments
        trans_refs_in_segments: Dict[str, int] = {}
        for s_idx, seg in enumerate(video_segments):
            for ref in seg.get("extra_material_refs", []):
                trans_refs_in_segments[ref] = s_idx

        for t_idx, trans in enumerate(transitions):
            if not isinstance(trans, dict):
                errors.append(f"Transition at index {t_idx} is not a dictionary")
                continue
            t_id = trans.get("id")
            if not t_id:
                errors.append(f"Transition at index {t_idx} missing 'id'")
            if trans.get("type") != "transition":
                errors.append(f"Transition {t_id} 'type' must be 'transition', got: {trans.get('type')}")

            # Verify native Cross Fade attributes
            res_id = str(trans.get("resource_id", ""))
            eff_id = str(trans.get("effect_id", ""))
            if res_id != "7657476671573937428":
                errors.append(f"Transition {t_id} resource_id must be '7657476671573937428', got: {res_id}")
            if eff_id != "7657476671573937428":
                errors.append(f"Transition {t_id} effect_id must be '7657476671573937428', got: {eff_id}")
            if trans.get("is_overlap") is not True:
                errors.append(f"Transition {t_id} is_overlap must be True")

            # Duration check (500,000 us ± 50,000 us quantization tolerance)
            t_dur = trans.get("duration", 0)
            if not isinstance(t_dur, (int, float)) or not (450_000 <= t_dur <= 550_000):
                errors.append(f"Transition {t_id} duration {t_dur}us out of expected tolerance [450000, 550000]")

            # Linkage check: Must be linked to an outgoing segment
            if t_id:
                if t_id not in trans_refs_in_segments:
                    errors.append(f"Transition {t_id} not referenced in any video segment extra_material_refs")
                else:
                    owning_seg_idx = trans_refs_in_segments[t_id]
                    if owning_seg_idx >= num_v_segs - 1:
                        errors.append(
                            f"Transition {t_id} incorrectly attached to final visual segment {owning_seg_idx} (must be outgoing to segment {owning_seg_idx + 1})"
                        )

        # Enforce strict count contract when cross_fade_enabled is explicitly provided
        if cross_fade_enabled is not None:
            expected_count = max(0, num_v_segs - 1) if cross_fade_enabled else 0
            if len(transitions) != expected_count:
                errors.append(
                    f"Transition count mismatch: cross_fade_enabled={cross_fade_enabled} expects {expected_count} transitions for {num_v_segs} clips, found {len(transitions)}"
                )
            if cross_fade_enabled and num_v_segs > 1:
                # Assert 1-to-1 sequential linkage: segment i has transition i for i in [0, num_v_segs - 2]
                for i in range(num_v_segs - 1):
                    seg_refs = set(video_segments[i].get("extra_material_refs", []))
                    matching = [t["id"] for t in transitions if t.get("id") in seg_refs]
                    if len(matching) != 1:
                        errors.append(
                            f"Video segment {i} expected exactly 1 outgoing transition, found {len(matching)}"
                        )
                # Final segment must have 0 transitions
                final_refs = set(video_segments[-1].get("extra_material_refs", []))
                final_trans = [t["id"] for t in transitions if t.get("id") in final_refs]
                if final_trans:
                    errors.append(
                        f"Final video segment {num_v_segs - 1} must not have outgoing transitions, found: {final_trans}"
                    )

        return errors
