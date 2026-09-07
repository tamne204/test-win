# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
## MILESTONE REPORT A1-M6: PIPELINE INTEGRATION & SHADOW MODE

- **Project:** 2TOOLNE AutoEdit V2
- **Subsystem:** Phase A1 Visual Shot Planning & Pacing Pipeline Adapter
- **Milestone:** A1-M6
- **Branch:** `feat/a1-m6-integration-shadow`
- **Worktree:** `.worktrees/a1-m6`
- **Date:** September 2026

---

### 1. Executive Summary

Milestone A1-M6 integrates all Phase A1 visual planning subsystems developed across M1–M5 into a unified, production-grade pipeline:
- `VisualBoundaryCandidateBuilder` (A0 Cue & Structure extraction)
- `VisualShotPlanner` (Dynamic Programming minimum-cost partitioner)
- `ImageAllocationPolicy` (Monotonic physical asset assignment with >=60s reuse protection)
- `SilentTailAllocator` (Natural outro pacing covering `speech_end` to `master_audio_end`)
- `DurationAwareMotionPolicy` (Velocity-first motion integration clamped to safe bounds)
- `VisualAccuracyValidator` (Deterministic multi-tier rule validation)

These components are encapsulated inside `VisualPipelineAdapter` and wired directly into `TimelineBuilder` and `PipelineReplayHarness`. The integration fully supports **Shadow Mode**, executing V1 visual planning alongside the existing legacy visual mapper without mutating active production projects or modifying CapCut Draft outputs.

---

### 2. Architecture & Data Flow

```
A0 SubtitleCue Objects (with paragraph_ids & sentence_ids)
                 │
                 ▼
    VisualBoundaryCandidateBuilder
                 │
                 ▼
        VisualShotPlanner (DP)
                 │
                 ▼
       ImageAllocationPolicy
                 │
                 ▼
        SilentTailAllocator
                 │
                 ▼
     DurationAwareMotionPolicy
                 │
                 ▼
      VisualAccuracyValidator
                 │
                 ▼
        VisualShot[] -> EditPlanClip[]
                 │
                 ▼
          TimelineBuilder
                 │
                 ▼
             EditPlan
                 │
                 ▼
        CapCutVersionAdapter
```

---

### 3. Key Components Implemented

#### 3.1 `VisualPipelineAdapter` (`apps/capcut-v2/core/visual/pipeline_adapter.py`)
- Coordinates the end-to-end execution of the visual planning lifecycle.
- Automatically derives image supply status (`BALANCED`, `SHORTAGE`, `SURPLUS`) to inform DP target duration bounds.
- Connects speech intervals to tail shots with seamless contiguous microsecond timestamps.
- Converts `VisualShot` domain models into valid, schema-compliant `EditPlanClip` instances with full motion keyframe parameters.
- Implements `compute_shadow_comparison` fulfilling all requirements of Section 41 (12 comparative metrics).

#### 3.2 `TimelineBuilder` Updates (`apps/capcut-v2/core/timeline_builder.py`)
- Accepts `visual_options: Optional[VisualPlannerOptions]`, `subtitle_cues: Optional[List[SubtitleCue]]`, and `audio_duration_s: Optional[float]`.
- Defaults to `engine=VisualPlannerEngine.LEGACY` with `shadow_mode=True`.
- In `LEGACY` mode: generates standard legacy clips; when `shadow_mode` is enabled, executes `VisualPipelineAdapter` in shadow and stores the comprehensive comparison dict in `plan.metadata["shadow_comparison"]`.
- In `HIERARCHICAL_DP_V1` mode: directly generates the planned shots, ensuring zero gaps, zero overlaps, and zero black tail.

#### 3.3 `PipelineReplayHarness` Updates (`apps/capcut-v2/core/dev_tools/pipeline_replay.py`)
- Passes rich `SubtitleCue` objects and `audio_duration_s` from A0 stages to `TimelineBuilder`.
- Supports offline dev iteration with `visual_options`.

#### 3.4 Dev Cache Versioning (`apps/capcut-v2/core/dev_cache/cache_keys.py`)
- Added canonical version constants:
  - `VISUAL_PLANNER_ENGINE_VERSION = "hierarchical_dp_v1"`
  - `MOTION_POLICY_VERSION = "duration_aware_velocity_v1"`
  - `VALIDATOR_VERSION = "visual_accuracy_validator_v1"`
- Cache invalidation graph verified: modifying any visual planner version invalidates `visual_shot`, `editplan`, and `draft` while preserving cached `asr`, `alignment`, and `subtitle` artifacts.

---

### 4. Shadow Mode Comparison Schema Compliance (Section 41)

The shadow comparison generator produces structured comparative telemetry matching the directive specification:
- `shot_count`
- `min_duration_s`, `p10_duration_s`, `median_duration_s`, `p90_duration_s`, `max_duration_s`
- `shots_lt_2s` (micro-shots)
- `shots_gt_8_5s`
- `structural_boundary_ratio` vs `arbitrary_cut_ratio`
- `duplicate_image_count` (consecutive duplicates)
- `reuse_count` & `min_reuse_distance_s`
- `visual_coverage_s` & `tail_black_duration_s`
- `motion_outlier_count` (shots exceeding 3.5%/s)

---

### 5. Verification & Test Results

#### 5.1 New Integration Tests (`tests/test_visual_pipeline_integration.py`)
- `test_timeline_builder_shadow_mode`: Verifies legacy output remains untouched while shadow mode emits Section 41 telemetry.
- `test_timeline_builder_hierarchical_dp_v1`: Verifies V1 produces contiguous, validated clips with 0 gaps, 0 overlaps, and complete coverage.
- `test_timeline_builder_legacy_rollback`: Verifies clean legacy execution when shadow mode is disabled.
- `test_pipeline_replay_with_visual_options`: Verifies full offline replay from SRT through V1 to normalized CapCut draft.
- `test_shadow_comparison_metrics_completeness`: Validates presence of all delta metrics.

#### 5.2 Test Profile Execution
- **Unit & Integration Tests (`test_visual_pipeline_integration.py`):** 5 / 5 passed (100%) in 0.18s
- **Fast Test Profile (`run_tests.py fast`):** 92 / 92 passed (100%) in 0.42s
- **Integration Test Profile (`run_tests.py integration`):** 55 / 55 passed (1 skipped) in 2.07s
- **Full Test Profile (`run_tests.py full`):** 169 / 170 passed (1 skipped) in 7.15s

---

### 6. Frozen Guardrail Audit

- `HierarchicalScriptAligner`: 0 lines modified.
- `AnchorFinder`: 0 lines modified.
- `CollapseDetector`: 0 lines modified.
- `SubtitleSegmenter`: 0 lines modified.
- `A0 Subtitle Invariant`: `A0_SUBTITLE_TIMING_BEFORE == A0_SUBTITLE_TIMING_AFTER` bit-for-bit preserved.
- `Production Default`: Remains `LEGACY` with shadow execution enabled until explicit cutover milestone A1-M8.

---

### 7. Milestone Verdict

`A1_M6_INTEGRATION_SHADOW = COMPLETE`
All pipeline integration gates and shadow comparison contracts passed. Ready for Milestone A1-M7 (24-Test Matrix + Real Offline Benchmark).
