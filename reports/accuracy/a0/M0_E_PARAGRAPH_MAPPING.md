# ACCURACY PHASE A0 — MILESTONE REPORT M0-E: PARAGRAPH MAPPING & SOURCE-SPAN HYGIENE
**Subsystem:** Subtitle Segmentation & Timeline Paragraph Derivation (`apps/capcut-v2/core/subtitles/subtitle_segmenter.py`, `apps/capcut-v2/core/srt_timeline.py`)  
**Milestone:** M0-E  
**Branch:** `feat/a0-m0-e-paragraph-mapping`  
**Status:** COMPLETED & VERIFIED  
**Date:** September 8, 2026  

---

## 1. OBJECTIVE & DELIVERABLES

Milestone M0-E eliminates failure mode `ERR-A0-02` (Paragraph ↔ Subtitle Cue 1:1 Mapping Fallacy), ensuring that splitting long lines into multiple subtitle cues never desynchronizes scene boundaries from script paragraphs.

### Deliverables Implemented:
1. **Single-Paragraph Cue Invariant (`SubtitleSegmenter`):**
   - Enforced rule: `ONE SUBTITLE CUE MAY NOT CROSS A STRONG PARAGRAPH BOUNDARY`.
   - `SubtitleSegmenter._segment_tokens` detects paragraph boundaries where `token[i].script_token.paragraph_id != token[i+1].script_token.paragraph_id`.
   - Triggers an immediate, inviolable cue break at paragraph transitions.
   - Dangling-word absorption logic explicitly forbids extending across paragraph boundaries.

2. **Source-Span Metadata Population (`SubtitleCue`):**
   - Populates source spans on each cue:
     - `source_token_start`: First token's `original_index`.
     - `source_token_end`: Last token's `original_index`.
     - `paragraph_ids`: Sorted list of unique paragraph IDs (guaranteed single ID per cue).
     - `sentence_ids`: Unique sentence IDs spanned by the cue.
     - `alignment_confidence`: Mean mathematical token alignment confidence.

3. **`SubtitleEntry` Extension (`srt_timeline.py`):**
   - Added `paragraph_ids`, `sentence_ids`, `source_token_start`, `source_token_end`, and `alignment_confidence` to `SubtitleEntry`.
   - Full backwards compatibility with legacy callers.

4. **Paragraph Scene Boundary Derivation (`compute_script_paragraphs_scene_boundaries`):**
   - Replaced flawed line-counting loop (`for _ in range(count): p_subs.append(subtitles[sub_idx])`) with true paragraph membership grouping.
   - When cues have explicit `paragraph_ids` (from Phase A0 `SubtitleCue`), cues are grouped by their explicit `paragraph_id`.
   - When cues lack explicit metadata (legacy SRT fallback), sequential normalized text matching against script paragraphs maps cues to paragraphs without assuming 1 line == 1 cue.
   - Zero desynchronization, zero orphaned final scene dump.

---

## 2. VERIFICATION & TEST RESULTS

Automated unit tests were implemented in `tests/test_a0_paragraph_mapping.py`:
- `test_cue_never_crosses_paragraph_boundary`: Verified cues strictly break at paragraph boundaries even when word count limit has not been reached.
- `test_paragraph_scene_boundaries_with_multi_cue_paragraphs`: Verified 1 paragraph splitting into 4 cues correctly yields 1 scene with all 4 cues and matching start/end times.
- `test_legacy_srt_fallback_multi_cues_per_paragraph`: Verified that raw text matching correctly groups multi-cue paragraphs without 1:1 line counting fallacy.

### Test Run Output:
```bash
PYTHONPATH="apps/capcut-v2" pytest tests/test_a0_paragraph_mapping.py
============================== 3 passed in 0.03s ===============================
```

Full A0 test suite verification:
```bash
PYTHONPATH="apps/capcut-v2" pytest tests/test_a0_*.py
============================== 26 passed in 0.05s ==============================
```

---

## 3. QA & SIGN-OFF

- **ERR-A0-02 Elimination:** Verified. Paragraph ↔ Cue mapping no longer relies on line counts. Multi-cue paragraphs group correctly into single scenes.
- **Paragraph Isolation:** Guaranteed. No cue spans across multiple paragraphs.
- **Sign-off:** Milestone M0-E is ratified and ready for merge into baseline.
