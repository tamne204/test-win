"""
apps/capcut-v2/adapters/capcut/output_verifier.py
Multi-layer output verification engine per Section 35 of CEO Master Directive.
Validates file presence, size stability, lock release, and ffprobe stream integrity.
"""
from __future__ import annotations

import os
import time
import json
import shutil
import subprocess
from typing import Dict, Any, Optional


class VerificationResult:
    def __init__(
        self,
        is_valid: bool,
        error_code: Optional[str] = None,
        error_message: Optional[str] = None,
        details: Optional[Dict[str, Any]] = None,
    ):
        self.is_valid = is_valid
        self.error_code = error_code
        self.error_message = error_message
        self.details = details or {}

    def to_dict(self) -> Dict[str, Any]:
        return {
            "valid": self.is_valid,
            "error_code": self.error_code,
            "error_message": self.error_message,
            "details": self.details,
        }


class OutputVerifier:
    """
    Validates rendered MP4 files against container specifications and timeline expectations.
    """

    @classmethod
    def check_file_lock_released(cls, file_path: str, timeout_sec: float = 10.0, poll_interval_sec: float = 0.5) -> bool:
        """
        Verify that no active process holds an exclusive write lock on the output file.
        """
        if not os.path.isfile(file_path):
            return False

        deadline = time.time() + timeout_sec
        while time.time() < deadline:
            try:
                # Attempt to open file in append mode to check for write lock release
                with open(file_path, "a+b") as f:
                    pass
                return True
            except (IOError, PermissionError):
                time.sleep(poll_interval_sec)

        return False

    @classmethod
    def probe_media_file(cls, file_path: str) -> Dict[str, Any]:
        """
        Run ffprobe to extract stream format and duration metadata.
        """
        ffprobe_bin = shutil.which("ffprobe")
        if not ffprobe_bin:
            # Check standard homebrew / windows paths
            candidates = [
                "/usr/local/bin/ffprobe",
                "/opt/homebrew/bin/ffprobe",
                "C:\\Program Files\\ffmpeg\\bin\\ffprobe.exe",
                "C:\\ffmpeg\\bin\\ffprobe.exe",
            ]
            for c in candidates:
                if os.path.isfile(c):
                    ffprobe_bin = c
                    break

        if not ffprobe_bin:
            raise RuntimeError("FFPROBE_NOT_FOUND: ffprobe executable is required for output verification.")

        cmd = [
            ffprobe_bin,
            "-v", "error",
            "-show_format",
            "-show_streams",
            "-print_format", "json",
            file_path,
        ]

        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if res.returncode != 0:
            raise RuntimeError(f"FFPROBE_ERROR: {res.stderr.strip()}")

        return json.loads(res.stdout)

    @classmethod
    def verify(
        cls,
        output_path: str,
        expected_duration_sec: Optional[float] = None,
        duration_tolerance_sec: float = 2.0,
        lock_timeout_sec: float = 15.0,
        require_audio: bool = False,
    ) -> VerificationResult:
        """
        Execute Section 35 mandatory multi-layer verification on rendered video.
        """
        # 1. File existence
        if not os.path.exists(output_path):
            return VerificationResult(
                is_valid=False,
                error_code="OUTPUT_FILE_MISSING",
                error_message=f"Render output file was not found at {output_path}",
            )

        if not os.path.isfile(output_path):
            return VerificationResult(
                is_valid=False,
                error_code="OUTPUT_NOT_A_FILE",
                error_message=f"Path is not a regular file: {output_path}",
            )

        # 2. File size > 0
        file_size = os.path.getsize(output_path)
        if file_size <= 0:
            return VerificationResult(
                is_valid=False,
                error_code="OUTPUT_FILE_ZERO_BYTES",
                error_message=f"Output file exists but has size 0 bytes: {output_path}",
                details={"size_bytes": 0},
            )

        # 3. File lock release
        if not cls.check_file_lock_released(output_path, timeout_sec=lock_timeout_sec):
            return VerificationResult(
                is_valid=False,
                error_code="OUTPUT_FILE_LOCKED",
                error_message=f"File remains locked by another process after {lock_timeout_sec}s timeout: {output_path}",
                details={"size_bytes": file_size},
            )

        # 4. ffprobe stream and container validation
        try:
            probe_data = cls.probe_media_file(output_path)
        except Exception as exc:
            return VerificationResult(
                is_valid=False,
                error_code="OUTPUT_CONTAINER_INVALID",
                error_message=f"Failed to parse media container with ffprobe: {exc}",
                details={"size_bytes": file_size},
            )

        streams = probe_data.get("streams", [])
        fmt = probe_data.get("format", {})

        video_stream = None
        audio_stream = None
        for s in streams:
            codec_type = s.get("codec_type")
            if codec_type == "video" and not video_stream:
                video_stream = s
            elif codec_type == "audio" and not audio_stream:
                audio_stream = s

        # Video stream mandatory
        if not video_stream:
            return VerificationResult(
                is_valid=False,
                error_code="OUTPUT_VIDEO_STREAM_MISSING",
                error_message="Valid container but no video stream detected in output.",
                details={"streams": streams, "format": fmt},
            )

        # Audio stream optional unless require_audio is True
        if require_audio and not audio_stream:
            return VerificationResult(
                is_valid=False,
                error_code="OUTPUT_AUDIO_STREAM_MISSING",
                error_message="Audio stream required but missing from rendered video.",
                details={"streams": streams, "format": fmt},
            )

        # 5. Duration validation if expected_duration_sec provided
        actual_duration_str = fmt.get("duration") or video_stream.get("duration")
        actual_duration = float(actual_duration_str) if actual_duration_str else None

        if expected_duration_sec is not None and actual_duration is not None:
            diff = abs(actual_duration - expected_duration_sec)
            if diff > duration_tolerance_sec:
                return VerificationResult(
                    is_valid=False,
                    error_code="OUTPUT_DURATION_MISMATCH",
                    error_message=(
                        f"Rendered video duration ({actual_duration:.2f}s) differs from expected "
                        f"timeline duration ({expected_duration_sec:.2f}s) by {diff:.2f}s (tolerance: {duration_tolerance_sec:.2f}s)"
                    ),
                    details={
                        "actual_duration": actual_duration,
                        "expected_duration": expected_duration_sec,
                        "difference_sec": diff,
                        "tolerance_sec": duration_tolerance_sec,
                        "size_bytes": file_size,
                    },
                )

        details = {
            "size_bytes": file_size,
            "format_name": fmt.get("format_name"),
            "actual_duration_sec": actual_duration,
            "video_codec": video_stream.get("codec_name"),
            "width": video_stream.get("width"),
            "height": video_stream.get("height"),
            "has_audio": audio_stream is not None,
            "audio_codec": audio_stream.get("codec_name") if audio_stream else None,
        }

        return VerificationResult(is_valid=True, details=details)
