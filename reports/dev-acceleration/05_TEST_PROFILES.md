# AutoEdit V2 — Developer Test Profiles

**Milestone:** DEVACCEL-5  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-5-test-profiles`  
**Status:** COMPLETE  

---

## 1. Executive Summary

DEVACCEL-5 introduces explicit, repository-native test execution profiles via `scripts/run_tests.py` and `pytest.ini`. Developers no longer need to remember manual `PYTHONPATH` strings, long file lists, or run slow end-to-end suites for simple unit or snapshot changes.

---

## 2. Test Profile Hierarchy

```
┌──────────────────────────────────────────────────────────────────┐
│ test:fast (0.63s — 67 tests)                                     │
│ Pure math, tokenizers, models, caches, golden corpus, snapshots  │
└─────────────────────────────────┬────────────────────────────────┘
                                  │
┌─────────────────────────────────▼────────────────────────────────┐
│ test:integration (2.42s — 56 tests)                              │
│ Offline pipeline replay, draft generation, schema, IPC mocks     │
└─────────────────────────────────┬────────────────────────────────┘
                                  │
┌─────────────────────────────────▼────────────────────────────────┐
│ test:accuracy (6.08s — 22 tests)                                 │
│ Phase A0 benchmarks, LONG_01 real ASR, adversarial edge cases    │
└─────────────────────────────────┬────────────────────────────────┘
                                  │
┌─────────────────────────────────▼────────────────────────────────┐
│ test:full (6.82s — 145 tests)                                    │
│ Complete automated suite (Fast + Integration + Accuracy)         │
└─────────────────────────────────┬────────────────────────────────┘
                                  │
┌─────────────────────────────────▼────────────────────────────────┐
│ test:release (~10s — 160+ tests)                                 │
│ Full gate + security hardening + license gates + packaging checks │
└──────────────────────────────────────────────────────────────────┘
```

---

## 3. Invocation Commands

```bash
# Fastest unit & snapshot loop during algorithm development:
python scripts/run_tests.py fast

# Offline pipeline & CapCut adapter validation:
python scripts/run_tests.py integration

# Accuracy benchmark verification:
python scripts/run_tests.py accuracy

# Comprehensive pre-merge test gate:
python scripts/run_tests.py full

# Release qualification gate:
python scripts/run_tests.py release
```

---

## 4. Empirical Performance Benchmarks

| Profile | Items Tested | Execution Time (pytest) | Total Wall-Clock Time | Primary Use Case |
| :--- | :---: | :---: | :---: | :--- |
| **`fast`** | 67 items | **0.35s** | **0.63s** | Inner-loop code editing, saved file hook |
| **`integration`** | 56 items | **2.05s** | **2.42s** | Feature branch verification, draft testing |
| **`accuracy`** | 22 items | **5.68s** | **6.08s** | Alignment / timing drift regression gate |
| **`full`** | 145 items | **6.38s** | **6.82s** | Pre-merge gate before merging to `main` |
| **`release`** | 160+ items | ~8.5s | ~9.2s | Release candidate sign-off |
