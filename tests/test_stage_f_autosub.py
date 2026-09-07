"""
tests/test_stage_f_autosub.py
Verification suite for Stage F (GAP-11: AutoSub without script).
Tests both python pipeline behavior (empty script = ASR transcription mode)
and renderer script/UI contract.
"""
import pytest
import sys
import os

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.models import (
    AlignmentOptions,
    SpeechWordTimestamp,
)
from core.subtitles.speech_timestamp_provider import MockSpeechTimestampProvider
from core.subtitles.pipeline import (
    ScriptToSrtPipeline,
    ScriptToSrtError,
)
from core.srt_timeline import parse_srt_file

import wave

@pytest.fixture
def dummy_wav(tmp_path):
    wav_path = str(tmp_path / "dummy.wav")
    with wave.open(wav_path, "w") as f:
        f.setnchannels(1)
        f.setsampwidth(2)
        f.setframerate(16000)
        f.writeframes(b"\x00" * 32000)
    return wav_path


def test_autosub_generates_srt_from_speech_without_script(dummy_wav):
    """Verify that empty script_text triggers AutoSub mode when allow_autosub=True."""
    asr_words = [
        SpeechWordTimestamp(word="Xin", start=0.5, end=0.8, confidence=0.95),
        SpeechWordTimestamp(word="chào", start=0.8, end=1.2, confidence=0.92),
        SpeechWordTimestamp(word="các", start=1.2, end=1.5, confidence=0.90),
        SpeechWordTimestamp(word="bạn", start=1.5, end=1.8, confidence=0.88),
        SpeechWordTimestamp(word="đã", start=2.0, end=2.2, confidence=0.91),
        SpeechWordTimestamp(word="đến", start=2.2, end=2.5, confidence=0.94),
        SpeechWordTimestamp(word="với", start=2.5, end=2.8, confidence=0.93),
        SpeechWordTimestamp(word="kênh.", start=2.8, end=3.2, confidence=0.96),
    ]
    mock_provider = MockSpeechTimestampProvider(predefined_timestamps=asr_words)
    pipeline = ScriptToSrtPipeline(asr_provider=mock_provider)

    # Empty script should NOT throw SCRIPT_EMPTY when allow_autosub=True
    result = pipeline.run(script_text="", audio_path=dummy_wav, allow_autosub=True)

    assert result is not None
    assert result.cue_count >= 1
    assert result.matched_percentage == 100.0
    assert "Xin chào các bạn đã đến với kênh." in result.srt_content

    # Validate SRT parsing
    cues = parse_srt_file(result.srt_content)
    assert len(cues) >= 1
    assert cues[0].start_us == 500_000
    assert cues[0].end_us == 3_200_000


def test_autosub_whitespace_only_script_triggers_autosub(dummy_wav):
    """Verify that script containing only spaces/newlines also runs AutoSub when requested."""
    asr_words = [
        SpeechWordTimestamp(word="AutoSub", start=1.0, end=1.5, confidence=0.85),
        SpeechWordTimestamp(word="test", start=1.5, end=2.0, confidence=0.85),
    ]
    mock_provider = MockSpeechTimestampProvider(predefined_timestamps=asr_words)
    pipeline = ScriptToSrtPipeline(asr_provider=mock_provider)

    result = pipeline.run(script_text="   \n\n\t  ", audio_path=dummy_wav, allow_autosub=True)
    assert result.cue_count >= 1
    assert "AutoSub test" in result.srt_content


def test_empty_script_without_autosub_raises_script_empty(dummy_wav):
    """Verify that empty script still raises SCRIPT_EMPTY if allow_autosub is False."""
    mock_provider = MockSpeechTimestampProvider(predefined_timestamps=[])
    pipeline = ScriptToSrtPipeline(asr_provider=mock_provider)

    with pytest.raises(ScriptToSrtError) as exc_info:
        pipeline.run(script_text="", audio_path=dummy_wav, allow_autosub=False)

    assert exc_info.value.code == "SCRIPT_EMPTY"


def test_autosub_no_speech_detected_raises_error(dummy_wav):
    """Verify that when no speech is detected in AutoSub mode, an informative error is raised."""
    mock_provider = MockSpeechTimestampProvider(predefined_timestamps=[])
    pipeline = ScriptToSrtPipeline(asr_provider=mock_provider)

    with pytest.raises(ScriptToSrtError) as exc_info:
        pipeline.run(script_text="", audio_path=dummy_wav, allow_autosub=True)

    assert exc_info.value.code == "NO_SPEECH_DETECTED"


def test_autosub_missing_audio_raises_error():
    """Verify missing audio validation still holds in AutoSub mode."""
    mock_provider = MockSpeechTimestampProvider(predefined_timestamps=[])
    pipeline = ScriptToSrtPipeline(asr_provider=mock_provider)

    with pytest.raises(ScriptToSrtError) as exc_info:
        pipeline.run(script_text="", audio_path="/non/existent/audio.wav", allow_autosub=True)

    assert exc_info.value.code == "AUDIO_MISSING"


def test_forced_alignment_with_script_still_works(dummy_wav):
    """Verify normal FA workflow is preserved when script is present."""
    asr_words = [
        SpeechWordTimestamp(word="Xin", start=0.5, end=0.8),
        SpeechWordTimestamp(word="chào", start=0.8, end=1.2),
    ]
    mock_provider = MockSpeechTimestampProvider(predefined_timestamps=asr_words)
    pipeline = ScriptToSrtPipeline(asr_provider=mock_provider)

    result = pipeline.run(script_text="Xin chào", audio_path=dummy_wav)
    assert result.cue_count == 1
    assert "Xin chào" in result.srt_content


def test_desktop_ui_has_mode_switching_and_autosub_tabs():
    """Verify index.html and app.js contract for AutoSub UI tabs."""
    html_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/index.html")
    js_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/app.js")

    with open(html_path, "r", encoding="utf-8") as f:
        html = f.read()

    assert 'id="tabModeFA"' in html
    assert 'id="tabModeSTT"' in html

    with open(js_path, "r", encoding="utf-8") as f:
        js = f.read()

    assert "state.subtitleWorkflowMode" in js
    assert "DOM.tabModeFA" in js
    assert "DOM.tabModeSTT" in js
    assert "AutoSub" in js
