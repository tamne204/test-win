# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
## REPORT 01: VISUAL MAPPING CODE AUDIT & PATHOLOGY ANALYSIS

- **Project:** 2TOOLNE AutoEdit V2
- **Subsystem:** Visual Pipeline & CapCut Adapter
- **Audit Target:** Production Call Graph, Data Contracts, Image Reuse, & Cut Boundaries
- **Date:** September 2026

---

### 1. Production Call Graph & Data Flow

The visual mapping pipeline connects the acoustic subtitle output to the final CapCut draft. The following trace documents the exact files, classes, and methods executing in current production:

```
[Audio + Script Inputs]
       │
       ▼
[HierarchicalScriptAligner] (core/alignment/hierarchical_aligner.py)
       │  Emits SubtitleCue[] with paragraph_id, sentence_id, token_span
       ▼
[SubtitleSegmenter] (core/subtitles/segmenter.py)
       │  Emits A0-compliant SubtitleCue list
       ▼
[AutoEditPipeline] (core/engine/autoedit_pipeline.py)
       │
       ├───────────────────────────────────────────┐
       ▼ (Pathway 1: If script available)         ▼ (Pathway 2: If SRT only)
[Paragraph Grouping in Pipeline]          [TimelineBuilder.build_from_srt]
  groups cues by paragraph_id                (core/timeline/timeline_builder.py)
       │                                           │  groups cues by 3.0s–7.0s duration
       ▼                                           ▼
[RuleEngine.apply_rules] (core/engine/rule_engine.py)
       │  Applies Ken Burns motion & transitions (KenBurnsEffect, PanDirection)
       ▼
[EditPlan] (core/models/edit_plan.py)
       │  Contains Track, VisualClip, AudioTrack, SubtitleTrack
       ▼
[CapCutVersionAdapter_9_3] (adapters/capcut/version_9_3.py)
       │  Serializes Draft JSON schema & copies media files
       ▼
[CapCut Draft Folder: draft_content.json + media/]
```

#### Exact Component Contracts
1. **`SubtitleCue` (`core/models/subtitle.py`):**
   - Fields: `cue_id`, `text`, `start_us`, `end_us`, `paragraph_id`, `sentence_id`, `token_start`, `token_end`.
   - **Crucial Architectural Disconnect:** When `TimelineBuilder` or `RuleEngine` consumes cues, only `start_us`, `end_us`, and `text` are read. The rich structural metadata (`paragraph_id`, `sentence_id`, `token_span`) introduced in Phase A0 is **dropped at the timeline boundary**.
2. **`VisualClip` (`core/models/edit_plan.py`):**
   - Fields: `clip_id`, `source_path`, `timeline_in_us`, `timeline_out_us`, `duration_us`, `transform`, `animation`.
   - Stores visual placement and assigned Ken Burns parameters.
3. **`TimelineBuilder` (`core/timeline/timeline_builder.py`):**
   - Function: `build_from_srt(srt_cues, media_files, target_min=3.0, target_max=7.0)`
   - Algorithm: Greedy accumulation of cue durations until cumulative length $\ge 3.0\text{s}$, then terminates the shot at the cue boundary.

```
CURRENT_VISUAL_MAPPING_ALGORITHM = GREEDY_CUE_ACCUMULATION_WITH_PARAGRAPH_FALLBACK
```

---

### 2. Why Image Count Expands in Timeline / Disk

In historical analysis of project `2toolne_1788804879_test_1`, an anomaly was observed: **278 physical images became 572 clips on the timeline and 572 files on disk**.

Our code audit revealed the exact two-stage mechanism for this expansion:

1. **Timeline Clip Subdivision:**
   - In pre-A0 legacy mode or tight SRT grouping, 572 shots were created because short duration thresholds ($2\text{s} - 4\text{s}$) split the 1640s timeline into 572 intervals.
   - When images were assigned via `image_paths[idx % len(image_paths)]`, the 278 images were looped more than twice.
2. **Physical Disk Duplication in `version_9_3.py`:**
   - In `apps/capcut-v2/adapters/capcut/version_9_3.py` (lines 165–182):
     ```python
     for idx, clip in enumerate(visual_track.clips):
         target_filename = f"clip_{idx:03d}{ext}"
         target_path = media_dir / target_filename
         shutil.copy2(clip.source_path, target_path)
     ```
   - Every single timeline clip received its own newly copied file in the CapCut project's `media/` folder. Even if the same image was used 3 times, it was copied as `clip_012.png`, `clip_290.png`, and `clip_450.png`.
   - Thus, 572 visual clips created **572 physical PNG files** totaling hundreds of megabytes of redundant disk space.

---

### 3. A0 Paragraph Metadata Disconnect

```
A0_PARAGRAPH_METADATA_REACHES_VISUAL_MAPPING = NO
```

#### The Gap
Phase A0 successfully created and verified `HierarchicalScriptAligner`, which guarantees that every subtitle cue knows:
- Its exact enclosing `paragraph_id`
- Its `sentence_id`
- Its character/token span in the original user script

However, `TimelineBuilder.build_from_srt` accepts only `(index, start_time, end_time, text)`. It strips all paragraph information. The visual stage operates blind to the script's syntactic hierarchy, relying solely on whether a duration accumulator has crossed 3.0 seconds.

---

### 4. Image Reuse Pathologies Audit

On `GOLDEN_LONG_01`, both production pathways were analyzed for image repetition defects:

| Metric | Pathway 1 (Paragraph-Driven) | Pathway 2 (SRT 3–7s Grouping) | Target Invariant (A1) |
| :--- | :--- | :--- | :--- |
| **`PHYSICAL_IMAGES`** | 278 | 278 | 278 |
| **`UNIQUE_IMAGES_REFERENCED`** | 266 | 278 | 278 |
| **`VISUAL_CLIPS`** | 266 | 363 | ~280 |
| **`AVERAGE_USES_PER_IMAGE`** | 0.957 (12 dropped) | 1.306 | 1.000 |
| **`CONSECUTIVE_SAME_IMAGE_CUTS`** | 0 | 0 | 0 |
| **`ABA_LOOP_LT_10S`** | 0 | 0 | 0 |
| **`ABCABC_LOOP_COUNT`** | 0 | 0 | 0 |
| **`MAX_REUSE_PER_IMAGE`** | 1 | 2 (for first 85 images) | 1 |
| **`MEDIAN_REUSE_DISTANCE`** | N/A (no reuse) | 1256.4 seconds | $\ge 120\text{s}$ (if shortage) |

#### Findings
- Current production does **not** generate rapid same-image flashing (consecutive duplicate cuts = 0, ABA loops < 10s = 0).
- The pathology in Pathway 1 is **under-utilization / truncation** (12 images completely dropped).
- The pathology in Pathway 2 is **premature looping** (85 images repeated at the end because shots were unnecessarily cut too short).

---

### 5. Cue ↔ Shot Relationship

Analyzing how subtitle cues group into visual shots across `GOLDEN_LONG_01`:

#### Pathway 1 (Paragraph-Driven, 266 Shots from 641 Cues)
- **Shots with 1 cue:** 129 (48.5%)
- **Shots with 2 cues:** 45 (16.9%)
- **Shots with 3 cues:** 29 (10.9%)
- **Shots with 4+ cues:** 63 (23.7%)
- **`CUES_TRIGGERING_NEW_IMAGE_PERCENT`:** `41.5%` ($266 / 641$)

#### Pathway 2 (SRT-Driven 3–7s, 363 Shots from 641 Cues)
- **Shots with 1 cue:** 181 (49.9%)
- **Shots with 2 cues:** 118 (32.5%)
- **Shots with 3 cues:** 46 (12.7%)
- **Shots with 4+ cues:** 18 (5.0%)
- **`CUES_TRIGGERING_NEW_IMAGE_PERCENT`:** `56.6%` ($363 / 641$)

In neither pathway does the engine degrade to 1:1 mapping (1 cue $\to$ 1 shot). However, in both pathways, ~50% of shots contain only a single subtitle cue. When a single cue is very short (e.g. "Và rồi...", 0.8s), Pathway 1 cuts after 0.8s, causing an unwanted micro-shot.

---

### 6. Cut Structure Quality Audit

We classified every visual cut boundary against the nearest linguistic and acoustic event in the script:

| Boundary Type | Pathway 1 (%) | Pathway 2 (%) | Description |
| :--- | :--- | :--- | :--- |
| **PARAGRAPH** | 84.5% | 31.8% | Cut occurs exactly between script paragraphs |
| **SENTENCE** | 0.0% | 47.2% | Cut occurs at sentence period/question/exclamation mark |
| **CLAUSE** | 0.0% | 6.6% | Cut occurs at comma, colon, semicolon, or dash |
| **ARBITRARY** | 15.5% | 14.4% | Cut occurs mid-clause between connected words |
| **TAIL DROPOUT** | 100.0% | 100.0% | Video terminates at narration end, leaving audio tail empty |

#### Conclusion
In Pathway 2, **14.4% of all visual cuts are arbitrary mid-clause cuts**, disrupting viewer comprehension. Pathway 1 avoids mid-clause cuts for the most part (84.5% paragraph cuts), but suffers from uncontrollable shot duration spikes.

Phase A1 must bridge this dichotomy by introducing a planner that optimizes both duration bounds and syntactic cut quality simultaneously.
