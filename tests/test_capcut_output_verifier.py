"""
tests/test_capcut_output_verifier.py
Unit tests for OutputVerifier per Section 35 of CEO Master Directive.
"""
import os
import sys
import tempfile
import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.insert(0, V2_DIR)

from adapters.capcut.output_verifier import OutputVerifier, VerificationResult


def test_output_verifier_missing_file():
    res = OutputVerifier.verify("/path/does/not/exist.mp4")
    assert res.is_valid is False
    assert res.error_code == "OUTPUT_FILE_MISSING"


def test_output_verifier_zero_byte_file():
    with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as f:
        tmp_name = f.name
    try:
        res = OutputVerifier.verify(tmp_name)
        assert res.is_valid is False
        assert res.error_code == "OUTPUT_FILE_ZERO_BYTES"
    finally:
        if os.path.exists(tmp_name):
            os.remove(tmp_name)


def test_output_verifier_invalid_container():
    with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as f:
        f.write(b"NOT_A_VALID_MP4_HEADER_GARBAGE")
        tmp_name = f.name
    try:
        res = OutputVerifier.verify(tmp_name)
        assert res.is_valid is False
        assert res.error_code in ("OUTPUT_CONTAINER_INVALID", "OUTPUT_VIDEO_STREAM_MISSING")
    finally:
        if os.path.exists(tmp_name):
            os.remove(tmp_name)


def test_output_verifier_with_real_video():
    # If a test audio exists in the repo
    sample_audio = "/Users/2tamne/tool ffmpeg/test_5s.wav"
    if os.path.isfile(sample_audio):
        res = OutputVerifier.verify(sample_audio)
        # Audio file does not have video stream -> OUTPUT_VIDEO_STREAM_MISSING
        assert res.is_valid is False
        assert res.error_code == "OUTPUT_VIDEO_STREAM_MISSING"

    real_mp4 = "/Users/2tamne/tool ffmpeg/test_out.mp4"
    if os.path.isfile(real_mp4):
        res2 = OutputVerifier.verify(real_mp4)
        assert res2.is_valid is True
        assert res2.details.get("video_codec") is not None
        assert res2.details.get("width") > 0
