"""
apps/capcut-v2/desktop_bridge/protocol.py
Defines the versioned JSON-RPC IPC protocol between Electron Main and Python Sidecar.
Direct stdin/stdout communication. No HTTP server, no localhost, no ports.
"""
from __future__ import annotations

import json
from typing import Dict, Any, Optional

PROTOCOL_VERSION = 1

# Standardized Error Codes
ERR_METHOD_NOT_FOUND = "METHOD_NOT_FOUND"
ERR_INVALID_PARAMS = "INVALID_PARAMS"
ERR_INVALID_REQUEST = "INVALID_REQUEST"
ERR_CAPCUT_NOT_FOUND = "CAPCUT_NOT_FOUND"
ERR_CAPCUT_VERSION_UNTESTED = "CAPCUT_VERSION_UNTESTED"
ERR_CAPCUT_VERSION_UNSUPPORTED = "CAPCUT_VERSION_UNSUPPORTED"
ERR_CAPCUT_PROJECT_INDEX_CONFLICT = "CAPCUT_PROJECT_INDEX_CONFLICT"
ERR_CAPCUT_DRAFT_VALIDATION_FAILED = "CAPCUT_DRAFT_VALIDATION_FAILED"
ERR_SOURCE_MEDIA_MISSING = "SOURCE_MEDIA_MISSING"
ERR_EDIT_PLAN_INVALID = "EDIT_PLAN_INVALID"
ERR_SIDECAR_INTERNAL_ERROR = "SIDECAR_INTERNAL_ERROR"

# Commercial License Error Codes (Section 18)
ERR_LICENSE_NOT_ACTIVATED = "LICENSE_NOT_ACTIVATED"
ERR_LICENSE_ACTIVE = "LICENSE_ACTIVE"
ERR_LICENSE_EXPIRED = "LICENSE_EXPIRED"
ERR_LICENSE_REVOKED = "LICENSE_REVOKED"
ERR_LICENSE_DEVICE_LIMIT = "LICENSE_DEVICE_LIMIT"
ERR_LICENSE_WRONG_PRODUCT = "LICENSE_WRONG_PRODUCT"
ERR_LICENSE_OFFLINE_GRACE = "LICENSE_OFFLINE_GRACE"
ERR_LICENSE_ONLINE_CHECK_REQUIRED = "LICENSE_ONLINE_CHECK_REQUIRED"
ERR_LICENSE_SERVER_UNAVAILABLE = "LICENSE_SERVER_UNAVAILABLE"
ERR_LICENSE_TOKEN_INVALID = "LICENSE_TOKEN_INVALID"
ERR_LICENSE_DEVICE_MISMATCH = "LICENSE_DEVICE_MISMATCH"

# Script-to-SRT Alignment Error Codes (Phase 5C)
ERR_SCRIPT_EMPTY = "SCRIPT_EMPTY"
ERR_AUDIO_MISSING = "AUDIO_MISSING"
ERR_AUDIO_UNREADABLE = "AUDIO_UNREADABLE"
ERR_ASR_MODEL_MISSING = "ASR_MODEL_MISSING"
ERR_ASR_FAILED = "ASR_FAILED"
ERR_ASR_RUNTIME_INCOMPLETE = "ASR_RUNTIME_INCOMPLETE"
ERR_SCRIPT_ALIGNMENT_FAILED = "SCRIPT_ALIGNMENT_FAILED"
ERR_SCRIPT_ALIGNMENT_LOW_CONFIDENCE = "SCRIPT_ALIGNMENT_LOW_CONFIDENCE"
ERR_SRT_GENERATION_FAILED = "SRT_GENERATION_FAILED"

# Render Automation Error Codes (Phase 5E - Section 42)
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

# Render Automation IPC Methods
METHOD_RENDER_NOW = "render_now"
METHOD_ENQUEUE_RENDER = "enqueue_render"
METHOD_GET_QUEUE_STATE = "get_render_queue_state"
METHOD_CONTROL_QUEUE = "control_render_queue"
METHOD_GET_RENDER_PROFILE = "get_render_profile"


def create_response(req_id: str, result: Any) -> Dict[str, Any]:
    """Build a success response dictionary."""
    return {
        "id": req_id,
        "protocol": PROTOCOL_VERSION,
        "ok": True,
        "result": result,
    }


def create_error(
    req_id: Optional[str],
    code: str,
    message: str,
    data: Optional[Any] = None,
) -> Dict[str, Any]:
    """Build a standardized error response dictionary."""
    err_obj: Dict[str, Any] = {
        "code": code,
        "message": message,
    }
    if data is not None:
        err_obj["data"] = data

    return {
        "id": req_id,
        "protocol": PROTOCOL_VERSION,
        "ok": False,
        "error": err_obj,
    }


def create_notification(event: str, data: Any) -> Dict[str, Any]:
    """Build an unsolicited progress or status notification."""
    return {
        "type": "notification",
        "protocol": PROTOCOL_VERSION,
        "event": event,
        "data": data,
    }


def serialize_message(msg: Dict[str, Any]) -> str:
    """Serialize dictionary into a clean single JSON line ending with newline."""
    return json.dumps(msg, ensure_ascii=False) + "\n"


def parse_message(raw_line: str) -> Dict[str, Any]:
    """
    Parse a raw string from stdin into a JSON request dictionary.
    Raises ValueError if malformed.
    """
    cleaned = raw_line.strip()
    if not cleaned:
        raise ValueError("Empty request line")
    data = json.loads(cleaned)
    if not isinstance(data, dict):
        raise ValueError("IPC payload must be a JSON object")
    return data
