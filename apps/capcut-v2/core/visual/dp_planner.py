"""
apps/capcut-v2/core/visual/dp_planner.py
Deterministic minimum-cost DAG partitioner over VisualBoundaryCandidate[].
Computes optimal visual shot boundaries balancing pacing and linguistic structure.
"""
from __future__ import annotations

import math
from dataclasses import dataclass, field
from typing import List, Optional, Tuple, Dict, Any

from .models import (
    VisualBoundaryCandidate,
    VisualBoundaryType,
    ShotDurationPolicy,
    ImageSupplyState,
)


@dataclass
class PlannedShotInterval:
    """A planned visual shot interval between two candidate boundaries."""
    shot_index: int
    start_candidate: VisualBoundaryCandidate
    end_candidate: VisualBoundaryCandidate
    start_us: int
    end_us: int
    duration_us: int
    duration_s: float
    cue_ids: List[int]
    paragraph_ids: List[int]
    sentence_ids: List[int]
    cost: float = 0.0
    is_internal_split: bool = False

    @property
    def start_s(self) -> float:
        return self.start_us / 1_000_000.0

    @property
    def end_s(self) -> float:
        return self.end_us / 1_000_000.0


@dataclass
class VisualShotBoundaryPath:
    """Complete planned path of visual shots across narration."""
    shots: List[PlannedShotInterval]
    total_cost: float
    speech_end_us: int
    coverage_complete: bool


class VisualShotPlanner:
    """
    Solves optimal visual shot segmentation via discrete Bellman dynamic programming.
    Operates in O(N * Kmax) time with sub-millisecond execution on 30m timelines.
    """

    def __init__(
        self,
        duration_policy: Optional[ShotDurationPolicy] = None,
        search_horizon_s: float = 14.0,
        max_candidates_lookback: int = 30,
    ):
        self.duration_policy = duration_policy or ShotDurationPolicy()
        self.search_horizon_s = search_horizon_s
        self.max_candidates_lookback = max_candidates_lookback

    def plan_shots(
        self,
        candidates: List[VisualBoundaryCandidate],
        physical_image_count: Optional[int] = None,
        spoken_duration_s: Optional[float] = None,
    ) -> VisualShotBoundaryPath:
        """
        Plans optimal visual shots from candidates over the spoken narration.
        """
        if not candidates:
            return VisualShotBoundaryPath(shots=[], total_cost=0.0, speech_end_us=0, coverage_complete=False)

        # Filter candidates up to SPEECH_END (or end of speech narration)
        speech_candidates: List[VisualBoundaryCandidate] = []
        for c in candidates:
            speech_candidates.append(c)
            if c.boundary_type == VisualBoundaryType.SPEECH_END:
                break

        n = len(speech_candidates)
        if n < 2:
            return VisualShotBoundaryPath(shots=[], total_cost=0.0, speech_end_us=0, coverage_complete=False)

        # 1. Determine image supply guidance to adapt target duration if shortage exists
        target_min, target_max = self._compute_target_duration_band(
            spoken_duration_s=spoken_duration_s or speech_candidates[-1].timestamp_s,
            physical_image_count=physical_image_count,
        )

        # 2. Allocate DP tables
        # dp_cost[i]: minimum total cost to reach candidate i
        dp_cost = [float("inf")] * n
        dp_parent = [-1] * n
        dp_edge_cost = [0.0] * n

        dp_cost[0] = 0.0

        # 3. Dynamic Programming forward traversal
        for i in range(1, n):
            c_i = speech_candidates[i]
            t_i = c_i.timestamp_s

            # Lookback window bounded by time horizon and max candidates
            for j in range(i - 1, -1, -1):
                c_j = speech_candidates[j]
                t_j = c_j.timestamp_s
                dur = t_i - t_j

                # Prune if duration exceeds search horizon
                if dur > self.search_horizon_s:
                    break
                if (i - j) > self.max_candidates_lookback:
                    break

                # Ignore impossible incoming states
                if dp_cost[j] == float("inf"):
                    continue

                # Hard bounds check
                is_terminal = (i == n - 1)
                if not is_terminal and dur < self.duration_policy.hard_min_s:
                    # Non-terminal shot below hard_min is pruned
                    continue
                if dur > self.duration_policy.hard_max_s:
                    # Shot exceeding hard_max is pruned
                    continue

                # Calculate transition cost
                edge_cost = self._compute_edge_cost(dur, c_i, target_min, target_max)
                cand_total = dp_cost[j] + edge_cost

                # Strict deterministic tie-breaking
                if cand_total < dp_cost[i] - 1e-9:
                    dp_cost[i] = cand_total
                    dp_parent[i] = j
                    dp_edge_cost[i] = edge_cost
                elif abs(cand_total - dp_cost[i]) <= 1e-9:
                    # Tie break: prefer higher structural strength, then lower duration delta
                    prev_best_j = dp_parent[i]
                    curr_strength = c_i.structural_strength
                    prev_dur = t_i - speech_candidates[prev_best_j].timestamp_s
                    curr_dev = abs(dur - ((target_min + target_max) / 2.0))
                    prev_dev = abs(prev_dur - ((target_min + target_max) / 2.0))
                    if curr_dev < prev_dev:
                        dp_cost[i] = cand_total
                        dp_parent[i] = j
                        dp_edge_cost[i] = edge_cost

        # 4. Fallback if terminal was unreachable (e.g. extreme sparse candidates)
        if dp_parent[-1] == -1:
            # Fallback: connect from last reachable node or greedy step
            last_valid = 0
            for k in range(n - 1, -1, -1):
                if dp_parent[k] != -1 or k == 0:
                    last_valid = k
                    break
            dp_parent[-1] = last_valid
            dp_edge_cost[-1] = 50.0

        # 5. Backtrack path
        chosen_indices = []
        curr = n - 1
        while curr != -1:
            chosen_indices.append(curr)
            curr = dp_parent[curr]
        chosen_indices.reverse()

        # 6. Build raw planned shots
        raw_shots: List[PlannedShotInterval] = []
        for idx in range(len(chosen_indices) - 1):
            j_idx = chosen_indices[idx]
            i_idx = chosen_indices[idx + 1]
            c_start = speech_candidates[j_idx]
            c_end = speech_candidates[i_idx]

            s_us = c_start.timestamp_us
            e_us = c_end.timestamp_us
            dur_us = e_us - s_us
            dur_s = dur_us / 1_000_000.0

            # Gather cues, paragraphs, and sentences spanning [s_us, e_us]
            cue_ids = []
            if c_end.left_cue_id is not None:
                cue_ids.append(c_end.left_cue_id)
            if c_start.right_cue_id is not None and c_start.right_cue_id not in cue_ids:
                cue_ids.insert(0, c_start.right_cue_id)

            paras = list(set(c_start.paragraph_ids + c_end.paragraph_ids))
            sents = list(set(c_start.sentence_ids + c_end.sentence_ids))

            shot = PlannedShotInterval(
                shot_index=idx,
                start_candidate=c_start,
                end_candidate=c_end,
                start_us=s_us,
                end_us=e_us,
                duration_us=dur_us,
                duration_s=dur_s,
                cue_ids=sorted(cue_ids),
                paragraph_ids=sorted(paras),
                sentence_ids=sorted(sents),
                cost=dp_edge_cost[i_idx],
                is_internal_split=c_end.is_internal or c_start.is_internal,
            )
            raw_shots.append(shot)

        # 7. Terminal residual handling:
        # If final shot duration < hard_min_s, merge into preceding shot if duration <= terminal_max_s
        final_shots = self._handle_terminal_residual(raw_shots)

        total_cost = sum(s.cost for s in final_shots)
        speech_end_us = final_shots[-1].end_us if final_shots else 0

        return VisualShotBoundaryPath(
            shots=final_shots,
            total_cost=total_cost,
            speech_end_us=speech_end_us,
            coverage_complete=True,
        )

    def _compute_target_duration_band(
        self,
        spoken_duration_s: float,
        physical_image_count: Optional[int],
    ) -> Tuple[float, float]:
        """
        Determines target shot duration band.
        If physical images are scarce, shifts target upward to reduce shot count naturally.
        """
        if physical_image_count is None or physical_image_count <= 0:
            return 5.4, 7.6

        supply_ratio = spoken_duration_s / float(physical_image_count)

        # High shortage (> 7.0s per image)
        if supply_ratio > 7.0:
            t_min = min(self.duration_policy.soft_max_s, max(self.duration_policy.target_min_s, supply_ratio - 1.5))
            t_max = min(self.duration_policy.hard_max_s, max(self.duration_policy.target_max_s, supply_ratio + 1.5))
            return t_min, t_max

        # Surplus (<= 4.0s per image)
        if supply_ratio <= 4.0:
            return self.duration_policy.soft_min_s, min(self.duration_policy.target_max_s, max(self.duration_policy.target_min_s, supply_ratio + 1.0))

        # Targeted ratio guidance (3.5 <= supply_ratio <= 7.0)
        # Center target band around supply_ratio to align with available asset count
        if supply_ratio >= 6.0:
            t_min = min(self.duration_policy.soft_max_s - 0.5, max(self.duration_policy.target_min_s, round(supply_ratio, 1)))
            t_max = min(self.duration_policy.soft_max_s, max(self.duration_policy.target_max_s, supply_ratio + 2.5))
            return t_min, t_max

        t_min = max(self.duration_policy.target_min_s, min(self.duration_policy.soft_max_s - 1.0, round(supply_ratio, 1)))
        t_max = min(self.duration_policy.soft_max_s, max(self.duration_policy.target_max_s, supply_ratio + 1.6))
        return t_min, t_max

    def _compute_edge_cost(
        self,
        dur: float,
        end_cand: VisualBoundaryCandidate,
        target_min: float,
        target_max: float,
    ) -> float:
        """
        Computes the complete transition cost: duration penalty + structural cost + pause reward.
        """
        # 1. Duration penalty
        if dur < self.duration_policy.hard_min_s:
            p_dur = 1000.0 + 100.0 * (self.duration_policy.hard_min_s - dur) ** 2
        elif dur < self.duration_policy.soft_min_s:
            p_dur = 10.0 * (self.duration_policy.soft_min_s - dur) ** 2
        elif target_min <= dur <= target_max:
            p_dur = 0.0
        elif dur < target_min:
            p_dur = 2.0 * (target_min - dur) ** 2
        elif dur <= self.duration_policy.soft_max_s:
            p_dur = 5.0 * (dur - target_max) ** 2
        else:
            p_dur = 25.0 * (dur - self.duration_policy.soft_max_s) ** 2

        # 2. Structural boundary cost
        b_type = end_cand.boundary_type
        if b_type in (VisualBoundaryType.PARAGRAPH_BOUNDARY, VisualBoundaryType.SPEECH_END, VisualBoundaryType.MASTER_AUDIO_END):
            p_struct = 0.0
        elif b_type == VisualBoundaryType.SENTENCE_BOUNDARY:
            p_struct = 2.0
        elif b_type == VisualBoundaryType.CLAUSE_BOUNDARY:
            p_struct = 8.0
        elif b_type == VisualBoundaryType.DURATION_FORCED_INTERNAL_BOUNDARY:
            p_struct = 15.0
        elif b_type == VisualBoundaryType.ACOUSTIC_PAUSE:
            p_struct = 6.0
        else:
            # Subtitle boundary / mid-clause
            p_struct = 35.0

        # 3. Acoustic pause reward
        gap_ms = end_cand.acoustic_gap_ms
        if gap_ms < 100.0:
            p_pause = 0.0
        elif gap_ms < 300.0:
            p_pause = -5.0 * ((gap_ms - 100.0) / 200.0)
        else:
            p_pause = -5.0

        return p_dur + p_struct + p_pause

    def _handle_terminal_residual(
        self,
        shots: List[PlannedShotInterval],
    ) -> List[PlannedShotInterval]:
        """
        Merges sub-minimal terminal residual shot into preceding shot.
        """
        if len(shots) < 2:
            return shots

        last = shots[-1]
        if last.duration_s < self.duration_policy.hard_min_s:
            prev = shots[-2]
            merged_dur_s = prev.duration_s + last.duration_s
            if merged_dur_s <= self.duration_policy.terminal_max_s:
                # Merge last into prev
                prev.end_candidate = last.end_candidate
                prev.end_us = last.end_us
                prev.duration_us = prev.end_us - prev.start_us
                prev.duration_s = prev.duration_us / 1_000_000.0
                prev.cue_ids = sorted(list(set(prev.cue_ids + last.cue_ids)))
                prev.paragraph_ids = sorted(list(set(prev.paragraph_ids + last.paragraph_ids)))
                prev.sentence_ids = sorted(list(set(prev.sentence_ids + last.sentence_ids)))
                prev.cost += last.cost
                return shots[:-1]

        return shots
