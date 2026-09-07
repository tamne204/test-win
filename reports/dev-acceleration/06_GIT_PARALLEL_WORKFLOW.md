# AutoEdit V2 — Git Worktree & Parallel Agent Workflow

**Milestone:** DEVACCEL-6  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-6-git-workflow`  
**Status:** COMPLETE  

---

## 1. Executive Summary

To maintain extreme software quality and prevent regressions in frozen production subsystems (such as Phase A0 Hierarchical Alignment, FFmpeg V1, or CapCut Draft generation), AutoEdit V2 mandates an isolated Git worktree development model. Direct feature editing on `main` is strictly prohibited.

This workflow standardizes how human engineers and autonomous AI agents collaborate in parallel without file contention or broken baseline builds.

---

## 2. The Strict Worktree Lifecycle

Every milestone or substantial feature follows a strict 9-step progression:

```
[BASELINE] ──> [BRANCH] ──> [WORKTREE] ──> [IMPLEMENT] ──> [TEST]
                                                              │
[CLEANUP] <── [MERGE --no-ff] <── [QA GATE] <── [REVIEW] <────┘
```

### Execution Steps
1. **BASELINE:** Verify `main` working tree is clean and all tests pass:
   ```bash
   git status
   python scripts/run_tests.py fast
   ```
2. **BRANCH & WORKTREE:** Create isolated branch and dedicated worktree directory:
   ```bash
   git worktree add .worktrees/feature-name -b feat/feature-name
   ```
3. **IMPLEMENT:** Develop code strictly inside `.worktrees/feature-name/`.
4. **TEST:** Verify using repository test profiles:
   ```bash
   python scripts/run_tests.py fast
   python scripts/run_tests.py integration
   ```
5. **REVIEW:** Inspect diff and ensure zero edits to frozen subsystems:
   ```bash
   git diff main
   ```
6. **COMMIT:** Atomic conventional commit on the feature branch.
7. **QA GATE:** Run comprehensive suite:
   ```bash
   python scripts/run_tests.py full
   ```
8. **MERGE:** Merge via non-fast-forward merge into `main`:
   ```bash
   git worktree remove .worktrees/feature-name
   git merge --no-ff feat/feature-name -m "merge: feat(scope): description"
   ```
9. **CLEANUP:** Delete merged feature branch if ephemeral.

---

## 3. Worktree Isolation Rules

1. **One Agent / Worker = One Worktree:**
   An agent or developer exclusively operates in their assigned worktree. Cross-editing another worker's active worktree directory is forbidden.
2. **Directory Placement:**
   All worktrees must reside within `.worktrees/` (e.g. `.worktrees/devaccel-1`), which is registered in the root `.gitignore`. This ensures worktree directories never appear as untracked files in the root workspace.
3. **Disposable Worktrees:**
   Worktrees must be completely removable at any time without data loss (all work is safely committed to the branch).

---

## 4. Parallel Agent Decomposition Strategy

When executing complex, multi-milestone architectural initiatives (such as the upcoming Phase A1: `VisualShotPlanner`), tasks must be partitioned into orthogonal sub-domains:

```
                     ┌──────────────────────────────┐
                     │   Architect / Coordinator    │
                     └──────────────┬───────────────┘
                                    │
       ┌────────────────────────────┼────────────────────────────┐
       │                            │                            │
┌──────▼─────────────┐     ┌────────▼─────────────┐     ┌────────▼─────────────┐
│      Agent A       │     │       Agent B        │     │       Agent C        │
│ Core Algorithm     │     │ Test Corpus & QA     │     │ Adapter Integration  │
│ (.worktrees/a1-alg)│     │ (.worktrees/a1-tests)│     │ (.worktrees/a1-adapt)│
└────────────────────┘     └──────────────────────┘     └──────────────────────┘
```

### Domain Separation Matrix
- **Agent A (Algorithm):** Owns new modules under `apps/capcut-v2/core/<new_subsystem>/`.
- **Agent B (Corpus / Validator):** Owns test fixtures under `tests/fixtures/golden/` and test suites under `tests/test_<new_subsystem>.py`.
- **Agent C (Integration):** Owns pipeline orchestration under `core/timeline_builder.py` and adapters under `adapters/capcut/`.
- **Agent D (Scout / Research):** Owns research benchmarking, technology scout reports under `reports/`.

### Anti-Pattern Guardrail
> [!CAUTION]
> **Never parallelize agents on the exact same source file.**
> Concurrent edits to shared files (such as `pipeline.py` or `models.py`) create merge thrashing, semantic drift, and catastrophic regressions. Shared interfaces must be frozen before parallel implementation begins.
