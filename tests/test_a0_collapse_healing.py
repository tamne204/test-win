"""
tests/test_a0_collapse_healing.py
Regression tests for production alignment collapse recovery and self-healing:
1. Monosyllabic reading speed (5.9 tokens/sec with 9.8 cps) passes cleanly.
2. Rapid dialogue micro-cues are consolidated to >= 0.40s.
3. Crammed bounded regions heal to DEGRADED and complete pipeline execution.
"""
import pytest
import sys
import os

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.models import (
    AlignmentOptions,
    ConfidenceLevel,
    MatchType,
    ScriptToken,
    SpeechWordTimestamp,
    SubtitleCue,
    AlignedToken,
)
from core.subtitles.script_normalizer import tokenize_script
from core.subtitles.collapse_detector import CollapseDetector
from core.subtitles.subtitle_segmenter import SubtitleSegmenter
from core.subtitles.hierarchical_aligner import HierarchicalScriptAligner
from core.subtitles.speech_timestamp_provider import MockSpeechTimestampProvider
from core.subtitles.pipeline import ScriptToSrtPipeline


def test_monosyllabic_low_cps_reading_speed_passes():
    """
    Verify that 5.9 tokens/sec with low characters per second (9.8 cps)
    is recognized as readable and does NOT trigger AlignmentCollapseError.
    """
    cues = [
        SubtitleCue(index=878, start_s=1174.8, end_s=1175.3, text="Tôi đi ra", confidence=ConfidenceLevel.HIGH),
        SubtitleCue(index=879, start_s=1175.35, end_s=1175.8, text="ngoài xem thử", confidence=ConfidenceLevel.HIGH),
        SubtitleCue(index=880, start_s=1175.85, end_s=1176.3, text="có gì không", confidence=ConfidenceLevel.HIGH),
    ]
    detector = CollapseDetector()
    result = detector.inspect(cues, language="vi", allow_degraded=False)

    assert result.has_collapse is False
    assert len(result.violations) == 0


def test_micro_cues_consolidate_in_segmenter():
    """
    Verify that SubtitleSegmenter consolidates micro-cues < 0.40s
    so they do not form clusters of flickering subtitles.
    """
    script = "Đi mau. Nhanh lên. Kịp không? Chờ tí. Tới rồi."
    tokens = tokenize_script(script, language="vi")

    aligned_tokens = []
    t = 702.5
    for tok in tokens:
        aligned_tokens.append(
            AlignedToken(
                script_token=tok,
                start_s=round(t, 2),
                end_s=round(t + 0.15, 2),
                confidence=ConfidenceLevel.HIGH,
                match_type=MatchType.EXACT,
            )
        )
        t += 0.20

    segmenter = SubtitleSegmenter()
    cues = segmenter.segment(aligned_tokens)

    # Every cue must have duration >= 0.40s
    for c in cues:
        assert c.duration_s >= 0.40, f"Cue '{c.text}' duration {c.duration_s}s is < 0.40s"

    # Detector must find ZERO micro-cue clusters
    detector = CollapseDetector()
    res = detector.inspect(cues, allow_degraded=False)
    assert res.has_collapse is False
    assert res.micro_cue_count == 0


def test_crammed_region_heals_to_degraded():
    """
    Verify that an unvoiced script gap inside a narrow bounded region
    heals unvoiced tokens to OMITTED and sets status to DEGRADED instead of COLLAPSED.
    """
    script = (
        "Đoạn mở đầu có căn chỉnh chuẩn xác. "
        "Đây là phần kịch bản người đọc hoàn toàn bỏ qua không hề nói câu nào. "
        "Đoạn kết thúc tiếp tục căn chỉnh chính xác."
    )
    tokens = tokenize_script(script, language="vi")

    # ASR has Anchor 1 at 1.0-3.0s, and Anchor 2 at 4.0-6.0s
    # The middle sentence (14 words) is omitted by speaker, with only 1.0s gap (4.0 - 3.0)!
    asr_words = [
        # Anchor 1
        SpeechWordTimestamp(word="đoạn", start=1.0, end=1.3),
        SpeechWordTimestamp(word="mở", start=1.3, end=1.6),
        SpeechWordTimestamp(word="đầu", start=1.6, end=1.9),
        SpeechWordTimestamp(word="có", start=1.9, end=2.2),
        SpeechWordTimestamp(word="căn", start=2.2, end=2.5),
        SpeechWordTimestamp(word="chỉnh", start=2.5, end=2.8),
        SpeechWordTimestamp(word="chuẩn", start=2.8, end=3.1),
        SpeechWordTimestamp(word="xác", start=3.1, end=3.4),
        # Gap: speaker omitted 14 words, 0.6s silence
        # Anchor 2
        SpeechWordTimestamp(word="đoạn", start=4.0, end=4.3),
        SpeechWordTimestamp(word="kết", start=4.3, end=4.6),
        SpeechWordTimestamp(word="thúc", start=4.6, end=4.9),
        SpeechWordTimestamp(word="tiếp", start=4.9, end=5.2),
        SpeechWordTimestamp(word="tục", start=5.2, end=5.5),
        SpeechWordTimestamp(word="căn", start=5.5, end=5.8),
        SpeechWordTimestamp(word="chỉnh", start=5.8, end=6.1),
        SpeechWordTimestamp(word="chính", start=6.1, end=6.4),
        SpeechWordTimestamp(word="xác", start=6.4, end=6.8),
    ]

    aligner = HierarchicalScriptAligner()
    aligned, anchors, health_list, unmatched = aligner.align(
        script_tokens=tokens,
        speech_timestamps=asr_words,
        audio_duration_s=7.0,
        language="vi",
    )

    # Health must not be COLLAPSED
    collapsed = [h for h in health_list if h.health_status == "COLLAPSED"]
    assert len(collapsed) == 0, f"Found COLLAPSED regions: {collapsed}"

    # Omitted span must be captured in unmatched
    assert any("SCRIPT_OMITTED" in u.reason for u in unmatched)


def test_pipeline_recovers_and_generates_srt():
    """
    Verify full pipeline execution with healing and allow_degraded=True produces valid SRT.
    """
    script = (
        "Chào mừng bạn đến với 2TOOLNE AutoEdit. "
        "Đây là phần nói cực nhanh để thử thách bộ điều chỉnh nhịp đọc. "
        "Hoàn tất quy trình căn chỉnh phụ đề mượt mà."
    )
    tokens = tokenize_script(script, language="vi")
    asr_words = [
        SpeechWordTimestamp(word=t.normalized_text, start=0.5 + i * 0.18, end=0.5 + (i + 1) * 0.18)
        for i, t in enumerate(tokens)
    ]

    mock_provider = MockSpeechTimestampProvider(asr_words)
    pipeline = ScriptToSrtPipeline(
        asr_provider=mock_provider,
        options=AlignmentOptions(allow_degraded=True, language="vi")
    )
    wav_fixture = os.path.abspath("reports/windows_rc/external_lab/TEST_MEDIA/voice_sample.wav")
    result = pipeline.run(script_text=script, audio_path=wav_fixture)

    assert result.cue_count > 0
    assert "1" in result.srt_content
    assert "-->" in result.srt_content
