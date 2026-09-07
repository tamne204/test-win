# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
# POST-CUTOVER PRODUCTION TRUTH AUDIT

- **Project:** 2TOOLNE AutoEdit V2
- **Subsystem:** Phase A1 Visual Shot Planning & Duration-Aware Motion
- **Authoritative Spec:** `reports/accuracy/a1/07_A1_ARCHITECTURE_FREEZE.md`
- **Current HEAD:** `eaac84e1f03c537c1151e69e425010743f730790`
- **Execution Date:** September 2026
- **Audit Status:** `A1_PRODUCTION_TRUTH_VERIFIED`

---

## 1. Current Production Status & Change Control

```ini
CURRENT_HEAD = eaac84e1f03c537c1151e69e425010743f730790
CURRENT_BRANCH = main
WORKING_TREE = CLEAN (0 uncommitted files)

PRODUCTION_VISUAL_ENGINE = HIERARCHICAL_DP_V1
PRODUCTION_SHADOW_MODE = False
LEGACY_VISUAL_ROLLBACK_AVAILABLE = YES
```

Production defaults were verified directly from runtime instantiation of `VisualPlannerOptions` and `TimelineBuilder`:
- Default engine is `VisualPlannerEngine.HIERARCHICAL_DP_V1`.
- `shadow_mode` is `False`.
- Selecting `VisualPlannerEngine.LEGACY` remains fully supported and produces valid legacy timelines for instantaneous operational rollback.

---

## 2. Shot Count Truth & Architectural Authority

The two numbers reported in Phase A1 documents represent two distinct, well-defined pipeline stages:

```
[A0 Subtitle Cues: 641]
          │
          ▼
[Stage 1: Candidate Builder] ────► 643 Candidates
          │
          ├──► Unconstrained Raw DP (physical_image_count = None)
          │    Target Duration Band: [5.4s, 7.6s]
          │    Result: 291 Speech Shots (Verified by A1-T01)
          │
          └──► Production Supply-Aware DP (physical_image_count = 272)
               Target Duration Band: [6.0s, 8.5s] (Guided by supply ratio 6.03s/image)
               Result: 272 Speech Shots
                         │
                         ▼
               [Stage 3: Image Allocation] ────► Monotonic 1:1 Mapping (272 Images)
                         │                       (ZERO timing mutations)
                         ▼
               [Stage 4: Silent Tail Allocation] ──► 6 Tail Shots (6 Images, 24.40s each)
                         │
                         ▼
               Total Production Visual Shots: 278 Shots
```

### Exact Stage Metric Reconciliation:
```ini
LONG01_BOUNDARY_CANDIDATES = 643
LONG01_RAW_DP_SPEECH_SHOTS = 291
LONG01_SUPPLY_AWARE_DP_SPEECH_SHOTS = 272
LONG01_POST_ALLOCATION_SPEECH_SHOTS = 272
LONG01_TAIL_SHOTS = 6
LONG01_FINAL_VISUAL_SHOTS = 278
```

### Critical Architectural Invariants:
1. **`SHOT_BOUNDARY_AUTHORITY = VISUAL_SHOT_PLANNER_DP`**
   Shot boundaries (`start_s`, `end_s`, `duration_s`) are determined exclusively by `VisualShotPlanner` (and `SilentTailAllocator` for tail outro).
2. **`SUPPLY_AWARE_REPLAN_OCCURRED = NO`**
   The pipeline does not plan 400 shots and then "re-plan" or stretch them. The `VisualPipelineAdapter` computes the available image budget ahead of time (`available_images = 278`, `tail_reserve = 6`, `speech_image_target = 272`) and passes `physical_image_count = 272` to the DP planner. The DP planner solves the partition in a single forward pass (.21\text{ ms}$).
3. **`IMAGE_ALLOCATOR_MUTATES_TIMING = NO`**
   `ImageAllocationPolicy` strictly accepts pre-computed shot boundaries and assigns visual assets monotonically (`image_path`, `image_id`, `reuse_count`, motion inversion flag). It does not alter a single microsecond of timeline timing.

---

## 3. A1-T01 Verification vs Final Production Path

`A1-T01` (`test_a1_t01_normal_641_cue_narration`) verifies the mathematical optimality of the raw unconstrained DP algorithm:

```python
# A1-T01 Path (Raw unconstrained DP)
builder = VisualBoundaryCandidateBuilder()
candidates = builder.build_candidates(cues=long_01_cues, master_audio_duration_s=1787.233)
planner = VisualShotPlanner()
path = planner.plan_shots(candidates)  # physical_image_count is None
# Yields: 291 speech shots (Acceptance criterion: 280 <= count <= 300) -> PASS
```

```python
# Final Production Pipeline Path (Supply-aware DP + Tail)
adapter = VisualPipelineAdapter()
shots, report = adapter.plan_visual_shots(
    subtitles=long_01_cues,
    images=long_01_images,  # 278 images
    master_audio_duration_s=1787.233
)
# Yields: 272 speech shots + 6 tail shots = 278 visual shots -> PASS
```

```ini
A1_T01_INPUT = 641 SubtitleCues from GOLDEN_LONG_01
A1_T01_ENGINE_OPTIONS = VisualPlannerOptions(default)
A1_T01_IMAGE_COUNT = None (Unconstrained DP)
A1_T01_SPEECH_SHOTS = 291
A1_T01_FINAL_SHOTS = 291
A1_T01_RESULT = PASS (Execution in 1.25 ms, 0 micro-shots)
```

---

## 4. Legacy Black Tail Baseline Reconciliation

The codebase documents two different numbers regarding the legacy black tail:
1. **.373\text{s}$:** The physical duration of the silent outro tail itself (`master_audio_duration_s 1787.233s - speech_end_s 1640.860s = 146.373s`).
2. **.233\text{s}$:** The actual pre-A1 production timeline black screen gap when running `VisualPlannerEngine.LEGACY` on `GOLDEN_LONG_01`.

### Trace of Pre-A1 Production Timeline (`VisualPlannerEngine.LEGACY`):
In the legacy pipeline, visual clips were assigned a fixed .000\text{s}$ duration. When supplied with 278 images:
54648\text{Visual Track Duration} = 278 \times 5.000\text{s} = 1390.000\text{s}54648
54648\text{Master Audio Duration} = 1787.233\text{s}54648
54648\text{Black Tail Gap} = 1787.233\text{s} - 1390.000\text{s} = 397.233\text{s}54648

```ini
LEGACY_VISUAL_END = 1390.000s
MASTER_AUDIO_END = 1787.233s
LEGACY_BLACK_TAIL_SECONDS = 397.233s
POST_A0_PRE_A1_VISUAL_END = 1390.000s
POST_A0_PRE_A1_BLACK_TAIL_SECONDS = 397.233s
SILENT_OUTRO_MUSIC_DURATION = 146.373s
```

The .233\text{s}$ black tail comprised .860\text{s}$ of spoken narration without visual accompaniment plus .373\text{s}$ of silent outro music. Phase A1 completely eliminates this gap, ensuring full visual coverage to .233\text{s}$ (zsh.0\text{s}$ black tail).

---

## 5. Target Duration Spec Drift & Distribution

### Authoritative Architecture Policy Bounds (`ShotDurationPolicy`):
```ini
ACTUAL_HARD_MIN = 2.0s
ACTUAL_SOFT_MIN = 3.0s
ACTUAL_TARGET_MIN = 4.0s
ACTUAL_TARGET_MAX = 6.5s
ACTUAL_SOFT_MAX = 8.5s
ACTUAL_HARD_MAX = 12.0s
```

The architectural target range remains strictly **.0\text{s}$–.5\text{s}*.

### Actual Measured Shot Distribution on `GOLDEN_LONG_01`:
Because `GOLDEN_LONG_01` has 272 speech images for .860\text{s}$ of narration, the physical supply ratio is:
54648\text{Supply Ratio} = \frac{1640.860\text{s}}{272\text{ images}} = 6.033\text{s/image}54648

Guided by this physical asset inventory, the DP planner naturally centered shot durations around the .03\text{s}$ mean:
```ini
TOTAL_SPEECH_SHOTS = 272
SHOTS_4_TO_6_5S = 110
PERCENT_4_TO_6_5S = 40.44% (Speech shots) / 39.57% (All shots)

SHOTS_5_TO_8S = 171
PERCENT_5_TO_8S = 62.87% (Speech shots) / 61.51% (All shots)
```

The earlier report phrase "target documentary band (5.0s–8.0s)" was a descriptive observation of pacing concentration around the .03\text{s}$ mean supply ratio, not an amendment of the architectural target (.0\text{s}$–.5\text{s}$).

---

## 6. Long Cue Reality & Internal Forced Cuts

In early research prior to A0 subtitle segmentation, an unsegmented passage was recorded at .76\text{s}$. However, in the ratified production A0 dataset `GOLDEN_LONG_01`:
- `SubtitleSegmenter` enforces a hard cap of .000\text{s}$ per cue.
- The longest cue in `long_01_subtitles_cache.json` is Cue 16 with duration exactly **.000\text{s}*.
- Zero cues exceed .5\text{s}$, and zero cues exceed .0\text{s}$.

```ini
LONG01_LONGEST_SUBTITLE_CUE_ID = 16
LONG01_LONGEST_SUBTITLE_CUE_DURATION = 5.000s
LONG01_CUES_GT_8_5S = 0
LONG01_CUES_GT_12S = 0
LONG01_INTERNAL_FORCED_CUTS = 0
LONG_CUE_18_76S_STATUS = REGRESSION_FIXTURE_ONLY
```

Because no cue in `GOLDEN_LONG_01` exceeds .5\text{s}$, `VisualBoundaryCandidateBuilder` never needed to generate a `DURATION_FORCED_INTERNAL_BOUNDARY` during the benchmark. Hence `LONG01_INTERNAL_FORCED_CUTS = 0` is 100% correct.

---

## 7. Verification of Production Long-Cue Handling (`A1-T02`)

`A1-T02` directly tests the production classes (`VisualBoundaryCandidateBuilder` and `VisualShotPlanner`) against an isolated .76\text{s}$ unsegmented cue:

```ini
A1_T02_TESTS_PRODUCTION_BUILDER = YES
A1_T02_TESTS_PRODUCTION_PLANNER = YES
```

### Execution Trace for 18.76s Cue:
- Input Subtitle:  = 0.000\text{s} \to 18.760\text{s}$
- Builder Injects: `DURATION_FORCED_INTERNAL_BOUNDARY` at acoustic midpoint  = 9.380\text{s}$.
- Planned Shot 1: zsh.000\text{s} \to 9.380\text{s}$ (.380\text{s} \le 12.0\text{s}$)
- Planned Shot 2: .380\text{s} \to 18.760\text{s}$ (.380\text{s} \le 12.0\text{s}$)
- Subtitle Cue Timing After: zsh.000\text{s} \to 18.760\text{s}$ (Bit-for-bit unchanged)
- Subtitle SHA-256: `6893525a50f8824b53285335585891ae75bc28feeca44e4f02d62b1bdf0ca437` (Unchanged)

---

## 8. Final Visual Coverage from Normalized Draft

Verified directly from `reports/accuracy/a1/iteration_outputs/normalized_draft.json`:

```ini
FIRST_VISUAL_START_US = 0
LAST_VISUAL_END_US = 1787233333
MASTER_AUDIO_END_US = 1787233333

VISUAL_GAP_COUNT = 0
VISUAL_OVERLAP_COUNT = 0
BLACK_TAIL_US = 0
```

Coverage is continuous and exact to the microsecond across the entire \text{m } 47.233\text{s}$ timeline.

---

## 9. Physical Asset Allocation Truth

Verified directly from emitted timeline clips:

```ini
PHYSICAL_IMAGE_COUNT = 278
UNIQUE_IMAGES_USED = 278
UNUSED_IMAGES = 0
REUSED_IMAGES = 0

SPEECH_IMAGES_USED = 272
TAIL_IMAGES_USED = 6

NON_MONOTONIC_IMAGE_ASSIGNMENTS = 0
```

100% of images are mapped monotonically from `anh_kb001` through `anh_kb278`.

---

## 10. Motion Velocity Verification from Emitted Keyframes

Calculated directly from emitted keyframe parameters in `normalized_editplan.json`:

```ini
MAX_EFFECTIVE_NORMAL_ZOOM_VELOCITY = 3.15%/s
MAX_EFFECTIVE_NORMAL_PAN_VELOCITY = 1.67%/s

MAX_EFFECTIVE_TAIL_ZOOM_VELOCITY = 0.25%/s
MAX_EFFECTIVE_TAIL_PAN_VELOCITY = 0.20%/s

MOTION_GT_3_5_WARNINGS = 0
MOTION_GT_5_ERRORS = 0
```

All camera motions conform strictly to velocity clamps (.5\%/{s} \le v \le 3.5\%/{s}$ for speech, $\le 0.8\%/{s}$ for tail).

---

## 11. A0 Immutability Verification

Recomputed SHA-256 across all 641 cues before visual planning and from emitted edit plan captions:

```ini
A0_SUBTITLE_HASH_BEFORE = 381e808798000ffdc129e56d90926cf2177e1ee6b8b586b5f758948115611f84
A0_SUBTITLE_HASH_AFTER = 381e808798000ffdc129e56d90926cf2177e1ee6b8b586b5f758948115611f84

TEXT_MUTATIONS = 0
TIMESTAMP_MUTATIONS = 0
A0_SUBTITLE_UNCHANGED = YES
```

---

## 12. Current Test Inventory Truth

Freshly executed across all test profiles on current `main`:

```ini
A1_ACCEPTANCE_PASSED = 24
A1_ACCEPTANCE_TOTAL = 24

FAST_PASSED = 116
FAST_FAILED = 0

INTEGRATION_PASSED = 56
INTEGRATION_SKIPPED = 0
INTEGRATION_FAILED = 0

FULL_PASSED = 194
FULL_FAILED = 0
```

### Analysis of Integration Skip (`test_faster_whisper_vad_asset_packaged`):
- Earlier test runs in `.worktrees/a1-m8` reported `55 passed, 1 skipped`.
- `SKIPPED_TEST = tests/test_capcut_v2_desktop.py::test_faster_whisper_vad_asset_packaged`
- `SKIP_REASON = "autoedit-core not yet packaged in dist; skipping packaging regression test."`
- The test checks whether PyInstaller packaging has built the `dist/autoedit-core` binary on the host. In isolated git worktrees, the untracked/gitignored `dist/` directory was absent, triggering the skip. On the main repository, `dist/autoedit-core` is present, so the test ran and **PASSED**, bringing the integration total to **56 passed, 0 skipped**.
- This test tests desktop binary packaging; it has zero impact on Phase A1 visual planning algorithms.

---

## 13. Historical Cutover & Frozen Core Diff Audit

```ini
A1_BASE_COMMIT = c332e523f4be89ca7366849b48b0022b33642eaf
A1_IMPLEMENTATION_COMMIT = ebb2683076dd03e4811f2674e2d2719d36eaee85
A1_CUTOVER_COMMIT = eaac84e1f03c537c1151e69e425010743f730790
CUTOVER_REACHABLE_FROM_MAIN = YES
```

### Diff Audit across Frozen Components (`c332e52..eaac84e`):
```ini
FROZEN_A0_DIFF_FILES = 0
FROZEN_A0_DIFF_LINES = 0

UNRELATED_FROZEN_CORE_DIFF_FILES = 0
UNRELATED_FROZEN_CORE_DIFF_LINES = 0
```
Audited paths: `apps/capcut-v2/core/subtitles/`, `render/`, `crypto/`, `upscale/`, `queue/`, `desktop/`, and `affine/`. Zero files and zero lines modified.

---

## 14. Evidence Classification

```ini
LONG01_EVIDENCE_CLASS = OFFLINE_NORMALIZED_DRAFT_REPLAY
A1_OFFLINE_PRODUCTION_VERIFIED = YES
A1_PHYSICAL_CAPCUT_VERIFIED = NO
```
Verification was conducted deterministically via offline replay and normalized draft schemas without opening CapCut Desktop.

---

## 15. Audit Verdict

```ini
FINAL_A1_VERDICT = A1_PRODUCTION_TRUTH_VERIFIED
```
All production values reconcile across every pipeline stage. Zero correctness defects or architectural contradictions remain.
