"""
apps/capcut-v2/core/visual/tail_allocator.py
Allocates visual shots across the silent outro / audio tail.
Guarantees 100% video track coverage to master_audio_end with ZERO black screen.
"""
from __future__ import annotations

import os
from typing import List, Optional, Dict, Any

from .models import VisualShot, TailDurationPolicy


class SilentTailAllocator:
    """
    Deterministically paces visual shots across the master audio tail.
    """

    def __init__(self, tail_policy: Optional[TailDurationPolicy] = None):
        self.tail_policy = tail_policy or TailDurationPolicy()

    def allocate_tail(
        self,
        speech_end_us: int,
        master_audio_duration_us: int,
        last_speech_image: str,
        remaining_images: List[str],
        start_shot_id: int = 0,
    ) -> List[VisualShot]:
        """
        Allocates visual shots covering from speech_end_us to master_audio_duration_us.
        """
        if master_audio_duration_us <= speech_end_us:
            # Zero tail duration
            return []

        tail_dur_us = master_audio_duration_us - speech_end_us
        tail_dur_s = tail_dur_us / 1_000_000.0

        # Case C: Zero remaining images -> extend final speech image across the entire tail
        if not remaining_images:
            img_id = os.path.basename(last_speech_image).split(".")[0]
            hold_shot = VisualShot(
                shot_id=start_shot_id,
                start_us=speech_end_us,
                end_us=master_audio_duration_us,
                duration_us=tail_dur_us,
                image_id=img_id,
                image_path=last_speech_image,
                cue_ids=[],
                paragraph_ids=[],
                sentence_ids=[],
                boundary_start_reason="speech_end",
                boundary_end_reason="master_audio_end",
                is_internal_split=False,
                reuse_count=1,
                motion_profile={"motion_type": "ULTRA_SLOW", "target_velocity": 0.2},
                diagnostics={"tail_case": "HOLD_LAST_IMAGE_ZERO_REMAINING"},
            )
            return [hold_shot]

        k_rem = len(remaining_images)
        natural_s = tail_dur_s / float(k_rem)

        tail_shots: List[VisualShot] = []

        # Case A: Remaining images sufficient for healthy tail pacing (4.0s <= natural_s <= 25.0s)
        if self.tail_policy.hard_min_s <= natural_s <= self.tail_policy.soft_max_s:
            curr_start_us = speech_end_us
            avg_shot_us = tail_dur_us // k_rem

            for idx, img in enumerate(remaining_images):
                is_last = (idx == k_rem - 1)
                curr_end_us = master_audio_duration_us if is_last else curr_start_us + avg_shot_us
                dur_us = curr_end_us - curr_start_us
                img_id = os.path.basename(img).split(".")[0]

                shot = VisualShot(
                    shot_id=start_shot_id + idx,
                    start_us=curr_start_us,
                    end_us=curr_end_us,
                    duration_us=dur_us,
                    image_id=img_id,
                    image_path=img,
                    cue_ids=[],
                    paragraph_ids=[],
                    sentence_ids=[],
                    boundary_start_reason="speech_end" if idx == 0 else "tail_beat",
                    boundary_end_reason="master_audio_end" if is_last else "tail_beat",
                    is_internal_split=False,
                    reuse_count=0,
                    motion_profile={"motion_type": "ULTRA_SLOW", "target_velocity": 0.25},
                    diagnostics={"tail_case": "MONOTONIC_NATURAL_PACING", "natural_s": round(natural_s, 2)},
                )
                tail_shots.append(shot)
                curr_start_us = curr_end_us

            return tail_shots

        # Case B: Extreme tail duration (natural_s > 25.0s): allocate remaining images up to soft_max (20-25s)
        # then apply shortage fallback (final hold or reuse) for remainder
        curr_start_us = speech_end_us
        shot_dur_us = int(round(self.tail_policy.soft_max_s * 1_000_000))

        for idx, img in enumerate(remaining_images):
            dur = min(shot_dur_us, master_audio_duration_us - curr_start_us)
            curr_end_us = curr_start_us + dur
            img_id = os.path.basename(img).split(".")[0]

            shot = VisualShot(
                shot_id=start_shot_id + len(tail_shots),
                start_us=curr_start_us,
                end_us=curr_end_us,
                duration_us=dur,
                image_id=img_id,
                image_path=img,
                cue_ids=[],
                paragraph_ids=[],
                sentence_ids=[],
                boundary_start_reason="speech_end" if len(tail_shots) == 0 else "tail_beat",
                boundary_end_reason="master_audio_end" if curr_end_us == master_audio_duration_us else "tail_beat",
                is_internal_split=False,
                reuse_count=0,
                motion_profile={"motion_type": "ULTRA_SLOW", "target_velocity": 0.25},
                diagnostics={"tail_case": "SHORTAGE_INITIAL_ALLOCATION"},
            )
            tail_shots.append(shot)
            curr_start_us = curr_end_us
            if curr_start_us >= master_audio_duration_us:
                break

        # If remainder exists, hold the last available image or last speech image
        if curr_start_us < master_audio_duration_us:
            rem_dur_us = master_audio_duration_us - curr_start_us
            hold_img = remaining_images[-1] if remaining_images else last_speech_image
            hold_id = os.path.basename(hold_img).split(".")[0]
            hold_shot = VisualShot(
                shot_id=start_shot_id + len(tail_shots),
                start_us=curr_start_us,
                end_us=master_audio_duration_us,
                duration_us=rem_dur_us,
                image_id=hold_id,
                image_path=hold_img,
                cue_ids=[],
                paragraph_ids=[],
                sentence_ids=[],
                boundary_start_reason="tail_beat",
                boundary_end_reason="master_audio_end",
                is_internal_split=False,
                reuse_count=1,
                motion_profile={"motion_type": "ULTRA_SLOW", "target_velocity": 0.15},
                diagnostics={"tail_case": "EXTENDED_HOLD_FALLBACK"},
            )
            tail_shots.append(hold_shot)

        return tail_shots
