# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A0 ARCHITECTURE FREEZE (RATIFIED)
**Subsystem:** Forced Alignment & Subtitle-Scene Synchronization Engine (`apps/capcut-v2/core/subtitles`, `apps/capcut-v2/core/srt_timeline.py`)  
**Document Type:** Formal Corrective Architecture Freeze Specification  
**Source of Truth:** `reports/accuracy/2TOOLNE_AUTOEDIT_ACCURACY_DEEP_RESEARCH.md`  
**Target Failures Addressed:** `ERR-A0-01` (Long-Form Alignment Collapse), `ERR-A0-02` (Paragraph ↔ Cue 1:1 Mapping Fallacy)  
**Execution Mode:** Final Architecture Consistency Correction (Zero Product Code Modification)  
**Status:** FROZEN, CONSISTENT & RATIFIED  
**Date:** September 8, 2026  

---

## 1. EXECUTIVE FREEZE CHARTER

This document establishes the ratified architectural specification for **Accuracy Phase A0** of **2TOOLNE AutoEdit V2**.

### 1.1 Objective & Boundary Guardrails
- **Primary Objective:** Eliminate long-form cascading alignment collapse (`ERR-A0-01`) and paragraph-to-cue scene desynchronization (`ERR-A0-02`) by establishing mathematically sound acoustic and textual alignment truth.
- **Strictly Deferred to Later Phases:**
  - `VisualShotPlanner` is **DEFERRED** (Phase A1).
  - Ken Burns motion duration scaling is **DEFERRED** (Phase A1).
  - Timeline frame quantization is **DEFERRED** (Phase A2).
- **Core Principle:** Downstream visual pacing problems (such as the 147 clips < 0.5s in `2toolne_1788804879_test_1`) are predominantly symptoms of corrupted alignment truth after minute 25:00. Timing truth must be solved and frozen first before visual pacing heuristics are designed.

---

## 2. FROZEN GOOD INVARIANTS

The following properties of 2TOOLNE AutoEdit V2 are verified as sound and are frozen against any modification:

```
+-----------------------------------------------------------------------------------------------+
|                                    FROZEN GOOD INVARIANTS                                     |
+===============================================================================================+
| 1. FORCED_ALIGNMENT_EMITTED_TEXT_SOURCE = ORIGINAL_SCRIPT_ONLY                                |
|    - Every emitted subtitle character must originate strictly from original script content.   |
|    - SCRIPT_TEXT_MUTATION_RATE = 0% (whisper text NEVER replaces or rewrites user wording).    |
|    - SCRIPT COVERAGE != 100% when speech objectively omits script text.                      |
|      Unspoken text is captured in AlignmentResult.unmatched_script_spans (SCRIPT_OMITTED,    |
|      SCRIPT_TAIL_UNSPOKEN) rather than receiving fabricated timestamps.                       |
+-----------------------------------------------------------------------------------------------+
| 2. VISUAL_TIMELINE_END == MASTER_AUDIO_END                                                    |
|    - Final timeline duration error against master audio = 0.0 us (0.0 ms).                    |
|    - Visual cuts extend to complete audio length.                                             |
|    - SUBTITLE_END_INVARIANT: subtitle.end <= audio.end. Cues are NOT forced to stretch to     |
|      audio end if speech ceases earlier (e.g. music outro, silence, or omitted trailing text).|
+-----------------------------------------------------------------------------------------------+
| 3. TIMELINE GEOMETRIC INTEGRITY                                                               |
|    - Visual gaps = 0 us (no black frames between cuts).                                       |
|    - Visual overlaps = 0 us (no colliding video tracks).                                      |
|    - Subtitle overlaps = 0 us (no simultaneously active narrative cues).                      |
+-----------------------------------------------------------------------------------------------+
| 4. DRAFT SERIALIZATION COMPATIBILITY                                                          |
|    - Output schema remains 100% compliant with CapCut desktop draft_info.json format.         |
|    - Natural media ordering (numerical 4-digit sort) preserved.                               |
+-----------------------------------------------------------------------------------------------+
```

---

## 3. ROOT CAUSE FREEZE: ERR-A0-01 & ERR-A0-02

### 3.1 Root Cause ERR-A0-01: Long-Form Forced Alignment Collapse
- **Legacy Architecture:** `ScriptAligner` (`apps/capcut-v2/core/subtitles/script_aligner.py`) operates as a single-pass, whole-document, forward-only monotonic greedy search with a fixed lookahead window (`max_lookahead = 40` ASR tokens).
- **Failure Mechanism:**
  1. In long-form audio (30–60 minutes), local acoustic mismatches inevitably occur (extended speaker pauses > 5s, unscripted speaker improvisation, speech dropouts, or Whisper ASR omission > 40 tokens).
  2. When script token $S_i$ cannot find a match in the 40-word lookahead, the search pointer $j$ does not advance. Subsequent script tokens accidentally match low-entropy common words (stopwords) far ahead, or the pointer lags behind real audio time.
  3. Because the aligner possesses **zero intermediate anchors** and **no global resynchronization**, a single local failure triggers permanent, irreversible drift for all subsequent minutes.
  4. In Pass 2 (`gap_interpolation`), unaligned tokens are linearly spaced across $(t_{end} - t_{start})$. When the ASR word list is exhausted towards the end of the timeline, the remaining audio tail (e.g. 19.6s in `2toolne_1788804879_test_1`) is forcibly populated with hundreds of remaining script tokens.
  5. **Empirical Disaster:** 44 narrative sentences were compressed into 19.6 seconds (durations 0.15s–0.35s, reading speed > 50 words/sec, generating 273 micro-cues).

### 3.2 Root Cause ERR-A0-02: Paragraph ↔ Cue 1:1 Mapping Fallacy
- **Legacy Architecture:** `compute_script_paragraphs_scene_boundaries` (`apps/capcut-v2/core/srt_timeline.py`, lines 203–222) computes:
  ```python
  paragraph_line_counts = [len(p.split('\n')) for p in paragraphs]
  for count in paragraph_line_counts:
      for _ in range(count):
          p_subs.append(subtitles[sub_idx])
          sub_idx += 1
  ```
- **Failure Mechanism:**
  - The function naively equates 1 script line to 1 subtitle cue.
  - In practice, `SubtitleSegmenter` breaks long script lines into multiple cues based on syntax, word limits (12 words), or duration limits (5.0s).
  - The moment *any* script line splits into 2 or more cues, `sub_idx` desynchronizes from paragraph boundaries. Paragraph 2 receives subtitles belonging to Paragraph 1; Paragraph 5 receives subtitles from Paragraph 3; and the remaining subtitle cues are dumped into an orphaned final scene.

---

## 4. HIERARCHICAL ALIGNMENT ARCHITECTURE (`HierarchicalScriptAligner`)

The whole-document greedy forward loop is formally **REJECTED** and replaced by `HierarchicalScriptAligner`.

```
========================================================================================
                        HIERARCHICAL ALIGNMENT ENGINE PIPELINE
========================================================================================

                 +---------------------------------------------+
                 | Master Script Text  +  Audio ASR Timestamps |
                 +---------------------------------------------+
                                        |
                                        v
                 +---------------------------------------------+
                 | Phase 1: Immutable Document Tokenization    |
                 | (ScriptToken[] + ASRWordTimestamp[])         |
                 +---------------------------------------------+
                                        |
                                        v
                 +---------------------------------------------+
                 | Phase 2: Anchor Discovery & Validation      |
                 | (N-gram match + Neighborhood consistency)   |
                 +---------------------------------------------+
                                        |
                                        v
                 +---------------------------------------------+
                 | Phase 3: Monotonic Anchor Chain (LIS / DP)  |
                 | (Form strictly increasing temporal chain)   |
                 +---------------------------------------------+
                                        |
                                        v
                 +---------------------------------------------+
                 | Phase 4: Bounded Region Partitioning        |
                 | [Anchor_k  -->  Bounded Span  --> Anchor_k+1]|
                 +---------------------------------------------+
                                        |
                                        v
                 +---------------------------------------------+
                 | Phase 5: Local Alignment with Adaptive Band |
                 | (Banded NW -> Auto-Widen -> Full DP Fallback|
                 +---------------------------------------------+
                                        |
                                        v
                 +---------------------------------------------+
                 | Phase 6: Diagnostic & Collapse Detector     |
                 | (Pre-SRT gate: flag/abort impossible rates) |
                 +---------------------------------------------+
                                        |
                                        v
                 +---------------------------------------------+
                 | Phase 7: SRT Segmentation with Source Spans |
                 | (Cues preserve ScriptToken & Paragraph IDs) |
                 +---------------------------------------------+
```

---

## 5. DOCUMENT REPRESENTATION SPECIFICATION

Both script text and ASR speech timestamps must be mapped into immutable, strongly-typed structures preserving complete structural and probabilistic context.

### 5.1 `ScriptToken` Data Structure
```python
@dataclass(frozen=True)
class ScriptToken:
    original_index: int           # Zero-based token sequence index
    raw_text: str                 # Verbatim original script substring
    normalized_text: str          # Canonical NFC, lowercased, stripped of punct
    char_start: int               # Byte/char start offset in raw script
    char_end: int                 # Byte/char end offset in raw script
    paragraph_id: int             # Zero-based paragraph sequence index (separated by \n\s*\n)
    sentence_id: int              # Zero-based sentence index within document
    clause_id: int                # Zero-based clause index within sentence
    leading_whitespace: str       # Exact whitespace preceding token
    trailing_punctuation: str     # Verbatim trailing punctuation (.,!?:;—)
    is_sentence_break: bool       # True if trailing punct is in {., ?, !, …}
    is_clause_break: bool         # True if trailing punct is in {,, ;, :, —}
```

### 5.2 `ASRWordTimestamp` Data Structure
```python
@dataclass(frozen=True)
class ASRWordTimestamp:
    original_index: int           # Zero-based ASR word index
    raw_word: str                 # Exact word as transcribed by Whisper
    normalized_text: str          # Matching normalization format
    start_s: float                # Acoustic onset in seconds
    end_s: float                  # Acoustic offset in seconds
    probability: float            # Whisper token probability (p in [0.0, 1.0])
    segment_id: int               # Whisper segment index
```
*Rule: `probability` must NEVER be discarded.*

---

## 6. ANCHOR DISCOVERY, VALIDATION & UNIQUENESS MODEL

### 6.1 Anchor Candidate Generation
Anchors are deterministic, multi-token sequences that match identically between the normalized script and the normalized ASR stream.
- **Candidate Lengths:** Multi-word N-grams with $N \in \{3, 4, 5\}$.
- **Primary Match Criterion:** Exact normalized string match across all $N$ tokens:
  $$\bigwedge_{i=0}^{N-1} \left( \text{ScriptToken}[s+i].\text{normalized} == \text{ASRWord}[a+i].\text{normalized} \right)$$

### 6.2 Anchor Disqualification Rules
A candidate N-gram is disqualified from becoming an anchor if:
1. **Stopword Only:** All $N$ tokens belong to the language-specific closed-class stopword set (e.g., "and in the", "và sau đó", "그리고 그는").
2. **Low Acoustic Probability:** Average Whisper probability across the N-gram is below 0.80:
   $$\bar{P}_{asr} = \frac{1}{N} \sum_{i=0}^{N-1} \text{ASRWord}[a+i].\text{probability} < 0.80$$
3. **Punctuation Contradiction:** Candidate crosses a strong paragraph break (`paragraph_id` differs across script tokens).

### 6.3 Anchor Neighborhood Consistency Validation
A candidate anchor is not trusted merely because 3+ words match with high probability. A high-scoring candidate can be an accidental match in repetitive dialogue or lyrics.
- **Preceding & Following Context Inspection:**
  For candidate anchor $A$ matching script tokens $[s, s+N-1]$ to ASR words $[a, a+N-1]$, evaluate the immediate surrounding context window (2 tokens before and 2 tokens after):
  $$S_{context}(A) = \frac{1}{4} \left( \text{sim}(s-1, a-1) + \text{sim}(s-2, a-2) + \text{sim}(s+N, a+N) + \text{sim}(s+N+1, a+N+1) \right)$$
- If $S_{context}(A) < 0.25$ and the N-gram is not uniquely isolated by surrounding silence, the anchor is downgraded or rejected.
- **Measurable Anchor Properties:**
  Every candidate anchor stores:
  - `anchor_score`: composite intrinsic score $[0.0, 1.0]$.
  - `anchor_context_score`: neighborhood consistency score $[0.0, 1.0]$.
  - `anchor_timestamp_confidence`: derived from Whisper probabilities $[0.0, 1.0]$.
  - `anchor_timestamp_uncertainty_ms`: acoustic boundary variance estimate (typically $\pm 20\text{ms}$ to $\pm 100\text{ms}$ based on ASR frame step).

### 6.4 Uniqueness Scoring
- Let $F_{script}$ be the total frequency of the N-gram across the entire script.
- Let $F_{asr}$ be the total frequency of the N-gram across the entire ASR word sequence.
- **Uniqueness Factor:**
  $$U(N\text{-gram}) = \frac{1}{F_{script} \cdot F_{asr}}$$
  - If $F_{script} > 1$ or $F_{asr} > 1$, $U \le 0.5$, penalizing the candidate.
  - If $F_{script} > 3$ (e.g. repeated refrains), candidate is rejected unless strongly supported by neighborhood context ($S_{context} \ge 0.85$).

---

## 7. MONOTONIC ANCHOR CHAIN ALGORITHM

From the validated candidate anchor set, a strictly monotonic anchor chain is constructed.

### 7.1 Monotonicity Invariants
Let anchor $A_k$ cover script tokens $[s_{start}^k, s_{end}^k]$ and audio time $[t_{start}^k, t_{end}^k]$. For any adjacent anchor pair $(A_k, A_{k+1})$ in the chain:
1. **Script Monotonicity:** $s_{start}^{k+1} > s_{end}^k$ (strict forward progress in script).
2. **Audio Monotonicity:** $t_{start}^{k+1} > t_{end}^k$ (strict forward progress in time).
3. **No Crossing:** Anchor paths can never cross ($s_{start}^{k+1} > s_{start}^k \iff t_{start}^{k+1} > t_{start}^k$).

### 7.2 Optimal Chain Selection via Weighted LIS / DP
Candidate selection is formulated as finding the maximum-weight path in a Directed Acyclic Graph (DAG) using Dynamic Programming:
- **Vertices:** Validated anchor candidates $A_i$ plus virtual `START` and `END` sentinels.
- **Vertex Weight:** $W(A_i) = S_{anchor}(A_i) \times S_{context}(A_i) \times \text{token\_count}(A_i)$.
- **Valid Directed Edge $(A_i \to A_j)$ exists if and only if:**
  $$s_{end}^i < s_{start}^j \quad \text{AND} \quad t_{end}^i < t_{start}^j \quad \text{AND} \quad \text{PlausibleRate}(A_i, A_j)$$
- **Anchor Edge Plausibility Guard (`ANCHOR_EDGE_PLAUSIBILITY_LIMIT`):**
  This is a broad boundary filter to eliminate absurd candidate jumps.
  $$\text{EffectiveTokenSpeed} = \frac{s_{start}^j - s_{end}^i}{t_{start}^j - t_{end}^i}$$
  If $\text{EffectiveTokenSpeed} < 0.2\text{ tokens/sec}$ (unrealistically long hold $> 90\text{s}$ without speech) or $\text{EffectiveTokenSpeed} > 8.0\text{ tokens/sec}$ ($\approx 40\text{ cps}$, clearly impossible sustained speech rate), edge is pruned.
- **Edge Transition Cost:**
  $$\text{Cost}(A_i, A_j) = \lambda \cdot \left| \frac{t_{start}^j - t_{end}^i}{T_{audio}} - \frac{s_{start}^j - s_{end}^i}{N_{script}} \right|$$
- **DP Recurrence:**
  $$\text{Score}(j) = W(A_j) + \max_{i < j, \text{valid}(i, j)} \left( \text{Score}(i) - \text{Cost}(A_i, A_j) \right)$$

---

## 8. BOUNDED ALIGNMENT REGIONS & DRIFT PROPAGATION INVARIANT

The established anchor chain partitions the entire document into independent, bounded regions:

$$\text{START} \xrightarrow{\text{Region } 0} A_1 \xrightarrow{\text{Region } 1} A_2 \xrightarrow{} \dots \xrightarrow{\text{Region } M} \text{END}$$

### 8.1 Drift Propagation Boundary Invariant
- **INVARIANT:** `LOCAL_ALIGNMENT_ERROR_PROPAGATION = CONFINED_TO_CURRENT_BOUNDED_REGION`
- **REQUIRED RATIFIED FORMULATION:**
  $$\text{DRIFT\_PROPAGATION\_BEYOND\_NEXT\_TRUSTED\_ANCHOR} = 0$$
- **Clarification of Acoustic Uncertainty:**
  Whisper-derived anchor timestamps contain an acoustic boundary uncertainty of $\pm \sigma_{anchor}$ (recorded as `anchor_timestamp_uncertainty_ms`, typically $20\text{–}60\text{ms}$).
  The architecture does **NOT** claim $0.0\text{ ms}$ absolute real-world acoustic ground-truth error.
  Rather, it guarantees that **algorithmic alignment failure inside Region $k$ cannot alter the alignment mapping or timestamps of Region $k+1$ and subsequent regions**.

---

## 9. LOCAL ALIGNMENT ALGORITHM, ADAPTIVE BANDING & FULL DP FALLBACK

Within each bounded region $k$, local sequence alignment maps script tokens $S^{(k)}$ against ASR words in $T^{(k)}$.

### 9.1 Adaptive Band Widening Policy
A naive static band $W = \max(12, 2 \cdot ||S| - |A||)$ fails when balanced insertions and deletions occur (e.g. speaker omits 15 words and improvises 15 words; $|S| - |A| \approx 0$, but the optimal DP path drifts $> 15$ cells away from the diagonal).

- **Initial Band Width:**
  $$W_0 = \max \left( 14, \; 2 \cdot ||S^{(k)}| - |A^{(k)}|| + \lceil 0.15 \cdot |S^{(k)}| \rceil \right)$$
- **Band Saturation Detector:**
  During DP traceback, if the optimal alignment path ever touches the outer boundary of the band ($j - i = \pm W/2$), the band is flagged as **saturated**.
- **Widening Procedure:**
  1. Automatically widen band: $W_{new} = W_{current} \times 2$.
  2. Recompute DP within the region.
  3. If band saturates a second time OR if $W_{new} \ge \min(|S^{(k)}|, |A^{(k)}|)$, immediately escalate to **Full DP Fallback**.

### 9.2 Full Needleman-Wunsch DP Fallback
Accuracy takes absolute precedence over aligner microsecond speed:
$$\text{ACCURACY} > \text{ALIGNER SPEED}$$
- For suspicious regions where:
  - Band repeatedly saturates, OR
  - Anchor neighborhood confidence is low ($< 0.65$), OR
  - Script omission / ASR insertion imbalance is high,
  the aligner automatically executes a full unbanded $O(|S^{(k)}| \cdot |A^{(k)}|)$ Needleman-Wunsch DP.
- Since bounded regions are small, full DP consumes $< 2\text{ MB}$ of transient RAM and executes in $< 15\text{ ms}$, completely eliminating clipping artifacts.

### 9.3 Local Cost Matrix Specification
Let $s$ be a script token and $a$ be an ASR word timestamp:

```
+-------------------+-------------------------------------------------------------------+
| Operation         | Cost / Score Formula                                              |
+===================+===================================================================+
| Exact Match       | +2.0 * (0.6 + 0.4 * a.probability)                                 |
| (norm_s == norm_a)|                                                                   |
+-------------------+-------------------------------------------------------------------+
| High-Fuzzy Match  | +1.5 * similarity * (0.5 + 0.5 * a.probability)                   |
| (sim >= 0.85)     |                                                                   |
+-------------------+-------------------------------------------------------------------+
| Weak-Fuzzy Match  | +0.5 * similarity * a.probability                                 |
| (0.70 <= sim <0.85)| (Rejected if len(s.raw_text) <= 2 to protect short particles)     |
+-------------------+-------------------------------------------------------------------+
| Script Omission   | -1.5 (Speaker skipped a script word; advance script, hold ASR)     |
+-------------------+-------------------------------------------------------------------+
| ASR Insertion     | -1.2 (Speaker improvised unscripted words; advance ASR, hold script)|
+-------------------+-------------------------------------------------------------------+
| Substitution      | -0.8 to -2.0 (depending on phonetic/character distance)           |
+-------------------+-------------------------------------------------------------------+
```

---

## 10. REGION SIZE BOUNDS & WORKLOAD METRICS

Region token counts must not be constrained by artificial static ceilings.
At normal conversational cadence:
- At a 30-second anchor spacing: $\approx 60\text{–}90$ script tokens.
- At a 90-second anchor spacing: $\approx 180\text{–}270$ script tokens.

### 10.1 Workload Characterization by Language
```
+---------------+-------------------+----------------------+----------------------+
| Language      | Typical Word Rate | 30s Span Token Count | 90s Span Token Count |
+===============+===================+======================+======================+
| Vietnamese    | 2.5 - 3.8 wps     | 75 - 114 tokens      | 225 - 342 tokens     |
+---------------+-------------------+----------------------+----------------------+
| Korean        | 2.0 - 3.2 wps     | 60 - 96 tokens       | 180 - 288 tokens     |
+---------------+-------------------+----------------------+----------------------+
| English       | 2.3 - 3.5 wps     | 70 - 105 tokens      | 210 - 315 tokens     |
+---------------+-------------------+----------------------+----------------------+
```

### 10.2 Guard Limits
- **`MAX_REGION_TOKEN_GUARD` = 400 tokens.**
  If anchor discovery leaves a gap exceeding 400 tokens ($\approx 2\text{ minutes}$ of speech), the region is partitioned by secondary acoustic pause anchors (VAD silence $\ge 1.2\text{s}$) before running local DP, preventing quadratic matrix explosion.

---

## 11. ACOUSTIC SILENCE & VAD SUPPORT

Faster-Whisper (`faster_whisper.WhisperModel`) is executed with `vad_filter=True` in `apps/capcut-v2/core/subtitles/speech_timestamp_provider.py`.
- **Acoustic Silence Signal:** Inter-word silence duration $\Delta t = a_{j+1}.start - a_j.end$.
- **Role in Alignment:** Silence is supporting acoustic evidence, never sole truth.
  - $\Delta t \ge 500\text{ ms}$: Supporting evidence for clause break (comma).
  - $\Delta t \ge 800\text{ ms}$: Supporting evidence for sentence break (period).
  - $\Delta t \ge 1200\text{ ms}$: Secondary anchor candidate to split large regions.
- Unaligned tokens must NOT be interpolated across an acoustic silence gap $\ge 1.0\text{ s}$.

---

## 12. DUAL-METRIC READING SPEED & THRESHOLD RECONCILIATION

Raw characters-per-second (CPS) is not directly comparable across different scripts (Korean syllabic blocks, English Latin words, Vietnamese diacritic words). The architecture establishes a **Dual-Metric Reading Speed Model**.

### 12.1 Primary & Secondary Speed Metrics
1. **Primary Metric (Cross-Language):**
   $$\text{Speed}_{token} = \frac{\text{word / token count}}{\text{duration in seconds}} \quad (\text{tokens / second})$$
2. **Secondary Metric (Language-Aware):**
   $$\text{Speed}_{cps} = \frac{\text{character count}}{\text{duration in seconds}} \quad (\text{chars / second})$$

### 12.2 Reconciled Speed Threshold Semantics
The previously disparate numbers are reconciled into clear semantic tiers:

```
+------------------------------------+--------------------------+-----------------------+---------------------------------------+
| Threshold Tier                     | Primary Token Rate       | Secondary CPS (Lang)  | Operational Meaning                   |
+====================================+==========================+=======================+=======================================+
| TARGET_READING_SPEED               | 2.0 - 3.2 tokens/sec     | Ko: 8 - 14 cps        | Optimal narrative pacing; subtitle    |
|                                    |                          | Vi/En: 12 - 18 cps    | comfortably readable.                 |
+------------------------------------+--------------------------+-----------------------+---------------------------------------+
| SOFT_WARNING_LIMIT                 | > 3.8 tokens/sec         | Ko: > 17 cps          | Non-fatal warning in UI; cue readable |
|                                    |                          | Vi/En: > 21 cps       | but hurried.                          |
+------------------------------------+--------------------------+-----------------------+---------------------------------------+
| HARD_VALIDATION_LIMIT              | > 5.0 tokens/sec         | Ko: > 22 cps          | Collapse trigger. Impossible to read. |
| (COLLAPSE TRIGGER)                 |                          | Vi/En: > 26 cps       | Flagged as COLLAPSED; rejected.       |
+------------------------------------+--------------------------+-----------------------+---------------------------------------+
| ANCHOR_EDGE_PLAUSIBILITY_LIMIT     | 0.2 - 8.0 tokens/sec     | 1.0 - 38.0 cps        | Broad boundary filter for LIS anchor  |
|                                    |                          |                       | candidate graph pruning only.         |
+------------------------------------+--------------------------+-----------------------+---------------------------------------+
```

---

## 13. CONFIDENCE MODEL, UNMATCHED SPANS & HEALTH DIAGNOSTICS

Averaging all tokens into a single global score (e.g. "92%") can mask a fatal local collapse. Confidence and coverage are strictly separated.

### 13.1 Measurable Granular Alignment Metrics
Every `AlignmentResult` exposes:
- `matched_token_ratio`: $\frac{N_{exact} + N_{fuzzy}}{N_{script\_total}}$
- `exact_match_ratio`: $\frac{N_{exact}}{N_{script\_total}}$
- `fuzzy_match_ratio`: $\frac{N_{fuzzy}}{N_{script\_total}}$
- `interpolated_ratio`: $\frac{N_{interpolated}}{N_{script\_total}}$
- `omitted_script_ratio`: $\frac{N_{omitted}}{N_{script\_total}}$
- `asr_insertion_ratio`: $\frac{N_{inserted}}{N_{asr\_total}}$
- `anchor_coverage_ratio`: fraction of document spanned between verified anchors.
- `region_health_list`: list of `RegionHealth` objects for every bounded region.

### 13.2 Document-Level Pass Invariant
$$\text{DOCUMENT\_PASS} \iff \left( \forall r \in \text{Regions}: r.\text{health\_status} \neq \text{"COLLAPSED"} \right)$$
A document with 95% global score that contains a single `COLLAPSED` region is marked **FAILED / NEEDS_REVIEW**.

### 13.3 Unmatched Script Spans Representation
When portions of the script are not spoken in audio, they are captured in:
`AlignmentResult.unmatched_script_spans: List[UnmatchedScriptSpan]`
```python
@dataclass
class UnmatchedScriptSpan:
    span_id: int
    char_start: int
    char_end: int
    token_start: int
    token_end: int
    text: str
    reason: str  # "SCRIPT_OMITTED" | "SCRIPT_TAIL_UNSPOKEN" | "ACOUSTIC_DROPOUT"
```
*Rule: Unspoken script spans NEVER receive fabricated timestamps.*

---

## 14. COLLAPSE DETECTOR & PRE-SRT VALIDATION GATE

Before subtitle cues or CapCut drafts are emitted, the `CollapseDetector` executes a deterministic pre-emission inspection.

### 14.1 Collapse Detection Rules
A region or timeline segment is flagged as `COLLAPSED` if any of the following conditions evaluate to `True`:
1. **Reading Speed Anomaly:** $\text{Speed}_{token} > \text{HARD\_VALIDATION\_LIMIT}$ across any span $\ge 3$ consecutive cues.
2. **Micro-Cue Cluster:** More than 4 subtitle cues having individual durations $< 0.40\text{ s}$ within any 5.0-second window.
3. **Excessive Interpolation Ratio:** More than $35\%$ of tokens in a window $> 30\text{ s}$ are unanchored `INTERPOLATED`.
4. **Anchor Spacing Divergence:** The ratio between audio duration $\Delta t$ and expected script reading time $\Delta s / \text{speed}_{ref}$ exceeds a factor of 3.5.

### 14.2 Collapse Action Policy
- A collapse must **NEVER** silently proceed as valid high-confidence output.
- When collapse is detected:
  1. Pipeline raises `AlignmentCollapseError` with exact region coordinates and metrics.
  2. In batch mode (`allow_degraded=True`), the collapsed region is marked `ConfidenceLevel.UNMATCHED`, flagged for review, and prevented from splitting visual scenes into micro-cuts.

---

## 15. TRAILING CONTENT & NON-ACOUSTIC END SENTINEL POLICY

The conceptual `END` sentinel is located at $(S_{total}, T_{audio})$.  
**CRITICAL CORRECTION:** `END` is a document boundary, **NOT a trusted acoustic anchor**. It possesses zero acoustic certainty.

### 15.1 Guarded Tail Alignment
- If no trusted N-gram anchor exists in the final 60 seconds of audio, the tail is treated as an unanchored boundary requiring stricter health checks.
- Minimum required duration for remaining script $\Delta S = S_{total} - s_{last\_anchor}$:
  $$T_{min\_req} = \frac{|\Delta S|}{\text{HARD\_VALIDATION\_LIMIT}}$$
- **Tail Policy Branches:**
  1. **Case A ($T_{audio} - t_{last} \ge T_{min\_req}$ and speech detected):**
     Run guarded tail alignment.
  2. **Case B ($T_{audio} - t_{last} < T_{min\_req}$):**
     **Mathematically impossible tail.** The audio file ended before the script was finished being spoken.
     - **Action:** DO NOT compress text into the audio tail.
     - Align only what fits at healthy reading speed ($\le \text{TARGET\_READING\_SPEED}$).
     - Mark remaining script tokens as `SCRIPT_TAIL_UNSPOKEN` (`ConfidenceLevel.UNMATCHED`).
     - Subtitle track ends when speech ends (`subtitle.end <= audio.end`).
     - Visual timeline remains extended to `audio.end` (`VISUAL_TIMELINE_END == MASTER_AUDIO_END`).

---

## 16. PARAGRAPH MAPPING SPECIFICATION (A0-02 RESOLUTION)

### 16.1 Source-Span Cue Model
Every generated subtitle cue retains explicit textual source span metadata:
```python
@dataclass
class SubtitleCue:
    index: int
    start_s: float
    end_s: float
    text: str
    confidence: ConfidenceLevel
    tokens: List[AlignedToken]
    # A0-02 Source Span Metadata
    source_token_start: int       # First ScriptToken.original_index
    source_token_end: int         # Last ScriptToken.original_index
    paragraph_ids: List[int]      # Unique paragraph_id(s) of covered tokens
    sentence_ids: List[int]       # Unique sentence_id(s) of covered tokens
    alignment_confidence: float   # True mathematical confidence [0.0, 1.0]
```

### 16.2 Paragraph Scene Boundary Derivation (`compute_script_paragraphs_scene_boundaries_v2`)
In `apps/capcut-v2/core/srt_timeline.py`, `compute_script_paragraphs_scene_boundaries_v2` groups cues by **source token paragraph membership** rather than line counts:
- All cues where `tokens[*].paragraph_id == P` belong to Scene $P$.
- If a single paragraph splits into 4 subtitle cues, all 4 cues remain grouped into Scene $P$.
- Scene $P$ start = Cue[0].start_us; Scene $P$ end = Cue[-1].end_us.
- Zero desynchronization between paragraphs and scenes.

### 16.3 Single-Paragraph Cue Invariant
- **FROZEN INVARIANT:** `ONE SUBTITLE CUE MAY NOT CROSS A STRONG PARAGRAPH BOUNDARY`.
- In `SubtitleSegmenter`:
  $$\text{should\_break} = \text{True} \quad \text{whenever } \text{token}[i].\text{paragraph\_id} \neq \text{token}[i+1].\text{paragraph\_id}$$
- A cue never spans across double newlines (`\n\s*\n`). Every cue belongs to exactly one paragraph.

---

## 17. DEFERRED SUBSYSTEMS JUSTIFICATION

The following subsystems are explicitly held out of Phase A0:

```
+-------------------------------+-----------+-------------------------------------------------------+
| Subsystem                     | Status    | Rationale for Deferral                                 |
+===============================+===========+=======================================================+
| VisualShotPlanner             | DEFERRED  | Over half of all short clips (<0.5s) were downstream  |
|                               | (Phase A1)| of alignment collapse after minute 25:00. Restoring   |
|                               |           | true timing truth must occur before shot planning.    |
+-------------------------------+-----------+-------------------------------------------------------+
| Ken Burns Duration Scaling    | DEFERRED  | Modifying camera motion deltas while shot durations   |
|                               | (Phase A1)| are erratic is futile. Motion will be duration-scaled |
|                               |           | after VisualShotPlanner is implemented.               |
+-------------------------------+-----------+-------------------------------------------------------+
| Timeline Frame Quantization   | DEFERRED  | Final project duration is currently microsecond-exact |
| (Integer 60 FPS snapping)     | (Phase A2)| (0.0 ms error) and has zero gaps. Frame quantization  |
|                               |           | is reserved for Phase A2 hygiene.                     |
+-------------------------------+-----------+-------------------------------------------------------+
```

---

## 18. BENCHMARK CORPUS & ACCEPTANCE GATES BY DATASET CLASS

### 18.1 Benchmark Corpus Matrix
```
+---------------+-------------------+----------------+--------------------+-------------------------+
| Test ID       | Workload Type     | Language       | Audio Duration     | Characteristics         |
+===============+===================+================+====================+=========================+
| SHORT_01      | Clean Narration   | Vietnamese     | 03m 15s            | Professional voiceover  |
+---------------+-------------------+----------------+--------------------+-------------------------+
| SHORT_02      | Clean Dialogue    | Korean         | 04m 42s            | Multi-speaker pauses    |
+---------------+-------------------+----------------+--------------------+-------------------------+
| LONG_01       | Real Production   | Korean         | 29m 47s            | Audited collapse project|
| (AUDITED)     | Narration         |                | (1787.23s)         | (2toolne_1788804879)    |
+---------------+-------------------+----------------+--------------------+-------------------------+
| LONG_02       | Controlled Stress | English / Vi   | 30m 00s            | Synthetic ground-truth  |
+---------------+-------------------+----------------+--------------------+-------------------------+
| LONG_03       | Maximum Stress    | Korean / Multi | 60m 00s            | Extended long-form      |
+---------------+-------------------+----------------+--------------------+-------------------------+
```

### 18.2 Acceptance Gates by Dataset Classification
Distinguishing clean audio from intentional audio-script mismatches:

```
+---------------------------------------+-------------------------------+-------------------------------+
| Quality Metric                        | CLEAN_AUDIO_SCRIPT_MATCH      | ADVERSARIAL / MISMATCH        |
|                                       | (SHORT_01, SHORT_02, LONG_02) | (LONG_01, ADV_01 - ADV_12)    |
+=======================================+===============================+===============================+
| Script Text Mutation Rate             | 0.0% (LOCKED)                 | 0.0% (LOCKED)                 |
+---------------------------------------+-------------------------------+-------------------------------+
| Matched Token Ratio                   | >= 88.0%                      | Classified per omitted spans  |
+---------------------------------------+-------------------------------+-------------------------------+
| Interpolated Token Ratio              | <= 8.0%                       | <= 12.0% (in spoken spans)    |
+---------------------------------------+-------------------------------+-------------------------------+
| Unspoken Text Representation          | 0 spans                       | Explicitly recorded in        |
|                                       |                               | unmatched_script_spans        |
+---------------------------------------+-------------------------------+-------------------------------+
| Micro-Cues (< 0.40s)                  | <= 1.0%                       | <= 2.0%                       |
+---------------------------------------+-------------------------------+-------------------------------+
| Reading Speed Violations (> HARD)     | 0.0%                          | 0.0%                          |
+---------------------------------------+-------------------------------+-------------------------------+
| Paragraph Scene Desync                | 0 cues                        | 0 cues                        |
+---------------------------------------+-------------------------------+-------------------------------+
| Catastrophic Tail Compression         | ABSENT (0 cues compressed)    | ABSENT (0 cues compressed)    |
+---------------------------------------+-------------------------------+-------------------------------+
| Collapsed Regions Count               | 0                             | 0                             |
+---------------------------------------+-------------------------------+-------------------------------+
```

### 18.3 LONG_01 Re-Benchmark Requirements (`2toolne_1788804879_test_1`)
1. **Zero Tail Compression:** 44 sentences inside 19.6s is completely eliminated.
2. **Post-Minute-25 Stability:** Window 25:00–29:47 must transition from `COLLAPSED` (292 clips, 273 micro-cues) to `HEALTHY` ($\approx 50\text{–}65$ visual clips).
3. **Unspoken Material:** Unspoken sentences identified in `unmatched_script_spans`.
4. **Paragraph Mapping:** Zero scene desynchronization across all 572 clips.

---

## 19. ROLLOUT ARCHITECTURE: VALIDATION SHADOW MODE & CUTOVER GATE

To ensure absolute safety, the rollout follows a strict two-stage procedure.

### 19.1 Validation Phase (Shadow Mode)
- **Production Configuration:**
  ```python
  active_engine = AlignmentEngineType.LEGACY
  shadow_engine = AlignmentEngineType.HIERARCHICAL_V1
  shadow_comparison = True
  ```
- **Execution:**
  - Legacy `ScriptAligner` produces the active draft output used by the user.
  - `HierarchicalScriptAligner` executes concurrently in the background.
  - Outputs only: `AlignmentResult`, telemetry metrics, and shadow comparison log.
  - Does **NOT** mutate the active user draft.

### 19.2 Production Cutover Gate
Only after benchmark acceptance gates pass 100% on `SHORT_01`, `SHORT_02`, `LONG_01`, `LONG_02`, `LONG_03`, and the 12 adversarial cases:
- Configuration is switched to:
  ```python
  active_engine = AlignmentEngineType.HIERARCHICAL_V1
  shadow_comparison = False
  ```
- `AlignmentEngineType.LEGACY` is retained **strictly as an emergency rollback path**.
- Engine selection is **NOT** exposed in the user-facing UI.

---

## 20. ARCHITECTURAL RATIFICATION SIGN-OFF

All prior contradictions have been eliminated. The corrective architecture for `ERR-A0-01` and `ERR-A0-02` is **FROZEN, CONSISTENT, AND RATIFIED**.

```
A0_ARCHITECTURE_CONSISTENT = YES
IMPLEMENTATION_READY = YES
```

---

## 21. POST-CUTOVER ARCHITECTURAL AMENDMENTS & EMPIRICAL CALIBRATIONS

Pursuant to the Post-Cutover Truth Audit, the following empirical calibrations have been ratified and incorporated into the authoritative baseline:

### 21.1 Anchor Acoustic Confidence Threshold Calibration
- **Prior Specification (Section 6.2):** Disqualify candidate if $\bar{P}_{asr} < 0.80$.
- **Amended Specification:** Disqualify candidate if $\bar{P}_{asr} < 0.75$.
- **Empirical Calibration Evidence:** Forensic evaluation of audited long-form production audio `LONG_01` (`Tập_1.wav`, Korean narration) established that Whisper word-level confidence on emotional dialogue and conversational phrases routinely scores in the $[0.75, 0.80)$ range. The calibrated 0.75 threshold recovered 13 valid emotional and dialogue narrative anchors (yielding 348 total anchors with 0 monotonicity crossings), whereas 0.80 created wider unanchored gaps up to 21.24s.

### 21.2 Repeated Refrain & Uniqueness Policy Reconciliation
- **Prior Specification (Section 6.4):** If $F_{script} > 3$, require $S_{context} \ge 0.85$.
- **Amended Specification:** 
  1. Pure identical repeated refrains ($F_{script} > 4$ and $U < 0.10$) are outright rejected at candidate generation stage.
  2. For ambiguous candidates ($F_{script} > 1$ or $F_{asr} > 1$), verified neighborhood context support of $S_{context} \ge 0.30$ is required.
- **Empirical Calibration Evidence:** Requiring $S_{context} \ge 0.85$ over a 4-token neighborhood demanded near-perfect ASR word accuracy across adjacent boundaries, inappropriately rejecting valid anchors during colloquial speech. An explicit regression test of 15 consecutive identical refrains confirmed that $U < 0.10$ completely prevents false anchor generation (0 false candidates), while $S_{context} \ge 0.30$ provides robust protection against cross-sentence jumping.

### 21.3 Reading Speed Evaluation Semantics: Peak vs Sustained Collapse Rate
- **Prior Specification (Section 12.2 & 14.1):** Hard token rate limit of 5.0 tokens/sec.
- **Amended Specification:**
  1. **Sustained Collapse Rate (Violation Trigger):** Evaluated over a sliding 3-cue window ($W_3 = \frac{\sum \text{tokens}}{\Delta t_{3\text{-cue}}}$). If $W_3 > 5.0\text{ tps}$ (or chars/sec $> 22.0\text{ KO} / 26.0\text{ VI}$), a collapse violation is triggered.
  2. **Transient Peak Rate (Diagnostic Telemetry):** Evaluated on individual single cues ($W_1 = \frac{\text{tokens}}{\Delta t_{\text{cue}}}$). Transient spikes (e.g. 5.88 tps on an isolated <0.35s phrase) are recorded as telemetry but do NOT trigger collapse violations unless sustained across 3 cues or clustered into $>4$ micro-cues within 5.0s.
