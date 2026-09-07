"""
tests/test_a0_adversarial.py
Adversarial Test Suite (ADV_01 - ADV_12) for Accuracy Phase A0.
Verifies robustness against all 12 acoustic/textual adversarial anomalies:
  ADV_01: Long Pause
  ADV_02: Script Omission
  ADV_03: Speaker Repetition
  ADV_04: Speaker Improvisation
  ADV_05: Whisper Deletion
  ADV_06: Whisper Hallucination
  ADV_07: Stopword Ambiguity
  ADV_08: Repeated Phrase
  ADV_09: Numbers and Symbols
  ADV_10: Proper Nouns
  ADV_11: Music Interlude
  ADV_12: Trailing Mismatch (Critical 44-Sentence Regression)
"""
import os
import sys
import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.models import (
    SpeechWordTimestamp,
    ConfidenceLevel,
    MatchType,
    AlignmentOptions,
    AlignmentEngineType,
)
from core.subtitles.script_normalizer import tokenize_script
from core.subtitles.anchor_finder import AnchorFinder
from core.subtitles.hierarchical_aligner import HierarchicalScriptAligner
from core.subtitles.collapse_detector import CollapseDetector
from core.subtitles.subtitle_segmenter import SubtitleSegmenter


def _make_asr(word_list):
    """Helper to construct SpeechWordTimestamp list from (word, start, end) tuples."""
    return [
        SpeechWordTimestamp(word=w, start=round(s, 3), end=round(e, 3), confidence=0.92, original_index=i)
        for i, (w, s, e) in enumerate(word_list)
    ]


# ---------------------------------------------------------------------------
# ADV_01: Long Pause
# ---------------------------------------------------------------------------
def test_adv_01_long_pause():
    """
    ADV_01: Long pause / silence (25s) between narration segments.
    Anchors must be found on both sides and drift must be 0 across the gap.
    """
    script = (
        "Chào mừng quý vị đã đến với chương trình hôm nay.\n\n"
        "Sau đây là nội dung chi tiết của bản tin buổi tối."
    )
    tokens = tokenize_script(script, language="vi")

    # Segment 1: 1.0s - 4.5s; Segment 2: 30.0s - 34.0s (25.5s pause)
    asr_p1 = [
        ("chào", 1.0, 1.3), ("mừng", 1.3, 1.7), ("quý", 1.7, 2.0),
        ("vị", 2.0, 2.3), ("đã", 2.3, 2.6), ("đến", 2.6, 2.9),
        ("với", 2.9, 3.2), ("chương", 3.2, 3.6), ("trình", 3.6, 4.0),
        ("hôm", 4.0, 4.3), ("nay", 4.3, 4.7),
    ]
    asr_p2 = [
        ("sau", 30.0, 30.3), ("đây", 30.3, 30.6), ("là", 30.6, 30.8),
        ("nội", 30.8, 31.2), ("dung", 31.2, 31.5), ("chi", 31.5, 31.8),
        ("tiết", 31.8, 32.2), ("của", 32.2, 32.5), ("bản", 32.5, 32.8),
        ("tin", 32.8, 33.1), ("buổi", 33.1, 33.5), ("tối", 33.5, 33.9),
    ]
    asr = _make_asr(asr_p1 + asr_p2)

    aligner = HierarchicalScriptAligner()
    aligned, anchors, health, _ = aligner.align(tokens, asr, audio_duration_s=35.0, language="vi")

    # Verify anchor exists in both segments
    p1_anchors = [a for a in anchors if a.end_s <= 5.0]
    p2_anchors = [a for a in anchors if a.start_s >= 29.0]
    assert len(p1_anchors) > 0, "Anchor in segment 1 must be found"
    assert len(p2_anchors) > 0, "Anchor in segment 2 must be found"

    # Verify first token of segment 2 starts at >= 30.0s (drift = 0 across 25s silence)
    seg2_first_token = [t for t in aligned if t.script_token.paragraph_id == 1][0]
    assert seg2_first_token.start_s >= 29.5, f"Expected >= 29.5s, got {seg2_first_token.start_s}"


# ---------------------------------------------------------------------------
# ADV_02: Script Omission
# ---------------------------------------------------------------------------
def test_adv_02_script_omission():
    """
    ADV_02: User script contains a middle paragraph completely omitted by narrator.
    Must be identified in unmatched_script_spans with reason SCRIPT_OMITTED.
    Subsequent paragraph must align without downstream drift.
    """
    script = (
        "Đây là phần mở đầu của câu chuyện hôm nay.\n\n"
        "Đoạn này hoàn toàn bị người đọc bỏ qua và không hề được thu âm trong tệp âm thanh thực tế.\n\n"
        "Còn đây là phần kết thúc của câu chuyện."
    )
    tokens = tokenize_script(script, language="vi")

    # Only p0 and p2 are in audio
    asr_p0 = [
        ("đây", 0.5, 0.8), ("là", 0.8, 1.0), ("phần", 1.0, 1.3),
        ("mở", 1.3, 1.6), ("đầu", 1.6, 1.9), ("của", 1.9, 2.1),
        ("câu", 2.1, 2.4), ("chuyện", 2.4, 2.8), ("hôm", 2.8, 3.1), ("nay", 3.1, 3.5),
    ]
    asr_p2 = [
        ("còn", 6.0, 6.3), ("đây", 6.3, 6.6), ("là", 6.6, 6.9),
        ("phần", 6.9, 7.2), ("kết", 7.2, 7.5), ("thúc", 7.5, 7.8),
        ("của", 7.8, 8.1), ("câu", 8.1, 8.4), ("chuyện", 8.4, 8.8),
    ]
    asr = _make_asr(asr_p0 + asr_p2)

    aligner = HierarchicalScriptAligner()
    aligned, anchors, health, unmatched_spans = aligner.align(tokens, asr, audio_duration_s=10.0, language="vi")

    # Verify omitted span is detected
    omitted = [s for s in unmatched_spans if s.reason == "SCRIPT_OMITTED"]
    assert len(omitted) > 0, "Omitted script paragraph must be recorded in unmatched_script_spans"

    # Check p2 starts around 6.0s (no drift from omission)
    p2_tokens = [t for t in aligned if t.script_token.paragraph_id == 2]
    assert p2_tokens[0].start_s >= 5.8, f"Drift! p2 started at {p2_tokens[0].start_s}"


# ---------------------------------------------------------------------------
# ADV_03: Speaker Repetition
# ---------------------------------------------------------------------------
def test_adv_03_speaker_repetition():
    """
    ADV_03: Speaker stumbles and repeats a sentence twice in audio, script has it once.
    Script must align monotonically without duplicating text or corrupting timing.
    """
    script = "Tôi xin nhắc lại thông báo quan trọng này đến tất cả mọi người."
    tokens = tokenize_script(script, language="vi")

    # Speaker says "Tôi xin nhắc lại" twice in ASR
    asr_data = [
        ("tôi", 0.5, 0.8), ("xin", 0.8, 1.1), ("nhắc", 1.1, 1.4), ("lại", 1.4, 1.7),  # Stumble
        ("tôi", 2.0, 2.3), ("xin", 2.3, 2.6), ("nhắc", 2.6, 2.9), ("lại", 2.9, 3.2),  # Real take
        ("thông", 3.2, 3.5), ("báo", 3.5, 3.8), ("quan", 3.8, 4.1), ("trọng", 4.1, 4.4),
        ("này", 4.4, 4.7), ("đến", 4.7, 5.0), ("tất", 5.0, 5.3), ("cả", 5.3, 5.6),
        ("mọi", 5.6, 5.9), ("người", 5.9, 6.3),
    ]
    asr = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, _, _, _ = aligner.align(tokens, asr, audio_duration_s=7.0, language="vi")

    # Tokens count must match original script tokens count exactly
    assert len(aligned) == len(tokens)
    reconstructed = " ".join(t.script_token.raw_text for t in aligned)
    assert reconstructed == " ".join(t.raw_text for t in tokens)


# ---------------------------------------------------------------------------
# ADV_04: Speaker Improvisation
# ---------------------------------------------------------------------------
def test_adv_04_speaker_improvisation():
    """
    ADV_04: Speaker adds unscripted remarks.
    SCRIPT_TEXT_MUTATION_RATE = 0%. Unscripted words must never contaminate script tokens.
    """
    script = "Mùa thu Hà Nội có hoa sữa thơm nồng."
    tokens = tokenize_script(script, language="vi")

    # Speaker improvises: "à vâng thưa các bạn mùa thu hà nội..."
    asr_data = [
        ("à", 0.2, 0.4), ("vâng", 0.4, 0.7), ("thưa", 0.7, 0.9), ("các", 0.9, 1.1), ("bạn", 1.1, 1.4),
        ("mùa", 1.5, 1.8), ("thu", 1.8, 2.1), ("hà", 2.1, 2.4), ("nội", 2.4, 2.7),
        ("có", 2.7, 2.9), ("hoa", 2.9, 3.2), ("sữa", 3.2, 3.5), ("thơm", 3.5, 3.8), ("nồng", 3.8, 4.2),
    ]
    asr = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, _, _, _ = aligner.align(tokens, asr, audio_duration_s=5.0, language="vi")

    # Check words in aligned
    aligned_words = [t.script_token.raw_text for t in aligned]
    assert "vâng" not in aligned_words
    assert "thưa" not in aligned_words
    assert "bạn" not in aligned_words
    assert len(aligned) == len(tokens)


# ---------------------------------------------------------------------------
# ADV_05: Whisper Deletion
# ---------------------------------------------------------------------------
def test_adv_05_whisper_deletion():
    """
    ADV_05: Whisper drops 3 words in the middle of a sentence due to low volume.
    Adaptive band DP interpolates the missing span without collapsing neighboring words.
    """
    script = "Chúng ta cần phải bảo vệ môi trường sống xanh sạch đẹp mỗi ngày."
    tokens = tokenize_script(script, language="vi")

    # ASR missing "môi trường sống"
    asr_data = [
        ("chúng", 0.5, 0.8), ("ta", 0.8, 1.0), ("cần", 1.0, 1.3), ("phải", 1.3, 1.6),
        ("bảo", 1.6, 1.9), ("vệ", 1.9, 2.2),
        # missing: môi, trường, sống
        ("xanh", 3.5, 3.8), ("sạch", 3.8, 4.1), ("đẹp", 4.1, 4.4),
        ("mỗi", 4.4, 4.7), ("ngày", 4.7, 5.1),
    ]
    asr = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, _, _, _ = aligner.align(tokens, asr, audio_duration_s=6.0, language="vi")

    # Check that missing words were interpolated with valid timestamps
    moi_tok = next(t for t in aligned if t.script_token.raw_text == "môi")
    assert moi_tok.match_type == MatchType.INTERPOLATED
    assert moi_tok.start_s >= 2.2
    assert moi_tok.end_s <= 3.5
    assert moi_tok.duration_s >= 0.15


# ---------------------------------------------------------------------------
# ADV_06: Whisper Hallucination
# ---------------------------------------------------------------------------
def test_adv_06_whisper_hallucination():
    """
    ADV_06: Whisper hallucinates during a silence interval.
    Anchor finder rejects hallucination; silence is preserved.
    """
    script = (
        "Bắt đầu bài kiểm tra độ chính xác.\n\n"
        "Kết thúc bài kiểm tra độ chính xác."
    )
    tokens = tokenize_script(script, language="vi")

    # Hallucination in between at 5.0 - 10.0s: "hãy like và subscribe kênh của tôi"
    asr_data = [
        ("bắt", 0.5, 0.8), ("đầu", 0.8, 1.1), ("bài", 1.1, 1.4), ("kiểm", 1.4, 1.7),
        ("tra", 1.7, 2.0), ("độ", 2.0, 2.3), ("chính", 2.3, 2.6), ("xác", 2.6, 3.0),
        # Hallucination
        ("hãy", 5.0, 5.3), ("like", 5.3, 5.6), ("và", 5.6, 5.8), ("subscribe", 5.8, 6.2),
        ("kênh", 6.2, 6.5), ("của", 6.5, 6.7), ("tôi", 6.7, 7.0),
        # Segment 2
        ("kết", 12.0, 12.3), ("thúc", 12.3, 12.6), ("bài", 12.6, 12.9), ("kiểm", 12.9, 13.2),
        ("tra", 13.2, 13.5), ("độ", 13.5, 13.8), ("chính", 13.8, 14.1), ("xác", 14.1, 14.5),
    ]
    asr = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, anchors, _, _ = aligner.align(tokens, asr, audio_duration_s=16.0, language="vi")

    # Segment 2 first token must start >= 11.5s (not pulled forward by hallucination)
    seg2_first = [t for t in aligned if t.script_token.paragraph_id == 1][0]
    assert seg2_first.start_s >= 11.5


# ---------------------------------------------------------------------------
# ADV_07: Stopword Ambiguity
# ---------------------------------------------------------------------------
def test_adv_07_stopword_ambiguity():
    """
    ADV_07: Stopword-dense regions must not produce false anchor chains.
    AnchorFinder filters out stopword-only n-grams.
    """
    finder = AnchorFinder()
    # "và là của những" are all stopwords
    stopwords_tok = tokenize_script("và là của những trong các", language="vi")
    asr_stopwords = _make_asr([("và", 1.0, 1.2), ("là", 1.2, 1.4), ("của", 1.4, 1.6), ("những", 1.6, 1.8), ("trong", 1.8, 2.0), ("các", 2.0, 2.2)])

    anchors = finder.find_anchors(stopwords_tok, asr_stopwords, audio_duration_s=5.0)
    # Stopword-only n-grams must be rejected as trusted anchors
    assert len(anchors) == 0, "Stopword-only n-gram must not become a trusted anchor"


# ---------------------------------------------------------------------------
# ADV_08: Repeated Phrase Across Paragraphs
# ---------------------------------------------------------------------------
def test_adv_08_repeated_phrase_across_paragraphs():
    """
    ADV_08: Identical phrase repeated in Paragraph 0 and Paragraph 2.
    Paragraph boundary guard prevents cross-paragraph false anchor matches.
    """
    script = (
        "Chúng ta phải kiên trì vượt qua khó khăn ban đầu.\n\n"
        "Đoạn văn ở giữa giải thích lý do vì sao cần phải như vậy.\n\n"
        "Chúng ta phải kiên trì vượt qua khó khăn ban đầu."
    )
    tokens = tokenize_script(script, language="vi")

    asr_p0 = [
        ("chúng", 0.5, 0.8), ("ta", 0.8, 1.0), ("phải", 1.0, 1.3), ("kiên", 1.3, 1.6),
        ("trì", 1.6, 1.9), ("vượt", 1.9, 2.2), ("qua", 2.2, 2.5), ("khó", 2.5, 2.8),
        ("khăn", 2.8, 3.1), ("ban", 3.1, 3.4), ("đầu", 3.4, 3.8),
    ]
    asr_p1 = [
        ("đoạn", 5.0, 5.3), ("văn", 5.3, 5.6), ("ở", 5.6, 5.8), ("giữa", 5.8, 6.2),
        ("giải", 6.2, 6.5), ("thích", 6.5, 6.8), ("lý", 6.8, 7.1), ("do", 7.1, 7.4),
        ("vì", 7.4, 7.6), ("sao", 7.6, 7.9), ("cần", 7.9, 8.2), ("phải", 8.2, 8.5),
        ("như", 8.5, 8.7), ("vậy", 8.7, 9.0),
    ]
    asr_p2 = [
        ("chúng", 11.0, 11.3), ("ta", 11.3, 11.5), ("phải", 11.5, 11.8), ("kiên", 11.8, 12.1),
        ("trì", 12.1, 12.4), ("vượt", 12.4, 12.7), ("qua", 12.7, 13.0), ("khó", 13.0, 13.3),
        ("khăn", 13.3, 13.6), ("ban", 13.6, 13.9), ("đầu", 13.9, 14.3),
    ]
    asr = _make_asr(asr_p0 + asr_p1 + asr_p2)

    aligner = HierarchicalScriptAligner()
    aligned, anchors, _, _ = aligner.align(tokens, asr, audio_duration_s=16.0, language="vi")

    p0_first = [t for t in aligned if t.script_token.paragraph_id == 0][0]
    p2_first = [t for t in aligned if t.script_token.paragraph_id == 2][0]

    # p0 first token must be ~0.5s, p2 first token must be ~11.0s
    assert p0_first.start_s < 2.0
    assert p2_first.start_s >= 10.5, f"Expected >= 10.5s, got {p2_first.start_s}"


# ---------------------------------------------------------------------------
# ADV_09: Numbers and Symbols
# ---------------------------------------------------------------------------
def test_adv_09_numbers_and_symbols():
    """
    ADV_09: Script contains currency symbols and numbers ($500, 2026).
    Script text retains exact verbatim symbols; alignment matches normalized text.
    """
    script = "Doanh thu năm 2026 dự kiến đạt mốc $500 triệu đô la."
    tokens = tokenize_script(script, language="vi")

    asr_data = [
        ("doanh", 0.5, 0.8), ("thu", 0.8, 1.1), ("năm", 1.1, 1.4), ("2026", 1.4, 1.9),
        ("dự", 1.9, 2.2), ("kiến", 2.2, 2.5), ("đạt", 2.5, 2.8), ("mốc", 2.8, 3.1),
        ("500", 3.1, 3.5), ("triệu", 3.5, 3.8), ("đô", 3.8, 4.1), ("la", 4.1, 4.5),
    ]
    asr = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, _, _, _ = aligner.align(tokens, asr, audio_duration_s=5.5, language="vi")

    tok_500 = next(t for t in aligned if "500" in t.script_token.raw_text)
    assert tok_500.script_token.raw_text == "$500"
    assert tok_500.start_s >= 2.5


# ---------------------------------------------------------------------------
# ADV_10: Proper Nouns (Korean & Vietnamese)
# ---------------------------------------------------------------------------
def test_adv_10_proper_nouns():
    """
    ADV_10: Script has Korean & Vietnamese proper nouns.
    Fuzzy similarity correctly pairs words without modifying original casing or characters.
    """
    script = "Huấn luyện viên Park Hang-seo đã dẫn dắt đội tuyển Việt Nam."
    tokens = tokenize_script(script, language="vi")

    # ASR has unhyphenated or slightly varied words
    asr_data = [
        ("huấn", 0.5, 0.8), ("luyện", 0.8, 1.1), ("viên", 1.1, 1.4),
        ("park", 1.4, 1.7), ("hang", 1.7, 2.0), ("seo", 2.0, 2.3),
        ("đã", 2.3, 2.5), ("dẫn", 2.5, 2.8), ("dắt", 2.8, 3.1),
        ("đội", 3.1, 3.4), ("tuyển", 3.4, 3.7), ("việt", 3.7, 4.0), ("nam", 4.0, 4.3),
    ]
    asr = _make_asr(asr_data)

    aligner = HierarchicalScriptAligner()
    aligned, _, _, _ = aligner.align(tokens, asr, audio_duration_s=5.0, language="vi")

    park_tok = next(t for t in aligned if "Park" in t.script_token.raw_text)
    assert park_tok.script_token.raw_text == "Park"


# ---------------------------------------------------------------------------
# ADV_11: Music Interlude
# ---------------------------------------------------------------------------
def test_adv_11_music_interlude_silence_bridge():
    """
    ADV_11: 45s music interlude without speech between scenes.
    VAD-aware silence bridge preserves monotonic anchor chain across the interlude.
    """
    script = (
        "Chào mừng bạn đến với phần một của video.\n\n"
        "Bây giờ chúng ta cùng bước sang phần hai của video."
    )
    tokens = tokenize_script(script, language="vi")

    # Scene 1: 1.0 - 4.5s; Interlude: 4.5s - 50.0s (45.5s gap); Scene 2: 50.0s - 54.0s
    asr_s1 = [
        ("chào", 1.0, 1.3), ("mừng", 1.3, 1.7), ("bạn", 1.7, 2.0),
        ("đến", 2.0, 2.3), ("với", 2.3, 2.6), ("phần", 2.6, 2.9),
        ("một", 2.9, 3.2), ("của", 3.2, 3.5), ("video", 3.5, 4.0),
    ]
    asr_s2 = [
        ("bây", 50.0, 50.3), ("giờ", 50.3, 50.6), ("chúng", 50.6, 50.9),
        ("ta", 50.9, 51.2), ("cùng", 51.2, 51.5), ("bước", 51.5, 51.8),
        ("sang", 51.8, 52.1), ("phần", 52.1, 52.4), ("hai", 52.4, 52.7),
        ("của", 52.7, 53.0), ("video", 53.0, 53.5),
    ]
    asr = _make_asr(asr_s1 + asr_s2)

    finder = AnchorFinder()
    anchors = finder.find_anchors(tokens, asr, audio_duration_s=55.0)

    # Must find anchors in BOTH Scene 1 and Scene 2 across the 45s music interlude
    s1_anc = [a for a in anchors if a.end_s <= 5.0]
    s2_anc = [a for a in anchors if a.start_s >= 49.0]
    assert len(s1_anc) > 0, "Anchor in scene 1 required"
    assert len(s2_anc) > 0, "Anchor in scene 2 required (silence bridge must span 45s interlude)"


# ---------------------------------------------------------------------------
# ADV_12: Trailing Mismatch (Audited 44-Sentence Collapse Regression)
# ---------------------------------------------------------------------------
def test_adv_12_trailing_mismatch_44_sentences():
    """
    ADV_12: Critical regression test reproducing the audited collapse failure.
    Script has 44 sentences remaining, but audio ends with only 19.6s left.
    Must NOT compress all 44 sentences into 19.6s at impossible speeds.
    Excess sentences must be recorded as SCRIPT_TAIL_UNSPOKEN.
    """
    # 44 sentences with ~8 words each = ~350 tokens
    sentences = [f"Đây là câu văn số {i+1} của phần kết thúc kịch bản." for i in range(44)]
    script = "Phần đầu kịch bản được nói đầy đủ và rõ ràng.\n\n" + "\n\n".join(sentences)
    tokens = tokenize_script(script, language="vi")

    # Audio has 5.0s for the first sentence, then only 19.6s left with speech ending at 24.6s
    asr_intro = [
        ("phần", 0.5, 0.8), ("đầu", 0.8, 1.2), ("kịch", 1.2, 1.6), ("bản", 1.6, 2.0),
        ("được", 2.0, 2.3), ("nói", 2.3, 2.7), ("đầy", 2.7, 3.1), ("đủ", 3.1, 3.5),
        ("và", 3.5, 3.8), ("rõ", 3.8, 4.2), ("ràng", 4.2, 4.8),
    ]
    # In the remaining 19.6s (5.0s to 24.6s), speaker actually only spoke 2 sentences (18 words)
    asr_tail = [
        ("đây", 5.5, 5.8), ("là", 5.8, 6.1), ("câu", 6.1, 6.4), ("văn", 6.4, 6.8),
        ("số", 6.8, 7.1), ("1", 7.1, 7.4), ("của", 7.4, 7.7), ("phần", 7.7, 8.0),
        ("kết", 8.0, 8.3), ("thúc", 8.3, 8.6), ("kịch", 8.6, 8.9), ("bản", 8.9, 9.3),
    ]
    asr = _make_asr(asr_intro + asr_tail)

    aligner = HierarchicalScriptAligner()
    aligned, anchors, health, unmatched_spans = aligner.align(
        tokens, asr, audio_duration_s=24.6, language="vi"
    )

    # Must record SCRIPT_TAIL_UNSPOKEN span
    tail_spans = [s for s in unmatched_spans if s.reason == "SCRIPT_TAIL_UNSPOKEN"]
    assert len(tail_spans) > 0, "Unspoken 44 sentences must be recorded as SCRIPT_TAIL_UNSPOKEN"

    # Segment cues and inspect with CollapseDetector
    segmenter = SubtitleSegmenter()
    cues = segmenter.segment(aligned)

    # The cues that ARE generated must be healthy (< 5.0 tokens/sec)
    detector = CollapseDetector()
    inspection = detector.inspect(cues=cues, region_health_list=health, language="vi", audio_duration_s=24.6, allow_degraded=True)

    assert inspection.has_collapse is False, f"Collapse occurred! Violations: {inspection.violations}"
    assert inspection.max_reading_speed_tps <= 5.0, f"Speed violation: {inspection.max_reading_speed_tps} tps"
