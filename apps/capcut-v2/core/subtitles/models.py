"""
apps/capcut-v2/core/subtitles/models.py
Strict data models for the Script-to-SRT Alignment Engine.
Zero AI rewriting: original user script is the uncompromising textual source of truth.
"""
from __future__ import annotations

import enum
from dataclasses import dataclass, field
from typing import List, Optional, Dict, Any


class ConfidenceLevel(str, enum.Enum):
    HIGH = "HIGH"
    MEDIUM = "MEDIUM"
    LOW = "LOW"
    UNMATCHED = "UNMATCHED"


class MatchType(str, enum.Enum):
    EXACT = "EXACT"
    FUZZY = "FUZZY"
    PHONETIC = "PHONETIC"
    INTERPOLATED = "INTERPOLATED"
    UNMATCHED = "UNMATCHED"


@dataclass
class SpeechWordTimestamp:
    """
    Individual word or token timestamp extracted by SpeechTimestampProvider.
    """
    word: str
    start: float  # in seconds
    end: float    # in seconds
    confidence: float = 1.0

    @property
    def duration(self) -> float:
        return max(0.0, self.end - self.start)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "text": self.word,
            "start": round(self.start, 3),
            "end": round(self.end, 3),
            "confidence": round(self.confidence, 3),
        }


@dataclass
class ScriptToken:
    """
    Token extracted from the original user script.
    Maintains exact original text, casing, and byte/character offsets.
    """
    token_index: int
    raw_text: str            # Exact verbatim text in user script
    normalized_text: str     # Simplified text strictly for matching (lowercase, no diacritics/punct)
    char_start: int          # Character index in original script string
    char_end: int            # End character index in original script string
    leading_whitespace: str = ""
    trailing_punctuation: str = ""
    is_sentence_break: bool = False
    is_clause_break: bool = False


@dataclass
class AlignedToken:
    """
    Original script token bound to an audio timestamp.
    Text content ALWAYS comes from script_token.raw_text.
    """
    script_token: ScriptToken
    start_s: float
    end_s: float
    confidence: ConfidenceLevel = ConfidenceLevel.UNMATCHED
    match_type: MatchType = MatchType.UNMATCHED
    asr_word: Optional[str] = None
    asr_confidence: float = 0.0

    @property
    def duration_s(self) -> float:
        return max(0.0, self.end_s - self.start_s)


@dataclass
class SubtitleCue:
    """
    A segmented subtitle cue conforming to 2TOOLNE standard subtitle rules.
    """
    index: int
    start_s: float
    end_s: float
    text: str
    confidence: ConfidenceLevel = ConfidenceLevel.HIGH
    tokens: List[AlignedToken] = field(default_factory=list)

    @property
    def duration_s(self) -> float:
        return max(0.0, self.end_s - self.start_s)

    @property
    def start_us(self) -> int:
        return int(round(self.start_s * 1_000_000))

    @property
    def end_us(self) -> int:
        return int(round(self.end_s * 1_000_000))

    @property
    def duration_us(self) -> int:
        return max(0, self.end_us - self.start_us)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "index": self.index,
            "start_s": round(self.start_s, 3),
            "end_s": round(self.end_s, 3),
            "duration_s": round(self.duration_s, 3),
            "text": self.text,
            "confidence": self.confidence.value if isinstance(self.confidence, ConfidenceLevel) else str(self.confidence),
            "token_count": len(self.tokens),
        }


@dataclass
class AlignmentOptions:
    """
    Configuration parameters for alignment and subtitle segmentation.
    """
    language: str = "AUTO"
    max_words_per_cue: int = 12
    max_chars_per_cue: int = 80
    min_duration_s: float = 0.6
    max_duration_s: float = 5.0
    min_gap_s: float = 0.05
    model_size: str = "base"


@dataclass
class AlignmentResult:
    """
    Complete outcome of the Script-to-SRT pipeline.
    """
    cues: List[SubtitleCue]
    original_script: str
    srt_content: str
    matched_percentage: float
    unmatched_percentage: float
    audio_duration_s: float
    cue_count: int
    low_confidence_count: int
    detected_language: str = "auto"
    warnings: List[str] = field(default_factory=list)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "cues": [c.to_dict() for c in self.cues],
            "srt_content": self.srt_content,
            "matched_percentage": round(self.matched_percentage, 1),
            "unmatched_percentage": round(self.unmatched_percentage, 1),
            "audio_duration_s": round(self.audio_duration_s, 2),
            "cue_count": self.cue_count,
            "low_confidence_count": self.low_confidence_count,
            "detected_language": self.detected_language,
            "warnings": self.warnings,
        }
