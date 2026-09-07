"""
apps/capcut-v2/core/subtitles
Script-to-SRT Subtitle Alignment Engine for 2toolne AutoEdit for CapCut V2.
"""
from .models import (
    SpeechWordTimestamp,
    ScriptToken,
    AlignedToken,
    SubtitleCue,
    AlignmentOptions,
    AlignmentResult,
    ConfidenceLevel,
    MatchType,
)
from .script_normalizer import (
    tokenize_script,
    normalize_for_matching,
    detect_language,
)
from .speech_timestamp_provider import (
    SpeechTimestampProvider,
    FasterWhisperTimestampProvider,
    MockSpeechTimestampProvider,
    ASRError,
    ASRModelMissingError,
    ASRFailedError,
    ASRRuntimeIncompleteError,
)
from .script_aligner import ScriptAligner
from .subtitle_segmenter import SubtitleSegmenter
from .srt_generator import generate_srt, validate_srt_content
from .pipeline import ScriptToSrtPipeline, ScriptToSrtError

__all__ = [
    "SpeechWordTimestamp",
    "ScriptToken",
    "AlignedToken",
    "SubtitleCue",
    "AlignmentOptions",
    "AlignmentResult",
    "ConfidenceLevel",
    "MatchType",
    "tokenize_script",
    "normalize_for_matching",
    "detect_language",
    "SpeechTimestampProvider",
    "FasterWhisperTimestampProvider",
    "MockSpeechTimestampProvider",
    "ASRError",
    "ASRModelMissingError",
    "ASRFailedError",
    "ASRRuntimeIncompleteError",
    "ScriptAligner",
    "SubtitleSegmenter",
    "generate_srt",
    "validate_srt_content",
    "ScriptToSrtPipeline",
    "ScriptToSrtError",
]
