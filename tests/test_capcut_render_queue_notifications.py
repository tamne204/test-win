"""
tests/test_capcut_render_queue_notifications.py
Verifies that RenderQueueManager listeners and Bridge notifications fire on state transitions.
Ensures GAP-02 and GAP-03 architectural requirements.
"""
import os
import sys
import tempfile
import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.insert(0, V2_DIR)

from adapters.capcut.render_job import (
    RenderJob,
    STATE_QUEUED,
    STATE_CANCELLED,
    STATE_PAUSED,
)
from adapters.capcut.render_queue_manager import (
    RenderQueueManager,
    QUEUE_STATUS_PAUSED,
    QUEUE_STATUS_RUNNING,
    QUEUE_STATUS_IDLE,
)
from desktop_bridge.bridge import DesktopBridge


def test_render_queue_manager_listener_called_on_enqueue_and_pause():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmpdir:
        persistence_path = os.path.join(tmpdir, "render_queue_state.json")
        manager = RenderQueueManager(persistence_path=persistence_path)
        manager.pause()

        received_states = []

        def on_update(state):
            received_states.append(state)

        manager.add_listener(on_update)

        job = RenderJob(
            project_id="proj_test",
            draft_id="draft_test",
            draft_path=tmpdir,
            output_path=os.path.join(tmpdir, "out.mp4"),
            output_filename="out.mp4",
            render_profile_id="windows_capcut_9_3_0_3970",
        )

        job_id = manager.enqueue(job)
        assert job_id is not None
        assert len(received_states) >= 1
        assert received_states[-1]["total_jobs"] == 1

        manager.resume()
        assert received_states[-1]["queue_status"] == QUEUE_STATUS_IDLE or received_states[-1]["queue_status"] == QUEUE_STATUS_RUNNING

        manager.pause()
        assert received_states[-1]["queue_status"] == QUEUE_STATUS_PAUSED

        manager.cancel_job(job_id)
        assert received_states[-1]["jobs"][0]["status"] == STATE_CANCELLED

        manager.clear_completed()
        assert received_states[-1]["total_jobs"] == 0


def test_bridge_emits_render_queue_update_notification():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmpdir:
        persistence_path = os.path.join(tmpdir, "render_queue_state.json")
        manager = RenderQueueManager(persistence_path=persistence_path)
        manager.pause()

        notifications = []

        def notification_cb(msg):
            notifications.append(msg)

        bridge = DesktopBridge(
            workspace_root=os.path.join(tmpdir, "ws"),
            user_presets_dir=os.path.join(tmpdir, "presets"),
            notification_callback=notification_cb,
            render_queue_manager=manager,
        )

        job = RenderJob(
            project_id="proj_bridge_test",
            draft_id="draft_bridge_test",
            draft_path=tmpdir,
            output_path=os.path.join(tmpdir, "out.mp4"),
            output_filename="out.mp4",
            render_profile_id="windows_capcut_9_3_0_3970",
        )

        manager.enqueue(job)

        # Check notifications
        queue_notifs = [n for n in notifications if n.get("event") == "render_queue_update"]
        assert len(queue_notifs) >= 1
        last_data = queue_notifs[-1]["data"]
        assert last_data["total_jobs"] == 1
        assert last_data["jobs"][0]["project_id"] == "proj_bridge_test"

        manager.pause()
        manager.cancel_job(job.job_id)
