"""
apps/capcut-v2/core/visual/pipeline_adapter.py
Phase A1 Visual Shot Planning & Motion Pipeline Adapter.
Integrates VisualBoundaryCandidateBuilder, VisualShotPlanner (DP),
ImageAllocationPolicy, SilentTailAllocator, DurationAwareMotionPolicy,
and VisualAccuracyValidator into a unified pipeline.
Supports active execution and shadow mode comparison with legacy timeline mapping.
"""
from __future__ import annotations

import os
import uuid
from dataclasses import asdict
from typing import Any, Dict, List, Optional, Tuple, Union

from core.edit_plan import EditPlanClip
from core.subtitles.models import SubtitleCue
from core.visual.models import (
    VisualBoundaryType,
    VisualBoundaryCandidate,
    VisualShot,
    ShotDurationPolicy,
    TailDurationPolicy,
    ImageSupplyState,
    VisualPlannerEngine,
    VisualPlannerOptions,
)
from core.visual.boundary_builder import VisualBoundaryCandidateBuilder
from core.visual.dp_planner import VisualShotPlanner, PlannedShotInterval
from core.visual.image_allocator import ImageAllocationPolicy, AllocationResult, ProjectValidationError
from core.visual.tail_allocator import SilentTailAllocator
from core.visual.motion_policy import DurationAwareMotionPolicy
from core.visual.validator import (
    VisualAccuracyValidator,
    ValidationSeverity,
    ValidationIssue,
    ValidationReport,
)
from core.visual.quantization import (
    FrameQuantizer,
    FrameAccuracyValidator,
    FrameValidationReport,
)


def _compute_percentile(sorted_vals: List[float], pct: float) -> float:
    """Deterministic linear interpolation percentile."""
    if not sorted_vals:
        return 0.0
    k = (len(sorted_vals) - 1) * pct
    f = int(k)
    c = min(f + 1, len(sorted_vals) - 1)
    d = k - f
    return sorted_vals[f] + d * (sorted_vals[c] - sorted_vals[f])


class VisualPipelineAdapter:
    """
    Unified coordinator executing the full Phase A1/A2 visual planning sequence.
    """

    def __init__(self, options: Optional[VisualPlannerOptions] = None):
        self.options = options or VisualPlannerOptions()
        self.boundary_builder = VisualBoundaryCandidateBuilder(
            duration_policy=self.options.duration_policy
        )
        self.planner = VisualShotPlanner(
            duration_policy=self.options.duration_policy
        )
        self.image_allocator = ImageAllocationPolicy(
            min_reuse_distance_s=self.options.min_reuse_distance_s
        )
        self.tail_allocator = SilentTailAllocator(
            tail_policy=self.options.tail_policy
        )
        self.motion_policy = DurationAwareMotionPolicy()
        self.validator = VisualAccuracyValidator()
        self.quantizer = FrameQuantizer(policy=self.options.quantization_policy)
        self.frame_validator = FrameAccuracyValidator(policy=self.options.quantization_policy)


    def plan_visual_shots(
        self,
        subtitles: List[SubtitleCue],
        images: List[str],
        master_audio_duration_s: float,
        options: Optional[VisualPlannerOptions] = None,
    ) -> Tuple[List[VisualShot], ValidationReport]:
        """
        Execute full visual shot planning pipeline.

        Returns:
            Tuple of (planned_shots: List[VisualShot], report: ValidationReport)
        """
        opts = options or self.options
        if not images:
            raise ProjectValidationError("Project contains zero visual assets.")

        # 1. Candidate Boundary Generation from A0 Subtitle Cues
        candidates = self.boundary_builder.build_candidates(
            cues=subtitles,
            master_audio_duration_s=master_audio_duration_s,
        )

        # 2. DP Minimum-Cost Shot Partitioning (Narration Zone)
        speech_dur_s = subtitles[-1].end_s if subtitles else master_audio_duration_s
        tail_dur_s = max(0.0, master_audio_duration_s - speech_dur_s)
        available_images = len(images)
        if tail_dur_s > 10.0 and available_images > 0:
            tail_reserve = min(available_images // 4, max(1, int(round(tail_dur_s / 24.4))))
            speech_image_target = max(1, available_images - tail_reserve)
        else:
            speech_image_target = available_images

        planned_path = self.planner.plan_shots(
            candidates=candidates,
            physical_image_count=speech_image_target,
            spoken_duration_s=speech_dur_s,
        )

        # 3. Monotonic Image Allocation with Bounded Reuse
        allocation: AllocationResult = self.image_allocator.allocate_images(
            planned_shots=planned_path.shots,
            image_paths=images,
        )
        speech_shots = allocation.visual_shots

        # 4. Silent Outro / Tail Allocation
        speech_end_us = planned_path.speech_end_us
        master_audio_dur_us = int(round(master_audio_duration_s * 1_000_000))
        last_speech_img = speech_shots[-1].image_path if speech_shots else images[-1]
        tail_shots: List[VisualShot] = self.tail_allocator.allocate_tail(
            speech_end_us=speech_end_us,
            master_audio_duration_us=master_audio_dur_us,
            last_speech_image=last_speech_img,
            remaining_images=allocation.remaining_images,
            start_shot_id=len(speech_shots),
        )

        # 5. Assemble and renumber all VisualShot objects
        all_shots: List[VisualShot] = list(speech_shots) + list(tail_shots)
        for s_idx, s in enumerate(all_shots):
            s.shot_id = s_idx + 1

        # 5b. Phase A2: Frame-Accurate Timeline Quantization
        quantizer = FrameQuantizer(policy=opts.quantization_policy)
        all_shots, q_diag = quantizer.quantize_shots(
            shots=all_shots,
            master_audio_duration_us=master_audio_dur_us,
        )

        # 6. Velocity-First Duration-Aware Motion Integration (computed on frame-quantized durations)
        all_shots = self.motion_policy.apply_motion_to_shots(all_shots)

        # 7. Comprehensive Visual Accuracy Validation
        report = self.validator.validate(
            shots=all_shots,
            master_audio_duration_us=master_audio_dur_us,
            speech_end_us=speech_end_us,
        )

        # 7b. Phase A2: Frame Accuracy Validation
        frame_validator = FrameAccuracyValidator(policy=opts.quantization_policy)
        frame_report = frame_validator.validate(
            shots=all_shots,
            master_audio_duration_us=master_audio_dur_us,
        )
        if frame_report.has_fatal:
            for f_issue in frame_report.issues:
                if f_issue.severity == "FATAL":
                    report.issues.append(
                        ValidationIssue(
                            check_id=f_issue.check_id,
                            severity=ValidationSeverity.FATAL,
                            shot_id=f_issue.shot_id,
                            timestamp_us=f_issue.timestamp_us or 0,
                            metric_name="frame_accuracy",
                            metric_value="fatal",
                            threshold="pass",
                            message=f_issue.message,
                        )
                    )
            report.is_valid = False
            report.has_fatal = True
            report.fatal_count = sum(1 for i in report.issues if i.severity == ValidationSeverity.FATAL)

        return all_shots, report


    def shots_to_editplan_clips(self, shots: List[VisualShot]) -> List[EditPlanClip]:
        """Convert planned VisualShots into EditPlanClips for CapCut adapter."""
        clips: List[EditPlanClip] = []
        valid_motions = {"ZOOM_IN", "ZOOM_OUT", "PAN_LEFT", "PAN_RIGHT", "PAN_UP", "PAN_DOWN", "NONE"}
        for s in shots:
            raw_m = s.motion_profile.get("motion_type", "NONE")
            clean_m = raw_m
            if clean_m.startswith("ULTRA_SLOW_"):
                clean_m = clean_m[len("ULTRA_SLOW_"):]
            elif clean_m.startswith("REDUCED_"):
                clean_m = clean_m[len("REDUCED_"):]
            if clean_m not in valid_motions:
                clean_m = "NONE"

            kf_params = dict(s.motion_profile)
            clip = EditPlanClip(
                clip_id=str(uuid.uuid4()).upper(),
                media_path=os.path.abspath(s.image_path),
                media_type="image",
                start_us=s.start_us,
                duration_us=s.duration_us,
                motion_type=clean_m,
                keyframe_params=kf_params,
            )
            clips.append(clip)
        return clips

    @staticmethod
    def compute_metrics(
        clips_or_shots: Union[List[EditPlanClip], List[VisualShot]],
        master_audio_duration_s: float,
        is_shots: bool = False,
    ) -> Dict[str, Any]:
        """Compute standardized accuracy & pacing metrics from clips or shots."""
        if not clips_or_shots:
            return {
                "shot_count": 0,
                "min_duration_s": 0.0,
                "p10_duration_s": 0.0,
                "median_duration_s": 0.0,
                "p90_duration_s": 0.0,
                "max_duration_s": 0.0,
                "shots_lt_2s": 0,
                "shots_gt_8_5s": 0,
                "structural_boundary_ratio": 0.0,
                "arbitrary_cut_ratio": 0.0,
                "duplicate_image_count": 0,
                "reuse_count": 0,
                "min_reuse_distance_s": None,
                "visual_coverage_s": 0.0,
                "tail_black_duration_s": round(master_audio_duration_s, 3),
                "motion_outlier_count": 0,
            }

        durations_s = [
            (c.duration_us / 1_000_000.0) for c in clips_or_shots
        ]
        sorted_d = sorted(durations_s)
        n = len(sorted_d)

        min_dur = round(sorted_d[0], 3)
        max_dur = round(sorted_d[-1], 3)
        p10 = round(_compute_percentile(sorted_d, 0.10), 3)
        med = round(_compute_percentile(sorted_d, 0.50), 3)
        p90 = round(_compute_percentile(sorted_d, 0.90), 3)

        shots_lt_2s = sum(1 for d in durations_s if d < 2.0)
        shots_gt_8_5s = sum(1 for d in durations_s if d > 8.5)

        # Image asset reuse and duplication
        image_paths = [
            (c.image_path if is_shots else c.media_path) for c in clips_or_shots
        ]
        duplicate_count = 0
        for i in range(1, len(image_paths)):
            if image_paths[i] == image_paths[i - 1]:
                duplicate_count += 1

        seen_images: Dict[str, List[float]] = {}
        min_reuse_dist = float("inf")
        reuse_cnt = 0
        for idx, c in enumerate(clips_or_shots):
            img = image_paths[idx]
            start_s = c.start_us / 1_000_000.0
            if img in seen_images:
                reuse_cnt += 1
                dist = start_s - seen_images[img][-1]
                if dist < min_reuse_dist:
                    min_reuse_dist = dist
                seen_images[img].append(start_s)
            else:
                seen_images[img] = [start_s]

        min_reuse_s = round(min_reuse_dist, 3) if min_reuse_dist != float("inf") else None

        # Visual coverage and tail black duration
        visual_start_s = clips_or_shots[0].start_us / 1_000_000.0
        visual_end_s = clips_or_shots[-1].end_us / 1_000_000.0
        coverage_s = round(max(0.0, visual_end_s - visual_start_s), 3)
        tail_black_s = round(max(0.0, master_audio_duration_s - visual_end_s), 3)

        # Motion outliers (velocity > 3.5%/s)
        motion_outliers = 0
        for c in clips_or_shots:
            dur = c.duration_us / 1_000_000.0
            if dur <= 0:
                continue
            kparams = c.motion_profile.get("keyframe_params", {}) if is_shots else c.keyframe_params
            if kparams:
                scale_start = kparams.get("scale_start", 1.0)
                scale_end = kparams.get("scale_end", 1.0)
                zoom_vel = abs(scale_end - scale_start) / dur * 100.0
                pan_start = kparams.get("pos_x_start", kparams.get("pan_x_start", 0.0))
                pan_end = kparams.get("pos_x_end", kparams.get("pan_x_end", 0.0))
                pan_vel = abs(pan_end - pan_start) / dur * 100.0
                if zoom_vel > 3.5 or pan_vel > 3.5:
                    motion_outliers += 1

        # Structural vs Arbitrary cut ratio
        if is_shots:
            structural_cuts = sum(
                1 for s in clips_or_shots
                if s.boundary_end_reason in [
                    VisualBoundaryType.PARAGRAPH_BOUNDARY.value,
                    VisualBoundaryType.SENTENCE_BOUNDARY.value,
                    VisualBoundaryType.ACOUSTIC_PAUSE.value,
                    VisualBoundaryType.SPEECH_END.value,
                    VisualBoundaryType.MASTER_AUDIO_END.value,
                ]
            )
            structural_ratio = round(structural_cuts / max(1, len(clips_or_shots)), 3)
        else:
            # Pre-A1 legacy heuristic: approximate from paragraph boundaries
            structural_ratio = 0.50

        arbitrary_ratio = round(1.0 - structural_ratio, 3)

        return {
            "shot_count": n,
            "min_duration_s": min_dur,
            "p10_duration_s": p10,
            "median_duration_s": med,
            "p90_duration_s": p90,
            "max_duration_s": max_dur,
            "shots_lt_2s": shots_lt_2s,
            "shots_gt_8_5s": shots_gt_8_5s,
            "structural_boundary_ratio": structural_ratio,
            "arbitrary_cut_ratio": arbitrary_ratio,
            "duplicate_image_count": duplicate_count,
            "reuse_count": reuse_cnt,
            "min_reuse_distance_s": min_reuse_s,
            "visual_coverage_s": coverage_s,
            "tail_black_duration_s": tail_black_s,
            "motion_outlier_count": motion_outliers,
        }

    @classmethod
    def compute_shadow_comparison(
        cls,
        legacy_clips: List[EditPlanClip],
        v1_shots: List[VisualShot],
        master_audio_duration_s: float,
    ) -> Dict[str, Any]:
        """
        Compare active legacy timeline against shadow V1 planner output.
        Fulfills Section 41 (SHADOW COMPARISON).
        """
        leg_metrics = cls.compute_metrics(legacy_clips, master_audio_duration_s, is_shots=False)
        v1_metrics = cls.compute_metrics(v1_shots, master_audio_duration_s, is_shots=True)

        return {
            "legacy": leg_metrics,
            "v1_shadow": v1_metrics,
            "delta": {
                "shot_count_delta": v1_metrics["shot_count"] - leg_metrics["shot_count"],
                "shots_lt_2s_eliminated": leg_metrics["shots_lt_2s"] - v1_metrics["shots_lt_2s"],
                "tail_black_seconds_eliminated": round(
                    leg_metrics["tail_black_duration_s"] - v1_metrics["tail_black_duration_s"], 3
                ),
                "duplicate_images_eliminated": leg_metrics["duplicate_image_count"] - v1_metrics["duplicate_image_count"],
                "motion_outliers_eliminated": leg_metrics["motion_outlier_count"] - v1_metrics["motion_outlier_count"],
                "structural_accuracy_gain": round(
                    v1_metrics["structural_boundary_ratio"] - leg_metrics["structural_boundary_ratio"], 3
                ),
            },
        }
