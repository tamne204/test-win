# AutoEdit V2 — Developer DraftNormalizer & Golden Snapshot Testing

**Milestone:** DEVACCEL-3  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-3-draft-normalizer`  
**Status:** COMPLETE  

---

## 1. Executive Summary

DEVACCEL-3 eliminates the requirement of launching physical CapCut Desktop to verify timeline, track, and keyframe changes. By implementing `DraftNormalizer` (`apps/capcut-v2/core/dev_tools/draft_normalizer.py`), developers can perform deterministic canonical JSON snapshot testing directly in unit and integration test suites.

---

## 2. Field Normalization Matrix

| Target Field | Raw CapCut Representation | Normalized Canonical Representation | Preservation Rationale |
| :--- | :--- | :--- | :--- |
| **Draft ID** | `UUIDv4` (e.g. `7B21F3A8-...`) | `"NORMALIZED_DRAFT_ID"` | Eliminates non-deterministic UUID noise |
| **Timestamps** | Microsecond epoch timestamp | `0` | Eliminates time-dependent diffs |
| **Hardware IDs** | `device_id`, `mac_address`, `hard_disk_id` | `"NORMALIZED_DEVICE"`, etc. | Enables identical snapshots across Mac and Windows |
| **Media Paths** | Machine-specific `/Users/2tamne/...` | `media/<basename>` | Cross-machine test reproducibility |
| **Track IDs** | Random UUID | `TRACK_VIDEO_00`, `TRACK_AUDIO_01` | Structural index identity |
| **Segment IDs** | Random UUID | `SEG_VIDEO_0000`, `SEG_AUDIO_0000` | Chronological segment sequence |
| **Material IDs** | Random UUID | `MAT_VIDEOS_0000`, `MAT_TEXTS_0000` | Relational link between segment & material |
| **Keyframe IDs** | Random UUID | `KF_SCALEX_000`, `KFP_SCALEX_000` | Relational link within keyframe curve |
| **Keyframe Values** | `[1.0]`, `[1.15]`, curve controls | **100% Preserved Exactly** | Ensures zoom & pan curves are regression-tested |
| **Timeranges** | `start`, `duration` in microseconds | **100% Preserved Exactly** | Catches gaps, overlaps, and duration misalignments |
| **Text Cues** | Text content, font styles, sizes | **100% Preserved Exactly** | Verifies subtitle rendering data |
| **Canvas** | Ratio `9:16`, width `1080`, height `1920` | **100% Preserved Exactly** | Verifies project geometry |

---

## 3. Golden Update Safety Protocol

1. **Failure Immutability:**
   When a snapshot test fails, `assert_draft_matches_golden()` produces a detailed unified diff and raises an `AssertionError`. It **never** mutates or overwrites the golden snapshot file.
2. **Explicit Developer Intent:**
   Golden files are updated only when the developer explicitly supplies `update_golden=True` (or `--update-goldens` via CLI runner).
3. **Canonical Diff Readability:**
   Because JSON is canonicalized with sorted keys and 2-space indentation, `git diff` on golden files shows only semantic changes (e.g., modified keyframe scale or segment duration).

---

## 4. Verification Results

Suite `tests/test_draft_normalizer.py`:
- `test_draft_normalizer_idempotence`: PASSED (Repeated normalization is identical)
- `test_draft_normalizer_detects_structural_diffs`: PASSED (Semantic duration tampering detected)
- `test_golden_draft_end_to_end_snapshot`: PASSED (CapCutAdapter -> DraftNormalizer -> Golden snapshot verified)

**Result:** 3 passed in 0.06s.
