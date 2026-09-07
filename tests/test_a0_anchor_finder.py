"""
tests/test_a0_anchor_finder.py
Unit tests for Milestone M0-B: Deterministic Anchor Discovery and Monotonic Chaining.
"""
import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

import pytest
from core.subtitles.models import (
    ScriptToken,
    SpeechWordTimestamp,
)
from core.subtitles.script_normalizer import tokenize_script
from core.subtitles.anchor_finder import (
    AnchorFinder,
    AnchorCandidate,
)


def _make_asr_words(words_with_times):
    """Helper to build SpeechWordTimestamp list."""
    res = []
    for i, item in enumerate(words_with_times):
        w, s, e = item[0], item[1], item[2]
        prob = item[3] if len(item) > 3 else 0.95
        res.append(
            SpeechWordTimestamp(
                word=w,
                start=s,
                end=e,
                confidence=prob,
                original_index=i,
                normalized_text=w.strip().lower(),
            )
        )
    return res


def test_clean_ngram_anchor_discovery():
    script = "Hôm nay trời rất đẹp và gió mát nhẹ nhàng."
    tokens = tokenize_script(script, language="vi")

    # ASR matching tokens with realistic timestamps
    asr_data = [
        ("hôm", 0.5, 0.8, 0.98),
        ("nay", 0.8, 1.1, 0.96),
        ("trời", 1.1, 1.4, 0.95),
        ("rất", 1.4, 1.7, 0.92),
        ("đẹp", 1.7, 2.1, 0.97),
        ("và", 2.2, 2.4, 0.90),
        ("gió", 2.5, 2.8, 0.94),
        ("mát", 2.8, 3.1, 0.95),
        ("nhẹ", 3.1, 3.4, 0.93),
        ("nhàng", 3.4, 3.8, 0.96),
    ]
    asr_words = _make_asr_words(asr_data)

    finder = AnchorFinder(min_ngram_len=3, max_ngram_len=5)
    anchors = finder.find_anchors(tokens, asr_words, audio_duration_s=4.0)

    assert len(anchors) > 0
    # First anchor should cover "hôm nay trời" or longer
    a0 = anchors[0]
    assert a0.script_start_idx == 0
    assert a0.ngram_len >= 3
    assert a0.start_s == 0.5
    assert a0.anchor_score >= 0.55
    assert a0.anchor_context_score > 0.0


def test_stopword_only_anchor_rejection():
    script = "Và sau đó nhưng mà và sau đó anh ấy đi tới trường học."
    tokens = tokenize_script(script, language="vi")

    # ASR matching stopword phrase "và sau đó nhưng mà"
    asr_data = [
        ("và", 0.5, 0.8, 0.95),
        ("sau", 0.8, 1.0, 0.95),
        ("đó", 1.0, 1.2, 0.95),
        ("nhưng", 1.2, 1.5, 0.95),
        ("mà", 1.5, 1.8, 0.95),
        ("anh", 2.0, 2.3, 0.95),
        ("ấy", 2.3, 2.5, 0.95),
        ("đi", 2.5, 2.7, 0.95),
        ("tới", 2.7, 3.0, 0.95),
        ("trường", 3.0, 3.4, 0.95),
        ("học", 3.4, 3.8, 0.95),
    ]
    asr_words = _make_asr_words(asr_data)

    finder = AnchorFinder(min_ngram_len=3, max_ngram_len=4)
    anchors = finder.find_anchors(tokens, asr_words, audio_duration_s=4.0)

    # Stopwords only ("và sau đó", "nhưng mà") should not form lone valid anchors
    for a in anchors:
        words = a.tokens_text.split()
        assert not all(w in {"và", "sau", "đó", "nhưng", "mà"} for w in words)


def test_low_asr_prob_rejection():
    script = "Một hai ba bốn năm sáu bảy tám."
    tokens = tokenize_script(script, language="vi")

    # ASR has low probability for "một hai ba"
    asr_data = [
        ("một", 0.5, 0.8, 0.30),
        ("hai", 0.8, 1.1, 0.35),
        ("ba", 1.1, 1.4, 0.40),
        ("bốn", 1.5, 1.8, 0.95),
        ("năm", 1.8, 2.1, 0.95),
        ("sáu", 2.1, 2.4, 0.95),
        ("bảy", 2.4, 2.7, 0.95),
        ("tám", 2.7, 3.0, 0.95),
    ]
    asr_words = _make_asr_words(asr_data)

    finder = AnchorFinder(min_ngram_len=3, min_asr_prob=0.80)
    anchors = finder.find_anchors(tokens, asr_words, audio_duration_s=3.5)

    # Anchors should not include the low probability span (tokens 0-2)
    for a in anchors:
        assert a.script_start_idx >= 3


def test_paragraph_boundary_guard():
    # Two paragraphs separated by blank line
    script = "Đoạn một câu cuối cùng.\n\nĐoạn hai bắt đầu ở đây."
    tokens = tokenize_script(script, language="vi")

    # ASR words matching the boundary span "cuối cùng đoạn hai"
    asr_data = [
        ("đoạn", 0.5, 0.8),
        ("một", 0.8, 1.1),
        ("câu", 1.1, 1.4),
        ("cuối", 1.4, 1.7),
        ("cùng", 1.7, 2.0),
        ("đoạn", 2.5, 2.8),
        ("hai", 2.8, 3.1),
        ("bắt", 3.1, 3.4),
        ("đầu", 3.4, 3.7),
        ("ở", 3.7, 3.9),
        ("đây", 3.9, 4.2),
    ]
    asr_words = _make_asr_words(asr_data)

    finder = AnchorFinder(min_ngram_len=3)
    anchors = finder.find_anchors(tokens, asr_words, audio_duration_s=4.5)

    # No anchor may cross from paragraph 0 to paragraph 1
    for a in anchors:
        p_ids = {tokens[k].paragraph_id for k in range(a.script_start_idx, a.script_end_idx + 1)}
        assert len(p_ids) == 1, f"Anchor {a.tokens_text} crossed paragraph boundaries!"


def test_monotonic_chain_no_crossing():
    script = "Phần đầu tiên mở màn. Phần thứ hai tiếp diễn. Phần thứ ba kết thúc."
    tokens = tokenize_script(script, language="vi")

    # ASR has out-of-order candidate (e.g. repetition or confusion)
    asr_data = [
        ("phần", 1.0, 1.3),
        ("thứ", 1.3, 1.5),
        ("ba", 1.5, 1.8),
        ("kết", 1.8, 2.0),
        ("thúc", 2.0, 2.3),
        ("phần", 3.0, 3.3),
        ("đầu", 3.3, 3.5),
        ("tiên", 3.5, 3.8),
        ("mở", 3.8, 4.0),
        ("màn", 4.0, 4.3),
        ("phần", 5.0, 5.3),
        ("thứ", 5.3, 5.5),
        ("hai", 5.5, 5.8),
        ("tiếp", 5.8, 6.0),
        ("diễn", 6.0, 6.3),
    ]
    asr_words = _make_asr_words(asr_data)

    finder = AnchorFinder(min_ngram_len=3)
    chain = finder.find_anchors(tokens, asr_words, audio_duration_s=7.0)

    # Chain must be strictly monotonic in both script and audio
    for i in range(len(chain) - 1):
        assert chain[i].script_end_idx < chain[i + 1].script_start_idx
        assert chain[i].end_s < chain[i + 1].start_s
        assert chain[i].asr_end_idx < chain[i + 1].asr_start_idx


def test_silence_bridge_across_45s_music_interlude():
    """
    CRITICAL REGRESSION: 45-second music/pause interlude between two valid anchors
    must NOT destroy the anchor chain due to low raw wall-clock token speed.
    """
    script = "Lời mở đầu trước khi có nhạc dạo. Đoạn tiếp theo sau khúc nhạc dạo."
    tokens = tokenize_script(script, language="vi")

    # Anchor 1 at 2.0s - 4.5s
    # Silence gap from 4.5s to 50.0s (45.5s interlude)
    # Anchor 2 at 50.0s - 53.0s
    asr_data = [
        ("lời", 1.5, 1.8),
        ("mở", 1.8, 2.2),
        ("đầu", 2.2, 2.6),
        ("trước", 2.6, 3.0),
        ("khi", 3.0, 3.3),
        ("có", 3.3, 3.6),
        ("nhạc", 3.6, 4.0),
        ("dạo", 4.0, 4.5),
        # 45.5s music gap here
        ("đoạn", 50.0, 50.4),
        ("tiếp", 50.4, 50.7),
        ("theo", 50.7, 51.0),
        ("sau", 51.0, 51.3),
        ("khúc", 51.3, 51.7),
        ("nhạc", 51.7, 52.1),
        ("dạo", 52.1, 52.6),
    ]
    asr_words = _make_asr_words(asr_data)

    finder = AnchorFinder(min_ngram_len=3)
    chain = finder.find_anchors(tokens, asr_words, audio_duration_s=55.0)

    # Chain must span across the 45-second silence bridge!
    assert len(chain) >= 2, "Silence bridge failed: anchor chain was broken by music interlude!"
    assert chain[0].end_s <= 5.0
    assert chain[-1].start_s >= 50.0
