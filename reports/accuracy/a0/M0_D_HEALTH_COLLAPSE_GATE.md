# ACCURACY PHASE A0 — MILESTONE REPORT M0-D: REGION HEALTH & COLLAPSE GATE
**Subsystem:** Pre-SRT Collapse Detection & Quality Gate (`apps/capcut-v2/core/subtitles/collapse_detector.py`)  
**Milestone:** M0-D  
**Branch:** `feat/a0-m0-d-health-collapse-gate`  
**Status:** COMPLETED & VERIFIED  
**Date:** September 8, 2026  

---

## 1. OBJECTIVE & DELIVERABLES

Milestone M0-D implements the deterministic quality gate that protects the timeline against silent alignment collapse, ensuring that micro-cue cascades (such as the audited 44 sentences in 19.6s) can never pass as valid output.

### Deliverables Implemented:
1. **`CollapseDetector` Quality Gate:**
   - Evaluates subtitle cues, token speeds, and region health prior to SRT emission.
   - Enforces language-aware reading speed limits:
     - Primary metric: `hard_token_rate = 5.0` tokens/second.
     - Secondary metric: `hard_cps_ko = 22.0` cps (Korean), `hard_cps_default = 26.0` cps (Vietnamese / English).

2. **Micro-Cue Clustering Detection:**
   - Detects clusters where $> 4$ cues of duration $< 0.40\text{s}$ appear inside any $5.0\text{s}$ window.
   - Flags rapid-flicker anomalies before video scenes are generated.

3. **Critical 44-Sentence Tail Collapse Signature:**
   - Dedicated inspection of timeline tails: detects if $> 15$ cues are crammed into the final $20\text{s}$ at rates exceeding the hard limit.
   - Permanent regression guard prevents the audited 25:00–29:47 disaster from ever recurring.

4. **`allow_degraded` Policy:**
   - If `allow_degraded=False` (standard interactive & export flow): raises `AlignmentCollapseError`, preventing draft corruption.
   - If `allow_degraded=True` (batch unattended processing): flags `has_collapse=True`, records exact violation coordinates, marks affected regions as degraded, and prevents visual shot slicing.

---

## 2. VERIFICATION & TEST RESULTS

Automated unit tests were implemented in `tests/test_a0_collapse_detector.py`:
- `test_clean_cues_pass_collapse_detector`: Verified clean narrative cues pass with zero violations.
- `test_critical_regression_44_sentences_in_19s`: Verified that 44 sentences in 19.6s reliably triggers `AlignmentCollapseError` with exact tail collapse violation.
- `test_micro_cue_cluster_detection`: Verified $> 4$ micro-cues in 5.0s is detected as cluster violation.
- `test_sustained_impossible_speed_detection`: Verified sustained reading speed $> 5.0$ tokens/sec across 3 cues is caught.
- `test_collapsed_region_flag_triggers_error`: Verified aligner region status "COLLAPSED" triggers gate rejection.

### Test Run Output:
```bash
PYTHONPATH="apps/capcut-v2" pytest tests/test_a0_collapse_detector.py tests/test_a0_hierarchical_aligner.py ...
============================== 44 passed in 0.18s ==============================
```

---

## 3. QA & SIGN-OFF

- **Regression Guard:** Verified. 44 sentences in 19.6s is mathematically rejected.
- **Micro-Cue Gate:** Active and verified.
- **Sign-off:** Milestone M0-D is ratified and ready for merge into baseline.
