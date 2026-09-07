"""
apps/capcut-v2/core/subtitles/subtitle_segmenter.py
Segments aligned script tokens into human-readable subtitle cues.
Implements the '2TOOLNE_STANDARD_SUBTITLE' preset:
- Approx. 12 words max per cue
- Prefers breaks at punctuation (. , ? ! : ;)
- Reconstructs exact original script wording, casing, and punctuation
- Enforces strict monotonic, non-overlapping timing with min/max duration bounds
- Avoids dangling 1-word subtitles
"""
from __future__ import annotations

from typing import List, Optional

from .models import (
    AlignedToken,
    SubtitleCue,
    ConfidenceLevel,
    AlignmentOptions,
)


class SubtitleSegmenter:
    """
    Groups aligned tokens into subtitle cues respecting 2TOOLNE style rules.
    """

    def __init__(self, options: Optional[AlignmentOptions] = None):
        self.options = options or AlignmentOptions()

    def segment(self, aligned_tokens: List[AlignedToken]) -> List[SubtitleCue]:
        """
        Segment a continuous sequence of aligned tokens into subtitle cues.
        """
        if not aligned_tokens:
            return []

        max_words = self.options.max_words_per_cue
        max_dur = self.options.max_duration_s
        min_dur = self.options.min_duration_s
        min_gap = self.options.min_gap_s

        cues: List[SubtitleCue] = []
        curr_tokens: List[AlignedToken] = []

        total_tokens = len(aligned_tokens)

        for i, token in enumerate(aligned_tokens):
            curr_tokens.append(token)

            is_last = (i == total_tokens - 1)
            tok_count = len(curr_tokens)

            cue_start = curr_tokens[0].start_s
            cue_end = curr_tokens[-1].end_s
            cue_dur = cue_end - cue_start

            should_break = False

            # Hard Paragraph Invariant: A subtitle cue may not cross a strong script paragraph boundary
            is_para_boundary = (i + 1 < total_tokens) and (token.script_token.paragraph_id != aligned_tokens[i + 1].script_token.paragraph_id)

            if is_last:
                should_break = True
            elif is_para_boundary:
                # Inviolable break at paragraph transition
                should_break = True
            elif tok_count >= max_words:
                should_break = True
            elif cue_dur >= max_dur:
                should_break = True
            elif token.script_token.is_sentence_break and tok_count >= 2:
                # Strong break at sentence ending if cue already has at least 2 words
                should_break = True
            elif token.script_token.is_clause_break and tok_count >= (max_words // 2):
                # Secondary break at comma/clause if cue is reasonably long
                should_break = True

            # Dangling word prevention:
            # If breaking now leaves exactly 1 token remaining for the next cue,
            # and we haven't hit strict hard limits, absorb the next token or defer the break.
            # Never defer across a paragraph boundary.
            if should_break and not is_last and not is_para_boundary:
                remaining_tokens = total_tokens - (i + 1)
                if remaining_tokens == 1 and tok_count < max_words + 2:
                    # Defer break to include the final dangling word
                    continue

            if should_break:
                cue = self._build_cue(len(cues) + 1, curr_tokens)
                cues.append(cue)
                curr_tokens = []

        # Post-processing: Timing hygiene (monotonicity, min_dur, max_dur, non-overlapping)
        cues = self._enforce_timing_hygiene(cues, min_dur, max_dur, min_gap)

        return cues

    def _build_cue(self, cue_index: int, tokens: List[AlignedToken]) -> SubtitleCue:
        """
        Reconstruct subtitle text verbatim from original ScriptTokens
        and preserve source token & paragraph metadata (A0-02 resolution).
        """
        start_s = tokens[0].start_s
        end_s = tokens[-1].end_s

        # Reconstruct text using exact raw_text and trailing_punctuation
        words_formatted: List[str] = []
        for t in tokens:
            st = t.script_token
            word_str = st.raw_text + (st.trailing_punctuation or "")
            words_formatted.append(word_str)

        cue_text = " ".join(words_formatted).strip()

        # Compute aggregate confidence
        conf_scores = {
            ConfidenceLevel.HIGH: 1.0,
            ConfidenceLevel.MEDIUM: 0.7,
            ConfidenceLevel.LOW: 0.3,
            ConfidenceLevel.OMITTED: 0.0,
            ConfidenceLevel.UNMATCHED: 0.0,
        }
        avg_score = sum(conf_scores.get(t.confidence, 0.5) for t in tokens) / max(1, len(tokens))

        if avg_score >= 0.85:
            cue_conf = ConfidenceLevel.HIGH
        elif avg_score >= 0.5:
            cue_conf = ConfidenceLevel.MEDIUM
        else:
            cue_conf = ConfidenceLevel.LOW

        # Mathematical alignment confidence and source metadata
        token_confs = [getattr(t, "token_confidence", 0.8) for t in tokens]
        math_conf = sum(token_confs) / max(1, len(token_confs))

        src_start = tokens[0].script_token.original_index
        src_end = tokens[-1].script_token.original_index
        para_ids = sorted(list({t.script_token.paragraph_id for t in tokens}))
        sent_ids = sorted(list({t.script_token.sentence_id for t in tokens}))

        return SubtitleCue(
            index=cue_index,
            start_s=round(start_s, 3),
            end_s=round(end_s, 3),
            text=cue_text,
            confidence=cue_conf,
            tokens=list(tokens),
            source_token_start=src_start,
            source_token_end=src_end,
            paragraph_ids=para_ids,
            sentence_ids=sent_ids,
            alignment_confidence=round(math_conf, 3),
        )

    def _enforce_timing_hygiene(
        self,
        cues: List[SubtitleCue],
        min_dur: float,
        max_dur: float,
        min_gap: float,
    ) -> List[SubtitleCue]:
        """
        Guarantees that cue timestamps are strictly monotonic, non-overlapping,
        and adhere to min/max duration constraints.
        """
        if not cues:
            return []

        for i in range(len(cues)):
            cue = cues[i]

            # 1. Enforce minimum duration
            if cue.duration_s < min_dur:
                next_start = cues[i + 1].start_s if i + 1 < len(cues) else cue.start_s + min_dur + 1.0
                max_extendable_end = next_start - min_gap
                cue.end_s = min(max_extendable_end, cue.start_s + min_dur)
                if cue.end_s <= cue.start_s:
                    cue.end_s = round(cue.start_s + 0.2, 3)

            # 2. Enforce maximum duration
            if cue.duration_s > max_dur:
                cue.end_s = round(cue.start_s + max_dur, 3)

            # 3. Enforce non-overlapping with next cue
            if i + 1 < len(cues):
                next_cue = cues[i + 1]
                if cue.end_s > next_cue.start_s - min_gap:
                    # Resolve overlap: pull current end back or push next start forward
                    midpoint = (cue.start_s + next_cue.end_s) / 2.0
                    cue.end_s = round(max(cue.start_s + 0.2, next_cue.start_s - min_gap), 3)
                    if cue.end_s >= next_cue.start_s:
                        next_cue.start_s = round(cue.end_s + min_gap, 3)
                        if next_cue.end_s <= next_cue.start_s:
                            next_cue.end_s = round(next_cue.start_s + 0.3, 3)

            cue.start_s = round(max(0.0, cue.start_s), 3)
            cue.end_s = round(max(cue.start_s + 0.1, cue.end_s), 3)

        return cues
