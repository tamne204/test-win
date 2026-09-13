"""
redactor.py - Sensitive Data Masking & Redaction for 2TOOLNE AutoEdit for CapCut.
Ensures license keys, device tokens, and credentials are never logged in plaintext.
"""

import re
from typing import Any, Dict, List, Union


def redact_license_key(key: str) -> str:
    """
    Redacts a license key for safe display/logging.
    Example: '2TL-CAP-A1B2-C3D4-E5F6-7890' -> '2TL-CAP-****-****-7890'
    Example: '2TAMNE-VIP-ABCD-1234' -> '2TAMNE-****-****-1234'
    """
    if not key or not isinstance(key, str):
        return "****"
    
    clean = key.strip()
    parts = clean.split("-")
    
    if len(parts) >= 4:
        # e.g., 2TL-CAP-XXXX-XXXX-XXXX-XXXX
        prefix = "-".join(parts[:2])
        suffix = parts[-1]
        return f"{prefix}-****-****-{suffix}"
    elif len(parts) == 3:
        return f"{parts[0]}-****-{parts[-1]}"
    elif len(clean) > 8:
        return f"{clean[:4]}****{clean[-4:]}"
    else:
        return "****"


def redact_token(token: str) -> str:
    """
    Redacts session or entitlement tokens.
    """
    if not token or not isinstance(token, str):
        return "****"
    clean = token.strip()
    if len(clean) > 12:
        return f"{clean[:4]}****{clean[-4:]}"
    return "****"


def sanitize_diagnostics(data: Union[Dict[str, Any], List[Any], str]) -> Any:
    """
    Recursively sanitize dictionaries/lists for logging or diagnostic export.
    """
    if isinstance(data, dict):
        sanitized = {}
        for k, v in data.items():
            k_lower = k.lower()
            if any(secret in k_lower for secret in ("key", "secret", "token", "password", "signature", "credential")):
                if isinstance(v, str):
                    if "key" in k_lower and ("2tl" in v.lower() or "2tamne" in v.lower()):
                        sanitized[k] = redact_license_key(v)
                    else:
                        sanitized[k] = redact_token(v)
                else:
                    sanitized[k] = "[REDACTED]"
            else:
                sanitized[k] = sanitize_diagnostics(v)
        return sanitized
    elif isinstance(data, list):
        return [sanitize_diagnostics(item) for item in data]
    elif isinstance(data, str):
        # Scan for license key patterns e.g. 2TL-CAP-...
        return re.sub(r"2TL-[A-Z0-9]+-[A-Z0-9-]+", lambda m: redact_license_key(m.group(0)), data)
    return data
