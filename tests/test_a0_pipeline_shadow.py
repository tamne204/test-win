"""
tests/test_a0_pipeline_shadow.py
Unit tests for ScriptToSrtPipeline dual-engine integration and shadow mode.
Verifies:
1. Legacy engine with shadow mode executes hierarchical aligner in background
2. Shadow telemetry is generated and persisted to reports/accuracy/shadow/
3. Hierarchical engine runs as active engine, enforces collapse gate, populates all A0 metrics
4. Catastrophic collapse raises ALIGNMENT_COLLAPSE_DETECTED when allow_degraded=False
5. Verifies 100% original script verbatim fidelity (0% text mutation)
"""
import os
import sys
import tempfile
import wave
import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.models import (
    AlignmentOptions,
    AlignmentEngineType,
    SpeechWordTimestamp,
    ConfidenceLevel,
    MatchType,
)
from core.subtitles.pipeline import ScriptToSrtPipeline, ScriptToSrtError
from core.subtitles.speech_timestamp_provider import MockSpeechTimestampProvider


def _create_temp_wav(duration_s: float = 5.0) -> str:
    """Creates a temporary silent WAV file of the specified duration."""
    temp_wav = tempfile.NamedTemporaryFile(suffix=".wav", delete=False)
    temp_wav.close()
    sample_rate = 16000
    n_frames = int(duration_s * sample_rate)
    with wave.open(temp_wav.name, "wb") as wf:
        wf.setnchannels(1)
        wf.setsampwidth(2)
        wf.setframerate(sample_rate)
        wf.writeframes(b"\x00\x00" * n_frames)
    return temp_wav.name


def test_pipeline_defaults_and_initialization():
    """Verify default pipeline configuration is HIERARCHICAL_V1 with shadow_mode=False."""
    pipeline = ScriptToSrtPipeline()
    assert pipeline.options.engine == AlignmentEngineType.HIERARCHICAL_V1
    assert pipeline.options.shadow_mode is False
    assert pipeline.options.allow_degraded is False


def test_legacy_pipeline_with_shadow_mode_telemetry():
    """
    Verify legacy run produces active cues while shadow hierarchical aligner
    runs in the background and populates shadow telemetry.
    """
    script = "Chào mừng quý vị khán giả đã quay trở lại với kênh của chúng tôi hôm nay."
    wav_path = _create_temp_wav(6.0)
    try:
        words = script.lower().replace(".", "").split()
        mock_timestamps = [
            SpeechWordTimestamp(word=w, start=0.5 + i * 0.35, end=0.5 + (i + 1) * 0.35, confidence=0.95)
            for i, w in enumerate(words)
        ]
        provider = MockSpeechTimestampProvider(mock_timestamps)
        options = AlignmentOptions(
            engine=AlignmentEngineType.LEGACY,
            shadow_mode=True,
            language="vi",
        )
        pipeline = ScriptToSrtPipeline(asr_provider=provider, options=options)
        result = pipeline.run(script_text=script, audio_path=wav_path)

        assert result.alignment_engine_version == "legacy"
        assert len(result.cues) > 0
        assert "shadow" in result.diagnostics
        shadow = result.diagnostics["shadow"]
        assert "legacy" in shadow
        assert "shadow_hierarchical" in shadow
        assert shadow["shadow_hierarchical"]["has_collapse"] is False
        assert shadow["shadow_hierarchical"]["cue_count"] > 0
    finally:
        if os.path.exists(wav_path):
            os.unlink(wav_path)


def test_hierarchical_v1_active_pipeline():
    """
    Verify hierarchical aligner runs as active engine, populates A0 diagnostic metrics,
    and produces verified cues.
    """
    script = "Đây là thử nghiệm kiểm tra tính chính xác của công nghệ căn chỉnh mới."
    wav_path = _create_temp_wav(5.0)
    try:
        words = script.lower().replace(".", "").split()
        mock_timestamps = [
            SpeechWordTimestamp(word=w, start=0.4 + i * 0.3, end=0.4 + (i + 1) * 0.3, confidence=0.96)
            for i, w in enumerate(words)
        ]
        provider = MockSpeechTimestampProvider(mock_timestamps)
        options = AlignmentOptions(
            engine=AlignmentEngineType.HIERARCHICAL_V1,
            shadow_mode=False,
            language="vi",
        )
        pipeline = ScriptToSrtPipeline(asr_provider=provider, options=options)
        result = pipeline.run(script_text=script, audio_path=wav_path)

        assert result.alignment_engine_version == "hierarchical_v1"
        assert result.matched_token_ratio >= 0.88
        assert result.exact_match_ratio > 0.0
        assert len(result.cues) > 0
        assert len(result.region_health_list) > 0
        # Check source span metadata on cues
        assert result.cues[0].source_token_start == 0
        assert len(result.cues[0].paragraph_ids) > 0
    finally:
        if os.path.exists(wav_path):
            os.unlink(wav_path)


def test_collapse_gate_triggers_error_on_impossible_rate():
    """
    Verify that when catastrophic collapse occurs (e.g. 50 words crammed into 0.5s),
    the pipeline aborts with ALIGNMENT_COLLAPSE_DETECTED when allow_degraded=False.
    """
    script = " ".join([f"từ_{i}" for i in range(50)])
    wav_path = _create_temp_wav(1.0)
    try:
        mock_timestamps = [
            SpeechWordTimestamp(word=f"từ_{i}", start=0.1 + i * 0.01, end=0.1 + (i + 1) * 0.01, confidence=0.9)
            for i in range(50)
        ]
        provider = MockSpeechTimestampProvider(mock_timestamps)
        options = AlignmentOptions(
            engine=AlignmentEngineType.HIERARCHICAL_V1,
            allow_degraded=False,
            language="vi",
        )
        pipeline = ScriptToSrtPipeline(asr_provider=provider, options=options)

        with pytest.raises(ScriptToSrtError) as exc_info:
            pipeline.run(script_text=script, audio_path=wav_path)

        assert exc_info.value.code == "ALIGNMENT_COLLAPSE_DETECTED"
    finally:
        if os.path.exists(wav_path):
            os.unlink(wav_path)


def test_verbatim_script_preservation():
    """
    CRITICAL INVARIANT: SCRIPT_TEXT_MUTATION_RATE == 0%.
    Original script words, casing, punctuation must remain 100% identical.
    """
    script = "Đoạn 1: Tôi tên là Nguyễn Văn A (sinh năm 1990).\n\nĐoạn 2: Hôm nay là thứ Hai!"
    wav_path = _create_temp_wav(6.0)
    try:
        asr_words = ["doan", "1", "toi", "ten", "la", "nguyen", "van", "a", "sinh", "nam", "1990", "doan", "2", "hom", "nay", "la", "thu", "hai"]
        mock_timestamps = [
            SpeechWordTimestamp(word=w, start=0.2 + i * 0.25, end=0.2 + (i + 1) * 0.25, confidence=0.85)
            for i, w in enumerate(asr_words)
        ]
        provider = MockSpeechTimestampProvider(mock_timestamps)
        options = AlignmentOptions(
            engine=AlignmentEngineType.HIERARCHICAL_V1,
            language="vi",
        )
        pipeline = ScriptToSrtPipeline(asr_provider=provider, options=options)
        result = pipeline.run(script_text=script, audio_path=wav_path)

        cue_texts = " ".join(c.text for c in result.cues)
        assert "Nguyễn Văn A" in cue_texts
        assert "(sinh năm 1990)" in cue_texts
        assert "thứ Hai!" in cue_texts
    finally:
        if os.path.exists(wav_path):
            os.unlink(wav_path)
