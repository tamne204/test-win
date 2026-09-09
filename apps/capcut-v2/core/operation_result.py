"""
apps/capcut-v2/core/operation_result.py
Normalized User-Facing Operation Result Contract and Artifact Reconciliation Engine.

Ensures the UI strictly reflects the authoritative final outcome rather than
intermediate or secondary exceptions (eliminating false-negative user statuses).
"""

from __future__ import annotations

import os
import json
import logging
import uuid
from dataclasses import dataclass, field
from typing import Dict, Any, Optional, List

logger = logging.getLogger(__name__)

# Standard Outcomes
OUTCOME_SUCCESS = "SUCCESS"
OUTCOME_SUCCESS_WITH_WARNING = "SUCCESS_WITH_WARNING"
OUTCOME_PARTIAL_SUCCESS = "PARTIAL_SUCCESS"
OUTCOME_RETRYABLE_ERROR = "RETRYABLE_ERROR"
OUTCOME_USER_INPUT_ERROR = "USER_INPUT_ERROR"
OUTCOME_SYSTEM_ERROR = "SYSTEM_ERROR"
OUTCOME_EXTERNAL_APP_ERROR = "EXTERNAL_APP_ERROR"
OUTCOME_CANCELLED = "CANCELLED"
OUTCOME_FAILED = "FAILED"

# Friendly User Message Mappings
FRIENDLY_ERROR_MESSAGES = {
    "MISSING_AUDIO": "Vui lòng chọn tệp âm thanh hợp lệ trước khi tạo dự án.",
    "MISSING_IMAGES": "Vui lòng chọn ít nhất một hình ảnh trước khi tạo dự án.",
    "FILE_NOT_FOUND": "Không tìm thấy một số tệp hình ảnh hoặc âm thanh nguồn.",
    "UNSUPPORTED_VERSION": "Phiên bản CapCut hiện tại chưa được hỗ trợ hoàn toàn.",
    "CAPCUT_BUSY": "CapCut đang bận hoặc bị khóa tiến trình. Vui lòng thử lại sau vài giây.",
    "LAUNCH_FAILED": "Không thể mở CapCut tự động. Bạn có thể mở thủ công trong CapCut.",
    "INSUFFICIENT_CREDITS": "Số dư không đủ để thực hiện thao tác này.",
    "INDEX_FAILED": "Dự án đã được tạo. Không thể cập nhật danh sách dự án ngay lúc này.",
    "WALLET_FAILED": "Dự án đã được tạo thành công. Không thể cập nhật số dư lúc này.",
}


@dataclass
class OperationResult:
    """Standard user-facing operation result contract."""
    operation_id: str = field(default_factory=lambda: str(uuid.uuid4()))
    operation_type: str = "PROJECT_CREATE"  # "PROJECT_CREATE", "RENDER", "QUEUE_BUILD", etc.
    outcome: str = OUTCOME_SUCCESS
    primary_message: str = "Thao tác thành công."
    secondary_message: Optional[str] = None
    user_action: Optional[str] = None  # "OPEN_CAPCUT", "CHOOSE_AUDIO", "RETRY", "RELOAD_PROJECTS"
    technical_code: Optional[str] = None
    recoverable: bool = False
    artifact_exists: bool = False
    artifact_path: Optional[str] = None
    diagnostics_id: Optional[str] = None
    data: Dict[str, Any] = field(default_factory=dict)

    @property
    def is_success(self) -> bool:
        return self.outcome in (OUTCOME_SUCCESS, OUTCOME_SUCCESS_WITH_WARNING, OUTCOME_PARTIAL_SUCCESS)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "operation_id": self.operation_id,
            "operation_type": self.operation_type,
            "outcome": self.outcome,
            "primary_message": self.primary_message,
            "secondary_message": self.secondary_message,
            "user_action": self.user_action,
            "technical_code": self.technical_code,
            "recoverable": self.recoverable,
            "artifact_exists": self.artifact_exists,
            "artifact_path": self.artifact_path,
            "diagnostics_id": self.diagnostics_id,
            "is_success": self.is_success,
            "data": self.data,
        }


def reconcile_project_creation(
    draft_dir: Optional[str],
    project_name: Optional[str] = None,
    secondary_warning: Optional[str] = None,
    exception_caught: Optional[Exception] = None,
    diagnostics_id: Optional[str] = None,
    extra_data: Optional[Dict[str, Any]] = None,
) -> OperationResult:
    """
    Authoritative post-operation artifact reconciliation.
    
    Verifies on-disk truth:
    1. Does draft_dir exist and contain required files?
    2. Does CapCutDraftValidator report 0 fatal errors?
    3. If yes: Guarantees outcome is NEVER 'FAILED' even if a late exception occurred!
    """
    op_id = str(uuid.uuid4())
    proj_name = project_name or "Dự án"
    data = extra_data or {}

    # Check 1: In-depth on-disk artifact inspection
    if draft_dir and os.path.isdir(draft_dir):
        info_file = os.path.join(draft_dir, "draft_info.json")
        content_file = os.path.join(draft_dir, "draft_content.json")
        meta_file = os.path.join(draft_dir, "draft_meta_info.json")
        has_info = os.path.isfile(info_file) or os.path.isfile(content_file)
        has_meta = os.path.isfile(meta_file)

        if has_info and has_meta:
            # Check draft validator
            from adapters.capcut.validator import CapCutDraftValidator
            val_errors = CapCutDraftValidator.validate_draft(draft_dir)

            # Filter out non-fatal warnings if any
            fatal_errors = [e for e in val_errors if not e.startswith("Warning")]

            if not fatal_errors:
                # EVIDENCE-BASED TRUTH: Project is completely valid on disk!
                if exception_caught or secondary_warning:
                    sec_msg = secondary_warning or (
                        FRIENDLY_ERROR_MESSAGES.get("LAUNCH_FAILED")
                        if "launch" in str(exception_caught).lower() or "open" in str(exception_caught).lower()
                        else f"Lưu ý: {str(exception_caught)}"
                    )
                    return OperationResult(
                        operation_id=op_id,
                        operation_type="PROJECT_CREATE",
                        outcome=OUTCOME_SUCCESS_WITH_WARNING,
                        primary_message=f"Dự án \"{proj_name}\" đã được tạo thành công.",
                        secondary_message=sec_msg,
                        user_action="OPEN_CAPCUT",
                        artifact_exists=True,
                        artifact_path=draft_dir,
                        diagnostics_id=diagnostics_id,
                        data=data,
                    )
                else:
                    return OperationResult(
                        operation_id=op_id,
                        operation_type="PROJECT_CREATE",
                        outcome=OUTCOME_SUCCESS,
                        primary_message=f"Dự án \"{proj_name}\" đã được tạo thành công.",
                        secondary_message="Bạn có thể mở ngay trong CapCut hoặc xem trong mục Dự Án.",
                        user_action="OPEN_CAPCUT",
                        artifact_exists=True,
                        artifact_path=draft_dir,
                        diagnostics_id=diagnostics_id,
                        data=data,
                    )

    # If artifact is missing or corrupted, classify error humanely
    err_str = str(exception_caught) if exception_caught else "Không xác định"
    err_code = "UNKNOWN_ERROR"
    user_action = "RETRY"
    recoverable = True

    if "audio" in err_str.lower() and ("không tìm thấy" in err_str.lower() or "missing" in err_str.lower()):
        primary = "Không tìm thấy tệp âm thanh đã chọn."
        err_code = "MISSING_AUDIO"
        user_action = "CHOOSE_AUDIO"
    elif "ảnh" in err_str.lower() or "image" in err_str.lower():
        primary = "Không tìm thấy tệp hình ảnh nguồn."
        err_code = "MISSING_IMAGES"
        user_action = "CHOOSE_IMAGES"
    elif "version" in err_str.lower():
        primary = "Phiên bản CapCut hiện tại chưa được hỗ trợ."
        err_code = "UNSUPPORTED_VERSION"
        user_action = "CHECK_CAPCUT"
        recoverable = False
    elif "lock" in err_str.lower() or "bận" in err_str.lower():
        primary = "CapCut đang bận hoặc thư viện dự án đang được truy cập."
        err_code = "CAPCUT_BUSY"
        user_action = "RETRY"
    else:
        primary = "Không thể tạo dự án CapCut lúc này."
        err_code = "BUILD_ERROR"

    return OperationResult(
        operation_id=op_id,
        operation_type="PROJECT_CREATE",
        outcome=OUTCOME_FAILED,
        primary_message=primary,
        secondary_message=f"Chi tiết: {err_str}",
        user_action=user_action,
        technical_code=err_code,
        recoverable=recoverable,
        artifact_exists=False,
        artifact_path=draft_dir,
        diagnostics_id=diagnostics_id,
        data=data,
    )
