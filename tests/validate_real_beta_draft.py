"""
tests/validate_real_beta_draft.py
Executes end-to-end real validation for Phase 2 Beta Foundation:
1. Generates 3 full HD portrait images (1080x1920)
2. Generates a 12-second clean sinusoidal WAV audio file
3. Generates synchronized 3-scene SRT subtitles
4. Builds EditPlan via TimelineBuilder in TIMING_MODE_SRT_DRIVEN
5. Registers into real CapCut Desktop draft root via CapCutProjectManager (with lock & atomic write)
6. Validates the installed project using CapCutDraftValidator
7. Verifies root_meta_info.json integrity
"""
from __future__ import annotations

import os
import sys
import wave
import math
import struct
import json
from PIL import Image, ImageDraw, ImageFont

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.edit_plan import EditPlan
from core.preset_manager import PresetManager
from core.timeline_builder import TimelineBuilder, TIMING_MODE_SRT_DRIVEN
from adapters.capcut.project_manager import CapCutProjectManager, STATUS_READY
from adapters.capcut.validator import CapCutDraftValidator
from adapters.capcut.detector import CapCutDetector, STATUS_SUPPORTED


def generate_test_media(output_dir: str):
    os.makedirs(output_dir, exist_ok=True)

    # 1. Generate 3 Distinct Images
    img_paths = []
    colors = [
        ((235, 87, 87), "SCENE 1: HOANG HON DO", "2TOOLNE BETA FOUNDATION"),
        ((47, 128, 237), "SCENE 2: BIEN XANH SAU", "DETERMINISTIC TIMELINE"),
        ((39, 174, 96), "SCENE 3: RUNG XANH MUOT", "NATIVE KEYFRAMES & SRT"),
    ]

    for i, (bg_col, title, subtitle) in enumerate(colors):
        p = os.path.join(output_dir, f"beta_scene_{i+1}.png")
        img = Image.new("RGB", (1080, 1920), color=bg_col)
        draw = ImageDraw.Draw(img)

        # Draw framing lines
        draw.rectangle([40, 40, 1040, 1880], outline=(255, 255, 255), width=8)
        draw.rectangle([80, 80, 1000, 1840], outline=(255, 255, 255), width=2)

        # Center banner
        draw.rectangle([100, 800, 980, 1120], fill=(20, 20, 20, 220))
        draw.text((140, 860), title, fill=(255, 255, 255))
        draw.text((140, 960), subtitle, fill=(240, 240, 240))
        draw.text((140, 1040), f"IMAGE {i+1}/3 - 1080x1920", fill=(180, 180, 180))

        img.save(p, "PNG")
        img_paths.append(p)

    # 2. Generate 12-second 44.1kHz Stereo WAV Audio
    audio_path = os.path.join(output_dir, "beta_soundtrack.wav")
    sample_rate = 44100
    duration_s = 12.0
    num_samples = int(sample_rate * duration_s)

    with wave.open(audio_path, "wb") as wf:
        wf.setnchannels(2)
        wf.setsampwidth(2)
        wf.setframerate(sample_rate)

        frames = bytearray()
        for s in range(num_samples):
            t = float(s) / sample_rate
            # 440Hz harmonic chord with gentle fade-out
            fade = 1.0 - max(0.0, (t - 10.0) / 2.0)
            val_l = int(12000 * math.sin(2 * math.pi * 440 * t) * fade)
            val_r = int(12000 * math.sin(2 * math.pi * 554.37 * t) * fade)
            frames.extend(struct.pack("<hh", val_l, val_r))

        wf.writeframes(frames)

    # 3. Generate 3-Scene SRT
    srt_path = os.path.join(output_dir, "beta_subtitles.srt")
    srt_content = """1
00:00:00,000 --> 00:00:04,000
2TOOLNE AutoEdit V2 - Khoi dong nen tang Beta cho CapCut Desktop.

2
00:00:04,000 --> 00:00:08,000
Dong bo timing SRT tu dong, chuyen dong native keyframe muot ma.

3
00:00:08,000 --> 00:00:12,000
Khong can AI - Hoan toan xac dinh, an toan tuyet doi cho du an.
"""
    with open(srt_path, "w", encoding="utf-8") as f:
        f.write(srt_content)

    return img_paths, audio_path, srt_path


def main():
    print("==================================================")
    print("2TOOLNE CAPCUT V2 - REAL MAC VALIDATION")
    print("==================================================")

    # 1. Hardware & Environment Check
    detector = CapCutDetector()
    status = detector.detect()
    print(f"Platform Detection: {status.status}")
    print(f"Detected CapCut Version: {status.detected_version}")
    print(f"CapCut App Path: {status.app_path}")
    print(f"Draft Storage: {status.draft_root_path}")
    assert status.status == STATUS_SUPPORTED, f"CapCut not supported on this machine: {status.diagnostic_message}"
    assert status.draft_root_path and os.path.isdir(status.draft_root_path), "Draft storage missing"

    # 2. Prepare Test Media
    tmp_media_dir = os.path.abspath("tests/scratch/beta_real_media")
    images, audio, srt = generate_test_media(tmp_media_dir)
    print(f"Generated {len(images)} images, audio, and SRT subtitles.")

    # 3. Build Timeline with Preset
    preset_mgr = PresetManager()
    preset = preset_mgr.get_preset("basic_slideshow")
    print(f"Using Preset: '{preset.name}' ({preset.canvas_ratio} @ {preset.fps} FPS)")

    builder = TimelineBuilder(preset)
    plan = builder.build(
        images=images,
        audio_path=audio,
        srt_source=srt,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        project_name="2TOOLNE Beta Foundation Real Mac Test",
    )
    print(f"EditPlan generated: {len(plan.clips)} clips, {len(plan.captions)} captions, duration={plan.project.duration_us / 1e6:.1f}s")

    # Strict Validation Gate
    plan_errors = plan.validate(check_files_exist=True)
    assert not plan_errors, f"EditPlan failed validation: {plan_errors}"
    print("EditPlan passed strict pre-generation validation gate.")

    # 4. Transactional Project Creation & Installation
    pm = CapCutProjectManager()
    result = pm.create_project(
        edit_plan=plan,
        project_name="2TOOLNE Beta Foundation Real Mac Test",
        auto_install=True,
    )

    assert result["status"] == STATUS_READY
    assert result["is_registered_in_capcut"] is True
    installed_dir = result["final_draft_dir"]
    print(f"Project installed successfully: {installed_dir}")

    # 5. Validate Installed Draft
    val_errors = CapCutDraftValidator.validate_draft(installed_dir)
    assert not val_errors, f"Installed draft validation failed: {val_errors}"
    print(f"Installed CapCut draft validated cleanly (0 errors).")

    # 6. Verify root_meta_info.json
    root_meta_path = os.path.join(status.draft_root_path, "root_meta_info.json")
    with open(root_meta_path, "r", encoding="utf-8") as f:
        root_data = json.load(f)

    registered_draft = next(
        (d for d in root_data.get("all_draft_store", []) if d.get("draft_id") == result["draft_id"]),
        None,
    )
    assert registered_draft is not None, "Draft not found in root_meta_info.json!"
    print(f"root_meta_info.json entry verified:")
    print(f"  - draft_id: {registered_draft.get('draft_id')}")
    print(f"  - draft_name: {registered_draft.get('draft_name')}")
    print(f"  - draft_fold_path: {registered_draft.get('draft_fold_path')}")
    print(f"  - tm_duration: {registered_draft.get('tm_duration')} us")

    print("==================================================")
    print("REAL MAC VALIDATION: SUCCESS")
    print("==================================================")
    return result


if __name__ == "__main__":
    main()
