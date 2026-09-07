"""
tests/test_capcut_render_queue.py
Unit tests for RenderJob, RenderQueueManager (Phase 5E.2 5-Job FSM, persistence, crash recovery).
"""
import os
import sys
import time
import shutil
import tempfile
import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.insert(0, V2_DIR)

from adapters.capcut.render_job import (
    RenderJob,
    STATE_QUEUED,
    STATE_DONE,
    STATE_FAILED,
    STATE_PAUSED,
    STATE_CANCELLED,
    STATE_SKIPPED,
)
from adapters.capcut.render_queue_manager import (
    RenderQueueManager,
    QUEUE_STATUS_IDLE,
    QUEUE_STATUS_RUNNING,
    QUEUE_STATUS_PAUSED,
)
from adapters.capcut.native_exporter import MockAutomationDriver


def test_render_job_transitions():
    job = RenderJob(
        project_id="proj_1",
        draft_id="draft_1",
        draft_path="/path/to/draft",
        output_path="/path/to/out.mp4",
        output_filename="out.mp4",
        render_profile_id="windows_capcut_9_3_0_3970",
    )
    assert job.status == STATE_QUEUED
    assert job.started_at is None

    job.transition_to("STARTING_CAPCUT")
    assert job.started_at is not None

    job.transition_to(STATE_DONE)
    assert job.finished_at is not None

    d = job.to_dict()
    job_restored = RenderJob.from_dict(d)
    assert job_restored.job_id == job.job_id
    assert job_restored.status == STATE_DONE


def test_render_queue_controls_and_persistence():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence_file = os.path.join(tmp_dir, "render_queue_state.json")
        driver = MockAutomationDriver()

        qm = RenderQueueManager(persistence_path=persistence_file, driver=driver)
        qm.pause()  # Start in paused state to inspect controls

        # Enqueue 5 sequential jobs (Section 39 requirement)
        job_ids = []
        for i in range(5):
            job = RenderJob(
                project_id=f"proj_{i+1}",
                draft_id=f"draft_{i+1}",
                draft_path=os.path.join(tmp_dir, f"draft_{i+1}"),
                output_path=os.path.join(tmp_dir, f"out_{i+1}.mp4"),
                output_filename=f"out_{i+1}.mp4",
                render_profile_id="windows_capcut_9_3_0_3970",
            )
            os.makedirs(job.draft_path, exist_ok=True)
            jid = qm.enqueue(job)
            job_ids.append(jid)

        state = qm.get_state()
        assert state["total_jobs"] == 5

        # Verify atomic snapshot persistence
        assert os.path.isfile(persistence_file)

        # Test Skip
        assert qm.skip_job(job_ids[4]) is True
        assert qm.get_state()["jobs"][4]["status"] == STATE_SKIPPED

        # Test Cancel
        assert qm.cancel_job(job_ids[3]) is True
        assert qm.get_state()["jobs"][3]["status"] == STATE_CANCELLED

        # Test Retry
        assert qm.retry_job(job_ids[3]) is True
        assert qm.get_state()["jobs"][3]["status"] == STATE_QUEUED
        assert qm.get_state()["jobs"][3]["retry_count"] == 1

        # Test Stop After Current
        qm.stop_after_current()
        assert qm.get_state()["queue_status"] in ("STOPPING_AFTER_CURRENT", "PAUSED")

        # Test Clear Completed
        qm.get_state()["jobs"][0]["status"] = STATE_DONE
        cleared = qm.clear_completed()
        assert cleared >= 1


def test_crash_recovery():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence_file = os.path.join(tmp_dir, "render_queue_state.json")
        out_file = os.path.join(tmp_dir, "finished.mp4")

        # Create a mock persistence file simulating an in-flight job during a crash
        sample_mp4 = "/Users/2tamne/tool ffmpeg/test_out.mp4"
        if os.path.isfile(sample_mp4):
            shutil.copy(sample_mp4, out_file)
        else:
            with open(out_file, "wb") as f:
                f.write(b"MOCK_DATA")

        raw_state = {
            "version": "1.0",
            "updated_at": time.time(),
            "queue_status": "RUNNING",
            "jobs": [
                {
                    "job_id": "crashed_job_1",
                    "project_id": "proj_crashed",
                    "draft_id": "draft_crashed",
                    "draft_path": tmp_dir,
                    "output_path": out_file,
                    "output_filename": "finished.mp4",
                    "render_profile_id": "windows_capcut_9_3_0_3970",
                    "render_settings": {},
                    "status": "RENDERING",  # mid-flight!
                    "created_at": "2026-09-07T00:00:00Z",
                    "started_at": "2026-09-07T00:01:00Z",
                    "finished_at": None,
                    "retry_count": 0,
                    "last_error": None,
                },
                {
                    "job_id": "crashed_job_2",
                    "project_id": "proj_crashed_2",
                    "draft_id": "draft_crashed_2",
                    "draft_path": tmp_dir,
                    "output_path": os.path.join(tmp_dir, "never_created.mp4"),
                    "output_filename": "never_created.mp4",
                    "render_profile_id": "windows_capcut_9_3_0_3970",
                    "render_settings": {},
                    "status": "STARTING_EXPORT",  # mid-flight before file creation
                    "created_at": "2026-09-07T00:00:00Z",
                    "started_at": "2026-09-07T00:01:00Z",
                    "finished_at": None,
                    "retry_count": 0,
                    "last_error": None,
                }
            ],
        }

        import json
        with open(persistence_file, "w") as f:
            json.dump(raw_state, f)

        # Boot up a new RenderQueueManager - should trigger recover_from_crash()
        driver = MockAutomationDriver()
        qm = RenderQueueManager(persistence_path=persistence_file, driver=driver)

        st = qm.get_state()
        job1 = st["jobs"][0]
        job2 = st["jobs"][1]

        # If real mp4 was copied, OutputVerifier marks it DONE
        if os.path.isfile(sample_mp4):
            assert job1["status"] == STATE_DONE
        else:
            assert job1["status"] == STATE_FAILED

        # Job 2 output file was never created -> CRASH_INTERRUPTED
        assert job2["status"] == STATE_FAILED
        assert job2["last_error"]["error_code"] == "CRASH_INTERRUPTED"
