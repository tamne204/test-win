# AutoEdit V2 — Development Loop Reality Audit

**Milestone:** DEVACCEL-0  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-0-audit`  
**Base Commit:** `e800c73`  
**Status:** COMPLETE  

---

## 1. Executive Summary

Prior to this acceleration initiative, developing and validating AutoEdit V2 features (especially visual shot mapping and timeline rules) was hampered by monolithic end-to-end execution. Even though unit tests for isolated mathematical functions run in under 0.5s, any realistic project-scale validation historically required re-running speech recognition (FasterWhisper on 30m audio takes ~143.5s), manually opening CapCut Desktop, and visually verifying the timeline.

This audit establishes empirical baseline timings and categorizes the dependency footprint for five core developer change categories.

---

## 2. Stage Dependency & Re-run Analysis

| Change Scenario | Reruns Whisper? | Reruns Alignment? | Reruns Subtitles? | Reruns Timeline? | Reruns Draft? | Requires Electron? | Requires CapCut App? | Requires Win Package? | Historical Iteration Time | Accelerated Target Time |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **A. Subtitle Logic** (e.g. boundary rules, max chars) | NO (if ASR cached)<br>YES (in manual e2e) | YES | YES | NO | NO | NO | NO | NO | 145s (e2e)<br>~2.5s (cached) | **< 0.5s** |
| **B. Timeline Logic** (e.g. clip durations, gap rules) | NO | NO | NO | YES | YES | NO | Optional | NO | 15–30s (manual CapCut) | **< 0.3s** |
| **C. Visual Mapping** (e.g. VisualShotPlanner, image sync) | NO | NO | NO | YES | YES | NO | Often (manual check) | NO | 160s (if full e2e rerun)<br>30s (CapCut inspect) | **< 1.0s** |
| **D. CapCut Adapter** (e.g. keyframe schema, tracks) | NO | NO | NO | NO | YES | NO | YES (historical) | NO | 20–45s | **< 0.2s** (normalized snapshot) |
| **E. Renderer / UI** (e.g. Electron bridge, progress) | NO | NO | NO | NO | NO | YES | NO | YES (on Windows) | 30–90s | Milestone / gate only |

---

## 3. Empirical Baseline Measurements

All measurements performed on the reference development environment (macOS Darwin 25.3.0, Apple Silicon, Python 3.12.14 in `.venv`, `PYTHONPATH=".:apps/capcut-v2"`):

```
CURRENT_FASTEST_UNIT_LOOP = 0.409s (pytest tests/test_script_normalizer.py)
CURRENT_TIMELINE_LOOP     = 0.296s (pytest tests/test_capcut_v2_core.py -k test_timeline_builder)
CURRENT_DRAFT_LOOP        = 0.080s (pytest tests/test_capcut_v2_core.py -k test_capcut_adapter_generation_and_schema)
CURRENT_A0_BENCHMARK_LOOP = 1.774s (pytest tests/test_a0_benchmark.py with cached LONG_01 ASR)
CURRENT_REAL_CAPCUT_LOOP  = 165.0s (Whisper 143.5s + Draft Gen 1.5s + CapCut Launch & manual visual inspection 20.0s)
```

---

## 4. Key Bottlenecks & Redundant Work Identified

1. **Acoustic Re-computation Waste:**
   Re-running speech recognition (FasterWhisper) on 30-minute audio (`Tập_1.wav`, 1641s speech) takes ~143.5s on GPU/CPU. When developing timeline algorithms, visual shot planners, or keyframe curves, the acoustic timestamps are 100% invariant. Re-running Whisper is pure waste.

2. **Physical Desktop Application Friction:**
   Developers frequently launched physical CapCut Desktop to answer questions like:
   - "Did the visual track get 278 clips?"
   - "Are keyframe zoom values correct (1.0 -> 1.15)?"
   - "Did audio and subtitle tracks align without gaps?"
   All of these invariants exist in `draft_info.json`. Relying on physical GUI inspection costs 20–45s per cycle and is impossible in headless CI or remote environments.

3. **Volatile Draft Metadata Preventing Snapshot Testing:**
   CapCut `draft_info.json` and `draft_meta_info.json` contain volatile fields:
   - Random UUIDs (`id: "7B21F3A8-..."`)
   - Timestamps (`create_time: 1725738...`)
   - Machine-specific absolute paths (`/Users/2tamne/...` vs `C:\\Users\\...`)
   Because these change on every run, naive `diff` or snapshot tests failed, forcing developers back into manual GUI checking.

4. **Monolithic Pipeline Coupling:**
   No standardized replay harness existed to load intermediate outputs (e.g. start pipeline from existing SRT directly into VisualShot, or start from EditPlan directly into CapCutAdapter).

---

## 5. DEVACCEL Architectural Remedy

To eliminate this waste, the remaining milestones will implement:
- **DEVACCEL-1:** Content-addressed SHA-256 intermediate artifact cache (`apps/capcut-v2/core/dev_cache/`).
- **DEVACCEL-2:** Golden test corpus (`tests/fixtures/golden/`) including real `LONG_01` ASR references.
- **DEVACCEL-3:** `DraftNormalizer` (`apps/capcut-v2/core/dev_tools/draft_normalizer.py`) enabling canonical snapshot testing.
- **DEVACCEL-4:** Pipeline Replay Harness (`apps/capcut-v2/core/dev_tools/pipeline_replay.py`) for stage-specific replay.
- **DEVACCEL-5:** Fast (< 2s), Integration, and Full test profiles.
- **DEVACCEL-6 & 7:** Worktree isolation and OSS scouting framework.
- **DEVACCEL-8:** Offline reality trace demonstrating end-to-end generation in seconds without CapCut or Whisper.
