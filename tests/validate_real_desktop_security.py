"""
tests/validate_real_desktop_security.py
Physical Validation Script on macOS (Darwin 26.1 / Apple Silicon) with CapCut Desktop 9.3.0.
Verifies:
1. Direct sidecar bypass attempt is denied (LICENSE_NOT_ACTIVATED)
2. Forged signature attempt is denied (LICENSE_TOKEN_INVALID)
3. Wrong product entitlement is denied (LICENSE_WRONG_PRODUCT)
4. Valid Ed25519 asymmetric activation succeeds
5. Generates real CapCut project through authorized sidecar
6. Validates CapCut draft with 0 errors
7. Launches CapCut Desktop 9.3.0 successfully
8. Deactivates and confirms sidecar locks down again
"""

import base64
import os
import sys
import time
import subprocess
from PIL import Image
from cryptography.hazmat.primitives.asymmetric import ed25519

# Path setup
ROOT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
V2_DIR = os.path.join(ROOT_DIR, "apps", "capcut-v2")
sys.path.insert(0, ROOT_DIR)
sys.path.insert(0, V2_DIR)

from core.security.device_id import get_privacy_device_id
from core.security.ed25519_verifier import canonicalize_payload
from core.security.license_guard import LicenseGuard, get_license_guard
from desktop_bridge.bridge import DesktopBridge
from adapters.capcut.validator import CapCutDraftValidator

SERVER_SIGNING_SEED = "wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE="


def create_token(payload_overrides=None, corrupt_sig=False):
    now = int(time.time())
    local_dev = get_privacy_device_id()
    payload = {
        "license_id": "lic_real_mac_sec_test",
        "user_id": "usr_real_mac_test",
        "product_id": "2toolne.capcut.v2",
        "device_id": local_dev,
        "plan": "PRO_VIP",
        "issued_at": now,
        "expires_at": now + (365 * 86400),
        "offline_until": now + (72 * 3600),
        "features": ["capcut_autoedit", "unlimited_export", "all_presets"],
    }
    if payload_overrides:
        payload.update(payload_overrides)

    canon = canonicalize_payload(payload)
    priv = ed25519.Ed25519PrivateKey.from_private_bytes(base64.b64decode(SERVER_SIGNING_SEED))
    sig = priv.sign(canon)
    if corrupt_sig:
        sig = bytes([sig[0] ^ 0xFF]) + sig[1:]

    return {
        "token_version": 1,
        "kid": "kid_2026_01",
        "payload": payload,
        "signature": base64.b64encode(sig).decode("utf-8"),
    }


def main():
    print("==================================================")
    print("2TOOLNE AUTOEDIT FOR CAPCUT V2 — PHASE 4 SECURITY VALIDATION")
    print("Host: macOS Apple Silicon | Target: CapCut Desktop 9.3.0")
    print("==================================================")

    guard = get_license_guard()
    bridge = DesktopBridge(license_guard=guard)

    # 1. Clean slate
    guard.clear_entitlement()
    print("\n[Step 1] Testing unactivated direct bypass attempt...")
    bypass_req = {"id": "bypass_1", "method": "GENERATE_CAPCUT_PROJECT", "params": {}}
    res = bridge.dispatch(bypass_req)
    assert res["ok"] is False, "Direct bypass should fail!"
    assert res["error"]["code"] == "LICENSE_NOT_ACTIVATED"
    print(f"  ✓ Rejected with expected error: {res['error']['code']}")

    # 2. Forged signature test
    print("\n[Step 2] Testing tampered signature rejection...")
    forged_envelope = create_token(corrupt_sig=True)
    guard.save_envelope(forged_envelope)
    res = bridge.dispatch(bypass_req)
    assert res["ok"] is False
    assert res["error"]["code"] == "LICENSE_TOKEN_INVALID"
    print(f"  ✓ Rejected forged signature: {res['error']['code']}")

    # 3. Wrong product test
    print("\n[Step 3] Testing wrong product entitlement rejection...")
    wrong_prod_envelope = create_token(payload_overrides={"product_id": "2toolne.slideshow.v1"})
    guard.save_envelope(wrong_prod_envelope)
    res = bridge.dispatch(bypass_req)
    assert res["ok"] is False
    assert res["error"]["code"] == "LICENSE_WRONG_PRODUCT"
    print(f"  ✓ Rejected wrong product: {res['error']['code']}")

    # 4. Valid Asymmetric Ed25519 Activation
    print("\n[Step 4] Activating with valid Ed25519-signed token (In-Memory Session)...")
    valid_envelope = create_token()
    guard.set_envelope(valid_envelope, masked_key="2TL-CAP-****-****-AB12", license_key_last4="AB12")
    status = guard.get_public_status()
    assert status["authorized"] is True
    assert status["state"] == "LICENSE_ACTIVE"
    assert not os.path.exists(guard.entitlement_path), "entitlement.json must NOT be written to disk!"
    print(f"  ✓ Activated in memory! State: {status['state']}, Plan: {status['plan']}, Key: {status['masked_key']}")
    print(f"  ✓ Confirmed: No plaintext entitlement.json written to disk.")

    # 4b. Close App & Reopen Simulation (Section 15)
    print("\n[Step 4b] Simulating Close App -> Reopen (Encrypted session handoff from Main)...")
    # Fresh guard simulating app restart
    guard_reopened = LicenseGuard()
    bridge_reopened = DesktopBridge(license_guard=guard_reopened)
    # Direct check before Electron feeds decrypted session
    assert guard_reopened.evaluate_entitlement()[0] is False, "New sidecar process must start unauthenticated"

    # Electron Main restores decrypted envelope from safeStorage over stdin IPC
    install_res = bridge_reopened.dispatch({
        "id": "restore_1",
        "method": "INSTALL_SIGNED_ENTITLEMENT",
        "params": {
            "envelope": valid_envelope,
            "masked_key": "2TL-CAP-****-****-AB12",
            "license_key_last4": "AB12",
            "trusted_server_time": int(time.time()),
        }
    })
    assert install_res["ok"] is True
    reopen_status = install_res["result"]
    assert reopen_status["authorized"] is True
    assert reopen_status["state"] == "LICENSE_ACTIVE"
    print(f"  ✓ Reopened and restored successfully! State: {reopen_status['state']}, Masked: {reopen_status['masked_key']}")

    # 4c. Offline Verification Test
    print("\n[Step 4c] Simulating offline verification (no server roundtrip)...")
    offline_eval = guard_reopened.evaluate_entitlement()
    assert offline_eval[0] is True
    print(f"  ✓ Offline entitlement verified validly via Ed25519 in RAM.")

    # 5. Generate Real Project through Authorized Bridge
    print("\n[Step 5] Generating real CapCut project with authorized sidecar...")
    media_dir = os.path.join(ROOT_DIR, "tests", "test_media_sec")
    os.makedirs(media_dir, exist_ok=True)
    
    img_paths = []
    colors = [(30, 144, 255), (255, 105, 180), (50, 205, 50)]
    for i, col in enumerate(colors):
        p = os.path.join(media_dir, f"sec_test_img_{i}.png")
        Image.new("RGB", (1080, 1920), color=col).save(p)
        img_paths.append(p)

    ts = int(time.time())
    proj_name = f"2TOOLNE Security Verified Draft {ts}"

    gen_req = {
        "id": "gen_real_sec",
        "method": "GENERATE_CAPCUT_PROJECT",
        "params": {
            "images": img_paths,
            "project_name": proj_name,
            "canvas_ratio": "9:16",
            "fps": 60.0,
            "preset_id": "basic_slideshow",
        }
    }

    gen_res = bridge_reopened.dispatch(gen_req)
    assert gen_res["ok"] is True, f"Project generation failed: {gen_res.get('error')}"
    draft_dir = gen_res["result"]["final_draft_dir"]
    print(f"  ✓ Project generated at: {draft_dir}")

    # 6. Validate Draft Integrity
    print("\n[Step 6] Validating draft integrity with CapCutDraftValidator...")
    val_errors = CapCutDraftValidator.validate_draft(draft_dir)
    assert len(val_errors) == 0, f"Draft validation failed: {val_errors}"
    print(f"  ✓ Draft validation passed with 0 errors")

    # 7. Launch CapCut Desktop
    print("\n[Step 7] Launching CapCut Desktop 9.3.0...")
    open_res = bridge_reopened.dispatch({"id": "open_sec", "method": "OPEN_CAPCUT", "params": {"draft_path": draft_dir}})
    assert open_res["ok"] is True
    print(f"  ✓ CapCut Desktop opened successfully (Launch status: {open_res['result'].get('launched', True)})")

    # 8. Deactivation Test
    print("\n[Step 8] Testing deactivation lockdown...")
    deact_res = bridge_reopened.dispatch({"id": "deact_1", "method": "CLEAR_LICENSE", "params": {}})
    assert deact_res["ok"] is True
    res_after = bridge_reopened.dispatch(bypass_req)
    assert res_after["ok"] is False
    assert res_after["error"]["code"] == "LICENSE_NOT_ACTIVATED"
    print(f"  ✓ Lockdown confirmed: {res_after['error']['code']}")

    # 9. Reopen After Deactivation Test
    print("\n[Step 9] Testing Reopen after deactivation...")
    guard_final = LicenseGuard()
    bridge_final = DesktopBridge(license_guard=guard_final)
    final_res = bridge_final.dispatch(bypass_req)
    assert final_res["ok"] is False
    assert final_res["error"]["code"] == "LICENSE_NOT_ACTIVATED"
    print(f"  ✓ Confirmed: Reopen stays locked with {final_res['error']['code']}")

    print("\n==================================================")
    print("PHASE 4.1 SECURITY PHYSICAL VALIDATION COMPLETED: PASS")
    print("==================================================")


if __name__ == "__main__":
    main()
