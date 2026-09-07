# OSS Technology Scout Evaluation Template

**Date:** YYYY-MM-DD  
**Evaluator:** <Agent ID / Engineer>  
**Target Subsystem:** <Subsystem Name, e.g. VisualShotPlanner, AudioBeatDetector>  
**Problem Statement:** <1-2 sentences summarizing the engineering challenge>  

---

## Candidate Overview

- **PROJECT:** <Name of library / repository>
- **URL:** <GitHub / Paper / Package URL>
- **LICENSE:** <Exact SPDX identifier, e.g. MIT, Apache-2.0, BSD-3-Clause, GPLv3>
- **LANGUAGE:** <Python / C++ / Rust / Go / TypeScript>
- **LAST_ACTIVITY:** <Date of last commit or release>
- **STARS_OR_ADOPTION_SIGNAL:** <GitHub stars, monthly downloads, citations>
- **SUPPORTED_OS:** <Windows 10/11, macOS Darwin, Linux>
- **OFFLINE:** <YES / NO (Can it operate 100% offline without remote API calls?)>
- **BINARY_SIZE:** <Installed package footprint on disk in MB>
- **DEPENDENCIES:** <Transitive dependencies, native compiled wheels, external runtimes>
- **PERFORMANCE:** <Throughput / latency benchmark result>
- **INTEGRATION_COMPLEXITY:** <LOW / MEDIUM / HIGH>
- **SECURITY_RISK:** <LOW / MEDIUM / HIGH (audit CVEs, supply chain footprint)>
- **COMMERCIAL_USE_ALLOWED:** <YES / NO / RESTRICTED>

---

## License Gate Classification

Select one:
- [ ] `SAFE_TO_BUNDLE`: Permissive open source (MIT, Apache-2.0, BSD-2/3, ISC, Unlicense). Can be bundled directly into commercial distribution.
- [ ] `SAFE_AS_REFERENCE_ONLY`: Proprietary or copyleft. Code cannot be copied; architecture/algorithm may be referenced for clean-room reimplementation.
- [ ] `REQUIRES_ATTRIBUTION`: Permissive but requires prominent NOTICE or LICENSE inclusion in installer/about screen.
- [ ] `COPYLEFT_RISK`: GPL / AGPL / LGPL. Strictly prohibited from static linkage or binary bundling in commercial AutoEdit distributions.
- [ ] `UNKNOWN_LICENSE`: Prohibited from any commercial bundling until legal clarity is obtained.

---

## Architectural Decision

Select one:
- [ ] `USE_DEPENDENCY`: Add as a direct runtime dependency via `requirements.txt` / pip.
- [ ] `WRAP_LIBRARY`: Introduce a clean decoupling adapter around the external library.
- [ ] `PORT_SMALL_PRIMITIVE`: Extract and adapt a small mathematical or algorithmic function into our own codebase with appropriate license attribution.
- [ ] `USE_AS_REFERENCE`: Use theoretical design or mathematical equations to write custom implementation.
- [ ] `BUILD_CUSTOM`: External solutions fail security, latency, or license gates; build in-house.

### Rationale & Justification:
<Document specific technical reasons, benchmarks, and architectural tradeoffs.>

---

## Action Plan & Verification
1. Small Spike outcome:
2. Performance benchmark compared to baseline:
3. Rollback / fallback path:
