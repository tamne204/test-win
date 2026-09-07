"""
apps/capcut-v2/adapters/capcut/adapter.py
Replaceable compatibility layer mapping EditPlan -> CapCut drafts.
Dispatches to specific CapCutVersionAdapter instances via CapCutAdapterRegistry.
"""
from __future__ import annotations

import os
import json
from typing import Dict, Any, Optional

try:
    from ...core.edit_plan import EditPlan
    from .detector import CapCutDetector, CapCutStatus, STATUS_NOT_FOUND
    from .registry import CapCutAdapterRegistry, STATUS_VERIFIED, STATUS_UNTESTED, STATUS_UNSUPPORTED
    from .validator import CapCutDraftValidator
except (ImportError, ValueError):
    from core.edit_plan import EditPlan
    from adapters.capcut.detector import CapCutDetector, CapCutStatus, STATUS_NOT_FOUND
    from adapters.capcut.registry import CapCutAdapterRegistry, STATUS_VERIFIED, STATUS_UNTESTED, STATUS_UNSUPPORTED
    from adapters.capcut.validator import CapCutDraftValidator


class CapCutAdapter:
    """
    Main entry point for CapCut draft conversion.
    Selects the matching version adapter and validates structural output.
    """

    def __init__(self, target_version: Optional[str] = None):
        self.status = CapCutDetector.detect()
        self.target_version = target_version or self.status.detected_version

    def generate(
        self,
        edit_plan: EditPlan,
        target_dir: str,
        draft_root_path: Optional[str] = None,
        allow_untested: bool = False,
    ) -> Dict[str, Any]:
        """
        Generate a validated CapCut project draft from an EditPlan.

        Args:
            edit_plan: The validated EditPlan.
            target_dir: Destination directory for the draft.
            draft_root_path: Root CapCut draft directory (e.g. com.lveditor.draft).
            allow_untested: If True, permits generation on untested versions in developer mode.

        Returns:
            Dict containing generation metadata and validation status.
        """
        root_path = draft_root_path or self.status.draft_root_path or os.path.dirname(target_dir)

        # 1. Resolve adapter via registry
        adapter_cls, status_code, diag_msg = CapCutAdapterRegistry.resolve_adapter(
            self.target_version,
            allow_untested=allow_untested,
        )

        if not adapter_cls:
            raise RuntimeError(f"{status_code}: {diag_msg}")

        # 2. Generate files using resolved adapter
        result = adapter_cls.generate_draft(
            edit_plan=edit_plan,
            target_dir=target_dir,
            draft_root_path=root_path,
        )

        # 3. Structural validation via CapCutDraftValidator
        validation_errors = CapCutDraftValidator.validate_draft(target_dir)
        if validation_errors:
            raise RuntimeError(
                f"Generated CapCut draft failed structural validation: {'; '.join(validation_errors)}"
            )

        result["validated"] = True
        result["adapter_used"] = adapter_cls.__name__
        result["compatibility_status"] = status_code
        return result
