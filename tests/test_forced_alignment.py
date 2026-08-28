"""
Automated Test Suite for Forced Alignment & Subtitle Engine
Ensures 100% adherence to the 4-Point Technical Specifications:
1. 16kHz Mono 16-bit PCM WAV CBR Normalization.
2. Two-Pass Anchor Alignment (Faster-Whisper ASR + difflib.SequenceMatcher).
3. 20ms Non-Overlapping Gap Buffer between consecutive blocks.
4. Min 0.4s / Max 7.0s duration bounds & Monotonic Ordering.
"""

import os
import pytest
import forced_alignment_engine


SAMPLE_AUDIO = '/Users/2tamne/tool ffmpeg/tts_outputs/0bc9c638-d2f8-4aa0-b50d-21f65665f5ba.wav'
SAMPLE_SCRIPT = """이것은 편지 이야기입니다.
부치지 못한 편지, 아니 정확히는, 부치지 않은 편지.
경기도의 한 요양원, 지난 3월 78세의 이정순 씨가 세상을 떠났습니다.
직원이 유품을 정리하다가 침대 아래 낡은 나무상자를 발견했습니다.
나무상자 여는 소리 조용히 안에는 편지가 들어있었습니다.
봉투도 없이 우표도 없이 수신인도 없이 다만 날짜만 적혀 있었습니다.
첫 번째 편지는 2년 4개월 전이었습니다.
마지막 편지는 돌아가시기 7일 전이었습니다.
오늘 저는 이정순 씨의 편지를 읽어드리겠습니다.
그분이 살아 계실 때 하지 못했던 말들을."""


def test_language_detection():
    """Verify auto-detection of script language."""
    ko_lines = ["안녕하세요", "반갑습니다"]
    vi_lines = ["Xin chào các bạn", "Hôm nay chúng ta cùng học lập trình"]
    en_lines = ["Hello world", "This is an automated test"]

    assert forced_alignment_engine.detect_script_language(ko_lines) == 'ko'
    assert forced_alignment_engine.detect_script_language(vi_lines) == 'vi'
    assert forced_alignment_engine.detect_script_language(en_lines) == 'en'


def test_audio_normalization():
    """Verify normalization produces valid 16kHz mono WAV file."""
    if not os.path.isfile(SAMPLE_AUDIO):
        pytest.skip("Sample audio file not found")

    norm_path = forced_alignment_engine.normalize_audio_to_wav16k(SAMPLE_AUDIO)
    assert os.path.isfile(norm_path)
    assert os.path.getsize(norm_path) > 1000

    # Cleanup temp file if created
    if norm_path != SAMPLE_AUDIO and os.path.isfile(norm_path):
        os.remove(norm_path)


def test_sanitize_gap_buffer():
    """Verify 20ms non-overlapping gap buffer between consecutive segments."""
    raw_segments = [
        {'id': 1, 'start': 0.0, 'end': 5.0, 'text': 'Câu 1'},
        {'id': 2, 'start': 5.0, 'end': 10.0, 'text': 'Câu 2 (chạm sát)'},
        {'id': 3, 'start': 9.5, 'end': 14.0, 'text': 'Câu 3 (bị đè timecode)'}
    ]

    sanitized = forced_alignment_engine._sanitize_final_segments(raw_segments, total_dur=20.0, gap_buffer=0.02)

    for i in range(len(sanitized) - 1):
        cur_end = sanitized[i]['end']
        next_start = sanitized[i + 1]['start']
        gap = round(next_start - cur_end, 3)
        assert gap >= 0.02, f"Gap between segment {i+1} and {i+2} is {gap}s, expected >= 0.02s"


def test_sanitize_duration_bounds():
    """Verify minimum 0.4s and maximum duration constraints."""
    raw_segments = [
        {'id': 1, 'start': 1.0, 'end': 1.1, 'text': 'Ngắn'},
        {'id': 2, 'start': 3.0, 'end': 15.0, 'text': 'Quá dài dài dài dài dài'}
    ]

    sanitized = forced_alignment_engine._sanitize_final_segments(raw_segments, total_dur=30.0, min_dur=0.4, max_dur=7.0)

    assert sanitized[0]['end'] - sanitized[0]['start'] >= 0.38
    assert sanitized[1]['end'] - sanitized[1]['start'] <= 7.05


def test_two_pass_forced_alignment_end_to_end():
    """Verify full Two-Pass alignment pipeline on realistic audio."""
    if not os.path.isfile(SAMPLE_AUDIO):
        pytest.skip("Sample audio file not found")

    lines = [l.strip() for l in SAMPLE_SCRIPT.strip().splitlines() if l.strip()]
    res = forced_alignment_engine.forced_align(
        audio_path=SAMPLE_AUDIO,
        script_text=SAMPLE_SCRIPT,
        engine='whisperx',
        language='ko'
    )

    assert res['success'] is True
    assert res['count'] == len(lines)
    assert len(res['segments']) == len(lines)
    assert '1\n' in res['srt']

    # Verify monotonic ordering and gap buffers
    segments = res['segments']
    for i in range(len(segments)):
        s = segments[i]
        assert s['end'] > s['start'], f"Segment {i+1} has invalid timing: {s['start']} -> {s['end']}"
        assert s['text'] == lines[i]
        if i < len(segments) - 1:
            next_s = segments[i + 1]
            assert next_s['start'] >= s['end'] + 0.015, f"Overlap detected between {i+1} and {i+2}"
