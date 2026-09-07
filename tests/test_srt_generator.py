"""
tests/test_srt_generator.py
Unit tests for srt_generator.py.
Verifies standard SubRip formatting, multilingual Unicode preservation, and validation.
"""
import pytest
import sys
import os

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.models import SubtitleCue, ConfidenceLevel
from core.subtitles.srt_generator import (
    generate_srt,
    validate_srt_content,
    format_srt_timestamp,
)


def test_format_srt_timestamp():
    """Verify millisecond-accurate SRT timestamp formatting."""
    assert format_srt_timestamp(0.0) == "00:00:00,000"
    assert format_srt_timestamp(1.25) == "00:00:01,250"
    assert format_srt_timestamp(65.505) == "00:01:05,505"
    assert format_srt_timestamp(3661.123) == "01:01:01,123"


def test_generate_and_validate_multilingual_srt():
    """Verify UTF-8 output with Vietnamese, Korean, Japanese, and English."""
    cues = [
        SubtitleCue(
            index=1,
            start_s=0.5,
            end_s=2.5,
            text="Tôi đang phát triển 2TOOLNE AutoEdit cho CapCut.",
            confidence=ConfidenceLevel.HIGH,
        ),
        SubtitleCue(
            index=2,
            start_s=2.6,
            end_s=4.8,
            text="안녕하세요! 이것은 자동 자막 생성 테스트입니다.",
            confidence=ConfidenceLevel.HIGH,
        ),
        SubtitleCue(
            index=3,
            start_s=5.0,
            end_s=7.2,
            text="こんにちは！自動字幕生成システムのテストです。",
            confidence=ConfidenceLevel.HIGH,
        ),
    ]

    srt_str = generate_srt(cues)
    assert "1\n00:00:00,500 --> 00:00:02,500\nTôi đang phát triển 2TOOLNE AutoEdit cho CapCut." in srt_str
    assert "2\n00:00:02,600 --> 00:00:04,800\n안녕하세요!" in srt_str
    assert "3\n00:00:05,000 --> 00:00:07,200\nこんにちは！" in srt_str

    is_valid, errors = validate_srt_content(srt_str)
    assert is_valid is True
    assert len(errors) == 0


def test_validate_srt_detects_corrupt_entries():
    """Verify validation flags overlapping timestamps and broken format."""
    # Bad timestamp: start >= end
    bad_srt_1 = "1\n00:00:03,000 --> 00:00:01,000\nNội dung sai mốc\n"
    is_valid, errors = validate_srt_content(bad_srt_1)
    assert is_valid is False
    assert any("start" in e and ">=" in e for e in errors)

    # Empty text
    bad_srt_2 = "1\n00:00:01,000 --> 00:00:02,000\n\n"
    is_valid, errors = validate_srt_content(bad_srt_2)
    assert is_valid is False
