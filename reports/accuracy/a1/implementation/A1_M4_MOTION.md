# MILESTONE A1-M4 REPORT: DURATION-AWARE MOTION POLICY

- **Milestone:** A1-M4
- **Subsystem:** Velocity-First Motion Integration Engine
- **Status:** COMPLETED
- **Branch:** `feat/a1-m4-motion`
- **Date:** September 2026

---

### 1. Implemented Components

1. **Velocity-First Motion Engine (`apps/capcut-v2/core/visual/motion_policy.py`):**
   - `DurationAwareMotionPolicy`: Calculates keyframe scale and pan deltas from target velocity ($v \in [1.5\%/\text{s}, 3.5\%/\text{s}]$), completely resolving the contradictory fixed-delta math that caused $8\%/\text{s}$ whiplash on short shots.
   - Absolute Safety Clamp: Hard clamp guarantees effective velocity never exceeds $5.0\%/\text{s}$ under any circumstance.
   - Exceptional Short Shots ($< 2.0\text{s}$): Static pan with reduced zoom delta ($\le 3\%$, effective velocity $\le 1.5\%/\text{s}$).
   - Very Long / Tail Shots ($> 12.0\text{s}$): Ultra-slow ambient drift ($0.2\%/\text{s} - 0.25\%/\text{s}$, well below the $\le 0.8\%/\text{s}$ limit).
   - Reused Image Inversion: Reused visual assets automatically invert motion direction (e.g. `ZOOM_IN` $\to$ `ZOOM_OUT`, `PAN_LEFT` $\to$ `PAN_RIGHT`) to prevent visual monotony.

---

### 2. Verification
- Fast test profile: 83 passed in 0.44s (`tests/test_visual_motion_policy.py` 4/4 passed).
- Velocity envelope verified across test shot durations (2.0s, 3.0s, 4.0s, 5.0s, 6.5s, 8.0s, 10.0s, 24.4s).
- Reused asset direction inversion verified.
