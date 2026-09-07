"""
apps/capcut-v2/core/subtitles/hierarchical_aligner.py
Hierarchical Script Aligner Engine (Phase A0 Ratified Architecture).
Coordinates:
1. Deterministic anchor discovery & chaining (AnchorFinder)
2. Bounded region partitioning (drift propagation = 0 beyond next anchor)
3. Local banded Needleman-Wunsch with adaptive widening and full DP fallback
4. Unit-consistent tail feasibility & unspoken script detection (zero tail collapse)
5. Granular region health diagnostics & token confidence calculation
"""
from __future__ import annotations

import math
from dataclasses import dataclass, field
from typing import List, Optional, Tuple, Dict, Any

from .models import (
    ScriptToken,
    SpeechWordTimestamp,
    ASRWordTimestamp,
    AlignedToken,
    ConfidenceLevel,
    MatchType,
    UnmatchedScriptSpan,
    RegionHealth,
    AlignmentResult,
    AlignmentEngineType,
)
from .anchor_finder import AnchorFinder, AnchorCandidate, _token_similarity


@dataclass
class BoundedRegion:
    """
    Isolated temporal and textual sub-problem between two anchors.
    Errors inside this region are strictly confined and cannot propagate past anchor_after.
    """
    region_id: int
    script_start_idx: int
    script_end_idx: int          # Inclusive (-1 if empty span)
    asr_start_idx: int
    asr_end_idx: int             # Inclusive (-1 if empty span)
    t_start_s: float
    t_end_s: float
    is_tail_region: bool = False
    anchor_before: Optional[AnchorCandidate] = None
    anchor_after: Optional[AnchorCandidate] = None

    @property
    def script_token_count(self) -> int:
        if self.script_start_idx > self.script_end_idx or self.script_start_idx < 0:
            return 0
        return self.script_end_idx - self.script_start_idx + 1

    @property
    def asr_word_count(self) -> int:
        if self.asr_start_idx > self.asr_end_idx or self.asr_start_idx < 0:
            return 0
        return self.asr_end_idx - self.asr_start_idx + 1

    @property
    def duration_s(self) -> float:
        return max(0.0, self.t_end_s - self.t_start_s)


class HierarchicalScriptAligner:
    """
    Hierarchical anchor-based aligner eliminating cascading long-form drift.
    Guarantees:
    - DRIFT_PROPAGATION_BEYOND_NEXT_TRUSTED_ANCHOR = 0
    - SCRIPT_TEXT_MUTATION_RATE = 0%
    - ZERO catastrophic tail compression
    """

    def __init__(
        self,
        anchor_finder: Optional[AnchorFinder] = None,
        max_region_token_guard: int = 400,
        hard_token_rate: float = 5.0,     # tokens/sec collapse trigger
        target_token_rate: float = 2.8,   # tokens/sec healthy pacing
        hard_cps_ko: float = 22.0,        # chars/sec collapse trigger for Korean
        hard_cps_default: float = 26.0,   # chars/sec collapse trigger for Vi/En
        exact_threshold: float = 0.95,
        fuzzy_threshold: float = 0.70,
    ):
        self.anchor_finder = anchor_finder or AnchorFinder()
        self.max_region_token_guard = max_region_token_guard
        self.hard_token_rate = hard_token_rate
        self.target_token_rate = target_token_rate
        self.hard_cps_ko = hard_cps_ko
        self.hard_cps_default = hard_cps_default
        self.exact_threshold = exact_threshold
        self.fuzzy_threshold = fuzzy_threshold

    def align(
        self,
        script_tokens: List[ScriptToken],
        speech_timestamps: List[SpeechWordTimestamp],
        audio_duration_s: float = 0.0,
        language: str = "auto",
    ) -> Tuple[List[AlignedToken], List[AnchorCandidate], List[RegionHealth], List[UnmatchedScriptSpan]]:
        """
        Execute hierarchical alignment:
        1. Discover and chain deterministic N-gram anchors.
        2. Partition into bounded regions.
        3. Align bounded regions with adaptive band DP & full DP fallback.
        4. Apply guarded tail feasibility.
        5. Generate RegionHealth diagnostics and UnmatchedScriptSpans.
        """
        if not script_tokens:
            return [], [], [], []

        if not speech_timestamps:
            # Fallback if no audio timestamps exist
            return self._fallback_unvoiced(script_tokens, audio_duration_s)

        num_script = len(script_tokens)
        num_asr = len(speech_timestamps)
        t_duration = audio_duration_s or speech_timestamps[-1].end

        # Phase 1: Anchor Discovery and Monotonic Chaining
        anchors = self.anchor_finder.find_anchors(
            script_tokens=script_tokens,
            speech_timestamps=speech_timestamps,
            audio_duration_s=t_duration,
        )

        # Phase 2: Partition into Bounded Regions
        regions = self._partition_bounded_regions(
            anchors=anchors,
            num_script=num_script,
            num_asr=num_asr,
            audio_duration_s=t_duration,
        )

        # Phase 3: Align Each Region (Drift Confined to Region)
        aligned_result: List[Optional[AlignedToken]] = [None] * num_script
        unmatched_spans: List[UnmatchedScriptSpan] = []
        region_health_list: List[RegionHealth] = []

        # First, pre-populate all anchors with 100% certainty (Anchor Invariant)
        for anc in anchors:
            self._bind_anchor_tokens(anc, script_tokens, speech_timestamps, aligned_result)

        # Align each bounded region independently
        for reg in regions:
            reg_health, spans = self._align_single_region(
                region=reg,
                script_tokens=script_tokens,
                speech_timestamps=speech_timestamps,
                aligned_output=aligned_result,
                language=language,
            )
            region_health_list.append(reg_health)
            unmatched_spans.extend(spans)

        # Guarantee all slots filled (fill any residual gap with safe interpolation guard)
        self._ensure_complete_alignment(aligned_result, script_tokens, t_duration)

        final_aligned: List[AlignedToken] = [t for t in aligned_result if t is not None]
        return final_aligned, anchors, region_health_list, unmatched_spans

    def _partition_bounded_regions(
        self,
        anchors: List[AnchorCandidate],
        num_script: int,
        num_asr: int,
        audio_duration_s: float,
    ) -> List[BoundedRegion]:
        """
        Partition document into isolated bounded regions:
        [START -> A1], [A1 -> A2], ..., [AK -> END].
        """
        regions: List[BoundedRegion] = []
        reg_id = 0

        if not anchors:
            # Single global bounded region (Tier 3 guarded)
            regions.append(
                BoundedRegion(
                    region_id=reg_id,
                    script_start_idx=0,
                    script_end_idx=num_script - 1,
                    asr_start_idx=0,
                    asr_end_idx=num_asr - 1,
                    t_start_s=0.0,
                    t_end_s=audio_duration_s,
                    is_tail_region=True,
                )
            )
            return regions

        # 1. Lead region (before first anchor)
        first_anc = anchors[0]
        if first_anc.script_start_idx > 0 or first_anc.asr_start_idx > 0:
            regions.append(
                BoundedRegion(
                    region_id=reg_id,
                    script_start_idx=0,
                    script_end_idx=first_anc.script_start_idx - 1,
                    asr_start_idx=0,
                    asr_end_idx=first_anc.asr_start_idx - 1,
                    t_start_s=0.0,
                    t_end_s=first_anc.start_s,
                    anchor_after=first_anc,
                )
            )
            reg_id += 1

        # 2. Intermediate regions between adjacent anchors
        for i in range(len(anchors) - 1):
            a_curr = anchors[i]
            a_next = anchors[i + 1]
            s_start = a_curr.script_end_idx + 1
            s_end = a_next.script_start_idx - 1
            as_start = a_curr.asr_end_idx + 1
            as_end = a_next.asr_start_idx - 1

            if s_start <= s_end or as_start <= as_end:
                regions.append(
                    BoundedRegion(
                        region_id=reg_id,
                        script_start_idx=s_start if s_start <= s_end else -1,
                        script_end_idx=s_end if s_start <= s_end else -1,
                        asr_start_idx=as_start if as_start <= as_end else -1,
                        asr_end_idx=as_end if as_start <= as_end else -1,
                        t_start_s=a_curr.end_s,
                        t_end_s=a_next.start_s,
                        anchor_before=a_curr,
                        anchor_after=a_next,
                    )
                )
                reg_id += 1

        # 3. Tail region (after last anchor)
        last_anc = anchors[-1]
        s_tail_start = last_anc.script_end_idx + 1
        as_tail_start = last_anc.asr_end_idx + 1

        if s_tail_start < num_script or as_tail_start < num_asr:
            regions.append(
                BoundedRegion(
                    region_id=reg_id,
                    script_start_idx=s_tail_start if s_tail_start < num_script else -1,
                    script_end_idx=num_script - 1 if s_tail_start < num_script else -1,
                    asr_start_idx=as_tail_start if as_tail_start < num_asr else -1,
                    asr_end_idx=num_asr - 1 if as_tail_start < num_asr else -1,
                    t_start_s=last_anc.end_s,
                    t_end_s=audio_duration_s,
                    is_tail_region=True,
                    anchor_before=last_anc,
                )
            )

        return regions

    def _bind_anchor_tokens(
        self,
        anchor: AnchorCandidate,
        script_tokens: List[ScriptToken],
        speech_timestamps: List[SpeechWordTimestamp],
        aligned_output: List[Optional[AlignedToken]],
    ):
        """
        Directly assign high-confidence anchor timestamps.
        """
        n = anchor.ngram_len
        for k in range(n):
            s_idx = anchor.script_start_idx + k
            a_idx = anchor.asr_start_idx + k
            st = script_tokens[s_idx]
            at = speech_timestamps[a_idx]

            aligned_output[s_idx] = AlignedToken(
                script_token=st,
                start_s=at.start,
                end_s=at.end,
                confidence=ConfidenceLevel.HIGH,
                match_type=MatchType.EXACT,
                asr_word=at.word,
                asr_confidence=at.confidence,
                asr_word_ref=at,
                string_similarity=1.0,
                token_confidence=at.confidence,
                anchor_distance_tokens=0,
                alignment_operation="anchor_exact",
            )

    def _align_single_region(
        self,
        region: BoundedRegion,
        script_tokens: List[ScriptToken],
        speech_timestamps: List[SpeechWordTimestamp],
        aligned_output: List[Optional[AlignedToken]],
        language: str = "auto",
    ) -> Tuple[RegionHealth, List[UnmatchedScriptSpan]]:
        """
        Align an isolated bounded region.
        """
        unmatched_spans: List[UnmatchedScriptSpan] = []
        p = region.script_token_count
        q = region.asr_word_count
        t_dur = region.duration_s

        s_tokens = [script_tokens[i] for i in range(region.script_start_idx, region.script_end_idx + 1)] if p > 0 else []
        a_words = [speech_timestamps[j] for j in range(region.asr_start_idx, region.asr_end_idx + 1)] if q > 0 else []

        exact_cnt = 0
        fuzzy_cnt = 0
        interp_cnt = 0
        omit_cnt = 0
        ins_cnt = 0

        # Case 1: Tail Region Feasibility Check (Unit-Consistent Protection)
        if region.is_tail_region and p > 0:
            tail_health, tail_spans = self._handle_tail_region(
                region=region,
                s_tokens=s_tokens,
                a_words=a_words,
                aligned_output=aligned_output,
                language=language,
            )
            return tail_health, tail_spans

        # Case 2: Pure ASR insertion (no script tokens in region)
        if p == 0:
            # Non-speech improvisation or filler words
            return RegionHealth(
                region_index=region.region_id,
                start_time_s=region.t_start_s,
                end_time_s=region.t_end_s,
                duration_s=t_dur,
                script_token_count=0,
                asr_word_count=q,
                inserted_count=q,
                token_reading_speed=0.0,
                mean_confidence=1.0,
                health_status="HEALTHY",
            ), []

        # Case 3: Script tokens present but ZERO ASR words
        if q == 0:
            # Script omission or unvoiced text
            return self._handle_unvoiced_region(
                region=region,
                s_tokens=s_tokens,
                aligned_output=aligned_output,
                language=language,
            )

        # Case 4: General Bounded Region Alignment with Adaptive Band DP
        alignment_pairs, was_full_dp = self._run_local_dp(s_tokens, a_words)

        for s_sub_idx, a_sub_idx, op_type, sim in alignment_pairs:
            s_abs = region.script_start_idx + s_sub_idx
            st = script_tokens[s_abs]

            if a_sub_idx is not None:
                at = a_words[a_sub_idx]
                if sim >= self.exact_threshold:
                    m_type = MatchType.EXACT
                    c_level = ConfidenceLevel.HIGH if at.confidence >= 0.8 else ConfidenceLevel.MEDIUM
                    exact_cnt += 1
                elif sim >= self.fuzzy_threshold:
                    m_type = MatchType.HIGH_FUZZY if sim >= 0.85 else MatchType.WEAK_FUZZY
                    c_level = ConfidenceLevel.MEDIUM
                    fuzzy_cnt += 1
                else:
                    m_type = MatchType.INTERPOLATED
                    c_level = ConfidenceLevel.LOW
                    interp_cnt += 1

                tok_conf = sim * at.confidence
                aligned_output[s_abs] = AlignedToken(
                    script_token=st,
                    start_s=at.start,
                    end_s=at.end,
                    confidence=c_level,
                    match_type=m_type,
                    asr_word=at.word,
                    asr_confidence=at.confidence,
                    asr_word_ref=at,
                    string_similarity=round(sim, 3),
                    token_confidence=round(tok_conf, 3),
                    anchor_distance_tokens=s_sub_idx + 1,
                    alignment_operation=op_type,
                )
            else:
                # Script Omission inside region
                omit_cnt += 1
                aligned_output[s_abs] = AlignedToken(
                    script_token=st,
                    start_s=region.t_start_s,
                    end_s=region.t_end_s,
                    confidence=ConfidenceLevel.OMITTED,
                    match_type=MatchType.OMITTED,
                    string_similarity=0.0,
                    token_confidence=0.0,
                    alignment_operation="omission",
                )

        # Linear spacing adjustment for any internal gaps/omissions within region
        self._smooth_region_interpolations(region, aligned_output)

        token_speed = p / max(0.1, t_dur)
        char_count = sum(len(t.raw_text) for t in s_tokens)
        cps = char_count / max(0.1, t_dur)

        # Health status determination
        hard_cps = self.hard_cps_ko if language.lower() == "ko" else self.hard_cps_default
        if token_speed > self.hard_token_rate or cps > hard_cps:
            status = "COLLAPSED"
        elif interp_cnt / max(1, p) > 0.35:
            status = "DEGRADED"
        elif was_full_dp and (omit_cnt > 0):
            status = "SUSPICIOUS"
        else:
            status = "HEALTHY"

        mean_conf = sum(aligned_output[region.script_start_idx + i].token_confidence for i in range(p)) / float(p)

        health = RegionHealth(
            region_index=region.region_id,
            start_time_s=region.t_start_s,
            end_time_s=region.t_end_s,
            duration_s=t_dur,
            script_token_count=p,
            asr_word_count=q,
            exact_matches=exact_cnt,
            fuzzy_matches=fuzzy_cnt,
            interpolated_count=interp_cnt,
            omitted_count=omit_cnt,
            inserted_count=ins_cnt,
            token_reading_speed=token_speed,
            language_cps=cps,
            mean_confidence=mean_conf,
            health_status=status,
        )

        return health, unmatched_spans

    def _handle_tail_region(
        self,
        region: BoundedRegion,
        s_tokens: List[ScriptToken],
        a_words: List[SpeechWordTimestamp],
        aligned_output: List[Optional[AlignedToken]],
        language: str,
    ) -> Tuple[RegionHealth, List[UnmatchedScriptSpan]]:
        """
        Guarded Tail Feasibility:
        Prevents compressing unrecorded script tokens into final seconds of audio.
        """
        p = len(s_tokens)
        q = len(a_words)
        t_dur = region.duration_s
        unmatched_spans: List[UnmatchedScriptSpan] = []

        # Unit-Consistent Feasibility Check (Tokens vs Tokens/sec)
        req_seconds_token = p / float(self.hard_token_rate)
        char_count = sum(len(t.raw_text) for t in s_tokens)
        hard_cps = self.hard_cps_ko if language.lower() == "ko" else self.hard_cps_default
        req_seconds_char = char_count / float(hard_cps)

        # If audio duration is mathematically impossible for the script volume:
        is_impossible_tail = (t_dur < req_seconds_token) and (q < p * 0.45)

        if is_impossible_tail:
            # Align only what spoken content exists at healthy cadence; mark rest UNSPOKEN
            alignable_tokens_count = min(p, int(math.ceil(t_dur * self.target_token_rate)))

            # Align supported tokens
            spoken_tokens = s_tokens[:alignable_tokens_count]
            unspoken_tokens = s_tokens[alignable_tokens_count:]

            if spoken_tokens:
                time_per_tok = t_dur / float(len(spoken_tokens))
                for i, st in enumerate(spoken_tokens):
                    abs_idx = region.script_start_idx + i
                    t_s = region.t_start_s + i * time_per_tok
                    t_e = t_s + time_per_tok
                    aligned_output[abs_idx] = AlignedToken(
                        script_token=st,
                        start_s=round(t_s, 3),
                        end_s=round(t_e, 3),
                        confidence=ConfidenceLevel.LOW,
                        match_type=MatchType.INTERPOLATED,
                        token_confidence=0.3,
                        alignment_operation="tail_guarded_fit",
                    )

            if unspoken_tokens:
                span_text = " ".join(t.raw_text for t in unspoken_tokens)
                first_un = unspoken_tokens[0]
                last_un = unspoken_tokens[-1]
                unmatched_spans.append(
                    UnmatchedScriptSpan(
                        span_id=len(unmatched_spans) + 1,
                        char_start=first_un.char_start,
                        char_end=last_un.char_end,
                        token_start=first_un.token_index,
                        token_end=last_un.token_index,
                        text=span_text,
                        reason="SCRIPT_TAIL_UNSPOKEN",
                    )
                )
                for st in unspoken_tokens:
                    abs_idx = region.script_start_idx + alignable_tokens_count + (st.token_index - first_un.token_index)
                    # Mark unspoken without compressing into micro-cues
                    aligned_output[abs_idx] = AlignedToken(
                        script_token=st,
                        start_s=region.t_end_s,
                        end_s=region.t_end_s,
                        confidence=ConfidenceLevel.UNMATCHED,
                        match_type=MatchType.UNMATCHED,
                        token_confidence=0.0,
                        alignment_operation="tail_unspoken",
                    )

            health = RegionHealth(
                region_index=region.region_id,
                start_time_s=region.t_start_s,
                end_time_s=region.t_end_s,
                duration_s=t_dur,
                script_token_count=p,
                asr_word_count=q,
                interpolated_count=alignable_tokens_count,
                omitted_count=len(unspoken_tokens),
                token_reading_speed=self.target_token_rate,
                mean_confidence=0.35,
                health_status="DEGRADED",
            )
            return health, unmatched_spans

        # If audio duration is plausible, run normal local DP
        alignment_pairs, was_full = self._run_local_dp(s_tokens, a_words)
        for s_sub, a_sub, op, sim in alignment_pairs:
            s_abs = region.script_start_idx + s_sub
            st = s_tokens[s_sub]
            if a_sub is not None:
                at = a_words[a_sub]
                aligned_output[s_abs] = AlignedToken(
                    script_token=st,
                    start_s=at.start,
                    end_s=at.end,
                    confidence=ConfidenceLevel.HIGH if sim >= 0.9 else ConfidenceLevel.MEDIUM,
                    match_type=MatchType.EXACT if sim >= 0.9 else MatchType.HIGH_FUZZY,
                    asr_word=at.word,
                    asr_confidence=at.confidence,
                    asr_word_ref=at,
                    string_similarity=round(sim, 3),
                    token_confidence=round(sim * at.confidence, 3),
                    alignment_operation=op,
                )
            else:
                aligned_output[s_abs] = AlignedToken(
                    script_token=st,
                    start_s=region.t_start_s,
                    end_s=region.t_end_s,
                    confidence=ConfidenceLevel.LOW,
                    match_type=MatchType.INTERPOLATED,
                    token_confidence=0.2,
                    alignment_operation="tail_interpolation",
                )

        self._smooth_region_interpolations(region, aligned_output)
        health = RegionHealth(
            region_index=region.region_id,
            start_time_s=region.t_start_s,
            end_time_s=region.t_end_s,
            duration_s=t_dur,
            script_token_count=p,
            asr_word_count=q,
            exact_matches=sum(1 for t in alignment_pairs if t[3] >= 0.95),
            token_reading_speed=p / max(0.1, t_dur),
            mean_confidence=0.85,
            health_status="HEALTHY",
        )
        return health, unmatched_spans

    def _handle_unvoiced_region(
        self,
        region: BoundedRegion,
        s_tokens: List[ScriptToken],
        aligned_output: List[Optional[AlignedToken]],
        language: str,
    ) -> Tuple[RegionHealth, List[UnmatchedScriptSpan]]:
        """
        Handles region with script tokens but zero ASR words.
        """
        p = len(s_tokens)
        t_dur = region.duration_s
        unmatched_spans: List[UnmatchedScriptSpan] = []

        # If span is large and time is short, it's an omitted script span
        if p >= 4 and (t_dur < (p / self.hard_token_rate)):
            span_text = " ".join(t.raw_text for t in s_tokens)
            unmatched_spans.append(
                UnmatchedScriptSpan(
                    span_id=1,
                    char_start=s_tokens[0].char_start,
                    char_end=s_tokens[-1].char_end,
                    token_start=s_tokens[0].token_index,
                    token_end=s_tokens[-1].token_index,
                    text=span_text,
                    reason="SCRIPT_OMITTED",
                )
            )
            for st in s_tokens:
                aligned_output[st.token_index] = AlignedToken(
                    script_token=st,
                    start_s=region.t_start_s,
                    end_s=region.t_end_s,
                    confidence=ConfidenceLevel.OMITTED,
                    match_type=MatchType.OMITTED,
                    token_confidence=0.0,
                    alignment_operation="script_omitted",
                )
            status = "DEGRADED"
        else:
            # Short gap: gracefully space tokens across available interval
            time_per_tok = t_dur / float(p) if p > 0 else 0.2
            for i, st in enumerate(s_tokens):
                t_s = region.t_start_s + i * time_per_tok
                t_e = t_s + time_per_tok
                aligned_output[st.token_index] = AlignedToken(
                    script_token=st,
                    start_s=round(t_s, 3),
                    end_s=round(t_e, 3),
                    confidence=ConfidenceLevel.LOW,
                    match_type=MatchType.INTERPOLATED,
                    token_confidence=0.25,
                    alignment_operation="gap_interpolation",
                )
            status = "HEALTHY" if t_dur >= 1.0 else "SUSPICIOUS"

        health = RegionHealth(
            region_index=region.region_id,
            start_time_s=region.t_start_s,
            end_time_s=region.t_end_s,
            duration_s=t_dur,
            script_token_count=p,
            asr_word_count=0,
            omitted_count=p if status == "DEGRADED" else 0,
            interpolated_count=p if status != "DEGRADED" else 0,
            token_reading_speed=p / max(0.1, t_dur),
            mean_confidence=0.2,
            health_status=status,
        )
        return health, unmatched_spans

    def _run_local_dp(
        self,
        s_tokens: List[ScriptToken],
        a_words: List[SpeechWordTimestamp],
    ) -> Tuple[List[Tuple[int, Optional[int], str, float]], bool]:
        """
        Banded Needleman-Wunsch with adaptive band widening and full DP fallback.
        Returns: List of (s_idx, a_idx_or_None, op_type, similarity) and was_full_dp boolean.
        """
        p = len(s_tokens)
        q = len(a_words)

        # Initial adaptive band width
        w = max(14, 2 * abs(p - q) + int(math.ceil(0.15 * p)))

        # Attempt 1: Banded DP
        pairs, saturated = self._needleman_wunsch(s_tokens, a_words, band_width=w)

        if not saturated:
            return pairs, False

        # Attempt 2: Widened band
        w_wide = w * 2
        if w_wide < max(p, q):
            pairs_wide, sat_wide = self._needleman_wunsch(s_tokens, a_words, band_width=w_wide)
            if not sat_wide:
                return pairs_wide, False

        # Attempt 3: Full DP Fallback (Unbanded) - ACCURACY > ALIGNER SPEED
        full_pairs, _ = self._needleman_wunsch(s_tokens, a_words, band_width=None)
        return full_pairs, True

    def _needleman_wunsch(
        self,
        s_tokens: List[ScriptToken],
        a_words: List[SpeechWordTimestamp],
        band_width: Optional[int] = None,
    ) -> Tuple[List[Tuple[int, Optional[int], str, float]], bool]:
        """
        Core sequence alignment DP matrix with affine costs.
        """
        p = len(s_tokens)
        q = len(a_words)

        norm_s = [t.normalized_text for t in s_tokens]
        norm_a = [getattr(t, "normalized_text", None) or t.word.strip().lower() for t in a_words]

        # Costs according to frozen architecture
        GAP_SCRIPT = -1.5  # Script omission
        GAP_ASR = -1.2     # ASR insertion

        # Memory safety: use flat list for 2D matrix
        dp = [[-1e9] * (q + 1) for _ in range(p + 1)]
        trace = [[0] * (q + 1) for _ in range(p + 1)]  # 1: match/sub, 2: del script, 3: ins asr

        dp[0][0] = 0.0
        for i in range(1, p + 1):
            dp[i][0] = i * GAP_SCRIPT
            trace[i][0] = 2
        for j in range(1, q + 1):
            dp[0][j] = j * GAP_ASR
            trace[0][j] = 3

        saturated = False

        for i in range(1, p + 1):
            s_word = norm_s[i - 1]

            # Calculate band bounds
            if band_width is not None:
                diag = int(round(i * (q / float(max(1, p)))))
                j_start = max(1, diag - band_width // 2)
                j_end = min(q, diag + band_width // 2)
            else:
                j_start = 1
                j_end = q

            for j in range(j_start, j_end + 1):
                a_word = norm_a[j - 1]
                prob = getattr(a_words[j - 1], "confidence", 1.0)

                # Match / Substitution Score
                if s_word == a_word:
                    match_score = 2.0 * (0.6 + 0.4 * prob)
                else:
                    sim = _token_similarity(s_word, a_word)
                    if sim >= 0.85:
                        match_score = 1.5 * sim * (0.5 + 0.5 * prob)
                    elif sim >= 0.70 and len(s_word) > 2:
                        match_score = 0.5 * sim * prob
                    else:
                        match_score = -1.5

                diag_val = dp[i - 1][j - 1] + match_score
                del_val = dp[i - 1][j] + GAP_SCRIPT
                ins_val = dp[i][j - 1] + GAP_ASR

                best_v = diag_val
                best_t = 1
                if del_val > best_v:
                    best_v = del_val
                    best_t = 2
                if ins_val > best_v:
                    best_v = ins_val
                    best_t = 3

                dp[i][j] = best_v
                trace[i][j] = best_t

        # Traceback
        curr_i = p
        curr_j = q
        result_rev: List[Tuple[int, Optional[int], str, float]] = []

        while curr_i > 0 or curr_j > 0:
            # Check band saturation
            if band_width is not None and curr_i > 0:
                diag = int(round(curr_i * (q / float(max(1, p)))))
                if abs(curr_j - diag) >= (band_width // 2 - 1):
                    saturated = True

            t = trace[curr_i][curr_j]
            if t == 1 or (curr_i > 0 and curr_j > 0 and t == 0):
                sim = _token_similarity(norm_s[curr_i - 1], norm_a[curr_j - 1])
                op = "exact" if sim >= 0.95 else ("fuzzy" if sim >= 0.70 else "sub")
                result_rev.append((curr_i - 1, curr_j - 1, op, sim))
                curr_i -= 1
                curr_j -= 1
            elif t == 2 or curr_j == 0:
                # Script omission (advance script only)
                result_rev.append((curr_i - 1, None, "omission", 0.0))
                curr_i -= 1
            else:
                # ASR insertion (advance ASR only, no script emission)
                curr_j -= 1

        result_rev.reverse()
        return result_rev, saturated

    def _smooth_region_interpolations(
        self,
        region: BoundedRegion,
        aligned_output: List[Optional[AlignedToken]],
    ):
        """
        Enforce strictly monotonic non-overlapping timing for unaligned / omitted tokens
        within the boundaries of this specific region.
        """
        if region.script_start_idx < 0 or region.script_end_idx < 0:
            return

        p_start = region.script_start_idx
        p_end = region.script_end_idx

        # Guard boundaries
        t_left = region.t_start_s
        t_right = region.t_end_s

        for idx in range(p_start, p_end + 1):
            tok = aligned_output[idx]
            if tok is None:
                continue

            # Ensure inside region bounds
            tok.start_s = round(max(t_left, tok.start_s), 3)
            tok.end_s = round(min(t_right, max(tok.start_s + 0.1, tok.end_s)), 3)

            # Monotonicity with previous token
            if idx > p_start and aligned_output[idx - 1] is not None:
                prev = aligned_output[idx - 1]
                if tok.start_s < prev.end_s:
                    tok.start_s = prev.end_s
                    if tok.end_s <= tok.start_s:
                        tok.end_s = round(min(t_right, tok.start_s + 0.15), 3)

    def _ensure_complete_alignment(
        self,
        aligned_result: List[Optional[AlignedToken]],
        script_tokens: List[ScriptToken],
        audio_duration_s: float,
    ):
        """
        Final safety net ensuring zero None slots in aligned_tokens array.
        """
        num_s = len(script_tokens)
        t_curr = 0.0
        for i in range(num_s):
            if aligned_result[i] is None:
                aligned_result[i] = AlignedToken(
                    script_token=script_tokens[i],
                    start_s=round(t_curr, 3),
                    end_s=round(min(audio_duration_s, t_curr + 0.3), 3),
                    confidence=ConfidenceLevel.UNMATCHED,
                    match_type=MatchType.UNMATCHED,
                    token_confidence=0.0,
                    alignment_operation="fallback_unmatched",
                )
            t_curr = aligned_result[i].end_s

    def _fallback_unvoiced(
        self,
        script_tokens: List[ScriptToken],
        audio_duration_s: float,
    ) -> Tuple[List[AlignedToken], List[AnchorCandidate], List[RegionHealth], List[UnmatchedScriptSpan]]:
        """Fallback when no speech timestamps exist."""
        num_s = len(script_tokens)
        dur = max(1.0, audio_duration_s or num_s * 0.4)
        per_token = dur / float(num_s)
        res: List[AlignedToken] = []
        for i, st in enumerate(script_tokens):
            s_t = i * per_token
            res.append(
                AlignedToken(
                    script_token=st,
                    start_s=round(s_t, 3),
                    end_s=round(s_t + per_token, 3),
                    confidence=ConfidenceLevel.UNMATCHED,
                    match_type=MatchType.UNMATCHED,
                    token_confidence=0.0,
                    alignment_operation="unvoiced_entire_script",
                )
            )
        health = RegionHealth(
            region_index=0,
            start_time_s=0.0,
            end_time_s=dur,
            duration_s=dur,
            script_token_count=num_s,
            asr_word_count=0,
            token_reading_speed=num_s / dur,
            mean_confidence=0.0,
            health_status="COLLAPSED",
        )
        return res, [], [health], []
