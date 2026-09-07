"""
apps/capcut-v2/adapters/capcut/render_queue_manager.py
Sequential Render Queue Manager per Sections 33, 39, 41, 42, 43, 45, and 46.
Finite State Machine orchestrating single-worker sequential CapCut render tasks.
"""
from __future__ import annotations

import os
import sys
import json
import time
import shutil
import threading
from typing import Dict, Any, Optional, List, Callable

try:
    from .render_job import (
        RenderJob,
        STATE_QUEUED,
        STATE_PRECHECK,
        STATE_STARTING_CAPCUT,
        STATE_WAITING_CAPCUT_READY,
        STATE_OPENING_PROJECT,
        STATE_VERIFYING_PROJECT,
        STATE_OPENING_EXPORT_DIALOG,
        STATE_CONFIGURING_EXPORT,
        STATE_STARTING_EXPORT,
        STATE_RENDERING,
        STATE_VERIFYING_OUTPUT,
        STATE_DONE,
        STATE_PAUSED,
        STATE_PAUSED_USER_EDITING,
        STATE_CANCELLED,
        STATE_SKIPPED,
        STATE_FAILED,
        ERR_CAPCUT_NOT_FOUND,
        ERR_WRONG_CAPCUT_VERSION,
        ERR_PROJECT_NOT_FOUND,
        ERR_USER_INTERRUPTION,
    )
    from .render_profile import RenderProfileRegistry, WINDOWS_CAPCUT_9_3_0_3970
    from .output_verifier import OutputVerifier
    from .ownership_manager import CapCutOwnershipManager, OWNER_MANUAL, OWNER_NONE
    from .native_exporter import CapCutNativeExporter, AutomationDriver
except (ImportError, ValueError):
    from adapters.capcut.render_job import (
        RenderJob,
        STATE_QUEUED,
        STATE_PRECHECK,
        STATE_STARTING_CAPCUT,
        STATE_WAITING_CAPCUT_READY,
        STATE_OPENING_PROJECT,
        STATE_VERIFYING_PROJECT,
        STATE_OPENING_EXPORT_DIALOG,
        STATE_CONFIGURING_EXPORT,
        STATE_STARTING_EXPORT,
        STATE_RENDERING,
        STATE_VERIFYING_OUTPUT,
        STATE_DONE,
        STATE_PAUSED,
        STATE_PAUSED_USER_EDITING,
        STATE_CANCELLED,
        STATE_SKIPPED,
        STATE_FAILED,
        ERR_CAPCUT_NOT_FOUND,
        ERR_WRONG_CAPCUT_VERSION,
        ERR_PROJECT_NOT_FOUND,
        ERR_USER_INTERRUPTION,
    )
    from adapters.capcut.render_profile import RenderProfileRegistry, WINDOWS_CAPCUT_9_3_0_3970
    from adapters.capcut.output_verifier import OutputVerifier
    from adapters.capcut.ownership_manager import CapCutOwnershipManager, OWNER_MANUAL, OWNER_NONE
    from adapters.capcut.native_exporter import CapCutNativeExporter, AutomationDriver


QUEUE_STATUS_IDLE = "IDLE"
QUEUE_STATUS_RUNNING = "RUNNING"
QUEUE_STATUS_PAUSED = "PAUSED"
QUEUE_STATUS_STOPPING = "STOPPING_AFTER_CURRENT"


class RenderQueueManager:
    """
    Finite State Machine orchestrator managing sequential video rendering.
    Enforces Single CapCut Worker invariant (Section 33).
    """

    def __init__(
        self,
        persistence_path: Optional[str] = None,
        driver: Optional[AutomationDriver] = None,
    ):
        self._lock = threading.RLock()
        self._jobs: List[RenderJob] = []
        self._active_job: Optional[RenderJob] = None
        self._status: str = QUEUE_STATUS_IDLE
        self._worker_thread: Optional[threading.Thread] = None
        self._stop_event = threading.Event()
        self._driver = driver
        self._listeners: List[Callable[[Dict[str, Any]], None]] = []

        # Persistence location
        if persistence_path:
            self.persistence_path = persistence_path
        elif sys.platform.startswith("win"):
            app_data = os.environ.get("APPDATA", os.path.expanduser("~\\AppData\\Roaming"))
            self.persistence_path = os.path.join(app_data, "2toolne-autoedit", "render_queue_state.json")
        else:
            self.persistence_path = os.path.expanduser("~/.2toolne-autoedit/render_queue_state.json")

        # Listen to ownership shifts
        CapCutOwnershipManager.add_listener(self._on_ownership_changed)

        # Restore from disk on initialization
        self.load_snapshot()
        self.recover_from_crash()

    def add_listener(self, listener: Callable[[Dict[str, Any]], None]) -> None:
        with self._lock:
            if listener not in self._listeners:
                self._listeners.append(listener)

    def remove_listener(self, listener: Callable[[Dict[str, Any]], None]) -> None:
        with self._lock:
            if listener in self._listeners:
                self._listeners.remove(listener)

    def _notify_listeners(self) -> None:
        try:
            state = self.get_state()
            for listener in list(self._listeners):
                try:
                    listener(state)
                except Exception:
                    pass
        except Exception:
            pass

    def _on_ownership_changed(self, old_owner: str, new_owner: str) -> None:
        if new_owner == OWNER_MANUAL:
            with self._lock:
                if self._active_job and self._status == QUEUE_STATUS_RUNNING:
                    self._active_job.transition_to(
                        STATE_PAUSED_USER_EDITING,
                        error_info={"error_code": ERR_USER_INTERRUPTION, "message": "User opened CapCut manually."},
                    )
                    self._status = QUEUE_STATUS_PAUSED
                    self.save_snapshot()

    # -------------------------------------------------------------------------
    # Queue Controls (Section 43)
    # -------------------------------------------------------------------------

    def enqueue(self, job: RenderJob) -> str:
        with self._lock:
            self._jobs.append(job)
            self.save_snapshot()

            # Auto-start if idle and not explicitly paused
            if self._status == QUEUE_STATUS_IDLE:
                self._start_worker_locked()

            return job.job_id

    def pause(self) -> None:
        with self._lock:
            self._status = QUEUE_STATUS_PAUSED
            self.save_snapshot()

    def resume(self) -> None:
        with self._lock:
            if self._status in (QUEUE_STATUS_PAUSED, QUEUE_STATUS_IDLE):
                self._start_worker_locked()
            self.save_snapshot()

    def stop_after_current(self) -> None:
        with self._lock:
            if self._status == QUEUE_STATUS_RUNNING:
                self._status = QUEUE_STATUS_STOPPING
            else:
                self._status = QUEUE_STATUS_PAUSED
            self.save_snapshot()

    def cancel_job(self, job_id: str) -> bool:
        with self._lock:
            for job in self._jobs:
                if job.job_id == job_id:
                    if job.status not in (STATE_DONE, STATE_FAILED, STATE_CANCELLED):
                        job.transition_to(STATE_CANCELLED)
                        if self._active_job and self._active_job.job_id == job_id:
                            CapCutOwnershipManager.release(job_id)
                        self.save_snapshot()
                        return True
            return False

    def skip_job(self, job_id: str) -> bool:
        with self._lock:
            for job in self._jobs:
                if job.job_id == job_id:
                    if job.status in (STATE_QUEUED, STATE_PAUSED):
                        job.transition_to(STATE_SKIPPED)
                        self.save_snapshot()
                        return True
            return False

    def retry_job(self, job_id: str) -> bool:
        with self._lock:
            for job in self._jobs:
                if job.job_id == job_id:
                    if job.status in (STATE_FAILED, STATE_CANCELLED, STATE_SKIPPED):
                        job.retry_count += 1
                        job.transition_to(STATE_QUEUED)
                        job.last_error = None
                        self.save_snapshot()
                        if self._status in (QUEUE_STATUS_IDLE, QUEUE_STATUS_PAUSED):
                            self.resume()
                        return True
            return False

    def clear_completed(self) -> int:
        with self._lock:
            initial_count = len(self._jobs)
            self._jobs = [j for j in self._jobs if j.status not in (STATE_DONE, STATE_CANCELLED, STATE_SKIPPED)]
            removed = initial_count - len(self._jobs)
            if removed > 0:
                self.save_snapshot()
            return removed

    def get_state(self) -> Dict[str, Any]:
        with self._lock:
            return {
                "queue_status": self._status,
                "total_jobs": len(self._jobs),
                "active_job": self._active_job.to_dict() if self._active_job else None,
                "jobs": [j.to_dict() for j in self._jobs],
                "current_capcut_owner": CapCutOwnershipManager.get_owner(),
            }

    # -------------------------------------------------------------------------
    # Worker Thread (Section 33: Single Worker)
    # -------------------------------------------------------------------------

    def _start_worker_locked(self) -> None:
        if self._worker_thread and self._worker_thread.is_alive():
            return
        self._status = QUEUE_STATUS_RUNNING
        self._stop_event.clear()
        self._worker_thread = threading.Thread(target=self._run_worker, daemon=True)
        self._worker_thread.start()

    def _run_worker(self) -> None:
        while not self._stop_event.is_set():
            job_to_run = None
            with self._lock:
                if self._status not in (QUEUE_STATUS_RUNNING,):
                    break

                for job in self._jobs:
                    if job.status == STATE_QUEUED:
                        job_to_run = job
                        break

                if not job_to_run:
                    self._status = QUEUE_STATUS_IDLE
                    self._active_job = None
                    self.save_snapshot()
                    break

                self._active_job = job_to_run

            self._process_single_job(job_to_run)

            with self._lock:
                self._active_job = None
                if self._status == QUEUE_STATUS_STOPPING:
                    self._status = QUEUE_STATUS_PAUSED
                    self.save_snapshot()
                    break

    def _process_single_job(self, job: RenderJob) -> None:
        """Execute the FSM stages for one job."""
        profile = RenderProfileRegistry.get(job.render_profile_id) or WINDOWS_CAPCUT_9_3_0_3970

        def stage_callback(stage_name: str, payload: Dict[str, Any]):
            with self._lock:
                job.transition_to(stage_name)
                self.save_snapshot()

        exporter = CapCutNativeExporter(
            profile=profile,
            driver=self._driver,
            progress_callback=stage_callback,
        )

        job.transition_to(STATE_PRECHECK)
        self.save_snapshot()

        expected_dur = job.render_settings.get("expected_duration_sec")
        result = exporter.export_project(
            draft_path=job.draft_path,
            expected_output_path=job.output_path,
            expected_duration_sec=expected_dur,
            job_id=job.job_id,
        )

        with self._lock:
            if result.get("ok"):
                job.verification_details = result.get("verification")
                job.transition_to(STATE_DONE)
            else:
                job.transition_to(STATE_FAILED, error_info={
                    "error_code": result.get("error_code", "UNKNOWN_ERROR"),
                    "message": result.get("message", "Render execution failed"),
                    "details": result.get("details"),
                })
            self.save_snapshot()

    # -------------------------------------------------------------------------
    # Atomic Snapshot Persistence (Section 45)
    # -------------------------------------------------------------------------

    def save_snapshot(self) -> None:
        try:
            target_dir = os.path.dirname(self.persistence_path)
            if target_dir and not os.path.exists(target_dir):
                os.makedirs(target_dir, exist_ok=True)

            temp_path = self.persistence_path + ".tmp"
            payload = {
                "version": "1.0",
                "updated_at": time.time(),
                "queue_status": self._status,
                "jobs": [j.to_dict() for j in self._jobs],
            }

            with open(temp_path, "w", encoding="utf-8") as f:
                json.dump(payload, f, indent=2)
                f.flush()
                os.fsync(f.fileno())

            # Atomic replace
            shutil.move(temp_path, self.persistence_path)
            self._notify_listeners()
        except Exception as exc:
            pass

    def load_snapshot(self) -> bool:
        with self._lock:
            if not os.path.isfile(self.persistence_path):
                return False
            try:
                with open(self.persistence_path, "r", encoding="utf-8") as f:
                    data = json.load(f)

                self._jobs = [RenderJob.from_dict(jd) for jd in data.get("jobs", [])]
                self._status = QUEUE_STATUS_IDLE  # Do not resume automatically until requested
                return True
            except Exception:
                return False

    # -------------------------------------------------------------------------
    # Crash Recovery (Section 46)
    # -------------------------------------------------------------------------

    def recover_from_crash(self) -> None:
        """
        Inspect unfinished jobs on boot and reconcile their actual disk state.
        Never blindly restart export after crash.
        """
        with self._lock:
            for job in self._jobs:
                if job.status in (
                    STATE_PRECHECK,
                    STATE_STARTING_CAPCUT,
                    STATE_WAITING_CAPCUT_READY,
                    STATE_OPENING_PROJECT,
                    STATE_VERIFYING_PROJECT,
                    STATE_OPENING_EXPORT_DIALOG,
                    STATE_CONFIGURING_EXPORT,
                    STATE_STARTING_EXPORT,
                    STATE_RENDERING,
                    STATE_VERIFYING_OUTPUT,
                ):
                    # Job was interrupted mid-flight!
                    if os.path.isfile(job.output_path):
                        # Run OutputVerifier to check if file actually completed
                        ver = OutputVerifier.verify(job.output_path)
                        if ver.is_valid:
                            job.verification_details = ver.details
                            job.transition_to(STATE_DONE)
                        else:
                            job.transition_to(STATE_FAILED, error_info={
                                "error_code": "CRASH_OUTPUT_INCOMPLETE",
                                "message": "App restart detected unfinished job with invalid/truncated output file.",
                            })
                    else:
                        job.transition_to(STATE_FAILED, error_info={
                            "error_code": "CRASH_INTERRUPTED",
                            "message": "App restart detected interrupted render job before output creation.",
                        })

            self.save_snapshot()
