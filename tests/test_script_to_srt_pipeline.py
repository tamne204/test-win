"""
tests/test_script_to_srt_pipeline.py
Integration tests for the full Script-to-SRT pipeline and its integration
with the existing CapCut V2 SRT timeline and timeline builder.
"""
import pytest
import sys
import os
import threading

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
from core.srt_timeline import parse_srt_file, compute_srt_scene_boundaries
from core.timeline_builder import TimelineBuilder, TIMING_MODE_SRT_DRIVEN


AUDIO_FIXTURE = os.path.abspath("reports/windows_rc/external_lab/TEST_MEDIA/voice_sample.wav")
SAMPLE_IMAGE = os.path.abspath("reports/windows_rc/external_lab/TEST_MEDIA/clip_01_portrait.png")


def test_script_to_srt_pipeline_end_to_end_mock():
    """Verify complete pipeline execution and seamless integration into existing TimelineBuilder."""
    script = (
        "Chào mừng bạn đến với 2TOOLNE AutoEdit. "
        "Đây là tính năng căn chỉnh kịch bản tự động thành phụ đề chuẩn SRT cho CapCut."
    )

    # Synthetic ASR word timestamps simulating speech
    asr_words = [
        SpeechWordTimestamp(word="Chào", start=0.5, end=0.8),
        SpeechWordTimestamp(word="mừng", start=0.8, end=1.1),
        SpeechWordTimestamp(word="bạn", start=1.1, end=1.4),
        SpeechWordTimestamp(word="đến", start=1.4, end=1.7),
        SpeechWordTimestamp(word="với", start=1.7, end=2.0),
        SpeechWordTimestamp(word="tool", start=2.0, end=2.4),      # Fuzzy match for '2TOOLNE'
        SpeechWordTimestamp(word="autoedit", start=2.4, end=3.0),  # Matches 'AutoEdit'
        # Second sentence
        SpeechWordTimestamp(word="Đây", start=3.5, end=3.8),
        SpeechWordTimestamp(word="là", start=3.8, end=4.0),
        SpeechWordTimestamp(word="tính", start=4.0, end=4.3),
        SpeechWordTimestamp(word="năng", start=4.3, end=4.6),
        SpeechWordTimestamp(word="căn", start=4.6, end=4.9),
        SpeechWordTimestamp(word="chỉnh", start=4.9, end=5.2),
        SpeechWordTimestamp(word="kịch", start=5.2, end=5.5),
        SpeechWordTimestamp(word="bản", start=5.5, end=5.8),
        SpeechWordTimestamp(word="tự", start=5.8, end=6.1),
        SpeechWordTimestamp(word="động", start=6.1, end=6.4),
        SpeechWordTimestamp(word="thành", start=6.4, end=6.7),
        SpeechWordTimestamp(word="phụ", start=6.7, end=7.0),
        SpeechWordTimestamp(word="đề", start=7.0, end=7.3),
        SpeechWordTimestamp(word="chuẩn", start=7.3, end=7.6),
        SpeechWordTimestamp(word="srt", start=7.6, end=8.0),
        SpeechWordTimestamp(word="cho", start=8.0, end=8.3),
        SpeechWordTimestamp(word="capcut", start=8.3, end=8.8),
    ]

    mock_provider = MockSpeechTimestampProvider(asr_words)
    pipeline = ScriptToSrtPipeline(asr_provider=mock_provider)

    # Progress tracking collector
    stages_visited = []
    def on_progress(stage, frac, msg):
        stages_visited.append(stage)

    result = pipeline.run(
        script_text=script,
        audio_path=AUDIO_FIXTURE,
        progress_callback=on_progress,
    )

    # 1. Verify Pipeline Outcomes
    assert result.cue_count >= 2
    assert result.matched_percentage > 70.0
    assert "PREPARING_AUDIO" in stages_visited
    assert "TRANSCRIBING_AUDIO" in stages_visited
    assert "ALIGNING_SCRIPT" in stages_visited
    assert "BUILDING_SUBTITLES" in stages_visited
    assert "VALIDATING_SRT" in stages_visited
    assert "READY" in stages_visited

    # 2. Source of Truth Check: original text preserved verbatim
    raw_cues_text = " ".join(c.text for c in result.cues)
    assert "2TOOLNE AutoEdit." in raw_cues_text
    assert "chuẩn SRT cho CapCut." in raw_cues_text

    # 3. EXISTING PIPELINE INTEGRATION: Feed generated SRT into existing srt_timeline.py
    parsed_entries = parse_srt_file(result.srt_content)
    assert len(parsed_entries) == result.cue_count

    scenes = compute_srt_scene_boundaries(parsed_entries, min_duration_s=2.0, max_duration_s=5.0)
    assert len(scenes) > 0

    # 4. EXISTING PIPELINE INTEGRATION: Build EditPlan with generated SRT
    builder = TimelineBuilder()
    plan = builder.build(
        images=[SAMPLE_IMAGE],
        audio_path=AUDIO_FIXTURE,
        srt_source=result.srt_content,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
    )

    assert plan is not None
    assert len(plan.clips) > 0
    assert len(plan.captions) == result.cue_count
    assert plan.captions[0].text == result.cues[0].text


def test_script_empty_error():
    """Verify SCRIPT_EMPTY error when empty script is passed."""
    pipeline = ScriptToSrtPipeline(asr_provider=MockSpeechTimestampProvider())
    with pytest.raises(ScriptToSrtError) as exc:
        pipeline.run(script_text="", audio_path=AUDIO_FIXTURE)
    assert exc.value.code == "SCRIPT_EMPTY"


def test_audio_missing_error():
    """Verify AUDIO_MISSING error when audio file does not exist."""
    pipeline = ScriptToSrtPipeline(asr_provider=MockSpeechTimestampProvider())
    with pytest.raises(ScriptToSrtError) as exc:
        pipeline.run(script_text="Kịch bản mẫu", audio_path="/non/existent/audio.wav")
    assert exc.value.code == "AUDIO_MISSING"


def test_cancellation_aborts_pipeline():
    """Verify cancellation token stops pipeline cleanly without resource locks."""
    cancel_event = threading.Event()
    cancel_event.set()  # Pre-cancelled

    pipeline = ScriptToSrtPipeline(asr_provider=MockSpeechTimestampProvider())
    with pytest.raises(ScriptToSrtError) as exc:
        pipeline.run(
            script_text="Kịch bản mẫu để kiểm tra hủy",
            audio_path=AUDIO_FIXTURE,
            cancellation_token=cancel_event,
        )
    assert exc.value.code == "CANCELLED"
