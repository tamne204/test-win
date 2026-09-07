"""
apps/capcut-v2/adapters/capcut/native_exporter.py
Phase 5E.1 Single Project Native Export runner per Sections 29, 30, 36, 37, and 38.
Executes Case C keyboard + multi-signal heartbeat flow with zero user interaction.
"""
from __future__ import annotations

import os
import sys
import time
import subprocess
from typing import Dict, Any, Optional, Callable

try:
    from .render_profile import RenderProfile, WINDOWS_CAPCUT_9_3_0_3970
    from .output_verifier import OutputVerifier, VerificationResult
    from .ownership_manager import CapCutOwnershipManager
except (ImportError, ValueError):
    from adapters.capcut.render_profile import RenderProfile, WINDOWS_CAPCUT_9_3_0_3970
    from adapters.capcut.output_verifier import OutputVerifier, VerificationResult
    from adapters.capcut.ownership_manager import CapCutOwnershipManager


class AutomationDriver:
    """Interface for low-level OS window and input manipulation."""

    def find_and_activate_window(self, window_class: str, timeout_sec: float = 10.0) -> bool:
        raise NotImplementedError

    def send_shortcut(self, shortcut: str) -> bool:
        raise NotImplementedError

    def send_key(self, key_name: str) -> bool:
        raise NotImplementedError

    def is_process_alive(self, pid_or_name: str) -> bool:
        raise NotImplementedError


class Win32AutomationDriver(AutomationDriver):
    """Production driver executing Win32 API calls on Windows interactive desktop."""

    def find_and_activate_window(self, window_class: str, timeout_sec: float = 10.0) -> bool:
        if not sys.platform.startswith("win"):
            return False

        try:
            import win32gui
            import win32con

            deadline = time.time() + timeout_sec
            while time.time() < deadline:
                hwnd = win32gui.FindWindow(window_class, None)
                if hwnd and win32gui.IsWindowVisible(hwnd):
                    win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                    win32gui.SetForegroundWindow(hwnd)
                    time.sleep(0.3)
                    return True
                time.sleep(0.5)
            return False
        except Exception:
            return False

    def send_shortcut(self, shortcut: str) -> bool:
        if not sys.platform.startswith("win"):
            return False

        # e.g. Ctrl+E
        try:
            import win32api
            import win32con

            if shortcut.upper() == "CTRL+E":
                win32api.keybd_event(win32con.VK_CONTROL, 0, 0, 0)
                time.sleep(0.05)
                win32api.keybd_event(ord('E'), 0, 0, 0)
                time.sleep(0.05)
                win32api.keybd_event(ord('E'), 0, win32con.KEYEVENTF_KEYUP, 0)
                time.sleep(0.05)
                win32api.keybd_event(win32con.VK_CONTROL, 0, win32con.KEYEVENTF_KEYUP, 0)
                return True
        except Exception:
            pass
        return False

    def send_key(self, key_name: str) -> bool:
        if not sys.platform.startswith("win"):
            return False

        try:
            import win32api
            import win32con

            vk_map = {
                "ENTER": win32con.VK_RETURN,
                "ESCAPE": win32con.VK_ESCAPE,
                "TAB": win32con.VK_TAB,
                "SPACE": win32con.VK_SPACE,
            }
            vk = vk_map.get(key_name.upper())
            if vk:
                win32api.keybd_event(vk, 0, 0, 0)
                time.sleep(0.05)
                win32api.keybd_event(vk, 0, win32con.KEYEVENTF_KEYUP, 0)
                return True
        except Exception:
            pass
        return False

    def is_process_alive(self, pid_or_name: str) -> bool:
        # Check via tasklist or psutil
        try:
            res = subprocess.run(["tasklist", "/FI", f"IMAGENAME eq {pid_or_name}"], capture_output=True, text=True)
            return pid_or_name.lower() in res.stdout.lower()
        except Exception:
            return True


class MockAutomationDriver(AutomationDriver):
    """Mock driver for unit tests and non-Windows continuous integration."""

    def __init__(self, simulate_file_creation: bool = True):
        self.simulate_file_creation = simulate_file_creation
        self.actions_log: list = []
        self.should_activate_succeed: bool = True

    def find_and_activate_window(self, window_class: str, timeout_sec: float = 10.0) -> bool:
        self.actions_log.append(f"activate:{window_class}")
        return self.should_activate_succeed

    def send_shortcut(self, shortcut: str) -> bool:
        self.actions_log.append(f"shortcut:{shortcut}")
        return True

    def send_key(self, key_name: str) -> bool:
        self.actions_log.append(f"key:{key_name}")
        return True

    def on_confirm_export(self, output_path: str) -> None:
        if self.simulate_file_creation and not os.path.exists(output_path):
            candidates = [
                os.path.join(os.path.dirname(__file__), "sample_test.mp4"),
                os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "desktop", "physical_validator", "sample_test.mp4"),
                "/Users/2tamne/tool ffmpeg/test_out.mp4",
            ]
            sample_mp4 = next((c for c in candidates if os.path.isfile(c)), None)
            if sample_mp4:
                import shutil
                shutil.copy(sample_mp4, output_path)
            else:
                with open(output_path, "wb") as f:
                    f.write(b"MOCK_MP4_PAYLOAD")

    def is_process_alive(self, pid_or_name: str) -> bool:
        return True


class CapCutNativeExporter:
    """
    Orchestrates the unattended single-project native export flow.
    """

    def __init__(
        self,
        profile: Optional[RenderProfile] = None,
        driver: Optional[AutomationDriver] = None,
        progress_callback: Optional[Callable[[str, Dict[str, Any]], None]] = None,
    ):
        self.profile = profile or WINDOWS_CAPCUT_9_3_0_3970
        if driver:
            self.driver = driver
        elif sys.platform.startswith("win"):
            self.driver = Win32AutomationDriver()
        else:
            self.driver = MockAutomationDriver()

        self.progress_callback = progress_callback

    def _emit_stage(self, stage: str, data: Optional[Dict[str, Any]] = None) -> None:
        if self.progress_callback:
            try:
                self.progress_callback(stage, data or {})
            except Exception:
                pass

    def export_project(
        self,
        draft_path: str,
        expected_output_path: str,
        expected_duration_sec: Optional[float] = None,
        job_id: str = "single_export",
        timeout_sec: Optional[float] = None,
    ) -> Dict[str, Any]:
        """
        Execute Phase 5E.1 export sequence.
        """
        max_timeout = timeout_sec or self.profile.absolute_safety_timeout_sec
        stall_timeout = self.profile.stall_timeout_sec

        # 1. PRECHECK
        self._emit_stage("PRECHECK", {"draft_path": draft_path, "output_path": expected_output_path})
        if not os.path.isdir(draft_path):
            return {
                "ok": False,
                "error_code": "DRAFT_NOT_FOUND",
                "message": f"Draft folder not found: {draft_path}",
            }

        out_dir = os.path.dirname(expected_output_path)
        if out_dir and not os.path.exists(out_dir):
            os.makedirs(out_dir, exist_ok=True)

        # Acquire ownership
        if not CapCutOwnershipManager.acquire_for_queue(job_id):
            return {
                "ok": False,
                "error_code": "USER_INTERRUPTION",
                "message": "CapCut is currently being edited manually by user.",
            }

        try:
            # 2. ACTIVATE WINDOW
            self._emit_stage("STARTING_CAPCUT", {"window_class": self.profile.main_window_class})
            if not self.driver.find_and_activate_window(self.profile.main_window_class, timeout_sec=10.0):
                return {
                    "ok": False,
                    "error_code": "CAPCUT_WINDOW_NOT_FOUND",
                    "message": f"Failed to locate and bring '{self.profile.main_window_class}' to foreground.",
                }

            # 3. TRIGGER EXPORT (Ctrl+E)
            self._emit_stage("OPENING_EXPORT_DIALOG", {"shortcut": self.profile.export_shortcut})
            time.sleep(0.5)
            self.driver.send_shortcut(self.profile.export_shortcut)
            time.sleep(1.0)  # Stabilize dialog

            # 4. CONFIRM EXPORT (Enter)
            self._emit_stage("STARTING_EXPORT", {"confirm_key": self.profile.confirm_export_key})
            self.driver.send_key(self.profile.confirm_export_key)
            if hasattr(self.driver, "on_confirm_export"):
                self.driver.on_confirm_export(expected_output_path)
            time.sleep(1.0)

            # 5. MONITOR HEARTBEAT
            self._emit_stage("RENDERING", {"output_path": expected_output_path})
            start_time = time.time()
            last_size = -1
            last_progress_time = time.time()
            file_found = False

            while time.time() - start_time < max_timeout:
                time.sleep(self.profile.heartbeat_poll_interval_sec)

                if os.path.exists(expected_output_path):
                    file_found = True
                    current_size = os.path.getsize(expected_output_path)

                    if current_size > last_size:
                        last_size = current_size
                        last_progress_time = time.time()
                        self._emit_stage("RENDERING", {
                            "output_path": expected_output_path,
                            "size_bytes": current_size,
                            "elapsed_sec": round(time.time() - start_time, 1),
                        })
                    elif current_size > 0 and (time.time() - last_progress_time) > 2.0:
                        # File size has stabilized! Check if lock is released
                        if OutputVerifier.check_file_lock_released(expected_output_path, timeout_sec=2.0):
                            break  # Rendering finished!
                else:
                    if time.time() - last_progress_time > stall_timeout:
                        return {
                            "ok": False,
                            "error_code": "EXPORT_STALLED",
                            "message": f"No output file appeared after {stall_timeout}s stall limit.",
                        }

                if (time.time() - last_progress_time) > stall_timeout:
                    return {
                        "ok": False,
                        "error_code": "EXPORT_STALLED",
                        "message": f"Output file stopped growing for over {stall_timeout}s.",
                    }

            if not file_found or not os.path.exists(expected_output_path):
                return {
                    "ok": False,
                    "error_code": "EXPORT_TIMEOUT",
                    "message": f"Export operation timed out after {max_timeout}s without producing file.",
                }

            # 6. DISMISS COMPLETION DIALOG
            self.driver.send_key(self.profile.dismiss_dialog_key)
            time.sleep(0.5)

            # 7. VERIFY OUTPUT
            self._emit_stage("VERIFYING_OUTPUT", {"output_path": expected_output_path})
            verification = OutputVerifier.verify(
                output_path=expected_output_path,
                expected_duration_sec=expected_duration_sec,
            )

            if not verification.is_valid:
                return {
                    "ok": False,
                    "error_code": verification.error_code or "OUTPUT_INVALID",
                    "message": verification.error_message or "Output verification failed.",
                    "details": verification.details,
                }

            self._emit_stage("DONE", {
                "output_path": expected_output_path,
                "verification": verification.details,
            })

            return {
                "ok": True,
                "output_path": expected_output_path,
                "verification": verification.details,
            }

        finally:
            CapCutOwnershipManager.release(job_id)
