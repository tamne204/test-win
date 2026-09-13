"""
apps/capcut-v2/core/dev_cache/cache_keys.py
Content-addressed cache key generators and dependency hashing models
for AutoEdit V2 development acceleration.
"""
from __future__ import annotations

import hashlib
import json
import os
from enum import Enum
from pathlib import Path
from typing import Any, Dict, List, Optional, Union

# Frozen / Default Engine Versions
DEFAULT_ASR_ENGINE_VERSION = "faster_whisper_v1"
DEFAULT_ALIGNMENT_ENGINE_VERSION = "hierarchical_v1"
DEFAULT_SUBTITLE_SEGMENTER_VERSION = "a0_subtitle_v1"
DEFAULT_VISUAL_PLANNER_VERSION = "visual_shot_v1"
DEFAULT_MOTION_ENGINE_VERSION = "ken_burns_v2"
DEFAULT_CAPCUT_ADAPTER_VERSION = "capcut_adapter_9_3_v2"

# Phase A1 Visual Engine Versions
VISUAL_PLANNER_ENGINE_VERSION = "hierarchical_dp_v1"
MOTION_POLICY_VERSION = "duration_aware_velocity_v1"
VALIDATOR_VERSION = "visual_accuracy_validator_v1"


class CacheStage(str, Enum):
    """Stages in the AutoEdit V2 generation pipeline."""
    ASR = "asr"
    ALIGNMENT = "alignment"
    SUBTITLE = "subtitle"
    VISUAL_SHOT = "visual_shot"
    EDITPLAN = "editplan"
    DRAFT = "draft"


def compute_content_hash(data: Union[str, bytes, dict, list, Path, None]) -> str:
    """
    Compute a deterministic SHA-256 hash for various data types.
    - Files / Paths: streamed SHA-256
    - str: utf-8 encoded SHA-256
    - bytes: direct SHA-256
    - dict / list: canonical sorted JSON SHA-256
    """
    if data is None:
        return hashlib.sha256(b"null").hexdigest()

    h = hashlib.sha256()

    if isinstance(data, (Path, str)) and os.path.exists(str(data)) and os.path.isfile(str(data)):
        # Stream file in 64KB blocks
        with open(str(data), "rb") as f:
            while chunk := f.read(65536):
                h.update(chunk)
        return h.hexdigest()

    if isinstance(data, str):
        h.update(data.encode("utf-8"))
        return h.hexdigest()

    if isinstance(data, bytes):
        h.update(data)
        return h.hexdigest()

    if isinstance(data, (dict, list)):
        canonical_json = json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
        h.update(canonical_json.encode("utf-8"))
        return h.hexdigest()

    # Fallback representation
    h.update(repr(data).encode("utf-8"))
    return h.hexdigest()


def _combine_hashes(stage: CacheStage, components: Dict[str, Any]) -> str:
    """
    Combine component hashes deterministically into a single stage cache key.
    """
    canonical_payload = {
        "stage": stage.value,
        "components": {k: str(v) for k, v in sorted(components.items())}
    }
    return compute_content_hash(canonical_payload)


def build_asr_key(
    audio_hash: str,
    engine_version: str = DEFAULT_ASR_ENGINE_VERSION,
    options: Optional[Dict[str, Any]] = None
) -> str:
    """AUDIO_HASH + ASR_ENGINE_VERSION + ASR_OPTIONS -> ASR_KEY"""
    return _combine_hashes(
        CacheStage.ASR,
        {
            "audio_hash": audio_hash,
            "engine_version": engine_version,
            "options_hash": compute_content_hash(options or {})
        }
    )


def build_alignment_key(
    script_hash: str,
    asr_artifact_hash: str,
    engine_version: str = DEFAULT_ALIGNMENT_ENGINE_VERSION,
    options: Optional[Dict[str, Any]] = None
) -> str:
    """SCRIPT_HASH + ASR_ARTIFACT_HASH + ALIGNMENT_ENGINE_VERSION -> ALIGNMENT_KEY"""
    return _combine_hashes(
        CacheStage.ALIGNMENT,
        {
            "script_hash": script_hash,
            "asr_artifact_hash": asr_artifact_hash,
            "engine_version": engine_version,
            "options_hash": compute_content_hash(options or {})
        }
    )


def build_subtitle_key(
    alignment_artifact_hash: str,
    subtitle_options: Optional[Dict[str, Any]] = None,
    segmenter_version: str = DEFAULT_SUBTITLE_SEGMENTER_VERSION
) -> str:
    """ALIGNMENT_HASH + SUBTITLE_OPTIONS_HASH -> SUBTITLE_KEY"""
    return _combine_hashes(
        CacheStage.SUBTITLE,
        {
            "alignment_artifact_hash": alignment_artifact_hash,
            "subtitle_options_hash": compute_content_hash(subtitle_options or {}),
            "segmenter_version": segmenter_version
        }
    )


def build_visual_shot_key(
    subtitle_artifact_hash: str,
    image_manifest_hash: str,
    planner_version: str = DEFAULT_VISUAL_PLANNER_VERSION,
    visual_settings: Optional[Dict[str, Any]] = None,
    validator_version: str = VALIDATOR_VERSION,
) -> str:
    """SUBTITLE_HASH + IMAGE_MANIFEST_HASH + VISUAL_PLANNER_VERSION + VALIDATOR_VERSION + VISUAL_SETTINGS_HASH -> VISUAL_SHOT_KEY"""
    return _combine_hashes(
        CacheStage.VISUAL_SHOT,
        {
            "subtitle_artifact_hash": subtitle_artifact_hash,
            "image_manifest_hash": image_manifest_hash,
            "planner_version": planner_version,
            "validator_version": validator_version,
            "visual_settings_hash": compute_content_hash(visual_settings or {})
        }
    )


def build_editplan_key(
    visual_shot_artifact_hash: str,
    motion_settings: Optional[Dict[str, Any]] = None,
    motion_engine_version: str = MOTION_POLICY_VERSION,
) -> str:
    """VISUAL_SHOT_HASH + MOTION_SETTINGS_HASH -> EDITPLAN_KEY"""
    return _combine_hashes(
        CacheStage.EDITPLAN,
        {
            "visual_shot_artifact_hash": visual_shot_artifact_hash,
            "motion_settings_hash": compute_content_hash(motion_settings or {}),
            "motion_engine_version": motion_engine_version
        }
    )


def build_draft_key(
    editplan_artifact_hash: str,
    adapter_version: str = DEFAULT_CAPCUT_ADAPTER_VERSION,
    adapter_settings: Optional[Dict[str, Any]] = None
) -> str:
    """EDITPLAN_HASH + CAPCUT_ADAPTER_VERSION -> DRAFT_KEY"""
    return _combine_hashes(
        CacheStage.DRAFT,
        {
            "editplan_artifact_hash": editplan_artifact_hash,
            "adapter_version": adapter_version,
            "adapter_settings_hash": compute_content_hash(adapter_settings or {})
        }
    )
