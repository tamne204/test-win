"""
apps/capcut-v2/core/visual/validator.py
Deterministic accuracy validator for visual shot timelines.
Audits pacing, gaps, overlaps, asset duplication, tail blackouts, and motion velocity.
"""
from __future__ import annotations

import enum
from dataclasses import dataclass, field
from typing import List, Dict, Any, Optional

from .models import VisualShot, ShotDurationPolicy, TailDurationPolicy


class ValidationSeverity(str, enum.Enum):
    INFO = "INFO"
    WARNING = "WARNING"
    ERROR = "ERROR"
    FATAL = "FATAL"


@dataclass
class ValidationIssue:
    """An individual issue flagged by the VisualAccuracyValidator."""
    check_id: str
    severity: ValidationSeverity
    shot_id: Optional[int]
    timestamp_us: int
    metric_name: str
    metric_value: Any
    threshold: Any
    message: str

    def to_dict(self) -> Dict[str, Any]:
        return {
            "check_id": self.check_id,
            "severity": self.severity.value,
            "shot_id": self.shot_id,
            "timestamp_us": self.timestamp_us,
            "timestamp_s": round(self.timestamp_us / 1_000_000.0, 3),
            "metric_name": self.metric_name,
            "metric_value": self.metric_value,
            "threshold": self.threshold,
            "message": self.message,
        }


@dataclass
class ValidationReport:
    """Comprehensive validation outcome across a visual plan."""
    is_valid: bool
    has_fatal: bool
    issues: List[ValidationIssue]
    total_shots: int
    error_count: int
    fatal_count: int
    warning_count: int
    info_count: int

    def to_dict(self) -> Dict[str, Any]:
        return {
            "is_valid": self.is_valid,
            "has_fatal": self.has_fatal,
            "total_shots": self.total_shots,
            "error_count": self.error_count,
            "fatal_count": self.fatal_count,
            "warning_count": self.warning_count,
            "info_count": self.info_count,
            "issues": [issue.to_dict() for issue in self.issues],
        }


class VisualAccuracyValidator:
    """
    Performs deterministic rule-based audits of planned visual shots.
    """

    def __init__(
        self,
        duration_policy: Optional[ShotDurationPolicy] = None,
        tail_policy: Optional[TailDurationPolicy] = None,
        target_max_velocity: float = 3.5,
        absolute_max_velocity: float = 5.0,
        min_reuse_distance_s: float = 60.0,
    ):
        self.duration_policy = duration_policy or ShotDurationPolicy()
        self.tail_policy = tail_policy or TailDurationPolicy()
        self.target_max_velocity = target_max_velocity
        self.absolute_max_velocity = absolute_max_velocity
        self.min_reuse_distance_s = min_reuse_distance_s

    def validate(
        self,
        shots: List[VisualShot],
        master_audio_duration_us: int,
        speech_end_us: Optional[int] = None,
    ) -> ValidationReport:
        """
        Audits visual shots for continuity, duration, asset, and motion violations.
        """
        issues: List[ValidationIssue] = []

        if not shots:
            issues.append(
                ValidationIssue(
                    check_id="VAL-FATAL-00",
                    severity=ValidationSeverity.FATAL,
                    shot_id=None,
                    timestamp_us=0,
                    metric_name="shot_count",
                    metric_value=0,
                    threshold="> 0",
                    message="Visual shot list is empty; zero video track generated.",
                )
            )
            return self._build_report(issues, 0)

        # 1. Timeline origin check
        if shots[0].start_us != 0:
            issues.append(
                ValidationIssue(
                    check_id="VAL-FATAL-12",
                    severity=ValidationSeverity.FATAL,
                    shot_id=0,
                    timestamp_us=shots[0].start_us,
                    metric_name="timeline_start",
                    metric_value=shots[0].start_us,
                    threshold=0,
                    message=f"Visual timeline starts at {shots[0].start_us / 1e6:.3f}s instead of 0.0s.",
                )
            )

        # 2. Individual shot checks
        last_seen_us: Dict[str, int] = {}
        for idx, shot in enumerate(shots):
            dur_s = shot.duration_s
            is_tail = (speech_end_us is not None and shot.start_us >= speech_end_us)

            # Missing asset check
            if not shot.image_path:
                issues.append(
                    ValidationIssue(
                        check_id="VAL-FATAL-11",
                        severity=ValidationSeverity.FATAL,
                        shot_id=shot.shot_id,
                        timestamp_us=shot.start_us,
                        metric_name="missing_asset",
                        metric_value=None,
                        threshold="non-empty path",
                        message=f"Shot {shot.shot_id} has empty image_path.",
                    )
                )

            # Asset rapid reuse check (< min_reuse_distance_s)
            if shot.image_path and shot.image_path in last_seen_us:
                dist_s = (shot.start_us - last_seen_us[shot.image_path]) / 1_000_000.0
                if dist_s < self.min_reuse_distance_s and not is_tail:
                    issues.append(
                        ValidationIssue(
                            check_id="VAL-ERR-02",
                            severity=ValidationSeverity.ERROR,
                            shot_id=shot.shot_id,
                            timestamp_us=shot.start_us,
                            metric_name="reuse_distance_s",
                            metric_value=round(dist_s, 2),
                            threshold=self.min_reuse_distance_s,
                            message=f"Shot {shot.shot_id} reuses '{shot.image_id}' after {dist_s:.1f}s, violating min_reuse_distance {self.min_reuse_distance_s:.1f}s.",
                        )
                    )
            if shot.image_path:
                last_seen_us[shot.image_path] = shot.end_us

            # Micro-shot check (< 2.0s)
            min_thresh = self.tail_policy.hard_min_s if is_tail else self.duration_policy.hard_min_s
            if dur_s < min_thresh:
                issues.append(
                    ValidationIssue(
                        check_id="VAL-ERR-01",
                        severity=ValidationSeverity.ERROR,
                        shot_id=shot.shot_id,
                        timestamp_us=shot.start_us,
                        metric_name="duration_s",
                        metric_value=round(dur_s, 3),
                        threshold=min_thresh,
                        message=f"Shot {shot.shot_id} duration {dur_s:.2f}s is below hard_min {min_thresh:.1f}s.",
                    )
                )

            # Long speech shot warning (> 8.5s)
            if not is_tail and dur_s > self.duration_policy.soft_max_s:
                issues.append(
                    ValidationIssue(
                        check_id="VAL-WARN-02",
                        severity=ValidationSeverity.WARNING,
                        shot_id=shot.shot_id,
                        timestamp_us=shot.start_us,
                        metric_name="duration_s",
                        metric_value=round(dur_s, 3),
                        threshold=self.duration_policy.soft_max_s,
                        message=f"Speech shot {shot.shot_id} duration {dur_s:.2f}s exceeds soft_max {self.duration_policy.soft_max_s:.1f}s.",
                    )
                )

            # Motion velocity checks
            eff_v = shot.motion_profile.get("effective_velocity_pct_per_sec", 0.0)
            if eff_v > self.absolute_max_velocity:
                issues.append(
                    ValidationIssue(
                        check_id="VAL-ERR-10",
                        severity=ValidationSeverity.ERROR,
                        shot_id=shot.shot_id,
                        timestamp_us=shot.start_us,
                        metric_name="velocity_pct_per_sec",
                        metric_value=eff_v,
                        threshold=self.absolute_max_velocity,
                        message=f"Shot {shot.shot_id} motion velocity {eff_v:.2f}%/s exceeds absolute limit {self.absolute_max_velocity:.1f}%/s.",
                    )
                )
            elif eff_v > self.target_max_velocity:
                issues.append(
                    ValidationIssue(
                        check_id="VAL-WARN-09",
                        severity=ValidationSeverity.WARNING,
                        shot_id=shot.shot_id,
                        timestamp_us=shot.start_us,
                        metric_name="velocity_pct_per_sec",
                        metric_value=eff_v,
                        threshold=self.target_max_velocity,
                        message=f"Shot {shot.shot_id} motion velocity {eff_v:.2f}%/s exceeds target max {self.target_max_velocity:.1f}%/s.",
                    )
                )

        # 3. Adjacent pairwise continuity and asset checks
        for idx in range(len(shots) - 1):
            curr_shot = shots[idx]
            next_shot = shots[idx + 1]

            # Gap check
            if next_shot.start_us > curr_shot.end_us:
                gap_ms = (next_shot.start_us - curr_shot.end_us) / 1000.0
                issues.append(
                    ValidationIssue(
                        check_id="VAL-FATAL-06",
                        severity=ValidationSeverity.FATAL,
                        shot_id=next_shot.shot_id,
                        timestamp_us=curr_shot.end_us,
                        metric_name="visual_gap_ms",
                        metric_value=round(gap_ms, 2),
                        threshold=0,
                        message=f"Visual gap of {gap_ms:.1f}ms between shot {curr_shot.shot_id} and {next_shot.shot_id}.",
                    )
                )

            # Overlap check
            if next_shot.start_us < curr_shot.end_us:
                overlap_ms = (curr_shot.end_us - next_shot.start_us) / 1000.0
                issues.append(
                    ValidationIssue(
                        check_id="VAL-FATAL-07",
                        severity=ValidationSeverity.FATAL,
                        shot_id=next_shot.shot_id,
                        timestamp_us=next_shot.start_us,
                        metric_name="visual_overlap_ms",
                        metric_value=round(overlap_ms, 2),
                        threshold=0,
                        message=f"Visual overlap of {overlap_ms:.1f}ms between shot {curr_shot.shot_id} and {next_shot.shot_id}.",
                    )
                )

            # Consecutive duplicate asset check
            if curr_shot.image_path == next_shot.image_path:
                issues.append(
                    ValidationIssue(
                        check_id="VAL-ERR-03",
                        severity=ValidationSeverity.ERROR,
                        shot_id=next_shot.shot_id,
                        timestamp_us=next_shot.start_us,
                        metric_name="duplicate_asset",
                        metric_value=next_shot.image_id,
                        threshold="different asset",
                        message=f"Consecutive shots {curr_shot.shot_id} and {next_shot.shot_id} use identical image '{next_shot.image_id}'.",
                    )
                )

        # 4. Tail coverage / black screen check
        last_end_us = shots[-1].end_us
        if last_end_us < master_audio_duration_us:
            black_tail_s = (master_audio_duration_us - last_end_us) / 1_000_000.0
            issues.append(
                ValidationIssue(
                    check_id="VAL-FATAL-08",
                    severity=ValidationSeverity.FATAL,
                    shot_id=shots[-1].shot_id,
                    timestamp_us=last_end_us,
                    metric_name="tail_black_screen_s",
                    metric_value=round(black_tail_s, 3),
                    threshold=0.0,
                    message=f"Video track cuts off {black_tail_s:.2f}s before master audio end (black screen dropout).",
                )
            )

        return self._build_report(issues, len(shots))

    def _build_report(self, issues: List[ValidationIssue], total_shots: int) -> ValidationReport:
        counts = {
            ValidationSeverity.INFO: 0,
            ValidationSeverity.WARNING: 0,
            ValidationSeverity.ERROR: 0,
            ValidationSeverity.FATAL: 0,
        }
        for issue in issues:
            counts[issue.severity] += 1

        is_valid = (counts[ValidationSeverity.ERROR] == 0 and counts[ValidationSeverity.FATAL] == 0)
        has_fatal = (counts[ValidationSeverity.FATAL] > 0)

        return ValidationReport(
            is_valid=is_valid,
            has_fatal=has_fatal,
            issues=issues,
            total_shots=total_shots,
            error_count=counts[ValidationSeverity.ERROR],
            fatal_count=counts[ValidationSeverity.FATAL],
            warning_count=counts[ValidationSeverity.WARNING],
            info_count=counts[ValidationSeverity.INFO],
        )
