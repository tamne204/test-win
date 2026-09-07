"""
apps/capcut-v2/core/subtitles/models.py
Strict data models for the Script-to-SRT Alignment Engine (Phase A0 Ratified Architecture).
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
    OMITTED = "OMITTED"
    UNMATCHED = "UNMATCHED"


class MatchType(str, enum.Enum):
    EXACT = "EXACT"
    HIGH_FUZZY = "HIGH_FUZZY"
    WEAK_FUZZY = "WEAK_FUZZY"
    FUZZY = "FUZZY"  # Backward compatibility alias
    INTERPOLATED = "INTERPOLATED"
    OMITTED = "OMITTED"
    INSERTED = "INSERTED"
    PHONETIC = "PHONETIC"
    UNMATCHED = "UNMATCHED"


class AlignmentEngineType(str, enum.Enum):
    LEGACY = "legacy"
    HIERARCHICAL_V1 = "hierarchical-anchor-v1"


@dataclass
class SpeechWordTimestamp:
    """
    Individual word or token timestamp extracted by SpeechTimestampProvider.
    Retains acoustic model probabilities (Whisper p in [0.0, 1.0]).
    """
    word: str
    start: float  # in seconds
    end: float    # in seconds
    confidence: float = 1.0  # Whisper token probability / likelihood
    original_index: int = 0
    normalized_text: str = ""
    segment_id: int = 0

    @property
    def raw_word(self) -> str:
        return self.word

    @property
    def start_s(self) -> float:
        return self.start

    @property
    def end_s(self) -> float:
        return self.end

    @property
    def probability(self) -> float:
        return self.confidence

    @property
    def duration(self) -> float:
        return max(0.0, self.end - self.start)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "text": self.word,
            "raw_word": self.word,
            "start": round(self.start, 3),
            "end": round(self.end, 3),
            "confidence": round(self.confidence, 3),
            "probability": round(self.confidence, 3),
            "original_index": self.original_index,
            "normalized_text": self.normalized_text,
            "segment_id": self.segment_id,
        }


# Type alias for explicit architecture naming
ASRWordTimestamp = SpeechWordTimestamp


@dataclass
class ScriptToken:
    """
    Token extracted from the original user script.
    Maintains exact original text, casing, byte/character offsets,
    and hierarchical document structure (paragraph_id, sentence_id, clause_id).
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
    paragraph_id: int = 0    # 0-indexed paragraph sequence (split on double newline / blank line)
    sentence_id: int = 0     # 0-indexed sentence sequence
    clause_id: int = 0       # 0-indexed clause sequence within sentence

    @property
    def original_index(self) -> int:
        return self.token_index


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
    asr_word_ref: Optional[SpeechWordTimestamp] = None
    string_similarity: float = 0.0
    token_confidence: float = 0.0
    anchor_distance_tokens: int = 0
    alignment_operation: str = ""

    @property
    def asr_probability(self) -> float:
        return self.asr_confidence

    @property
    def duration_s(self) -> float:
        return max(0.0, self.end_s - self.start_s)


@dataclass
class UnmatchedScriptSpan:
    """
    Identified portion of user script not spoken in the narration audio.
    Prevents synthetic tail compression or fabricated timestamps.
    """
    span_id: int
    char_start: int
    char_end: int
    token_start: int
    token_end: int
    text: str
    reason: str = "SCRIPT_OMITTED"  # "SCRIPT_OMITTED" | "SCRIPT_TAIL_UNSPOKEN" | "ACOUSTIC_DROPOUT"

    def to_dict(self) -> Dict[str, Any]:
        return {
            "span_id": self.span_id,
            "char_start": self.char_start,
            "char_end": self.char_end,
            "token_start": self.token_start,
            "token_end": self.token_end,
            "text": self.text,
            "reason": self.reason,
        }


@dataclass
class RegionHealth:
    """
    Diagnostic health report for an individual bounded alignment region.
    """
    region_index: int
    start_time_s: float
    end_time_s: float
    duration_s: float
    script_token_count: int
    asr_word_count: int
    exact_matches: int = 0
    fuzzy_matches: int = 0
    interpolated_count: int = 0
    omitted_count: int = 0
    inserted_count: int = 0
    token_reading_speed: float = 0.0
    language_cps: float = 0.0
    mean_confidence: float = 0.0
    health_status: str = "HEALTHY"  # "HEALTHY" | "DEGRADED" | "SUSPICIOUS" | "COLLAPSED"

    def to_dict(self) -> Dict[str, Any]:
        return {
            "region_index": self.region_index,
            "start_time_s": round(self.start_time_s, 3),
            "end_time_s": round(self.end_time_s, 3),
            "duration_s": round(self.duration_s, 3),
            "script_token_count": self.script_token_count,
            "asr_word_count": self.asr_word_count,
            "exact_matches": self.exact_matches,
            "fuzzy_matches": self.fuzzy_matches,
            "interpolated_count": self.interpolated_count,
            "omitted_count": self.omitted_count,
            "inserted_count": self.inserted_count,
            "token_reading_speed": round(self.token_reading_speed, 2),
            "language_cps": round(self.language_cps, 2),
            "mean_confidence": round(self.mean_confidence, 3),
            "health_status": self.health_status,
        }


@dataclass
class SubtitleCue:
    """
    A segmented subtitle cue conforming to 2TOOLNE standard subtitle rules.
    Retains underlying token span and paragraph IDs to resolve A0-02 desynchronization.
    """
    index: int
    start_s: float
    end_s: float
    text: str
    confidence: ConfidenceLevel = ConfidenceLevel.HIGH
    tokens: List[AlignedToken] = field(default_factory=list)
    source_token_start: int = 0
    source_token_end: int = 0
    paragraph_ids: List[int] = field(default_factory=list)
    sentence_ids: List[int] = field(default_factory=list)
    alignment_confidence: float = 1.0

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
            "source_token_start": self.source_token_start,
            "source_token_end": self.source_token_end,
            "paragraph_ids": self.paragraph_ids,
            "sentence_ids": self.sentence_ids,
            "alignment_confidence": round(self.alignment_confidence, 3),
        }


@dataclass
class AlignmentOptions:
    """
    Configuration parameters for alignment and subtitle segmentation.
    Validation Phase: defaults to LEGACY active with shadow mode enabled.
    """
    language: str = "AUTO"
    max_words_per_cue: int = 12
    max_chars_per_cue: int = 80
    min_duration_s: float = 0.6
    max_duration_s: float = 5.0
    min_gap_s: float = 0.05
    model_size: str = "base"
    engine: AlignmentEngineType = AlignmentEngineType.LEGACY
    shadow_mode: bool = True
    allow_degraded: bool = False


@dataclass
class AlignmentResult:
    """
    Complete outcome of the Script-to-SRT pipeline.
    Exposes full granular diagnostics, ratios, and health status per region.
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
    aligned_tokens: List[AlignedToken] = field(default_factory=list)
    anchors: List[Any] = field(default_factory=list)
    regions: List[Any] = field(default_factory=list)
    matched_token_ratio: float = 0.0
    exact_match_ratio: float = 0.0
    fuzzy_match_ratio: float = 0.0
    interpolated_ratio: float = 0.0
    omitted_script_ratio: float = 0.0
    asr_insertion_ratio: float = 0.0
    anchor_coverage_ratio: float = 0.0
    region_health_list: List[RegionHealth] = field(default_factory=list)
    unmatched_script_spans: List[UnmatchedScriptSpan] = field(default_factory=list)
    alignment_engine_version: str = "legacy"
    diagnostics: Dict[str, Any] = field(default_factory=dict)

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
            "matched_token_ratio": round(self.matched_token_ratio, 3),
            "exact_match_ratio": round(self.exact_match_ratio, 3),
            "fuzzy_match_ratio": round(self.fuzzy_match_ratio, 3),
            "interpolated_ratio": round(self.interpolated_ratio, 3),
            "omitted_script_ratio": round(self.omitted_script_ratio, 3),
            "asr_insertion_ratio": round(self.asr_insertion_ratio, 3),
            "anchor_coverage_ratio": round(self.anchor_coverage_ratio, 3),
            "region_health_list": [r.to_dict() for r in self.region_health_list],
            "unmatched_script_spans": [s.to_dict() for s in self.unmatched_script_spans],
            "alignment_engine_version": self.alignment_engine_version,
            "diagnostics": self.diagnostics,
        }
