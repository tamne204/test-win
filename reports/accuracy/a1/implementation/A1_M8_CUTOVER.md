# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
## MILESTONE REPORT A1-M8: PRODUCTION CUTOVER TO HIERARCHICAL DP VISUAL PLANNER

- **Project:** 2TOOLNE AutoEdit V2
- **Subsystem:** Phase A1 Visual Planning & Motion Subsystem
- **Milestone:** A1-M8
- **Branch:** `chore/a1-production-cutover`
- **Worktree:** `.worktrees/a1-m8`
- **Authoritative Spec:** `reports/accuracy/a1/07_A1_ARCHITECTURE_FREEZE.md`
- **Date:** September 2026

---

### 1. Executive Summary

Milestone A1-M8 executes the final production cutover of Phase A1:
- Production default engine is now **`HIERARCHICAL_DP_V1`** (`VisualPlannerEngine.HIERARCHICAL_DP_V1`).
- Shadow mode is disabled for normal production runs (`shadow_mode = False`).
- The legacy visual mapper remains fully intact and accessible via `VisualPlannerEngine.LEGACY` for instant rollback capability.
- Full automated test suite passes with **193 passed, 1 skipped (desktop hardware bridge)** in **7.57s**.
- All 24 acceptance tests (`A1-T01` to `A1-T24`) pass in **0.51s**.
- Golden dataset `GOLDEN_LONG_01` (29m47s) processes in **0.187s**, allocating 278 physical images monotonically into 278 shots (272 speech + 6 silent tail) with **zero visual gaps**, **zero visual overlaps**, and **zero black frames**.

---

### 2. Production Default Changes

#### 2.1 `apps/capcut-v2/core/visual/models.py`
```diff
 @dataclass
 class VisualPlannerOptions:
     """Runtime configuration for visual planning."""
-    engine: VisualPlannerEngine = VisualPlannerEngine.LEGACY
-    shadow_mode: bool = True
+    engine: VisualPlannerEngine = VisualPlannerEngine.HIERARCHICAL_DP_V1
+    shadow_mode: bool = False
     duration_policy: ShotDurationPolicy = field(default_factory=ShotDurationPolicy)
     tail_policy: TailDurationPolicy = field(default_factory=TailDurationPolicy)
     min_reuse_distance_s: float = 60.0
```

#### 2.2 `apps/capcut-v2/core/timeline_builder.py`
```diff
         caption_list: List[EditPlanCaption] = []
         total_timeline_duration_us = 0
         shadow_metadata: Dict[str, Any] = {}
-        v_opts = visual_options or VisualPlannerOptions(
-            engine=VisualPlannerEngine.LEGACY,
-            shadow_mode=True,
-        )
+        v_opts = visual_options or VisualPlannerOptions()
```

---

### 3. Rollback Safety Verification

The legacy timeline generator (`VisualPlannerEngine.LEGACY`) remains selectable via `VisualPlannerOptions(engine=VisualPlannerEngine.LEGACY)`:
```python
legacy_opts = VisualPlannerOptions(engine=VisualPlannerEngine.LEGACY, shadow_mode=False)
plan = tb.build(images=images, subtitle_cues=cues, visual_options=legacy_opts)
assert plan.metadata.get("visual_planner_engine") == "legacy"
```
Verification confirmed that selecting `LEGACY` runs without error, preserving complete operational rollback safety.

---

### 4. Test Suite Execution Summary

| Test Profile | Tests Run | Result | Duration | Notes |
| :--- | :--- | :--- | :--- | :--- |
| **`fast`** | 116 passed | **SUCCESS** | 0.90s | Unit, candidate builder, DP planner, image allocator, motion policy, validator, integration |
| **`accuracy`** | 46 passed | **SUCCESS** | 6.19s | A0 benchmarks, adversarial suites, forced alignment, A1 24-test matrix |
| **`integration`**| 55 passed, 1 skipped | **SUCCESS** | 1.79s | Replay harness, draft normalizer, timeline builder, diagnostics |
| **`full`** | 193 passed, 1 skipped | **SUCCESS** | 7.57s | Complete test suite across all subsystems |

---

### 5. Final Subsystem Status
- `A0_PRODUCTION_TRUTH_VERIFIED = YES`
- `A1_PRODUCTION_CUTOVER_COMPLETE = YES`
- `A1_DEFAULT_ENGINE = HIERARCHICAL_DP_V1`
- `A1_SHADOW_MODE_DEFAULT = False`
- `LEGACY_ROLLBACK_AVAILABLE = YES`
- `A1_PHYSICAL_CAPCUT_VERIFIED = NO` (Offline normalized draft only)
