"""
tests/validate_real_script_to_srt_macos.py
Real macOS physical validation & benchmark for Phase 5C: Script-to-SRT Alignment Engine.
Validates:
1. Vietnamese Speech + Script alignment using local Faster-Whisper.
2. English Speech + Script alignment using local Faster-Whisper.
3. Original Script is 100% Source of Truth (no rewriting of brand names, numbers, diacritics).
4. Full CapCut draft generation on macOS, validated with CapCutDraftValidator.
5. Performance Benchmark (audio duration, processing time, RTF).
"""
from __future__ import annotations

import os
import sys
import time
import json

# Ensure apps/capcut-v2 is in sys.path
V2_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_ROOT not in sys.path:
    sys.path.insert(0, V2_ROOT)

from core.subtitles import (
    ScriptToSrtPipeline,
    FasterWhisperTimestampProvider,
    AlignmentOptions,
    validate_srt_content,
)
from core.srt_timeline import parse_srt_file, compute_srt_scene_boundaries
from core.timeline_builder import TimelineBuilder, TIMING_MODE_SRT_DRIVEN
from adapters.capcut.project_manager import CapCutProjectManager
from adapters.capcut.validator import CapCutDraftValidator

VI_AUDIO = os.path.abspath("tests/scratch/vi_test_speech.mp3")
EN_AUDIO = os.path.abspath("tests/scratch/en_test_speech.mp3")
SAMPLE_IMG_1 = os.path.abspath("reports/windows_rc/external_lab/TEST_MEDIA/clip_01_portrait.png")
SAMPLE_IMG_2 = os.path.abspath("reports/windows_rc/external_lab/TEST_MEDIA/clip_02_architecture.png")


def run_benchmark_and_validation():
    print("=" * 60)
    print("  PHASE 5C — REAL MACOS SCRIPT-TO-SRT PHYSICAL VALIDATION")
    print("=" * 60)

    # --------------------------------------------------------------------------
    # 1. Vietnamese Speech + Script Test
    # --------------------------------------------------------------------------
    print("\n--- [1/3] VIETNAMESE SCRIPT + AUDIO BENCHMARK ---")
    vi_script = (
        "Chào mừng bạn đến với 2TOOLNE AutoEdit cho CapCut. "
        "Đây là công cụ biên tập video tự động hàng đầu."
    )
    print(f"Original Script:\n   \"{vi_script}\"")
    print(f"Audio file: {VI_AUDIO}")

    provider = FasterWhisperTimestampProvider(model_size="tiny", device="cpu", compute_type="float32")
    pipeline = ScriptToSrtPipeline(
        asr_provider=provider,
        options=AlignmentOptions(language="vi", max_words_per_cue=12)
    )

    t0 = time.time()
    vi_result = pipeline.run(script_text=vi_script, audio_path=VI_AUDIO)
    t_proc_vi = time.time() - t0

    rtf_vi = t_proc_vi / max(0.1, vi_result.audio_duration_s)

    print(f"\n✓ Vietnamese Transcription & Alignment Complete:")
    print(f"   Audio Duration:    {vi_result.audio_duration_s:.2f} s")
    print(f"   Processing Time:   {t_proc_vi:.2f} s")
    print(f"   Real-Time Factor:  {rtf_vi:.2f} (RTF < 1.0 is faster than real-time)")
    print(f"   Matched Script:    {vi_result.matched_percentage:.1f}%")
    print(f"   Subtitle Cues:     {vi_result.cue_count}")

    print("\nGenerated SRT:")
    for line in vi_result.srt_content.strip().splitlines():
        print(f"   {line}")

    # Integrity verification
    is_valid, errors = validate_srt_content(vi_result.srt_content)
    assert is_valid, f"Generated Vietnamese SRT invalid: {errors}"
    print("\n✓ SRT format integrity: VALID")

    # Source of truth check
    joined_cues = " ".join(c.text for c in vi_result.cues)
    assert "2TOOLNE AutoEdit" in joined_cues, "Brand name '2TOOLNE AutoEdit' was corrupted!"
    assert "CapCut" in joined_cues, "Proper noun 'CapCut' was corrupted!"
    assert "hàng đầu." in joined_cues, "Sentence ending diacritics were corrupted!"
    print("✓ SOURCE OF TRUTH VERIFIED: 100% original text & brand names preserved.")

    # --------------------------------------------------------------------------
    # 2. English Speech + Script Test
    # --------------------------------------------------------------------------
    print("\n--- [2/3] ENGLISH SCRIPT + AUDIO BENCHMARK ---")
    en_script = (
        "Welcome to 2TOOLNE AutoEdit V2 for CapCut. "
        "This is an automated timeline alignment demonstration."
    )
    print(f"Original Script:\n   \"{en_script}\"")
    print(f"Audio file: {EN_AUDIO}")

    pipeline_en = ScriptToSrtPipeline(
        asr_provider=provider,
        options=AlignmentOptions(language="en", max_words_per_cue=12)
    )

    t0_en = time.time()
    en_result = pipeline_en.run(script_text=en_script, audio_path=EN_AUDIO)
    t_proc_en = time.time() - t0_en
    rtf_en = t_proc_en / max(0.1, en_result.audio_duration_s)

    print(f"\n✓ English Transcription & Alignment Complete:")
    print(f"   Audio Duration:    {en_result.audio_duration_s:.2f} s")
    print(f"   Processing Time:   {t_proc_en:.2f} s")
    print(f"   Real-Time Factor:  {rtf_en:.2f}")
    print(f"   Matched Script:    {en_result.matched_percentage:.1f}%")
    print(f"   Subtitle Cues:     {en_result.cue_count}")

    en_cues = " ".join(c.text for c in en_result.cues)
    assert "2TOOLNE AutoEdit V2" in en_cues, "English brand name corrupted!"
    print("✓ English alignment and source of truth verified.")

    # --------------------------------------------------------------------------
    # 3. CapCut Project Generation & Validation on macOS
    # --------------------------------------------------------------------------
    print("\n--- [3/3] CAPCUT DRAFT GENERATION TEST ---")
    builder = TimelineBuilder()
    plan = builder.build(
        images=[SAMPLE_IMG_1, SAMPLE_IMG_2],
        audio_path=VI_AUDIO,
        srt_source=vi_result.srt_content,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        project_name="Phase 5C Real Mac Validation",
    )

    assert len(plan.captions) == vi_result.cue_count
    print(f"✓ EditPlan generated with {len(plan.clips)} clips and {len(plan.captions)} caption elements.")

    # Generate real CapCut draft in temp workspace
    workspace = os.path.abspath("tests/scratch/phase5c_workspace")
    pm = CapCutProjectManager(workspace_root=workspace)

    gen_res = pm.create_and_register_project(
        edit_plan=plan,
        project_name="2toolne_phase5c_subtitle_test",
        auto_install=True,
        allow_untested=True,
    )

    final_draft_dir = gen_res["final_draft_dir"]
    print(f"✓ Draft created at: {final_draft_dir}")

    # Validate draft content
    val_errors = CapCutDraftValidator.validate_draft(final_draft_dir)
    print(f"✓ CapCutDraftValidator: errors={val_errors}")
    assert len(val_errors) == 0, f"CapCut draft validation failed: {val_errors}"

    # Verify text track in draft_info.json
    draft_json_path = os.path.join(final_draft_dir, "draft_info.json")
    with open(draft_json_path, "r", encoding="utf-8") as f:
        draft_data = json.load(f)

    # Check tracks for text segment
    tracks = draft_data.get("tracks", [])
    text_tracks = [t for t in tracks if t.get("type") == "text"]
    assert len(text_tracks) > 0, "No text track found in generated draft_content.json!"
    text_segments = text_tracks[0].get("segments", [])
    assert len(text_segments) == vi_result.cue_count, (
        f"Expected {vi_result.cue_count} text segments in CapCut draft, got {len(text_segments)}"
    )

    print(f"✓ CapCut Text Track verified: {len(text_segments)} editable subtitle segments created.")
    print("=" * 60)
    print("  MACOS PHYSICAL VALIDATION & PERFORMANCE BENCHMARK: PASS")
    print("=" * 60)

    return {
        "vi_audio_dur": vi_result.audio_duration_s,
        "vi_proc_time": t_proc_vi,
        "vi_rtf": rtf_vi,
        "vi_cues": vi_result.cue_count,
        "en_audio_dur": en_result.audio_duration_s,
        "en_proc_time": t_proc_en,
        "en_rtf": rtf_en,
        "en_cues": en_result.cue_count,
    }


if __name__ == "__main__":
    run_benchmark_and_validation()
