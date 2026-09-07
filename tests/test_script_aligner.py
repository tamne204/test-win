"""
tests/test_script_aligner.py
Unit tests for script_aligner.py.
Verifies monotonic ordering, repeated phrase disambiguation, omission interpolation,
and filler word rejection while preserving 100% original text.
"""
import pytest
import sys
import os

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.models import (
    SpeechWordTimestamp,
    ConfidenceLevel,
    MatchType,
)
from core.subtitles.script_normalizer import tokenize_script
from core.subtitles.script_aligner import ScriptAligner


def test_perfect_match_alignment():
    """Verify 1-to-1 exact alignment for clean speech."""
    script = "Hôm nay tôi giới thiệu phần mềm mới."
    tokens = tokenize_script(script, language="vi")

    asr_words = [
        SpeechWordTimestamp(word="Hôm", start=1.0, end=1.3, confidence=0.98),
        SpeechWordTimestamp(word="nay", start=1.3, end=1.6, confidence=0.97),
        SpeechWordTimestamp(word="tôi", start=1.6, end=1.9, confidence=0.99),
        SpeechWordTimestamp(word="giới", start=1.9, end=2.2, confidence=0.95),
        SpeechWordTimestamp(word="thiệu", start=2.2, end=2.6, confidence=0.96),
        SpeechWordTimestamp(word="phần", start=2.7, end=3.0, confidence=0.98),
        SpeechWordTimestamp(word="mềm", start=3.0, end=3.3, confidence=0.97),
        SpeechWordTimestamp(word="mới", start=3.3, end=3.8, confidence=0.99),
    ]

    aligner = ScriptAligner()
    aligned = aligner.align(tokens, asr_words, audio_duration_s=5.0)

    assert len(aligned) == len(tokens)
    for at in aligned:
        assert at.confidence == ConfidenceLevel.HIGH
        assert at.match_type == MatchType.EXACT
        assert at.start_s < at.end_s

    # Verify timestamps match
    assert aligned[0].start_s == 1.0
    assert aligned[-1].end_s == 3.8


def test_repeated_phrase_handling():
    """
    CRITICAL: Verify repeated phrase 'đây là sản phẩm mới' appearing 3 times
    is matched sequentially to consecutive spoken occurrences, not all to the first.
    """
    script = (
        "Đây là sản phẩm mới. "
        "Tôi xin nhắc lại, đây là sản phẩm mới. "
        "Và cuối cùng, đây là sản phẩm mới."
    )
    tokens = tokenize_script(script, language="vi")

    # Construct ASR timestamps for 3 separate occurrences in sequence
    asr_words = [
        # Occurrence 1: 1.0s -> 3.0s
        SpeechWordTimestamp(word="đây", start=1.0, end=1.4),
        SpeechWordTimestamp(word="là", start=1.4, end=1.8),
        SpeechWordTimestamp(word="sản", start=1.8, end=2.3),
        SpeechWordTimestamp(word="phẩm", start=2.3, end=2.7),
        SpeechWordTimestamp(word="mới", start=2.7, end=3.1),

        # Intermediate phrase
        SpeechWordTimestamp(word="tôi", start=4.0, end=4.3),
        SpeechWordTimestamp(word="xin", start=4.3, end=4.6),
        SpeechWordTimestamp(word="nhắc", start=4.6, end=4.9),
        SpeechWordTimestamp(word="lại", start=4.9, end=5.3),

        # Occurrence 2: 6.0s -> 8.0s
        SpeechWordTimestamp(word="đây", start=6.0, end=6.4),
        SpeechWordTimestamp(word="là", start=6.4, end=6.8),
        SpeechWordTimestamp(word="sản", start=6.8, end=7.3),
        SpeechWordTimestamp(word="phẩm", start=7.3, end=7.7),
        SpeechWordTimestamp(word="mới", start=7.7, end=8.1),

        # Intermediate phrase
        SpeechWordTimestamp(word="và", start=9.0, end=9.2),
        SpeechWordTimestamp(word="cuối", start=9.2, end=9.5),
        SpeechWordTimestamp(word="cùng", start=9.5, end=9.9),

        # Occurrence 3: 11.0s -> 13.0s
        SpeechWordTimestamp(word="đây", start=11.0, end=11.4),
        SpeechWordTimestamp(word="là", start=11.4, end=11.8),
        SpeechWordTimestamp(word="sản", start=11.8, end=12.3),
        SpeechWordTimestamp(word="phẩm", start=12.3, end=12.7),
        SpeechWordTimestamp(word="mới", start=12.7, end=13.1),
    ]

    aligner = ScriptAligner()
    aligned = aligner.align(tokens, asr_words, audio_duration_s=15.0)

    assert len(aligned) == len(tokens)

    # Find the 3 occurrences of 'mới' in the aligned tokens
    moi_tokens = [t for t in aligned if t.script_token.raw_text.lower() == "mới"]
    assert len(moi_tokens) == 3

    # Ensure strictly increasing chronological timestamps across repeated phrases
    assert moi_tokens[0].end_s <= 3.5
    assert 7.5 <= moi_tokens[1].end_s <= 8.5
    assert 12.5 <= moi_tokens[2].end_s <= 13.5
    assert moi_tokens[0].end_s < moi_tokens[1].start_s < moi_tokens[2].start_s


def test_asr_omission_interpolation():
    """
    Verify that when speaker skips a word in script, the missing word
    is interpolated conservatively and marked LOW confidence.
    """
    # Script: 'tôi rất thích video này'
    # Speaker speaks: 'tôi thích video này' (skips 'rất')
    script = "Tôi rất thích video này."
    tokens = tokenize_script(script, language="vi")

    asr_words = [
        SpeechWordTimestamp(word="tôi", start=1.0, end=1.4),
        # 'rất' omitted
        SpeechWordTimestamp(word="thích", start=2.0, end=2.4),
        SpeechWordTimestamp(word="video", start=2.5, end=2.9),
        SpeechWordTimestamp(word="này", start=3.0, end=3.4),
    ]

    aligner = ScriptAligner()
    aligned = aligner.align(tokens, asr_words, audio_duration_s=5.0)

    assert len(aligned) == 5
    rat_tok = aligned[1]
    assert rat_tok.script_token.raw_text == "rất"
    assert rat_tok.confidence == ConfidenceLevel.LOW
    assert rat_tok.match_type == MatchType.INTERPOLATED

    # Timestamp must lie between 'tôi' (1.4s) and 'thích' (2.0s)
    assert 1.4 <= rat_tok.start_s < rat_tok.end_s <= 2.0


def test_extra_filler_words_rejected():
    """
    Verify that filler words in ASR (e.g. 'ừm', 'à') do not contaminate the script.
    """
    script = "Tôi đang hoàn thành dự án."
    tokens = tokenize_script(script, language="vi")

    asr_words = [
        SpeechWordTimestamp(word="tôi", start=1.0, end=1.3),
        SpeechWordTimestamp(word="ừm", start=1.4, end=1.7),   # Filler word
        SpeechWordTimestamp(word="đang", start=1.8, end=2.1),
        SpeechWordTimestamp(word="à", start=2.2, end=2.4),    # Filler word
        SpeechWordTimestamp(word="hoàn", start=2.5, end=2.8),
        SpeechWordTimestamp(word="thành", start=2.8, end=3.1),
        SpeechWordTimestamp(word="dự", start=3.2, end=3.5),
        SpeechWordTimestamp(word="án", start=3.5, end=3.9),
    ]

    aligner = ScriptAligner()
    aligned = aligner.align(tokens, asr_words, audio_duration_s=5.0)

    # Resulting tokens must match original script tokens count and wording exactly
    aligned_words = [t.script_token.raw_text for t in aligned]
    assert aligned_words == ["Tôi", "đang", "hoàn", "thành", "dự", "án"]
    assert "ừm" not in aligned_words
    assert "à" not in aligned_words


def test_monotonic_non_decreasing_guarantee():
    """Verify that every single aligned token is strictly monotonic in time."""
    script = "Một hai ba bốn năm sáu bảy tám chín mười."
    tokens = tokenize_script(script, language="vi")

    # Jumbled/sparse ASR timestamps
    asr_words = [
        SpeechWordTimestamp(word="một", start=0.5, end=0.8),
        SpeechWordTimestamp(word="ba", start=1.5, end=1.8),
        SpeechWordTimestamp(word="mười", start=4.5, end=5.0),
    ]

    aligner = ScriptAligner()
    aligned = aligner.align(tokens, asr_words, audio_duration_s=6.0)

    assert len(aligned) == 10
    for idx in range(len(aligned)):
        assert aligned[idx].start_s < aligned[idx].end_s
        if idx > 0:
            assert aligned[idx].start_s >= aligned[idx - 1].end_s
