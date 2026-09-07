# AutoEdit V2 — Pipeline Replay Harness

**Milestone:** DEVACCEL-4  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-4-pipeline-replay`  
**Status:** COMPLETE  

---

## 1. Executive Summary

DEVACCEL-4 implements the `PipelineReplayHarness` (`apps/capcut-v2/core/dev_tools/pipeline_replay.py`), providing developers with stage-specific replay capabilities. Developers can test downstream modifications (e.g. subtitle formatting, scene boundary heuristics, motion curves, or CapCut Draft generation) by injecting intermediate artifacts without running earlier pipeline stages.

Crucially, in compliance with Section 15 of the Directive, the harness creates **zero fake parallel implementations**: it directly invokes the real, production-hardened implementations of `HierarchicalScriptAligner`, `SubtitleSegmenter`, `srt_generator`, `TimelineBuilder`, `RuleEngine`, and `CapCutAdapter`.

---

## 2. Supported Replay Entry Points

```
[Audio / Whisper] ──(Optional or Cached)──┐
                                          │
Cached SpeechWordTimestamp[] ─────────────┼──> [Replay from ASR]
                                          │        ↓ HierarchicalScriptAligner
                                          │        ↓ SubtitleSegmenter
                                          │
Raw SRT or SubtitleCue[] ─────────────────┼──> [Replay from SRT]
                                          │        ↓ TimelineBuilder (SRT_DRIVEN)
                                          │        ↓ RuleEngine
                                          │
Canonical EditPlan ───────────────────────┴──> [Replay from EditPlan]
                                                   ↓ CapCutAdapter
                                                   ↓ DraftNormalizer
                                                   ↓
                                              [Canonical Draft Snapshot]
```

### Methods
- `replay_from_asr(...)`: Starts from acoustic word timestamps. Executes hierarchical alignment, subtitle segmentation, SRT-driven timeline generation, CapCut draft synthesis, and draft normalization.
- `replay_from_srt(...)`: Starts from existing subtitle text/file. Directly exercises `TimelineBuilder` and `CapCutAdapter`.
- `replay_from_editplan(...)`: Starts from structured timeline object. Directly exercises `CapCutAdapter`.

---

## 3. Observable Execution Telemetry & Diagnostics

Every replay execution produces a structured `DevRunManifest`:
```json
{
  "start_time": 1725761340.12,
  "total_duration_ms": 78.4,
  "stages": [
    {
      "stage_name": "alignment",
      "cache_hit": true,
      "duration_ms": 1.2,
      "input_hash": "a1b2c3...",
      "artifact_hash": "d4e5f6..."
    },
    {
      "stage_name": "subtitle_segmenter",
      "cache_hit": true,
      "duration_ms": 0.8,
      "input_hash": "d4e5f6...",
      "artifact_hash": "789abc..."
    },
    {
      "stage_name": "timeline_builder",
      "cache_hit": false,
      "duration_ms": 14.5,
      "input_hash": "789abc...",
      "artifact_hash": "def123..."
    },
    {
      "stage_name": "capcut_adapter",
      "cache_hit": false,
      "duration_ms": 32.1,
      "input_hash": "def123...",
      "artifact_hash": "456789..."
    }
  ],
  "engine_versions": {
    "alignment": "hierarchical-anchor-v1",
    "segmenter": "a0_subtitle_v1",
    "timeline": "timeline_builder_v2",
    "adapter": "capcut_9_3"
  }
}
```

---

## 4. Verification Results

Suite `tests/test_pipeline_replay.py`:
- `test_replay_from_asr_short_vi`: PASSED (Cache miss on run 1 -> cache hit on run 2)
- `test_replay_from_srt`: PASSED (Direct SRT -> Timeline -> Draft verified)
- `test_replay_from_editplan`: PASSED (Direct EditPlan -> Draft verified)
- `test_replay_manifest_telemetry`: PASSED (Structured telemetry matches 4 pipeline stages)
- `test_replay_long_01_offline`: PASSED (Complete 29m47s dataset replayed in **< 0.15s** without Whisper or CapCut Desktop!)

**Result:** 5 passed in 0.19s.
