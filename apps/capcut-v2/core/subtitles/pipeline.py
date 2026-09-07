"""
apps/capcut-v2/core/subtitles/pipeline.py
Master Script-to-SRT Alignment Pipeline.
Coordinates:
Audio + Script -> Normalize -> Transcribe (ASR) -> Monotonic Align -> Segment -> SRT
Original script is the absolute source of truth.
"""
from __future__ import annotations

import os
import threading
import time
from typing import Optional, Callable, Dict, Any, List

from .models import (
    AlignmentOptions,
    AlignmentResult,
    ConfidenceLevel,
    SubtitleCue,
    ScriptToken,
    AlignedToken,
    MatchType,
)
from .script_normalizer import tokenize_script, detect_language
from .speech_timestamp_provider import (
    SpeechTimestampProvider,
    FasterWhisperTimestampProvider,
    ASRError,
    ASRModelMissingError,
    ASRFailedError,
    ASRRuntimeIncompleteError,
)
from .script_aligner import ScriptAligner
from .subtitle_segmenter import SubtitleSegmenter
from .srt_generator import generate_srt, validate_srt_content


class ScriptToSrtError(Exception):
    """Base exception for pipeline errors with machine-readable error codes."""
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


def get_audio_duration_s(audio_path: str) -> float:
    """
    Get duration in seconds of an audio file using soundfile, wave, or ffprobe fallback.
    """
    if not os.path.isfile(audio_path):
        raise FileNotFoundError(f"Audio file does not exist: {audio_path}")

    # 1. Try soundfile
    try:
        import soundfile as sf
        info = sf.info(audio_path)
        return float(info.duration)
    except Exception:
        pass

    # 2. Try standard wave library for WAV files
    try:
        import wave
        with wave.open(audio_path, "rb") as wf:
            frames = wf.getnframes()
            rate = wf.getframerate()
            return float(frames) / float(rate)
    except Exception:
        pass

    # 3. Fallback estimate
    return 0.0


class ScriptToSrtPipeline:
    """
    Executes the full Script-to-SRT alignment workflow.
    """

    def __init__(
        self,
        asr_provider: Optional[SpeechTimestampProvider] = None,
        options: Optional[AlignmentOptions] = None,
    ):
        self.options = options or AlignmentOptions()
        self.asr_provider = asr_provider or FasterWhisperTimestampProvider(
            model_size=self.options.model_size
        )
        self.aligner = ScriptAligner()
        self.segmenter = SubtitleSegmenter(self.options)

    def run(
        self,
        script_text: str,
        audio_path: str,
        progress_callback: Optional[Callable[[str, float, str], None]] = None,
        cancellation_token: Optional[threading.Event] = None,
        allow_autosub: bool = False,
        mode: str = "fa",
    ) -> AlignmentResult:
        """
        Execute alignment from original script and audio (or AutoSub if allow_autosub=True).

        Args:
            script_text: Original raw user script text.
            audio_path: Path to the target audio file.
            progress_callback: Optional callback receiving (stage, fraction, display_message).
            cancellation_token: Optional event for cancelling mid-flight.
            allow_autosub: If True, allow generating subtitles from audio without script.
            mode: 'fa' for Forced Alignment, 'autosub' or 'stt' for AutoSub.

        Returns:
            AlignmentResult containing cues, stats, and generated SRT.
        """
        def notify(stage: str, frac: float, msg: str):
            if progress_callback:
                progress_callback(stage, frac, msg)

        # 1. Input Validation
        is_autosub = allow_autosub or (mode.lower() in ("autosub", "stt"))
        has_script = bool(script_text and script_text.strip())

        if not has_script and not is_autosub:
            raise ScriptToSrtError("SCRIPT_EMPTY", "Nội dung kịch bản văn bản đang để trống.")

        if not audio_path:
            raise ScriptToSrtError("AUDIO_MISSING", "Chưa cung cấp đường dẫn tệp âm thanh.")

        if not os.path.isfile(audio_path):
            raise ScriptToSrtError("AUDIO_MISSING", f"Không tìm thấy tệp âm thanh tại: {audio_path}")

        try:
            audio_duration = get_audio_duration_s(audio_path)
        except Exception as e:
            raise ScriptToSrtError("AUDIO_UNREADABLE", f"Không thể đọc định dạng tệp âm thanh: {e}")

        if cancellation_token and cancellation_token.is_set():
            raise ScriptToSrtError("CANCELLED", "Quy trình đã bị hủy bởi người dùng.")

        notify("PREPARING_AUDIO", 0.1, "Đang kiểm tra và chuẩn bị dữ liệu âm thanh...")

        if has_script:
            # 2. Tokenize and Normalize Script (Keeping Original Verbatim)
            notify("NORMALIZING_SCRIPT", 0.2, "Đang xử lý phân tách từ và dấu câu kịch bản gốc...")
            detected_lang = detect_language(script_text) if self.options.language.upper() == "AUTO" else self.options.language.lower()
            script_tokens = tokenize_script(script_text, language=detected_lang)

            if not script_tokens:
                raise ScriptToSrtError("SCRIPT_EMPTY", "Không trích xuất được từ ngữ hợp lệ từ kịch bản.")
        else:
            detected_lang = self.options.language.lower() if self.options.language.upper() != "AUTO" else "vi"

        if cancellation_token and cancellation_token.is_set():
            raise ScriptToSrtError("CANCELLED", "Quy trình đã bị hủy bởi người dùng.")

        # 3. Transcribe Audio for Timestamps
        stage_title = "Đang nhận diện mốc thời gian phát âm qua ASR Engine..." if has_script else "Đang tự động nhận dạng giọng nói (AutoSub)..."
        notify("TRANSCRIBING_AUDIO", 0.35, stage_title)
        try:
            asr_timestamps = self.asr_provider.get_timestamps(
                audio_path=audio_path,
                language=detected_lang if detected_lang != "auto" else None,
                progress_callback=lambda st, fr: notify(st, 0.2 + fr * 0.4, "Đang phân tích âm học..."),
                cancellation_token=cancellation_token,
            )
        except ASRRuntimeIncompleteError as err:
            raise ScriptToSrtError(
                "ASR_RUNTIME_INCOMPLETE",
                "Thành phần nhận dạng giọng nói bị thiếu hoặc chưa được cài đặt đầy đủ. Vui lòng cài lại hoặc cập nhật 2TOOLNE AutoEdit."
            )
        except ASRModelMissingError as err:
            raise ScriptToSrtError("ASR_MODEL_MISSING", str(err))
        except ASRFailedError as err:
            err_str = str(err)
            if (
                "silero_vad" in err_str
                or "NO_SUCHFILE" in err_str
                or "ONNXRuntimeError" in err_str
                or "File doesn't exist" in err_str
                or ("assets" in err_str and ".onnx" in err_str)
            ):
                raise ScriptToSrtError(
                    "ASR_RUNTIME_INCOMPLETE",
                    "Thành phần nhận dạng giọng nói bị thiếu hoặc chưa được cài đặt đầy đủ. Vui lòng cài lại hoặc cập nhật 2TOOLNE AutoEdit."
                )
            raise ScriptToSrtError("ASR_FAILED", err_str)
        except Exception as err:
            err_str = str(err)
            if (
                "silero_vad" in err_str
                or "NO_SUCHFILE" in err_str
                or "ONNXRuntimeError" in err_str
                or "File doesn't exist" in err_str
                or ("assets" in err_str and ".onnx" in err_str)
            ):
                raise ScriptToSrtError(
                    "ASR_RUNTIME_INCOMPLETE",
                    "Thành phần nhận dạng giọng nói bị thiếu hoặc chưa được cài đặt đầy đủ. Vui lòng cài lại hoặc cập nhật 2TOOLNE AutoEdit."
                )
            raise ScriptToSrtError("ASR_FAILED", f"Lỗi không xác định trong bộ nhận diện âm thanh: {err}")

        if cancellation_token and cancellation_token.is_set():
            raise ScriptToSrtError("CANCELLED", "Quy trình đã bị hủy bởi người dùng.")

        if not asr_timestamps:
            if not has_script:
                raise ScriptToSrtError("NO_SPEECH_DETECTED", "Không phát hiện thấy giọng nói hoặc lời thoại nào trong tệp âm thanh.")

        if has_script:
            # 4. Monotonic Alignment
            notify("ALIGNING_SCRIPT", 0.7, "Đang căn chỉnh từ gốc vào mốc thời gian âm thanh...")
            aligned_tokens = self.aligner.align(
                script_tokens=script_tokens,
                speech_timestamps=asr_timestamps,
                audio_duration_s=audio_duration,
            )

            if not aligned_tokens:
                raise ScriptToSrtError("SCRIPT_ALIGNMENT_FAILED", "Không thể căn chỉnh kịch bản với âm thanh.")

            # Compute match statistics
            matched_count = sum(1 for t in aligned_tokens if t.confidence in (ConfidenceLevel.HIGH, ConfidenceLevel.MEDIUM))
            total_count = len(aligned_tokens)
            matched_pct = (matched_count / total_count * 100.0) if total_count > 0 else 0.0
            unmatched_pct = 100.0 - matched_pct

            warnings: List[str] = []
            if matched_pct < 40.0:
                warnings.append(
                    f"Độ khớp kịch bản thấp ({matched_pct:.1f}%). Vui lòng kiểm tra lại kịch bản hoặc âm thanh."
                )
        else:
            # AutoSub mode: construct aligned tokens directly from ASR words
            notify("BUILDING_AUTOSUB", 0.75, "Đang chuẩn hóa từ ngữ tự động tạo (AutoSub)...")
            aligned_tokens = []
            for idx, word in enumerate(asr_timestamps):
                st_tok = ScriptToken(
                    token_index=idx,
                    raw_text=word.word,
                    normalized_text=word.word.lower(),
                    char_start=0,
                    char_end=len(word.word),
                    leading_whitespace=" " if idx > 0 else "",
                    trailing_punctuation="",
                    is_sentence_break=False,
                    is_clause_break=False,
                )
                conf = ConfidenceLevel.HIGH if word.confidence >= 0.7 else (
                    ConfidenceLevel.MEDIUM if word.confidence >= 0.4 else ConfidenceLevel.LOW
                )
                aligned_tokens.append(
                    AlignedToken(
                        script_token=st_tok,
                        start_s=word.start,
                        end_s=word.end,
                        confidence=conf,
                        match_type=MatchType.EXACT,
                        asr_word=word.word,
                        asr_confidence=word.confidence,
                    )
                )
            matched_pct = 100.0
            unmatched_pct = 0.0
            warnings = []

        if cancellation_token and cancellation_token.is_set():
            raise ScriptToSrtError("CANCELLED", "Quy trình đã bị hủy bởi người dùng.")

        # 5. Segment into Subtitle Cues (2TOOLNE Standard 12-Word Style)
        notify("BUILDING_SUBTITLES", 0.85, "Đang phân đoạn câu phụ đề (chuẩn 12 từ 2TOOLNE)...")
        cues = self.segmenter.segment(aligned_tokens)

        # 6. Generate and Validate SRT
        notify("VALIDATING_SRT", 0.95, "Đang kiểm tra tính toàn vẹn của tệp SRT...")
        srt_content = generate_srt(cues)
        is_valid, validation_errors = validate_srt_content(srt_content)
        if not is_valid:
            warnings.extend(validation_errors)

        low_conf_count = sum(1 for c in cues if c.confidence == ConfidenceLevel.LOW)

        notify("READY", 1.0, "Hoàn tất tạo phụ đề từ kịch bản!")

        return AlignmentResult(
            cues=cues,
            original_script=script_text,
            srt_content=srt_content,
            matched_percentage=matched_pct,
            unmatched_percentage=unmatched_pct,
            audio_duration_s=audio_duration,
            cue_count=len(cues),
            low_confidence_count=low_conf_count,
            detected_language=detected_lang,
            warnings=warnings,
        )
