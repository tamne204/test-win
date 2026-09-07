"""
apps/capcut-v2/adapters/capcut/launcher.py
Safely launches CapCut Desktop using OS-native execution.
No mouse coordinates, no macro automation.
"""
from __future__ import annotations

import os
import sys
import subprocess
from typing import Dict, Any, Optional

from .detector import CapCutDetector, STATUS_NOT_FOUND


class CapCutLauncher:
    """
    Safely launches CapCut Desktop application.
    """

    @classmethod
    def launch(cls, draft_path: Optional[str] = None) -> Dict[str, Any]:
        """
        Open CapCut Desktop. If draft_path provided, verify it exists first.
        """
        detector = CapCutDetector()
        status = detector.detect()

        if status.status == STATUS_NOT_FOUND:
            return {
                "ok": False,
                "error": "CAPCUT_NOT_INSTALLED",
                "message": "CapCut Desktop application was not found on this computer.",
            }

        if draft_path and not os.path.exists(draft_path):
            return {
                "ok": False,
                "error": "DRAFT_NOT_FOUND",
                "message": f"Specified draft directory does not exist: {draft_path}",
            }

        try:
            if sys.platform == "darwin":
                # macOS: open application
                subprocess.Popen(["open", "-a", "CapCut"])
                return {
                    "ok": True,
                    "message": "CapCut Desktop launched successfully.",
                    "platform": "macOS",
                }
            elif sys.platform.startswith("win"):
                # Windows
                if status.app_path and os.path.isfile(status.app_path):
                    subprocess.Popen([status.app_path])
                    return {
                        "ok": True,
                        "message": "CapCut Desktop launched successfully.",
                        "platform": "Windows",
                    }
                else:
                    return {
                        "ok": False,
                        "error": "CAPCUT_EXE_NOT_FOUND",
                        "message": "CapCut executable path not found on Windows.",
                    }
            else:
                return {
                    "ok": False,
                    "error": "UNSUPPORTED_PLATFORM",
                    "message": f"Operating system {sys.platform} is not supported for CapCut Desktop.",
                }
        except Exception as exc:
            return {
                "ok": False,
                "error": "CAPCUT_OPEN_FAILED",
                "message": f"Failed to launch CapCut Desktop: {exc}",
            }
