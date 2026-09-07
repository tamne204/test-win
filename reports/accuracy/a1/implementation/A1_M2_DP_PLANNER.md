# MILESTONE A1-M2 REPORT: VISUAL SHOT PLANNER DP ENGINE

- **Milestone:** A1-M2
- **Subsystem:** Minimum-Cost Temporal Partitioning DP Core
- **Status:** COMPLETED
- **Branch:** `feat/a1-m2-dp-planner`
- **Date:** September 2026

---

### 1. Implemented Components

1. **Dynamic Programming Partitioner (`apps/capcut-v2/core/visual/dp_planner.py`):**
   - `VisualShotPlanner`: Solves optimal shot segmentation over `VisualBoundaryCandidate[]` using discrete Bellman dynamic programming in $\mathcal{O}(N \cdot K_{\text{max}})$ time.
   - Dynamic Search Window: Search window bounded by `search_horizon <= 14.0s` and `K_max <= 30` candidates, eliminating rigid cue-count assumptions.
   - Supply-Aware Duration Modulation: Automatically detects physical image shortages ($> 7.0\text{s/image}$) and naturally shifts target duration toward `soft_max` ($8.5\text{s}$), reducing shot count deterministically without ad-hoc post-stretch hacks.
   - Continuous Acoustic Pause Reward: Fully continuous interpolation for gaps between $100\text{ms}$ and $300\text{ms}$ ($P_{\text{pause}} = -5.0 \cdot \frac{\text{gap}-100}{200}$), and full $-5.0$ reward for $\ge 300\text{ms}$.
   - Structural Boundary Cost: 0.0 for paragraph breaks, 2.0 for sentence periods, 8.0 for clauses, 15.0 for duration-forced internal splits, 35.0 for mid-clause arbitrary cuts.
   - Terminal Residual Handling: Sub-minimal final residual shots ($< 2.0\text{s}$) are merged into preceding shots up to `terminal_max` ($14.0\text{s}$), completely preventing sub-second visual artifacts.
   - Strict Determinism: 100 consecutive runs yield identical paths and cost values with zero float/dict non-determinism.

---

### 2. Verification
- Fast test profile: 74 passed in 0.30s (`tests/test_visual_dp_planner.py` 4/4 passed).
- Long cue split verified: 18.76s cue splits into balanced $9.38\text{s} + 9.38\text{s}$ shots with zero subtitle modification.
- Terminal residual merge verified: 0.8s remainder cleanly merged into preceding shot.
