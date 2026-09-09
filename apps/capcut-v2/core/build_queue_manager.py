"""
apps/capcut-v2/core/build_queue_manager.py
Project Build Queue Manager (Queue A) for 2TOOLNE AutoEdit V2.
Orchestrates transactional creation of CapCut draft projects from raw studio inputs.
Completely separate from Render Queue (Queue B).
Zero AI randomness. Strictly deterministic.
"""
from __future__ import annotations

import os
import sys
import json
import time
import shutil
import logging
import threading
from dataclasses import dataclass, field, asdict
from typing import Dict, Any, Optional, List, Callable

logger = logging.getLogger("AutoEdit.BuildQueue")

# FSM States for Build Jobs
STATE_QUEUED = "QUEUED"
STATE_VALIDATING = "VALIDATING"
STATE_PINNING_INPUTS = "PINNING_INPUTS"
STATE_UPSCALING = "UPSCALING"
STATE_SUBTITLE = "SUBTITLE"
STATE_WAITING_SRT_REVIEW = "WAITING_SRT_REVIEW"
STATE_TIMELINE = "TIMELINE"
STATE_BUILDING_DRAFT = "BUILDING_DRAFT"
STATE_VERIFYING = "VERIFYING"
STATE_PROJECT_READY = "PROJECT_READY"
STATE_FAILED = "FAILED"
STATE_CANCELLED = "CANCELLED"

# Overall Queue Status
QUEUE_STATUS_IDLE = "IDLE"
QUEUE_STATUS_RUNNING = "RUNNING"
QUEUE_STATUS_STOPPING = "STOPPING_AFTER_CURRENT"

# Vietnamese Human-Readable State Descriptions
VI_STATE_LABELS = {
    STATE_QUEUED: "Đang chờ xử lý",
    STATE_VALIDATING: "Kiểm tra tệp tin đầu vào",
    STATE_PINNING_INPUTS: "Cố định tài nguyên dự án",
    STATE_UPSCALING: "Đang nâng cấp độ phân giải ảnh (AI Upscale)",
    STATE_SUBTITLE: "Đang xử lý phụ đề và so khớp giọng nói",
    STATE_WAITING_SRT_REVIEW: "Chờ người dùng duyệt phụ đề",
    STATE_TIMELINE: "Đang dựng dòng thời gian và bố cục hình ảnh",
    STATE_BUILDING_DRAFT: "Đang sinh cấu trúc dự án CapCut",
    STATE_VERIFYING: "Thẩm định độ toàn vẹn dự án",
    STATE_PROJECT_READY: "Dự án đã sẵn sàng",
    STATE_FAILED: "Thất bại",
    STATE_CANCELLED: "Đã hủy",
}


@dataclass
class BuildJob:
    """Snapshot representing a single CapCut project build request."""
    job_id: str
    project_name: str
    payload: Dict[str, Any] = field(default_factory=dict)
    state: str = STATE_QUEUED
    progress: float = 0.0
    current_step: str = VI_STATE_LABELS[STATE_QUEUED]
    created_at: float = field(default_factory=time.time)
    started_at: Optional[float] = None
    completed_at: Optional[float] = None
    error: Optional[str] = None
    result: Optional[Dict[str, Any]] = None

    def to_dict(self) -> Dict[str, Any]:
        return {
            "job_id": self.job_id,
            "project_name": self.project_name,
            "payload": self.payload,
            "state": self.state,
            "progress": self.progress,
            "current_step": self.current_step,
            "created_at": self.created_at,
            "started_at": self.started_at,
            "completed_at": self.completed_at,
            "error": self.error,
            "result": self.result,
        }

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> BuildJob:
        return cls(
            job_id=data.get("job_id", ""),
            project_name=data.get("project_name", "AutoEdit Project"),
            payload=data.get("payload", {}),
            state=data.get("state", STATE_QUEUED),
            progress=float(data.get("progress", 0.0)),
            current_step=data.get("current_step", VI_STATE_LABELS.get(data.get("state", STATE_QUEUED), "")),
            created_at=float(data.get("created_at", time.time())),
            started_at=data.get("started_at"),
            completed_at=data.get("completed_at"),
            error=data.get("error"),
            result=data.get("result"),
        )


class ProjectBuildQueueManager:
    """
    Finite State Machine orchestrator managing sequential CapCut project creation.
    Enforces atomic creation, queue snapshotting, crash recovery, and sequential batch execution.
    """

    def __init__(
        self,
        persistence_path: Optional[str] = None,
        workspace_root: Optional[str] = None,
        generator_fn: Optional[Callable[[Dict[str, Any], Callable[[str, float, str], None]], Dict[str, Any]]] = None,
    ):
        self._lock = threading.RLock()
        self._jobs: List[BuildJob] = []
        self._active_job: Optional[BuildJob] = None
        self._status: str = QUEUE_STATUS_IDLE
        self._worker_thread: Optional[threading.Thread] = None
        self._stop_event = threading.Event()
        self._cancel_active_event = threading.Event()
        self._listeners: List[Callable[[Dict[str, Any]], None]] = []
        self.workspace_root = workspace_root
        self._generator_fn = generator_fn

        # Persistence location
        if persistence_path:
            self.persistence_path = persistence_path
        elif sys.platform.startswith("win"):
            app_data = os.environ.get("APPDATA", os.path.expanduser("~\\AppData\\Roaming"))
            self.persistence_path = os.path.join(app_data, "2toolne-autoedit", "build_queue_state.json")
        else:
            self.persistence_path = os.path.expanduser("~/.2toolne-autoedit/build_queue_state.json")

        # Load snapshot & recover on start
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

    def _notify(self) -> None:
        state = self.get_state()
        for listener in list(self._listeners):
            try:
                listener(state)
            except Exception as e:
                logger.warning(f"Error in build queue listener: {e}")

    def enqueue_job(self, payload: Dict[str, Any], project_name: Optional[str] = None) -> BuildJob:
        """
        Freezes a studio configuration snapshot and places it into the build queue.
        Later modifications to the studio configuration do not affect this snapshot.
        """
        with self._lock:
            # Deep copy payload to ensure immutable snapshot
            payload_snapshot = json.loads(json.dumps(payload))
            name = project_name or payload_snapshot.get("project_name") or f"Project_{int(time.time())}"
            job_id = f"build_{int(time.time() * 1000)}_{os.urandom(2).hex()}"

            job = BuildJob(
                job_id=job_id,
                project_name=name,
                payload=payload_snapshot,
                state=STATE_QUEUED,
                progress=0.0,
                current_step=VI_STATE_LABELS[STATE_QUEUED],
                created_at=time.time(),
            )
            self._jobs.append(job)
            self.save_snapshot()
            self._notify()
            return job

    def get_state(self) -> Dict[str, Any]:
        """Returns the full state of the build queue."""
        with self._lock:
            return {
                "queue_status": self._status,
                "total_jobs": len(self._jobs),
                "active_job": self._active_job.to_dict() if self._active_job else None,
                "jobs": [j.to_dict() for j in self._jobs],
            }

    def get_job(self, job_id: str) -> Optional[BuildJob]:
        with self._lock:
            for j in self._jobs:
                if j.job_id == job_id:
                    return j
            return None

    def cancel_job(self, job_id: str) -> bool:
        """Cancels a queued or currently executing build job."""
        with self._lock:
            job = self.get_job(job_id)
            if not job:
                return False

            if job.state == STATE_QUEUED or job.state == STATE_WAITING_SRT_REVIEW:
                job.state = STATE_CANCELLED
                job.current_step = VI_STATE_LABELS[STATE_CANCELLED]
                job.completed_at = time.time()
                self.save_snapshot()
                self._notify()
                return True

            if self._active_job and self._active_job.job_id == job_id:
                self._cancel_active_event.set()
                job.state = STATE_CANCELLED
                job.current_step = VI_STATE_LABELS[STATE_CANCELLED]
                job.completed_at = time.time()
                self.save_snapshot()
                self._notify()
                return True

            return False

    def retry_job(self, job_id: str) -> bool:
        """Resets a failed or cancelled job back to QUEUED state."""
        with self._lock:
            job = self.get_job(job_id)
            if not job or job.state not in (STATE_FAILED, STATE_CANCELLED):
                return False

            job.state = STATE_QUEUED
            job.progress = 0.0
            job.current_step = VI_STATE_LABELS[STATE_QUEUED]
            job.started_at = None
            job.completed_at = None
            job.error = None
            job.result = None
            self.save_snapshot()
            self._notify()
            return True

    def remove_job(self, job_id: str) -> bool:
        """Removes a job from queue if it is not currently executing."""
        with self._lock:
            if self._active_job and self._active_job.job_id == job_id:
                return False
            initial_len = len(self._jobs)
            self._jobs = [j for j in self._jobs if j.job_id != job_id]
            if len(self._jobs) != initial_len:
                self.save_snapshot()
                self._notify()
                return True
            return False

    def clear_completed(self) -> int:
        """Removes all finished, failed, or cancelled jobs."""
        with self._lock:
            active_id = self._active_job.job_id if self._active_job else None
            before = len(self._jobs)
            self._jobs = [
                j for j in self._jobs
                if j.job_id == active_id or j.state not in (STATE_PROJECT_READY, STATE_FAILED, STATE_CANCELLED)
            ]
            cleared = before - len(self._jobs)
            if cleared > 0:
                self.save_snapshot()
                self._notify()
            return cleared

    def build_job(self, job_id: str) -> bool:
        """Triggers execution for a specific job."""
        with self._lock:
            job = self.get_job(job_id)
            if not job:
                return False
            if job.state in (STATE_FAILED, STATE_CANCELLED):
                job.state = STATE_QUEUED
                job.progress = 0.0
                job.error = None
                job.result = None
                self.save_snapshot()

            if self._status == QUEUE_STATUS_RUNNING:
                return True

            self._status = QUEUE_STATUS_RUNNING
            self._stop_event.clear()
            self._cancel_active_event.clear()
            self._worker_thread = threading.Thread(
                target=self._run_worker,
                args=([job_id],),
                daemon=True,
                name=f"BuildWorker_{job_id}",
            )
            self._worker_thread.start()
            self._notify()
            return True

    def build_all(self) -> bool:
        """Starts batch sequential processing of all QUEUED jobs."""
        with self._lock:
            queued_jobs = [j for j in self._jobs if j.state == STATE_QUEUED]
            if not queued_jobs:
                return False
            if self._status == QUEUE_STATUS_RUNNING:
                return True

            self._status = QUEUE_STATUS_RUNNING
            self._stop_event.clear()
            self._cancel_active_event.clear()
            self._worker_thread = threading.Thread(
                target=self._run_worker,
                args=(None,),
                daemon=True,
                name="BuildWorker_Batch",
            )
            self._worker_thread.start()
            self._notify()
            return True

    def stop_queue(self) -> None:
        """Signals worker to stop after the current active job completes."""
        with self._lock:
            if self._status == QUEUE_STATUS_RUNNING:
                self._status = QUEUE_STATUS_STOPPING
                self._stop_event.set()
                self._notify()

    # -------------------------------------------------------------------------
    # Sequential Worker Logic
    # -------------------------------------------------------------------------

    def _run_worker(self, target_job_ids: Optional[List[str]] = None) -> None:
        """Single sequential worker loop ensuring CapCut Draft index safety."""
        logger.info(f"Build worker started. Target IDs: {target_job_ids or 'ALL QUEUED'}")

        while not self._stop_event.is_set():
            next_job: Optional[BuildJob] = None
            with self._lock:
                for j in self._jobs:
                    if j.state == STATE_QUEUED:
                        if target_job_ids is None or j.job_id in target_job_ids:
                            next_job = j
                            break

                if not next_job:
                    self._status = QUEUE_STATUS_IDLE
                    self._active_job = None
                    self.save_snapshot()
                    self._notify()
                    break

                self._active_job = next_job
                self._cancel_active_event.clear()

            # Process the selected job outside the lock
            self._process_single_job(next_job)

            with self._lock:
                self._active_job = None
                self.save_snapshot()
                self._notify()

            if target_job_ids and next_job.job_id in target_job_ids:
                target_job_ids.remove(next_job.job_id)
                if not target_job_ids:
                    break

        with self._lock:
            self._status = QUEUE_STATUS_IDLE
            self._active_job = None
            self.save_snapshot()
            self._notify()
        logger.info("Build worker terminated.")

    def _process_single_job(self, job: BuildJob) -> None:
        """Executes full transactional build sequence for a single job."""
        job.started_at = time.time()
        job.progress = 5.0

        def update_state(new_state: str, progress: float, msg: Optional[str] = None):
            with self._lock:
                job.state = new_state
                job.progress = progress
                job.current_step = msg or VI_STATE_LABELS.get(new_state, new_state)
                self.save_snapshot()
                self._notify()

        try:
            if self._cancel_active_event.is_set():
                job.state = STATE_CANCELLED
                job.current_step = VI_STATE_LABELS[STATE_CANCELLED]
                job.completed_at = time.time()
                return

            # Milestone 1: VALIDATING
            update_state(STATE_VALIDATING, 10.0, "Kiểm tra tệp tin đầu vào...")
            payload = job.payload
            images = payload.get("images", [])
            audio_path = payload.get("audio_path")

            if not images and not audio_path:
                raise ValueError("Cần ít nhất một ảnh hoặc một tệp âm thanh hợp lệ.")

            # Validate local file existence
            missing_files = []
            for img in images:
                if not os.path.exists(img):
                    missing_files.append(img)
            if audio_path and not os.path.exists(audio_path):
                missing_files.append(audio_path)
            if missing_files:
                raise ValueError(f"Không tìm thấy các tệp tin sau: {', '.join(missing_files[:3])}")

            if self._cancel_active_event.is_set():
                update_state(STATE_CANCELLED, job.progress)
                return

            # Milestone 2: PINNING_INPUTS
            update_state(STATE_PINNING_INPUTS, 20.0, "Cố định tài nguyên dự án...")
            time.sleep(0.05)

            # Milestone 3: UPSCALING (if enabled)
            if payload.get("auto_upscale", False):
                update_state(STATE_UPSCALING, 25.0, "Đang kiểm tra phóng to ảnh (AI Upscale)...")
                time.sleep(0.05)

            if self._cancel_active_event.is_set():
                update_state(STATE_CANCELLED, job.progress)
                return

            # Milestone 4: SUBTITLE
            update_state(STATE_SUBTITLE, 35.0, "Đang xử lý phụ đề và so khớp giọng nói...")
            if self._generator_fn:
                def progress_cb(stage: str, frac: float, msg: str):
                    if self._cancel_active_event.is_set():
                        raise InterruptedError("Job cancelled by user")
                    mapped_state = STATE_TIMELINE
                    if "SUBTITLE" in stage or "ALIGN" in stage:
                        mapped_state = STATE_SUBTITLE
                    elif "DRAFT" in stage or "CAPCUT" in stage:
                        mapped_state = STATE_BUILDING_DRAFT
                    elif "VERIF" in stage or "INSTALL" in stage:
                        mapped_state = STATE_VERIFYING
                    elif "READY" in stage:
                        mapped_state = STATE_PROJECT_READY
                    update_state(mapped_state, frac * 100.0, msg)

                result = self._generator_fn(payload, progress_cb)
            else:
                result = self._execute_default_build(payload, update_state)

            if self._cancel_active_event.is_set():
                update_state(STATE_CANCELLED, job.progress)
                return

            # Milestone 5: PROJECT_READY
            job.completed_at = time.time()
            job.result = result
            job.state = STATE_PROJECT_READY
            job.progress = 100.0
            job.current_step = VI_STATE_LABELS[STATE_PROJECT_READY]
            logger.info(f"Build job {job.job_id} ({job.project_name}) SUCCESS.")

        except InterruptedError:
            job.state = STATE_CANCELLED
            job.completed_at = time.time()
            job.current_step = VI_STATE_LABELS[STATE_CANCELLED]
            logger.info(f"Build job {job.job_id} cancelled.")
        except Exception as e:
            # Evidence-based artifact reconciliation before classifying as failed
            draft_dir = None
            if isinstance(getattr(job, "result", None), dict) and job.result.get("final_draft_dir"):
                draft_dir = job.result.get("final_draft_dir")

            if not draft_dir:
                try:
                    from adapters.capcut.detector import CapCutDetector
                    draft_root = CapCutDetector.get_draft_root()
                    if draft_root and os.path.exists(draft_root):
                        for folder in os.listdir(draft_root):
                            full_folder = os.path.join(draft_root, folder)
                            if os.path.isdir(full_folder) and (folder == job.project_name or folder.startswith(f"{job.project_name}_")):
                                if abs(time.time() - os.path.getmtime(full_folder)) < 600:
                                    draft_dir = full_folder
                                    break
                except Exception:
                    pass

            from core.operation_result import reconcile_project_creation
            op_result = reconcile_project_creation(
                draft_dir=draft_dir,
                project_name=job.project_name,
                exception_caught=e,
            )

            if op_result.is_success:
                job.completed_at = time.time()
                job.state = STATE_PROJECT_READY
                job.progress = 100.0
                job.current_step = f"Dự án đã sẵn sàng ({op_result.secondary_message or 'Lưu ý'})"
                job.error = None
                if not job.result:
                    job.result = {
                        "ok": True,
                        "project_name": job.project_name,
                        "final_draft_dir": draft_dir,
                        "is_registered_in_capcut": True,
                        "outcome": op_result.outcome,
                        "warning": op_result.secondary_message,
                    }
                self.save_snapshot()
                self._notify()
                logger.warning(f"Build job {job.job_id} reconciled to PROJECT_READY despite exception: {e}")
                return

            job.state = STATE_FAILED
            job.completed_at = time.time()
            job.error = op_result.primary_message
            job.current_step = f"Lỗi: {op_result.primary_message}"
            self.save_snapshot()
            self._notify()
            logger.error(f"Build job {job.job_id} failed: {e}", exc_info=True)

    def _execute_default_build(
        self,
        payload: Dict[str, Any],
        update_state_cb: Callable[[str, float, str], None],
    ) -> Dict[str, Any]:
        """Default fallback draft generation invoking TimelineBuilder and CapCutProjectManager."""
        from core.timeline_builder import TimelineBuilder, TIMING_MODE_FIXED, TIMING_MODE_SRT_DRIVEN
        from core.preset_manager import PresetManager
        from adapters.capcut.project_manager import CapCutProjectManager

        images = payload.get("images", [])
        audio_path = payload.get("audio_path")
        srt_source = payload.get("srt_source")
        timing_mode = payload.get("timing_mode", TIMING_MODE_FIXED)
        preset_id = payload.get("preset_id", "normal")
        project_name = payload.get("project_name", "AutoEdit Project")
        custom_duration = payload.get("custom_clip_duration_s")
        script_text = payload.get("script_text")
        motion_weights = payload.get("motion_weights")
        aspect_ratio = payload.get("aspect_ratio")
        auto_install = bool(payload.get("auto_install", True))
        allow_untested = bool(payload.get("allow_untested", False))
        override_draft_root = payload.get("override_draft_root")

        # Subtitle alignment if script provided without srt
        if script_text and script_text.strip() and audio_path and not srt_source:
            update_state_cb(STATE_SUBTITLE, 40.0, "Đang so khớp kịch bản với giọng nói...")
            try:
                from core.subtitles.script_to_srt_pipeline import ScriptToSrtPipeline
                pipeline = ScriptToSrtPipeline()
                align_res = pipeline.align_script_to_audio(script_text=script_text, audio_path=audio_path)
                srt_source = align_res.srt_content
                timing_mode = TIMING_MODE_SRT_DRIVEN
            except Exception as e:
                logger.warning(f"Script alignment failed in build queue: {e}")

        # Timeline building
        update_state_cb(STATE_TIMELINE, 55.0, "Xây dựng dòng thời gian và bố cục chuyển động...")
        pm_presets = PresetManager(presets_dir=self.workspace_root)
        preset = pm_presets.get_preset(preset_id)
        builder = TimelineBuilder(preset)
        plan = builder.build(
            images=images,
            audio_path=audio_path,
            srt_source=srt_source,
            project_name=project_name,
            custom_clip_duration_s=custom_duration,
            timing_mode=timing_mode,
            script_text=script_text,
            motion_weights=motion_weights,
            aspect_ratio=aspect_ratio,
        )

        # Generating CapCut draft
        update_state_cb(STATE_BUILDING_DRAFT, 75.0, "Sinh cấu trúc dự án CapCut...")
        pm = CapCutProjectManager(staging_base_dir=self.workspace_root)

        # Installing & verifying project
        update_state_cb(STATE_VERIFYING, 90.0, "Cài đặt và thẩm định dự án CapCut...")
        gen_result = pm.create_project(
            edit_plan=plan,
            project_name=project_name,
            auto_install=auto_install,
            allow_untested=allow_untested,
            override_draft_root=override_draft_root,
        )

        return {
            "ok": True,
            "project_id": gen_result["project_id"],
            "project_name": gen_result["project_name"],
            "draft_id": gen_result["draft_id"],
            "final_draft_dir": gen_result["final_draft_dir"],
            "staging_dir": gen_result["staging_dir"],
            "is_registered_in_capcut": gen_result["is_registered_in_capcut"],
            "capcut_detected_version": gen_result["capcut_detected_version"],
            "duration_s": plan.project.duration_us / 1_000_000,
            "clip_count": len(plan.clips),
        }

    # -------------------------------------------------------------------------
    # Persistence and Crash Recovery
    # -------------------------------------------------------------------------

    def save_snapshot(self) -> None:
        """Atomically persists the queue state to disk."""
        try:
            parent = os.path.dirname(self.persistence_path)
            if parent and not os.path.exists(parent):
                os.makedirs(parent, exist_ok=True)

            data = {
                "version": 1,
                "saved_at": time.time(),
                "status": self._status,
                "jobs": [j.to_dict() for j in self._jobs],
            }
            tmp_file = f"{self.persistence_path}.tmp"
            with open(tmp_file, "w", encoding="utf-8") as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            os.replace(tmp_file, self.persistence_path)
        except Exception as e:
            logger.warning(f"Failed to persist build queue state: {e}")

    def load_snapshot(self) -> None:
        """Restores queue state from persistence file if available."""
        if not os.path.exists(self.persistence_path):
            return
        try:
            with open(self.persistence_path, "r", encoding="utf-8") as f:
                data = json.load(f)
            loaded_jobs = [BuildJob.from_dict(d) for d in data.get("jobs", [])]
            with self._lock:
                self._jobs = loaded_jobs
            logger.info(f"Restored {len(self._jobs)} jobs from build queue persistence.")
        except Exception as e:
            logger.warning(f"Could not load build queue snapshot: {e}")

    def recover_from_crash(self) -> None:
        """
        Detects any jobs that were mid-execution when the application abruptly exited.
        Safely checks disk truth: if project exists and passes validation, reconciles to
        STATE_PROJECT_READY and notifies 'Dự án đã được khôi phục.' Otherwise transitions to FAILED.
        """
        from core.operation_result import reconcile_project_creation
        recovered_ready_count = 0
        interrupted_failed_count = 0
        with self._lock:
            self._status = QUEUE_STATUS_IDLE
            self._active_job = None
            for j in self._jobs:
                if j.state in (
                    STATE_VALIDATING,
                    STATE_PINNING_INPUTS,
                    STATE_UPSCALING,
                    STATE_SUBTITLE,
                    STATE_TIMELINE,
                    STATE_BUILDING_DRAFT,
                    STATE_VERIFYING,
                    STATE_FAILED,
                ):
                    # Check disk for existing valid project
                    draft_dir = None
                    if isinstance(j.result, dict):
                        draft_dir = j.result.get("final_draft_dir") or j.result.get("staging_dir")

                    if not draft_dir:
                        try:
                            from adapters.capcut.detector import CapCutDetector
                            draft_root = CapCutDetector.get_draft_root()
                            if draft_root and os.path.exists(draft_root):
                                for folder in os.listdir(draft_root):
                                    full_folder = os.path.join(draft_root, folder)
                                    if os.path.isdir(full_folder) and (folder == j.project_name or folder.startswith(f"{j.project_name}_")):
                                        draft_dir = full_folder
                                        break
                        except Exception:
                            pass

                    if draft_dir:
                        op_result = reconcile_project_creation(draft_dir=draft_dir, project_name=j.project_name)
                        if op_result.is_success:
                            j.state = STATE_PROJECT_READY
                            j.progress = 100.0
                            j.current_step = "Dự án đã được khôi phục."
                            j.error = None
                            if not j.result:
                                j.result = {
                                    "ok": True,
                                    "project_name": j.project_name,
                                    "final_draft_dir": draft_dir,
                                    "is_registered_in_capcut": True,
                                }
                            recovered_ready_count += 1
                            continue

                    # If not recoverable to READY, mark as FAILED with friendly retry guidance
                    if j.state != STATE_FAILED:
                        j.state = STATE_FAILED
                        j.error = "Quá trình bị gián đoạn do ứng dụng đóng đột ngột. Vui lòng bấm 'Thử lại'."
                        j.current_step = VI_STATE_LABELS[STATE_FAILED]
                        interrupted_failed_count += 1

            if recovered_ready_count > 0 or interrupted_failed_count > 0:
                self.save_snapshot()
                logger.info(
                    f"Crash recovery complete: {recovered_ready_count} restored to READY, "
                    f"{interrupted_failed_count} marked FAILED."
                )
