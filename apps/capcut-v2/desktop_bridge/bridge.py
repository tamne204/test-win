"""
apps/capcut-v2/desktop_bridge/bridge.py
DesktopBridge dispatches IPC requests to the underlying V2 Python core.
Encapsulates TimelineBuilder, RuleEngine, PresetManager, CapCutDetector,
CapCutProjectManager, and CapCutLauncher.
"""
from __future__ import annotations

import os
import sys
import uuid
import platform
import traceback
import threading
from typing import Dict, Any, Optional, Callable, List

# Add parent path to resolve core and adapters
V2_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if V2_ROOT not in sys.path:
    sys.path.insert(0, V2_ROOT)

from capcut_version import get_version, get_app_name, get_product_id
from core.edit_plan import EditPlan
from core.rule_engine import RuleEngine, ALL_SUPPORTED_MOTIONS
from core.preset_manager import PresetManager, RulePreset, PRESET_BASIC_SLIDESHOW
from core.timeline_builder import TimelineBuilder, TIMING_MODE_FIXED, TIMING_MODE_SRT_DRIVEN
from core.build_queue_manager import ProjectBuildQueueManager
from adapters.capcut.detector import CapCutDetector, STATUS_SUPPORTED, STATUS_UNTESTED
from adapters.capcut.project_manager import CapCutProjectManager, STATUS_READY
from adapters.capcut.launcher import CapCutLauncher
from adapters.capcut.validator import CapCutDraftValidator
from adapters.capcut.render_profile import RenderProfileRegistry, WINDOWS_CAPCUT_9_3_0_3970
from adapters.capcut.render_job import RenderJob
from adapters.capcut.render_queue_manager import RenderQueueManager
from adapters.capcut.version_guard import CapCutVersionGuard
from core.operation_result import (
    OperationResult,
    reconcile_project_creation,
    OUTCOME_SUCCESS,
    OUTCOME_SUCCESS_WITH_WARNING,
    OUTCOME_FAILED,
)

from core.security import (
    LicenseGuard,
    LicenseEntitlementError,
    get_license_guard,
    sanitize_diagnostics,
)
from core.subtitles import (
    ScriptToSrtPipeline,
    ScriptToSrtError,
    AlignmentOptions,
    generate_srt,
    validate_srt_content,
)

from .protocol import (
    PROTOCOL_VERSION,
    ERR_METHOD_NOT_FOUND,
    ERR_INVALID_PARAMS,
    ERR_CAPCUT_NOT_FOUND,
    ERR_CAPCUT_VERSION_UNTESTED,
    ERR_CAPCUT_VERSION_UNSUPPORTED,
    ERR_CAPCUT_PROJECT_INDEX_CONFLICT,
    ERR_CAPCUT_DRAFT_VALIDATION_FAILED,
    ERR_SOURCE_MEDIA_MISSING,
    ERR_EDIT_PLAN_INVALID,
    ERR_SIDECAR_INTERNAL_ERROR,
    ERR_LICENSE_NOT_ACTIVATED,
    ERR_SCRIPT_EMPTY,
    ERR_AUDIO_MISSING,
    ERR_ASR_FAILED,
    ERR_ASR_RUNTIME_INCOMPLETE,
    ERR_AUDIO_UNREADABLE,
    ERR_ASR_MODEL_MISSING,
    ERR_SCRIPT_ALIGNMENT_FAILED,
    ERR_SCRIPT_ALIGNMENT_LOW_CONFIDENCE,
    ERR_SRT_GENERATION_FAILED,
    create_response,
    create_error,
    create_notification,
)


COMMERCIAL_METHODS = {
    "GET_PRESETS",
    "SAVE_CUSTOM_PRESET",
    "DELETE_CUSTOM_PRESET",
    "VALIDATE_INPUTS",
    "BUILD_EDIT_PLAN",
    "GENERATE_CAPCUT_PROJECT",
    "OPEN_CAPCUT",
    "GET_PROJECT_STATUS",
    "GENERATE_SRT_FROM_SCRIPT",
    "GET_SUBTITLE_ALIGNMENT_STATUS",
    "CANCEL_SUBTITLE_ALIGNMENT",
    "EXPORT_SRT",
    "RENDER_NOW",
    "ENQUEUE_RENDER",
    "GET_RENDER_QUEUE_STATE",
    "CONTROL_RENDER_QUEUE",
    "GET_RENDER_PROFILE",
    "ENQUEUE_BUILD_JOB",
    "GET_BUILD_QUEUE_STATE",
    "BUILD_PROJECT_JOB",
    "BUILD_ALL_PROJECTS",
    "CANCEL_BUILD_JOB",
    "RETRY_BUILD_JOB",
    "REMOVE_BUILD_JOB",
    "CLEAR_COMPLETED_BUILD_JOBS",
}


class DesktopBridge:
    """
    Handles dispatching of JSON-RPC requests from the Electron Main Process.
    """

    def __init__(
        self,
        workspace_root: Optional[str] = None,
        user_presets_dir: Optional[str] = None,
        notification_callback: Optional[Callable[[Dict[str, Any]], None]] = None,
        license_guard: Optional[LicenseGuard] = None,
        render_queue_manager: Optional[RenderQueueManager] = None,
        build_queue_manager: Optional[ProjectBuildQueueManager] = None,
    ):
        self.workspace_root = workspace_root or self._get_default_workspace_dir()
        self.user_presets_dir = user_presets_dir or self._get_default_presets_dir()
        self.preset_manager = PresetManager(user_presets_dir=self.user_presets_dir)
        self.detector = CapCutDetector()
        self.notification_callback = notification_callback
        self.license_guard = license_guard or get_license_guard()
        self.render_queue_manager = render_queue_manager or RenderQueueManager()
        self.render_queue_manager.add_listener(self._on_render_queue_update)
        self.build_queue_manager = build_queue_manager or ProjectBuildQueueManager(
            workspace_root=self.workspace_root,
            generator_fn=self._generate_capcut_project_core,
        )
        self.build_queue_manager.add_listener(self._on_build_queue_update)
        self._subtitle_cancel_event = threading.Event()
        self._last_alignment_result = None
        os.makedirs(self.workspace_root, exist_ok=True)
        os.makedirs(self.user_presets_dir, exist_ok=True)

    def _on_render_queue_update(self, state: Dict[str, Any]) -> None:
        self.notify("render_queue_update", state)

    def _on_build_queue_update(self, state: Dict[str, Any]) -> None:
        self.notify("build_queue_update", state)

    @staticmethod
    def _get_default_workspace_dir() -> str:
        if sys.platform == "darwin":
            base = os.path.expanduser("~/Library/Application Support/2toolne AutoEdit/projects")
        elif sys.platform.startswith("win"):
            base = os.path.join(os.environ.get("APPDATA", "C:\\Users\\Default\\AppData\\Roaming"), "2toolne AutoEdit", "projects")
        else:
            base = os.path.expanduser("~/.2toolne/autoedit-capcut/projects")
        return os.path.abspath(base)

    @staticmethod
    def _get_default_presets_dir() -> str:
        if sys.platform == "darwin":
            base = os.path.expanduser("~/Library/Application Support/2toolne AutoEdit/presets")
        elif sys.platform.startswith("win"):
            base = os.path.join(os.environ.get("APPDATA", "C:\\Users\\Default\\AppData\\Roaming"), "2toolne AutoEdit", "presets")
        else:
            base = os.path.expanduser("~/.2toolne/autoedit-capcut/presets")
        return os.path.abspath(base)

    def notify(self, event: str, data: Any):
        if self.notification_callback:
            msg = create_notification(event, data)
            self.notification_callback(msg)

    def dispatch(self, req: Dict[str, Any]) -> Dict[str, Any]:
        """
        Main entry point for request routing. Returns response dictionary.
        """
        req_id = req.get("id", str(uuid.uuid4()))
        method = req.get("method", "").upper()
        params = req.get("params") or {}

        try:
            # Commercial License Gate (Sections 13, 14, 15)
            # Must deny unauthorized execution even if invoked directly via CLI
            if method in COMMERCIAL_METHODS:
                try:
                    self.license_guard.require_entitlement(
                        product="2toolne.capcut.v2",
                        feature="capcut_autoedit"
                    )
                except LicenseEntitlementError as lic_err:
                    return create_error(
                        req_id,
                        lic_err.state,
                        lic_err.message,
                        data=lic_err.details,
                    )

            handler_map = {
                "PING": self._handle_ping,
                "GET_APP_INFO": self._handle_get_app_info,
                "DETECT_CAPCUT": self._handle_detect_capcut,
                "GET_LICENSE_STATUS": self._handle_get_license_status,
                "INSTALL_SIGNED_ENTITLEMENT": self._handle_install_signed_entitlement,
                "CLEAR_LICENSE": self._handle_clear_license,
                "GET_PRESETS": self._handle_get_presets,
                "SAVE_CUSTOM_PRESET": self._handle_save_custom_preset,
                "DELETE_CUSTOM_PRESET": self._handle_delete_custom_preset,
                "VALIDATE_INPUTS": self._handle_validate_inputs,
                "BUILD_EDIT_PLAN": self._handle_build_edit_plan,
                "GENERATE_CAPCUT_PROJECT": self._handle_generate_capcut_project,
                "OPEN_CAPCUT": self._handle_open_capcut,
                "GET_PROJECT_STATUS": self._handle_get_project_status,
                "GET_DIAGNOSTICS": self._handle_get_diagnostics,
                "GENERATE_SRT_FROM_SCRIPT": self._handle_generate_srt_from_script,
                "GET_SUBTITLE_ALIGNMENT_STATUS": self._handle_get_subtitle_alignment_status,
                "CANCEL_SUBTITLE_ALIGNMENT": self._handle_cancel_subtitle_alignment,
                "EXPORT_SRT": self._handle_export_srt,
                "RENDER_NOW": self._handle_render_now,
                "ENQUEUE_RENDER": self._handle_enqueue_render,
                "GET_RENDER_QUEUE_STATE": self._handle_get_render_queue_state,
                "CONTROL_RENDER_QUEUE": self._handle_control_render_queue,
                "GET_RENDER_PROFILE": self._handle_get_render_profile,
                "ENQUEUE_BUILD_JOB": self._handle_enqueue_build_job,
                "GET_BUILD_QUEUE_STATE": self._handle_get_build_queue_state,
                "BUILD_PROJECT_JOB": self._handle_build_project_job,
                "BUILD_ALL_PROJECTS": self._handle_build_all_projects,
                "CANCEL_BUILD_JOB": self._handle_cancel_build_job,
                "RETRY_BUILD_JOB": self._handle_retry_build_job,
                "REMOVE_BUILD_JOB": self._handle_remove_build_job,
                "CLEAR_COMPLETED_BUILD_JOBS": self._handle_clear_completed_build_jobs,
            }

            handler = handler_map.get(method)
            if not handler:
                return create_error(
                    req_id,
                    ERR_METHOD_NOT_FOUND,
                    f"Unknown IPC method: '{method}'",
                )

            result = handler(params)
            return create_response(req_id, result)

        except ScriptToSrtError as srt_err:
            return create_error(req_id, srt_err.code, srt_err.message)
        except ValueError as val_err:
            return create_error(req_id, ERR_INVALID_PARAMS, str(val_err))
        except Exception as exc:
            # Format clean error without raw traceback in primary message
            err_msg = str(exc)
            err_code = ERR_SIDECAR_INTERNAL_ERROR
            if (
                "silero_vad" in err_msg
                or "onnxruntimeerror" in err_msg.lower()
                or ("no_suchfile" in err_msg.lower() and "onnx" in err_msg.lower())
                or ("file doesn't exist" in err_msg.lower() and "onnx" in err_msg.lower())
            ):
                err_code = ERR_ASR_RUNTIME_INCOMPLETE
                err_msg = (
                    "Thành phần nhận dạng giọng nói bị thiếu hoặc chưa được cài đặt đầy đủ. "
                    "Vui lòng cài lại hoặc cập nhật 2TOOLNE AutoEdit."
                )
            elif "validation failed" in err_msg.lower():
                err_code = ERR_EDIT_PLAN_INVALID
            elif "not found" in err_msg.lower() and "capcut" in err_msg.lower():
                err_code = ERR_CAPCUT_NOT_FOUND
            elif "conflict" in err_msg.lower():
                err_code = ERR_CAPCUT_PROJECT_INDEX_CONFLICT

            return create_error(
                req_id,
                err_code,
                err_msg,
                data={"traceback": traceback.format_exc()},
            )

    # --------------------------------------------------------------------------
    # Command Handlers
    # --------------------------------------------------------------------------

    def _handle_ping(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return {
            "pong": True,
            "protocol": PROTOCOL_VERSION,
            "version": get_version(),
            "product_id": get_product_id(),
        }

    def _handle_get_app_info(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return {
            "product_id": get_product_id(),
            "app_name": get_app_name(),
            "version": get_version(),
            "platform": sys.platform,
            "python_version": platform.python_version(),
            "workspace_root": self.workspace_root,
            "user_presets_dir": self.user_presets_dir,
        }

    def _handle_detect_capcut(self, params: Dict[str, Any]) -> Dict[str, Any]:
        status = self.detector.detect()
        return status.to_dict()

    def _handle_get_license_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self.license_guard.get_public_status()

    def _handle_install_signed_entitlement(self, params: Dict[str, Any]) -> Dict[str, Any]:
        envelope = params.get("envelope")
        if not envelope or not isinstance(envelope, dict):
            raise ValueError("Parameter 'envelope' is required and must be a dictionary.")
        trusted_server_time = params.get("trusted_server_time")
        masked_key = params.get("masked_key")
        license_key_last4 = params.get("license_key_last4")
        self.license_guard.set_envelope(
            envelope,
            trusted_server_time=trusted_server_time,
            masked_key=masked_key,
            license_key_last4=license_key_last4,
        )
        return self.license_guard.get_public_status()

    def _handle_clear_license(self, params: Dict[str, Any]) -> Dict[str, Any]:
        self.license_guard.clear_entitlement()
        return {"cleared": True, "status": self.license_guard.get_public_status()}

    def _handle_get_presets(self, params: Dict[str, Any]) -> Dict[str, Any]:
        presets = self.preset_manager.list_presets()
        return {
            "presets": [p.to_dict() for p in presets],
            "default_id": "basic_slideshow",
        }

    def _handle_save_custom_preset(self, params: Dict[str, Any]) -> Dict[str, Any]:
        name = params.get("name")
        if not name:
            raise ValueError("Preset name is required.")

        scene_duration_s = float(params.get("scene_duration_s", 5.0))
        motion_sequence = params.get("motion_sequence") or [
            "ZOOM_IN", "ZOOM_OUT", "PAN_LEFT", "PAN_RIGHT"
        ]
        canvas_ratio = params.get("canvas_ratio", "9:16")
        fps = float(params.get("fps", 60.0))
        caption_pos_y = float(params.get("caption_position_y", -0.6))
        music_vol = float(params.get("music_volume", 1.0))

        preset = self.preset_manager.save_custom_preset(
            name_or_preset=name,
            scene_duration_s=scene_duration_s,
            motion_sequence=motion_sequence,
            canvas_ratio=canvas_ratio,
            fps=fps,
            caption_position_y=caption_pos_y,
            audio_volume=music_vol,
        )
        return {"saved": True, "preset": preset.to_dict()}

    def _handle_delete_custom_preset(self, params: Dict[str, Any]) -> Dict[str, Any]:
        preset_id = params.get("preset_id")
        if not preset_id:
            raise ValueError("preset_id is required.")
        deleted = self.preset_manager.delete_custom_preset(preset_id)
        return {"deleted": deleted, "preset_id": preset_id}

    def _handle_validate_inputs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        images: List[str] = params.get("images", [])
        audio_path: Optional[str] = params.get("audio_path")
        srt_source: Optional[str] = params.get("srt_source")

        errors: List[str] = []
        if not images:
            errors.append("Vui lòng chọn ít nhất một tệp hình ảnh.")
        else:
            for img in images:
                if not os.path.exists(img):
                    errors.append(f"Không tìm thấy tệp ảnh trên đĩa: '{img}'")

        if audio_path and not os.path.exists(audio_path):
            errors.append(f"Không tìm thấy tệp âm thanh trên đĩa: '{audio_path}'")

        if srt_source and os.path.exists(srt_source) and not os.path.isfile(srt_source):
            errors.append(f"Đường dẫn SRT không hợp lệ: '{srt_source}'")

        return {
            "valid": len(errors) == 0,
            "errors": errors,
            "image_count": len(images),
            "has_audio": bool(audio_path),
            "has_srt": bool(srt_source),
        }

    def _handle_build_edit_plan(self, params: Dict[str, Any]) -> Dict[str, Any]:
        images = params.get("images", [])
        audio_path = params.get("audio_path")
        srt_source = params.get("srt_source")
        timing_mode = params.get("timing_mode", TIMING_MODE_FIXED)
        preset_id = params.get("preset_id", "basic_slideshow")
        project_name = params.get("project_name", "2TOOLNE AutoEdit Project")
        custom_duration = params.get("custom_clip_duration_s")
        script_text = params.get("script_text")
        motion_weights = params.get("motion_weights")
        aspect_ratio = params.get("aspect_ratio")

        preset = self.preset_manager.get_preset(preset_id)
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

        val_errors = plan.validate(check_files_exist=True)
        if val_errors:
            raise ValueError(f"EditPlan không hợp lệ: {'; '.join(val_errors)}")

        return plan.to_dict()

    def _generate_capcut_project_core(
        self,
        params: Dict[str, Any],
        progress_cb: Optional[Callable[[str, float, str], None]] = None,
    ) -> Dict[str, Any]:
        """
        Executes complete transactional project creation and installation.
        Emits progress notifications to progress_cb and global IPC notification.
        """
        def report(stage: str, frac: float, msg: str):
            if progress_cb:
                progress_cb(stage, frac, msg)
            self.notify("progress", {"stage": stage, "percent": int(frac * 100), "message": msg})

        images = params.get("images", [])
        audio_path = params.get("audio_path")
        srt_source = params.get("srt_source")
        timing_mode = params.get("timing_mode", TIMING_MODE_FIXED)
        preset_id = params.get("preset_id", "normal")
        project_name = params.get("project_name", "2TOOLNE AutoEdit Project")
        custom_duration = params.get("custom_clip_duration_s")
        auto_install = bool(params.get("auto_install", True))
        allow_untested = bool(params.get("allow_untested", False))
        override_draft_root = params.get("override_draft_root")
        script_text = params.get("script_text")
        motion_weights = params.get("motion_weights")
        aspect_ratio = params.get("aspect_ratio")

        # Milestone 1: VALIDATING INPUT
        report("VALIDATING_INPUT", 0.15, "Kiểm tra tệp tin đầu vào...")
        val_res = self._handle_validate_inputs(params)
        if not val_res["valid"]:
            raise ValueError(f"Lỗi tệp tin: {'; '.join(val_res['errors'])}")

        # Milestone 1.5: SCRIPT-TO-AUDIO ALIGNMENT IF SCRIPT IS GIVEN WITHOUT SRT
        if script_text and script_text.strip() and audio_path and not srt_source:
            report("ALIGNING_SUBTITLES", 0.25, "Đang so khớp kịch bản với giọng nói...")
            try:
                pipeline = ScriptToSrtPipeline()
                align_res = pipeline.align_script_to_audio(script_text=script_text, audio_path=audio_path)
                srt_source = align_res.srt_content
                timing_mode = TIMING_MODE_SRT_DRIVEN
            except Exception as align_err:
                print(f"Warning: Script alignment failed: {align_err}. Continuing with fixed timing.")

        # Milestone 2: BUILDING TIMELINE
        report("BUILDING_TIMELINE", 0.35, "Xây dựng dòng thời gian và bố cục chuyển động...")
        preset = self.preset_manager.get_preset(preset_id)
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

        # Milestone 3: VALIDATING EDIT PLAN
        report("VALIDATING_EDIT_PLAN", 0.45, "Thẩm định cấu trúc dòng thời gian...")
        plan_errors = plan.validate(check_files_exist=True)
        if plan_errors:
            raise ValueError(f"EditPlan không hợp lệ: {'; '.join(plan_errors)}")

        # Milestone 4: GENERATING CAPCUT DRAFT
        report("GENERATING_CAPCUT_DRAFT", 0.65, "Sinh cấu trúc dự án CapCut trong staging...")
        pm = CapCutProjectManager(staging_base_dir=self.workspace_root)

        # Milestone 5: INSTALLING PROJECT
        report("INSTALLING_PROJECT", 0.85, "Cài đặt và khóa bảo vệ thư viện dự án CapCut...")
        try:
            gen_result = pm.create_project(
                edit_plan=plan,
                project_name=project_name,
                auto_install=auto_install,
                allow_untested=allow_untested,
                override_draft_root=override_draft_root,
            )
            final_draft = gen_result.get("final_draft_dir")
            op_result = reconcile_project_creation(
                draft_dir=final_draft,
                project_name=project_name,
                extra_data=gen_result,
            )
        except Exception as exc:
            # Post-operation artifact reconciliation: Was project actually written to disk?
            potential_draft = None
            target_root = override_draft_root or getattr(self.detector.status, "draft_root_path", None)
            if target_root and os.path.isdir(target_root):
                p_slug = (project_name or "").strip().replace(" ", "_")
                for d in os.listdir(target_root):
                    if p_slug and p_slug in d and os.path.isdir(os.path.join(target_root, d)):
                        potential_draft = os.path.join(target_root, d)
                        break

            op_result = reconcile_project_creation(
                draft_dir=potential_draft,
                project_name=project_name,
                exception_caught=exc,
            )
            if op_result.is_success:
                gen_result = {
                    "status": "READY",
                    "project_id": str(uuid.uuid4()).upper(),
                    "project_name": project_name,
                    "draft_id": str(uuid.uuid4()).upper(),
                    "final_draft_dir": potential_draft,
                    "staging_dir": potential_draft,
                    "is_registered_in_capcut": True,
                    "capcut_detected_version": getattr(self.detector.status, "detected_version", "9.4.0"),
                }
            else:
                raise exc

        # Milestone 6: READY
        report("READY", 1.0, op_result.primary_message)

        return {
            "ok": True,
            "status": "READY",
            "outcome": op_result.outcome,
            "primary_message": op_result.primary_message,
            "secondary_message": op_result.secondary_message,
            "user_action": op_result.user_action,
            "operation_result": op_result.to_dict(),
            "project_id": gen_result["project_id"],
            "project_name": gen_result["project_name"],
            "draft_id": gen_result["draft_id"],
            "final_draft_dir": gen_result["final_draft_dir"],
            "staging_dir": gen_result.get("staging_dir"),
            "is_registered_in_capcut": gen_result.get("is_registered_in_capcut", True),
            "capcut_detected_version": gen_result.get("capcut_detected_version"),
            "duration_s": plan.project.duration_us / 1_000_000,
            "clip_count": len(plan.clips),
        }

    def _handle_generate_capcut_project(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._generate_capcut_project_core(params)

    # -------------------------------------------------------------------------
    # Project Build Queue Handlers (Queue A)
    # -------------------------------------------------------------------------

    def _handle_enqueue_build_job(self, params: Dict[str, Any]) -> Dict[str, Any]:
        payload = params.get("payload") or params
        project_name = params.get("project_name") or payload.get("project_name")
        job = self.build_queue_manager.enqueue_job(payload, project_name)
        return {"ok": True, "job": job.to_dict()}

    def _handle_get_build_queue_state(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self.build_queue_manager.get_state()

    def _handle_build_project_job(self, params: Dict[str, Any]) -> Dict[str, Any]:
        job_id = params.get("job_id")
        if not job_id:
            raise ValueError("job_id is required")
        started = self.build_queue_manager.build_job(job_id)
        return {"ok": started}

    def _handle_build_all_projects(self, params: Dict[str, Any]) -> Dict[str, Any]:
        started = self.build_queue_manager.build_all()
        return {"ok": started}

    def _handle_cancel_build_job(self, params: Dict[str, Any]) -> Dict[str, Any]:
        job_id = params.get("job_id")
        if not job_id:
            raise ValueError("job_id is required")
        cancelled = self.build_queue_manager.cancel_job(job_id)
        return {"ok": cancelled}

    def _handle_retry_build_job(self, params: Dict[str, Any]) -> Dict[str, Any]:
        job_id = params.get("job_id")
        if not job_id:
            raise ValueError("job_id is required")
        retried = self.build_queue_manager.retry_job(job_id)
        return {"ok": retried}

    def _handle_remove_build_job(self, params: Dict[str, Any]) -> Dict[str, Any]:
        job_id = params.get("job_id")
        if not job_id:
            raise ValueError("job_id is required")
        removed = self.build_queue_manager.remove_job(job_id)
        return {"ok": removed}

    def _handle_clear_completed_build_jobs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        count = self.build_queue_manager.clear_completed()
        return {"ok": True, "cleared_count": count}

    def _handle_open_capcut(self, params: Dict[str, Any]) -> Dict[str, Any]:
        draft_path = params.get("draft_path")
        res = CapCutLauncher.launch(draft_path=draft_path)
        return res

    def _handle_get_project_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        project_id = params.get("project_id")
        if not project_id:
            raise ValueError("project_id is required.")

        meta_file = os.path.join(self.workspace_root, project_id, "metadata", "project.json")
        if not os.path.isfile(meta_file):
            raise FileNotFoundError(f"Project not found: {project_id}")

        import json
        with open(meta_file, "r", encoding="utf-8") as f:
            manifest = json.load(f)
        return manifest

    def _handle_get_diagnostics(self, params: Dict[str, Any]) -> Dict[str, Any]:
        capcut_status = self.detector.detect().to_dict()
        diag = {
            "version": get_version(),
            "product_id": get_product_id(),
            "platform": sys.platform,
            "os_release": platform.release(),
            "python_executable": sys.executable,
            "workspace_root": self.workspace_root,
            "workspace_exists": os.path.isdir(self.workspace_root),
            "capcut_status": capcut_status,
            "license_status": self.license_guard.get_public_status(),
        }
        return sanitize_diagnostics(diag)

    def _handle_generate_srt_from_script(self, params: Dict[str, Any]) -> Dict[str, Any]:
        script_text = params.get("script_text") or ""
        script_path = params.get("script_path")
        if script_path and os.path.isfile(script_path) and not script_text:
            with open(script_path, "r", encoding="utf-8-sig", errors="replace") as f:
                script_text = f.read()

        audio_path = params.get("audio_path") or ""
        language = params.get("language") or "AUTO"
        opts_dict = params.get("options") or {}
        mode = str(params.get("mode", "fa")).lower()
        allow_autosub = bool(params.get("allow_autosub")) or (mode in ("autosub", "stt"))

        options = AlignmentOptions(
            language=language,
            max_words_per_cue=int(opts_dict.get("max_words_per_cue", 12)),
            max_chars_per_cue=int(opts_dict.get("max_chars_per_cue", 80)),
            min_duration_s=float(opts_dict.get("min_duration_s", 0.6)),
            max_duration_s=float(opts_dict.get("max_duration_s", 5.0)),
            min_gap_s=float(opts_dict.get("min_gap_s", 0.05)),
            model_size=str(opts_dict.get("model_size", "base")),
            allow_degraded=bool(opts_dict.get("allow_degraded", True)),
        )

        self._subtitle_cancel_event.clear()
        pipeline = ScriptToSrtPipeline(options=options)

        def progress_cb(stage: str, frac: float, msg: str):
            self.notify("progress", {
                "stage": stage,
                "percent": int(frac * 100),
                "message": msg,
            })

        result = pipeline.run(
            script_text=script_text,
            audio_path=audio_path,
            progress_callback=progress_cb,
            cancellation_token=self._subtitle_cancel_event,
            allow_autosub=allow_autosub,
            mode=mode,
        )

        self._last_alignment_result = result
        return result.to_dict()

    def _handle_get_subtitle_alignment_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        if not hasattr(self, "_last_alignment_result") or self._last_alignment_result is None:
            return {"has_result": False}
        return {"has_result": True, "result": self._last_alignment_result.to_dict()}

    def _handle_cancel_subtitle_alignment(self, params: Dict[str, Any]) -> Dict[str, Any]:
        self._subtitle_cancel_event.set()
        return {"cancelled": True}

    def _handle_export_srt(self, params: Dict[str, Any]) -> Dict[str, Any]:
        srt_content = params.get("srt_content") or ""
        target_path = params.get("target_path") or ""

        if not target_path:
            raise ValueError("target_path is required.")
        if not srt_content:
            raise ValueError("srt_content is empty.")

        target_dir = os.path.dirname(os.path.abspath(target_path))
        os.makedirs(target_dir, exist_ok=True)

        with open(target_path, "w", encoding="utf-8") as f:
            f.write(srt_content)

        return {"ok": True, "exported_path": os.path.abspath(target_path)}

    def _handle_get_render_profile(self, params: Dict[str, Any]) -> Dict[str, Any]:
        profile_id = params.get("profile_id")
        if profile_id:
            profile = RenderProfileRegistry.get(profile_id)
            return profile.to_dict() if profile else {"error": "PROFILE_NOT_FOUND"}
        return {"profiles": [p.to_dict() for p in RenderProfileRegistry.get_all()]}

    def _handle_render_now(self, params: Dict[str, Any]) -> Dict[str, Any]:
        draft_path = params.get("draft_path")
        output_path = params.get("output_path")
        if not draft_path or not output_path:
            raise ValueError("draft_path and output_path are required.")

        default_pid = "macos_capcut_9_4_0" if sys.platform == "darwin" else "windows_capcut_9_3_0_3970"
        job = RenderJob(
            project_id=params.get("project_id", "manual_project"),
            draft_id=params.get("draft_id", "manual_draft"),
            draft_path=draft_path,
            output_path=output_path,
            output_filename=os.path.basename(output_path),
            render_profile_id=params.get("render_profile_id", default_pid),
            render_settings=params.get("render_settings", {}),
        )
        job_id = self.render_queue_manager.enqueue(job)
        return {"ok": True, "job_id": job_id, "status": "QUEUED"}

    def _handle_enqueue_render(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._handle_render_now(params)

    def _handle_get_render_queue_state(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self.render_queue_manager.get_state()

    def _handle_control_render_queue(self, params: Dict[str, Any]) -> Dict[str, Any]:
        action = params.get("action", "").lower()
        job_id = params.get("job_id")

        if action == "pause":
            self.render_queue_manager.pause()
            return {"ok": True, "action": "pause"}
        elif action == "resume":
            self.render_queue_manager.resume()
            return {"ok": True, "action": "resume"}
        elif action == "stop_after_current":
            self.render_queue_manager.stop_after_current()
            return {"ok": True, "action": "stop_after_current"}
        elif action == "cancel" and job_id:
            success = self.render_queue_manager.cancel_job(job_id)
            return {"ok": success, "action": "cancel", "job_id": job_id}
        elif action == "retry" and job_id:
            success = self.render_queue_manager.retry_job(job_id)
            return {"ok": success, "action": "retry", "job_id": job_id}
        elif action == "skip" and job_id:
            success = self.render_queue_manager.skip_job(job_id)
            return {"ok": success, "action": "skip", "job_id": job_id}
        elif action == "clear_completed":
            count = self.render_queue_manager.clear_completed()
            return {"ok": True, "action": "clear_completed", "cleared_count": count}
        else:
            raise ValueError(f"Unknown or invalid queue control action: '{action}'")
