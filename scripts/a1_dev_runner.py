#!/usr/bin/env python3
"""
scripts/a1_dev_runner.py
Rapid iteration runner for Phase A1 VisualShotPlanner development.
Executes against cached A0 artifacts completely offline without Whisper or CapCut Desktop.
Outputs:
  - visual_shots.json
  - metrics.json
  - normalized_editplan.json
  - normalized_draft.json
  - a1_shadow_comparison.json
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
import time
from pathlib import Path
from typing import Any, Dict, List
from PIL import Image

# Ensure PYTHONPATH
REPO_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO_ROOT))
sys.path.insert(0, str(REPO_ROOT / "apps" / "capcut-v2"))

from adapters.capcut.adapter import CapCutAdapter
from core.dev_cache import ArtifactCache, CacheStage, compute_content_hash
from core.dev_tools.draft_normalizer import DraftNormalizer
from core.edit_plan import EditPlan
from core.preset_manager import RulePreset, PRESET_BASIC_SLIDESHOW
from core.subtitles.models import SubtitleCue, SpeechWordTimestamp
from core.timeline_builder import TimelineBuilder, TIMING_MODE_SRT_DRIVEN
from core.visual.models import VisualPlannerEngine, VisualPlannerOptions, VisualShot
from core.visual.pipeline_adapter import VisualPipelineAdapter
from tests.fixtures.golden import GoldenCorpus


def format_timestamp(seconds: float) -> str:
    """Format seconds into MM:SS.mmm string."""
    m = int(seconds // 60)
    s = seconds - (m * 60)
    return f"{m:02d}:{s:06.3f}"


def format_review_table(shots: List[VisualShot], start_s: float, end_s: float, title: str) -> str:
    """Formats a segment of visual shots into a markdown review table."""
    lines = [
        f"#### {title}",
        "| Start | End | Duration | Image | Cues | Para | Sent | Boundary Reason | Reuse | Motion |",
        "| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |",
    ]
    matching = [s for s in shots if s.start_s < end_s and s.end_s > start_s]
    for s in matching:
        dur_str = f"{s.duration_s:.2f}s"
        start_str = f"`{format_timestamp(s.start_s)}`"
        end_str = f"`{format_timestamp(s.end_s)}`"
        img_str = f"`{s.image_id}`"
        cue_str = f"C{min(s.cue_ids)}–C{max(s.cue_ids)}" if s.cue_ids else "(Music)"
        para_str = f"P{s.paragraph_ids[0]}" if s.paragraph_ids else "Tail"
        sent_str = f"S{s.sentence_ids[0]}" if s.sentence_ids else "—"
        reason = s.boundary_end_reason.replace("_", " ").title()
        reuse = s.reuse_count
        m_type = s.motion_profile.get("motion_type", "NONE")
        v_rate = s.motion_profile.get("effective_velocity_pct_per_sec", 0.0)
        motion_str = f"{m_type} ({v_rate:.1f}%/s)"
        lines.append(
            f"| {start_str} | {end_str} | `{dur_str}` | {img_str} | {cue_str} | {para_str} | {sent_str} | {reason} | {reuse} | {motion_str} |"
        )
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="AutoEdit Phase A1 Offline Development Runner")
    parser.add_argument("--fixture", default="GOLDEN_LONG_01", help="Golden fixture to execute against")
    parser.add_argument("--out-dir", default="reports/accuracy/a1/iteration_outputs", help="Output directory for JSON artifacts")
    parser.add_argument("--cache-dir", default=None, help="Explicit dev cache directory")

    args = parser.parse_args()

    t0 = time.perf_counter()
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    fixture = GoldenCorpus.get_fixture(args.fixture)
    print(f"Loaded fixture [{args.fixture}]: {len(fixture.words)} words, {len(fixture.images)} images")

    # Generate or reuse mock images for visual planning
    mock_dir = out_dir / "mock_images"
    mock_dir.mkdir(exist_ok=True)
    image_paths = []
    for i, orig_p in enumerate(fixture.images, 1):
        fname = os.path.basename(orig_p)
        p = mock_dir / fname
        if not p.is_file():
            img = Image.new("RGB", (32, 32), color=(i * 12 % 255, i * 24 % 255, i * 36 % 255))
            img.save(str(p))
        image_paths.append(str(p))

    # Load rich A0 SubtitleCues from cache if available
    cache_cues_path = REPO_ROOT / "reports" / "accuracy" / "a0" / "long_01_subtitles_cache.json"
    if args.fixture == "GOLDEN_LONG_01" and cache_cues_path.exists():
        with open(cache_cues_path, "r", encoding="utf-8") as f:
            cues_data = json.load(f)
        cue_fields = {
            "index", "start_s", "end_s", "text", "paragraph_ids", "sentence_ids",
            "alignment_confidence", "source_token_start", "source_token_end"
        }
        sub_cues = [SubtitleCue(**{k: v for k, v in d.items() if k in cue_fields}) for d in cues_data]
    else:
        # Fallback to simple segmenter
        sub_cues = [
            SubtitleCue(
                index=idx + 1,
                start_s=w.start,
                end_s=w.end,
                text=w.word,
            )
            for idx, w in enumerate(fixture.words)
        ]

    # Step 1: Execute A1 DP Visual Planning Pipeline
    v_opts_a1 = VisualPlannerOptions(
        engine=VisualPlannerEngine.HIERARCHICAL_DP_V1,
        shadow_mode=False,
    )
    adapter = VisualPipelineAdapter(options=v_opts_a1)
    t_dp_start = time.perf_counter()
    planned_shots, report = adapter.plan_visual_shots(
        subtitles=sub_cues,
        images=image_paths,
        master_audio_duration_s=fixture.audio_duration_s,
    )
    dp_latency_ms = (time.perf_counter() - t_dp_start) * 1000.0

    # Build A1 EditPlan & Normalized Draft
    tb_a1 = TimelineBuilder(preset=PRESET_BASIC_SLIDESHOW)
    edit_plan_a1 = tb_a1.build(
        images=image_paths,
        subtitle_cues=sub_cues,
        audio_duration_s=fixture.audio_duration_s,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        visual_options=v_opts_a1,
    )

    capcut_adapter = CapCutAdapter()
    draft_dir_a1 = out_dir / "draft_a1"
    gen_result_a1 = capcut_adapter.generate(edit_plan_a1, str(draft_dir_a1), allow_untested=True)
    with open(os.path.join(draft_dir_a1, "draft_info.json"), "r", encoding="utf-8") as f:
        draft_raw_a1 = json.load(f)
    normalized_draft_a1 = DraftNormalizer.normalize_draft_info(draft_raw_a1)

    # Step 2: Execute Legacy Pipeline for Shadow Mode Comparison
    v_opts_legacy = VisualPlannerOptions(
        engine=VisualPlannerEngine.LEGACY,
        shadow_mode=True,
    )
    tb_legacy = TimelineBuilder(preset=PRESET_BASIC_SLIDESHOW)
    edit_plan_legacy = tb_legacy.build(
        images=image_paths,
        subtitle_cues=sub_cues,
        audio_duration_s=fixture.audio_duration_s,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        visual_options=v_opts_legacy,
    )

    shadow_comparison = VisualPipelineAdapter.compute_shadow_comparison(
        legacy_clips=edit_plan_legacy.clips,
        v1_shots=planned_shots,
        master_audio_duration_s=fixture.audio_duration_s,
    )

    # Step 3: Write visual_shots.json
    visual_shots_data = [
        {
            "shot_id": s.shot_id,
            "start_s": round(s.start_s, 3),
            "end_s": round(s.end_s, 3),
            "duration_s": round(s.duration_s, 3),
            "start_us": s.start_us,
            "end_us": s.end_us,
            "duration_us": s.duration_us,
            "image_id": s.image_id,
            "image_path": DraftNormalizer.sanitize_path(s.image_path),
            "cue_ids": s.cue_ids,
            "paragraph_ids": s.paragraph_ids,
            "sentence_ids": s.sentence_ids,
            "boundary_start_reason": s.boundary_start_reason,
            "boundary_end_reason": s.boundary_end_reason,
            "reuse_count": s.reuse_count,
            "is_internal_split": s.is_internal_split,
            "motion_profile": s.motion_profile,
            "diagnostics": s.diagnostics,
        }
        for s in planned_shots
    ]
    with open(out_dir / "visual_shots.json", "w", encoding="utf-8") as f:
        json.dump(visual_shots_data, f, indent=2, ensure_ascii=False)

    # Step 4: Compute duration distribution & metrics
    durations = [s.duration_s for s in planned_shots]
    speech_durations = [s.duration_s for s in planned_shots if s.cue_ids]
    tail_durations = [s.duration_s for s in planned_shots if not s.cue_ids]
    dur_dist = {
        "less_than_1_5s": sum(1 for d in durations if d < 1.5),
        "between_1_5s_and_2_0s": sum(1 for d in durations if 1.5 <= d < 2.0),
        "between_2_0s_and_3_0s": sum(1 for d in durations if 2.0 <= d < 3.0),
        "between_3_0s_and_5_0s": sum(1 for d in durations if 3.0 <= d < 5.0),
        "between_5_0s_and_8_0s": sum(1 for d in durations if 5.0 <= d < 8.0),
        "between_8_0s_and_10_0s": sum(1 for d in durations if 8.0 <= d < 10.0),
        "greater_than_10_0s": sum(1 for d in durations if d >= 10.0),
    }

    metrics = {
        "fixture_id": args.fixture,
        "total_audio_duration_s": fixture.audio_duration_s,
        "spoken_duration_s": sub_cues[-1].end_s if sub_cues else 0.0,
        "tail_duration_s": round(fixture.audio_duration_s - (sub_cues[-1].end_s if sub_cues else 0.0), 3),
        "total_images": len(image_paths),
        "total_cues": len(sub_cues),
        "total_shots": len(planned_shots),
        "speech_shots_count": len(speech_durations),
        "tail_shots_count": len(tail_durations),
        "dp_latency_ms": round(dp_latency_ms, 2),
        "duration_distribution": dur_dist,
        "validation_report": report.to_dict(),
        "wall_clock_duration_s": round(time.perf_counter() - t0, 3),
        "whisper_executed": False,
        "capcut_desktop_opened": False,
    }
    with open(out_dir / "metrics.json", "w", encoding="utf-8") as f:
        json.dump(metrics, f, indent=2, ensure_ascii=False)

    # Step 5: Write normalized_editplan.json & normalized_draft.json
    with open(out_dir / "normalized_editplan.json", "w", encoding="utf-8") as f:
        json.dump(edit_plan_a1.to_dict(), f, indent=2, ensure_ascii=False)

    with open(out_dir / "normalized_draft.json", "w", encoding="utf-8") as f:
        json.dump(normalized_draft_a1, f, indent=2, ensure_ascii=False)

    # Step 6: Write a1_shadow_comparison.json
    with open(out_dir / "a1_shadow_comparison.json", "w", encoding="utf-8") as f:
        json.dump(shadow_comparison, f, indent=2, ensure_ascii=False)

    total_s = time.perf_counter() - t0
    print(f"A1 Dev Runner executed successfully in {total_s:.3f}s (DP Latency: {dp_latency_ms:.2f}ms).")
    print(f"Total Shots: {len(planned_shots)} (Speech: {len(speech_durations)}, Tail: {len(tail_durations)})")
    print(f"Validation: valid={report.is_valid}, errors={report.error_count}, warnings={report.warning_count}")
    print(f"Artifacts generated in: {out_dir}\n")

    # Generate Human Review Tables
    print("=" * 80)
    print("OFFLINE REAL PLANNER OUTPUT — HUMAN REVIEW TABLES")
    print("=" * 80)
    t1 = format_review_table(planned_shots, 0.0, 120.0, "Table 1: Opening (00:00 – 02:00)")
    t2 = format_review_table(planned_shots, 600.0, 720.0, "Table 2: Mid-Narrative (10:00 – 12:00)")
    t3 = format_review_table(planned_shots, 1500.0, 1640.860, "Table 3: Pre-Climax & Narration End (25:00 – 27:20.860)")
    t4 = format_review_table(planned_shots, 1640.860, 1787.233, "Table 4: Silent Outro / Music Tail (27:20.860 – 29:47.233, 146.373s)")

    print(t1)
    print(t2)
    print(t3)
    print(t4)

    # Save review tables to review document
    review_path = out_dir / "HUMAN_REVIEW_TABLES.md"
    with open(review_path, "w", encoding="utf-8") as f:
        f.write("# Phase A1 Real Production Review Tables (`GOLDEN_LONG_01`)\n\n")
        f.write(t1 + "\n\n")
        f.write(t2 + "\n\n")
        f.write(t3 + "\n\n")
        f.write(t4 + "\n")
    print(f"Review tables written to: {review_path}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
