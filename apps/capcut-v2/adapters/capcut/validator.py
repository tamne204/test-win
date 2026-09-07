"""
apps/capcut-v2/adapters/capcut/validator.py
Strict validator for CapCut project drafts.
Verifies structural schema, UUID uniqueness, material references,
timeline non-overlap, keyframe monotonicity, and file presence.
"""
from __future__ import annotations

import os
import json
from typing import List, Dict, Any, Set


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
    def validate_draft(cls, draft_dir: str) -> List[str]:
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

                    # Check media file existence for videos and audios
                    if mat_type in ["videos", "audios"]:
                        m_path = item.get("path", "")
                        if not m_path:
                            errors.append(f"Material {m_id} in '{mat_type}' has empty 'path'")
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

        return errors
