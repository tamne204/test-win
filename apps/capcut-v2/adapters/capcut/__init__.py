"""
CapCut Desktop adapter and project generator.
"""
from __future__ import annotations

from .detector import CapCutDetector, CapCutStatus
from .adapter import CapCutAdapter
from .project_manager import CapCutProjectManager
from .launcher import CapCutLauncher
from .render_profile import RenderProfile, RenderProfileRegistry, WINDOWS_CAPCUT_9_3_0_3970
from .output_verifier import OutputVerifier, VerificationResult
from .ownership_manager import CapCutOwnershipManager
from .version_guard import CapCutVersionGuard
from .native_exporter import CapCutNativeExporter
from .render_job import RenderJob
from .render_queue_manager import RenderQueueManager
