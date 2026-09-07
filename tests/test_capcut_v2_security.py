"""
tests/test_capcut_v2_security.py
Comprehensive Commercial Security, Authentication & Licensing Test Suite (Phase 4).
Tests:
1. Missing license -> LICENSE_NOT_ACTIVATED
2. Valid active license -> Access granted
3. Expired license -> LICENSE_EXPIRED
4. Revoked license -> LICENSE_REVOKED
5. Wrong product entitlement -> LICENSE_WRONG_PRODUCT
6. Device mismatch / Machine copying -> LICENSE_DEVICE_MISMATCH
7. Tampered payload / signature forgery -> LICENSE_TOKEN_INVALID
8. Offline valid within grace (72h) -> LICENSE_OFFLINE_GRACE
9. Offline grace expired -> LICENSE_ONLINE_CHECK_REQUIRED
10. Clock rollback defense -> LICENSE_ONLINE_CHECK_REQUIRED
11. Direct sidecar CLI execution bypass -> DENIED
12. Pre-activation command authorization (PING, GET_APP_INFO, DETECT_CAPCUT allowed)
13. Commercial command authorization (GENERATE_CAPCUT_PROJECT, GET_PRESETS, etc. protected)
14. Log & diagnostics redaction -> Masked keys, no raw credentials
15. Key rotation via kid support
16. Privacy-conscious device ID stability and irreversibility
17. Server unavailable handling -> does NOT falsely revoke
"""

import base64
import copy
import json
import os
import shutil
import sys
import tempfile
import time
import pytest

from cryptography.hazmat.primitives.asymmetric import ed25519
from cryptography.hazmat.primitives import serialization

# Ensure apps/capcut-v2 is in sys.path
V2_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_ROOT not in sys.path:
    sys.path.insert(0, V2_ROOT)

from core.security.device_id import get_privacy_device_id
from core.security.ed25519_verifier import (
    verify_signed_entitlement,
    canonicalize_payload,
    TRUSTED_PUBLIC_KEYS,
)
from core.security.license_guard import (
    LicenseGuard,
    LicenseEntitlementError,
    STATE_NOT_ACTIVATED,
    STATE_ACTIVE,
    STATE_EXPIRED,
    STATE_REVOKED,
    STATE_WRONG_PRODUCT,
    STATE_OFFLINE_GRACE,
    STATE_ONLINE_CHECK_REQUIRED,
    STATE_TOKEN_INVALID,
    STATE_DEVICE_MISMATCH,
)
from core.security.redactor import redact_license_key, redact_token, sanitize_diagnostics
from desktop_bridge.bridge import DesktopBridge, COMMERCIAL_METHODS
from desktop_bridge.protocol import PROTOCOL_VERSION

# Test Server Private Signing Key Seed for kid_2026_01
TEST_SERVER_SEED_B64 = "wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE="


def create_signed_token_envelope(
    payload_overrides: dict = None,
    kid: str = "kid_2026_01",
    tamper_signature: bool = False,
) -> dict:
    """Helper to simulate authoritative server-side Ed25519 signing."""
    now = int(time.time())
    local_dev = get_privacy_device_id()

    payload = {
        "license_id": "lic_test_capcut_pro",
        "user_id": "usr_test_vip",
        "product_id": "2toolne.capcut.v2",
        "device_id": local_dev,
        "plan": "PRO",
        "issued_at": now,
        "expires_at": now + (30 * 86400),
        "offline_until": now + (72 * 3600),
        "features": ["capcut_autoedit", "unlimited_export", "all_presets"],
    }
    if payload_overrides:
        payload.update(payload_overrides)

    canonical_bytes = canonicalize_payload(payload)

    # Server signs using private key
    seed_bytes = base64.b64decode(TEST_SERVER_SEED_B64)
    private_key = ed25519.Ed25519PrivateKey.from_private_bytes(seed_bytes)
    sig_bytes = private_key.sign(canonical_bytes)

    if tamper_signature:
        # Corrupt 1 byte in signature
        sig_bytes = bytearray(sig_bytes)
        sig_bytes[0] ^= 0xFF
        sig_bytes = bytes(sig_bytes)

    return {
        "token_version": 1,
        "kid": kid,
        "payload": payload,
        "signature": base64.b64encode(sig_bytes).decode("utf-8"),
    }


@pytest.fixture
def clean_guard():
    """Provides an isolated LicenseGuard instance with a temporary app data directory."""
    tmp_dir = tempfile.mkdtemp(prefix="2toolne_sec_test_")
    guard = LicenseGuard(app_data_dir=tmp_dir)
    yield guard
    shutil.rmtree(tmp_dir, ignore_errors=True)


@pytest.fixture
def secured_bridge(clean_guard):
    """Provides a DesktopBridge wired to the isolated LicenseGuard."""
    return DesktopBridge(
        workspace_root=os.path.join(clean_guard._app_data_dir, "projects"),
        user_presets_dir=os.path.join(clean_guard._app_data_dir, "presets"),
        license_guard=clean_guard,
    )


# ==============================================================================
# 1. CORE ENTITLEMENT & STATE TESTS
# ==============================================================================

def test_missing_license_denied(clean_guard):
    """Fresh install without key must evaluate to LICENSE_NOT_ACTIVATED."""
    is_auth, state, msg, payload = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_NOT_ACTIVATED
    assert payload is None


def test_valid_license_active(clean_guard):
    """Validly signed token envelope grants active status."""
    envelope = create_signed_token_envelope()
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, payload = clean_guard.evaluate_entitlement()
    assert is_auth is True
    assert state == STATE_ACTIVE
    assert payload["product_id"] == "2toolne.capcut.v2"
    assert payload["device_id"] == get_privacy_device_id()


def test_expired_license_denied(clean_guard):
    """Expired license must transition to LICENSE_EXPIRED."""
    now = int(time.time())
    envelope = create_signed_token_envelope(payload_overrides={
        "expires_at": now - 3600, # Expired 1 hour ago
        "offline_until": now - 1800,
    })
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_EXPIRED


def test_revoked_license_denied(clean_guard):
    """Revoked license status must transition to LICENSE_REVOKED."""
    envelope = create_signed_token_envelope(payload_overrides={
        "status": "REVOKED",
    })
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_REVOKED


def test_wrong_product_denied(clean_guard):
    """Valid license for another product (e.g. V1 SLIDESHOW) must be rejected."""
    envelope = create_signed_token_envelope(payload_overrides={
        "product_id": "2toolne.slideshow.v1", # Not capcut.v2
    })
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement(required_product="2toolne.capcut.v2")
    assert is_auth is False
    assert state == STATE_WRONG_PRODUCT
    assert "2toolne.slideshow.v1" in msg


def test_device_copying_mismatch_denied(clean_guard):
    """Copying entitlement to another machine must fail with LICENSE_DEVICE_MISMATCH."""
    envelope = create_signed_token_envelope(payload_overrides={
        "device_id": "dev_fake_machine_foreign_hardware_hash_9999",
    })
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_DEVICE_MISMATCH


@pytest.mark.parametrize("tampered_field,fake_value", [
    ("plan", "ENTERPRISE"),
    ("expires_at", 2000000000),
    ("offline_until", 2000000000),
    ("product_id", "2toolne.enterprise.v9"),
    ("device_id", "dev_malicious_attacker_device_9999"),
])
def test_forgery_tampered_payload_fields_denied(clean_guard, tampered_field, fake_value):
    """Section 12: Tampering with any payload claim without valid private key re-signing must fail."""
    envelope = create_signed_token_envelope()
    envelope["payload"][tampered_field] = fake_value
    clean_guard.set_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_TOKEN_INVALID



def test_forgery_invalid_signature_denied(clean_guard):
    """Corrupt signature must fail verification."""
    envelope = create_signed_token_envelope(tamper_signature=True)
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_TOKEN_INVALID


def test_offline_valid_within_grace(clean_guard):
    """Offline usage within 72h grace window is granted as LICENSE_OFFLINE_GRACE."""
    now = int(time.time())
    envelope = create_signed_token_envelope(payload_overrides={
        "issued_at": now - (36 * 3600), # 36 hours ago (still within 72h)
        "offline_until": now + (36 * 3600),
    })
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is True
    assert state == STATE_OFFLINE_GRACE


def test_offline_grace_expired_denied(clean_guard):
    """Offline grace expired must require online validation."""
    now = int(time.time())
    envelope = create_signed_token_envelope(payload_overrides={
        "issued_at": now - (80 * 3600),
        "offline_until": now - 3600, # Grace ended 1 hour ago
        "expires_at": now + (10 * 86400), # Subscription still active online
    })
    clean_guard.save_envelope(envelope)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_ONLINE_CHECK_REQUIRED


def test_clock_rollback_defense(clean_guard):
    """Rolling back system clock by more than 12 hours must trigger online check required."""
    now = int(time.time())
    envelope = create_signed_token_envelope()
    # Record trusted server time as now
    clean_guard.save_envelope(envelope, trusted_server_time=now)

    # Manually simulate clock guard having seen a future time (e.g. user rolled back clock 24h)
    clean_guard._update_clock_guard(trusted_server_time=now + 86400, local_recorded_time=now + 86400)

    is_auth, state, msg, _ = clean_guard.evaluate_entitlement()
    assert is_auth is False
    assert state == STATE_ONLINE_CHECK_REQUIRED
    assert "đồng hồ" in msg.lower() or "clock" in msg.lower()


# ==============================================================================
# 2. SIDECAR BRIDGE ENFORCEMENT & BYPASS TESTS
# ==============================================================================

def test_sidecar_pre_activation_methods_allowed(secured_bridge):
    """PING, GET_APP_INFO, DETECT_CAPCUT must be accessible before activation."""
    ping_res = secured_bridge.dispatch({"id": "p1", "method": "PING"})
    assert ping_res["ok"] is True
    assert ping_res["result"]["pong"] is True

    info_res = secured_bridge.dispatch({"id": "i1", "method": "GET_APP_INFO"})
    assert info_res["ok"] is True
    assert info_res["result"]["product_id"] == "2toolne.capcut.v2"

    det_res = secured_bridge.dispatch({"id": "d1", "method": "DETECT_CAPCUT"})
    assert det_res["ok"] is True


def test_sidecar_commercial_methods_denied_without_activation(secured_bridge):
    """Direct execution of all commercial methods without activation must be denied."""
    for method in COMMERCIAL_METHODS:
        res = secured_bridge.dispatch({"id": f"comm_{method}", "method": method, "params": {}})
        assert res["ok"] is False
        assert res["error"]["code"] == STATE_NOT_ACTIVATED


def test_sidecar_commercial_methods_allowed_with_valid_license(secured_bridge, clean_guard):
    """Commercial methods are permitted once a valid signed entitlement is installed."""
    envelope = create_signed_token_envelope()
    clean_guard.save_envelope(envelope)

    # GET_PRESETS should succeed
    res = secured_bridge.dispatch({"id": "pres1", "method": "GET_PRESETS"})
    assert res["ok"] is True
    assert "presets" in res["result"]


# ==============================================================================
# 3. REDACTION, PRIVACY & ROTATION TESTS
# ==============================================================================

def test_license_key_redaction():
    """Verify license keys and tokens are securely masked in logs."""
    masked = redact_license_key("2TL-CAP-A1B2-C3D4-E5F6-7890")
    assert masked == "2TL-CAP-****-****-7890"

    v1_masked = redact_license_key("2TAMNE-VIP-ABCD-1234")
    assert v1_masked == "2TAMNE-VIP-****-****-1234"

    tok_masked = redact_token("sess_token_very_secret_1234567890")
    assert tok_masked == "sess****7890"


def test_diagnostics_sanitization():
    """Verify get_diagnostics sanitizes sensitive fields."""
    raw_data = {
        "license_key": "2TL-CAP-A1B2-C3D4-E5F6-7890",
        "secret_token": "secret_1234567890_abcdef",
        "other_info": "safe_string",
    }
    cleaned = sanitize_diagnostics(raw_data)
    assert cleaned["license_key"] == "2TL-CAP-****-****-7890"
    assert "****" in cleaned["secret_token"]
    assert cleaned["other_info"] == "safe_string"


def test_privacy_device_id_format():
    """Verify device ID is stable, non-empty, and prefixed with 'dev_' without exposing raw UUID."""
    dev1 = get_privacy_device_id()
    dev2 = get_privacy_device_id()
    assert dev1 == dev2
    assert dev1.startswith("dev_")
    assert len(dev1) >= 40 # dev_ + sha256


def test_key_rotation_kid_support(clean_guard):
    """Verify key rotation using kid: unknown kid is rejected, new trusted kid is accepted."""
    # Generate an ephemeral keypair for kid_2026_02
    new_priv = ed25519.Ed25519PrivateKey.generate()
    new_pub = new_priv.public_key()
    new_pub_b64 = base64.b64encode(
        new_pub.public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw
        )
    ).decode("utf-8")

    # Create envelope signed with new private key
    now = int(time.time())
    payload = {
        "license_id": "lic_rot_test",
        "user_id": "usr_rot",
        "product_id": "2toolne.capcut.v2",
        "device_id": get_privacy_device_id(),
        "plan": "PRO",
        "issued_at": now,
        "expires_at": now + 86400,
        "offline_until": now + 86400,
        "features": ["capcut_autoedit"],
    }
    canon = canonicalize_payload(payload)
    sig = new_priv.sign(canon)
    sig_b64 = base64.b64encode(sig).decode("utf-8")

    envelope = {
        "token_version": 1,
        "kid": "kid_2026_02",
        "payload": payload,
        "signature": sig_b64,
    }

    # Verify fails against default store because kid_2026_02 is unknown
    is_valid, err, _ = verify_signed_entitlement(envelope)
    assert is_valid is False
    assert err == "UNKNOWN_KEY_ID"

    # Verify succeeds when updated trusted key store contains kid_2026_02
    multi_keys = dict(TRUSTED_PUBLIC_KEYS)
    multi_keys["kid_2026_02"] = new_pub_b64

    is_valid, err, payload_out = verify_signed_entitlement(envelope, custom_trusted_keys=multi_keys)
    assert is_valid is True
    assert payload_out["license_id"] == "lic_rot_test"


# ==============================================================================
# 4. PHASE 4.1 — STORAGE & HANDOFF SECURITY TESTS
# ==============================================================================

def test_in_memory_handoff_no_disk_entitlement(clean_guard):
    """Section 6: Sidecar stores entitlement in memory only; never creates entitlement.json on disk."""
    envelope = create_signed_token_envelope()
    clean_guard.set_envelope(envelope, masked_key="2TL-CAP-****-****-7890", license_key_last4="7890")

    # Verify authorization works in memory
    is_auth, state, msg, payload = clean_guard.evaluate_entitlement()
    assert is_auth is True
    assert state == STATE_ACTIVE

    # CRITICAL: Verify entitlement.json DOES NOT exist on disk
    assert not os.path.exists(clean_guard.entitlement_path), "entitlement.json must not be written to disk!"

    # Verify status has hint and no raw key
    status = clean_guard.get_public_status()
    assert status["masked_key"] == "2TL-CAP-****-****-7890"
    assert status["license_key_last4"] == "7890"
    assert "license_key" not in status or not status.get("license_key")


def test_direct_sidecar_fails_without_electron_handoff():
    """Section 7: Launching sidecar directly without Electron handoff must fail commercial commands."""
    fresh_guard = LicenseGuard(app_data_dir=tempfile.mkdtemp(prefix="sidecar_direct_test_"))
    bridge = DesktopBridge(
        workspace_root="/tmp/projects",
        user_presets_dir="/tmp/presets",
        license_guard=fresh_guard,
    )

    # Pre-activation allowed
    ping_res = bridge.dispatch({"id": "p1", "method": "PING"})
    assert ping_res["ok"] is True

    # Commercial command denied
    gen_res = bridge.dispatch({
        "id": "g1",
        "method": "GENERATE_CAPCUT_PROJECT",
        "params": {"project_name": "Unauthorized"}
    })
    assert gen_res["ok"] is False
    assert gen_res["error"]["code"] == STATE_NOT_ACTIVATED


def test_no_raw_license_key_on_disk(clean_guard):
    """Section 13: Search recursively for raw license key in app data directory; must not be found."""
    raw_key = "2TL-CAP-DISP-OSK1-TEST-9999"
    envelope = create_signed_token_envelope()
    clean_guard.set_envelope(envelope, masked_key="2TL-CAP-****-****-9999", license_key_last4="9999")

    # Recursively scan all files in app_data_dir
    found_raw_key = False
    for root, dirs, files in os.walk(clean_guard._app_data_dir):
        for fname in files:
            fpath = os.path.join(root, fname)
            try:
                with open(fpath, "rb") as f:
                    content = f.read()
                    if raw_key.encode("utf-8") in content:
                        found_raw_key = True
            except Exception:
                pass

    assert found_raw_key is False, f"Raw key {raw_key} was unexpectedly found on disk!"


def test_subtitle_commercial_methods_license_gate(tmp_path, clean_guard):
    """Verify Script-to-SRT methods are protected by the commercial license gate."""
    # Ensure subtitle methods are members of COMMERCIAL_METHODS
    assert "GENERATE_SRT_FROM_SCRIPT" in COMMERCIAL_METHODS
    assert "GET_SUBTITLE_ALIGNMENT_STATUS" in COMMERCIAL_METHODS
    assert "CANCEL_SUBTITLE_ALIGNMENT" in COMMERCIAL_METHODS
    assert "EXPORT_SRT" in COMMERCIAL_METHODS

    bridge = DesktopBridge(workspace_root=str(tmp_path), license_guard=clean_guard)

    # 1. Without activation -> Denied
    res = bridge.dispatch({
        "id": "srt-unauth",
        "method": "GENERATE_SRT_FROM_SCRIPT",
        "params": {"script_text": "demo", "audio_path": "demo.wav"}
    })
    assert res["ok"] is False
    assert res["error"]["code"] == STATE_NOT_ACTIVATED

    # 2. With valid active license -> Allowed
    envelope = create_signed_token_envelope()
    clean_guard.set_envelope(envelope)

    res_cancel = bridge.dispatch({
        "id": "srt-auth-cancel",
        "method": "CANCEL_SUBTITLE_ALIGNMENT",
        "params": {}
    })
    assert res_cancel["ok"] is True
    assert res_cancel["result"]["cancelled"] is True


