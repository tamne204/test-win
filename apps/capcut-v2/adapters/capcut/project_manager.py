"""
apps/capcut-v2/adapters/capcut/project_manager.py
Manages project workspaces, transactional registration, file locking,
conflict detection, and rollback into CapCut Desktop's draft directory.
"""
from __future__ import annotations

import os
import re
import json
import time
import shutil
import uuid
import hashlib
import contextlib
from typing import Dict, Any, Optional, List

try:
    import fcntl
    HAS_FCNTL = True
except ImportError:
    fcntl = None
    HAS_FCNTL = False

try:
    from ...core.edit_plan import EditPlan
    from .adapter import CapCutAdapter
    from .detector import CapCutDetector
    from .validator import CapCutDraftValidator
except (ImportError, ValueError):
    from core.edit_plan import EditPlan
    from adapters.capcut.adapter import CapCutAdapter
    from adapters.capcut.detector import CapCutDetector
    from adapters.capcut.validator import CapCutDraftValidator

# Normalized Error Codes
ERR_INDEX_CONFLICT = "CAPCUT_PROJECT_INDEX_CONFLICT"
ERR_ROLLBACK = "CAPCUT_PROJECT_INSTALL_ROLLBACK"
ERR_VALIDATION_FAILED = "CAPCUT_DRAFT_VALIDATION_FAILED"

# Project Statuses
STATUS_DRAFT = "DRAFT"
STATUS_BUILDING_TIMELINE = "BUILDING_TIMELINE"
STATUS_VALIDATING_EDIT_PLAN = "VALIDATING_EDIT_PLAN"
STATUS_GENERATING_DRAFT = "GENERATING_DRAFT"
STATUS_VALIDATING_DRAFT = "VALIDATING_DRAFT"
STATUS_INSTALLING_DRAFT = "INSTALLING_DRAFT"
STATUS_READY = "READY"
STATUS_OPENED_IN_CAPCUT = "OPENED_IN_CAPCUT"
STATUS_ERROR = "ERROR"


@contextlib.contextmanager
def file_lock(file_path: str, timeout_sec: float = 5.0):
    """
    Cross-platform cooperative file lock.
    Uses fcntl.flock on POSIX, and .lock file polling on Windows.
    """
    lock_file = f"{file_path}.lock"
    start_time = time.time()

    if HAS_FCNTL:
        lock_fd = os.open(lock_file, os.O_CREAT | os.O_RDWR)
        try:
            while True:
                try:
                    fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except (IOError, OSError):
                    if time.time() - start_time > timeout_sec:
                        raise TimeoutError(f"Timed out waiting for file lock on {lock_file}")
                    time.sleep(0.05)
            yield
        finally:
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_UN)
            except Exception:
                pass
            os.close(lock_fd)
            try:
                if os.path.exists(lock_file):
                    os.unlink(lock_file)
            except Exception:
                pass
    else:
        # Fallback lockfile polling for Windows
        while True:
            try:
                fd = os.open(lock_file, os.O_CREAT | os.O_EXCL | os.O_RDWR)
                os.close(fd)
                break
            except OSError:
                if time.time() - start_time > timeout_sec:
                    raise TimeoutError(f"Timed out waiting for file lock on {lock_file}")
                time.sleep(0.05)
        try:
            yield
        finally:
            try:
                if os.path.exists(lock_file):
                    os.unlink(lock_file)
            except Exception:
                pass


class CapCutProjectManager:
    """
    Handles project creation in isolated staging workspaces,
    then securely installs and registers them into CapCut Desktop's draft store.
    """

    def __init__(
        self,
        staging_base_dir: Optional[str] = None,
        workspace_root: Optional[str] = None,
    ):
        self.detector = CapCutDetector()
        self.status = self.detector.detect()
        self.staging_base_dir = staging_base_dir or workspace_root or os.path.abspath("projects_capcut")
        os.makedirs(self.staging_base_dir, exist_ok=True)

    def create_project(self, *args, **kwargs) -> Dict[str, Any]:
        """Convenience alias for create_and_register_project."""
        return self.create_and_register_project(*args, **kwargs)

    def create_and_register_project(
        self,
        edit_plan: EditPlan,
        install_to_capcut: bool = True,
        auto_install: Optional[bool] = None,
        allow_untested: bool = False,
        project_name: Optional[str] = None,
        override_draft_root: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Transactional project generation & installation:
        1. VALIDATING_EDIT_PLAN: Run EditPlan.validate().
        2. GENERATING_DRAFT: Generate in isolated staging workspace.
        3. VALIDATING_DRAFT: Run CapCutDraftValidator on staged draft.
        4. INSTALLING_DRAFT: If install_to_capcut=True:
           - Acquire file lock on root_meta_info.json.
           - Check conflict detection.
           - Backup root_meta_info.json.
           - Copy project folder.
           - Atomic write root_meta_info.json with fsync.
           - Run CapCutDraftValidator on installed draft.
        5. READY: Record project.json manifest.
        6. On failure: ROLLBACK and cleanup.
        """
        if auto_install is not None:
            install_to_capcut = auto_install
        if project_name:
            edit_plan.project.name = project_name

        draft_root_target = override_draft_root or self.status.draft_root_path

        project_id = str(uuid.uuid4()).upper()
        now_ts = int(time.time())
        project_slug = re.sub(r'[^A-Za-z0-9_\-]', '_', edit_plan.project.name.strip()) or "project"
        folder_name = f"2toolne_{now_ts}_{project_slug}"

        # Setup structured workspace: projects_capcut/<project_id>/
        project_workspace = os.path.join(self.staging_base_dir, project_id)
        staging_dir = os.path.join(project_workspace, "generated", folder_name)
        metadata_dir = os.path.join(project_workspace, "metadata")
        os.makedirs(staging_dir, exist_ok=True)
        os.makedirs(metadata_dir, exist_ok=True)

        manifest_path = os.path.join(metadata_dir, "project.json")
        manifest: Dict[str, Any] = {
            "project_id": project_id,
            "display_name": edit_plan.project.name,
            "created_at": now_ts,
            "updated_at": now_ts,
            "edit_plan_version": "1.0",
            "preset_id": edit_plan.metadata.get("preset_name", "basic"),
            "status": STATUS_DRAFT,
            "capcut_detected_version": self.status.detected_version,
            "staging_dir": staging_dir,
            "installed_draft_dir": None,
            "media_mapping": {},
        }
        self._write_manifest(manifest_path, manifest)

        installed_dir: Optional[str] = None
        backup_meta_path: Optional[str] = None
        root_meta_path: Optional[str] = None

        try:
            # Step 1: Validate EditPlan
            manifest["status"] = STATUS_VALIDATING_EDIT_PLAN
            self._write_manifest(manifest_path, manifest)
            edit_plan_errors = edit_plan.validate()
            if edit_plan_errors:
                raise ValueError(f"EditPlan validation failed: {'; '.join(edit_plan_errors)}")

            # Step 2: Generate Draft in Staging
            manifest["status"] = STATUS_GENERATING_DRAFT
            self._write_manifest(manifest_path, manifest)

            adapter = CapCutAdapter(target_version=self.status.detected_version or "9.3.0")
            draft_root = draft_root_target or os.path.dirname(staging_dir)
            gen_result = adapter.generate(
                edit_plan=edit_plan,
                target_dir=staging_dir,
                draft_root_path=draft_root,
                allow_untested=allow_untested or bool(override_draft_root),
            )

            # Step 3: Validate Staged Draft
            manifest["status"] = STATUS_VALIDATING_DRAFT
            self._write_manifest(manifest_path, manifest)
            staged_errors = CapCutDraftValidator.validate_draft(staging_dir)
            if staged_errors:
                raise RuntimeError(f"{ERR_VALIDATION_FAILED} in staging: {'; '.join(staged_errors)}")

            # Step 4: Install to CapCut Drafts (Transactional)
            if install_to_capcut and draft_root_target:
                # Check version compatibility gate (bypass if override_draft_root provided)
                if not override_draft_root and self.status.status != "CAPCUT_VERSION_SUPPORTED" and not allow_untested:
                    raise RuntimeError(
                        f"Installation blocked: CapCut version '{self.status.detected_version}' is not VERIFIED."
                    )

                manifest["status"] = STATUS_INSTALLING_DRAFT
                self._write_manifest(manifest_path, manifest)

                capcut_draft_dir = os.path.join(draft_root_target, folder_name)
                if os.path.exists(capcut_draft_dir):
                    raise FileExistsError(f"Safety violation: Target draft path already exists: {capcut_draft_dir}")

                root_meta_path = os.path.join(draft_root_target, "root_meta_info.json")

                # Perform locked atomic installation
                with file_lock(root_meta_path, timeout_sec=5.0):
                    # Copy project directory
                    shutil.copytree(staging_dir, capcut_draft_dir)
                    installed_dir = capcut_draft_dir

                    # Remap all paths inside installed draft to capcut_draft_dir
                    self._remap_paths_to_installed(capcut_draft_dir, staging_dir)

                    # Update root_meta_info with conflict detection & backup
                    backup_meta_path = self._register_in_root_meta_locked(
                        root_meta_path=root_meta_path,
                        draft_dir=capcut_draft_dir,
                        draft_id=gen_result["draft_id"],
                        project_name=edit_plan.project.name,
                        duration_us=edit_plan.project.duration_us,
                    )

                # Post-install validation on the installed folder
                installed_errors = CapCutDraftValidator.validate_draft(capcut_draft_dir)
                if installed_errors:
                    raise RuntimeError(f"{ERR_VALIDATION_FAILED} on installed draft: {'; '.join(installed_errors)}")

            # Step 5: Success
            manifest["status"] = STATUS_READY
            manifest["installed_draft_dir"] = installed_dir or staging_dir
            manifest["updated_at"] = int(time.time())
            self._write_manifest(manifest_path, manifest)

            return {
                "status": STATUS_READY,
                "project_id": project_id,
                "project_name": edit_plan.project.name,
                "draft_id": gen_result["draft_id"],
                "staging_dir": staging_dir,
                "final_draft_dir": installed_dir or staging_dir,
                "is_registered_in_capcut": bool(installed_dir),
                "capcut_detected_version": self.status.detected_version,
                "adapter_used": gen_result.get("adapter_used", "CapCutVersionAdapter_9_3"),
            }

        except Exception as exc:
            # ROLLBACK TRIGGER
            print(f"⚠️ [Rollback] {ERR_ROLLBACK} triggered: {exc}")
            manifest["status"] = STATUS_ERROR
            manifest["error"] = str(exc)
            self._write_manifest(manifest_path, manifest)

            # 1. Remove incomplete installed directory if created
            if installed_dir and os.path.exists(installed_dir):
                try:
                    shutil.rmtree(installed_dir, ignore_errors=True)
                    print(f"🧹 [Rollback] Removed incomplete draft: {installed_dir}")
                except Exception as e:
                    print(f"Error removing incomplete draft during rollback: {e}")

            # 2. Restore root_meta_info.json from backup if touched
            if backup_meta_path and root_meta_path and os.path.exists(backup_meta_path):
                try:
                    shutil.copy2(backup_meta_path, root_meta_path)
                    print(f"🔄 [Rollback] Restored root_meta_info.json from backup: {backup_meta_path}")
                except Exception as e:
                    print(f"Error restoring root_meta_info backup: {e}")

            raise exc

    def _register_in_root_meta_locked(
        self,
        root_meta_path: str,
        draft_dir: str,
        draft_id: str,
        project_name: str,
        duration_us: int,
    ) -> str:
        """
        Assumes file_lock is currently held.
        Reads latest state, creates backup, inserts draft, writes atomically with fsync.
        Returns the backup file path.
        """
        now_us = int(time.time() * 1_000_000)
        backup_path = f"{root_meta_path}.bak.{int(time.time())}"

        root_data: Dict[str, Any] = {
            "all_draft_store": [],
            "draft_ids": 0,
            "root_path": os.path.dirname(root_meta_path),
        }

        if os.path.isfile(root_meta_path):
            shutil.copy2(root_meta_path, backup_path)
            try:
                with open(root_meta_path, "r", encoding="utf-8") as f:
                    root_data = json.load(f)
            except Exception as e:
                raise RuntimeError(f"Cannot parse root_meta_info.json: {e}")

        all_draft_store: list = root_data.get("all_draft_store", [])

        # Build entry
        new_entry = {
            "cloud_draft_cover": False,
            "cloud_draft_sync": False,
            "draft_cloud_last_action_download": False,
            "draft_cloud_purchase_info": "",
            "draft_cloud_template_id": "",
            "draft_cloud_tutorial_info": "",
            "draft_cloud_videocut_purchase_info": "",
            "draft_cover": "draft_cover.jpg",
            "draft_fold_path": draft_dir,
            "draft_id": draft_id,
            "draft_is_ai_shorts": False,
            "draft_is_cloud_temp_draft": False,
            "draft_is_infinite_canvas_draft": False,
            "draft_is_invisible": False,
            "draft_is_pippit_draft": False,
            "draft_is_web_article_video": False,
            "draft_json_file": os.path.join(draft_dir, "draft_info.json"),
            "draft_name": project_name,
            "draft_new_version": "",
            "draft_root_path": os.path.dirname(root_meta_path),
            "draft_timeline_materials_size": 1024,
            "draft_type": "",
            "draft_web_article_video_enter_from": "",
            "streaming_edit_draft_ready": True,
            "tm_draft_cloud_completed": "",
            "tm_draft_cloud_entry_id": -1,
            "tm_draft_cloud_modified": 0,
            "tm_draft_cloud_parent_entry_id": -1,
            "tm_draft_cloud_space_id": -1,
            "tm_draft_cloud_user_id": -1,
            "tm_draft_create": now_us,
            "tm_draft_modified": now_us,
            "tm_draft_removed": 0,
            "tm_duration": duration_us,
        }

        # Filter duplicates and insert at beginning
        filtered = [d for d in all_draft_store if d.get("draft_id") != draft_id]
        filtered.insert(0, new_entry)
        root_data["all_draft_store"] = filtered

        # Atomic write with fsync
        temp_file = f"{root_meta_path}.tmp.{os.getpid()}"
        with open(temp_file, "w", encoding="utf-8") as f:
            json.dump(root_data, f, indent=2, ensure_ascii=False)
            f.flush()
            os.fsync(f.fileno())

        os.replace(temp_file, root_meta_path)
        return backup_path

    def _write_manifest(self, path: str, data: Dict[str, Any]):
        """Safely write project.json manifest."""
        temp_m = f"{path}.tmp"
        with open(temp_m, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        os.replace(temp_m, path)

    def _remap_paths_to_installed(self, installed_dir: str, staging_dir: str) -> None:
        """
        Remap all staging paths to installed target paths in draft_info.json and draft_meta_info.json.
        Ensures CapCut can resolve all media assets without unlinked/missing file errors.
        Handles JSON-escaped backslashes, raw backslashes, and forward slashes on Windows & Unix.
        """
        staging_norm = os.path.normpath(staging_dir)
        installed_norm = os.path.normpath(installed_dir)

        # Variations to replace:
        # 1. JSON-escaped backslashes (e.g. "C:\\Users\\...")
        # 2. Raw backslashes (e.g. "C:\Users\...")
        # 3. Forward slashes (e.g. "C:/Users/...")
        pairs = [
            (staging_norm.replace("\\", "\\\\"), installed_norm.replace("\\", "\\\\")),
            (staging_norm, installed_norm),
            (staging_dir.replace("\\", "/"), installed_dir.replace("\\", "/")),
        ]

        for file_name in ("draft_info.json", "draft_meta_info.json"):
            target_path = os.path.join(installed_dir, file_name)
            if os.path.isfile(target_path):
                try:
                    with open(target_path, "r", encoding="utf-8") as f:
                        content = f.read()
                    for src, dst in pairs:
                        content = content.replace(src, dst)
                    with open(target_path, "w", encoding="utf-8") as f:
                        f.write(content)
                except Exception as e:
                    print(f"Warning remapping {file_name}: {e}")

