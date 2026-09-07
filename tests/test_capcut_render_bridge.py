"""
tests/test_capcut_render_bridge.py
Unit tests for DesktopBridge IPC dispatching of render methods.
"""
import os
import sys
import tempfile
import pytest

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.insert(0, V2_DIR)

from desktop_bridge.bridge import DesktopBridge
from desktop_bridge.protocol import PROTOCOL_VERSION
from core.security.license_guard import LicenseGuard
from unittest.mock import MagicMock


@pytest.fixture
def mock_bridge():
    with tempfile.TemporaryDirectory() as tmp_dir:
        # Create a mock active license guard so commercial methods pass
        guard = LicenseGuard(app_data_dir=tmp_dir)
        guard.require_entitlement = MagicMock(return_value={"status": "ACTIVE", "tier": "commercial"})

        bridge = DesktopBridge(
            workspace_root=os.path.join(tmp_dir, "ws"),
            user_presets_dir=os.path.join(tmp_dir, "presets"),
            license_guard=guard,
        )
        yield bridge, tmp_dir


def test_bridge_get_render_profile(mock_bridge):
    bridge, _ = mock_bridge
    req = {
        "id": "req_1",
        "method": "GET_RENDER_PROFILE",
        "params": {"profile_id": "windows_capcut_9_3_0_3970"},
    }
    resp = bridge.dispatch(req)
    assert resp["ok"] is True
    assert resp["result"]["profile_id"] == "windows_capcut_9_3_0_3970"
    assert resp["result"]["architecture"] == "CASE_C_HYBRID_KEYBOARD_HEARTBEAT"


def test_bridge_render_now_and_queue_state(mock_bridge):
    bridge, tmp_dir = mock_bridge
    draft_path = os.path.join(tmp_dir, "draft")
    os.makedirs(draft_path, exist_ok=True)
    out_path = os.path.join(tmp_dir, "video.mp4")

    # 1. Enqueue via RENDER_NOW
    req = {
        "id": "req_2",
        "method": "RENDER_NOW",
        "params": {
            "draft_path": draft_path,
            "output_path": out_path,
            "project_id": "p_test",
        },
    }
    resp = bridge.dispatch(req)
    assert resp["ok"] is True
    job_id = resp["result"]["job_id"]
    assert job_id is not None

    # 2. Query queue state
    req_state = {
        "id": "req_3",
        "method": "GET_RENDER_QUEUE_STATE",
        "params": {},
    }
    resp_state = bridge.dispatch(req_state)
    assert resp_state["ok"] is True
    assert resp_state["result"]["total_jobs"] >= 1

    # 3. Control queue (Pause & Resume)
    req_pause = {
        "id": "req_4",
        "method": "CONTROL_RENDER_QUEUE",
        "params": {"action": "pause"},
    }
    resp_pause = bridge.dispatch(req_pause)
    assert resp_pause["ok"] is True
    assert resp_pause["result"]["action"] == "pause"
