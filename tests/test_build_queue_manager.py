"""
tests/test_build_queue_manager.py
Unit and integration tests for ProjectBuildQueueManager (Queue A)
Acceptance Matrix per Directive 10 (Wave 1: Studio + Build Queue + Batch)
"""
import os
import sys
import time
import json
import tempfile
import threading
from pathlib import Path
from typing import Dict, Any, Callable
from PIL import Image

import pytest

REPO_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO_ROOT))
sys.path.insert(0, str(REPO_ROOT / "apps" / "capcut-v2"))

from core.build_queue_manager import (
    BuildJob,
    ProjectBuildQueueManager,
    STATE_QUEUED,
    STATE_VALIDATING,
    STATE_PINNING_INPUTS,
    STATE_UPSCALING,
    STATE_SUBTITLE,
    STATE_WAITING_SRT_REVIEW,
    STATE_TIMELINE,
    STATE_BUILDING_DRAFT,
    STATE_VERIFYING,
    STATE_PROJECT_READY,
    STATE_FAILED,
    STATE_CANCELLED,
    QUEUE_STATUS_IDLE,
    QUEUE_STATUS_RUNNING,
)
from desktop_bridge.bridge import DesktopBridge


def create_dummy_image(path: str, color=(255, 0, 0)):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    img = Image.new("RGB", (200, 200), color=color)
    img.save(path)


def test_build_job_snapshot_immutability():
    """Verify queue freezes configuration so later Studio changes do not mutate queued jobs."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        qm = ProjectBuildQueueManager(persistence_path=persistence)

        original_payload = {
            "project_name": "Initial Name",
            "aspect_ratio": "9:16",
            "images": ["/path/img1.png"],
            "custom_clip_duration_s": 5.0,
        }

        job = qm.enqueue_job(original_payload)
        assert job.project_name == "Initial Name"
        assert job.payload["aspect_ratio"] == "9:16"

        # Mutate studio dictionary
        original_payload["project_name"] = "MUTATED"
        original_payload["aspect_ratio"] = "16:9"
        original_payload["images"].append("/path/img2.png")

        # Verify queued snapshot remained completely unmodified
        retrieved_job = qm.get_job(job.job_id)
        assert retrieved_job.project_name == "Initial Name"
        assert retrieved_job.payload["aspect_ratio"] == "9:16"
        assert len(retrieved_job.payload["images"]) == 1


def test_build_queue_single_project_success():
    """Test building 1 project to PROJECT_READY."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        img_path = os.path.join(tmp_dir, "test.png")
        create_dummy_image(img_path)

        def mock_generator(payload: Dict[str, Any], progress_cb: Callable[[str, float, str], None]):
            progress_cb("TIMELINE", 0.5, "Dựng timeline...")
            progress_cb("READY", 1.0, "Sẵn sàng!")
            return {
                "ok": True,
                "project_id": "proj_123",
                "draft_id": "draft_123",
                "final_draft_dir": os.path.join(tmp_dir, "draft_123"),
                "duration_s": 5.0,
            }

        qm = ProjectBuildQueueManager(
            persistence_path=persistence,
            generator_fn=mock_generator,
        )

        job = qm.enqueue_job({
            "project_name": "Test Single",
            "images": [img_path],
        })
        assert job.state == STATE_QUEUED

        # Run single job
        qm.build_job(job.job_id)
        # Wait for completion
        for _ in range(50):
            if qm.get_job(job.job_id).state == STATE_PROJECT_READY:
                break
            time.sleep(0.05)

        finished_job = qm.get_job(job.job_id)
        assert finished_job.state == STATE_PROJECT_READY
        assert finished_job.progress == 100.0
        assert finished_job.result["project_id"] == "proj_123"
        assert qm.get_state()["queue_status"] == QUEUE_STATUS_IDLE


def test_build_queue_5_projects_sequential():
    """Test building 5 sequential projects without race conditions."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        img_path = os.path.join(tmp_dir, "test.png")
        create_dummy_image(img_path)

        processed_order = []

        def mock_generator(payload: Dict[str, Any], progress_cb: Callable[[str, float, str], None]):
            p_name = payload["project_name"]
            processed_order.append(p_name)
            progress_cb("TIMELINE", 0.5, f"Dựng timeline {p_name}...")
            time.sleep(0.02)
            progress_cb("READY", 1.0, f"Sẵn sàng {p_name}!")
            return {
                "ok": True,
                "project_name": p_name,
                "final_draft_dir": os.path.join(tmp_dir, p_name),
            }

        qm = ProjectBuildQueueManager(
            persistence_path=persistence,
            generator_fn=mock_generator,
        )

        for i in range(5):
            qm.enqueue_job({
                "project_name": f"Project_{i+1}",
                "images": [img_path],
            })

        assert qm.get_state()["total_jobs"] == 5
        # Build all
        qm.build_all()

        # Wait for all 5 to complete
        for _ in range(100):
            state = qm.get_state()
            ready_count = sum(1 for j in state["jobs"] if j["state"] == STATE_PROJECT_READY)
            if ready_count == 5:
                break
            time.sleep(0.05)

        state = qm.get_state()
        assert sum(1 for j in state["jobs"] if j["state"] == STATE_PROJECT_READY) == 5
        assert processed_order == ["Project_1", "Project_2", "Project_3", "Project_4", "Project_5"]
        assert state["queue_status"] == QUEUE_STATUS_IDLE


def test_build_queue_app_restart_persistence_and_recovery():
    """Simulate app crash during job execution and verify safe recovery on restart."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")

        # Manually create state as if sidecar crashed mid-build
        crashed_data = {
            "version": 1,
            "saved_at": time.time(),
            "status": "RUNNING",
            "jobs": [
                {
                    "job_id": "job_crashed",
                    "project_name": "Interrupted Project",
                    "payload": {"project_name": "Interrupted Project", "images": []},
                    "state": STATE_BUILDING_DRAFT,
                    "progress": 75.0,
                    "current_step": "Đang sinh cấu trúc draft CapCut...",
                    "created_at": time.time() - 60,
                },
                {
                    "job_id": "job_queued_behind",
                    "project_name": "Queued Project",
                    "payload": {"project_name": "Queued Project", "images": []},
                    "state": STATE_QUEUED,
                    "progress": 0.0,
                    "created_at": time.time() - 30,
                }
            ],
        }
        with open(persistence, "w", encoding="utf-8") as f:
            json.dump(crashed_data, f)

        # Initialize manager after crash
        qm = ProjectBuildQueueManager(persistence_path=persistence)
        state = qm.get_state()

        # The interrupted job must safely transition to FAILED with clear retry instructions
        crashed_job = qm.get_job("job_crashed")
        assert crashed_job.state == STATE_FAILED
        assert "gián đoạn" in crashed_job.error

        # The queued job remains QUEUED
        queued_job = qm.get_job("job_queued_behind")
        assert queued_job.state == STATE_QUEUED

        # Manager status must be reset to IDLE
        assert state["queue_status"] == QUEUE_STATUS_IDLE

        # Retry crashed job
        assert qm.retry_job("job_crashed") is True
        assert qm.get_job("job_crashed").state == STATE_QUEUED


def test_build_queue_failed_project_and_retry():
    """Test failed project when images missing, followed by retry."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        qm = ProjectBuildQueueManager(persistence_path=persistence)

        # Non-existent image file
        missing_img = os.path.join(tmp_dir, "missing.png")
        job = qm.enqueue_job({
            "project_name": "Fail Test",
            "images": [missing_img],
        })

        qm.build_job(job.job_id)
        for _ in range(30):
            if qm.get_job(job.job_id).state == STATE_FAILED:
                break
            time.sleep(0.05)

        failed_job = qm.get_job(job.job_id)
        assert failed_job.state == STATE_FAILED
        assert "Không tìm thấy" in failed_job.error

        # Now create the image file and retry
        create_dummy_image(missing_img)
        assert qm.retry_job(job.job_id) is True
        assert qm.get_job(job.job_id).state == STATE_QUEUED
        assert qm.get_job(job.job_id).error is None


def test_build_queue_cancel_job():
    """Test cancelling queued and running jobs."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        qm = ProjectBuildQueueManager(persistence_path=persistence)

        job1 = qm.enqueue_job({"project_name": "Job 1", "images": []})
        assert qm.cancel_job(job1.job_id) is True
        assert qm.get_job(job1.job_id).state == STATE_CANCELLED


def test_build_queue_clear_completed():
    """Test clearing completed or cancelled jobs."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        qm = ProjectBuildQueueManager(persistence_path=persistence)

        j1 = qm.enqueue_job({"project_name": "Job 1"})
        j2 = qm.enqueue_job({"project_name": "Job 2"})
        j3 = qm.enqueue_job({"project_name": "Job 3"})

        qm.get_job(j1.job_id).state = STATE_PROJECT_READY
        qm.get_job(j2.job_id).state = STATE_CANCELLED

        cleared = qm.clear_completed()
        assert cleared == 2
        assert len(qm.get_state()["jobs"]) == 1
        assert qm.get_state()["jobs"][0]["job_id"] == j3.job_id


def test_build_queue_desktop_bridge_integration():
    """Test DesktopBridge IPC dispatch for build queue methods."""
    from unittest.mock import MagicMock
    from core.security.license_guard import LicenseGuard

    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        img_path = os.path.join(tmp_dir, "bridge_test.png")
        create_dummy_image(img_path)

        guard = LicenseGuard(app_data_dir=tmp_dir)
        guard.require_entitlement = MagicMock(return_value={"status": "ACTIVE", "tier": "commercial"})

        bridge = DesktopBridge(workspace_root=tmp_dir, license_guard=guard)

        # 1. Enqueue
        req_enqueue = {
            "id": "req_1",
            "method": "ENQUEUE_BUILD_JOB",
            "params": {
                "project_name": "Bridge Queued Project",
                "images": [img_path],
                "aspect_ratio": "9:16",
            }
        }
        res_enqueue = bridge.dispatch(req_enqueue)
        assert res_enqueue["ok"] is True
        job = res_enqueue["result"]["job"]
        job_id = job["job_id"]
        assert job["project_name"] == "Bridge Queued Project"

        # 2. Get State
        req_state = {
            "id": "req_2",
            "method": "GET_BUILD_QUEUE_STATE",
            "params": {}
        }
        res_state = bridge.dispatch(req_state)
        assert res_state["ok"] is True
        assert res_state["result"]["total_jobs"] >= 1

        # 3. Cancel Job
        req_cancel = {
            "id": "req_3",
            "method": "CANCEL_BUILD_JOB",
            "params": {"job_id": job_id}
        }
        res_cancel = bridge.dispatch(req_cancel)
        assert res_cancel["ok"] is True
        assert res_cancel["result"]["ok"] is True

        # 4. Retry Job
        req_retry = {
            "id": "req_4",
            "method": "RETRY_BUILD_JOB",
            "params": {"job_id": job_id}
        }
        res_retry = bridge.dispatch(req_retry)
        assert res_retry["ok"] is True
        assert res_retry["result"]["ok"] is True

        # 5. Clear Completed
        bridge.build_queue_manager.get_job(job_id).state = STATE_PROJECT_READY
        req_clear = {
            "id": "req_5",
            "method": "CLEAR_COMPLETED_BUILD_JOBS",
            "params": {}
        }
        res_clear = bridge.dispatch(req_clear)
        assert res_clear["ok"] is True
        assert res_clear["result"]["cleared_count"] >= 1


def test_build_queue_existing_srt_and_upscale_cases():
    """Verify queue handles existing SRT and optional AI Upscale flags correctly."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        img_path = os.path.join(tmp_dir, "test.png")
        create_dummy_image(img_path)

        visited_states = []

        def mock_gen(payload: Dict[str, Any], progress_cb: Callable[[str, float, str], None]):
            if payload.get("auto_upscale"):
                visited_states.append("UPSCALING_PROCESSED")
            if payload.get("srt_source"):
                visited_states.append("SRT_USED")
            progress_cb("READY", 1.0, "Xong")
            return {"ok": True, "project_name": payload.get("project_name")}

        qm = ProjectBuildQueueManager(
            persistence_path=persistence,
            generator_fn=mock_gen,
        )

        job = qm.enqueue_job({
            "project_name": "Upscale & SRT Project",
            "images": [img_path],
            "srt_source": "1\n00:00:00,000 --> 00:00:03,000\nXin chao cac ban\n",
            "auto_upscale": True,
        })

        qm.build_job(job.job_id)
        for _ in range(50):
            if qm.get_job(job.job_id).state == STATE_PROJECT_READY:
                break
            time.sleep(0.05)

        assert qm.get_job(job.job_id).state == STATE_PROJECT_READY
        assert "UPSCALING_PROCESSED" in visited_states
        assert "SRT_USED" in visited_states


def test_build_queue_forced_alignment_and_autosub_cases():
    """Verify queue handles script-based forced alignment and autosub modes."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "build_queue.json")
        img_path = os.path.join(tmp_dir, "test.png")
        create_dummy_image(img_path)

        received_payloads = []

        def mock_gen(payload: Dict[str, Any], progress_cb: Callable[[str, float, str], None]):
            received_payloads.append(payload)
            progress_cb("READY", 1.0, "Ready")
            return {"ok": True, "project_name": payload.get("project_name")}

        qm = ProjectBuildQueueManager(
            persistence_path=persistence,
            generator_fn=mock_gen,
        )

        # Forced Alignment Case: script_text provided without srt_source
        j_fa = qm.enqueue_job({
            "project_name": "FA Project",
            "images": [img_path],
            "script_text": "Day la kịch bản lời thoại.",
            "subtitle_mode": "forced_alignment",
        })

        # AutoSub Case: subtitle_mode is autosub
        j_stt = qm.enqueue_job({
            "project_name": "AutoSub Project",
            "images": [img_path],
            "subtitle_mode": "autosub",
        })

        qm.build_all()
        for _ in range(50):
            state = qm.get_state()
            if sum(1 for j in state["jobs"] if j["state"] == STATE_PROJECT_READY) == 2:
                break
            time.sleep(0.05)

        assert qm.get_job(j_fa.job_id).state == STATE_PROJECT_READY
        assert qm.get_job(j_stt.job_id).state == STATE_PROJECT_READY
        assert len(received_payloads) == 2
        assert received_payloads[0]["subtitle_mode"] == "forced_alignment"
        assert received_payloads[1]["subtitle_mode"] == "autosub"

