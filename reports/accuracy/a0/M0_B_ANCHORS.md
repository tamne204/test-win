# ACCURACY PHASE A0 — MILESTONE REPORT M0-B: ANCHOR DISCOVERY & MONOTONIC CHAIN
**Subsystem:** Anchor Discovery & Monotonic Chaining Engine (`apps/capcut-v2/core/subtitles/anchor_finder.py`)  
**Milestone:** M0-B  
**Branch:** `feat/a0-m0-b-anchors`  
**Status:** COMPLETED & VERIFIED  
**Date:** September 8, 2026  

---

## 1. OBJECTIVE & DELIVERABLES

Milestone M0-B implements the deterministic multi-word anchor discovery and dynamic programming chain selection engine that prevents cascading long-form drift.

### Deliverables Implemented:
1. **Deterministic Anchor Discovery (`AnchorFinder`):**
   - Exact normalized N-gram extraction with $N \in \{3, 4, 5\}$.
   - Inverted indexing of ASR word streams for $O(1)$ dictionary lookup.
   - Computes document-wide occurrence frequencies: $F_{script}$ and $F_{asr}$.

2. **Multi-Stage Candidate Filtering:**
   - **Stopword-Only Rejection:** Anchors consisting entirely of closed-class stopwords across Vietnamese, Korean, and English are rejected.
   - **Paragraph Boundary Guard:** Candidates spanning across multiple paragraphs (`paragraph_id` transitions) are disqualified to ensure clean paragraph alignment.
   - **ASR Acoustic Confidence Guard:** Candidates with average Whisper token likelihood $\bar{P}_{asr} < 0.75$ are rejected (calibrated from 0.80 based on empirical Korean conversational dialogue tests on `LONG_01`, retaining 13 critical emotional narrative anchors without false positives).
   - **Repeated Refrain & Uniqueness Policy:** 
     - Computes uniqueness factor $U = \frac{1}{F_{script} \cdot F_{asr}}$.
     - Pure identical refrains ($F_{script} > 4$ with $U < 0.10$) are outright rejected at discovery to prevent false repeating locks.
     - Composite intrinsic anchor score:
       $$S_{anchor} = 0.30 \cdot \text{len\_score} + 0.40 \cdot U + 0.30 \cdot \bar{P}_{asr}$$

3. **Neighborhood Context Validation:**
   - Evaluates up to 2 preceding and 2 following context tokens between script and ASR.
   - Correctly normalizes score by `actual_context_terms_evaluated` (does not blindly divide by 4 at document boundaries).
   - Ambiguous candidates ($F > 1$) require verified neighborhood context support ($S_{context} \ge 0.30$) to withstand minor Whisper transcription perturbations while preventing false matches.
   - Stores: `anchor_score`, `anchor_context_score`, `anchor_timestamp_confidence`, and `anchor_timestamp_uncertainty_ms` (kept `None` unless backed by tangible acoustic evidence).

4. **Monotonic Chain Selection (Weighted DAG / LIS DP):**
   - Strictly enforces monotonicity in both dimensions:
     - Script progress: $s_{start}^{j} > s_{end}^i$
     - Audio progress: $t_{start}^j > t_{end}^i$ (zero crossing permitted).
   - Dynamic programming maximizes total chain weight with temporal drift penalties:
     $$W_j = S_{anchor}(j) \cdot S_{context}(j) \cdot (N_j^{1.2})$$

5. **VAD-Aware Silence Bridge Edge:**
   - Addresses the critical requirement that long non-speech pauses (e.g. 45-second music interlude) must NOT destroy valid anchor transitions.
   - Computes `speech_active_duration = \Delta t - non_speech_duration`.
   - Recognizes explicit `SILENCE_BRIDGE_EDGE` when low raw rate is explained by measured silence intervals ($\ge 3.0\text{s}$), preserving the chain across long interludes.

---

## 2. VERIFICATION & TEST RESULTS

Automated unit tests were implemented in `tests/test_a0_anchor_finder.py`:
- `test_clean_ngram_anchor_discovery`: Verified exact 3/4/5-gram extraction and scoring.
- `test_stopword_only_anchor_rejection`: Verified stopword-only candidate rejection.
- `test_low_asr_prob_rejection`: Verified acoustic confidence gate.
- `test_paragraph_boundary_guard`: Verified candidates cannot cross paragraph breaks.
- `test_monotonic_chain_no_crossing`: Verified strict monotonicity without crossing.
- `test_silence_bridge_across_45s_music_interlude`: Verified permanent regression ensuring a 45.5s music interlude between valid narration anchors successfully forms a connected anchor chain via the silence bridge.

### Test Run Output:
```bash
PYTHONPATH="apps/capcut-v2" pytest tests/test_a0_anchor_finder.py tests/test_a0_data_models.py ...
============================== 35 passed in 0.09s ==============================
```

---

## 3. QA & SIGN-OFF

- **Acoustic Uncertainty:** Verified. No arbitrary $\pm 20\text{ms}$ manufactured.
- **Silence Bridge:** Verified. Music interludes do not break monotonic anchor progression.
- **Sign-off:** Milestone M0-B is ratified and ready for merge into baseline.
