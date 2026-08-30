"""
test_mandatory_license_gate.py
Mandatory License Entitlement Gate Test Suite (Phase 1).
Verifies:
1. Fresh install without key -> 403 LICENSE_REQUIRED
2. Direct POST /render bypass attempt -> 403 Forbidden
3. Direct POST /tts/generate without license -> 403 Forbidden
4. Direct POST /api/forced-align without license -> 403 Forbidden
5. Valid active license -> passes license gate
6. Expired license state -> 403 Forbidden
7. Revoked license state -> 403 Forbidden
8. Valid offline grace state -> passes license gate
9. Invalid X-App-Token + valid license -> 401 Unauthorized
10. Valid X-App-Token + invalid license -> 403 Forbidden
"""

import os
import sys
import pytest
import license_manager
import app as flask_app_module


@pytest.fixture(autouse=True)
def restore_license_state():
    """Ensure clean license state before and after each test."""
    orig_status = dict(license_manager._current_status)
    yield
    license_manager._current_status = orig_status


def test_fresh_install_render_blocked():
    """Fresh install with unactivated state must return 403 LICENSE_REQUIRED."""
    license_manager._current_status = {
        "ok": False,
        "status": "unactivated",
        "tier": "NONE",
        "days_left": 0,
        "expires_at": "",
        "message": "Chưa nhập mã bản quyền.",
        "features": []
    }
    client = flask_app_module.app.test_client()
    auth_header = {'X-App-Token': flask_app_module.APP_SESSION_SECRET}

    res = client.post('/render', headers=auth_header)
    assert res.status_code == 403
    data = res.get_json()
    assert data["ok"] is False
    assert data["error"] == "LICENSE_REQUIRED"


def test_protected_endpoints_blocked_when_unlicensed():
    """Verify all protected AI and subtitle endpoints are blocked when unlicensed."""
    license_manager._current_status = {
        "ok": False,
        "status": "unactivated",
        "tier": "NONE",
        "days_left": 0,
        "expires_at": "",
        "message": "Chưa nhập mã bản quyền.",
        "features": []
    }
    client = flask_app_module.app.test_client()
    auth_header = {'X-App-Token': flask_app_module.APP_SESSION_SECRET}

    for endpoint in ['/tts/generate', '/api/forced-align', '/subtitles/align', '/subtitles/preview', '/api/subtitles/translate']:
        res = client.post(endpoint, headers=auth_header)
        assert res.status_code == 403, f"Endpoint {endpoint} failed to enforce license gate"
        data = res.get_json()
        assert data["error"] == "LICENSE_REQUIRED"


def test_expired_license_blocked():
    """Expired license must return 403 LICENSE_REQUIRED."""
    license_manager._current_status = {
        "ok": False,
        "status": "EXPIRED",
        "tier": "VIP",
        "days_left": 0,
        "expires_at": "2025-01-01 00:00:00",
        "message": "Bản quyền đã hết hạn.",
        "features": []
    }
    client = flask_app_module.app.test_client()
    auth_header = {'X-App-Token': flask_app_module.APP_SESSION_SECRET}

    res = client.post('/render', headers=auth_header)
    assert res.status_code == 403
    data = res.get_json()
    assert data["error"] == "LICENSE_REQUIRED"


def test_revoked_license_blocked():
    """Revoked license must return 403 LICENSE_REQUIRED."""
    license_manager._current_status = {
        "ok": False,
        "status": "REVOKED",
        "tier": "NONE",
        "days_left": 0,
        "expires_at": "",
        "message": "Key bản quyền đã bị vô hiệu hóa.",
        "features": []
    }
    client = flask_app_module.app.test_client()
    auth_header = {'X-App-Token': flask_app_module.APP_SESSION_SECRET}

    res = client.post('/render', headers=auth_header)
    assert res.status_code == 403
    data = res.get_json()
    assert data["error"] == "LICENSE_REQUIRED"


def test_valid_active_license_passes_gate():
    """Valid active license passes gate and proceeds to parameter validation."""
    license_manager._current_status = {
        "ok": True,
        "status": "valid",
        "tier": "VIP",
        "days_left": 30,
        "expires_at": "2026-12-31 23:59:59",
        "message": "Bản quyền hợp lệ",
        "features": ["all"]
    }
    client = flask_app_module.app.test_client()
    auth_header = {'X-App-Token': flask_app_module.APP_SESSION_SECRET}

    # Calling /render with no files should pass license check (not 403), reaching input validation
    res = client.post('/render', headers=auth_header)
    assert res.status_code != 403


def test_offline_grace_period_passes_gate():
    """Offline grace cache entitlement passes gate when network is offline."""
    license_manager._current_status = {
        "ok": True,
        "status": "offline_cache",
        "tier": "VIP",
        "days_left": 15,
        "expires_at": "2026-12-31 23:59:59",
        "message": "Chế độ ngoại tuyến (Hợp lệ)",
        "features": ["all"]
    }
    client = flask_app_module.app.test_client()
    auth_header = {'X-App-Token': flask_app_module.APP_SESSION_SECRET}

    res = client.post('/render', headers=auth_header)
    assert res.status_code != 403


def test_auth_and_license_matrix():
    """
    Test 2-Factor Security Matrix:
    - Invalid Token + Valid License -> 401 Unauthorized
    - Valid Token + Invalid License -> 403 License Required
    - Valid Token + Valid License   -> 200/Passes Gates
    """
    client = flask_app_module.app.test_client()

    # 1. Invalid Token + Valid License -> 401
    license_manager._current_status = {"ok": True, "status": "valid"}
    res_bad_token = client.post('/render', headers={'X-App-Token': 'bad_token'})
    assert res_bad_token.status_code == 401

    # 2. Valid Token + Invalid License -> 403
    license_manager._current_status = {"ok": False, "status": "unactivated"}
    res_unlicensed = client.post('/render', headers={'X-App-Token': flask_app_module.APP_SESSION_SECRET})
    assert res_unlicensed.status_code == 403

    # 3. Valid Token + Valid License -> Passes Auth & License Gates
    license_manager._current_status = {"ok": True, "status": "valid"}
    res_valid = client.post('/render', headers={'X-App-Token': flask_app_module.APP_SESSION_SECRET})
    assert res_valid.status_code not in (401, 403)
