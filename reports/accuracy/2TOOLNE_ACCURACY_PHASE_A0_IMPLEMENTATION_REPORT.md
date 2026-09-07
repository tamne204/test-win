# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A0 IMPLEMENTATION REPORT

**Project:** 2TOOLNE AutoEdit V2  
**Target Subsystems:** Subtitle Forced Alignment, Subtitle Segmentation, Timeline Scene Derivation  
**Date:** September 8, 2026  
**Status:** IMPLEMENTATION COMPLETE & SHADOW VALIDATED  
**Authoritative Freeze Spec:** `reports/accuracy/2TOOLNE_ACCURACY_PHASE_A0_ARCHITECTURE_FREEZE.md`  

---

## 1. EXECUTIVE SUMMARY & SCOPE COMPLIANCE

Accuracy Phase A0 eliminates the two foundational accuracy failures identified in the root-cause forensic audit:
1. **ERR-A0-01 (Long-Form Forced Alignment Collapse):** Global monotonic DP warping without bounded anchor chains caused cumulative acoustic drift, losing lock during non-speech/silent intervals and cramming tens to hundreds of subtitle cues into the project tail (e.g. 44 sentences into 19.6s in `2toolne_1788804879_test_1`).
2. **ERR-A0-02 (Paragraph ↔ Subtitle Cue 1:1 Mapping Fallacy):** The timeline generator assumed a 1:1 correspondence between script lines and subtitle cues, causing multi-cue paragraphs to desynchronize visual scene boundaries across the entire timeline.

### Scope Guardrails & Invariants Strictly Preserved:
- **`FORCED_ALIGNMENT_EMITTED_TEXT_SOURCE = ORIGINAL_SCRIPT_ONLY`**: Whisper provides acoustic timestamp evidence only. Whisper transcription wording never alters or replaces user script text. Script text mutation rate = **0.000%**.
- **`LOCAL_ALIGNMENT_ERROR_PROPAGATION = CONFINED_TO_CURRENT_BOUNDED_REGION`**: Failures or ad-libs within Region $k$ are strictly bounded by surrounding verified acoustic anchors $[A_k, A_{k+1}]$ and cannot propagate drift to subsequent regions.
- **CapCut Final Duration Lock**: Video duration is strictly locked to audio duration (`VISUAL_TIMELINE_END == MASTER_AUDIO_END`). Subtitle cues never exceed audio duration (`cue.end <= audio.end`), visual gaps = 0, visual overlaps = 0, subtitle overlaps = 0.
- **Deferred Subsystems Intact**: `VisualShotPlanner`, Ken Burns duration scaling, and frame quantization were **NOT** modified during Phase A0 and remain safely deferred to subsequent phases.

---

## 2. COMPLETED MILESTONE ARCHITECTURE & ARTIFACTS

Phase A0 was executed systematically across 6 dedicated Git branches/worktrees, with every milestone fully verified before merging into `main`:

| Milestone | Subsystem / Component | Key Implementation Details | Git Branch / Commit | Status | Milestone Report |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **M0-A** | Data Models & Contracts | Defined `ScriptToken`, `ASRWordTimestamp`, `AlignedToken`, `RegionHealth`, `UnmatchedScriptSpan`, `SubtitleCue`, `AlignmentOptions`, `AlignmentResult` in `core/subtitles/models.py`. | `feat/a0-m0-a-data-models` (`9a8bc43`) | **MERGED** | [`M0_A_DATA_MODELS.md`](file:///Users/2tamne/tool%20ffmpeg/reports/accuracy/a0/M0_A_DATA_MODELS.md) |
| **M0-B** | Anchor Discovery & Monotonic Chain | Implemented `AnchorFinder` in `core/subtitles/anchor_finder.py`: Stopword filtering, N-gram uniqueness scoring, forward/backward neighborhood validation, acoustic speed guard ($<8.0$ words/s), and silence bridging. | `feat/a0-m0-b-anchors` (`ab0ceef`) | **MERGED** | [`M0_B_ANCHORS.md`](file:///Users/2tamne/tool%20ffmpeg/reports/accuracy/a0/M0_B_ANCHORS.md) |
| **M0-C** | Hierarchical Aligner & DP Fallback | Implemented `HierarchicalScriptAligner` in `core/subtitles/hierarchical_aligner.py`: Bounded region division, adaptive band widening ($k=2,4,8$), Needleman-Wunsch DP fallback, and tail feasibility detection. | `feat/a0-m0-c-hierarchical-aligner` (`7fbf1d8`) | **MERGED** | [`M0_C_HIERARCHICAL_ALIGNER.md`](file:///Users/2tamne/tool%20ffmpeg/reports/accuracy/a0/M0_C_HIERARCHICAL_ALIGNER.md) |
| **M0-D** | Quality Gate & Collapse Detector | Implemented `CollapseDetector` and `AlignmentCollapseError` in `core/subtitles/collapse_detector.py`: Dual-metric speed thresholds, micro-cue clustering detection, and tail collapse gate. | `feat/a0-m0-d-quality-gate` (`4e259b1`) | **MERGED** | [`M0_D_HEALTH_COLLAPSE_GATE.md`](file:///Users/2tamne/tool%20ffmpeg/reports/accuracy/a0/M0_D_HEALTH_COLLAPSE_GATE.md) |
| **M0-E** | Paragraph Mapping & Hygiene | Enforced Single-Paragraph Cue Invariant in `SubtitleSegmenter` (`core/subtitles/subtitle_segmenter.py`). Rewrote `compute_script_paragraphs_scene_boundaries` in `core/srt_timeline.py` using true paragraph membership grouping. | `feat/a0-m0-e-paragraph-mapping` (`5896bbd`) | **MERGED** | [`M0_E_PARAGRAPH_MAPPING.md`](file:///Users/2tamne/tool%20ffmpeg/reports/accuracy/a0/M0_E_PARAGRAPH_MAPPING.md) |
| **M0-F** | Benchmark Suite & Shadow Mode | Integrated `AlignmentEngineType.HIERARCHICAL_V1` and Shadow Mode in `core/subtitles/pipeline.py`. Implemented 12 adversarial test cases (`tests/test_a0_adversarial.py`) and 5 benchmark datasets (`tests/test_a0_benchmark.py`). | `feat/a0-m0-f-benchmark-and-shadow` (`fe57a9a`) | **MERGED** | [`M0_F_BENCHMARK_AND_SHADOW.md`](file:///Users/2tamne/tool%20ffmpeg/reports/accuracy/a0/M0_F_BENCHMARK_AND_SHADOW.md) |

---

## 3. PRODUCTION RE-BENCHMARK: AUDITED PROJECT `LONG_01`

A forensic before-and-after re-benchmark was conducted on the exact project that triggered the Phase A0 deep research (`2toolne_1788804879_test_1`, `Tập_1.wav`, 29m 47.23s, 3,068 Korean script tokens).

### Key Forensic Findings:
- Real narration finishes at **1640.86s** (27m 20.86s) with the spoken Korean phrase *"다음 이야기에서 뵙겠습니다."*
- From **1640.86s to 1787.23s** (final 146.37 seconds), the audio contains **zero speech**.
- **Legacy Engine Result:** Lost monotonic alignment lock, shoved **337 cues** into the silent tail, including **197 micro-cues (<0.40s)** and **44 cues jammed into the final 20 seconds** at >13.3 tokens/sec.
- **Hierarchical Engine V1 Forensic Results:**
  - `SCRIPT_TOKEN_COUNT = 3068`
  - `ASR_WORD_COUNT = 3053`
  - `EXACT_MATCHED = 2129` (69.39%)
  - `FUZZY_MATCHED = 592` (19.30%)
  - `INTERPOLATED = 347` (11.31%)
  - `OMITTED = 0`
  - `UNMATCHED = 0`
  - `SCRIPT_MUTATION_RATE = 0.000%`
  - **Reading Speed Profile:**
    - Document Average: **1.87 TPS**
    - Max Sustained (3-cue window): **3.06 TPS** (13.7 CPS) — strictly below 5.0 TPS limit
    - Transient Peak Single-Cue: **5.88 TPS** (26.1 CPS) — single isolated exclamation (<0.35s)
  - Emitted **0 cues** into the silent tail (1640.86s – 1787.23s).
  - Emitted **0 cues** in the final 20 seconds.
  - Collapse violations: **0**.
  - Detailed forensic report available at: [`LONG_01_BEFORE_AFTER_COMPARISON.md`](file:///Users/2tamne/tool%20ffmpeg/reports/accuracy/a0/LONG_01_BEFORE_AFTER_COMPARISON.md).

---

## 4. ADVERSARIAL & BENCHMARK VALIDATION RESULTS

The entire test suite was executed against the merged codebase on `main`:

```
============================= test session starts ==============================
rootdir: /Users/2tamne/tool ffmpeg
collected 48 items

tests/test_a0_adversarial.py ............                                [ 25%]
tests/test_a0_anchor_finder.py ......                                    [ 37%]
tests/test_a0_benchmark.py .....                                         [ 47%]
tests/test_a0_collapse_detector.py .....                                 [ 58%]
tests/test_a0_data_models.py ........                                    [ 75%]
tests/test_a0_hierarchical_aligner.py ....                               [ 83%]
tests/test_a0_paragraph_mapping.py ...                                   [ 89%]
tests/test_a0_pipeline_shadow.py .....                                   [100%]

============================== 48 passed in 1.71s ==============================
```

Legacy regression suite verification:
```
tests/test_forced_alignment.py .....                                     [100%]
============================== 5 passed in 5.42s ===============================
```

### Reconciled Test Inventory:
- **A0_NEW_TESTS:** 48 / 48 passed (100%)
- **LEGACY_REGRESSION_TESTS:** 5 / 5 passed (100%)
- **TOTAL_EXECUTED_TESTS:** 53 / 53 passed (100%)
- **TOTAL_FAILED:** 0

---

## 5. PHASE A0 IMPLEMENTATION & CUTOVER VERDICTS

### Verdict 1: Implementation & Shadow Validation (Milestone M0-F Complete)
```
================================================================================
VERDICT 1: A0_HIERARCHICAL_IMPLEMENTATION_COMPLETE_SHADOW_VALIDATED
================================================================================
```

### Verdict 2: Production Cutover Complete (Merged into Main at Commit 564caf1)
- **Active Production Default:** `AlignmentOptions.engine = AlignmentEngineType.HIERARCHICAL_V1`
- **Shadow Mode:** `AlignmentOptions.shadow_mode = False`
- **Emergency Rollback:** `AlignmentEngineType.LEGACY` verified intact and available.
```
================================================================================
VERDICT 2: A0_HIERARCHICAL_PRODUCTION_CUTOVER_COMPLETE
================================================================================
```
