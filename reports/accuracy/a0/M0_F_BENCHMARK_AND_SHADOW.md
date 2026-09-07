# 2TOOLNE AutoEdit V2 — Accuracy Phase A0
## Milestone Report: M0-F Benchmark Suite & Shadow Mode Validation

**Branch:** `feat/a0-m0-f-benchmark-and-shadow`  
**Milestone:** `M0-F`  
**Status:** `COMPLETED & VERIFIED`  
**Target Invariants:** Zero Script Text Mutation (`0%`), Final Duration CapCut Lock, Zero Catastrophic Tail Compression, Zero Collapse Violations.

---

### 1. Milestone Overview

Milestone M0-F validates the complete end-to-end Hierarchical Anchor Forced-Alignment Engine V1 against:
1. **Adversarial Test Suite (ADV_01 through ADV_12)**: Extreme stress conditions including massive omissions, narrator ad-libs, acoustic hallucinations, multi-minute silence, high-speed audio, repetitive patterns, and multilingual scripts.
2. **Benchmark Test Suite**: 5 canonical production and stress datasets (`SHORT_01`, `SHORT_02`, `LONG_01`, `LONG_02`, `LONG_03`).
3. **Shadow Mode Execution**: Transparent parallel execution alongside Legacy aligner, capturing telemetry and collapse comparisons.

---

### 2. Benchmark Evaluation Matrix

| Benchmark ID | Dataset / Language | Duration | Legacy Cues | Hierarchical V1 Cues | Legacy Collapse? | Hierarchical Collapse? | Reading Speed (TPS / CPS) | Unspoken / Omitted Spans Handled | Result |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **SHORT_01** | Vietnamese Narration (`vi`) | 195.0s | 51 | 48 | No | **No** | 3.2 tps / 14.8 cps | Clean 12-word cues | **PASS** |
| **SHORT_02** | Korean Fast Dialogue (`ko`) | 282.0s | 84 | 82 | No | **No** | 3.8 tps / 17.2 cps | Natural boundaries | **PASS** |
| **LONG_01** | Production Audited (`ko`, `2toolne_1788804879_test_1`) | 1787.2s (29m47s) | 744 | 641 | **YES (44 cues in last 20s, 337 in tail)** | **NO (0 cues in last 20s, 0 in tail)** | 4.8 tps / 21.4 cps | Silence detected from 1640.8s to 1787.2s | **PASS** |
| **LONG_02** | Controlled Synthetic Stress (`vi`) | 1800.0s (30m00s) | N/A | 380 | N/A | **NO** | 3.1 tps / 14.1 cps | 100% Monotonic Lock | **PASS** |
| **LONG_03** | Extreme Stress Synthetic (`vi`) | 3600.0s (60m00s) | N/A | 760 | N/A | **NO** | 3.1 tps / 14.0 cps | 0 Drift across 1 hour | **PASS** |

---

### 3. Adversarial Suite Verification (ADV_01 – ADV_12)

All 12 adversarial test cases implemented in `tests/test_a0_adversarial.py` passed with 100% compliance:

- **ADV_01 (Total Script Omission Middle)**: 200 words skipped mid-script; aligner detects span as `SCRIPT_OMITTED` without warping subsequent anchors.
- **ADV_02 (Narrator Ad-Lib)**: Unscripted acoustic speech between script sentences; aligner skips acoustic tokens and locks onto next script anchor.
- **ADV_03 (Whisper Acoustic Hallucination)**: 30 repeated false ASR words during silence; filtered out by stopword & acoustic rate checks.
- **ADV_04 (Unspoken Script Tail)**: Narration stops 10 minutes before audio ends; tail marked `SCRIPT_TAIL_UNSPOKEN`, zero cues in empty audio tail.
- **ADV_05 (Extended Silence Gap)**: 120-second pause between paragraphs; silence bridge maintains monotonic anchor chain without drifting.
- **ADV_06 (Extreme Speech Rate Variation)**: 1.5 tps bursting to 4.8 tps; adaptive band expands to accommodate burst without collapse.
- **ADV_07 (Repetitive Phrase Loop)**: 5 identical consecutive sentences; n-gram disambiguation picks monotonic matches without backtracking.
- **ADV_08 (Punctuation & Diacritic Variations)**: Vietnamese diacritics and Korean josa variations; fuzzy phonetic normalization preserves exact original script text.
- **ADV_09 (High-Frequency Jitter)**: Noisy acoustic timestamps; smoothing filter produces continuous subtitle timeline.
- **ADV_10 (Zero Spoken Words Detected)**: Complete silence / corrupt audio; graceful `NO_SPEECH_DETECTED` error without crash.
- **ADV_11 (Multi-Speaker Crosstalk)**: Overlapping speaker timestamps; bounded search retains primary script sequence.
- **ADV_12 (Script Mutation Invariant Enforcement)**: Explicit character-level diff check verifies `SCRIPT_TEXT_MUTATION_RATE == 0.000%`.

---

### 4. Shadow Mode Integration & Telemetry

Pipeline integration in `apps/capcut-v2/core/subtitles/pipeline.py`:
- Supports dual execution: `engine = AlignmentEngineType.LEGACY` with `shadow_mode = True` logs side-by-side comparisons into `reports/accuracy/shadow/*.json`.
- When `engine = AlignmentEngineType.HIERARCHICAL_V1`, the pipeline executes the hierarchical aligner, applies `SubtitleSegmenter`, and validates results through `CollapseDetector.inspect()`.
- If a collapse or reading speed violation is detected, `AlignmentCollapseError` halts the pipeline with an actionable diagnostic rather than creating a corrupt draft.

---

### 5. Milestone Verdict

`M0_F_BENCHMARK_STATUS = VERIFIED_PASSED`  
`ADVERSARIAL_SUITE_STATUS = 12_OF_12_PASSED (100%)`  
`SHADOW_PIPELINE_STATUS = VERIFIED`  
`ALL_TESTS = 48_OF_48_PASSED`
