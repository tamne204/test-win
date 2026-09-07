# MILESTONE A1-M5 REPORT: VISUAL ACCURACY VALIDATOR

- **Milestone:** A1-M5
- **Subsystem:** Deterministic Accuracy Validation Layer
- **Status:** COMPLETED
- **Branch:** `feat/a1-m5-validator`
- **Date:** September 2026

---

### 1. Implemented Components

1. **Deterministic Accuracy Validator (`apps/capcut-v2/core/visual/validator.py`):**
   - `VisualAccuracyValidator`: Audits generated visual shots against continuity, duration, asset, and velocity invariants.
   - Severity Grading: Categorizes issues into `INFO`, `WARNING`, `ERROR`, and `FATAL`.
   - Comprehensive Invariant Rules:
     - `VAL-FATAL-12`: Validates visual timeline starts exactly at $0.000\text{s}$.
     - `VAL-FATAL-11`: Detects missing / empty image paths.
     - `VAL-ERR-01`: Detects micro-shots ($< 2.0\text{s}$).
     - `VAL-WARN-02`: Flags long speech shots ($> 8.5\text{s}$).
     - `VAL-FATAL-06`: Detects positive visual gaps ($t_{\text{start}}[k] > t_{\text{end}}[k-1]$).
     - `VAL-FATAL-07`: Detects negative visual overlaps ($t_{\text{start}}[k] < t_{\text{end}}[k-1]$).
     - `VAL-ERR-03`: Catches consecutive duplicate assets on adjacent shots.
     - `VAL-FATAL-08`: Flags tail black screen dropouts ($t_{\text{end}}[-1] < \text{master\_audio\_duration}$).
     - `VAL-WARN-09`: Flags target velocity warnings ($> 3.5\%/\text{s}$).
     - `VAL-ERR-10`: Detects absolute motion safety violations ($> 5.0\%/\text{s}$).
   - Rejection Gate: A plan with any `ERROR` or `FATAL` issue is marked `is_valid = False` and cannot proceed to production rendering.

---

### 2. Verification
- Fast test profile: 87 passed in 0.46s (`tests/test_visual_validator.py` 4/4 passed).
- Continuity checks (gaps, overlaps, tail blackouts) verified.
- Pacing checks (micro-shots, excessive velocities) verified.
