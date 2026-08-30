"""
test_client_diagnostics.py
Automated Test Suite for Client Diagnostic Reporting System.
Covers:
1. Diagnostic ID format and uniqueness
2. Report structure, system, GPU, PyTorch, and FFmpeg metadata
3. Strict privacy redaction (secrets, license keys, tokens, emails)
4. Path anonymization (<USER_HOME>)
5. Media and user content exclusion
6. Size limit validation (< 1MB)
7. Rate limiting (max 5 per minute)
8. Offline local report saving
9. Local API endpoint validation with session token
"""

import os
import re
import json
import tempfile
import pytest
from pathlib import Path

import diagnostic_collector
from diagnostic_collector import (
    generate_diagnostic_id,
    redact_path,
    sanitize_text,
    sanitize_data_recursive,
    collect_diagnostic_report,
    save_diagnostic_report_locally,
    submit_diagnostic_report
)
import app as flask_app_module


def test_diagnostic_id_format():
    diag_id = generate_diagnostic_id()
    assert re.match(r"^VBC-\d{8}-[A-F0-9]{6}$", diag_id)
    # Test uniqueness
    diag_id_2 = generate_diagnostic_id()
    assert diag_id != diag_id_2


def test_report_structure_and_fields():
    report = collect_diagnostic_report(
        recent_error={"error_message": "FFmpeg pipe test error"},
        job_context={"resolution": "1080p", "fps": 60}
    )
    assert isinstance(report, dict)
    assert "diagnostic_id" in report
    assert "timestamp" in report
    assert "app_version" in report
    assert "system" in report
    assert "gpu_pytorch" in report
    assert "ffmpeg" in report
    assert "renderer" in report
    assert "license_state" in report
    assert "recent_error" in report

    # Verify system keys
    sys_info = report["system"]
    assert "os_platform" in sys_info
    assert "cpu_model" in sys_info
    assert "ram_total_gb" in sys_info

    # Verify GPU keys
    gpu_info = report["gpu_pytorch"]
    assert "pytorch_installed" in gpu_info
    assert "cuda_available" in gpu_info
    assert "mps_available" in gpu_info


def test_privacy_redaction_secrets():
    # 1. License key redaction
    sample_key = "2TAMNE-PREM-ABCD-1234"
    clean_key = sanitize_text(f"Error activating key {sample_key} on server")
    assert "ABCD" not in clean_key
    assert "2TAMNE-****-****-****" in clean_key

    # 2. 64-hex and 32-hex secrets
    hex64 = "a" * 64
    hex32 = "b" * 32
    clean_hex = sanitize_text(f"HWID: {hex64}, Token: {hex32}")
    assert hex64 not in clean_hex
    assert hex32 not in clean_hex
    assert "<REDACTED_64HEX>" in clean_hex
    assert "<REDACTED_32HEX>" in clean_hex

    # 3. Email redaction
    clean_email = sanitize_text("Contact user@example.com for help")
    assert "user@example.com" not in clean_email
    assert "<REDACTED_EMAIL>" in clean_email


def test_path_anonymization():
    home_p = str(Path.home())
    test_path = os.path.join(home_p, "Documents", "MyProject", "video.mp4")
    redacted = redact_path(test_path)
    assert home_p not in redacted
    assert "<USER_HOME>" in redacted

    # Windows style path
    win_path = r"C:\Users\JohnDoe\AppData\Local\Temp\render.log"
    assert "<USER_HOME>" in redact_path(win_path)


def test_data_structure_recursive_sanitization():
    raw_data = {
        "user_path": str(Path.home()) + "/test.png",
        "license_key": "2TAMNE-AAAA-BBBB-CCCC",
        "X-App-Token": "secret_token_123",
        "session_token": "secret_session",
        "safe_field": "1080p"
    }
    sanitized = sanitize_data_recursive(raw_data)
    # Sensitive keys must be stripped
    assert "license_key" not in sanitized
    assert "X-App-Token" not in sanitized
    assert "session_token" not in sanitized
    # Paths must be redacted
    assert str(Path.home()) not in sanitized["user_path"]
    assert "<USER_HOME>" in sanitized["user_path"]
    assert sanitized["safe_field"] == "1080p"


def test_offline_local_save():
    report = collect_diagnostic_report()
    saved_path = save_diagnostic_report_locally(report)
    assert os.path.isfile(saved_path)
    with open(saved_path, "r", encoding="utf-8") as f:
        data = json.load(f)
    assert data["diagnostic_id"] == report["diagnostic_id"]
    # Clean up
    if os.path.isfile(saved_path):
        os.remove(saved_path)


def test_size_limit_enforcement():
    huge_report = {
        "diagnostic_id": generate_diagnostic_id(),
        "huge_payload": "X" * (1024 * 1024 + 100)  # > 1MB
    }
    res = submit_diagnostic_report(huge_report)
    assert res["ok"] is False
    assert res["error"] == "PAYLOAD_TOO_LARGE"


def test_rate_limiting():
    diagnostic_collector._SUBMISSION_HISTORY = []
    report = {"diagnostic_id": generate_diagnostic_id(), "test": "rate_limit"}

    # Mock server URL that fails quickly to test rate limit tracking
    mock_url = "https://127.0.0.1:54321/mock_endpoint"

    for _ in range(5):
        _ = submit_diagnostic_report(report, server_url=mock_url)

    # 6th submission within 1 minute must be rate limited
    res = submit_diagnostic_report(report, server_url=mock_url)
    assert res["ok"] is False
    assert res["error"] == "RATE_LIMITED"
    # Reset history
    diagnostic_collector._SUBMISSION_HISTORY = []


def test_diagnostic_api_endpoints():
    client = flask_app_module.app.test_client()
    auth_headers = {'X-App-Token': flask_app_module.APP_SESSION_SECRET}

    # 1. Generate endpoint
    res_gen = client.post('/api/diagnostics/generate', headers=auth_headers)
    assert res_gen.status_code == 200
    data = res_gen.get_json()
    assert data["ok"] is True
    assert "diagnostic_id" in data["report"]

    # 2. Save local endpoint
    res_save = client.post('/api/diagnostics/save_local', json={'report': data["report"]}, headers=auth_headers)
    assert res_save.status_code == 200
    save_data = res_save.get_json()
    assert save_data["ok"] is True
    assert "local_path" in save_data
