"""
apps/capcut-v2/core/visual/boundary_builder.py
Constructs discrete VisualBoundaryCandidate objects directly from A0 SubtitleCue metadata.
Handles long cues via deterministic duration-balanced internal boundaries.
"""
from __future__ import annotations

from typing import List, Optional, Dict, Any
from core.subtitles.models import SubtitleCue, AlignedToken
from .models import VisualBoundaryCandidate, VisualBoundaryType, ShotDurationPolicy


class VisualBoundaryCandidateBuilder:
    """
    Builds a discrete timeline candidate list from authoritative A0 subtitle cues.
    Never alters, shifts, or trims underlying A0 subtitle timing.
    """

    def __init__(self, duration_policy: Optional[ShotDurationPolicy] = None):
        self.duration_policy = duration_policy or ShotDurationPolicy()

    def build_candidates(
        self,
        cues: List[SubtitleCue],
        master_audio_duration_s: float,
        speech_end_s: Optional[float] = None,
    ) -> List[VisualBoundaryCandidate]:
        """
        Builds the complete set of visual boundary candidates across speech and tail.
        """
        if not cues:
            # Degenerate case: no speech subtitles
            return [
                VisualBoundaryCandidate(
                    boundary_id="B_START",
                    timestamp_us=0,
                    timestamp_s=0.0,
                    boundary_type=VisualBoundaryType.SPEECH_START,
                    source_reason="timeline_origin",
                ),
                VisualBoundaryCandidate(
                    boundary_id="B_AUDIO_END",
                    timestamp_us=int(round(master_audio_duration_s * 1_000_000)),
                    timestamp_s=master_audio_duration_s,
                    boundary_type=VisualBoundaryType.MASTER_AUDIO_END,
                    source_reason="master_audio_end",
                ),
            ]

        raw_candidates: List[VisualBoundaryCandidate] = []

        # 1. Timeline origin candidate
        raw_candidates.append(
            VisualBoundaryCandidate(
                boundary_id="B_000_ORIGIN",
                timestamp_us=0,
                timestamp_s=0.0,
                boundary_type=VisualBoundaryType.SPEECH_START,
                source_reason="timeline_origin",
                structural_strength=1.0,
                paragraph_ids=cues[0].paragraph_ids[:1] if cues[0].paragraph_ids else [0],
                sentence_ids=cues[0].sentence_ids[:1] if cues[0].sentence_ids else [0],
            )
        )

        # 2. If first cue does not start at 0.0, add first cue start if gap >= 200ms
        if cues[0].start_s > 0.2:
            raw_candidates.append(
                VisualBoundaryCandidate(
                    boundary_id="B_CUE0_START",
                    timestamp_us=cues[0].start_us,
                    timestamp_s=cues[0].start_s,
                    boundary_type=VisualBoundaryType.SPEECH_START,
                    source_reason="first_cue_start",
                    structural_strength=0.8,
                    paragraph_ids=cues[0].paragraph_ids[:1] if cues[0].paragraph_ids else [0],
                    sentence_ids=cues[0].sentence_ids[:1] if cues[0].sentence_ids else [0],
                )
            )

        # 3. Process each cue: internal boundaries for long cues + cue end boundary
        for i, cue in enumerate(cues):
            cue_dur = cue.duration_s

            # Long cue check: if cue duration > soft_max_s (8.5s), inject internal visual cuts
            if cue_dur > self.duration_policy.soft_max_s:
                internal_candidates = self._generate_internal_candidates(cue)
                raw_candidates.extend(internal_candidates)

            # Cue end candidate
            is_last = (i == len(cues) - 1)
            next_cue = cues[i + 1] if not is_last else None

            # Calculate acoustic gap to next cue
            acoustic_gap_ms = 0.0
            if next_cue is not None:
                acoustic_gap_ms = max(0.0, (next_cue.start_s - cue.end_s) * 1000.0)

            # Determine boundary structural type and strength
            b_type, strength, reason = self._classify_cue_boundary(cue, next_cue, acoustic_gap_ms)

            b_id = f"B_CUE_{cue.index:04d}_END"
            if is_last:
                b_type = VisualBoundaryType.SPEECH_END
                strength = 1.0
                reason = "speech_end"

            cand = VisualBoundaryCandidate(
                boundary_id=b_id,
                timestamp_us=cue.end_us,
                timestamp_s=cue.end_s,
                boundary_type=b_type,
                paragraph_ids=list(cue.paragraph_ids),
                sentence_ids=list(cue.sentence_ids),
                left_cue_id=cue.index,
                right_cue_id=next_cue.index if next_cue else None,
                acoustic_gap_ms=acoustic_gap_ms,
                structural_strength=strength,
                source_reason=reason,
                is_internal=False,
            )
            raw_candidates.append(cand)

        # 4. Master audio end candidate
        audio_end_us = int(round(master_audio_duration_s * 1_000_000))
        last_speech_end_us = cues[-1].end_us

        if audio_end_us > last_speech_end_us:
            raw_candidates.append(
                VisualBoundaryCandidate(
                    boundary_id="B_MASTER_AUDIO_END",
                    timestamp_us=audio_end_us,
                    timestamp_s=master_audio_duration_s,
                    boundary_type=VisualBoundaryType.MASTER_AUDIO_END,
                    source_reason="master_audio_end",
                    structural_strength=1.0,
                )
            )

        # 5. Deduplicate candidates at identical timestamps
        return self._deduplicate_candidates(raw_candidates)

    def _generate_internal_candidates(self, cue: SubtitleCue) -> List[VisualBoundaryCandidate]:
        """
        Deterministically bisects or subdivides an oversized subtitle cue.
        Preserves subtitle timing 100% while offering visual cut points.
        """
        candidates: List[VisualBoundaryCandidate] = []
        cue_dur = cue.duration_s

        # Target number of splits: e.g. 18.76s -> 2 halves (9.38s each)
        num_segments = max(2, int(round(cue_dur / self.duration_policy.target_max_s)))

        # Priority 1: Check token-level punctuation if aligned tokens exist
        split_found = False
        if cue.tokens and len(cue.tokens) >= num_segments:
            # Search for best token boundary near midpoints
            target_midpoint = cue.start_s + (cue_dur / float(num_segments))
            best_token = None
            best_dist = float("inf")

            for tok in cue.tokens[:-1]:
                # Check for sentence break, clause break, or token end near midpoint
                dist = abs(tok.end_s - target_midpoint)
                has_punct = tok.script_token.is_sentence_break or tok.script_token.is_clause_break
                score = dist - (1.0 if has_punct else 0.0)
                if score < best_dist and tok.end_s > cue.start_s + self.duration_policy.hard_min_s and tok.end_s < cue.end_s - self.duration_policy.hard_min_s:
                    best_dist = score
                    best_token = tok

            if best_token is not None:
                split_us = int(round(best_token.end_s * 1_000_000))
                candidates.append(
                    VisualBoundaryCandidate(
                        boundary_id=f"B_CUE_{cue.index:04d}_INTERNAL_TOK",
                        timestamp_us=split_us,
                        timestamp_s=best_token.end_s,
                        boundary_type=VisualBoundaryType.DURATION_FORCED_INTERNAL_BOUNDARY,
                        paragraph_ids=list(cue.paragraph_ids),
                        sentence_ids=list(cue.sentence_ids),
                        left_cue_id=cue.index,
                        right_cue_id=cue.index,
                        structural_strength=0.6 if best_token.script_token.is_sentence_break or best_token.script_token.is_clause_break else 0.4,
                        source_reason=f"internal_token_punct_split (dur={cue_dur:.2f}s)",
                        is_internal=True,
                    )
                )
                split_found = True

        # Priority 3: Deterministic duration balancing if no token split succeeded
        if not split_found:
            for seg in range(1, num_segments):
                split_s = cue.start_s + (cue_dur * (seg / float(num_segments)))
                split_us = int(round(split_s * 1_000_000))
                candidates.append(
                    VisualBoundaryCandidate(
                        boundary_id=f"B_CUE_{cue.index:04d}_INTERNAL_BAL_{seg}",
                        timestamp_us=split_us,
                        timestamp_s=split_s,
                        boundary_type=VisualBoundaryType.DURATION_FORCED_INTERNAL_BOUNDARY,
                        paragraph_ids=list(cue.paragraph_ids),
                        sentence_ids=list(cue.sentence_ids),
                        left_cue_id=cue.index,
                        right_cue_id=cue.index,
                        structural_strength=0.35,
                        source_reason=f"internal_duration_balance_split (seg {seg}/{num_segments}, dur={cue_dur:.2f}s)",
                        is_internal=True,
                    )
                )

        return candidates

    def _classify_cue_boundary(
        self,
        current_cue: SubtitleCue,
        next_cue: Optional[SubtitleCue],
        acoustic_gap_ms: float,
    ) -> tuple[VisualBoundaryType, float, str]:
        """
        Classifies boundary structural strength based on A0 paragraph, sentence, and punctuation.
        """
        if next_cue is None:
            return VisualBoundaryType.SPEECH_END, 1.0, "speech_end"

        # 1. Paragraph boundary check
        curr_paras = set(current_cue.paragraph_ids)
        next_paras = set(next_cue.paragraph_ids)
        if curr_paras and next_paras and (curr_paras != next_paras):
            return VisualBoundaryType.PARAGRAPH_BOUNDARY, 1.0, "paragraph_transition"

        # 2. Sentence boundary check
        curr_sentences = set(current_cue.sentence_ids)
        next_sentences = set(next_cue.sentence_ids)
        text_ends_sentence = current_cue.text.rstrip().endswith((".", "?", "!", "..."))
        if (curr_sentences and next_sentences and curr_sentences != next_sentences) or text_ends_sentence:
            return VisualBoundaryType.SENTENCE_BOUNDARY, 0.8, "sentence_period"

        # 3. Clause boundary check
        text_ends_clause = current_cue.text.rstrip().endswith((",", ";", ":", "—", "-"))
        if text_ends_clause:
            return VisualBoundaryType.CLAUSE_BOUNDARY, 0.5, "clause_punctuation"

        # 4. Acoustic pause check
        if acoustic_gap_ms >= 100.0:
            return VisualBoundaryType.ACOUSTIC_PAUSE, 0.4, f"acoustic_pause_{acoustic_gap_ms:.0f}ms"

        # 5. Standard subtitle cue boundary
        return VisualBoundaryType.SUBTITLE_BOUNDARY, 0.2, "subtitle_boundary"

    def _deduplicate_candidates(
        self,
        candidates: List[VisualBoundaryCandidate],
    ) -> List[VisualBoundaryCandidate]:
        """
        Sorts candidates chronologically and deduplicates at identical timestamps,
        preserving the candidate with highest structural strength.
        """
        candidates.sort(key=lambda c: c.timestamp_us)
        unique: List[VisualBoundaryCandidate] = []

        for cand in candidates:
            if not unique:
                unique.append(cand)
                continue

            last = unique[-1]
            if cand.timestamp_us == last.timestamp_us:
                # Merge: take highest structural strength and strongest type
                if cand.structural_strength > last.structural_strength:
                    # Update last with stronger candidate
                    unique[-1] = cand
            else:
                unique.append(cand)

        return unique
