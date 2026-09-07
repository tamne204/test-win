"""
tests/test_golden_corpus.py
Validation tests for AutoEdit V2 Golden Corpus and Fixture Generator.
"""
import json
import os
import sys
import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from tests.fixtures.golden import GoldenCorpus, GoldenFixture


EXPECTED_FIXTURE_IDS = [
    "GOLDEN_SHORT_VI",
    "GOLDEN_SHORT_KO",
    "GOLDEN_LONG_01",
    "GOLDEN_SILENT_TAIL",
    "GOLDEN_SCRIPT_OMISSION",
    "GOLDEN_IMAGE_SHORTAGE",
    "GOLDEN_IMAGE_SURPLUS",
    "GOLDEN_DUPLICATE_FILENAMES",
    "GOLDEN_MANY_PARAGRAPHS",
    "GOLDEN_LONG_PARAGRAPH",
    "GOLDEN_NO_SUBTITLE",
    "GOLDEN_AUTOSUB",
    "GOLDEN_FORCED_ALIGNMENT",
]


def test_golden_corpus_catalog():
    fixtures = GoldenCorpus.get_all_fixtures()
    for fid in EXPECTED_FIXTURE_IDS:
        assert fid in fixtures, f"Missing required golden fixture: {fid}"
        f = fixtures[fid]
        assert isinstance(f, GoldenFixture)
        assert len(f.fixture_hash) == 64


def test_golden_long_01_real_asr_words():
    f = GoldenCorpus.get_fixture("GOLDEN_LONG_01")
    # Verify it loaded the 3,053 acoustic words from long_01_asr_cache.json
    assert len(f.words) == 3053
    assert f.audio_duration_s == 1787.233333
    assert len(f.images) == 278
    assert f.words[0].word == "2년"


def test_golden_manifest_content_hash_integrity():
    manifest_path = os.path.join(os.path.dirname(__file__), "fixtures", "golden", "manifest.json")
    assert os.path.isfile(manifest_path)
    with open(manifest_path, "r", encoding="utf-8") as f:
        manifest = json.load(f)

    fixtures = GoldenCorpus.get_all_fixtures()
    assert manifest["total_fixtures"] == len(fixtures)

    for fid, f in fixtures.items():
        assert fid in manifest["fixtures"]
        m_entry = manifest["fixtures"][fid]
        assert m_entry["fixture_hash"] == f.fixture_hash
        assert m_entry["script_hash"] == f.script_hash
        assert m_entry["words_hash"] == f.words_hash


def test_corpus_determinism():
    f1 = GoldenCorpus.get_all_fixtures()
    f2 = GoldenCorpus.get_all_fixtures()
    for k in f1:
        assert f1[k].fixture_hash == f2[k].fixture_hash
