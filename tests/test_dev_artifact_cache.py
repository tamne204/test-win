"""
tests/test_dev_artifact_cache.py
Comprehensive unit tests for the content-addressed developer artifact cache.
"""
import json
import os
import sys
import tempfile
import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.dev_cache import (
    CacheStage,
    ArtifactCache,
    compute_content_hash,
    build_asr_key,
    build_alignment_key,
    build_subtitle_key,
    build_visual_shot_key,
    build_editplan_key,
    build_draft_key,
)


def test_compute_content_hash_determinism(tmp_path):
    # Dict key order independence
    d1 = {"a": 1, "b": [1, 2, 3], "c": {"x": True}}
    d2 = {"c": {"x": True}, "a": 1, "b": [1, 2, 3]}
    assert compute_content_hash(d1) == compute_content_hash(d2)

    # List order sensitivity
    l1 = [1, 2, 3]
    l2 = [3, 2, 1]
    assert compute_content_hash(l1) != compute_content_hash(l2)

    # File hashing matches content
    test_file = tmp_path / "sample.txt"
    test_file.write_text("Hello AutoEdit V2", encoding="utf-8")
    assert compute_content_hash(test_file) == compute_content_hash("Hello AutoEdit V2")


def test_dependency_chain_invalidation():
    # Audio -> ASR
    audio_hash_v1 = "audio_hash_aaa"
    audio_hash_v2 = "audio_hash_bbb"
    asr_key_v1 = build_asr_key(audio_hash_v1)
    asr_key_v2 = build_asr_key(audio_hash_v2)
    assert asr_key_v1 != asr_key_v2

    # ASR -> Alignment
    script_hash = "script_hash_sss"
    align_key_1 = build_alignment_key(script_hash, asr_key_v1)
    align_key_2 = build_alignment_key(script_hash, asr_key_v2)
    assert align_key_1 != align_key_2

    # VisualShot change doesn't invalidate upstream ASR or Alignment
    sub_hash = "sub_hash_111"
    img_hash_1 = "img_manifest_1"
    img_hash_2 = "img_manifest_2"
    vs_key_1 = build_visual_shot_key(sub_hash, img_hash_1)
    vs_key_2 = build_visual_shot_key(sub_hash, img_hash_2)
    assert vs_key_1 != vs_key_2
    # Upstream keys remained completely unchanged!


def test_artifact_cache_lifecycle(tmp_path):
    cache = ArtifactCache(cache_dir=tmp_path / ".dev_cache")

    key = "test_key_12345"
    payload = {"words": [{"word": "xin", "start": 0.0, "end": 0.2}]}

    # Initially missing
    assert not cache.has(CacheStage.ASR, key)
    assert cache.get(CacheStage.ASR, key) is None
    assert cache.stats["misses"] == 1

    # Put
    p_hash = cache.put(CacheStage.ASR, key, payload, metadata={"project_id": "proj_1"})
    assert isinstance(p_hash, str) and len(p_hash) == 64
    assert cache.has(CacheStage.ASR, key)
    assert cache.stats["writes"] == 1

    # Get
    data = cache.get(CacheStage.ASR, key)
    assert data == payload
    assert cache.stats["hits"] == 1


def test_cache_corruption_resilience(tmp_path):
    cache = ArtifactCache(cache_dir=tmp_path / ".dev_cache")
    key = "corrupt_key_abc"
    payload = {"status": "ok"}
    cache.put(CacheStage.ALIGNMENT, key, payload)

    data_path, meta_path = cache._get_entry_paths(CacheStage.ALIGNMENT, key)
    assert data_path.is_file()

    # Intentionally corrupt data file
    data_path.write_text("CORRUPTED NOT JSON", encoding="utf-8")

    # Accessing corrupt file must return None (MISS) and safely self-evict
    assert cache.get(CacheStage.ALIGNMENT, key) is None
    assert not data_path.is_file()  # Evicted!
    assert not meta_path.is_file()  # Evicted!
    assert cache.stats["corruptions_evicted"] == 1


def test_cache_checksum_tampering(tmp_path):
    cache = ArtifactCache(cache_dir=tmp_path / ".dev_cache")
    key = "tamper_key_xyz"
    payload = {"number": 42}
    cache.put(CacheStage.SUBTITLE, key, payload)

    data_path, _ = cache._get_entry_paths(CacheStage.SUBTITLE, key)
    # Modify data so checksum mismatches
    data_path.write_text(json.dumps({"number": 999}), encoding="utf-8")

    # Mismatch caught, entry evicted, returns None
    assert cache.get(CacheStage.SUBTITLE, key) is None
    assert not data_path.is_file()
    assert cache.stats["corruptions_evicted"] == 1


def test_cache_stage_and_project_clearing(tmp_path):
    cache = ArtifactCache(cache_dir=tmp_path / ".dev_cache")
    cache.put(CacheStage.ASR, "k1", {"x": 1}, metadata={"project_id": "pA"})
    cache.put(CacheStage.ASR, "k2", {"x": 2}, metadata={"project_id": "pB"})
    cache.put(CacheStage.ALIGNMENT, "k3", {"x": 3}, metadata={"project_id": "pA"})

    stats = cache.get_stats()
    assert stats["total_items"] == 3

    # Clear project pA
    cleared = cache.clear_project("pA")
    assert cleared == 2
    assert not cache.has(CacheStage.ASR, "k1")
    assert cache.has(CacheStage.ASR, "k2")
    assert not cache.has(CacheStage.ALIGNMENT, "k3")

    # Clear all
    cache.clear_all()
    stats = cache.get_stats()
    assert stats["total_items"] == 0


def test_cache_safety_guardrail():
    with pytest.raises(ValueError, match="Safety Violation"):
        ArtifactCache(cache_dir="/some/path/projects_capcut/wrong")
