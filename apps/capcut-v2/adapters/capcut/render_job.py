"""
apps/capcut-v2/adapters/capcut/render_job.py
Render Job data model and finite state definitions per Sections 40, 41, and 42.
"""
from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Dict, Any, Optional

# Normal lifecycle states (Section 41)
STATE_QUEUED = "QUEUED"
STATE_PRECHECK = "PRECHECK"
STATE_STARTING_CAPCUT = "STARTING_CAPCUT"
STATE_WAITING_CAPCUT_READY = "WAITING_CAPCUT_READY"
STATE_OPENING_PROJECT = "OPENING_PROJECT"
STATE_VERIFYING_PROJECT = "VERIFYING_PROJECT"
STATE_OPENING_EXPORT_DIALOG = "OPENING_EXPORT_DIALOG"
STATE_CONFIGURING_EXPORT = "CONFIGURING_EXPORT"
STATE_STARTING_EXPORT = "STARTING_EXPORT"
STATE_RENDERING = "RENDERING"
STATE_VERIFYING_OUTPUT = "VERIFYING_OUTPUT"
STATE_DONE = "DONE"

# Control and interrupted states (Section 43)
STATE_PAUSED = "PAUSED"
STATE_PAUSED_USER_EDITING = "PAUSED_USER_EDITING"
STATE_CANCELLED = "CANCELLED"
STATE_SKIPPED = "SKIPPED"
STATE_FAILED = "FAILED"

# Explicit error codes (Section 42)
ERR_CAPCUT_NOT_FOUND = "CAPCUT_NOT_FOUND"
ERR_WRONG_CAPCUT_VERSION = "WRONG_CAPCUT_VERSION"
ERR_PROJECT_NOT_FOUND = "PROJECT_NOT_FOUND"
ERR_PROJECT_OPEN_FAILED = "PROJECT_OPEN_FAILED"
ERR_EXPORT_DIALOG_NOT_FOUND = "EXPORT_DIALOG_NOT_FOUND"
ERR_EXPORT_CONFIG_FAILED = "EXPORT_CONFIG_FAILED"
ERR_EXPORT_START_FAILED = "EXPORT_START_FAILED"
ERR_UNKNOWN_POPUP = "UNKNOWN_POPUP"
ERR_EXPORT_STALLED = "EXPORT_STALLED"
ERR_EXPORT_TIMEOUT = "EXPORT_TIMEOUT"
ERR_CAPCUT_CRASH = "CAPCUT_CRASH"
ERR_OUTPUT_INVALID = "OUTPUT_INVALID"
ERR_USER_INTERRUPTION = "USER_INTERRUPTION"


class RenderJob:
    """
    Represents a single automated video render task within the queue.
    """

    def __init__(
        self,
        project_id: str,
        draft_id: str,
        draft_path: str,
        output_path: str,
        output_filename: str,
        render_profile_id: str,
        render_settings: Optional[Dict[str, Any]] = None,
        job_id: Optional[str] = None,
        status: str = STATE_QUEUED,
        created_at: Optional[str] = None,
        started_at: Optional[str] = None,
        finished_at: Optional[str] = None,
        retry_count: int = 0,
        last_error: Optional[Dict[str, Any]] = None,
        verification_details: Optional[Dict[str, Any]] = None,
    ):
        self.job_id = job_id or f"job_{uuid.uuid4().hex[:12]}"
        self.project_id = project_id
        self.draft_id = draft_id
        self.draft_path = draft_path
        self.output_path = output_path
        self.output_filename = output_filename
        self.render_profile_id = render_profile_id
        self.render_settings = render_settings or {}
        self.status = status
        self.created_at = created_at or datetime.now(timezone.utc).isoformat()
        self.started_at = started_at
        self.finished_at = finished_at
        self.retry_count = retry_count
        self.last_error = last_error
        self.verification_details = verification_details

    def transition_to(self, new_state: str, error_info: Optional[Dict[str, Any]] = None) -> None:
        """Update job FSM state with timestamp tracking."""
        self.status = new_state
        now = datetime.now(timezone.utc).isoformat()

        if new_state in (STATE_PRECHECK, STATE_STARTING_CAPCUT) and not self.started_at:
            self.started_at = now

        if new_state in (STATE_DONE, STATE_FAILED, STATE_CANCELLED, STATE_SKIPPED):
            self.finished_at = now

        if error_info:
            self.last_error = error_info

    def to_dict(self) -> Dict[str, Any]:
        return {
            "job_id": self.job_id,
            "project_id": self.project_id,
            "draft_id": self.draft_id,
            "draft_path": self.draft_path,
            "output_path": self.output_path,
            "output_filename": self.output_filename,
            "render_profile_id": self.render_profile_id,
            "render_settings": self.render_settings,
            "status": self.status,
            "created_at": self.created_at,
            "started_at": self.started_at,
            "finished_at": self.finished_at,
            "retry_count": self.retry_count,
            "last_error": self.last_error,
            "verification_details": self.verification_details,
        }

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> RenderJob:
        return cls(
            job_id=data.get("job_id"),
            project_id=data.get("project_id", ""),
            draft_id=data.get("draft_id", ""),
            draft_path=data.get("draft_path", ""),
            output_path=data.get("output_path", ""),
            output_filename=data.get("output_filename", ""),
            render_profile_id=data.get("render_profile_id", ""),
            render_settings=data.get("render_settings", {}),
            status=data.get("status", STATE_QUEUED),
            created_at=data.get("created_at"),
            started_at=data.get("started_at"),
            finished_at=data.get("finished_at"),
            retry_count=data.get("retry_count", 0),
            last_error=data.get("last_error"),
            verification_details=data.get("verification_details"),
        )
