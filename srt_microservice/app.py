"""
FastAPI Standalone Microservice for Subtitles & Forced Alignment
Optimized for Linux VPS High-Concurrency Deployment (Docker / Bare Metal)
"""

import os
import time
import tempfile
import uuid
import psutil
from typing import Optional, List, Dict, Any
from fastapi import FastAPI, UploadFile, File, Form, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse, PlainTextResponse

import forced_alignment_engine
import subtitles_engine

app = FastAPI(
    title="SRT Forced Alignment & AutoSub Microservice",
    description="High-performance, standalone AI microservice for millisecond-accurate subtitle alignment & transcription.",
    version="1.0.0"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# In-memory metrics tracking
METRICS = {
    "total_requests": 0,
    "successful_alignments": 0,
    "failed_alignments": 0,
    "total_audio_seconds_processed": 0.0,
    "total_processing_seconds": 0.0
}


def _cleanup_file(path: str):
    """Safely remove a temporary file."""
    try:
        if path and os.path.isfile(path):
            os.remove(path)
    except Exception:
        pass


@app.get("/")
def root():
    return {
        "service": "SRT Forced Alignment Microservice",
        "status": "online",
        "version": "1.0.0",
        "endpoints": {
            "align": "POST /api/v1/align",
            "autosub": "POST /api/v1/autosub",
            "health": "GET /health",
            "metrics": "GET /metrics"
        }
    }


@app.get("/health")
def health_check():
    """System resource monitoring & healthcheck."""
    vm = psutil.virtual_memory()
    return {
        "status": "healthy",
        "cpu_usage_percent": psutil.cpu_percent(interval=None),
        "ram_usage_percent": vm.percent,
        "ram_used_mb": round((vm.total - vm.available) / (1024 * 1024), 1),
        "ram_total_mb": round(vm.total / (1024 * 1024), 1),
        "active_processes": len(psutil.pids())
    }


@app.get("/metrics")
def get_metrics():
    """Returns throughput and performance statistics."""
    avg_rtf = 0.0
    if METRICS["total_processing_seconds"] > 0:
        avg_rtf = round(METRICS["total_audio_seconds_processed"] / METRICS["total_processing_seconds"], 2)

    return {
        "metrics": METRICS,
        "average_real_time_factor": f"{avg_rtf}x (Speedup)",
        "server_uptime_hours": round((time.time() - psutil.boot_time()) / 3600, 2)
    }


@app.post("/api/v1/align")
async def api_align(
    background_tasks: BackgroundTasks,
    audio: UploadFile = File(..., description="Audio file (WAV, MP3, M4A, OGG)"),
    script_text: Optional[str] = Form(None, description="Ground truth text script"),
    script_file: Optional[UploadFile] = File(None, description="Or upload ground truth .txt file"),
    language: str = Form("auto", description="Language code: vi, ko, en, ja, or auto"),
    engine: str = Form("whisperx", description="Alignment engine: whisperx, acoustic_vad, or gemini"),
    gap_buffer: float = Form(0.02, description="Safety gap between cues in seconds (e.g. 0.02s = 20ms)")
):
    """
    Two-Pass Anchor Forced Alignment Endpoint:
    Aligns 100% ground-truth script lines to spoken audio timestamps.
    Guarantees monotonic ordering, 0 overlap, and strict non-overlapping gap buffer.
    """
    METRICS["total_requests"] += 1
    t0 = time.time()

    # 1. Resolve script text
    resolved_script = ""
    if script_text and script_text.strip():
        resolved_script = script_text.strip()
    elif script_file:
        file_bytes = await script_file.read()
        resolved_script = file_bytes.decode('utf-8', errors='ignore').strip()

    if not resolved_script:
        METRICS["failed_alignments"] += 1
        raise HTTPException(status_code=400, detail="Vui lòng cung cấp kịch bản (script_text hoặc script_file).")

    # 2. Save uploaded audio to temporary file
    temp_dir = tempfile.mkdtemp(prefix="srt_align_")
    ext = os.path.splitext(audio.filename or "input.wav")[1] or ".wav"
    audio_temp_path = os.path.join(temp_dir, f"audio_{uuid.uuid4().hex[:8]}{ext}")

    try:
        with open(audio_temp_path, "wb") as f_out:
            content = await audio.read()
            f_out.write(content)

        # 3. Execute Forced Alignment
        align_res = forced_alignment_engine.forced_align(
            audio_path=audio_temp_path,
            script_text=resolved_script,
            engine=engine,
            language=language
        )

        t1 = time.time()
        proc_time = round(t1 - t0, 3)

        if not align_res.get("success"):
            METRICS["failed_alignments"] += 1
            raise HTTPException(status_code=500, detail=align_res.get("error", "Lỗi trong quá trình xử lý alignment."))

        # 4. Calculate performance & benchmark stats
        segments = align_res.get("segments", [])
        total_audio_dur = round(segments[-1]["end"], 3) if segments else 0.0
        rtf = round(total_audio_dur / max(proc_time, 0.001), 2)

        METRICS["successful_alignments"] += 1
        METRICS["total_audio_seconds_processed"] += total_audio_dur
        METRICS["total_processing_seconds"] += proc_time

        # Schedule temp cleanup
        background_tasks.add_task(_cleanup_file, audio_temp_path)

        return JSONResponse(content={
            "success": True,
            "count": len(segments),
            "audio_duration_sec": total_audio_dur,
            "processing_time_sec": proc_time,
            "real_time_factor": f"{rtf}x real-time",
            "srt": align_res.get("srt", ""),
            "segments": segments,
            "server_benchmark": {
                "cpu_percent": psutil.cpu_percent(interval=None),
                "ram_used_mb": round(psutil.virtual_memory().used / (1024 * 1024), 1)
            }
        })

    except HTTPException:
        raise
    except Exception as e:
        METRICS["failed_alignments"] += 1
        raise HTTPException(status_code=500, detail=f"Lỗi hệ thống: {str(e)}")
    finally:
        background_tasks.add_task(_cleanup_file, audio_temp_path)


@app.post("/api/v1/autosub")
async def api_autosub(
    background_tasks: BackgroundTasks,
    audio: UploadFile = File(..., description="Audio file"),
    language: str = Form("auto"),
    engine: str = Form("faster-whisper", description="Engine: faster-whisper, gemini, or stable-ts")
):
    """
    Speech-To-Text Transcription Endpoint (when no script is available).
    """
    METRICS["total_requests"] += 1
    t0 = time.time()

    temp_dir = tempfile.mkdtemp(prefix="srt_autosub_")
    ext = os.path.splitext(audio.filename or "input.wav")[1] or ".wav"
    audio_temp_path = os.path.join(temp_dir, f"audio_{uuid.uuid4().hex[:8]}{ext}")

    try:
        with open(audio_temp_path, "wb") as f_out:
            content = await audio.read()
            f_out.write(content)

        # Call transcription engine
        srt_text, raw_segments = subtitles_engine.generate_subtitles(
            audio_path=audio_temp_path,
            language=language,
            model_size="base"
        )

        t1 = time.time()
        proc_time = round(t1 - t0, 3)

        return JSONResponse(content={
            "success": True,
            "processing_time_sec": proc_time,
            "srt": srt_text,
            "segments": raw_segments
        })
    finally:
        background_tasks.add_task(_cleanup_file, audio_temp_path)
