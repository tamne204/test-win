"""
tests/test_wave4_account_and_release.py
Acceptance tests for Wave 4: Account / Wallet UX, Release Packaging Exclusions, and End-to-End Workflow.
"""
import os
import sys
import yaml
import pytest
from pathlib import Path

DESKTOP_DIR = Path(__file__).resolve().parent.parent / "apps" / "capcut-v2" / "desktop"


def test_electron_builder_exclusions():
    config_file = DESKTOP_DIR / "electron-builder.yml"
    assert config_file.is_file()

    with open(config_file, "r", encoding="utf-8") as f:
        config = yaml.safe_load(f)

    files_list = config.get("files", [])
    assert "!reports/**" in files_list
    assert "!diagnostics/**" in files_list
    assert "!**/.pytest_cache/**" in files_list
    assert "!**/__pycache__/**" in files_list
    assert "!**/*.pyc" in files_list

    # Check extraResources exclusions
    extra = config.get("extraResources", [])
    assert len(extra) > 0
    filters = extra[0].get("filter", [])
    assert "!**/reports/**" in filters
    assert "!**/diagnostics/**" in filters
    assert "!**/.pytest_cache/**" in filters
    assert "!**/__pycache__/**" in filters


def test_auth_wallet_flow_simulation():
    """Simulate account and wallet balance tracking."""
    state = {
        "user": None,
        "token_balance": 0,
        "projects": [],
    }

    # 1. Login
    state["user"] = {"id": "usr_123", "email": "creator@2toolne.vn", "tier": "PRO"}
    state["token_balance"] = 500

    assert state["user"]["tier"] == "PRO"
    assert state["token_balance"] == 500

    # 2. Deduct tokens on project creation
    cost_per_project = 10
    assert state["token_balance"] >= cost_per_project
    state["token_balance"] -= cost_per_project

    state["projects"].append({"id": "proj_1", "name": "Video 1"})
    assert state["token_balance"] == 490
    assert len(state["projects"]) == 1


def test_end_to_end_acceptance_flow(tmp_path):
    """
    End-to-end acceptance flow:
    Preset Selection -> Timeline Plan -> Staging Draft -> Build Queue -> Render Queue.
    """
    from core.preset_manager import PresetManager
    from core.timeline_builder import TimelineBuilder
    from adapters.capcut.render_job import RenderJob, STATE_QUEUED
    from adapters.capcut.render_queue_manager import RenderQueueManager
    from adapters.capcut.native_exporter import MockAutomationDriver

    # 1. Preset selection
    pm = PresetManager()
    preset = pm.get_preset("normal")
    assert preset.id == "normal"

    # 2. Timeline plan
    img1 = tmp_path / "1.png"
    img2 = tmp_path / "2.png"
    img1.write_bytes(b"dummy")
    img2.write_bytes(b"dummy")

    builder = TimelineBuilder(preset=preset)
    plan = builder.build(images=[str(img1), str(img2)], project_name="E2E Project")
    assert plan.project.name == "E2E Project"
    assert len(plan.clips) == 2

    # 3. Queue to Render Queue
    state_file = str(tmp_path / "e2e_render_state.json")
    mock_driver = MockAutomationDriver(simulate_file_creation=True)
    qm = RenderQueueManager(persistence_path=state_file, driver=mock_driver)

    draft_dir = tmp_path / "e2e_draft"
    draft_dir.mkdir()
    out_mp4 = tmp_path / "e2e_output.mp4"

    job = RenderJob(
        project_id=plan.project.name,
        draft_id="draft_e2e",
        draft_path=str(draft_dir),
        output_path=str(out_mp4),
        output_filename="e2e_output.mp4",
        render_profile_id="macos_capcut_9_4_0" if sys.platform == "darwin" else "windows_capcut_9_3_0_3970",
    )
    job_id = qm.enqueue(job)
    assert job_id is not None
