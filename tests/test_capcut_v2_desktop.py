"""
tests/test_capcut_v2_desktop.py
Comprehensive unit and integration tests for Phase 3 Desktop Architecture:
1. Versioned JSON-RPC IPC protocol (protocol.py)
2. DesktopBridge dispatching & error normalization (bridge.py)
3. Custom presets lifecycle through DesktopBridge
4. Input validation and EditPlan generation through DesktopBridge
5. Cross-platform path handling (Unicode, Vietnamese diacritics, spaces)
6. Python Core Sidecar child-process loop over stdin/stdout
"""
from __future__ import annotations

import os
import sys
import json
import time
import subprocess
import pytest
from PIL import Image

# Ensure apps/capcut-v2 is in python path
V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from desktop_bridge.protocol import (
    PROTOCOL_VERSION,
    create_response,
    create_error,
    create_notification,
    serialize_message,
    parse_message,
    ERR_METHOD_NOT_FOUND,
    ERR_INVALID_PARAMS,
    ERR_INVALID_REQUEST,
)
from desktop_bridge.bridge import DesktopBridge
import base64
from cryptography.hazmat.primitives.asymmetric import ed25519
from core.security.device_id import get_privacy_device_id
from core.security.ed25519_verifier import canonicalize_payload
from core.security.license_guard import get_license_guard


@pytest.fixture(autouse=True)
def activate_desktop_license():
    """Ensure desktop tests run with a validly activated license."""
    guard = get_license_guard()
    now = int(time.time())
    payload = {
        "license_id": "lic_test_desktop_autouse",
        "license_key": "2TL-CAP-TEST-AUTO-USE1-2026",
        "user_id": "usr_desktop_test",
        "product_id": "2toolne.capcut.v2",
        "device_id": get_privacy_device_id(),
        "plan": "PRO",
        "issued_at": now,
        "expires_at": now + (30 * 86400),
        "offline_until": now + (72 * 3600),
        "features": ["capcut_autoedit", "unlimited_export", "all_presets"],
    }
    canon = canonicalize_payload(payload)
    seed = base64.b64decode("wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE=")
    priv = ed25519.Ed25519PrivateKey.from_private_bytes(seed)
    sig_b64 = base64.b64encode(priv.sign(canon)).decode("utf-8")
    envelope = {
        "token_version": 1,
        "kid": "kid_2026_01",
        "payload": payload,
        "signature": sig_b64,
    }
    guard.save_envelope(envelope)
    yield
    guard.clear_entitlement()


# ==============================================================================
# 1. Protocol Tests
# ==============================================================================

def test_protocol_message_builders():
    """Verify response, error, and notification packet structures."""
    # Response
    res = create_response("req-123", {"status": "ok", "value": 42})
    assert res["id"] == "req-123"
    assert res["protocol"] == PROTOCOL_VERSION
    assert res["ok"] is True
    assert res["result"]["value"] == 42

    # Error
    err = create_error("req-123", "TEST_ERROR", "Something broke", data={"code": 500})
    assert err["id"] == "req-123"
    assert err["ok"] is False
    assert err["error"]["code"] == "TEST_ERROR"
    assert err["error"]["message"] == "Something broke"
    assert err["error"]["data"]["code"] == 500

    # Notification
    notif = create_notification("progress", {"percent": 50, "stage": "TEST"})
    assert notif["type"] == "notification"
    assert notif["event"] == "progress"
    assert notif["data"]["percent"] == 50


def test_protocol_serialization_and_parsing():
    """Verify single line serialization with newline and parsing."""
    msg = {"id": "1", "protocol": 1, "method": "PING", "params": {}}
    line = serialize_message(msg)
    assert line.endswith("\n")
    assert not line.endswith("\n\n")

    parsed = parse_message(line)
    assert parsed == msg

    # Malformed inputs
    with pytest.raises(ValueError):
        parse_message("")

    with pytest.raises(ValueError):
        parse_message("   \n")

    with pytest.raises(ValueError):
        parse_message("not json at all")

    with pytest.raises(ValueError):
        parse_message('["array_not_dict"]')


# ==============================================================================
# 2. DesktopBridge Command Tests
# ==============================================================================

def test_bridge_ping(tmp_path):
    """Verify PING command returns pong and version info."""
    bridge = DesktopBridge(workspace_root=str(tmp_path))
    req = {"id": "req-ping", "protocol": 1, "method": "PING", "params": {}}
    res = bridge.dispatch(req)
    assert res["id"] == "req-ping"
    assert res["ok"] is True
    assert res["result"]["pong"] is True
    assert res["result"]["protocol"] == 1
    assert "version" in res["result"]
    assert res["result"]["product_id"] == "2toolne.capcut.v2"


def test_bridge_get_app_info(tmp_path):
    """Verify GET_APP_INFO command returns desktop metadata."""
    bridge = DesktopBridge(workspace_root=str(tmp_path))
    req = {"id": "req-info", "protocol": 1, "method": "GET_APP_INFO", "params": {}}
    res = bridge.dispatch(req)
    assert res["ok"] is True
    assert res["result"]["product_id"] == "2toolne.capcut.v2"
    assert res["result"]["app_name"] == "2toolne AutoEdit for CapCut"
    assert "platform" in res["result"]
    assert "python_version" in res["result"]


def test_bridge_detect_capcut(tmp_path):
    """Verify DETECT_CAPCUT command runs detector."""
    bridge = DesktopBridge(workspace_root=str(tmp_path))
    req = {"id": "req-det", "protocol": 1, "method": "DETECT_CAPCUT", "params": {}}
    res = bridge.dispatch(req)
    assert res["ok"] is True
    assert "status" in res["result"]
    assert "detected_version" in res["result"]


def test_bridge_presets_lifecycle(tmp_path):
    """Verify GET_PRESETS, SAVE_CUSTOM_PRESET, and DELETE_CUSTOM_PRESET."""
    presets_dir = str(tmp_path / "custom_presets")
    bridge = DesktopBridge(workspace_root=str(tmp_path), user_presets_dir=presets_dir)

    # 1. List presets
    req_list = {"id": "p-list", "method": "GET_PRESETS", "params": {}}
    res_list = bridge.dispatch(req_list)
    assert res_list["ok"] is True
    preset_ids = [p["id"] for p in res_list["result"]["presets"]]
    assert "basic_slideshow" in preset_ids
    assert "tiktok_fast" in preset_ids

    # 2. Save custom preset
    save_params = {
        "name": "Bridge Vlog Preset",
        "scene_duration_s": 4.5,
        "motion_sequence": ["PAN_UP", "PAN_DOWN"],
        "canvas_ratio": "16:9",
        "fps": 30.0,
    }
    req_save = {"id": "p-save", "method": "SAVE_CUSTOM_PRESET", "params": save_params}
    res_save = bridge.dispatch(req_save)
    assert res_save["ok"] is True
    assert res_save["result"]["saved"] is True
    preset_id = res_save["result"]["preset"]["id"]
    assert "bridge_vlog_preset" in preset_id

    # 3. Delete custom preset
    req_del = {"id": "p-del", "method": "DELETE_CUSTOM_PRESET", "params": {"preset_id": preset_id}}
    res_del = bridge.dispatch(req_del)
    assert res_del["ok"] is True
    assert res_del["result"]["deleted"] is True


def test_bridge_validate_inputs(tmp_path):
    """Verify VALIDATE_INPUTS flags missing and detects present media."""
    bridge = DesktopBridge(workspace_root=str(tmp_path))

    # Missing files
    req_invalid = {
        "id": "v-1",
        "method": "VALIDATE_INPUTS",
        "params": {
            "images": [str(tmp_path / "missing.png")],
            "audio_path": str(tmp_path / "missing.mp3"),
        },
    }
    res_invalid = bridge.dispatch(req_invalid)
    assert res_invalid["ok"] is True
    assert res_invalid["result"]["valid"] is False
    assert len(res_invalid["result"]["errors"]) >= 2

    # Valid files
    img = tmp_path / "valid.png"
    Image.new("RGB", (100, 100)).save(img)
    aud = tmp_path / "valid.wav"
    with open(aud, "wb") as f:
        f.write(b"RIFF$\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00D\xac\x00\x00\x88X\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00")

    req_valid = {
        "id": "v-2",
        "method": "VALIDATE_INPUTS",
        "params": {
            "images": [str(img)],
            "audio_path": str(aud),
        },
    }
    res_valid = bridge.dispatch(req_valid)
    assert res_valid["ok"] is True
    assert res_valid["result"]["valid"] is True
    assert len(res_valid["result"]["errors"]) == 0


def test_bridge_unknown_method(tmp_path):
    """Verify unknown method returns METHOD_NOT_FOUND error."""
    bridge = DesktopBridge(workspace_root=str(tmp_path))
    req = {"id": "bad-1", "method": "UNSUPPORTED_METHOD", "params": {}}
    res = bridge.dispatch(req)
    assert res["ok"] is False
    assert res["error"]["code"] == ERR_METHOD_NOT_FOUND


# ==============================================================================
# 3. Cross-Platform Path Safety (Unicode, Spaces, Vietnamese)
# ==============================================================================

def test_bridge_cross_platform_unicode_and_vietnamese_paths(tmp_path):
    """Verify DesktopBridge safely handles paths with Vietnamese diacritics, spaces, and Unicode."""
    unicode_dir = tmp_path / "Thư mục dự án 2TOOLNE mới"
    unicode_dir.mkdir()

    img_path = unicode_dir / "ảnh hoàng hôn tuyệt đẹp.png"
    Image.new("RGB", (1080, 1920), color=(200, 100, 50)).save(img_path)

    bridge = DesktopBridge(workspace_root=str(unicode_dir))
    req = {
        "id": "u-1",
        "method": "BUILD_EDIT_PLAN",
        "params": {
            "images": [str(img_path)],
            "project_name": "Dự Án Tiếng Việt Có Dấu 100%",
        },
    }
    res = bridge.dispatch(req)
    assert res["ok"] is True
    assert res["result"]["project"]["name"] == "Dự Án Tiếng Việt Có Dấu 100%"
    assert res["result"]["clips"][0]["media_path"] == str(img_path)


# ==============================================================================
# 4. Sidecar Process Subprocess Loop (stdin/stdout IPC)
# ==============================================================================

def test_sidecar_process_execution():
    """Verify sidecar_main.py operates correctly as an independent process via stdin/stdout."""
    sidecar_script = os.path.join(V2_DIR, "desktop_bridge", "sidecar_main.py")

    proc = subprocess.Popen(
        [sys.executable, sidecar_script],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )

    try:
        # 1. Send PING
        ping_req = json.dumps({"id": "proc-ping", "protocol": 1, "method": "PING", "params": {}}) + "\n"
        proc.stdin.write(ping_req)
        proc.stdin.flush()

        stdout_line = proc.stdout.readline()
        assert stdout_line.strip(), "Expected stdout line from sidecar"
        res = json.loads(stdout_line.strip())
        assert res["id"] == "proc-ping"
        assert res["ok"] is True
        assert res["result"]["pong"] is True

        # 2. Send GET_APP_INFO
        info_req = json.dumps({"id": "proc-info", "protocol": 1, "method": "GET_APP_INFO", "params": {}}) + "\n"
        proc.stdin.write(info_req)
        proc.stdin.flush()

        stdout_line2 = proc.stdout.readline()
        res2 = json.loads(stdout_line2.strip())
        assert res2["id"] == "proc-info"
        assert res2["ok"] is True
        assert res2["result"]["product_id"] == "2toolne.capcut.v2"

        # 3. Close stdin (simulate EOF)
        proc.stdin.close()
        exit_code = proc.wait(timeout=5)
        assert exit_code == 0, f"Sidecar exited with non-zero code: {exit_code}"

    finally:
        if proc.poll() is None:
            proc.kill()


def test_bridge_subtitle_methods_direct(tmp_path):
    """Verify DesktopBridge subtitle alignment methods."""
    audio_file = os.path.abspath("reports/windows_rc/external_lab/TEST_MEDIA/voice_sample.wav")
    bridge = DesktopBridge(workspace_root=str(tmp_path))

    # Temporarily bypass license for unit test of bridge dispatch logic
    bridge.license_guard.require_entitlement = lambda **kw: {"status": "ACTIVE"}

    # 1. CANCEL test
    cancel_res = bridge.dispatch({
        "id": "sub-c1",
        "method": "CANCEL_SUBTITLE_ALIGNMENT",
        "params": {},
    })
    assert cancel_res["ok"] is True
    assert cancel_res["result"]["cancelled"] is True

    # 2. EXPORT_SRT test
    test_srt = "1\n00:00:01,000 --> 00:00:03,000\nXin chào\n"
    out_srt_path = str(tmp_path / "exported.srt")
    export_res = bridge.dispatch({
        "id": "sub-e1",
        "method": "EXPORT_SRT",
        "params": {
            "srt_content": test_srt,
            "target_path": out_srt_path,
        }
    })
    assert export_res["ok"] is True
    assert os.path.isfile(out_srt_path)
    with open(out_srt_path, "r", encoding="utf-8") as f:
        assert f.read() == test_srt


def test_faster_whisper_vad_asset_packaged():
    """Verify silero_vad_v6.onnx exists and has size > 0 in built sidecar bundle."""
    sidecar_dist = os.path.abspath("apps/capcut-v2/packaging/dist/autoedit-core")
    if not os.path.isdir(sidecar_dist):
        pytest.skip("autoedit-core not yet packaged in dist; skipping packaging regression test.")

    vad_path = os.path.join(
        sidecar_dist,
        "_internal",
        "faster_whisper",
        "assets",
        "silero_vad_v6.onnx",
    )
    assert os.path.isfile(vad_path), f"silero_vad_v6.onnx missing from bundle: {vad_path}"
    file_size = os.path.getsize(vad_path)
    assert file_size > 0, f"silero_vad_v6.onnx is empty (0 bytes): {vad_path}"
    assert file_size > 1_000_000, f"silero_vad_v6.onnx unexpectedly small ({file_size} bytes)"
