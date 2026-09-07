"""
apps/capcut-v2/core/subtitles/collapse_detector.py
Deterministic Pre-SRT Collapse Detector and Validation Gate (Phase A0 Ratified Architecture).
Guarantees that impossible alignment patterns (such as 44 sentences in 19.6s) can NEVER silently
proceed as valid high-confidence output.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import List, Dict, Any, Optional

from .models import (
    SubtitleCue,
    RegionHealth,
    ConfidenceLevel,
    MatchType,
    AlignedToken,
)


class AlignmentCollapseError(Exception):
    """Raised when catastrophic alignment collapse or impossible pacing is detected."""
    def __init__(self, message: str, violations: Optional[List[str]] = None, details: Optional[Dict[str, Any]] = None):
        super().__init__(message)
        self.violations = violations or []
        self.details = details or {}


@dataclass
class CollapseInspectionResult:
    """
    Outcome of pre-SRT collapse inspection.
    """
    has_collapse: bool
    violations: List[str] = field(default_factory=list)
    warnings: List[str] = field(default_factory=list)
    micro_cue_count: int = 0
    micro_cue_ratio: float = 0.0
    max_reading_speed_cps: float = 0.0
    max_reading_speed_tps: float = 0.0
    collapsed_region_count: int = 0
    details: Dict[str, Any] = field(default_factory=dict)


class CollapseDetector:
    """
    Pre-emission validation gate inspecting subtitle cues and region health.
    Enforces the frozen quality limits across languages.
    """

    def __init__(
        self,
        hard_token_rate: float = 5.0,     # tokens/sec collapse threshold
        hard_cps_ko: float = 22.0,        # chars/sec collapse threshold for Korean
        hard_cps_default: float = 26.0,   # chars/sec collapse threshold for Vi/En
        soft_token_rate: float = 3.8,     # tokens/sec warning threshold
        micro_cue_threshold_s: float = 0.40,
        max_micro_cue_cluster: int = 4,    # in 5.0s window
        max_interpolation_ratio: float = 0.35,
    ):
        self.hard_token_rate = hard_token_rate
        self.hard_cps_ko = hard_cps_ko
        self.hard_cps_default = hard_cps_default
        self.soft_token_rate = soft_token_rate
        self.micro_cue_threshold_s = micro_cue_threshold_s
        self.max_micro_cue_cluster = max_micro_cue_cluster
        self.max_interpolation_ratio = max_interpolation_ratio

    def inspect(
        self,
        cues: List[SubtitleCue],
        region_health_list: Optional[List[RegionHealth]] = None,
        language: str = "auto",
        audio_duration_s: float = 0.0,
        allow_degraded: bool = False,
    ) -> CollapseInspectionResult:
        """
        Execute deterministic inspection.
        Raises AlignmentCollapseError if collapse detected and allow_degraded=False.
        """
        violations: List[str] = []
        warnings: List[str] = []
        region_health_list = region_health_list or []

        if not cues:
            return CollapseInspectionResult(has_collapse=False)

        total_cues = len(cues)
        hard_cps = self.hard_cps_ko if language.lower() == "ko" else self.hard_cps_default

        # 1. Micro-Cue Analysis
        micro_cues = [c for c in cues if c.duration_s < self.micro_cue_threshold_s]
        micro_cue_count = len(micro_cues)
        micro_cue_ratio = (micro_cue_count / float(total_cues)) if total_cues > 0 else 0.0

        # Check sliding 5.0s window for micro-cue clustering
        for i in range(total_cues):
            w_start = cues[i].start_s
            w_end = w_start + 5.0
            cluster_cues = [
                c for c in cues[i:]
                if c.start_s <= w_end and c.duration_s < self.micro_cue_threshold_s
            ]
            if len(cluster_cues) > self.max_micro_cue_cluster:
                violations.append(
                    f"Micro-cue cluster detected: {len(cluster_cues)} cues < {self.micro_cue_threshold_s}s "
                    f"within 5.0s window at {w_start:.2f}s."
                )
                break

        # 2. Reading Speed Analysis (Sliding 3-cue window & per-cue)
        max_cps = 0.0
        max_tps = 0.0

        for i in range(total_cues):
            c = cues[i]
            dur = max(0.05, c.duration_s)
            tok_count = len(c.tokens) if c.tokens else len(c.text.split())
            char_count = len(c.text)

            tps = tok_count / dur
            cps = char_count / dur
            if tps > max_tps:
                max_tps = tps
            if cps > max_cps:
                max_cps = cps

            # Sliding 3-cue window for sustained speed anomaly
            if i <= total_cues - 3:
                window_cues = cues[i : i + 3]
                win_dur = max(0.1, window_cues[-1].end_s - window_cues[0].start_s)
                win_toks = sum(len(x.tokens) if x.tokens else len(x.text.split()) for x in window_cues)
                win_chars = sum(len(x.text) for x in window_cues)

                win_tps = win_toks / win_dur
                win_cps = win_chars / win_dur

                if win_tps > self.hard_token_rate or win_cps > hard_cps:
                    violations.append(
                        f"Sustained impossible reading speed: {win_tps:.1f} tokens/sec ({win_cps:.1f} cps) "
                        f"across cues {i+1}-{i+3} at {window_cues[0].start_s:.2f}s."
                    )
                    break
                elif win_tps > self.soft_token_rate:
                    warnings.append(
                        f"Hurried reading speed: {win_tps:.1f} tokens/sec at cue {i+1}."
                    )

        # 3. Critical Tail Collapse Check (The 44-sentence in 19.6s signature)
        tail_start = max(0.0, (audio_duration_s or cues[-1].end_s) - 20.0)
        tail_cues = [c for c in cues if c.start_s >= tail_start]
        if tail_cues:
            tail_toks = sum(len(c.tokens) if c.tokens else len(c.text.split()) for c in tail_cues)
            tail_dur = max(0.1, cues[-1].end_s - tail_start)
            if len(tail_cues) >= 15 and (tail_toks / tail_dur) > self.hard_token_rate:
                violations.append(
                    f"Catastrophic tail collapse detected: {len(tail_cues)} cues ({tail_toks} tokens) "
                    f"crammed into final {tail_dur:.1f}s (> {self.hard_token_rate} tokens/sec)."
                )

        # 4. Region Health Inspection
        collapsed_regions = [r for r in region_health_list if r.health_status == "COLLAPSED"]
        if collapsed_regions:
            violations.append(
                f"{len(collapsed_regions)} bounded regions flagged as COLLAPSED by aligner."
            )

        has_collapse = len(violations) > 0

        result = CollapseInspectionResult(
            has_collapse=has_collapse,
            violations=violations,
            warnings=warnings,
            micro_cue_count=micro_cue_count,
            micro_cue_ratio=round(micro_cue_ratio, 3),
            max_reading_speed_cps=round(max_cps, 2),
            max_reading_speed_tps=round(max_tps, 2),
            collapsed_region_count=len(collapsed_regions),
            details={
                "language": language,
                "hard_token_rate": self.hard_token_rate,
                "hard_cps": hard_cps,
                "total_cues": total_cues,
            },
        )

        if has_collapse and not allow_degraded:
            raise AlignmentCollapseError(
                f"Alignment collapsed! Violations detected: {'; '.join(violations)}",
                violations=violations,
                details=result.details,
            )

        return result
