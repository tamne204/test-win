"""
apps/capcut-v2/core/security package
"""

from .device_id import get_privacy_device_id
from .ed25519_verifier import verify_signed_entitlement, TRUSTED_PUBLIC_KEYS, canonicalize_payload
from .license_guard import (
    LicenseGuard,
    LicenseEntitlementError,
    get_license_guard,
    require_entitlement,
    STATE_NOT_ACTIVATED,
    STATE_ACTIVE,
    STATE_EXPIRED,
    STATE_REVOKED,
    STATE_DEVICE_LIMIT,
    STATE_WRONG_PRODUCT,
    STATE_OFFLINE_GRACE,
    STATE_ONLINE_CHECK_REQUIRED,
    STATE_SERVER_UNAVAILABLE,
    STATE_TOKEN_INVALID,
    STATE_DEVICE_MISMATCH,
)
from .redactor import redact_license_key, redact_token, sanitize_diagnostics

__all__ = [
    "get_privacy_device_id",
    "verify_signed_entitlement",
    "canonicalize_payload",
    "TRUSTED_PUBLIC_KEYS",
    "LicenseGuard",
    "LicenseEntitlementError",
    "get_license_guard",
    "require_entitlement",
    "redact_license_key",
    "redact_token",
    "sanitize_diagnostics",
    "STATE_NOT_ACTIVATED",
    "STATE_ACTIVE",
    "STATE_EXPIRED",
    "STATE_REVOKED",
    "STATE_DEVICE_LIMIT",
    "STATE_WRONG_PRODUCT",
    "STATE_OFFLINE_GRACE",
    "STATE_ONLINE_CHECK_REQUIRED",
    "STATE_SERVER_UNAVAILABLE",
    "STATE_TOKEN_INVALID",
    "STATE_DEVICE_MISMATCH",
]
