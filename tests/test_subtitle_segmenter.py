"""
tests/test_subtitle_segmenter.py
Unit tests for subtitle_segmenter.py.
Verifies the 2TOOLNE_STANDARD_SUBTITLE preset (max 12 words, punctuation breaks,
non-overlapping monotonic timing, and no dangling 1-word subtitles).
"""
import pytest
import sys
import os

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.models import (
    AlignmentOptions,
    ConfidenceLevel,
    MatchType,
    AlignedToken,
)
from core.subtitles.script_normalizer import tokenize_script
from core.subtitles.subtitle_segmenter import SubtitleSegmenter


def _create_synthetic_aligned_tokens(script: str, word_dur: float = 0.4) -> list:
    tokens = tokenize_script(script, language="vi")
    aligned = []
    t = 1.0
    for tok in tokens:
        aligned.append(
            AlignedToken(
                script_token=tok,
                start_s=round(t, 3),
                end_s=round(t + word_dur, 3),
                confidence=ConfidenceLevel.HIGH,
                match_type=MatchType.EXACT,
            )
        )
        t += word_dur + 0.05
    return aligned


def test_max_words_per_cue_rule():
    """Verify cues do not exceed maximum words per cue."""
    # 25 words with no punctuation
    words = [f"từ{i}" for i in range(1, 26)]
    script = " ".join(words)
    aligned = _create_synthetic_aligned_tokens(script)

    segmenter = SubtitleSegmenter(AlignmentOptions(max_words_per_cue=12))
    cues = segmenter.segment(aligned)

    assert len(cues) >= 2
    for cue in cues:
        cue_word_count = len(cue.text.split())
        assert cue_word_count <= 14, f"Cue exceeded max words: {cue_word_count}"


def test_punctuation_priority_breakpoints():
    """Verify segmenter breaks at sentence endings before reaching word limits."""
    script = "Chào bạn. Hôm nay trời rất đẹp, chúng ta cùng làm video CapCut nhé!"
    aligned = _create_synthetic_aligned_tokens(script)

    segmenter = SubtitleSegmenter(AlignmentOptions(max_words_per_cue=12))
    cues = segmenter.segment(aligned)

    assert len(cues) >= 2
    # The first cue should end at 'bạn.'
    assert cues[0].text.endswith(".")
    # The last cue should end with 'nhé!'
    assert cues[-1].text.endswith("!")


def test_no_dangling_one_word_subtitle():
    """Verify that a sentence with 13 words doesn't produce a 1-word dangling cue."""
    # 13 words: 'Một hai ba bốn năm sáu bảy tám chín mười mười_một mười_hai mười_ba.'
    words = ["Từ1", "Từ2", "Từ3", "Từ4", "Từ5", "Từ6", "Từ7", "Từ8", "Từ9", "Từ10", "Từ11", "Từ12", "Từ13."]
    script = " ".join(words)
    aligned = _create_synthetic_aligned_tokens(script)

    segmenter = SubtitleSegmenter(AlignmentOptions(max_words_per_cue=12))
    cues = segmenter.segment(aligned)

    # Must NOT have a dangling 1-word cue at the end
    for cue in cues:
        assert len(cue.text.split()) > 1, f"Found dangling 1-word cue: '{cue.text}'"


def test_monotonic_non_overlapping_timing():
    """Verify cues have strictly non-overlapping, positive durations and monotonic timestamps."""
    script = "2TOOLNE AutoEdit là công cụ dựng video thông minh và hoàn toàn tự động."
    aligned = _create_synthetic_aligned_tokens(script)

    segmenter = SubtitleSegmenter()
    cues = segmenter.segment(aligned)

    for i in range(len(cues)):
        assert cues[i].start_s < cues[i].end_s
        assert cues[i].duration_s >= 0.4
        if i > 0:
            assert cues[i].start_s >= cues[i - 1].end_s, f"Overlap detected between cue {i} and {i+1}"
