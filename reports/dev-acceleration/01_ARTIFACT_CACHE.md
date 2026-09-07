# AutoEdit V2 — Content-Addressed Intermediate Artifact Cache

**Milestone:** DEVACCEL-1  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-1-cache`  
**Status:** COMPLETE  

---

## 1. Executive Summary

DEVACCEL-1 establishes the foundation for offline, zero-redundancy feature development. By introducing content-addressed intermediate caching across the entire AutoEdit V2 generation pipeline, developers can modify downstream stages (such as VisualShot planning or timeline rules) without re-executing expensive upstream stages (such as FasterWhisper speech recognition or audio alignment).

---

## 2. Dependency Hashing Model

Each stage is strictly content-addressed using cryptographic SHA-256 digests over sorted, canonicalized JSON representations of inputs, engine version strings, and algorithmic options:

```
[Audio File Content]
       ↓
  audio_hash + ASR_ENGINE_VERSION + asr_options
       ↓
 [ASR Cache Artifact]  (SpeechWordTimestamp list)
       ↓
  script_hash + asr_artifact_hash + ALIGNMENT_ENGINE_VERSION + align_options
       ↓
 [Alignment Artifact]  (AlignedToken spans & anchors)
       ↓
  alignment_artifact_hash + SUBTITLE_SEGMENTER_VERSION + subtitle_options
       ↓
 [Subtitle Artifact]   (SubtitleCue list & SRT)
       ↓
  subtitle_artifact_hash + image_manifest_hash + VISUAL_PLANNER_VERSION + visual_settings
       ↓
 [VisualShot Artifact] (VisualShot[] spans & assigned images)
       ↓
  visual_shot_artifact_hash + MOTION_ENGINE_VERSION + motion_settings
       ↓
 [EditPlan Artifact]   (Canonical EditPlan specification)
       ↓
  editplan_artifact_hash + CAPCUT_ADAPTER_VERSION + adapter_settings
       ↓
 [Draft Artifact]      (draft_info.json & draft_meta_info.json)
```

### Invalidation Guarantee
Upstream modifications strictly invalidate downstream stages without affecting parallel branches or invalidating unrelated assets:
- Modifying `VisualShotPlanner` causes a cache miss only for `VisualShot`, `EditPlan`, and `Draft`. `ASR` and `Alignment` are **100% reused** (saving ~143.5s per test run on `LONG_01`).
- Modifying `Ken Burns` motion settings causes a cache miss only for `EditPlan` and `Draft`. `VisualShot` timing and `ASR` are **100% reused**.

---

## 3. Cache Resilience & Production Separation

1. **Strict Production Isolation:**
   The development cache root defaults to `<repo_root>/.dev_cache/` (registered in `.gitignore`). Initializer guards explicitly reject and raise `ValueError` if configured within user project folders (`projects_capcut`, `projects`, etc.).
2. **Self-Healing Corruption Handling:**
   If a cache file is corrupted, truncated, or tampered with:
   - Checksum validation fails.
   - The corrupt file and its metadata sidecar are **immediately evicted** from disk.
   - The cache reports an immediate `MISS` (`None`), allowing the caller to recompute seamlessly without crashing.
3. **Atomic Writes:**
   All entries are written via `tempfile.NamedTemporaryFile` in the target directory and finalized via atomic `os.replace`.
4. **Disposability & Selective Invalidation:**
   The cache provides fine-grained lifecycle management:
   - `clear_stage(stage)`
   - `clear_project(project_id)`
   - `clear_all()`
   - Developer CLI: `python -m core.dev_cache.cli --stats`, `--clear-all`, `--clear-project <id>`

---

## 4. Verification Results

Suite `tests/test_dev_artifact_cache.py`:
- `test_compute_content_hash_determinism`: PASSED
- `test_dependency_chain_invalidation`: PASSED
- `test_artifact_cache_lifecycle`: PASSED
- `test_cache_corruption_resilience`: PASSED
- `test_cache_checksum_tampering`: PASSED
- `test_cache_stage_and_project_clearing`: PASSED
- `test_cache_safety_guardrail`: PASSED

**Result:** 7 passed in 0.04s.
