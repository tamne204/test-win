"""
tests/test_a0_data_models.py
Unit tests for Accuracy Phase A0 Data Models and Document Structure.
"""
import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

import pytest
from core.subtitles.models import (
    ScriptToken,
    SpeechWordTimestamp,
    ASRWordTimestamp,
    AlignedToken,
    ConfidenceLevel,
    MatchType,
    SubtitleCue,
    AlignmentResult,
    AlignmentOptions,
    AlignmentEngineType,
    UnmatchedScriptSpan,
    RegionHealth,
)
from core.subtitles.script_normalizer import tokenize_script


def test_script_token_structure_and_properties():
    tok = ScriptToken(
        token_index=5,
        raw_text="Hello",
        normalized_text="hello",
        char_start=20,
        char_end=25,
        leading_whitespace=" ",
        trailing_punctuation=",",
        is_sentence_break=False,
        is_clause_break=True,
        paragraph_id=2,
        sentence_id=3,
        clause_id=1,
    )
    assert tok.original_index == 5
    assert tok.raw_text == "Hello"
    assert tok.normalized_text == "hello"
    assert tok.char_start == 20
    assert tok.char_end == 25
    assert tok.trailing_punctuation == ","
    assert tok.is_clause_break is True
    assert tok.paragraph_id == 2
    assert tok.sentence_id == 3
    assert tok.clause_id == 1


def test_asr_word_timestamp_probabilities():
    asr = ASRWordTimestamp(
        word="test",
        start=1.234,
        end=1.789,
        confidence=0.92,
        original_index=12,
        normalized_text="test",
        segment_id=1,
    )
    assert asr.raw_word == "test"
    assert asr.start_s == 1.234
    assert asr.end_s == 1.789
    assert asr.probability == 0.92
    assert asr.confidence == 0.92
    assert asr.original_index == 12
    assert asr.segment_id == 1

    d = asr.to_dict()
    assert d["probability"] == 0.92
    assert d["original_index"] == 12


def test_aligned_token_metadata():
    st = ScriptToken(
        token_index=0,
        raw_text="World",
        normalized_text="world",
        char_start=0,
        char_end=5,
    )
    asr = SpeechWordTimestamp(word="world", start=0.5, end=0.9, confidence=0.88)
    aligned = AlignedToken(
        script_token=st,
        start_s=0.5,
        end_s=0.9,
        confidence=ConfidenceLevel.HIGH,
        match_type=MatchType.EXACT,
        asr_word="world",
        asr_confidence=0.88,
        asr_word_ref=asr,
        string_similarity=1.0,
        token_confidence=0.88,
        anchor_distance_tokens=2,
        alignment_operation="exact",
    )
    assert aligned.asr_probability == 0.88
    assert aligned.string_similarity == 1.0
    assert aligned.token_confidence == 0.88
    assert aligned.anchor_distance_tokens == 2
    assert aligned.alignment_operation == "exact"
    assert aligned.asr_word_ref is asr


def test_unmatched_script_span():
    span = UnmatchedScriptSpan(
        span_id=1,
        char_start=50,
        char_end=90,
        token_start=10,
        token_end=15,
        text="This sentence was never spoken in audio.",
        reason="SCRIPT_OMITTED",
    )
    assert span.reason == "SCRIPT_OMITTED"
    d = span.to_dict()
    assert d["span_id"] == 1
    assert d["text"] == "This sentence was never spoken in audio."


def test_region_health_diagnostics():
    rh = RegionHealth(
        region_index=0,
        start_time_s=0.0,
        end_time_s=15.0,
        duration_s=15.0,
        script_token_count=35,
        asr_word_count=34,
        exact_matches=30,
        fuzzy_matches=3,
        interpolated_count=2,
        omitted_count=0,
        inserted_count=1,
        token_reading_speed=2.33,
        language_cps=12.5,
        mean_confidence=0.91,
        health_status="HEALTHY",
    )
    d = rh.to_dict()
    assert d["health_status"] == "HEALTHY"
    assert d["token_reading_speed"] == 2.33
    assert d["mean_confidence"] == 0.91


def test_subtitle_cue_source_span():
    cue = SubtitleCue(
        index=1,
        start_s=1.0,
        end_s=3.5,
        text="Hello world test",
        source_token_start=0,
        source_token_end=2,
        paragraph_ids=[0],
        sentence_ids=[0],
        alignment_confidence=0.95,
    )
    assert cue.source_token_start == 0
    assert cue.source_token_end == 2
    assert cue.paragraph_ids == [0]
    assert cue.sentence_ids == [0]
    assert cue.alignment_confidence == 0.95
    d = cue.to_dict()
    assert d["source_token_start"] == 0
    assert d["paragraph_ids"] == [0]


def test_alignment_result_extended_fields():
    res = AlignmentResult(
        cues=[],
        original_script="Hello world.",
        srt_content="",
        matched_percentage=90.0,
        unmatched_percentage=10.0,
        audio_duration_s=10.0,
        cue_count=0,
        low_confidence_count=0,
        matched_token_ratio=0.90,
        exact_match_ratio=0.80,
        fuzzy_match_ratio=0.10,
        interpolated_ratio=0.05,
        omitted_script_ratio=0.05,
        asr_insertion_ratio=0.02,
        anchor_coverage_ratio=0.85,
        alignment_engine_version=AlignmentEngineType.HIERARCHICAL_V1.value,
    )
    assert res.matched_token_ratio == 0.90
    assert res.alignment_engine_version == "hierarchical-anchor-v1"
    d = res.to_dict()
    assert d["matched_token_ratio"] == 0.90
    assert d["alignment_engine_version"] == "hierarchical-anchor-v1"


def test_tokenize_script_hierarchical_structure():
    script = (
        "Đoạn 1, câu 1. Câu thứ hai của đoạn 1!\n\n"
        "Đoạn 2, chỉ có một câu duy nhất?"
    )
    tokens = tokenize_script(script, language="vi")
    assert len(tokens) > 0

    # First paragraph tokens should have paragraph_id == 0
    p0_tokens = [t for t in tokens if t.paragraph_id == 0]
    p1_tokens = [t for t in tokens if t.paragraph_id == 1]
    assert len(p0_tokens) > 0
    assert len(p1_tokens) > 0

    # Check sentence_id advancement in paragraph 0
    sent_ids_p0 = {t.sentence_id for t in p0_tokens}
    assert len(sent_ids_p0) >= 2, "Paragraph 0 should contain at least 2 sentences"

    # Check clause_id on commas
    first_clause_tokens = [t for t in p0_tokens if t.sentence_id == 0 and t.clause_id == 0]
    second_clause_tokens = [t for t in p0_tokens if t.sentence_id == 0 and t.clause_id > 0]
    assert len(first_clause_tokens) > 0
    assert len(second_clause_tokens) > 0
