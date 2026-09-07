"""
apps/capcut-v2/core/subtitles/anchor_finder.py
Deterministic Anchor Discovery, Context Validation, and Monotonic Chaining (Phase A0).
Finds high-confidence multi-token N-gram anchor sequences and constructs a strictly
increasing temporal chain with VAD-aware silence bridge support.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import List, Optional, Tuple, Dict, Set, Any

from .models import (
    ScriptToken,
    SpeechWordTimestamp,
    ASRWordTimestamp,
)

try:
    from rapidfuzz import fuzz
    HAS_RAPIDFUZZ = True
except ImportError:
    HAS_RAPIDFUZZ = False
    import difflib


def _token_similarity(s1: str, s2: str) -> float:
    """Calculate normalized token similarity ratio between 0.0 and 1.0."""
    if not s1 or not s2:
        return 1.0 if s1 == s2 else 0.0
    if s1 == s2:
        return 1.0
    if HAS_RAPIDFUZZ:
        return fuzz.ratio(s1, s2) / 100.0
    return difflib.SequenceMatcher(None, s1, s2).ratio()


# Common stopword sets across supported languages
STOPWORDS_EN = {
    "the", "a", "an", "and", "or", "but", "in", "on", "at", "to", "for", "of",
    "with", "by", "is", "are", "was", "were", "it", "that", "this", "he", "she",
    "they", "we", "you", "i", "as", "be", "so", "from"
}

STOPWORDS_VI = {
    "và", "hoặc", "nhưng", "là", "ở", "tại", "trong", "cho", "với", "của", "bởi",
    "được", "bị", "thì", "mà", "có", "này", "đó", "kia", "một", "các", "những",
    "ra", "vào", "lại", "đến", "đi", "đã", "sẽ", "đang", "sau", "trước", "khi",
    "từ", "về", "lên", "xuống", "rồi", "lúc"
}

STOPWORDS_KO = {
    "그리고", "그러나", "그런데", "하지만", "또한", "그", "이", "저", "것", "수",
    "등", "및", "에", "에서", "의", "를", "을", "가", "은", "는", "로", "으로",
    "하다", "있다", "되다", "않다"
}

ALL_STOPWORDS = STOPWORDS_EN | STOPWORDS_VI | STOPWORDS_KO


@dataclass
class AnchorCandidate:
    """
    Deterministic multi-token anchor sequence matching between Script and ASR.
    """
    anchor_id: int
    script_start_idx: int
    script_end_idx: int          # Inclusive
    asr_start_idx: int
    asr_end_idx: int             # Inclusive
    ngram_len: int               # 3, 4, or 5
    tokens_text: str             # Normalized space-joined text
    start_s: float               # Audio start timestamp
    end_s: float                 # Audio end timestamp
    asr_avg_probability: float   # Mean Whisper probability
    script_frequency: int = 1    # F_script
    asr_frequency: int = 1       # F_asr
    uniqueness_score: float = 1.0
    anchor_score: float = 1.0
    anchor_context_score: float = 1.0
    anchor_timestamp_confidence: float = 1.0
    anchor_timestamp_uncertainty_ms: Optional[float] = None
    is_sentinel: bool = False

    @property
    def duration_s(self) -> float:
        return max(0.0, self.end_s - self.start_s)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "anchor_id": self.anchor_id,
            "script_start_idx": self.script_start_idx,
            "script_end_idx": self.script_end_idx,
            "asr_start_idx": self.asr_start_idx,
            "asr_end_idx": self.asr_end_idx,
            "ngram_len": self.ngram_len,
            "tokens_text": self.tokens_text,
            "start_s": round(self.start_s, 3),
            "end_s": round(self.end_s, 3),
            "duration_s": round(self.duration_s, 3),
            "asr_avg_probability": round(self.asr_avg_probability, 3),
            "script_frequency": self.script_frequency,
            "asr_frequency": self.asr_frequency,
            "uniqueness_score": round(self.uniqueness_score, 3),
            "anchor_score": round(self.anchor_score, 3),
            "anchor_context_score": round(self.anchor_context_score, 3),
            "anchor_timestamp_confidence": round(self.anchor_timestamp_confidence, 3),
            "anchor_timestamp_uncertainty_ms": self.anchor_timestamp_uncertainty_ms,
            "is_sentinel": self.is_sentinel,
        }


class AnchorFinder:
    """
    Discovers deterministic N-gram anchors, filters candidates by uniqueness & context,
    and constructs a strictly monotonic anchor chain via weighted DAG / LIS DP.
    """

    def __init__(
        self,
        min_ngram_len: int = 3,
        max_ngram_len: int = 5,
        min_asr_prob: float = 0.75,
        min_anchor_score: float = 0.55,
        min_context_score: float = 0.30,
        drift_penalty_weight: float = 2.0,
    ):
        self.min_ngram_len = min_ngram_len
        self.max_ngram_len = max_ngram_len
        self.min_asr_prob = min_asr_prob
        self.min_anchor_score = min_anchor_score
        self.min_context_score = min_context_score
        self.drift_penalty_weight = drift_penalty_weight

    def find_anchors(
        self,
        script_tokens: List[ScriptToken],
        speech_timestamps: List[SpeechWordTimestamp],
        audio_duration_s: float = 0.0,
    ) -> List[AnchorCandidate]:
        """
        Full anchor discovery pipeline:
        1. Extract N-gram matches (N in [3, 5]).
        2. Filter by paragraph boundaries, stopwords, and ASR probability.
        3. Score document-wide uniqueness.
        4. Validate neighborhood context (2 before, 2 after).
        5. Select optimal monotonic anchor chain via Weighted LIS Dynamic Programming.
        """
        if not script_tokens or not speech_timestamps:
            return []

        # 1. Candidate Discovery & Frequency Indexing
        candidates = self._discover_candidate_ngrams(script_tokens, speech_timestamps)
        if not candidates:
            return []

        # 2. Context Validation
        validated = self._validate_neighborhood_contexts(
            candidates, script_tokens, speech_timestamps
        )
        if not validated:
            return []

        # 3. Monotonic Chain Selection (Weighted DAG / LIS DP)
        chain = self._select_monotonic_chain(
            validated,
            len(script_tokens),
            len(speech_timestamps),
            audio_duration_s or (speech_timestamps[-1].end if speech_timestamps else 0.0),
            speech_timestamps=speech_timestamps,
        )

        return chain

    def _discover_candidate_ngrams(
        self,
        script_tokens: List[ScriptToken],
        speech_timestamps: List[SpeechWordTimestamp],
    ) -> List[AnchorCandidate]:
        """
        Scan normalized script and ASR tokens for exact N-gram matches.
        Computes document-wide frequencies F_script and F_asr.
        """
        num_s = len(script_tokens)
        num_a = len(speech_timestamps)

        norm_s = [t.normalized_text for t in script_tokens]
        norm_a = [getattr(t, "normalized_text", None) or t.word.strip().lower() for t in speech_timestamps]

        candidates: List[AnchorCandidate] = []
        cand_id = 1

        # Index ASR N-grams for fast O(1) lookup
        for n in range(self.max_ngram_len, self.min_ngram_len - 1, -1):
            asr_ngram_map: Dict[Tuple[str, ...], List[int]] = {}
            for a_idx in range(num_a - n + 1):
                gram = tuple(norm_a[a_idx : a_idx + n])
                if any(not word for word in gram):
                    continue
                asr_ngram_map.setdefault(gram, []).append(a_idx)

            # Count script N-gram frequencies across entire document
            script_ngram_counts: Dict[Tuple[str, ...], int] = {}
            for s_idx in range(num_s - n + 1):
                gram = tuple(norm_s[s_idx : s_idx + n])
                if any(not word for word in gram):
                    continue
                script_ngram_counts[gram] = script_ngram_counts.get(gram, 0) + 1

            # Match against script
            for s_idx in range(num_s - n + 1):
                gram = tuple(norm_s[s_idx : s_idx + n])
                if gram not in asr_ngram_map:
                    continue

                # Filter 1: Stopword-only rejection
                if all(word in ALL_STOPWORDS for word in gram):
                    continue

                # Filter 2: Paragraph boundary guard (cannot cross double newline)
                span_paras = {script_tokens[s_idx + k].paragraph_id for k in range(n)}
                if len(span_paras) > 1:
                    continue

                f_script = script_ngram_counts[gram]
                f_asr = len(asr_ngram_map[gram])

                # Filter 3: Uniqueness penalty / rejection
                uniqueness = 1.0 / float(f_script * f_asr)
                if f_script > 4 and uniqueness < 0.10:
                    continue

                # For each occurrence in ASR
                for a_idx in asr_ngram_map[gram]:
                    asr_tokens = speech_timestamps[a_idx : a_idx + n]
                    avg_prob = sum(getattr(t, "confidence", 1.0) for t in asr_tokens) / float(n)

                    # Filter 4: Acoustic model probability threshold
                    if avg_prob < self.min_asr_prob:
                        continue

                    # Intrinsic score
                    len_score = (n - self.min_ngram_len) / float(max(1, self.max_ngram_len - self.min_ngram_len))
                    score = 0.30 * len_score + 0.40 * uniqueness + 0.30 * avg_prob

                    if score < self.min_anchor_score:
                        continue

                    cand = AnchorCandidate(
                        anchor_id=cand_id,
                        script_start_idx=s_idx,
                        script_end_idx=s_idx + n - 1,
                        asr_start_idx=a_idx,
                        asr_end_idx=a_idx + n - 1,
                        ngram_len=n,
                        tokens_text=" ".join(gram),
                        start_s=round(asr_tokens[0].start, 3),
                        end_s=round(asr_tokens[-1].end, 3),
                        asr_avg_probability=round(avg_prob, 3),
                        script_frequency=f_script,
                        asr_frequency=f_asr,
                        uniqueness_score=round(uniqueness, 3),
                        anchor_score=round(score, 3),
                        anchor_timestamp_confidence=round(avg_prob, 3),
                        anchor_timestamp_uncertainty_ms=None,  # Not invented; evidence-backed only
                    )
                    candidates.append(cand)
                    cand_id += 1

        return candidates

    def _validate_neighborhood_contexts(
        self,
        candidates: List[AnchorCandidate],
        script_tokens: List[ScriptToken],
        speech_timestamps: List[SpeechWordTimestamp],
    ) -> List[AnchorCandidate]:
        """
        Evaluate up to 2 preceding and 2 following context tokens.
        Normalizes by actual_context_terms_evaluated (never divides by fixed 4).
        """
        num_s = len(script_tokens)
        num_a = len(speech_timestamps)

        norm_s = [t.normalized_text for t in script_tokens]
        norm_a = [getattr(t, "normalized_text", None) or t.word.strip().lower() for t in speech_timestamps]

        validated: List[AnchorCandidate] = []

        for cand in candidates:
            evaluated_terms = 0
            sim_sum = 0.0

            # 1. Preceding 2 tokens (if available)
            for offset in (1, 2):
                s_pos = cand.script_start_idx - offset
                a_pos = cand.asr_start_idx - offset
                if s_pos >= 0 and a_pos >= 0:
                    sim_sum += _token_similarity(norm_s[s_pos], norm_a[a_pos])
                    evaluated_terms += 1

            # 2. Following 2 tokens (if available)
            for offset in (1, 2):
                s_pos = cand.script_end_idx + offset
                a_pos = cand.asr_end_idx + offset
                if s_pos < num_s and a_pos < num_a:
                    sim_sum += _token_similarity(norm_s[s_pos], norm_a[a_pos])
                    evaluated_terms += 1

            if evaluated_terms > 0:
                context_score = sim_sum / float(evaluated_terms)
            else:
                # Document boundary sentinel region
                context_score = 0.70

            cand.anchor_context_score = round(context_score, 3)

            # If anchor is ambiguous (F > 1), require strong context support
            if cand.script_frequency > 1 or cand.asr_frequency > 1:
                if cand.anchor_context_score < self.min_context_score:
                    continue

            validated.append(cand)

        return validated

    def _select_monotonic_chain(
        self,
        candidates: List[AnchorCandidate],
        total_script_tokens: int,
        total_asr_tokens: int,
        audio_duration_s: float,
        speech_timestamps: Optional[List[SpeechWordTimestamp]] = None,
    ) -> List[AnchorCandidate]:
        """
        Weighted Longest Increasing Subsequence (LIS) Dynamic Programming.
        Enforces strict script & audio monotonicity with VAD-aware silence bridge edge.
        """
        if not candidates:
            return []

        # Sort candidates primarily by script start, secondarily by ASR start
        candidates.sort(key=lambda c: (c.script_start_idx, c.asr_start_idx, -c.ngram_len))

        # Precompute silence intervals between consecutive ASR words for VAD silence bridge
        silence_gaps: List[Tuple[float, float]] = []
        if speech_timestamps and len(speech_timestamps) > 1:
            for k in range(len(speech_timestamps) - 1):
                g_start = speech_timestamps[k].end
                g_end = speech_timestamps[k + 1].start
                if g_end - g_start >= 1.5:  # Silence gap >= 1.5s
                    silence_gaps.append((g_start, g_end))

        def get_inter_silence_duration(t_start: float, t_end: float) -> float:
            tot = 0.0
            for gs, ge in silence_gaps:
                if ge <= t_start:
                    continue
                if gs >= t_end:
                    break
                overlap_start = max(gs, t_start)
                overlap_end = min(ge, t_end)
                if overlap_end > overlap_start:
                    tot += (overlap_end - overlap_start)
            return tot

        n_cands = len(candidates)
        # dp[i] = (max_score, parent_index)
        dp = [0.0] * n_cands
        parent = [-1] * n_cands

        t_total = max(1.0, audio_duration_s)
        s_total = max(1, total_script_tokens)

        for j in range(n_cands):
            cand_j = candidates[j]
            w_j = cand_j.anchor_score * cand_j.anchor_context_score * (cand_j.ngram_len ** 1.2)
            best_score = w_j
            best_p = -1

            for i in range(j):
                cand_i = candidates[i]

                # Hard Invariant 1: Strict Script Monotonicity
                if cand_i.script_end_idx >= cand_j.script_start_idx:
                    continue

                # Hard Invariant 2: Strict Audio Monotonicity (No crossing)
                if cand_i.end_s >= cand_j.start_s or cand_i.asr_end_idx >= cand_j.asr_start_idx:
                    continue

                delta_s = cand_j.script_start_idx - cand_i.script_end_idx
                delta_t = cand_j.start_s - cand_i.end_s

                # VAD-aware speech active duration
                non_speech = get_inter_silence_duration(cand_i.end_s, cand_j.start_s)
                speech_active_dur = max(0.2, delta_t - non_speech)

                raw_speed = delta_s / max(0.1, delta_t)
                speech_active_speed = delta_s / speech_active_dur

                # Rate plausibility guard
                # 1. Superhuman rate: > 8.0 tokens/sec
                if raw_speed > 8.0 or speech_active_speed > 9.0:
                    continue

                # 2. Silence bridge edge: if raw rate is low (< 0.2 tps), allow if non-speech explains it
                if raw_speed < 0.2:
                    is_silence_bridge = (non_speech >= 3.0 or delta_t >= 8.0) and (speech_active_speed >= 0.3)
                    if not is_silence_bridge and delta_t > 90.0:
                        # Unexplained extreme gap without speech evidence
                        continue

                # Temporal drift cost penalty
                drift_diff = abs((delta_t / t_total) - (delta_s / s_total))
                cost = self.drift_penalty_weight * drift_diff

                trans_score = dp[i] + w_j - cost
                if trans_score > best_score:
                    best_score = trans_score
                    best_p = i

            dp[j] = best_score
            parent[j] = best_p

        # Find candidate with highest DP score
        best_end_idx = max(range(n_cands), key=lambda idx: dp[idx])

        # Reconstruct chain via parent pointers
        chain: List[AnchorCandidate] = []
        curr = best_end_idx
        while curr != -1:
            chain.append(candidates[curr])
            curr = parent[curr]

        chain.reverse()

        # Re-number anchors 1-indexed for clarity
        for idx, a in enumerate(chain):
            a.anchor_id = idx + 1

        return chain
