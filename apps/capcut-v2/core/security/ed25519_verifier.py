"""
ed25519_verifier.py - Asymmetric Digital Signature Verification for 2TOOLNE AutoEdit.
Verifies signed offline entitlement tokens using embedded Public Verification Keys only.
Private signing keys NEVER exist in the desktop application client.
"""

import base64
import json
from typing import Any, Dict, Optional, Tuple

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric import ed25519

# Trusted Public Keys for 2TOOLNE Products (Key Rotation supported via 'kid')
TRUSTED_PUBLIC_KEYS: Dict[str, str] = {
    "kid_2026_01": "+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=",
}


def canonicalize_payload(payload: Dict[str, Any]) -> bytes:
    """
    Deterministically serialize payload dictionary to UTF-8 bytes for signature verification.
    Uses sorted keys and compact separators without whitespace.
    """
    return json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def verify_signed_entitlement(
    token_envelope: Dict[str, Any],
    custom_trusted_keys: Optional[Dict[str, str]] = None
) -> Tuple[bool, Optional[str], Optional[Dict[str, Any]]]:
    """
    Verifies an asymmetric Ed25519 signed entitlement envelope.
    
    Expected envelope schema:
    {
        "token_version": 1,
        "kid": "kid_2026_01",
        "payload": {
            "license_id": "lic_...",
            "user_id": "usr_...",
            "product_id": "2toolne.capcut.v2",
            "device_id": "dev_...",
            "plan": "PRO",
            "issued_at": 1772812800,
            "expires_at": 1804348800,
            "offline_until": 1773072000,
            "features": ["capcut_autoedit", "unlimited_export"]
        },
        "signature": "<base64-encoded 64-byte Ed25519 signature>"
    }
    
    Returns:
        (True, None, payload) if signature is valid.
        (False, error_code, None) if verification fails.
    """
    if not isinstance(token_envelope, dict):
        return False, "TOKEN_MALFORMED", None

    kid = token_envelope.get("kid")
    signature_b64 = token_envelope.get("signature")
    payload = token_envelope.get("payload")

    if not kid or not signature_b64 or not isinstance(payload, dict):
        return False, "TOKEN_FIELDS_MISSING", None

    keys_store = custom_trusted_keys or TRUSTED_PUBLIC_KEYS
    pub_key_b64 = keys_store.get(kid)
    if not pub_key_b64:
        return False, "UNKNOWN_KEY_ID", None

    try:
        pub_key_bytes = base64.b64decode(pub_key_b64)
        signature_bytes = base64.b64decode(signature_b64)
        
        public_key = ed25519.Ed25519PublicKey.from_public_bytes(pub_key_bytes)
        canonical_bytes = canonicalize_payload(payload)
        
        # Verify asymmetric digital signature
        public_key.verify(signature_bytes, canonical_bytes)
        return True, None, payload

    except InvalidSignature:
        return False, "SIGNATURE_VERIFICATION_FAILED", None
    except Exception as e:
        return False, f"VERIFICATION_ERROR_{type(e).__name__}", None
