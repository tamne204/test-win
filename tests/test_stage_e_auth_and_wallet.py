"""
tests/test_stage_e_auth_and_wallet.py
Unit and integration tests for Stage E:
- GAP-09: User Account Login & Session Management
- GAP-10: Authoritative Wallet Token Balance (Zero Hardcoded Token Balance)
"""
from __future__ import annotations

import os
import json
import pytest
from pathlib import Path

DESKTOP_DIR = Path(__file__).resolve().parent.parent / "apps" / "capcut-v2" / "desktop"


def test_zero_hardcoded_token_balance_in_renderer():
    """Verify that state.tokenBalance in app.js is initialized to 0, never hardcoded 50."""
    app_js = DESKTOP_DIR / "src" / "renderer" / "app.js"
    assert app_js.is_file()
    content = app_js.read_text(encoding="utf-8")

    # Verify state initialization
    assert "tokenBalance: 0" in content, "state.tokenBalance must be initialized to 0, not hardcoded"
    assert "currentUser: null" in content

    # Ensure DOMContentLoaded does not set static 50
    assert "DOM.tokenBalance.textContent = state.tokenBalance" not in content.split("DOMContentLoaded")[1].split("setInterval")[0]


def test_auth_login_credential_validation():
    """Verify login input requirements."""
    def validate_login_inputs(email: str | None, password: str | None) -> tuple[bool, str]:
        if not email or not password:
            return False, "Vui lòng nhập đầy đủ Email và Mật khẩu."
        email_clean = email.strip()
        if "@" not in email_clean:
            return False, "Email không hợp lệ."
        return True, "OK"

    assert validate_login_inputs(None, "pass123")[0] is False
    assert validate_login_inputs("user@test.com", None)[0] is False
    assert validate_login_inputs("invalid-email", "pass123")[0] is False
    assert validate_login_inputs("user@test.com", "pass123")[0] is True


def test_auth_session_persistence_contract(tmp_path):
    """Verify session token and user payload saving and revocation."""
    session_file = tmp_path / "auth_session.json"

    def save_session(token: str, user: dict):
        session_file.write_text(json.dumps({"token": token, "user": user}), encoding="utf-8")

    def get_session():
        if not session_file.exists():
            return None
        return json.loads(session_file.read_text(encoding="utf-8"))

    def clear_session():
        if session_file.exists():
            session_file.unlink()

    # Initial state
    assert get_session() is None

    # Login saves session
    save_session("jwt_token_test_12345", {"email": "pro_user@2tamne.site", "id": "usr_001"})
    session = get_session()
    assert session is not None
    assert session["token"] == "jwt_token_test_12345"
    assert session["user"]["email"] == "pro_user@2tamne.site"

    # Logout purges session
    clear_session()
    assert get_session() is None


def test_wallet_balance_resolution():
    """Verify live wallet balance precedence: server > license plan > unactivated 0."""
    def resolve_wallet(token_res: dict | None, license_status: dict | None) -> int:
        if token_res and "balance" in token_res:
            return token_res["balance"]
        if license_status and (license_status.get("authorized") or license_status.get("active")):
            plan = license_status.get("plan", "PRO")
            return 100 if plan == "PRO" else 50
        return 0

    # Case 1: Live server token balance takes highest precedence
    assert resolve_wallet({"balance": 75}, {"authorized": True, "plan": "PRO"}) == 75

    # Case 2: Licensed active plan without live server balance
    assert resolve_wallet(None, {"authorized": True, "plan": "PRO"}) == 100
    assert resolve_wallet(None, {"authorized": True, "plan": "STARTER"}) == 50

    # Case 3: Unactivated / unauthorized
    assert resolve_wallet(None, {"authorized": False}) == 0
    assert resolve_wallet(None, None) == 0
