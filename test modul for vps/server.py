"""
High-Throughput VPS Server for SRT Forced Alignment & AutoSub
Tuned for aaPanel (Baota Panel) Python Project Manager & Nginx Reverse Proxy.
"""

import os
import sys
import gc
import time
import asyncio
import tempfile
import uuid
import psutil
from typing import Optional, Dict, Any
from contextlib import asynccontextmanager

from fastapi import FastAPI, UploadFile, File, Form, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
import uvicorn

import engine_optimized

# TUNABLE RESOURCE LIMITS (Optimized for 15 concurrent users)
MAX_CONCURRENT_INFERENCES = int(os.environ.get("MAX_CONCURRENT_INFERENCES", 2))
CPU_THREADS_PER_WORKER = int(os.environ.get("CPU_THREADS_PER_WORKER", 2))
PORT = int(os.environ.get("PORT", 8000))

INFERENCE_SEMAPHORE = None

SERVER_STATS = {
    "total_received": 0,
    "total_completed": 0,
    "total_failed": 0,
    "current_queue_depth": 0,
    "active_inferences": 0
}


@asynccontextmanager
async def lifespan(app: FastAPI):
    global INFERENCE_SEMAPHORE
    INFERENCE_SEMAPHORE = asyncio.Semaphore(MAX_CONCURRENT_INFERENCES)
    print("=" * 65)
    print(f"🚀 [aaPanel SRT Microservice] Initializing on Port {PORT}")
    print(f"⚙️  Max Concurrent CPU Inferences: {MAX_CONCURRENT_INFERENCES}")
    print(f"🧵 CPU Threads Per Model: {CPU_THREADS_PER_WORKER}")
    print(f"💾 Pre-warming Faster-Whisper int8 Model in RAM...")
    engine_optimized.get_whisper_model(model_size="base", cpu_threads=CPU_THREADS_PER_WORKER)
    print(f"✅ Ready to accept connections from aaPanel Nginx / Clients!")
    print("=" * 65)
    yield
    print("🛑 aaPanel SRT Microservice Stopped.")


app = FastAPI(
    title="aaPanel SRT Forced Alignment Microservice (15-User Scaled)",
    lifespan=lifespan
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


def _cleanup(path: str):
    try:
        if path and os.path.isfile(path):
            os.remove(path)
    except Exception:
        pass


@app.get("/")
def root():
    return {
        "service": "aaPanel SRT Forced Alignment Microservice",
        "status": "online",
        "version": "1.0.0",
        "panel": "aaPanel / Baota Linux",
        "endpoints": {
            "align": "POST /api/v1/align",
            "health": "GET /health",
            "metrics": "GET /metrics"
        }
    }


@app.get("/health")
def health():
    vm = psutil.virtual_memory()
    return {
        "status": "healthy",
        "cpu_usage_percent": psutil.cpu_percent(interval=None),
        "ram_used_mb": round((vm.total - vm.available) / (1024 * 1024), 1),
        "ram_total_mb": round(vm.total / (1024 * 1024), 1),
        "active_inferences": SERVER_STATS["active_inferences"],
        "queued_requests": SERVER_STATS["current_queue_depth"]
    }


@app.get("/metrics")
def metrics():
    return {
        "stats": SERVER_STATS,
        "max_concurrent_workers": MAX_CONCURRENT_INFERENCES
    }


@app.post("/api/v1/align")
async def align_endpoint(
    background_tasks: BackgroundTasks,
    audio: UploadFile = File(...),
    script_text: Optional[str] = Form(None),
    script_file: Optional[UploadFile] = File(None),
    language: str = Form("auto")
):
    """
    Two-Pass Forced Alignment Endpoint with Async Semaphore Guardrail.
    """
    SERVER_STATS["total_received"] += 1
    SERVER_STATS["current_queue_depth"] += 1
    t_start = time.time()

    # 1. Resolve script text
    resolved_script = ""
    if script_text and script_text.strip():
        resolved_script = script_text.strip()
    elif script_file:
        raw_b = await script_file.read()
        resolved_script = raw_b.decode('utf-8', errors='ignore').strip()

    if not resolved_script:
        SERVER_STATS["current_queue_depth"] -= 1
        SERVER_STATS["total_failed"] += 1
        raise HTTPException(status_code=400, detail="Vui lòng cung cấp kịch bản gốc.")

    # 2. Save uploaded audio
    temp_dir = tempfile.mkdtemp(prefix="vps_upload_")
    ext = os.path.splitext(audio.filename or "input.wav")[1] or ".wav"
    audio_path = os.path.join(temp_dir, f"audio_{uuid.uuid4().hex[:8]}{ext}")

    try:
        with open(audio_path, "wb") as f_out:
            f_out.write(await audio.read())
    except Exception as e:
        SERVER_STATS["current_queue_depth"] -= 1
        SERVER_STATS["total_failed"] += 1
        raise HTTPException(status_code=500, detail=f"Lỗi ghi file audio: {e}")

    # 3. Enter Queued Semaphore (Concurrency control)
    try:
        async with INFERENCE_SEMAPHORE:
            SERVER_STATS["current_queue_depth"] -= 1
            SERVER_STATS["active_inferences"] += 1
            t_infer_start = time.time()

            loop = asyncio.get_event_loop()
            res = await loop.run_in_executor(
                None,
                engine_optimized.align_audio_to_script,
                audio_path,
                resolved_script,
                language,
                "base",
                CPU_THREADS_PER_WORKER
            )

            SERVER_STATS["active_inferences"] -= 1

            if not res.get("success"):
                SERVER_STATS["total_failed"] += 1
                raise HTTPException(status_code=500, detail=res.get("error", "Lỗi alignment."))

            SERVER_STATS["total_completed"] += 1
            t_end = time.time()

            return JSONResponse(content={
                "success": True,
                "count": res.get("count", 0),
                "audio_duration_sec": res.get("audio_duration_sec", 0.0),
                "queue_wait_sec": round(t_infer_start - t_start, 3),
                "compute_time_sec": round(t_end - t_infer_start, 3),
                "total_latency_sec": round(t_end - t_start, 3),
                "srt": res.get("srt", ""),
                "segments": res.get("segments", [])
            })

    except HTTPException:
        raise
    except Exception as e:
        SERVER_STATS["active_inferences"] = max(0, SERVER_STATS["active_inferences"] - 1)
        SERVER_STATS["total_failed"] += 1
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        background_tasks.add_task(_cleanup, audio_path)


if __name__ == "__main__":
    uvicorn.run("server:app", host="0.0.0.0", port=PORT, workers=1, reload=False)
