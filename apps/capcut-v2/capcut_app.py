"""
apps/capcut-v2/capcut_app.py
DEVELOPMENT_POC_ONLY — Retired from production path.
Production uses Native Desktop Application (Electron Shell + Python Sidecar IPC).
This Flask application is retained ONLY for development/debugging tests.
"""
from __future__ import annotations

import os
import sys
import logging
import traceback
from typing import Dict, Any, List, Optional

# Production Architecture Flags
DEVELOPMENT_POC_ONLY = True
PRODUCTION_HTTP_SERVER = None
PRODUCTION_LOCALHOST = None
PRODUCTION_PORT = None

from flask import Flask, request, render_template, jsonify

try:
    from .capcut_version import get_version, get_app_name, get_product_id
    from .core.edit_plan import EditPlan
    from .core.timeline_builder import TimelineBuilder, TIMING_MODE_FIXED, TIMING_MODE_SRT_DRIVEN
    from .core.preset_manager import PresetManager, RulePreset, PRESET_BASIC_SLIDESHOW
    from .adapters.capcut.detector import CapCutDetector, STATUS_SUPPORTED, STATUS_UNTESTED
    from .adapters.capcut.project_manager import CapCutProjectManager
    from .adapters.capcut.launcher import CapCutLauncher
except (ImportError, ValueError):
    from capcut_version import get_version, get_app_name, get_product_id
    from core.edit_plan import EditPlan
    from core.timeline_builder import TimelineBuilder, TIMING_MODE_FIXED, TIMING_MODE_SRT_DRIVEN
    from core.preset_manager import PresetManager, RulePreset, PRESET_BASIC_SLIDESHOW
    from adapters.capcut.detector import CapCutDetector, STATUS_SUPPORTED, STATUS_UNTESTED
    from adapters.capcut.project_manager import CapCutProjectManager
    from adapters.capcut.launcher import CapCutLauncher

# ---------------------------------------------------------------------------
# Setup isolated logging
# ---------------------------------------------------------------------------
log_dir = os.path.abspath("logs")
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, "capcut-v2.log")

logger = logging.getLogger("capcut_v2")
logger.setLevel(logging.INFO)
if not logger.handlers:
    fh = logging.FileHandler(log_file, encoding="utf-8")
    fh.setFormatter(logging.Formatter("[%(asctime)s] [%(levelname)s] %(message)s"))
    logger.addHandler(fh)
    ch = logging.StreamHandler(sys.stdout)
    ch.setFormatter(logging.Formatter("⚡ [CapCut V2 Beta] %(message)s"))
    logger.addHandler(ch)

# ---------------------------------------------------------------------------
# Flask setup
# ---------------------------------------------------------------------------
template_dir = os.path.join(os.path.dirname(__file__), "templates")
app = Flask(__name__, template_folder=template_dir)
app.config["MAX_CONTENT_LENGTH"] = 500 * 1024 * 1024  # 500 MB max upload for V2

# Staging uploads directory
upload_dir = os.path.abspath("uploads_capcut")
os.makedirs(upload_dir, exist_ok=True)

preset_mgr = PresetManager()


@app.route("/", methods=["GET"])
def index():
    """Render Beta Foundation interface."""
    detector = CapCutDetector()
    status = detector.detect()
    presets = preset_mgr.list_presets()
    return render_template(
        "index.html",
        app_name=get_app_name(),
        version=get_version(),
        capcut_status=status.to_dict(),
        presets=[p.to_dict() for p in presets],
    )


@app.route("/api/status", methods=["GET"])
def get_system_status():
    """Return CapCut detector status and V2 identity."""
    detector = CapCutDetector()
    status = detector.detect()
    return jsonify({
        "ok": True,
        "product_id": get_product_id(),
        "app_name": get_app_name(),
        "version": get_version(),
        "capcut": status.to_dict(),
    })


@app.route("/api/presets", methods=["GET"])
def list_presets():
    """Return all available presets."""
    presets = preset_mgr.list_presets()
    return jsonify({
        "ok": True,
        "presets": [p.to_dict() for p in presets],
    })


@app.route("/api/presets/save", methods=["POST"])
def save_custom_preset():
    """Save user custom preset."""
    try:
        data = request.get_json(force=True)
        name = data.get("name", "Custom Preset").strip()
        scene_duration = float(data.get("scene_duration_s", 5.0))
        motion_sequence = data.get("motion_sequence", ["ZOOM_IN", "ZOOM_OUT"])
        canvas = data.get("canvas_ratio", "9:16")
        fps = float(data.get("fps", 60.0))
        caption_y = float(data.get("caption_position_y", -0.6))
        audio_volume = float(data.get("audio_volume", 1.0))

        preset = preset_mgr.save_custom_preset(
            name=name,
            scene_duration_s=scene_duration,
            motion_sequence=motion_sequence,
            canvas_ratio=canvas,
            fps=fps,
            caption_position_y=caption_y,
            audio_volume=audio_volume,
        )
        return jsonify({"ok": True, "preset": preset.to_dict()})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 400


@app.route("/api/project/generate", methods=["POST"])
def generate_project():
    """
    Build timeline and generate CapCut draft with transactional safety.
    Supports Fixed and SRT-Driven timing modes.
    """
    try:
        project_name = request.form.get("project_name", "AutoEdit Project").strip()
        preset_id = request.form.get("preset", "basic_slideshow")
        timing_mode = request.form.get("timing_mode", TIMING_MODE_FIXED).upper()
        caption_text = request.form.get("caption_text", "").strip()
        allow_untested = request.form.get("allow_untested", "false").lower() == "true"

        # Custom canvas and FPS overrides if supplied
        custom_canvas = request.form.get("canvas_ratio")
        custom_fps = request.form.get("fps")
        custom_duration = request.form.get("scene_duration_s")

        preset = preset_mgr.get_preset(preset_id)

        # Clone preset if UI overrides were specified
        if custom_canvas or custom_fps or custom_duration:
            preset_dict = preset.to_dict()
            if custom_canvas:
                preset_dict["canvas_ratio"] = custom_canvas
                if custom_canvas == "16:9":
                    preset_dict["width"], preset_dict["height"] = 1920, 1080
                elif custom_canvas == "1:1":
                    preset_dict["width"], preset_dict["height"] = 1080, 1080
                else:
                    preset_dict["width"], preset_dict["height"] = 1080, 1920
            if custom_fps:
                preset_dict["fps"] = float(custom_fps)
            if custom_duration:
                preset_dict["scene_duration_s"] = float(custom_duration)
            preset = RulePreset.from_dict(preset_dict)

        # Handle uploaded images or file paths
        images: List[str] = []
        uploaded_files = request.files.getlist("images")
        if uploaded_files and uploaded_files[0].filename:
            for f in uploaded_files:
                fn = f.filename
                save_path = os.path.join(upload_dir, fn)
                f.save(save_path)
                images.append(save_path)

        if not images:
            raw_paths = request.form.get("image_paths", "")
            if raw_paths:
                images = [p.strip() for p in raw_paths.split(",") if p.strip()]

        if not images:
            return jsonify({
                "ok": False,
                "error": "SOURCE_MEDIA_MISSING",
                "message": "Vui lòng chọn hoặc tải lên ít nhất 1 hình ảnh.",
            }), 400

        # Handle uploaded audio or path
        audio_path = None
        if "audio" in request.files and request.files["audio"].filename:
            af = request.files["audio"]
            audio_path = os.path.join(upload_dir, af.filename)
            af.save(audio_path)
        else:
            raw_audio = request.form.get("audio_path", "").strip()
            if raw_audio and os.path.isfile(raw_audio):
                audio_path = raw_audio

        # Handle SRT file if supplied
        srt_source = None
        if "srt_file" in request.files and request.files["srt_file"].filename:
            sf = request.files["srt_file"]
            srt_path = os.path.join(upload_dir, sf.filename)
            sf.save(srt_path)
            srt_source = srt_path
        else:
            raw_srt = request.form.get("srt_text", "").strip()
            if raw_srt:
                srt_source = raw_srt

        logger.info(
            f"Generating project '{project_name}' [Preset={preset.name}, Mode={timing_mode}, "
            f"Canvas={preset.canvas_ratio}, Images={len(images)}, Audio={bool(audio_path)}, SRT={bool(srt_source)}]"
        )

        # 1. TimelineBuilder creates EditPlan
        builder = TimelineBuilder(preset=preset)
        captions = [{"text": caption_text, "start_s": 0.0, "duration_s": 5.0}] if caption_text else None

        edit_plan = builder.build(
            images=images,
            audio_path=audio_path,
            srt_source=srt_source,
            captions=captions,
            project_name=project_name,
            timing_mode=timing_mode,
        )

        # 2. ProjectManager handles staging, draft generation, and atomic registration
        manager = CapCutProjectManager()
        result = manager.create_and_register_project(
            edit_plan=edit_plan,
            install_to_capcut=True,
            allow_untested=allow_untested,
        )

        logger.info(f"Successfully generated CapCut project: {result['draft_id']} -> {result['final_draft_dir']}")

        return jsonify({
            "ok": True,
            "result": result,
        })

    except Exception as exc:
        logger.error(f"Error during project generation: {traceback.format_exc()}")
        return jsonify({
            "ok": False,
            "error": "CAPCUT_DRAFT_GENERATION_FAILED",
            "message": str(exc),
        }), 500


@app.route("/api/project/open", methods=["POST"])
def open_capcut():
    """Launch CapCut Desktop application."""
    try:
        data = request.get_json(silent=True) or {}
        draft_path = data.get("draft_path") or request.form.get("draft_path")
        res = CapCutLauncher.launch(draft_path=draft_path)
        return jsonify(res)
    except Exception as exc:
        logger.error(f"Error launching CapCut: {exc}")
        return jsonify({
            "ok": False,
            "error": "CAPCUT_OPEN_FAILED",
            "message": str(exc),
        }), 500


def run():
    """Start CapCut V2 server on port 8088."""
    port = int(os.environ.get("CAPCUT_V2_PORT", 8088))
    logger.info(f"🚀 Starting {get_app_name()} v{get_version()} on port {port}...")
    app.run(host="127.0.0.1", port=port, debug=False)


if __name__ == "__main__":
    run()
