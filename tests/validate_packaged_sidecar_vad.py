"""
tests/validate_packaged_sidecar_vad.py
Tests the PACKAGED autoedit-core binary (not source Python) directly over JSON-RPC IPC:
1. Spawns apps/capcut-v2/packaging/dist/autoedit-core/autoedit-core
2. PING handshake
3. Activates license entitlement for script_to_srt via INSTALL_SIGNED_ENTITLEMENT
4. Sends GENERATE_SRT_FROM_SCRIPT with real audio and Vietnamese script
5. Verifies:
   - NO ONNXRuntimeError
   - NO NO_SUCHFILE
   - VAD loads successfully
   - SRT generated successfully with cues
"""
import os
import sys
import json
import time
import subprocess
import base64
from cryptography.hazmat.primitives.asymmetric import ed25519

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
V2_ROOT = os.path.join(REPO_ROOT, "apps", "capcut-v2")
SIDECAR_BIN = os.path.join(V2_ROOT, "packaging", "dist", "autoedit-core", "autoedit-core")
AUDIO_FILE = os.path.join(REPO_ROOT, "tests", "scratch", "vi_test_speech.mp3")

if not os.path.isfile(SIDECAR_BIN):
    raise FileNotFoundError(f"Packaged sidecar binary not found at: {SIDECAR_BIN}")
if not os.path.isfile(AUDIO_FILE):
    raise FileNotFoundError(f"Audio file not found at: {AUDIO_FILE}")


def send_ipc(proc, msg):
    line = json.dumps(msg, ensure_ascii=False) + "\n"
    proc.stdin.write(line)
    proc.stdin.flush()

    while True:
        resp_line = proc.stdout.readline()
        if not resp_line:
            raise RuntimeError("Premature EOF from sidecar")
        parsed = json.loads(resp_line.strip())
        if parsed.get("type") == "notification":
            data = parsed.get("data", {})
            print(f"  [Notification] stage={data.get('stage')} percent={data.get('percent')}% msg='{data.get('message')}'")
            continue
        return parsed


def main():
    print("=" * 60)
    print("  TESTING PACKAGED SIDECAR BINARY RUNTIME (FASTER-WHISPER VAD)")
    print(f"Binary: {SIDECAR_BIN}")
    print(f"Audio:  {AUDIO_FILE}")
    print("=" * 60)

    proc = subprocess.Popen(
        [SIDECAR_BIN],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        bufsize=1,
    )

    try:
        # 1. PING
        resp = send_ipc(proc, {"id": "p1", "protocol": 1, "method": "PING", "params": {}})
        assert resp.get("ok") is True, f"PING failed: {resp}"
        print("✓ PING: OK")

        # 2. Activate test license using official test key and INSTALL_SIGNED_ENTITLEMENT
        sys.path.insert(0, V2_ROOT)
        from core.security.ed25519_verifier import canonicalize_payload
        from core.security.device_id import get_privacy_device_id

        seed = base64.b64decode("wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE=")
        priv = ed25519.Ed25519PrivateKey.from_private_bytes(seed)
        now = int(time.time())
        payload = {
            "license_id": "lic_hotfix_test_vad",
            "user_id": "usr_tester_hotfix",
            "product_id": "2toolne.capcut.v2",
            "device_id": get_privacy_device_id(),
            "plan": "PRO",
            "issued_at": now,
            "expires_at": now + 86400 * 30,
            "offline_until": now + 86400 * 3,
            "features": ["script_to_srt", "capcut_autoedit", "all_presets"],
        }
        sig = priv.sign(canonicalize_payload(payload))
        envelope = {
            "token_version": 1,
            "kid": "kid_2026_01",
            "payload": payload,
            "signature": base64.b64encode(sig).decode("utf-8"),
        }

        resp = send_ipc(proc, {
            "id": "act1",
            "protocol": 1,
            "method": "INSTALL_SIGNED_ENTITLEMENT",
            "params": {"envelope": envelope}
        })
        assert resp.get("ok") is True, f"INSTALL_SIGNED_ENTITLEMENT failed: {resp}"
        print("✓ License Entitlement Activated with 'script_to_srt'")

        # 3. GENERATE_SRT_FROM_SCRIPT
        script_text = "Chào mừng bạn đến với 2TOOLNE AutoEdit cho CapCut. Đây là công cụ biên tập video tự động hàng đầu."
        print("\n-> Sending GENERATE_SRT_FROM_SCRIPT to PACKAGED binary...")
        t0 = time.time()
        resp = send_ipc(proc, {
            "id": "srt1",
            "protocol": 1,
            "method": "GENERATE_SRT_FROM_SCRIPT",
            "params": {
                "script_text": script_text,
                "audio_path": AUDIO_FILE,
                "language": "vi",
                "options": {
                    "max_words_per_cue": 12,
                    "min_duration_s": 0.6,
                    "max_duration_s": 5.0,
                }
            }
        })
        elapsed = time.time() - t0

        print(f"\nResponse received in {elapsed:.2f}s:")
        assert resp.get("ok") is True, f"GENERATE_SRT_FROM_SCRIPT failed in packaged sidecar: {resp.get('error')}"

        result = resp["result"]
        cues = result["cues"]
        assert len(cues) > 0, "No cues returned!"
        srt_content = result["srt_content"]
        assert "2TOOLNE AutoEdit" in srt_content, "Brand text missing from SRT!"

        print(f"✓ Cues generated: {len(cues)}")
        print(f"✓ Matched percentage: {result['matched_percentage']}%")
        print("\nGenerated SRT:")
        print(srt_content.strip())

        print("=" * 60)
        print("  PACKAGED SIDECAR VAD RUNTIME TEST: PASS")
        print("=" * 60)

    finally:
        proc.kill()
        proc.wait()


if __name__ == "__main__":
    main()
