"""
apps/capcut-v2/desktop_bridge/__init__.py
Desktop bridge package for 2TOOLNE AutoEdit for CapCut (V2).
Connects Electron Desktop Shell to Python Core Sidecar via stdin/stdout JSON IPC.
"""
from __future__ import annotations

from .protocol import (
    PROTOCOL_VERSION,
    create_response,
    create_error,
    create_notification,
    serialize_message,
    parse_message,
)
from .bridge import DesktopBridge

__all__ = [
    "PROTOCOL_VERSION",
    "create_response",
    "create_error",
    "create_notification",
    "serialize_message",
    "parse_message",
    "DesktopBridge",
]
