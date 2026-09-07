# MILESTONE A1-M1 REPORT: MODELS & VISUAL BOUNDARY CANDIDATE BUILDER

- **Milestone:** A1-M1
- **Subsystem:** Data Contracts & Visual Boundary Extraction
- **Status:** COMPLETED
- **Branch:** `feat/a1-m1-boundary-builder`
- **Date:** September 2026

---

### 1. Implemented Components

1. **Data Contracts (`apps/capcut-v2/core/visual/models.py`):**
   - `VisualBoundaryType`: Enum covering 9 boundary origins (`SPEECH_START`, `SUBTITLE_BOUNDARY`, `PARAGRAPH_BOUNDARY`, `SENTENCE_BOUNDARY`, `CLAUSE_BOUNDARY`, `ACOUSTIC_PAUSE`, `DURATION_FORCED_INTERNAL_BOUNDARY`, `SPEECH_END`, `MASTER_AUDIO_END`).
   - `VisualBoundaryCandidate`: Strongly typed candidate with exact timestamp, structural strength, left/right cue context, acoustic gap, and internal split flag.
   - `ShotDurationPolicy` & `TailDurationPolicy`: Explicit duration boundaries for speech shots ($2.0\text{s} - 12.0\text{s}$) and tail shots ($4.0\text{s} - 35.0\text{s}$).
   - `VisualShot`: Internal shot data model with image path, cue IDs, paragraph IDs, motion profile, and planner cost.
   - `ImageSupplyState`: Shortage, Balanced, and Surplus states.
   - `VisualPlannerEngine` & `VisualPlannerOptions`: Configuration for legacy and `HIERARCHICAL_DP_V1` engine with shadow mode.

2. **Boundary Builder (`apps/capcut-v2/core/visual/boundary_builder.py`):**
   - `VisualBoundaryCandidateBuilder`: Consumes actual A0 `SubtitleCue` objects directly (never reparsing flat SRT).
   - Long-Cue Internal Splits: Automatically detects cues $> \text{soft\_max}$ ($8.5\text{s}$), such as `LONG_01` cue 641 ($18.76\text{s}$), and bisects into balanced internal candidates ($9.38\text{s} + 9.38\text{s}$) while leaving subtitle timing 100% untouched.
   - Punctuation & Acoustic Classification: Assigns structural strength (1.0 for paragraph breaks, 0.8 for sentences, 0.5 for clauses, 0.4 for pauses $\ge 100\text{ms}$).
   - Strict Chronological Deduplication: Merges identical timestamp candidates while retaining the strongest structural signal.

---

### 2. Verification
- Unit test suite: `tests/test_visual_boundary_builder.py` (3 passed in 0.04s).
- Fast test profile: 67 passed in 0.42s.
- Zero diff on existing A0 alignment or core subsystems.
