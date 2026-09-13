"""
apps/capcut-v2/core/visual/quantization.py
Phase A2: Frame-Accurate Timeline Quantization for 2TOOLNE AutoEdit V2.
Maps visual cuts, image clips, transitions, and keyframes onto legal frame boundaries.
Eliminates sub-frame rounding drift without visual gaps, overlaps, or cumulative error.
"""
from __future__ import annotations

import math
from dataclasses import dataclass, field
from fractions import Fraction
from typing import Any, Dict, List, Optional, Tuple, Union

from .models import VisualShot


def rational_fps_from_float(fps: float) -> Tuple[int, int]:
    """
    Converts floating FPS to canonical rational (numerator, denominator).
    Handles NTSC drop-frame approximations (23.976, 29.97, 59.94) and standard integers.
    """
    if abs(fps - 23.976) < 0.01:
        return 24000, 1001
    elif abs(fps - 29.97) < 0.01:
        return 30000, 1001
    elif abs(fps - 59.94) < 0.01:
        return 60000, 1001
    
    # Exact integer frame rates
    r = round(fps)
    if abs(fps - r) < 0.001:
        return r, 1
        
    # Arbitrary rational fps
    frac = Fraction(fps).limit_denominator(1001)
    return frac.numerator, frac.denominator


@dataclass
class FrameTimebase:
    """
    Rational timebase arithmetic for frame <-> microsecond conversions.
    Avoids floating-point accumulation errors by using exact integer fractions.
    """
    fps_numerator: int = 60
    fps_denominator: int = 1

    @property
    def fps(self) -> float:
        return self.fps_numerator / float(self.fps_denominator) if self.fps_denominator else 60.0

    @property
    def frame_duration_us(self) -> float:
        return (1_000_000.0 * self.fps_denominator) / float(self.fps_numerator)

    def us_to_frame_index(self, timestamp_us: int) -> int:
        """
        Rounds a microsecond timestamp to the nearest legal integer frame index.
        Uses exact integer half-up rounding: (us * num + (1000000 * den // 2)) // (1000000 * den).
        """
        total_den = 1_000_000 * self.fps_denominator
        return (timestamp_us * self.fps_numerator + total_den // 2) // total_den

    def frame_index_to_us(self, frame_index: int) -> int:
        """
        Converts an integer frame index to its exact nominal start timestamp in microseconds.
        Uses exact integer rounding: (f_idx * 1000000 * den + num // 2) // num.
        """
        return (frame_index * 1_000_000 * self.fps_denominator + self.fps_numerator // 2) // self.fps_numerator

    def is_on_grid(self, timestamp_us: int, tolerance_us: int = 1) -> bool:
        """Checks if a microsecond timestamp lands on a legal frame boundary within tolerance."""
        f_idx = self.us_to_frame_index(timestamp_us)
        exact_us = self.frame_index_to_us(f_idx)
        return abs(timestamp_us - exact_us) <= tolerance_us


@dataclass
class FrameQuantizationPolicy:
    """Configuration for Phase A2 frame-accurate timeline quantization."""
    fps_numerator: int = 60
    fps_denominator: int = 1
    min_visual_frame_count: int = 1  # Every shot must contain >= 1 frame
    preserve_terminal_audio_end: bool = True  # Invariant: VISUAL_END == MASTER_AUDIO_END
    max_drift_tolerance_frames: float = 0.5

    @property
    def fps(self) -> float:
        return self.fps_numerator / float(self.fps_denominator) if self.fps_denominator else 60.0

    @classmethod
    def from_fps(cls, fps: float, preserve_terminal_audio_end: bool = True) -> "FrameQuantizationPolicy":
        num, den = rational_fps_from_float(fps)
        return cls(
            fps_numerator=num,
            fps_denominator=den,
            preserve_terminal_audio_end=preserve_terminal_audio_end,
        )


@dataclass
class BoundaryQuantizationRecord:
    boundary_index: int
    original_us: int
    quantized_us: int
    frame_index: int
    error_us: int
    error_frames: float
    collision_shifted: bool = False


@dataclass
class QuantizationDiagnostics:
    timebase: FrameTimebase
    total_boundaries: int = 0
    subframe_boundaries_before: int = 0
    offgrid_boundaries_after: int = 0
    max_error_us: int = 0
    max_error_frames: float = 0.0
    collisions_detected: int = 0
    collision_repairs: int = 0
    records: List[BoundaryQuantizationRecord] = field(default_factory=list)


class FrameQuantizer:
    """
    Quantizes A1 VisualShot boundaries onto absolute frame-accurate positions.
    Guarantees zero gaps, zero overlaps, and zero cumulative drift.
    """

    def __init__(self, policy: Optional[FrameQuantizationPolicy] = None):
        self.policy = policy or FrameQuantizationPolicy()
        self.timebase = FrameTimebase(
            fps_numerator=self.policy.fps_numerator,
            fps_denominator=self.policy.fps_denominator,
        )

    def quantize_shots(
        self,
        shots: List[VisualShot],
        master_audio_duration_us: Optional[int] = None,
    ) -> Tuple[List[VisualShot], QuantizationDiagnostics]:
        """
        Quantizes visual shot boundaries and keyframe intervals onto legal frame boundaries.

        Args:
            shots: List of unquantized or planned VisualShot objects.
            master_audio_duration_us: Optional authoritative master audio duration in microseconds.

        Returns:
            Tuple of (quantized_shots, diagnostics).
        """
        if not shots:
            return [], QuantizationDiagnostics(timebase=self.timebase)

        diag = QuantizationDiagnostics(timebase=self.timebase)
        n_shots = len(shots)

        # 1. Extract absolute continuous boundaries
        raw_boundaries_us: List[int] = [shots[0].start_us]
        for s in shots:
            raw_boundaries_us.append(s.end_us)

        diag.total_boundaries = len(raw_boundaries_us)
        frame_dur_us = self.timebase.frame_duration_us

        # Count how many were originally off-grid
        for b in raw_boundaries_us:
            if not self.timebase.is_on_grid(b):
                diag.subframe_boundaries_before += 1

        # 2. Map absolute boundaries to nearest frame index
        # Boundary 0 is strictly frame 0 (start of timeline)
        frame_indices: List[int] = [0]
        for k in range(1, n_shots):
            b_us = raw_boundaries_us[k]
            f_idx = self.timebase.us_to_frame_index(b_us)
            frame_indices.append(f_idx)

        # Terminal boundary:
        # If master_audio_duration_us provided, determine terminal frame index
        terminal_raw_us = master_audio_duration_us if master_audio_duration_us is not None else raw_boundaries_us[-1]
        terminal_f_idx = self.timebase.us_to_frame_index(terminal_raw_us)
        frame_indices.append(terminal_f_idx)

        # 3. Collision Detection and Deterministic Monotonicity Enforcement
        # Ensure frame_indices[k] >= frame_indices[k-1] + min_visual_frame_count
        min_frames = max(1, self.policy.min_visual_frame_count)
        shifted = [False] * len(frame_indices)

        for k in range(1, len(frame_indices)):
            if frame_indices[k] < frame_indices[k - 1] + min_frames:
                diag.collisions_detected += 1
                # Forward push: shift boundary k to guarantee at least min_frames
                frame_indices[k] = frame_indices[k - 1] + min_frames
                shifted[k] = True
                diag.collision_repairs += 1

        # Backward propagation if terminal frame was pushed beyond terminal target
        if frame_indices[-1] > terminal_f_idx and terminal_f_idx >= n_shots * min_frames:
            # Shift backwards to maintain exact terminal frame
            frame_indices[-1] = terminal_f_idx
            for k in range(len(frame_indices) - 2, 0, -1):
                if frame_indices[k] > frame_indices[k + 1] - min_frames:
                    frame_indices[k] = frame_indices[k + 1] - min_frames
                    shifted[k] = True

        # 4. Reconstruct Quantized VisualShots with Exact Frame-Aligned Boundaries
        quantized_shots: List[VisualShot] = []
        max_err_us = 0
        max_err_f = 0.0

        for k in range(n_shots):
            orig_s = shots[k]
            st_f = frame_indices[k]
            en_f = frame_indices[k + 1]
            dur_f = en_f - st_f

            st_us = self.timebase.frame_index_to_us(st_f)
            # For the final shot, if preserve_terminal_audio_end is True and audio duration is provided,
            # clamp exact microsecond end to master audio duration to preserve VISUAL_END == MASTER_AUDIO_END
            if k == n_shots - 1 and self.policy.preserve_terminal_audio_end and master_audio_duration_us is not None:
                en_us = master_audio_duration_us
            else:
                en_us = self.timebase.frame_index_to_us(en_f)

            dur_us = en_us - st_us

            # Measure boundary quantization error against original
            err_st_us = abs(st_us - orig_s.start_us)
            err_st_f = err_st_us / frame_dur_us if frame_dur_us > 0 else 0.0
            if err_st_us > max_err_us:
                max_err_us = err_st_us
            if err_st_f > max_err_f:
                max_err_f = err_st_f

            rec = BoundaryQuantizationRecord(
                boundary_index=k,
                original_us=orig_s.start_us,
                quantized_us=st_us,
                frame_index=st_f,
                error_us=err_st_us,
                error_frames=err_st_f,
                collision_shifted=shifted[k],
            )
            diag.records.append(rec)

            # Check if internal boundary is on legal frame grid
            if not self.timebase.is_on_grid(st_us):
                diag.offgrid_boundaries_after += 1

            # Quantize keyframe params to legal frame offsets
            quantized_motion = self._quantize_motion_keyframes(orig_s.motion_profile, dur_us, dur_f)

            # Create updated VisualShot
            q_shot = VisualShot(
                shot_id=orig_s.shot_id,
                start_us=st_us,
                end_us=en_us,
                duration_us=dur_us,
                image_id=orig_s.image_id,
                image_path=orig_s.image_path,
                cue_ids=list(orig_s.cue_ids),
                paragraph_ids=list(orig_s.paragraph_ids),
                sentence_ids=list(orig_s.sentence_ids),
                boundary_start_reason=orig_s.boundary_start_reason,
                boundary_end_reason=orig_s.boundary_end_reason,
                is_internal_split=orig_s.is_internal_split,
                reuse_count=orig_s.reuse_count,
                previous_use_distance_us=orig_s.previous_use_distance_us,
                motion_profile=quantized_motion,
                planner_cost=orig_s.planner_cost,
                diagnostics=dict(orig_s.diagnostics),
                start_frame=st_f,
                end_frame=en_f,
                duration_frames=dur_f,
                quantization_error_us=err_st_us,
            )
            # Attach quantization telemetry
            q_shot.diagnostics["quantization"] = {
                "start_frame": st_f,
                "end_frame": en_f,
                "duration_frames": dur_f,
                "quantization_error_us": err_st_us,
                "collision_shifted": shifted[k],
                "fps": self.policy.fps,
            }
            quantized_shots.append(q_shot)

        # Terminal boundary record
        term_rec = BoundaryQuantizationRecord(
            boundary_index=n_shots,
            original_us=raw_boundaries_us[-1],
            quantized_us=quantized_shots[-1].end_us,
            frame_index=frame_indices[-1],
            error_us=abs(quantized_shots[-1].end_us - raw_boundaries_us[-1]),
            error_frames=abs(quantized_shots[-1].end_us - raw_boundaries_us[-1]) / frame_dur_us if frame_dur_us > 0 else 0.0,
            collision_shifted=shifted[-1],
        )
        diag.records.append(term_rec)

        diag.max_error_us = max_err_us
        diag.max_error_frames = max_err_f
        return quantized_shots, diag

    def _quantize_motion_keyframes(
        self,
        motion_profile: Dict[str, Any],
        duration_us: int,
        duration_frames: int,
    ) -> Dict[str, Any]:
        """
        Ensures motion profile duration and intermediate keyframe offsets
        are strictly aligned to the clip's local frame grid.
        """
        if not motion_profile:
            return {}

        new_profile = dict(motion_profile)
        # Update effective velocity based on new quantized duration
        dur_s = duration_us / 1_000_000.0
        if dur_s > 0:
            scale_delta = abs(new_profile.get("scale_end", 1.0) - new_profile.get("scale_start", 1.0))
            px_delta = abs(new_profile.get("pan_x_end", 0.0) - new_profile.get("pan_x_start", 0.0))
            py_delta = abs(new_profile.get("pan_y_end", 0.0) - new_profile.get("pan_y_start", 0.0))
            pan_delta = math.sqrt(px_delta**2 + py_delta**2)
            
            effective_v = max(scale_delta, pan_delta) / dur_s * 100.0
            new_profile["effective_velocity_pct_per_sec"] = round(effective_v, 2)
            new_profile["velocity_pct_per_s"] = round(effective_v, 2)

        # If keyframes list present in profile, quantize offsets
        if "keyframe_list" in new_profile:
            quantized_kfs = []
            prev_f = -1
            for kf in new_profile["keyframe_list"]:
                kf_copy = dict(kf)
                orig_offset = kf_copy.get("time_offset", 0)
                f_idx = self.timebase.us_to_frame_index(orig_offset)
                # Keep monotonic and within [0, duration_frames]
                f_idx = max(prev_f + 1 if prev_f >= 0 else 0, min(duration_frames, f_idx))
                kf_copy["time_offset"] = self.timebase.frame_index_to_us(f_idx)
                kf_copy["frame_offset"] = f_idx
                prev_f = f_idx
                quantized_kfs.append(kf_copy)
            new_profile["keyframe_list"] = quantized_kfs

        return new_profile


@dataclass
class FrameValidationIssue:
    check_id: str
    severity: str  # ERROR, WARNING, FATAL
    message: str
    shot_id: Optional[int] = None
    timestamp_us: Optional[int] = None
    frame_index: Optional[int] = None


@dataclass
class FrameValidationReport:
    is_valid: bool = True
    has_fatal: bool = False
    issues: List[FrameValidationIssue] = field(default_factory=list)

    @property
    def error_count(self) -> int:
        return sum(1 for i in self.issues if i.severity == "ERROR")

    @property
    def warning_count(self) -> int:
        return sum(1 for i in self.issues if i.severity == "WARNING")

    @property
    def fatal_count(self) -> int:
        return sum(1 for i in self.issues if i.severity == "FATAL")


class FrameAccuracyValidator:
    """
    Deterministic audit layer verifying frame-accuracy across visual cuts,
    durations, keyframes, and timeline boundaries.
    """

    def __init__(self, policy: Optional[FrameQuantizationPolicy] = None):
        self.policy = policy or FrameQuantizationPolicy()
        self.timebase = FrameTimebase(
            fps_numerator=self.policy.fps_numerator,
            fps_denominator=self.policy.fps_denominator,
        )

    def validate(
        self,
        shots: List[VisualShot],
        master_audio_duration_us: Optional[int] = None,
    ) -> FrameValidationReport:
        """
        Validates frame-grid conformance, visual continuity, and keyframe properties.
        """
        report = FrameValidationReport()

        if not shots:
            report.issues.append(
                FrameValidationIssue(
                    check_id="FRAME-FATAL-10",
                    severity="FATAL",
                    message="Timeline contains zero visual shots.",
                )
            )
            report.is_valid = False
            report.has_fatal = True
            return report

        # FRAME-FATAL-10: Check timeline starts at 0
        if shots[0].start_us != 0:
            report.issues.append(
                FrameValidationIssue(
                    check_id="FRAME-FATAL-10",
                    severity="FATAL",
                    message=f"Visual timeline does not start at 0 (starts at {shots[0].start_us} us).",
                    shot_id=shots[0].shot_id,
                    timestamp_us=shots[0].start_us,
                )
            )

        # Check terminal visual coverage
        if master_audio_duration_us is not None:
            if abs(shots[-1].end_us - master_audio_duration_us) > 1:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-FATAL-10",
                        severity="FATAL",
                        message=f"Visual timeline end ({shots[-1].end_us} us) does not match master audio end ({master_audio_duration_us} us).",
                        shot_id=shots[-1].shot_id,
                        timestamp_us=shots[-1].end_us,
                    )
                )

        frame_dur_us = self.timebase.frame_duration_us

        for i, s in enumerate(shots):
            # FRAME-ERR-02: Zero-frame or non-positive duration
            if s.duration_us <= 0 or (s.duration_frames is not None and s.duration_frames <= 0):
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-ERR-02",
                        severity="ERROR",
                        message=f"Shot {s.shot_id} has non-positive duration ({s.duration_us} us).",
                        shot_id=s.shot_id,
                        timestamp_us=s.start_us,
                    )
                )

            # FRAME-ERR-01: Visual boundary off-grid (internal cuts)
            if i > 0:
                if not self.timebase.is_on_grid(s.start_us):
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-01",
                            severity="ERROR",
                            message=f"Shot {s.shot_id} start timestamp {s.start_us} us is off frame-grid.",
                            shot_id=s.shot_id,
                            timestamp_us=s.start_us,
                        )
                    )

            # FRAME-ERR-03 / FRAME-ERR-04: Continuity check
            if i > 0:
                prev_end = shots[i - 1].end_us
                if s.start_us > prev_end:
                    gap_us = s.start_us - prev_end
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-03",
                            severity="ERROR",
                            message=f"Visual gap of {gap_us} us detected between shot {shots[i-1].shot_id} and {s.shot_id}.",
                            shot_id=s.shot_id,
                            timestamp_us=prev_end,
                        )
                    )
                elif s.start_us < prev_end:
                    overlap_us = prev_end - s.start_us
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-04",
                            severity="ERROR",
                            message=f"Visual overlap of {overlap_us} us detected between shot {shots[i-1].shot_id} and {s.shot_id}.",
                            shot_id=s.shot_id,
                            timestamp_us=s.start_us,
                        )
                    )

            # FRAME-WARN-08: Quantization error > 0.5 frame
            q_diag = s.diagnostics.get("quantization", {})
            err_us = q_diag.get("quantization_error_us", 0)
            if frame_dur_us > 0 and (err_us / frame_dur_us) > (self.policy.max_drift_tolerance_frames + 0.001):
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-WARN-08",
                        severity="WARNING",
                        message=f"Shot {s.shot_id} quantization error {err_us} us exceeds {self.policy.max_drift_tolerance_frames} frames.",
                        shot_id=s.shot_id,
                        timestamp_us=s.start_us,
                    )
                )

            # FRAME-ERR-09: Motion safety regression
            mp = s.motion_profile or {}
            vel = mp.get("effective_velocity_pct_per_sec", 0.0)
            is_tail = s.boundary_start_reason in ("tail_beat", "speech_end") and not s.cue_ids
            if vel > 5.01:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-ERR-09",
                        severity="ERROR",
                        message=f"Shot {s.shot_id} effective velocity {vel}%/s violates absolute clamp 5.0%/s.",
                        shot_id=s.shot_id,
                        timestamp_us=s.start_us,
                    )
                )
            elif is_tail and vel > 0.81:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-ERR-09",
                        severity="ERROR",
                        message=f"Tail shot {s.shot_id} velocity {vel}%/s exceeds tail limit 0.8%/s.",
                        shot_id=s.shot_id,
                        timestamp_us=s.start_us,
                    )
                )
            elif not is_tail and vel > 3.51:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-ERR-09",
                        severity="WARNING",
                        message=f"Normal speech shot {s.shot_id} velocity {vel}%/s exceeds recommended limit 3.5%/s.",
                        shot_id=s.shot_id,
                        timestamp_us=s.start_us,
                    )
                )

            # KEYFRAME CHECKS: FRAME-ERR-05, FRAME-ERR-06, FRAME-ERR-07
            kfs = mp.get("keyframe_list", [])
            if kfs:
                prev_offset = -1
                for kf in kfs:
                    kf_offset = kf.get("time_offset", 0)
                    if kf_offset < 0 or kf_offset > s.duration_us:
                        report.issues.append(
                            FrameValidationIssue(
                                check_id="FRAME-ERR-06",
                                severity="ERROR",
                                message=f"Shot {s.shot_id} keyframe offset {kf_offset} us is outside clip duration {s.duration_us} us.",
                                shot_id=s.shot_id,
                                timestamp_us=s.start_us + kf_offset,
                            )
                        )
                    if prev_offset >= 0:
                        if kf_offset == prev_offset:
                            report.issues.append(
                                FrameValidationIssue(
                                    check_id="FRAME-ERR-05",
                                    severity="ERROR",
                                    message=f"Shot {s.shot_id} duplicate keyframe at offset {kf_offset} us.",
                                    shot_id=s.shot_id,
                                    timestamp_us=s.start_us + kf_offset,
                                )
                            )
                        elif kf_offset < prev_offset:
                            report.issues.append(
                                FrameValidationIssue(
                                    check_id="FRAME-ERR-07",
                                    severity="ERROR",
                                    message=f"Shot {s.shot_id} non-monotonic keyframe offset {kf_offset} < {prev_offset} us.",
                                    shot_id=s.shot_id,
                                    timestamp_us=s.start_us + kf_offset,
                                )
                            )
                    # Check keyframe is on frame grid
                    abs_kf = s.start_us + kf_offset
                    if not self.timebase.is_on_grid(abs_kf):
                        report.issues.append(
                            FrameValidationIssue(
                                check_id="FRAME-ERR-01",
                                severity="ERROR",
                                message=f"Shot {s.shot_id} keyframe absolute timestamp {abs_kf} us is off frame-grid.",
                                shot_id=s.shot_id,
                                timestamp_us=abs_kf,
                            )
                        )
                    prev_offset = kf_offset
            else:
                # Default 2-keyframe model: start at 0, end at duration_us
                # Check end keyframe on frame grid (for internal shots)
                if i < len(shots) - 1:
                    if not self.timebase.is_on_grid(s.end_us):
                        report.issues.append(
                            FrameValidationIssue(
                                check_id="FRAME-ERR-01",
                                severity="ERROR",
                                message=f"Shot {s.shot_id} end keyframe timestamp {s.end_us} us is off frame-grid.",
                                shot_id=s.shot_id,
                                timestamp_us=s.end_us,
                            )
                        )

        if report.error_count > 0 or report.fatal_count > 0:
            report.is_valid = False
        if report.fatal_count > 0:
            report.has_fatal = True

        return report

    def validate_clips(
        self,
        clips: List[Any],
        master_audio_duration_us: Optional[int] = None,
    ) -> FrameValidationReport:
        """
        Validates EditPlanClip objects for frame-grid conformance.
        """
        report = FrameValidationReport()
        if not clips:
            report.issues.append(
                FrameValidationIssue(
                    check_id="FRAME-FATAL-10",
                    severity="FATAL",
                    message="Clip list is empty.",
                )
            )
            report.is_valid = False
            report.has_fatal = True
            return report

        if clips[0].start_us != 0:
            report.issues.append(
                FrameValidationIssue(
                    check_id="FRAME-FATAL-10",
                    severity="FATAL",
                    message=f"Clips do not start at 0 (starts at {clips[0].start_us} us).",
                )
            )

        if master_audio_duration_us is not None:
            if abs(clips[-1].end_us - master_audio_duration_us) > 1:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-FATAL-10",
                        severity="FATAL",
                        message=f"Clips end ({clips[-1].end_us} us) does not match master audio end ({master_audio_duration_us} us).",
                    )
                )

        for i, c in enumerate(clips):
            if c.duration_us <= 0:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-ERR-02",
                        severity="ERROR",
                        message=f"Clip {getattr(c, 'clip_id', i)} has non-positive duration ({c.duration_us} us).",
                    )
                )
            if i > 0:
                if not self.timebase.is_on_grid(c.start_us):
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-01",
                            severity="ERROR",
                            message=f"Clip {getattr(c, 'clip_id', i)} start {c.start_us} us is off frame-grid.",
                        )
                    )
                prev_end = clips[i - 1].end_us
                if c.start_us > prev_end:
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-03",
                            severity="ERROR",
                            message=f"Clip gap of {c.start_us - prev_end} us between clip {i-1} and {i}.",
                        )
                    )
                elif c.start_us < prev_end:
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-04",
                            severity="ERROR",
                            message=f"Clip overlap of {prev_end - c.start_us} us between clip {i-1} and {i}.",
                        )
                    )

        if report.error_count > 0 or report.fatal_count > 0:
            report.is_valid = False
        if report.fatal_count > 0:
            report.has_fatal = True
        return report

    def validate_draft(
        self,
        draft_info_data: Dict[str, Any],
        master_audio_duration_us: Optional[int] = None,
    ) -> FrameValidationReport:
        """
        Validates CapCut draft JSON data for frame-grid conformance and keyframe monotonicity.
        """
        report = FrameValidationReport()
        tracks = draft_info_data.get("tracks", [])
        video_tracks = [t for t in tracks if t.get("type") == "video"]

        if not video_tracks:
            report.issues.append(
                FrameValidationIssue(
                    check_id="FRAME-FATAL-10",
                    severity="FATAL",
                    message="Draft has no video track.",
                )
            )
            report.is_valid = False
            report.has_fatal = True
            return report

        main_track = video_tracks[0]
        segments = main_track.get("segments", [])
        if not segments:
            report.issues.append(
                FrameValidationIssue(
                    check_id="FRAME-FATAL-10",
                    severity="FATAL",
                    message="Main video track contains zero segments.",
                )
            )
            report.is_valid = False
            report.has_fatal = True
            return report

        sorted_segs = []
        for seg in segments:
            tr = seg.get("target_timerange", {})
            st = tr.get("start", 0)
            dur = tr.get("duration", 0)
            sorted_segs.append((st, st + dur, dur, seg))

        sorted_segs.sort(key=lambda x: x[0])

        if sorted_segs[0][0] != 0:
            report.issues.append(
                FrameValidationIssue(
                    check_id="FRAME-FATAL-10",
                    severity="FATAL",
                    message=f"First segment starts at {sorted_segs[0][0]} us instead of 0.",
                )
            )

        if master_audio_duration_us is not None:
            if abs(sorted_segs[-1][1] - master_audio_duration_us) > 1:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-FATAL-10",
                        severity="FATAL",
                        message=f"Draft video end ({sorted_segs[-1][1]} us) does not match audio end ({master_audio_duration_us} us).",
                    )
                )

        for i, (st, en, dur, seg) in enumerate(sorted_segs):
            seg_id = seg.get("id", f"seg_{i}")
            if dur <= 0:
                report.issues.append(
                    FrameValidationIssue(
                        check_id="FRAME-ERR-02",
                        severity="ERROR",
                        message=f"Segment {seg_id} duration is non-positive ({dur} us).",
                    )
                )
            if i > 0:
                if not self.timebase.is_on_grid(st):
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-01",
                            severity="ERROR",
                            message=f"Segment {seg_id} start {st} us is off frame-grid.",
                        )
                    )
                prev_end = sorted_segs[i - 1][1]
                if st > prev_end:
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-03",
                            severity="ERROR",
                            message=f"Draft visual gap of {st - prev_end} us before {seg_id}.",
                        )
                    )
                elif st < prev_end:
                    report.issues.append(
                        FrameValidationIssue(
                            check_id="FRAME-ERR-04",
                            severity="ERROR",
                            message=f"Draft visual overlap of {prev_end - st} us before {seg_id}.",
                        )
                    )

            # Check keyframes in segment
            for kf_group in seg.get("common_keyframes", []):
                kf_prop = kf_group.get("property_type", "unknown")
                kf_list = kf_group.get("keyframe_list", [])
                prev_kf_offset = -1
                for kf in kf_list:
                    kf_offset = kf.get("time_offset", 0)
                    if kf_offset < 0 or kf_offset > dur:
                        report.issues.append(
                            FrameValidationIssue(
                                check_id="FRAME-ERR-06",
                                severity="ERROR",
                                message=f"Segment {seg_id} keyframe offset {kf_offset} us outside duration {dur} us for {kf_prop}.",
                            )
                        )
                    if prev_kf_offset >= 0:
                        if kf_offset == prev_kf_offset:
                            report.issues.append(
                                FrameValidationIssue(
                                    check_id="FRAME-ERR-05",
                                    severity="ERROR",
                                    message=f"Segment {seg_id} duplicate keyframe at offset {kf_offset} us for {kf_prop}.",
                                )
                            )
                        elif kf_offset < prev_kf_offset:
                            report.issues.append(
                                FrameValidationIssue(
                                    check_id="FRAME-ERR-07",
                                    severity="ERROR",
                                    message=f"Segment {seg_id} non-monotonic keyframes {kf_offset} < {prev_kf_offset} us for {kf_prop}.",
                                )
                            )
                    # Check keyframe grid
                    abs_kf = st + kf_offset
                    # End keyframe of last clip may match terminal audio duration
                    if i < len(sorted_segs) - 1 or kf_offset < dur:
                        if not self.timebase.is_on_grid(abs_kf):
                            report.issues.append(
                                FrameValidationIssue(
                                    check_id="FRAME-ERR-01",
                                    severity="ERROR",
                                    message=f"Segment {seg_id} keyframe timestamp {abs_kf} us is off frame-grid.",
                                )
                            )
                    prev_kf_offset = kf_offset

        if report.error_count > 0 or report.fatal_count > 0:
            report.is_valid = False
        if report.fatal_count > 0:
            report.has_fatal = True
        return report
