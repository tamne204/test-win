# ACCURACY PHASE A0 — MILESTONE REPORT M0-A: DATA MODEL EVOLUTION
**Subsystem:** Core Subtitle & Alignment Data Models (`apps/capcut-v2/core/subtitles/models.py`, `script_normalizer.py`)  
**Milestone:** M0-A  
**Branch:** `feat/a0-m0-a-data-models`  
**Status:** COMPLETED & VERIFIED  
**Date:** September 8, 2026  

---

## 1. OBJECTIVE & DELIVERABLES

Milestone M0-A establishes the strongly typed, immutable data structures required to support hierarchical anchor-based alignment, granular region health diagnostics, and paragraph-to-cue mapping without breaking existing downstream consumers.

### Deliverables Implemented:
1. **`ScriptToken` Extension:**
   - Retained `token_index`, `raw_text`, `normalized_text`, `char_start`, `char_end`, `leading_whitespace`, `trailing_punctuation`, `is_sentence_break`, `is_clause_break`.
   - Added hierarchical document sequence fields:
     - `paragraph_id: int` (0-indexed paragraph index, split on `\n\s*\n` double newlines / blank lines).
     - `sentence_id: int` (0-indexed document-wide sentence index).
     - `clause_id: int` (0-indexed clause index within sentence, incrementing on `,;:-`).
     - Added `original_index` property aliasing `token_index`.

2. **`ASRWordTimestamp` First-Class Model:**
   - Formalized `ASRWordTimestamp` as an alias of `SpeechWordTimestamp` with full acoustic likelihood exposure.
   - Preserved `word`, `start`, `end`, `confidence`.
   - Added `original_index: int`, `normalized_text: str`, `segment_id: int`.
   - Properties exposed: `raw_word`, `start_s`, `end_s`, `probability` (Whisper token probability is strictly preserved and never discarded).

3. **`AlignedToken` Extension:**
   - Retained `script_token`, `start_s`, `end_s`, `confidence`, `match_type`, `asr_word`, `asr_confidence`.
   - Added `asr_word_ref: Optional[SpeechWordTimestamp]`.
   - Added `string_similarity: float`.
   - Added `token_confidence: float`.
   - Added `anchor_distance_tokens: int`.
   - Added `alignment_operation: str`.
   - Added `asr_probability` property.

4. **`UnmatchedScriptSpan` Model:**
   - Created `UnmatchedScriptSpan` dataclass to explicitly represent unspoken script portions without fabricating timestamps:
     - `span_id: int`
     - `char_start: int`, `char_end: int`
     - `token_start: int`, `token_end: int`
     - `text: str`
     - `reason: str` (`SCRIPT_OMITTED`, `SCRIPT_TAIL_UNSPOKEN`, `ACOUSTIC_DROPOUT`)

5. **`RegionHealth` Model:**
   - Structured diagnostic entity representing individual bounded region health:
     - `region_index`, `start_time_s`, `end_time_s`, `duration_s`
     - `script_token_count`, `asr_word_count`
     - `exact_matches`, `fuzzy_matches`, `interpolated_count`, `omitted_count`, `inserted_count`
     - `token_reading_speed` (tokens/sec), `language_cps` (chars/sec)
     - `mean_confidence`
     - `health_status` (`HEALTHY`, `DEGRADED`, `SUSPICIOUS`, `COLLAPSED`)

6. **`SubtitleCue` Extension (A0-02 Foundation):**
   - Retained `index`, `start_s`, `end_s`, `text`, `confidence`, `tokens`.
   - Added source span mapping:
     - `source_token_start: int`
     - `source_token_end: int`
     - `paragraph_ids: List[int]`
     - `sentence_ids: List[int]`
     - `alignment_confidence: float`

7. **`AlignmentOptions` & `AlignmentEngineType`:**
   - Added enum `AlignmentEngineType`: `LEGACY = "legacy"`, `HIERARCHICAL_V1 = "hierarchical-anchor-v1"`.
   - In `AlignmentOptions`, configured:
     - `engine = AlignmentEngineType.LEGACY` (Validation Phase active default).
     - `shadow_mode = True` (Enables concurrent shadow execution).
     - `allow_degraded = False`.

8. **`AlignmentResult` Extension:**
   - Extended with:
     - `aligned_tokens: List[AlignedToken]`
     - `anchors: List[Any]`
     - `regions: List[Any]`
     - Granular ratios: `matched_token_ratio`, `exact_match_ratio`, `fuzzy_match_ratio`, `interpolated_ratio`, `omitted_script_ratio`, `asr_insertion_ratio`, `anchor_coverage_ratio`.
     - `region_health_list: List[RegionHealth]`
     - `unmatched_script_spans: List[UnmatchedScriptSpan]`
     - `alignment_engine_version: str`
     - `diagnostics: Dict[str, Any]`

---

## 2. VERIFICATION & TEST RESULTS

Automated unit tests were authored in `tests/test_a0_data_models.py` and executed against the full subtitle test suite:

```bash
PYTHONPATH="apps/capcut-v2" pytest tests/test_a0_data_models.py tests/test_script_aligner.py tests/test_script_normalizer.py tests/test_script_to_srt_pipeline.py tests/test_subtitle_segmenter.py tests/test_srt_generator.py
```

### Test Summary:
- `tests/test_a0_data_models.py`: 8 passed
- `tests/test_script_aligner.py`: 5 passed
- `tests/test_script_normalizer.py`: 5 passed
- `tests/test_script_to_srt_pipeline.py`: 4 passed
- `tests/test_subtitle_segmenter.py`: 4 passed
- `tests/test_srt_generator.py`: 3 passed
- **Total:** 29 passed in 0.12s (100% PASS, 0 failures, 0 regressions).

---

## 3. QA & SIGN-OFF

- **Backward Compatibility:** Verified. All existing tests pass without modification.
- **Strict Invariants:** Original text verbatim preservation guaranteed; no Whisper text substitution.
- **Sign-off:** Milestone M0-A is ratified and ready for merge into baseline.
