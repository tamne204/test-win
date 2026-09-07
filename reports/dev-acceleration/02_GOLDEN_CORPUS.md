# AutoEdit V2 — Golden Test Corpus & Fixture Generator

**Milestone:** DEVACCEL-2  
**Date:** 2026-09-08  
**Repository Branch:** `feat/devaccel-2-golden-corpus`  
**Status:** COMPLETE  

---

## 1. Executive Summary

DEVACCEL-2 establishes the authoritative golden test corpus for AutoEdit V2 development. Instead of using arbitrary ad-hoc media files or incurring massive repository bloat by committing gigabytes of raw video files, the golden corpus defines lightweight, deterministic, content-addressed test fixtures representing every critical operational scenario.

Crucially, the real production dataset `LONG_01` (29m47s audio, 1641s speech narration, 266 paragraphs, 278 images) is formalized as a permanent regression fixture reusing its verified FasterWhisper acoustic cache.

---

## 2. Golden Corpus Catalog

| Fixture ID | Language | Description | Speech / Audio (s) | Words | Images | Edge Case Targeted |
| :--- | :---: | :--- | :---: | :---: | :---: | :--- |
| `GOLDEN_SHORT_VI` | `vi` | Standard 3-paragraph Vietnamese intro | 5.8 / 6.5 | 13 | 3 | Baseline standard flow |
| `GOLDEN_SHORT_KO` | `ko` | Korean polite greeting | 4.1 / 5.0 | 5 | 2 | Multilingual CJK tokenization |
| `GOLDEN_LONG_01` | `vi` | Real production long-form dataset | 1641.3 / 1787.2 | 3,053 | 278 | Real-scale drift, scale, memory |
| `GOLDEN_SILENT_TAIL` | `vi` | Speech terminates early, long tail | 4.2 / 20.0 | 7 | 2 | Acoustic silence / tail clamping |
| `GOLDEN_SCRIPT_OMISSION` | `vi` | Script has sentences skipped by speaker | 3.2 / 5.0 | 6 | 1 | Unspoken script text handling |
| `GOLDEN_IMAGE_SHORTAGE` | `vi` | 6 subtitle cues but only 2 physical images | 6.0 / 7.0 | 6 | 2 | Image reuse / duration stretching |
| `GOLDEN_IMAGE_SURPLUS` | `vi` | 2 subtitle cues with 10 physical images | 3.8 / 4.5 | 6 | 10 | Rapid cuts / visual pacing |
| `GOLDEN_DUPLICATE_FILENAMES` | `vi` | Multiple `slide.png` in different directories | 2.8 / 3.5 | 5 | 3 | Media asset ID namespace collision |
| `GOLDEN_MANY_PARAGRAPHS` | `vi` | 20 small paragraphs | 26.5 / 27.0 | 60 | 20 | Rapid paragraph boundary switching |
| `GOLDEN_LONG_PARAGRAPH` | `vi` | 1 massive uninterrupted paragraph (30 words) | 11.0 / 12.0 | 30 | 3 | Long cue sentence splitting |
| `GOLDEN_NO_SUBTITLE` | `und` | Slideshow with audio, no script or subtitles | 0.0 / 15.0 | 0 | 3 | Music-only / pure visual timeline |
| `GOLDEN_AUTOSUB` | `vi` | Speech-only mode; subtitles derived from ASR | 2.7 / 4.0 | 5 | 1 | Auto-subtitle generation mode |
| `GOLDEN_FORCED_ALIGNMENT` | `vi` | Uppercase user script with lowercase speech | 5.0 / 6.0 | 10 | 2 | Preservation of exact user script |

---

## 3. Fixture Generator & Manifest Integrity

All fixtures are generated through `tests/fixtures/golden/generator.py` and tracked in `tests/fixtures/golden/manifest.json`.
Every fixture maintains:
- `script_hash`: SHA-256 of canonical text
- `words_hash`: SHA-256 of sorted acoustic word timestamps
- `images_hash`: SHA-256 of image manifests
- `fixture_hash`: Master SHA-256 linking all component hashes

Any modification to a fixture immediately alters its SHA-256 digest, preventing silent test degradation.

---

## 4. Verification Results

Suite `tests/test_golden_corpus.py`:
- `test_golden_corpus_catalog`: PASSED (All 13 fixtures present & typed)
- `test_golden_long_01_real_asr_words`: PASSED (3,053 real FasterWhisper words loaded)
- `test_golden_manifest_content_hash_integrity`: PASSED (All runtime hashes match manifest)
- `test_corpus_determinism`: PASSED (Repeated generations yield identical hashes)

**Result:** 4 passed in 0.06s.
