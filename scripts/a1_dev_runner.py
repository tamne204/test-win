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
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
from pathlib import Path
from PIL import Image

# Ensure PYTHONPATH
REPO_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO_ROOT))
sys.path.insert(0, str(REPO_ROOT / "apps" / "capcut-v2"))

from core.dev_cache import ArtifactCache, CacheStage, compute_content_hash
from core.dev_tools.draft_normalizer import DraftNormalizer
from core.dev_tools.pipeline_replay import PipelineReplayHarness
from core.subtitles.models import SpeechWordTimestamp
from tests.fixtures.golden import GoldenCorpus


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
    for i in range(1, min(len(fixture.images) + 1, 21)): # Up to 20 representative images
        p = mock_dir / f"img_{i:03d}.png"
        if not p.is_file():
            img = Image.new("RGB", (1080, 1920), color=(i * 12 % 255, i * 24 % 255, i * 36 % 255))
            img.save(str(p))
        image_paths.append(str(p))

    speech_words = [
        SpeechWordTimestamp(
            word=w.word,
            start=w.start,
            end=w.end,
            confidence=w.confidence,
            original_index=w.original_index,
        )
        for w in fixture.words
    ]

    harness = PipelineReplayHarness(cache_dir=args.cache_dir)
    draft_dir = out_dir / "draft_temp"

    # Replay through offline pipeline
    res = harness.replay_from_asr(
        script_text=fixture.script,
        speech_words=speech_words,
        images=image_paths,
        audio_duration_s=fixture.audio_duration_s,
        draft_target_dir=str(draft_dir),
        use_cache=True,
    )

    # 1. Output visual_shots.json (prepared format for future VisualShotPlanner)
    visual_shots = []
    for idx, clip in enumerate(res.edit_plan.clips):
        visual_shots.append({
            "shot_index": idx,
            "clip_id": clip.clip_id,
            "media_path": DraftNormalizer.sanitize_path(clip.media_path),
            "start_s": round(clip.start_us / 1_000_000.0, 3),
            "duration_s": round(clip.duration_us / 1_000_000.0, 3),
            "motion_type": clip.motion_type,
            "keyframe_params": clip.keyframe_params,
        })
    with open(out_dir / "visual_shots.json", "w", encoding="utf-8") as f:
        json.dump(visual_shots, f, indent=2, ensure_ascii=False)

    # 2. Output metrics.json
    metrics = {
        "fixture_id": args.fixture,
        "total_audio_duration_s": fixture.audio_duration_s,
        "total_clips": len(res.edit_plan.clips),
        "total_cues": len(res.subtitles),
        "replay_manifest": res.manifest.to_dict(),
        "wall_clock_duration_s": round(time.perf_counter() - t0, 3),
        "whisper_executed": False,
        "capcut_desktop_opened": False,
    }
    with open(out_dir / "metrics.json", "w", encoding="utf-8") as f:
        json.dump(metrics, f, indent=2, ensure_ascii=False)

    # 3. Output normalized_editplan.json
    with open(out_dir / "normalized_editplan.json", "w", encoding="utf-8") as f:
        json.dump(res.edit_plan.to_dict(), f, indent=2, ensure_ascii=False)

    # 4. Output normalized_draft.json
    with open(out_dir / "normalized_draft.json", "w", encoding="utf-8") as f:
        json.dump(res.normalized_draft, f, indent=2, ensure_ascii=False)

    total_s = time.perf_counter() - t0
    print(f"A1 Iteration completed in {total_s:.3f}s. Artifacts written to: {out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
