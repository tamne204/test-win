"""
apps/capcut-v2/core/subtitles/script_aligner.py
Monotonic sequence alignment between normalized original script tokens and ASR word timestamps.
Original script is the UNCOMPROMISING source of truth.
ASR determines timestamps only; it never overwrites user text, casing, brand names, or punctuation.
"""
from __future__ import annotations

from typing import List, Optional, Tuple, Dict, Any

from .models import (
    ScriptToken,
    SpeechWordTimestamp,
    AlignedToken,
    ConfidenceLevel,
    MatchType,
)
from .script_normalizer import normalize_for_matching

try:
    from rapidfuzz import fuzz
    HAS_RAPIDFUZZ = True
except ImportError:
    HAS_RAPIDFUZZ = False
    import difflib


def _similarity(s1: str, s2: str) -> float:
    """Calculate string similarity ratio between 0.0 and 1.0."""
    if not s1 or not s2:
        return 1.0 if s1 == s2 else 0.0
    if s1 == s2:
        return 1.0
    if HAS_RAPIDFUZZ:
        return fuzz.ratio(s1, s2) / 100.0
    return difflib.SequenceMatcher(None, s1, s2).ratio()


class ScriptAligner:
    """
    Monotonically aligns normalized script tokens to ASR word timestamps.
    Handles repeated sentences, omissions, stutters/fillers, and brand names.
    """

    def __init__(
        self,
        exact_threshold: float = 0.95,
        fuzzy_threshold: float = 0.75,
        max_lookahead_asr_tokens: int = 40,
    ):
        self.exact_threshold = exact_threshold
        self.fuzzy_threshold = fuzzy_threshold
        self.max_lookahead = max_lookahead_asr_tokens

    def align(
        self,
        script_tokens: List[ScriptToken],
        speech_timestamps: List[SpeechWordTimestamp],
        audio_duration_s: float = 0.0,
    ) -> List[AlignedToken]:
        """
        Align script tokens to speech word timestamps monotonically.

        Args:
            script_tokens: Normalized tokens from original user script.
            speech_timestamps: Word timestamps extracted from ASR.
            audio_duration_s: Total audio duration in seconds.

        Returns:
            List of AlignedToken objects, matching script_tokens 1-to-1 in order.
        """
        if not script_tokens:
            return []

        # If no ASR timestamps available at all, interpolate entire script over audio duration
        if not speech_timestamps:
            return self._interpolate_entire_script(script_tokens, audio_duration_s)

        num_script = len(script_tokens)
        num_asr = len(speech_timestamps)

        # Pre-normalize ASR word texts for matching
        norm_asr = [
            (i, normalize_for_matching(ts.word), ts)
            for i, ts in enumerate(speech_timestamps)
        ]

        aligned_tokens: List[Optional[AlignedToken]] = [None] * num_script

        curr_asr_idx = 0

        # Pass 1: Monotonic Forward Anchor Matching (Exact & High-Confidence Fuzzy)
        for s_idx, s_tok in enumerate(script_tokens):
            s_norm = s_tok.normalized_text
            if not s_norm:
                continue

            best_match_idx = -1
            best_sim = 0.0
            best_match_type = MatchType.UNMATCHED
            best_conf = ConfidenceLevel.UNMATCHED

            # Search in a monotonic forward window
            search_end = min(num_asr, curr_asr_idx + self.max_lookahead)

            for a_idx in range(curr_asr_idx, search_end):
                _, a_norm, a_ts = norm_asr[a_idx]
                if not a_norm:
                    continue

                # Exact match
                if s_norm == a_norm:
                    best_match_idx = a_idx
                    best_sim = 1.0
                    best_match_type = MatchType.EXACT
                    best_conf = ConfidenceLevel.HIGH
                    break

                # Fuzzy match
                sim = _similarity(s_norm, a_norm)
                if sim > best_sim:
                    best_sim = sim
                    if sim >= self.exact_threshold:
                        best_match_idx = a_idx
                        best_match_type = MatchType.EXACT
                        best_conf = ConfidenceLevel.HIGH
                    elif sim >= self.fuzzy_threshold:
                        best_match_idx = a_idx
                        best_match_type = MatchType.FUZZY
                        best_conf = ConfidenceLevel.MEDIUM

            # If anchor found
            if best_match_idx != -1 and best_sim >= self.fuzzy_threshold:
                matched_ts = speech_timestamps[best_match_idx]
                aligned_tokens[s_idx] = AlignedToken(
                    script_token=s_tok,
                    start_s=matched_ts.start,
                    end_s=matched_ts.end,
                    confidence=best_conf,
                    match_type=best_match_type,
                    asr_word=matched_ts.word,
                    asr_confidence=matched_ts.confidence,
                )
                # Advance monotonic ASR cursor past matched token
                curr_asr_idx = best_match_idx + 1

        # Pass 2: Conservative Gap Interpolation for Unmatched Tokens
        # Interpolate between known valid anchors to preserve monotonicity
        result: List[AlignedToken] = []
        i = 0
        while i < num_script:
            if aligned_tokens[i] is not None:
                result.append(aligned_tokens[i])
                i += 1
                continue

            # Find the unaligned gap [i, j - 1]
            gap_start = i
            while i < num_script and aligned_tokens[i] is None:
                i += 1
            gap_end = i - 1  # inclusive

            gap_len = gap_end - gap_start + 1

            # Determine anchor before gap
            prev_anchor = result[-1] if result else None
            t_start = prev_anchor.end_s if prev_anchor else 0.0

            # Determine anchor after gap
            next_anchor = aligned_tokens[i] if i < num_script else None
            if next_anchor:
                t_end = next_anchor.start_s
            else:
                # Trailing gap at the end of the script
                t_end = max(t_start + gap_len * 0.4, audio_duration_s or (t_start + gap_len * 0.5))

            # Ensure valid time range
            if t_end < t_start:
                t_end = t_start + gap_len * 0.3

            total_gap_time = max(gap_len * 0.1, t_end - t_start)
            time_per_token = total_gap_time / gap_len

            for k_idx in range(gap_start, gap_end + 1):
                offset = k_idx - gap_start
                token_start = round(t_start + offset * time_per_token, 3)
                token_end = round(token_start + time_per_token, 3)

                aligned = AlignedToken(
                    script_token=script_tokens[k_idx],
                    start_s=token_start,
                    end_s=token_end,
                    confidence=ConfidenceLevel.LOW,
                    match_type=MatchType.INTERPOLATED,
                )
                result.append(aligned)

        # Pass 3: Monotonicity and Non-Overlapping Guard
        for idx in range(len(result)):
            if result[idx].end_s <= result[idx].start_s:
                result[idx].end_s = round(result[idx].start_s + 0.2, 3)

            if idx > 0 and result[idx].start_s < result[idx - 1].end_s:
                # Resolve overlap: set start to previous end
                result[idx].start_s = result[idx - 1].end_s
                if result[idx].end_s <= result[idx].start_s:
                    result[idx].end_s = round(result[idx].start_s + 0.2, 3)

        return result

    def _interpolate_entire_script(
        self, script_tokens: List[ScriptToken], total_duration_s: float
    ) -> List[AlignedToken]:
        """Fallback when no ASR timestamps exist: uniformly span tokens."""
        dur = total_duration_s if total_duration_s > 0 else len(script_tokens) * 0.4
        per_token = dur / len(script_tokens)
        result: List[AlignedToken] = []

        for i, tok in enumerate(script_tokens):
            start_s = round(i * per_token, 3)
            end_s = round(start_s + per_token, 3)
            result.append(
                AlignedToken(
                    script_token=tok,
                    start_s=start_s,
                    end_s=end_s,
                    confidence=ConfidenceLevel.UNMATCHED,
                    match_type=MatchType.UNMATCHED,
                )
            )

        return result
