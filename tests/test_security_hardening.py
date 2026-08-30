"""
test_security_hardening.py
Security Hardening Regression Test Suite.
Covers:
1. Path Traversal & Injection Attack Prevention (Canonical Path Validation)
2. Localhost Authentication & App-Scoped Session Secret
3. Host Header & DNS Rebinding Protection
4. WMIC-Free HWID Generation & Log Redaction
5. Digital Signature & SHA-256 Update Integrity
6. Authoritative Version Consistency
7. FFmpeg Binary Trust & Audit
8. Concurrency Limits & Resource Guardrails
"""

import os
import sys
import hmac
import hashlib
import tempfile
import pytest
from pathlib import Path

from ffmpeg_utils import validate_canonical_path, get_ffmpeg_security_info
from license_manager import get_hwid, redact_license_key, redact_token
from updater.checksum import compute_sha256, verify_sha256, verify_package_signature, TRUSTED_UPDATE_ROOT_KEY
from version import __version__ as APP_VERSION
import app as flask_app_module


# ── 1. Path Traversal & Canonical Path Validation ────────────────────────
def test_canonical_path_traversal_prevention():
    base_dir = tempfile.mkdtemp()
    test_file = os.path.join(base_dir, "valid.png")
    with open(test_file, "w") as f:
        f.write("test")

    # Valid relative path should resolve
    valid = validate_canonical_path(base_dir, "valid.png")
    assert valid == Path(test_file).resolve()

    # Traversal attacks must raise ValueError
    attacks = [
        "../secret.txt",
        "..\\secret.txt",
        "subdir/../../etc/passwd",
        "/etc/passwd",
        "C:\\Windows\\System32\\calc.exe",
        "\\\\remote-server\\share\\evil.exe",
        "//remote-server/share/evil.exe",
        "valid.png\0.evil",
    ]
    for attack in attacks:
        with pytest.raises(ValueError):
            validate_canonical_path(base_dir, attack)


# ── 2. Localhost Authentication Enforcement ──────────────────────────────
def test_localhost_auth_enforcement():
    flask_app = flask_app_module.app
    client = flask_app.test_client()

    # 1. Public bootstrap routes (GET / and /api/health) must succeed without token
    res_health = client.get('/api/health')
    assert res_health.status_code == 200

    # 2. Protected API call without token must return 401 Unauthorized
    res_no_token = client.post('/api/license/activate', json={'license_key': 'TEST'})
    assert res_no_token.status_code == 401

    # 3. Protected API call with invalid token must return 401 Unauthorized
    res_bad_token = client.post(
        '/api/license/activate',
        json={'license_key': 'TEST'},
        headers={'X-App-Token': 'invalid_secret_token'}
    )
    assert res_bad_token.status_code == 401

    # 4. Protected API call with valid session token must be accepted
    valid_token = flask_app_module.APP_SESSION_SECRET
    res_auth = client.post(
        '/api/license/activate',
        json={'license_key': ''},
        headers={'X-App-Token': valid_token}
    )
    # Status code 400 means authentication passed and hit business logic validation
    assert res_auth.status_code == 400


# ── 3. Host Header & DNS Rebinding Protection ────────────────────────────
def test_host_header_dns_rebinding_protection():
    flask_app = flask_app_module.app
    client = flask_app.test_client()

    # External Host header must be rejected with 403 Forbidden
    res_external = client.post(
        '/api/license/activate',
        headers={'Host': 'attacker-controlled.site', 'X-App-Token': flask_app_module.APP_SESSION_SECRET}
    )
    assert res_external.status_code == 403


# ── 4. WMIC-Free HWID Generation & Privacy Log Redaction ─────────────────
def test_wmic_free_hwid_generation():
    hwid = get_hwid()
    assert isinstance(hwid, str)
    assert len(hwid) == 64  # SHA-256 hex length
    assert hwid.isalnum()


def test_license_log_redaction():
    key = "2TAMNE-PREMIUM-ABCD-1234"
    redacted = redact_license_key(key)
    assert "ABCD" not in redacted
    assert redacted == "2TAMNE-****-****-1234"

    token = "abcdef1234567890"
    red_tok = redact_token(token)
    assert red_tok == "abcd...7890"


# ── 5. Digital Signature & SHA-256 Update Integrity ──────────────────────
def test_update_integrity_verification():
    with tempfile.NamedTemporaryFile(delete=False) as tf:
        tf.write(b"SlideshowBuilder Binary Content v2.2.3.19")
        tf_path = tf.name

    try:
        real_hash = compute_sha256(tf_path)
        assert verify_sha256(tf_path, real_hash) is True
        assert verify_sha256(tf_path, "tampered_hash_value_12345") is False
        assert verify_sha256(tf_path, "", mandatory=True) is False

        # Digital signature test
        payload = b'{"version":"2.2.3.19","hash":"' + real_hash.encode() + b'"}'
        valid_sig = hmac.new(TRUSTED_UPDATE_ROOT_KEY.encode(), payload, hashlib.sha256).hexdigest()

        assert verify_package_signature(payload, valid_sig) is True
        assert verify_package_signature(payload, "forged_signature_00000") is False
        assert verify_package_signature(b'{"version":"2.2.3.19_tampered"}', valid_sig) is False
    finally:
        if os.path.isfile(tf_path):
            os.remove(tf_path)


# ── 6. Authoritative Version Consistency ─────────────────────────────────
def test_authoritative_version_consistency():
    assert APP_VERSION == "2.2.3.19"

    # Verify website/index.php references the authoritative version
    web_index = Path("/Users/2tamne/tool ffmpeg/website/index.php")
    if web_index.is_file():
        with open(web_index, "r", encoding="utf-8") as f:
            content = f.read()
        assert "v2.2.3.19" in content
        assert "v2.2.3.17" not in content


# ── 7. FFmpeg Binary Trust & Audit ───────────────────────────────────────
def test_ffmpeg_security_info():
    info = get_ffmpeg_security_info()
    assert isinstance(info, dict)
    assert "binary_path" in info
    assert "version" in info
    assert "is_trusted" in info
    assert info["is_trusted"] is True


# ── 8. Concurrency Semaphore Guardrails ──────────────────────────────────
def test_concurrency_semaphore():
    sem = flask_app_module.RENDER_SEMAPHORE
    # Acquire 2 slots
    assert sem.acquire(blocking=False) is True
    assert sem.acquire(blocking=False) is True
    # Third slot must be rejected (max 2 concurrent)
    assert sem.acquire(blocking=False) is False
    # Release slots
    sem.release()
    sem.release()
