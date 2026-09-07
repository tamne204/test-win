# AutoEdit V2 — OSS Technology Scout & Reuse Workflow

**Milestone:** DEVACCEL-7  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-7-oss-scout`  
**Status:** COMPLETE  

---

## 1. Executive Summary & Core Principle

The foundational engineering principle for AutoEdit V2 is:
> **BUILD THE PRODUCT, REUSE THE COMMODITY.**

Engineers and AI agents must never waste engineering cycles building commodity algorithms, file format parsers, or signal processing primitives from scratch when mature, battle-tested, high-performance open-source technology already exists.

However, commercial shipping introduces strict license, security, and supply-chain constraints. This document formalizes the standardized research, scouting, and gating procedure required before any substantial subsystem is introduced.

---

## 2. The 7-Stage Technology Scout Funnel

```
1. PROBLEM DEFINITION
       ↓
2. BROAD SCOUT (GitHub, Papers, PyPI, PapersWithCode)
       ↓
3. SHORTLIST (Top 2–3 candidates against operational criteria)
       ↓
4. SMALL SPIKE (Isolated proof-of-concept in scratch directory)
       ↓
5. BENCHMARK (Latency, memory, binary size, offline reliability)
       ↓
6. LICENSE GATE & DECISION MODEL (Commercial safety sign-off)
       ↓
7. PRODUCTION INTEGRATION (Encapsulated behind Adapter interface)
```

---

## 3. License Gate Classification

Commercial distribution of 2TOOLNE AutoEdit requires rigorous license compliance:

| License Classification | Allowed in AutoEdit? | Permitted Actions | Examples |
| :--- | :---: | :--- | :--- |
| `SAFE_TO_BUNDLE` | **YES** | Can be packaged, bundled, and shipped in installer | MIT, Apache 2.0, BSD-2/3, ISC, Unlicense |
| `REQUIRES_ATTRIBUTION` | **YES** | Allowed, must embed copyright & license in `THIRDPARTY_NOTICES.txt` | MIT, Apache 2.0, BSD |
| `PORT_SMALL_PRIMITIVE` | **YES** | Porting small helper routines; keep original copyright header | Permissive snippets, public domain |
| `SAFE_AS_REFERENCE` | **YES** | Design, mathematical equations, or RFC specs used for clean implementation | Academic papers, RFCs, clean-room code |
| `COPYLEFT_RISK` | **NO** | Prohibited from static linkage or closed bundling | GPLv2, GPLv3, AGPL |
| `UNKNOWN_LICENSE` | **NO** | Strictly prohibited until explicit legal authorization | Unlicensed GitHub repos |

---

## 4. The 5-Way Reuse Decision Model

Every researched solution must be assigned one architectural decision:

1. `USE_DEPENDENCY`: Add as a direct pip/npm dependency when the library is actively maintained, permissively licensed, lightweight (< 15MB), and pure-Python or pre-compiled for Windows/macOS.
2. `WRAP_LIBRARY`: When a library has complex internals, wrap it inside a strict repository adapter interface (e.g. `adapters/capcut/` or `speech_timestamp_provider.py`). Product code only touches our adapter interface, allowing seamless replacement.
3. `PORT_SMALL_PRIMITIVE`: When we only need 50 lines of an algorithm (e.g. a specific easing formula, color conversion, or token distance function), port the clean primitive rather than adding a 100MB external dependency tree.
4. `USE_AS_REFERENCE`: When algorithms are described in papers or non-permissive codebases, write an in-house clean-room implementation from specification.
5. `BUILD_CUSTOM`: When existing solutions fail latency, offline requirements, or security gates, construct custom product logic.

---

## 5. Application to Upcoming Phase A1 (VisualShotPlanner)

For the upcoming Phase A1 visual planning subsystem:
- **Scout candidates for image salient crop / visual subject detection:**
  - Evaluate lightweight ONNX models (e.g. YOLOv8-nano, MediaPipe, OpenCV Saliency).
  - Gate: Must run offline on CPU in < 50ms per image.
- **Scout candidates for semantic text-to-image similarity:**
  - Evaluate quantized MobileCLIP / TinyCLIP ONNX.
  - Gate: Must not bloat installer by > 50MB.
- Use `reports/dev-acceleration/OSS_TECHNOLOGY_SCOUT_TEMPLATE.md` to document findings before writing any product code.
