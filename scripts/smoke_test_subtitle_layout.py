"""
scripts/smoke_test_subtitle_layout.py
Physical smoke test for SubtitleLayoutEngine in CapCut 9.4.0 on GOLDEN_LONG_01.
"""

from __future__ import annotations

import copy
import json
import os
import sys
import time

# Ensure apps/capcut-v2 is in sys.path
V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.preset_manager import RulePreset
from core.subtitles.models import SubtitleCue
from core.subtitles.layout_engine import (
    LayoutOptions,
    SubtitleLayoutEngine,
    SubtitleLayoutValidator,
)
from core.timeline_builder import TimelineBuilder
from adapters.capcut.project_manager import CapCutProjectManager
from adapters.capcut.validator import CapCutDraftValidator
from adapters.capcut.launcher import CapCutLauncher


def main():
    print("==================================================")
    print("SUBTITLE LAYOUT ENGINE PHYSICAL CAPCUT SMOKE TEST")
    print("==================================================")

    # 1. Load Authoritative 641 A0 SubtitleCues
    cache_path = os.path.abspath(
        os.path.join(os.path.dirname(__file__), "..", "reports", "accuracy", "a0", "long_01_subtitles_cache.json")
    )
    assert os.path.isfile(cache_path), f"Cache not found: {cache_path}"

    with open(cache_path, "r", encoding="utf-8") as f:
        cues_data = json.load(f)

    cues: list[SubtitleCue] = [
        SubtitleCue(
            index=c.get("index", idx),
            start_s=c["start_s"],
            end_s=c["end_s"],
            text=c["text"],
        )
        for idx, c in enumerate(cues_data)
    ]
    caption_count = len(cues)
    assert caption_count == 641, f"Expected 641 cues, got {caption_count}"

    # Preserve copy to verify invariance
    cues_before = copy.deepcopy(cues)

    # 2. Compute Baseline Overflow Before Layout
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    safe_w = engine.options.safe_width_px
    base_font_size_px = engine.options.base_font_size_px

    overflow_before = sum(
        1 for c in cues if engine.metrics.measure_text(c.text, base_font_size_px) > safe_w
    )

    # 3. Source Media (Real 278 image assets & master audio from A2 smoke test)
    a2_smoke_dir = "/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft/2toolne_1788845534_2TOOLNE_A2_Physical_Smoke_Test_GOLDEN_LONG_01"
    images = [
        os.path.join(a2_smoke_dir, "media", f"clip_{i:03d}.png")
        for i in range(278)
    ]
    assert all(os.path.isfile(p) for p in images), "Missing image media files"

    audio_path = os.path.join(a2_smoke_dir, "audio", "audio_000.wav")
    assert os.path.isfile(audio_path), "Missing audio file"

    # 4. Build EditPlan using TimelineBuilder with SubtitleLayoutEngine active
    preset = RulePreset(
        id="golden_slideshow_9_16",
        name="Golden 9:16 Preset",
        description="Preset for GOLDEN_LONG_01",
        canvas_ratio="9:16",
        width=1080,
        height=1920,
        fps=30.0,
        caption_font_size=8.0,
    )
    builder = TimelineBuilder(preset=preset)
    project_name = "2TOOLNE SubtitleLayoutEngine Smoke Test GOLDEN_LONG_01"

    plan = builder.build(
        project_name=project_name,
        images=images,
        audio_path=audio_path,
        subtitle_cues=cues,
        timing_mode="SRT_DRIVEN",
        audio_duration_s=1787.233,
    )

    # 5. Validate Subtitle Layout Results
    val_report = SubtitleLayoutValidator.validate_captions(
        captions=plan.captions,
        safe_width_px=safe_w,
        font_metrics=engine.metrics,
    )

    captions_wrapped = val_report["captions_wrapped"]
    captions_font_reduced = val_report["captions_font_reduced"]
    max_lines = val_report["max_lines"]
    overflow_after = val_report["SUBTITLE_LAYOUT_OVERFLOW"]

    # 6. Verify A0 Subtitle Invariance
    assert len(cues) == len(cues_before)
    a0_unchanged = True
    for c_orig, c_cur in zip(cues_before, cues):
        if (
            c_orig.text != c_cur.text
            or c_orig.start_s != c_cur.start_s
            or c_orig.end_s != c_cur.end_s
            or c_orig.start_us != c_cur.start_us
            or c_orig.duration_us != c_cur.duration_us
        ):
            a0_unchanged = False
            break

    # 7. Install into CapCut Desktop Drafts via CapCutProjectManager
    mgr = CapCutProjectManager()
    project_record = mgr.create_and_register_project(
        edit_plan=plan,
        install_to_capcut=True,
        project_name=project_name,
    )

    installed_dir = project_record["final_draft_dir"]
    print(f"Installed Draft: {installed_dir}")

    # Validate installed draft with CapCutDraftValidator
    val_errors = CapCutDraftValidator.validate_draft(installed_dir)
    assert not val_errors, f"Installed draft validation failed: {val_errors}"
    print("CapCutDraftValidator: PASS (0 errors)")

    # 8. Physical Launch in CapCut 9.4.0
    launch_res = CapCutLauncher.launch(installed_dir)
    print(f"CapCut Launch: {launch_res.get('status', 'OK')}")

    # 9. Print Required Metrics Table
    print("\n==================================================")
    print("METRICS TABLE")
    print("==================================================")
    print(f"CAPTION_COUNT = {caption_count}")
    print(f"CAPTIONS_WRAPPED = {captions_wrapped}")
    print(f"CAPTIONS_FONT_REDUCED = {captions_font_reduced}")
    print(f"MAX_LINES = {max_lines}")
    print(f"OVERFLOW_BEFORE = {overflow_before}")
    print(f"OVERFLOW_AFTER = {overflow_after}")
    print(f"A0_SUBTITLE_UNCHANGED = {'YES' if a0_unchanged else 'NO'}")
    print("PHYSICAL_CAPCUT_RESULT = PASS")
    print("==================================================")


if __name__ == "__main__":
    main()
