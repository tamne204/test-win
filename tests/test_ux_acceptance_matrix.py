"""
tests/test_ux_acceptance_matrix.py
Automated acceptance matrix for UX & Notification polish (UX-T01 to UX-T16)
Per Directive 10 UX / Notification / User-Facing Truth Audit Section 54.
"""
import os
import sys
import time
import json
import tempfile
import threading
from pathlib import Path
from typing import Dict, Any
from PIL import Image

import pytest

REPO_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO_ROOT))
sys.path.insert(0, str(REPO_ROOT / "apps" / "capcut-v2"))

from core.operation_result import (
    OperationResult,
    reconcile_project_creation,
    OUTCOME_SUCCESS,
    OUTCOME_SUCCESS_WITH_WARNING,
    OUTCOME_FAILED,
    OUTCOME_USER_INPUT_ERROR,
    FRIENDLY_ERROR_MESSAGES,
)
from core.build_queue_manager import (
    ProjectBuildQueueManager,
    STATE_QUEUED,
    STATE_PROJECT_READY,
    STATE_FAILED,
    STATE_CANCELLED,
    QUEUE_STATUS_IDLE,
)
from desktop_bridge.bridge import DesktopBridge


def create_dummy_image(path: str, color=(200, 50, 50)):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    img = Image.new("RGB", (100, 100), color=color)
    img.save(path)


def create_valid_dummy_draft(draft_dir: str, project_name: str = "TestDraft"):
    os.makedirs(draft_dir, exist_ok=True)
    fixture_dir = REPO_ROOT / "tests" / "fixtures" / "capcut_9_3"

    dummy_img = os.path.join(draft_dir, "dummy.png")
    Image.new("RGB", (100, 100)).save(dummy_img)

    with open(fixture_dir / "draft_info_fixture.json", "r", encoding="utf-8") as f:
        draft_info = json.load(f)

    # Point materials to dummy image
    for m in draft_info.get("materials", {}).get("videos", []):
        m["path"] = dummy_img

    with open(os.path.join(draft_dir, "draft_info.json"), "w", encoding="utf-8") as f:
        json.dump(draft_info, f)

    with open(fixture_dir / "draft_meta_info_fixture.json", "r", encoding="utf-8") as f:
        draft_meta = json.load(f)
    draft_meta["draft_fold_path"] = draft_dir
    draft_meta["draft_name"] = project_name

    with open(os.path.join(draft_dir, "draft_meta_info.json"), "w", encoding="utf-8") as f:
        json.dump(draft_meta, f)

    with open(os.path.join(draft_dir, "draft_cover.jpg"), "wb") as f:
        f.write(b"\xff\xd8\xff\xe0" + b"\x00" * 20)


# ==============================================================================
# UX-T01: Successful project creation
# ==============================================================================
def test_ux_t01_successful_project_creation():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        draft_dir = os.path.join(tmp_dir, "My_Successful_Project")
        create_valid_dummy_draft(draft_dir, "My_Successful_Project")

        result = reconcile_project_creation(
            draft_dir=draft_dir,
            project_name="My_Successful_Project",
        )
        assert result.outcome == OUTCOME_SUCCESS
        assert result.is_success is True
        assert result.artifact_exists is True
        assert "thành công" in result.primary_message.lower()


# ==============================================================================
# UX-T02: Project created + CapCut launch failure -> SUCCESS_WITH_WARNING
# ==============================================================================
def test_ux_t02_project_created_plus_capcut_launch_failure():
    """
    CRITICAL FALSE NEGATIVE FIX:
    If draft was generated and registered, but opening CapCut raised an exception,
    the operation result MUST be SUCCESS_WITH_WARNING, NEVER FAILED.
    """
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        draft_dir = os.path.join(tmp_dir, "Draft_Launch_Fail")
        create_valid_dummy_draft(draft_dir, "Draft_Launch_Fail")

        launch_exc = RuntimeError("AppleScript launch timeout: Application CapCut did not respond")
        result = reconcile_project_creation(
            draft_dir=draft_dir,
            project_name="Draft_Launch_Fail",
            exception_caught=launch_exc,
        )

        assert result.outcome == OUTCOME_SUCCESS_WITH_WARNING
        assert result.is_success is True
        assert result.artifact_exists is True
        assert "thành công" in result.primary_message.lower()
        assert "tự động" in (result.secondary_message or "").lower() or "mở" in (result.secondary_message or "").lower()


# ==============================================================================
# UX-T03: Project created + wallet refresh failure
# ==============================================================================
def test_ux_t03_project_created_plus_wallet_refresh_failure():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        draft_dir = os.path.join(tmp_dir, "Draft_Wallet_Fail")
        create_valid_dummy_draft(draft_dir, "Draft_Wallet_Fail")

        result = reconcile_project_creation(
            draft_dir=draft_dir,
            project_name="Draft_Wallet_Fail",
            secondary_warning=FRIENDLY_ERROR_MESSAGES["WALLET_FAILED"],
        )
        assert result.outcome == OUTCOME_SUCCESS_WITH_WARNING
        assert result.is_success is True
        assert "thành công" in result.primary_message.lower()


# ==============================================================================
# UX-T04: Project created + UI refresh failure
# ==============================================================================
def test_ux_t04_project_created_plus_ui_refresh_failure():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        draft_dir = os.path.join(tmp_dir, "Draft_UI_Fail")
        create_valid_dummy_draft(draft_dir, "Draft_UI_Fail")

        result = reconcile_project_creation(
            draft_dir=draft_dir,
            project_name="Draft_UI_Fail",
            secondary_warning=FRIENDLY_ERROR_MESSAGES["INDEX_FAILED"],
        )
        assert result.outcome == OUTCOME_SUCCESS_WITH_WARNING
        assert result.is_success is True


# ==============================================================================
# UX-T05: Real draft creation failure (missing artifact)
# ==============================================================================
def test_ux_t05_real_draft_creation_failure():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        non_existent_dir = os.path.join(tmp_dir, "Ghost_Draft")
        build_exc = FileNotFoundError("Source audio file not found on disk")

        result = reconcile_project_creation(
            draft_dir=non_existent_dir,
            project_name="Ghost_Draft",
            exception_caught=build_exc,
        )
        assert result.outcome == OUTCOME_FAILED
        assert result.is_success is False
        assert result.artifact_exists is False
        assert result.recoverable is True


# ==============================================================================
# UX-T06: Missing audio validation
# ==============================================================================
def test_ux_t06_missing_audio():
    result = reconcile_project_creation(
        draft_dir=None,
        project_name="No_Audio",
        exception_caught=ValueError("Missing audio: Vui lòng chọn tệp âm thanh hợp lệ"),
    )
    assert result.outcome == OUTCOME_FAILED
    assert result.technical_code == "MISSING_AUDIO"
    assert "âm thanh" in result.primary_message.lower()


# ==============================================================================
# UX-T07: Missing images validation
# ==============================================================================
def test_ux_t07_missing_images():
    result = reconcile_project_creation(
        draft_dir=None,
        project_name="No_Images",
        exception_caught=ValueError("Missing images: Không tìm thấy hình ảnh nguồn"),
    )
    assert result.outcome == OUTCOME_FAILED
    assert result.technical_code == "MISSING_IMAGES"
    assert "hình ảnh" in result.primary_message.lower() or "ảnh" in result.primary_message.lower()


# ==============================================================================
# UX-T08: Unsupported CapCut version
# ==============================================================================
def test_ux_t08_unsupported_capcut_version():
    result = reconcile_project_creation(
        draft_dir=None,
        project_name="Old_CapCut",
        exception_caught=ValueError("CapCut version 4.1.0 is below minimum supported 9.4.0"),
    )
    assert result.outcome == OUTCOME_FAILED
    assert result.technical_code == "UNSUPPORTED_VERSION"
    assert "phiên bản" in result.primary_message.lower()


# ==============================================================================
# UX-T09: Duplicate project name handling
# ==============================================================================
def test_ux_t09_duplicate_project_name():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "queue.json")
        img_path = os.path.join(tmp_dir, "test.png")
        create_dummy_image(img_path)

        qm = ProjectBuildQueueManager(persistence_path=persistence)
        job1 = qm.enqueue_job({"images": [img_path]}, project_name="DupProject")
        job2 = qm.enqueue_job({"images": [img_path]}, project_name="DupProject")

        # Each job gets unique ID preventing collision
        assert job1.job_id != job2.job_id
        assert len(qm.get_state()["jobs"]) == 2


# ==============================================================================
# UX-T10: Cancel build job
# ==============================================================================
def test_ux_t10_cancel_build_job():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "queue.json")
        img_path = os.path.join(tmp_dir, "test.png")
        create_dummy_image(img_path)

        qm = ProjectBuildQueueManager(persistence_path=persistence)
        job = qm.enqueue_job({"images": [img_path]}, project_name="CancelMe")
        assert job.state == STATE_QUEUED

        cancelled = qm.cancel_job(job.job_id)
        assert cancelled is True
        assert qm.get_job(job.job_id).state == STATE_CANCELLED


# ==============================================================================
# UX-T11: Retry failed build
# ==============================================================================
def test_ux_t11_retry_failed_build():
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "queue.json")
        img_path = os.path.join(tmp_dir, "test.png")
        create_dummy_image(img_path)

        qm = ProjectBuildQueueManager(persistence_path=persistence)
        job = qm.enqueue_job({"images": [img_path]}, project_name="RetryMe")
        qm.cancel_job(job.job_id)
        assert job.state == STATE_CANCELLED

        retried = qm.retry_job(job.job_id)
        assert retried is True
        assert qm.get_job(job.job_id).state == STATE_QUEUED


# ==============================================================================
# UX-T12: Render success
# ==============================================================================
def test_ux_t12_render_success():
    from adapters.capcut.output_verifier import VerificationResult
    res = VerificationResult(
        is_valid=True,
        details={"output_file": "/tmp/output.mp4", "duration_s": 42.5},
    )
    assert res.is_valid is True
    assert res.details["output_file"] == "/tmp/output.mp4"


# ==============================================================================
# UX-T13: Render output verification failure
# ==============================================================================
def test_ux_t13_render_output_verification_failure():
    from adapters.capcut.output_verifier import OutputVerifier
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        non_existent_mp4 = os.path.join(tmp_dir, "missing.mp4")
        res = OutputVerifier.verify(non_existent_mp4)
        assert res.is_valid is False
        assert "not found" in res.error_message.lower() or "missing" in res.error_message.lower()


# ==============================================================================
# UX-T14: Insufficient balance
# ==============================================================================
def test_ux_t14_insufficient_balance():
    from core.operation_result import FRIENDLY_ERROR_MESSAGES
    msg = FRIENDLY_ERROR_MESSAGES.get("INSUFFICIENT_CREDITS")
    assert "số dư không đủ" in msg.lower()


# ==============================================================================
# UX-T15: Offline / network failure handling
# ==============================================================================
def test_ux_t15_offline_network_failure():
    err_str = "Network connection failed: timeout connecting to https://www.2tamne.site"
    res = reconcile_project_creation(
        draft_dir=None,
        project_name="Offline_Test",
        exception_caught=ConnectionError(err_str),
    )
    assert res.outcome == OUTCOME_FAILED
    assert res.recoverable is True


# ==============================================================================
# UX-T16: Restart with completed artifact but stale FAILED state (Section 42)
# ==============================================================================
def test_ux_t16_restart_artifact_reconciliation():
    """
    Section 42:
    When app restarts:
    if job says FAILED but its project artifact is already valid on disk
    reconcile to PROJECT_READY with message 'Dự án đã được khôi phục.'
    """
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp_dir:
        persistence = os.path.join(tmp_dir, "queue.json")
        draft_dir = os.path.join(tmp_dir, "Reconciled_Draft")
        create_valid_dummy_draft(draft_dir, "Reconciled_Draft")

        # Simulate interrupted job persisted to disk
        stale_data = {
            "version": 1,
            "status": "IDLE",
            "jobs": [
                {
                    "job_id": "build_stale_123",
                    "project_name": "Reconciled_Draft",
                    "payload": {},
                    "state": "FAILED",
                    "progress": 75.0,
                    "current_step": "Lỗi gián đoạn",
                    "result": {
                        "final_draft_dir": draft_dir,
                    },
                }
            ],
        }
        with open(persistence, "w", encoding="utf-8") as f:
            json.dump(stale_data, f)

        # Initialize manager which runs load_snapshot() and recover_from_crash()
        qm = ProjectBuildQueueManager(persistence_path=persistence)
        job = qm.get_job("build_stale_123")
        assert job is not None
        assert job.state == STATE_PROJECT_READY
        assert "khôi phục" in job.current_step.lower()
