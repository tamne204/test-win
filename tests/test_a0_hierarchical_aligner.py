"""
tests/test_a0_hierarchical_aligner.py
Unit tests for Milestone M0-C: Hierarchical Script Aligner, Bounded Regions & Drift Containment.
"""
import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

import pytest
from core.subtitles.models import (
    ScriptToken,
    SpeechWordTimestamp,
    ConfidenceLevel,
    MatchType,
)
from core.subtitles.script_normalizer import tokenize_script
from core.subtitles.hierarchical_aligner import HierarchicalScriptAligner


def _make_asr(words_and_times):
    res = []
    for i, item in enumerate(words_and_times):
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


def test_drift_containment_invariant():
    """
    CRITICAL PROOF: A failure/omission inside Region K cannot change mapping
    or timestamps in later trusted-anchor regions.
    DRIFT_PROPAGATION_BEYOND_NEXT_TRUSTED_ANCHOR = 0.
    """
    # 3 distinct paragraphs:
    # Anchor 1: "Một hai ba bốn"
    # Gap with corruption/omission: "năm sáu bảy tám"
    # Anchor 2: "chín mười mười một mười hai"
    # Gap: "mười ba mười bốn"
    # Anchor 3: "mười lăm mười sáu mười bảy mười tám"
    script = (
        "Một hai ba bốn năm sáu bảy tám. "
        "Chín mười mười một mười hai mười ba mười bốn. "
        "Mười lăm mười sáu mười bảy mười tám."
    )
    tokens = tokenize_script(script, language="vi")

    # In ASR: the speaker completely skipped "năm sáu bảy tám"!
    # But spoken Anchor 2 starts at exactly 10.0s, Anchor 3 starts at 20.0s.
    asr_data = [
        # Anchor 1
        ("một", 1.0, 1.4),
        ("hai", 1.4, 1.8),
        ("ba", 1.8, 2.2),
        ("bốn", 2.2, 2.6),
        # Gap: "năm sáu bảy tám" is omitted by speaker, silence until 10.0s!
        # Anchor 2
        ("chín", 10.0, 10.4),
        ("mười", 10.4, 10.7),
        ("mười", 10.7, 11.0),
        ("một", 11.0, 11.3),
        ("mười", 11.3, 11.6),
        ("hai", 11.6, 12.0),
        # Gap
        ("mười", 15.0, 15.3),
        ("ba", 15.3, 15.6),
        ("mười", 15.6, 16.0),
        ("bốn", 16.0, 16.4),
        # Anchor 3
        ("mười", 20.0, 20.3),
        ("lăm", 20.3, 20.7),
        ("mười", 20.7, 21.0),
        ("sáu", 21.0, 21.3),
        ("mười", 21.3, 21.6),
        ("bảy", 21.6, 22.0),
        ("mười", 22.0, 22.3),
        ("tám", 22.3, 22.7),
    ]
    asr_words = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, anchors, health_list, unmatched = aligner.align(
        script_tokens=tokens,
        speech_timestamps=asr_words,
        audio_duration_s=25.0,
        language="vi",
    )

    # 1. Verify Anchor 2 is discovered and starts around 10.4s
    anc2 = next((a for a in anchors if "mười một" in a.tokens_text or "mười hai" in a.tokens_text), None)
    assert anc2 is not None, "Anchor 2 must be found"
    assert anc2.start_s >= 10.0

    # 2. Verify Final Anchor starts at >= 20.0s
    anc_last = anchors[-1]
    assert anc_last.start_s >= 20.0

    # 3. Check token 18 ("Mười"): timestamp must be 20.0s despite earlier omission
    tok_18 = aligned[18]
    assert tok_18.start_s == 20.0, "Drift occurred! Token 18 did not start at 20.0s"

    # 4. Check token 19 ("lăm"): timestamp must be 20.3s
    tok_19 = aligned[19]
    assert tok_19.start_s == 20.3


def test_zero_script_text_mutation():
    """Verify script text is 100% preserved and never replaced by Whisper text."""
    script = "Tôi yêu Việt Nam, thủ đô Hà Nội ngàn năm văn hiến!"
    tokens = tokenize_script(script, language="vi")

    # ASR words with slight differences or mistakes
    asr_data = [
        ("toi", 0.5, 0.8),
        ("yeu", 0.8, 1.1),
        ("viet", 1.1, 1.4),
        ("nam", 1.4, 1.7),
        ("thu", 2.0, 2.3),
        ("do", 2.3, 2.5),
        ("ha", 2.5, 2.8),
        ("noi", 2.8, 3.1),
        ("ngan", 3.2, 3.5),
        ("nam", 3.5, 3.8),
        ("van", 3.8, 4.1),
        ("hien", 4.1, 4.5),
    ]
    asr_words = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, _, _, _ = aligner.align(tokens, asr_words, audio_duration_s=5.0, language="vi")

    # Reconstructed text from aligned tokens
    reconstructed = " ".join(t.script_token.raw_text for t in aligned)
    # Original words without trailing punct
    original_words = " ".join(t.raw_text for t in tokens)
    assert reconstructed == original_words


def test_tail_feasibility_prevents_compression():
    """
    CRITICAL REGRESSION: Script has 30 remaining tokens, but only 1.5 seconds of audio left.
    The aligner must NOT compress 30 words into 1.5s (which would give 0.05s/word).
    It must align what fits at healthy rate and mark excess SCRIPT_TAIL_UNSPOKEN.
    """
    script = "Mở đầu câu chuyện ở đây và bây giờ. " + " ".join([f"từ{i}" for i in range(30)])
    tokens = tokenize_script(script, language="vi")

    # Anchor at start: "mở đầu câu chuyện" at 0.5s - 2.5s
    # Audio duration is 4.0s (only 1.5s left for 30 remaining words!)
    # ASR only has 2 words in the tail
    asr_data = [
        ("mở", 0.5, 0.8),
        ("đầu", 0.8, 1.2),
        ("câu", 1.2, 1.6),
        ("chuyện", 1.6, 2.0),
        ("ở", 2.0, 2.3),
        ("đây", 2.3, 2.5),
        ("từ0", 2.7, 3.0),
        ("từ1", 3.0, 3.3),
    ]
    asr_words = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner(hard_token_rate=5.0, target_token_rate=2.5)
    aligned, _, _, unmatched_spans = aligner.align(
        tokens, asr_words, audio_duration_s=4.0, language="vi"
    )

    # Must detect unspoken tail span
    assert len(unmatched_spans) > 0
    tail_span = next((s for s in unmatched_spans if s.reason == "SCRIPT_TAIL_UNSPOKEN"), None)
    assert tail_span is not None, "Tail collapse occurred! Unspoken script was not identified"

    # Check that individual token durations do NOT collapse to < 0.15s
    spoken_tokens = [t for t in aligned if t.confidence != ConfidenceLevel.UNMATCHED]
    for t in spoken_tokens:
        assert t.duration_s >= 0.20, f"Token {t.script_token.raw_text} duration collapsed to {t.duration_s}s"


def test_speaker_improvisation_not_emitted():
    """
    ASR words spoken by voice that are NOT in the script must be treated as insertions
    and NEVER emitted into the final script tokens.
    """
    script = "Chào mừng quý vị đã đến với chương trình."
    tokens = tokenize_script(script, language="vi")

    # Speaker improvises: "vâng à ừm" in the middle
    asr_data = [
        ("chào", 0.5, 0.8),
        ("mừng", 0.8, 1.1),
        ("quý", 1.1, 1.3),
        ("vị", 1.3, 1.5),
        ("vâng", 1.6, 1.8),  # improvisation
        ("à", 1.8, 2.0),     # improvisation
        ("ừm", 2.0, 2.2),    # improvisation
        ("đã", 2.3, 2.5),
        ("đến", 2.5, 2.8),
        ("với", 2.8, 3.0),
        ("chương", 3.0, 3.3),
        ("trình", 3.3, 3.7),
    ]
    asr_words = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, _, _, _ = aligner.align(tokens, asr_words, audio_duration_s=4.0, language="vi")

    # Aligned tokens must match the exact number of script tokens
    assert len(aligned) == len(tokens)
    emitted_words = [t.script_token.raw_text for t in aligned]
    assert "vâng" not in emitted_words
    assert "à" not in emitted_words
    assert "ừm" not in emitted_words
