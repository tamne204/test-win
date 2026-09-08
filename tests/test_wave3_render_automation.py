"""
tests/test_wave3_render_automation.py
Acceptance tests for Wave 3: Render Queue & Native Automation.
"""
import os
import sys
import time
import tempfile
import pytest

from adapters.capcut.render_profile import (
    RenderProfileRegistry,
    MACOS_CAPCUT_9_4_0,
    WINDOWS_CAPCUT_9_3_0_3970,
)
from adapters.capcut.render_job import (
    RenderJob,
    STATE_QUEUED,
    STATE_DONE,
    STATE_FAILED,
    STATE_CANCELLED,
    STATE_SKIPPED,
)
from adapters.capcut.render_queue_manager import (
    RenderQueueManager,
    QUEUE_STATUS_IDLE,
    QUEUE_STATUS_RUNNING,
    QUEUE_STATUS_PAUSED,
)
from adapters.capcut.native_exporter import (
    AutomationDriver,
    MockAutomationDriver,
    MacOSAutomationDriver,
    CapCutNativeExporter,
)
from desktop_bridge.bridge import DesktopBridge


def test_macos_render_profile_registered():
    profile = RenderProfileRegistry.get("macos_capcut_9_4_0")
    assert profile is not None
    assert profile.platform_name == "darwin"
    assert profile.app_version == "9.4.0"
    assert profile.export_shortcut == "Cmd+E"
    assert profile.confirm_export_key == "Return"
    assert profile.dismiss_dialog_key == "Escape"


def test_macos_automation_driver_interface():
    driver = MacOSAutomationDriver()
    assert isinstance(driver, AutomationDriver)
    assert hasattr(driver, "find_and_activate_window")
    assert hasattr(driver, "send_shortcut")
    assert hasattr(driver, "send_key")
    assert hasattr(driver, "is_process_alive")


def test_render_queue_manager_lifecycle(tmp_path):
    state_file = str(tmp_path / "render_state.json")
    mock_driver = MockAutomationDriver(simulate_file_creation=True)
    qm = RenderQueueManager(persistence_path=state_file, driver=mock_driver)

    draft_dir = tmp_path / "test_draft"
    draft_dir.mkdir()
    out_file = tmp_path / "test_output.mp4"

    job = RenderJob(
        project_id="TestRenderProj",
        draft_id="draft_123",
        draft_path=str(draft_dir),
        output_path=str(out_file),
        output_filename="test_output.mp4",
        render_profile_id="macos_capcut_9_4_0" if sys.platform == "darwin" else "windows_capcut_9_3_0_3970",
    )

    job_id = qm.enqueue(job)
    assert job_id == job.job_id

    # Wait for worker thread to process job to completion
    deadline = time.time() + 15.0
    while time.time() < deadline:
        st = qm.get_state()
        if st["jobs"] and st["jobs"][0]["status"] in (STATE_DONE, STATE_FAILED):
            break
        time.sleep(0.2)

    state = qm.get_state()
    assert len(state["jobs"]) == 1
    assert state["jobs"][0]["status"] == STATE_DONE
    assert os.path.exists(state_file)


def test_bridge_render_methods(tmp_path):
    state_file = str(tmp_path / "bridge_render_state.json")
    mock_driver = MockAutomationDriver(simulate_file_creation=True)
    qm = RenderQueueManager(persistence_path=state_file, driver=mock_driver)

    bridge = DesktopBridge(
        workspace_root=str(tmp_path),
        render_queue_manager=qm,
    )
    # Bypass commercial entitlement check for unit testing
    bridge.license_guard.require_entitlement = lambda *args, **kwargs: {"status": "ACTIVE"}

    draft_dir = tmp_path / "bridge_draft"
    draft_dir.mkdir()
    out_file = tmp_path / "bridge_out.mp4"

    # Enqueue via bridge
    res = bridge.dispatch({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "ENQUEUE_RENDER",
        "params": {
            "draft_path": str(draft_dir),
            "output_path": str(out_file),
            "project_id": "BridgeTestProject",
        }
    })
    assert res.get("result", {}).get("ok") is True
    job_id = res["result"]["job_id"]

    # Check queue state via bridge
    st_res = bridge.dispatch({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "GET_RENDER_QUEUE_STATE",
        "params": {}
    })
    assert len(st_res["result"]["jobs"]) >= 1

    # Pause queue
    ctrl_res = bridge.dispatch({
        "jsonrpc": "2.0",
        "id": 3,
        "method": "CONTROL_RENDER_QUEUE",
        "params": {"action": "pause"}
    })
    assert ctrl_res.get("result", {}).get("ok") is True

    # Get render profiles
    prof_res = bridge.dispatch({
        "jsonrpc": "2.0",
        "id": 4,
        "method": "GET_RENDER_PROFILE",
        "params": {}
    })
    assert "profiles" in prof_res["result"]
    assert len(prof_res["result"]["profiles"]) >= 2


def test_physical_macos_capcut_detection():
    if sys.platform != "darwin" or not os.path.isdir("/Applications/CapCut.app"):
        pytest.skip("CapCut.app not installed on this machine")

    from adapters.capcut.version_guard import CapCutVersionGuard
    exe_path = "/Applications/CapCut.app/Contents/MacOS/CapCut"
    res = CapCutVersionGuard.inspect_executable(exe_path)
    assert res.is_supported is True
    assert res.detected_version == "9.4.0"
    assert res.profile is not None
    assert res.profile.profile_id == "macos_capcut_9_4_0"
