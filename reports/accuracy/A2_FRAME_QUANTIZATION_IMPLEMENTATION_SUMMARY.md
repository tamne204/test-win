# 2TOOLNE AUTOEDIT V2 — PHASE A2 IMPLEMENTATION SUMMARY
## FRAME-ACCURATE TIMELINE QUANTIZATION

```yaml
PROJECT: 2TOOLNE AutoEdit V2
SUBSYSTEM: Phase A2 Frame-Accurate Timeline Quantization
BRANCH: feat/a2-frame-quantization
BASE COMMIT: 7a39aa9
MODE: Fast-Track Implement -> Test -> Real Project -> Physical CapCut
STATUS: FROZEN_PRODUCTION
VERIFIED_PLATFORM: macOS Darwin 26.1 (Apple Silicon) / CapCut Desktop 9.4.0
DATE: 2026-09-08
```

---

## 1. Executive Summary

Phase A2 delivers **Frame-Accurate Timeline Quantization** for 2TOOLNE AutoEdit V2, closing the gap between sub-millisecond continuous planning decisions and NLE frame grids.

Prior to A2, visual shot cuts and motion keyframes contained sub-frame microsecond fractions (e.g. 217 out of 278 visual cut boundaries on `GOLDEN_LONG_01` were off-grid at 60fps), introducing risks of single-frame black flashes, rounding jitter, and NLE timeline drift.

Under Phase A2:
1. **Absolute Boundary Quantization ($Q(t)$)**: All visual shot boundaries are snapped to the nearest legal frame index using exact rational integer arithmetic. Adjacent clips share bit-for-bit identical boundaries, eliminating cumulative drift, visual gaps, and overlaps ($GAPS = 0, OVERLAPS = 0$).
2. **Deterministic Collision Repair**: A minimum visual shot duration of $\ge 1$ frame is strictly enforced ($ZERO\_FRAME\_SHOTS = 0$).
3. **Master Audio Invariant Preserved**: Master audio duration is authoritative ($VISUAL\_END == MASTER\_AUDIO\_END$, $BLACK\_TAIL = 0.0s$).
4. **A0 Subtitle Truth Frozen**: Subtitle timestamps and texts remain completely untouched ($A0\_SUBTITLE\_HASH\_BEFORE == A0\_SUBTITLE\_HASH\_AFTER$).
5. **Keyframe Alignment & Monotonicity**: Motion keyframe timestamps and velocities are re-quantized to the local clip frame grid ($OFFGRID\_KEYFRAMES = 0$, strict monotonicity).

---

## 2. Core Architecture & Mathematical Specification

### 2.1 Rational Timebase Arithmetic (`FrameTimebase`)
To prevent floating-point accumulation errors across long timelines (e.g. 30-minute videos containing $>100,000$ frames), all frame $\leftrightarrow$ microsecond conversions use exact integer fraction arithmetic:

$$f_{idx} = \left\lfloor \frac{t_{\mu s} \cdot N + \frac{1,000,000 \cdot D}{2}}{1,000,000 \cdot D} \right\rfloor$$

$$t_{\mu s}(f_{idx}) = \left\lfloor \frac{f_{idx} \cdot 1,000,000 \cdot D + \frac{N}{2}}{N} \right\rfloor$$

Where:
- $N$ = Frame rate numerator (e.g. 60, 30, 25, 24, 30000, 60000)
- $D$ = Frame rate denominator (e.g. 1, 1001)

Supported frame rates include 24, 25, 30, 50, 60 fps, and rational NTSC rates (23.976, 29.97, 59.94 fps).

### 2.2 Absolute Boundary Mapping (`FrameQuantizer`)
Quantization is applied to **absolute continuous boundaries** rather than individual clip durations:

$$\text{shot}[k].\text{start\_us} = t_{\mu s}(Q(\text{boundary}[k]))$$
$$\text{shot}[k].\text{end\_us}   = t_{\mu s}(Q(\text{boundary}[k+1]))$$
$$\text{shot}[k].\text{duration\_us} = \text{shot}[k].\text{end\_us} - \text{shot}[k].\text{start\_us}$$

Because $\text{shot}[k].\text{end\_us} \equiv \text{shot}[k+1].\text{start\_us}$, the timeline is guaranteed continuous without gaps or overlaps.

### 2.3 Integrated Audit Layer (`FrameAccuracyValidator`)
Ten automated deterministic checks are enforced:
- `FRAME-ERR-01`: Visual boundary or keyframe off frame grid.
- `FRAME-ERR-02`: Zero-frame or non-positive duration shot.
- `FRAME-ERR-03`: Visual gap between adjacent shots.
- `FRAME-ERR-04`: Visual overlap between adjacent shots.
- `FRAME-ERR-05`: Duplicate keyframe frame offset.
- `FRAME-ERR-06`: Keyframe offset outside clip duration.
- `FRAME-ERR-07`: Non-monotonic keyframe offsets.
- `FRAME-WARN-08`: Quantization boundary error $> 0.5$ frame.
- `FRAME-ERR-09`: Motion velocity violation ($> 5.0\%/s$ absolute, $> 0.8\%/s$ tail, $> 3.5\%/s$ speech).
- `FRAME-FATAL-10`: Timeline start $\neq 0$ or terminal coverage mismatch against master audio.

---

## 3. Real Project Verification: `GOLDEN_LONG_01`

Evaluated on `GOLDEN_LONG_01` (29m 47.233s, 641 A0 subtitle cues, 278 images, 60fps):

| Metric | Before A2 (A1 Frozen) | After A2 (Quantized) | Status |
|---|---|---|---|
| **Total Visual Shots** | 278 | 278 | Preserved (0 change) |
| **Sub-Frame Boundaries** | 217 | 0 | **100% Eliminated** |
| **Off-Grid Boundaries** | 217 | 0 | **0 off-grid** |
| **Sub-Frame Keyframes** | 434 | 0 | **100% Eliminated** |
| **Off-Grid Keyframes** | 434 | 0 | **0 off-grid** |
| **Max Quantization Error** | N/A | $7,780\,\mu\text{s}$ (0.4668 frames) | $\le 0.50$ frames |
| **Quantization Collisions** | N/A | 0 | None |
| **Zero-Frame Shots** | 0 | 0 | Zero |
| **Visual Gaps** | 0 | 0 | Zero |
| **Visual Overlaps** | 0 | 0 | Zero |
| **Black Tail Duration** | 0.0s | 0.0s | Zero |
| **A0 Subtitle Hash** | Frozen A0 | Bit-for-bit identical | **Preserved** |
| **A1 Image Order** | Monotonic 1-278 | Monotonic 1-278 | **Preserved** |
| **Replay Execution Time** | N/A | 4.44 ms | **Real-time** |
| **Frame Validator Outcome** | N/A | `is_valid = True`, 0 errors | **Passed** |

---

## 4. Automated Acceptance Test Matrix

Full automated test suite executed with Python 3.12:

### 4.1 A2 Acceptance Matrix (`tests/test_a2_acceptance_matrix.py`)
- `A2-T01`: 24fps exact boundary quantization — **PASSED**
- `A2-T02`: 25fps quantization (40,000 us grid) — **PASSED**
- `A2-T03`: 30fps quantization (33,333 us grid) — **PASSED**
- `A2-T04`: 60fps quantization (16,667 us grid) — **PASSED**
- `A2-T05`: Rational NTSC 30000/1001 (29.97fps) — **PASSED**
- `A2-T06`: Nearest-frame boundary rounding ($\le 0.5$ frames) — **PASSED**
- `A2-T07`: Boundary collision detection and deterministic repair — **PASSED**
- `A2-T08`: Zero-frame shot prevention and audit gating — **PASSED**
- `A2-T09`: No cumulative drift over 100 sequential shots — **PASSED**
- `A2-T10`: 278-shot LONG_01 continuity and frame accuracy — **PASSED**
- `A2-T11`: Keyframes on legal frame grid — **PASSED**
- `A2-T12`: Keyframe monotonicity and bounds enforcement — **PASSED**
- `A2-T13`: A1 image order unchanged — **PASSED**
- `A2-T14`: A0 subtitle unchanged (bit-for-bit hash equality) — **PASSED**
- `A2-T15`: Tail coverage unchanged ($VISUAL\_END == MASTER\_AUDIO\_END$) — **PASSED**
- `A2-T16`: Bit-for-bit determinism across repeated runs — **PASSED**
- `A2-T17`: Normalized Draft regression and schema validity — **PASSED**

**Result: 17/17 passed in 0.31s**

### 4.2 Standard Profile Test Runs
- `scripts/run_tests.py fast`: **133 passed in 1.24s**
- `scripts/run_tests.py integration`: **55 passed, 1 skipped in 1.96s**
- `scripts/run_tests.py full`: **210 passed, 1 skipped in 8.13s**

---

## 5. Physical CapCut 9.4.0 Smoke Test Evidence

```ini
CAPCUT_VERSION = 9.4.0
PROJECT_NAME = 2TOOLNE A2 Physical Smoke Test GOLDEN_LONG_01
DRAFT_DIR = /Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft/2toolne_1788845534_2TOOLNE_A2_Physical_Smoke_Test_GOLDEN_LONG_01
ROOT_META_INDEX = 0 (Promoted to active front project)
DRAFT_WARNINGS = 0 (CapCutDraftValidator: [] | FrameAccuracyValidator: True, 0 errors)
AUDIO_TRACK = 1787.233s master audio track intact
SUBTITLE_TRACK = 641 captions synchronized
VIDEO_TRACK = 278 segments, exactly frame-aligned, 0 off-grid cuts
TAIL_COVERAGE = 6 outro shots smoothly covering to 1787.233s (0.0s black screen)
MOTION_KEYFRAMES = All ScaleX/PositionX keyframes frame-accurate and monotonic
PHYSICAL_STATUS = PASSED
```

---

## 6. Final Verdict

All automated verification gates and physical smoke test requirements are satisfied:

$$\mathbf{A2\_FRAME\_QUANTIZATION\_FROZEN\_PRODUCTION}$$
