"""
tests/test_capcut_native_exporter.py
Unit tests for CapCutNativeExporter and MockAutomationDriver (Phase 5E.1).
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

from adapters.capcut.native_exporter import (
    CapCutNativeExporter,
    MockAutomationDriver,
)
from adapters.capcut.render_profile import WINDOWS_CAPCUT_9_3_0_3970
from adapters.capcut.ownership_manager import CapCutOwnershipManager, OWNER_MANUAL, OWNER_NONE


def test_native_exporter_precheck_missing_draft():
    driver = MockAutomationDriver()
    exporter = CapCutNativeExporter(profile=WINDOWS_CAPCUT_9_3_0_3970, driver=driver)

    res = exporter.export_project(
        draft_path="/tmp/non_existent_draft_path_12345",
        expected_output_path="/tmp/output.mp4",
    )
    assert res["ok"] is False
    assert res["error_code"] == "DRAFT_NOT_FOUND"


def test_native_exporter_user_interruption():
    driver = MockAutomationDriver()
    exporter = CapCutNativeExporter(profile=WINDOWS_CAPCUT_9_3_0_3970, driver=driver)

    with tempfile.TemporaryDirectory() as tmp_dir:
        draft_dir = os.path.join(tmp_dir, "draft")
        os.makedirs(draft_dir)

        # Simulate user taking manual ownership
        CapCutOwnershipManager.set_manual_owner()

        res = exporter.export_project(
            draft_path=draft_dir,
            expected_output_path=os.path.join(tmp_dir, "out.mp4"),
        )
        assert res["ok"] is False
        assert res["error_code"] == "USER_INTERRUPTION"

        CapCutOwnershipManager.release()


def test_native_exporter_successful_lifecycle():
    driver = MockAutomationDriver()
    events = []

    def on_progress(stage, data):
        events.append((stage, data))

    exporter = CapCutNativeExporter(
        profile=WINDOWS_CAPCUT_9_3_0_3970,
        driver=driver,
        progress_callback=on_progress,
    )

    with tempfile.TemporaryDirectory() as tmp_dir:
        draft_dir = os.path.join(tmp_dir, "draft")
        os.makedirs(draft_dir)
        output_file = os.path.join(tmp_dir, "output.mp4")

        # Copy a real MP4 to output_file after simulated trigger
        sample_mp4 = "/Users/2tamne/tool ffmpeg/test_out.mp4"
        if os.path.isfile(sample_mp4):
            shutil.copy(sample_mp4, output_file)
        else:
            with open(output_file, "wb") as f:
                f.write(b"MOCK_MP4_DATA_FOR_TEST")

        res = exporter.export_project(
            draft_path=draft_dir,
            expected_output_path=output_file,
            timeout_sec=5.0,
        )

        # Check driver actions were invoked in sequence
        assert "activate:CapCutMainWindow" in driver.actions_log
        assert "shortcut:Ctrl+E" in driver.actions_log
        assert "key:Enter" in driver.actions_log
        assert "key:Escape" in driver.actions_log

        # Verify stages
        stage_names = [e[0] for e in events]
        assert "PRECHECK" in stage_names
        assert "STARTING_CAPCUT" in stage_names
        assert "OPENING_EXPORT_DIALOG" in stage_names
        assert "STARTING_EXPORT" in stage_names
        assert "RENDERING" in stage_names

        if os.path.isfile(sample_mp4):
            assert res["ok"] is True
            assert "DONE" in stage_names
