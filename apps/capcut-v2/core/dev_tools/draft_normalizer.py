"""
apps/capcut-v2/core/dev_tools/draft_normalizer.py
Developer-only Draft Normalizer for deterministic CapCut Draft snapshot testing.
Strips volatile UUIDs, timestamps, and machine-specific file paths while strictly
preserving track structure, clip order, timeranges, keyframes, transforms, and subtitles.
"""
from __future__ import annotations

import copy
import difflib
import json
import os
import re
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple, Union


class DraftNormalizer:
    """
    Normalizes CapCut draft_info.json and draft_meta_info.json into canonical form.
    """

    @classmethod
    def to_canonical_json(cls, data: Any) -> str:
        """Serialize data to deterministic, canonical formatted JSON."""
        return json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + "\n"

    @classmethod
    def sanitize_path(cls, path_str: str) -> str:
        """Convert absolute / machine-specific paths to deterministic virtual paths."""
        if not path_str or not isinstance(path_str, str):
            return ""
        # Normalize backslashes
        p = path_str.replace("\\", "/")
        # Extract filename / relative basename
        basename = os.path.basename(p)
        if "/media/" in p or "media/" in p:
            return f"media/{basename}"
        return f"media/{basename}"

    @classmethod
    def normalize_draft_info(cls, draft_info: Dict[str, Any]) -> Dict[str, Any]:
        """
        Normalize draft_info dictionary:
        - Replaces volatile UUIDs with deterministic structural IDs.
        - Zeroes out non-deterministic timestamps.
        - Sanitizes absolute paths in materials.
        - Preserves semantic keys: canvas, fps, duration, tracks, segments, keyframes, materials.
        """
        d = copy.deepcopy(draft_info)

        # 1. Volatile Root fields
        d["id"] = "NORMALIZED_DRAFT_ID"
        if "create_time" in d:
            d["create_time"] = 0
        if "update_time" in d:
            d["update_time"] = 0

        # Platform version / details normalization if present
        if "platform" in d and isinstance(d["platform"], dict):
            d["platform"]["os"] = "NORMALIZED_OS"
            d["platform"]["os_version"] = "NORMALIZED_OS_VERSION"
        if "last_modified_platform" in d and isinstance(d["last_modified_platform"], dict):
            d["last_modified_platform"]["device_id"] = "NORMALIZED_DEVICE"
            d["last_modified_platform"]["hard_disk_id"] = "NORMALIZED_DISK"
            d["last_modified_platform"]["mac_address"] = "NORMALIZED_MAC"
            d["last_modified_platform"]["os"] = "NORMALIZED_OS"
            d["last_modified_platform"]["os_version"] = "NORMALIZED_OS_VERSION"

        # 2. Build ID mapping table for materials and tracks
        id_map: Dict[str, str] = {}

        # Scan materials to assign deterministic IDs
        materials = d.get("materials", {})
        for mat_category in sorted(materials.keys()):
            cat_list = materials[mat_category]
            if isinstance(cat_list, list):
                for idx, item in enumerate(cat_list):
                    if isinstance(item, dict) and "id" in item:
                        old_id = item["id"]
                        new_id = f"MAT_{mat_category.upper()}_{idx:04d}"
                        id_map[old_id] = new_id
                        item["id"] = new_id

                        # Sanitize media paths
                        if "path" in item:
                            item["path"] = cls.sanitize_path(item["path"])
                        if "media_path" in item and item["media_path"]:
                            item["media_path"] = cls.sanitize_path(item["media_path"])

        # 3. Normalize Tracks & Segments
        tracks = d.get("tracks", [])
        for t_idx, track in enumerate(tracks):
            t_type = track.get("type", "unknown").upper()
            track["id"] = f"TRACK_{t_type}_{t_idx:02d}"

            segments = track.get("segments", [])
            for s_idx, seg in enumerate(segments):
                seg["id"] = f"SEG_{t_type}_{s_idx:04d}"

                # Remap material_id
                old_mat_id = seg.get("material_id", "")
                if old_mat_id in id_map:
                    seg["material_id"] = id_map[old_mat_id]

                # Remap extra_material_refs
                if "extra_material_refs" in seg and isinstance(seg["extra_material_refs"], list):
                    seg["extra_material_refs"] = [
                        id_map.get(ref, ref) for ref in seg["extra_material_refs"]
                    ]

                # Normalize keyframes
                common_kf = seg.get("common_keyframes", [])
                for kf_idx, kf in enumerate(common_kf):
                    prop = kf.get("property_type", "KF").upper()
                    kf["id"] = f"KF_{prop}_{kf_idx:03d}"
                    if kf.get("material_id"):
                        kf["material_id"] = id_map.get(kf["material_id"], kf["material_id"])

                    kf_list = kf.get("keyframe_list", [])
                    for pt_idx, pt in enumerate(kf_list):
                        pt["id"] = f"KFP_{prop}_{pt_idx:03d}"

        return d

    @classmethod
    def normalize_draft_meta_info(cls, meta_info: Dict[str, Any]) -> Dict[str, Any]:
        """Normalize draft_meta_info dictionary."""
        m = copy.deepcopy(meta_info)

        m["draft_id"] = "NORMALIZED_DRAFT_ID"
        m["draft_fold_path"] = "[NORMALIZED_DRAFT_PATH]"
        if "draft_root_path" in m:
            m["draft_root_path"] = "[NORMALIZED_ROOT_PATH]"
        if "draft_cover" in m:
            m["draft_cover"] = "[NORMALIZED_COVER]"

        for t_field in ("tm_draft_create", "tm_draft_modified", "tm_duration"):
            if t_field in m:
                m[t_field] = 0

        # Normalize draft_materials
        materials = m.get("draft_materials", [])
        for mat_group in materials:
            val_list = mat_group.get("value", [])
            if isinstance(val_list, list):
                for idx, v in enumerate(val_list):
                    if isinstance(v, dict):
                        if "file_Path" in v:
                            v["file_Path"] = cls.sanitize_path(v["file_Path"])
                        if "id" in v:
                            v["id"] = f"META_MAT_{idx:04d}"

        return m

    @classmethod
    def normalize_draft_directory(
        cls, draft_dir: Union[str, Path]
    ) -> Tuple[Dict[str, Any], Optional[Dict[str, Any]]]:
        """Load and normalize both draft_info.json and draft_meta_info.json from a folder."""
        d_dir = Path(draft_dir)
        info_file = d_dir / "draft_info.json"
        meta_file = d_dir / "draft_meta_info.json"

        if not info_file.is_file():
            raise FileNotFoundError(f"draft_info.json not found in {draft_dir}")

        with open(info_file, "r", encoding="utf-8") as f:
            raw_info = json.load(f)
        norm_info = cls.normalize_draft_info(raw_info)

        norm_meta = None
        if meta_file.is_file():
            with open(meta_file, "r", encoding="utf-8") as f:
                raw_meta = json.load(f)
            norm_meta = cls.normalize_draft_meta_info(raw_meta)

        return norm_info, norm_meta

    @classmethod
    def compare_normalized(
        cls,
        actual: Dict[str, Any],
        expected: Dict[str, Any]
    ) -> List[str]:
        """Compare two normalized objects, returning unified diff lines if differences exist."""
        actual_str = cls.to_canonical_json(actual)
        expected_str = cls.to_canonical_json(expected)

        if actual_str == expected_str:
            return []

        diff = list(
            difflib.unified_diff(
                expected_str.splitlines(keepends=True),
                actual_str.splitlines(keepends=True),
                fromfile="expected_golden.json",
                tofile="actual_normalized.json",
            )
        )
        return diff


def assert_draft_matches_golden(
    actual_draft_dir: Union[str, Path],
    golden_file_path: Union[str, Path],
    update_golden: bool = False
) -> None:
    """
    Assert that the generated draft matches the expected golden snapshot.
    If update_golden is True, writes the canonical normalized JSON to golden_file_path.
    """
    norm_info, _ = DraftNormalizer.normalize_draft_directory(actual_draft_dir)
    canonical_json = DraftNormalizer.to_canonical_json(norm_info)

    golden_path = Path(golden_file_path)

    if update_golden:
        golden_path.parent.mkdir(parents=True, exist_ok=True)
        with open(golden_path, "w", encoding="utf-8") as f:
            f.write(canonical_json)
        print(f"Updated golden draft snapshot: {golden_path}")
        return

    if not golden_path.is_file():
        raise FileNotFoundError(
            f"Golden snapshot does not exist: {golden_path}. "
            "Run with update_golden=True to establish baseline."
        )

    with open(golden_path, "r", encoding="utf-8") as f:
        expected = json.load(f)

    diff = DraftNormalizer.compare_normalized(norm_info, expected)
    if diff:
        diff_text = "".join(diff[:50])
        raise AssertionError(f"Draft does not match golden snapshot! Diff:\n{diff_text}")
