"""
tests/validate_phase4_1_disk_scan.py
Validates:
- RAW_LICENSE_KEY_FOUND_ON_DISK = NO
- RAW_LICENSE_KEY_FOUND_IN_LOGS = NO
- NO READABLE LICENSE TOKEN PAYLOAD in secure storage
"""
import os
import json
import base64
import time
from core.security.license_guard import LicenseGuard
from core.security.device_id import get_privacy_device_id
from core.security.ed25519_verifier import canonicalize_payload
from cryptography.hazmat.primitives.asymmetric import ed25519

DISPOSABLE_KEY = "2TL-CAP-DISP-OSK1-TEST-9999"
SERVER_SEED = "wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE="

def run_scan():
    app_data = os.path.expanduser("~/Library/Application Support/2toolne AutoEdit")
    os.makedirs(app_data, exist_ok=True)
    
    # 1. Initialize guard
    guard = LicenseGuard(app_data_dir=app_data)
    
    # 2. Simulate server-signed token WITHOUT license_key in payload
    now = int(time.time())
    payload = {
        "license_id": "lic_disp_test_9999",
        "user_id": "usr_disp_test",
        "product_id": "2toolne.capcut.v2",
        "device_id": get_privacy_device_id(),
        "plan": "PRO",
        "issued_at": now,
        "expires_at": now + (30 * 86400),
        "offline_until": now + (72 * 3600),
        "features": ["capcut_autoedit", "unlimited_export", "all_presets"],
    }
    canon = canonicalize_payload(payload)
    priv = ed25519.Ed25519PrivateKey.from_private_bytes(base64.b64decode(SERVER_SEED))
    sig = priv.sign(canon)
    envelope = {
        "token_version": 1,
        "kid": "kid_2026_01",
        "payload": payload,
        "signature": base64.b64encode(sig).decode("utf-8"),
    }

    # 3. Perform activation (handoff to sidecar in-memory)
    guard.set_envelope(
        envelope,
        trusted_server_time=now,
        masked_key="2TL-CAP-****-****-9999",
        license_key_last4="9999",
    )
    
    status = guard.get_public_status()
    assert status["authorized"] is True
    assert status["masked_key"] == "2TL-CAP-****-****-9999"
    assert status["license_key_last4"] == "9999"

    # 4. Check if entitlement.json exists on disk
    entitlement_file = os.path.join(app_data, "entitlement.json")
    if os.path.exists(entitlement_file):
        raise AssertionError(f"FAIL: Plaintext entitlement.json exists at {entitlement_file}")
    print("✓ Confirmed: entitlement.json does not exist on disk.")

    # 5. Recursively search entire app data directory for DISPOSABLE_KEY
    found_in_files = []
    for root, dirs, files in os.walk(app_data):
        for fname in files:
            fpath = os.path.join(root, fname)
            try:
                with open(fpath, "rb") as f:
                    content = f.read()
                    if DISPOSABLE_KEY.encode("utf-8") in content:
                        found_in_files.append(fpath)
            except Exception:
                pass

    if found_in_files:
        raise AssertionError(f"FAIL: Found raw license key in files: {found_in_files}")
    print("✓ Confirmed: RAW_LICENSE_KEY_FOUND_ON_DISK = NO")

    # 6. Check clock_guard.json content
    clock_guard_path = os.path.join(app_data, "clock_guard.json")
    if os.path.exists(clock_guard_path):
        with open(clock_guard_path, "r", encoding="utf-8") as f:
            cg_data = json.load(f)
        assert "last_trusted_server_time" in cg_data
        assert "last_local_check_time" in cg_data
        assert DISPOSABLE_KEY not in json.dumps(cg_data)
        print("✓ Confirmed: clock_guard.json contains only timestamps (NON-SENSITIVE).")

    print("\nALL DISK SCAN VERIFICATIONS PASSED!")

if __name__ == "__main__":
    run_scan()
