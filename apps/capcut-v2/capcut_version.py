"""
apps/capcut-v2/capcut_version.py
Single source of truth for CapCut AutoEdit (V2) product versioning.
Independent from V1 (FFmpeg Edition).
"""
from __future__ import annotations

PRODUCT_ID = "2toolne.capcut.v2"
APP_NAME = "2toolne AutoEdit for CapCut"
CAPCUT_VERSION = "2.0.4"
RELEASE_STREAM = "capcut-v2"


def get_version() -> str:
    """Return current V2 semantic version."""
    return CAPCUT_VERSION


def get_app_name() -> str:
    """Return user-facing application name."""
    return APP_NAME


def get_product_id() -> str:
    """Return internal unique product ID."""
    return PRODUCT_ID
