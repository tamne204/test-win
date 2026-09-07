"""
apps/capcut-v2/core/visual/image_allocator.py
Assigns physical image assets to planned visual shots in strict chronological monotonic order.
Enforces zero-image validation, shortage reuse constraints (>=60s distance), and surplus preservation.
"""
from __future__ import annotations

import os
from dataclasses import dataclass, field
from typing import List, Optional, Tuple, Dict, Any

from .models import VisualShot
from .dp_planner import PlannedShotInterval


class ProjectValidationError(Exception):
    """Raised when visual project invariants cannot be met (e.g. zero assets)."""
    pass


@dataclass
class AllocationResult:
    """Result of image allocation across speech narration shots."""
    visual_shots: List[VisualShot]
    used_images: List[str]
    remaining_images: List[str]
    reuse_count: int
    min_observed_reuse_distance_s: Optional[float]


class ImageAllocationPolicy:
    """
    Allocates physical images monotonically across planned shots.
    """

    def __init__(self, min_reuse_distance_s: float = 60.0):
        self.min_reuse_distance_s = min_reuse_distance_s

    def allocate_images(
        self,
        planned_shots: List[PlannedShotInterval],
        image_paths: List[str],
    ) -> AllocationResult:
        """
        Maps physical image assets to planned visual shots.
        """
        if not image_paths:
            raise ProjectValidationError("No physical visual assets supplied")

        if not planned_shots:
            return AllocationResult(
                visual_shots=[],
                used_images=[],
                remaining_images=list(image_paths),
                reuse_count=0,
                min_observed_reuse_distance_s=None,
            )

        num_shots = len(planned_shots)
        num_images = len(image_paths)

        assigned_shots: List[VisualShot] = []
        used_image_paths: List[str] = []
        # Track last use end timestamp for each image path
        image_last_end_us: Dict[str, int] = {}
        image_use_counts: Dict[str, int] = {}
        min_reuse_dist_s: Optional[float] = None
        total_reuses = 0

        for idx, pshot in enumerate(planned_shots):
            if idx < num_images:
                # Monotonic forward assignment
                chosen_image = image_paths[idx]
                reuse_count = image_use_counts.get(chosen_image, 0)
                prev_dist_us = None
            else:
                # Shortage: must reuse an image
                total_reuses += 1
                chosen_image, prev_dist_us = self._select_reuse_image(
                    current_start_us=pshot.start_us,
                    image_paths=image_paths,
                    assigned_shots=assigned_shots,
                    image_last_end_us=image_last_end_us,
                )
                reuse_count = image_use_counts.get(chosen_image, 0) + 1
                dist_s = prev_dist_us / 1_000_000.0 if prev_dist_us else 0.0
                if min_reuse_dist_s is None or dist_s < min_reuse_dist_s:
                    min_reuse_dist_s = dist_s

            # Update usage tracking
            image_use_counts[chosen_image] = image_use_counts.get(chosen_image, 0) + 1
            image_last_end_us[chosen_image] = pshot.end_us
            if chosen_image not in used_image_paths:
                used_image_paths.append(chosen_image)

            img_id = os.path.basename(chosen_image).split(".")[0]

            vshot = VisualShot(
                shot_id=idx,
                start_us=pshot.start_us,
                end_us=pshot.end_us,
                duration_us=pshot.duration_us,
                image_id=img_id,
                image_path=chosen_image,
                cue_ids=list(pshot.cue_ids),
                paragraph_ids=list(pshot.paragraph_ids),
                sentence_ids=list(pshot.sentence_ids),
                boundary_start_reason=pshot.start_candidate.source_reason,
                boundary_end_reason=pshot.end_candidate.source_reason,
                is_internal_split=pshot.is_internal_split,
                reuse_count=reuse_count,
                previous_use_distance_us=prev_dist_us,
                planner_cost=pshot.cost,
                diagnostics={
                    "start_candidate_type": pshot.start_candidate.boundary_type.value,
                    "end_candidate_type": pshot.end_candidate.boundary_type.value,
                    "alternate_motion": (reuse_count > 0),
                },
            )
            assigned_shots.append(vshot)

        # Remaining unconsumed images are available for silent tail allocation
        remaining = image_paths[len(assigned_shots):] if len(assigned_shots) < num_images else []

        return AllocationResult(
            visual_shots=assigned_shots,
            used_images=used_image_paths,
            remaining_images=remaining,
            reuse_count=total_reuses,
            min_observed_reuse_distance_s=min_reuse_dist_s,
        )

    def _select_reuse_image(
        self,
        current_start_us: int,
        image_paths: List[str],
        assigned_shots: List[VisualShot],
        image_last_end_us: Dict[str, int],
    ) -> Tuple[str, int]:
        """
        Selects an image for reuse satisfying MIN_REUSE_DISTANCE_SECONDS (>= 60s),
        and avoiding adjacent duplicate and A-B-A oscillation.
        """
        min_dist_us = int(round(self.min_reuse_distance_s * 1_000_000))
        last_shot_img = assigned_shots[-1].image_path if assigned_shots else None
        second_last_shot_img = assigned_shots[-2].image_path if len(assigned_shots) >= 2 else None

        best_cand: Optional[str] = None
        best_dist_us: int = -1

        # Search candidates in oldest-used order
        for img in image_paths:
            # Prevent adjacent duplicate
            if img == last_shot_img:
                continue
            # Prevent rapid A-B-A loop
            if img == second_last_shot_img:
                continue

            last_end = image_last_end_us.get(img, 0)
            dist_us = current_start_us - last_end

            # Check if satisfies 60s minimum distance
            if dist_us >= min_dist_us:
                if dist_us > best_dist_us:
                    best_dist_us = dist_us
                    best_cand = img

        # Fallback if no image meets 60s distance: choose the one with maximum elapsed distance
        if best_cand is None:
            max_dist = -1
            for img in image_paths:
                if img == last_shot_img:
                    continue
                dist_us = current_start_us - image_last_end_us.get(img, 0)
                if dist_us > max_dist:
                    max_dist = dist_us
                    best_cand = img
            best_dist_us = max(0, max_dist)

        return best_cand or image_paths[0], max(0, best_dist_us)
