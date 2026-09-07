"""
apps/capcut-v2/core/subtitles/speech_timestamp_provider.py
SpeechTimestampProvider abstraction and providers:
1. SpeechTimestampProvider (ABC)
2. FasterWhisperTimestampProvider (Local CPU Faster-Whisper ASR)
3. MockSpeechTimestampProvider (Deterministic offline provider for tests & CI)
"""
from __future__ import annotations

import os
import threading
from abc import ABC, abstractmethod
from typing import List, Optional, Callable, Dict, Any

from .models import SpeechWordTimestamp


class ASRError(Exception):
    """Base exception for speech recognition errors."""
    pass


class ASRModelMissingError(ASRError):
    """Raised when the specified ASR model is missing or cannot be downloaded."""
    pass


class ASRFailedError(ASRError):
    """Raised when transcription fails during audio decoding or model inference."""
    pass


class ASRRuntimeIncompleteError(ASRError):
    """Raised when runtime speech assets (VAD, ONNX runtime, models) are missing or incomplete."""
    pass


class SpeechTimestampProvider(ABC):
    """
    Abstract interface for obtaining word-level speech timestamps.
    Shields the rest of the application from specific speech recognition libraries.
    """

    @abstractmethod
    def get_timestamps(
        self,
        audio_path: str,
        language: Optional[str] = None,
        progress_callback: Optional[Callable[[str, float], None]] = None,
        cancellation_token: Optional[threading.Event] = None,
    ) -> List[SpeechWordTimestamp]:
        """
        Extract word/token timestamps from the provided audio file.

        Args:
            audio_path: Path to the audio file.
            language: Optional language hint ('vi', 'en', 'ja', 'ko', or None for AUTO).
            progress_callback: Optional callback receiving (stage_name, fraction_0_to_1).
            cancellation_token: Optional threading.Event to check for early abort.

        Returns:
            List of SpeechWordTimestamp items ordered by time.
        """
        pass


class FasterWhisperTimestampProvider(SpeechTimestampProvider):
    """
    Local-first speech recognition provider utilizing faster-whisper.
    Operates on local CPU with configurable model sizes (tiny, base, small).
    """

    _cached_models: Dict[str, Any] = {}
    _cache_lock = threading.Lock()

    def __init__(self, model_size: str = "base", device: str = "cpu", compute_type: str = "int8"):
        self.model_size = model_size
        self.device = device
        self.compute_type = compute_type

    def _get_model(self):
        cache_key = f"{self.model_size}_{self.device}_{self.compute_type}"
        with self._cache_lock:
            if cache_key in self._cached_models:
                return self._cached_models[cache_key]

            try:
                from faster_whisper import WhisperModel
            except ImportError as err:
                raise ASRModelMissingError(
                    f"faster-whisper is not installed in the environment: {err}"
                )

            try:
                cpu_threads = min(8, os.cpu_count() or 4)
                # Note: On macOS ARM, float32 or int8 are robust; int8 provides fast inference
                model = WhisperModel(
                    self.model_size,
                    device=self.device,
                    compute_type=self.compute_type,
                    cpu_threads=cpu_threads,
                )
                self._cached_models[cache_key] = model
                return model
            except Exception as e:
                # Fallback to float32 if int8 is unsupported on host CPU
                try:
                    cpu_threads = min(8, os.cpu_count() or 4)
                    model = WhisperModel(
                        self.model_size,
                        device=self.device,
                        compute_type="float32",
                        cpu_threads=cpu_threads,
                    )
                    self._cached_models[cache_key] = model
                    return model
                except Exception as fallback_err:
                    raise ASRFailedError(
                        f"Failed to load faster-whisper model '{self.model_size}': {fallback_err}"
                    )

    def get_timestamps(
        self,
        audio_path: str,
        language: Optional[str] = None,
        progress_callback: Optional[Callable[[str, float], None]] = None,
        cancellation_token: Optional[threading.Event] = None,
    ) -> List[SpeechWordTimestamp]:
        if not os.path.isfile(audio_path):
            raise FileNotFoundError(f"Audio file not found: {audio_path}")

        if progress_callback:
            progress_callback("PREPARING_AUDIO", 0.1)

        if cancellation_token and cancellation_token.is_set():
            return []

        model = self._get_model()

        if progress_callback:
            progress_callback("TRANSCRIBING_AUDIO", 0.3)

        # Normalize language parameter: 'auto' or 'AUTO' -> None for Whisper auto-detection
        lang_param = None
        if language and language.upper() != "AUTO":
            lang_param = language.lower()

        try:
            segments, info = model.transcribe(
                audio_path,
                word_timestamps=True,
                language=lang_param,
                vad_filter=True,
            )

            word_timestamps: List[SpeechWordTimestamp] = []

            for seg in segments:
                if cancellation_token and cancellation_token.is_set():
                    break

                if seg.words:
                    for w in seg.words:
                        w_text = w.word.strip()
                        if w_text:
                            word_timestamps.append(
                                SpeechWordTimestamp(
                                    word=w_text,
                                    start=round(float(w.start), 3),
                                    end=round(float(w.end), 3),
                                    confidence=round(float(getattr(w, "probability", 1.0)), 3),
                                )
                            )
                else:
                    # Fallback to segment-level split if words not populated
                    words = seg.text.strip().split()
                    if words:
                        dur_per_word = max(0.1, (seg.end - seg.start) / len(words))
                        curr_s = seg.start
                        for w in words:
                            word_timestamps.append(
                                SpeechWordTimestamp(
                                    word=w,
                                    start=round(float(curr_s), 3),
                                    end=round(float(curr_s + dur_per_word), 3),
                                    confidence=0.8,
                                )
                            )
                            curr_s += dur_per_word

            if progress_callback:
                progress_callback("TRANSCRIBING_AUDIO", 0.6)

            return word_timestamps

        except Exception as e:
            if cancellation_token and cancellation_token.is_set():
                return []
            err_str = str(e)
            if (
                "silero_vad" in err_str
                or "NO_SUCHFILE" in err_str
                or "ONNXRuntimeError" in err_str
                or "File doesn't exist" in err_str
                or ("assets" in err_str and ".onnx" in err_str)
            ):
                raise ASRRuntimeIncompleteError(
                    f"Thành phần nhận dạng giọng nói bị thiếu hoặc chưa được cài đặt đầy đủ: {e}"
                )
            raise ASRFailedError(f"Speech transcription failed: {e}")


class MockSpeechTimestampProvider(SpeechTimestampProvider):
    """
    Deterministic mock provider for automated unit tests and CI runs.
    Accepts pre-configured timestamps or generates synthetic timestamps from audio duration.
    """

    def __init__(self, predefined_timestamps: Optional[List[SpeechWordTimestamp]] = None):
        self.predefined = predefined_timestamps

    def set_predefined(self, timestamps: List[SpeechWordTimestamp]):
        self.predefined = timestamps

    def get_timestamps(
        self,
        audio_path: str,
        language: Optional[str] = None,
        progress_callback: Optional[Callable[[str, float], None]] = None,
        cancellation_token: Optional[threading.Event] = None,
    ) -> List[SpeechWordTimestamp]:
        if progress_callback:
            progress_callback("TRANSCRIBING_AUDIO", 0.5)

        if cancellation_token and cancellation_token.is_set():
            return []

        if self.predefined is not None:
            return list(self.predefined)

        # Generate simple synthetic sequence if none provided
        return [
            SpeechWordTimestamp(word="demo", start=0.5, end=1.0, confidence=0.95),
            SpeechWordTimestamp(word="subtitle", start=1.1, end=1.8, confidence=0.92),
        ]
