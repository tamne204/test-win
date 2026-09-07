"""
license_guard.py - Central Authorization Guard & License Enforcement for 2TOOLNE AutoEdit.
Enforces dual-layer commercial access control in the Python sidecar / core engine.
Implements clock rollback defense, offline grace evaluation, and deterministic state transitions.
"""

import json
import os
import platform
import time
from typing import Any, Dict, Optional, Tuple

from .device_id import get_privacy_device_id
from .ed25519_verifier import verify_signed_entitlement
from .redactor import redact_license_key

# Normalized License States (Section 18)
STATE_NOT_ACTIVATED = "LICENSE_NOT_ACTIVATED"
STATE_ACTIVE = "LICENSE_ACTIVE"
STATE_EXPIRED = "LICENSE_EXPIRED"
STATE_REVOKED = "LICENSE_REVOKED"
STATE_DEVICE_LIMIT = "LICENSE_DEVICE_LIMIT"
STATE_WRONG_PRODUCT = "LICENSE_WRONG_PRODUCT"
STATE_OFFLINE_GRACE = "LICENSE_OFFLINE_GRACE"
STATE_ONLINE_CHECK_REQUIRED = "LICENSE_ONLINE_CHECK_REQUIRED"
STATE_SERVER_UNAVAILABLE = "LICENSE_SERVER_UNAVAILABLE"
STATE_TOKEN_INVALID = "LICENSE_TOKEN_INVALID"
STATE_DEVICE_MISMATCH = "LICENSE_DEVICE_MISMATCH"

# Maximum backward system clock drift allowed (12 hours = 43,200 seconds)
MAX_CLOCK_DRIFT_TOLERANCE_SECONDS = 43200


class LicenseEntitlementError(Exception):
    """Exception raised when a commercial command is denied by the License Guard."""
    def __init__(self, state: str, message: str, details: Optional[Dict[str, Any]] = None):
        super().__init__(message)
        self.state = state
        self.message = message
        self.details = details or {}


class LicenseGuard:
    """
    Central Authorizer for Python Core.
    Validates cryptographic offline entitlement tokens, device binding, and time integrity.
    """
    def __init__(self, app_data_dir: Optional[str] = None):
        self._app_data_dir = app_data_dir or self._resolve_default_app_data_dir()
        os.makedirs(self._app_data_dir, exist_ok=True)
        self._entitlement_path = os.path.join(self._app_data_dir, "entitlement.json")
        self._clock_guard_path = os.path.join(self._app_data_dir, "clock_guard.json")
        self._cached_status: Optional[Dict[str, Any]] = None

        # In-memory entitlement state (process lifetime only - Section 6)
        self._in_memory_envelope: Optional[Dict[str, Any]] = None
        self._in_memory_masked_key: Optional[str] = None
        self._in_memory_last4: Optional[str] = None

        # Ensure no legacy plaintext entitlement file remains on disk
        if os.path.isfile(self._entitlement_path):
            try:
                os.remove(self._entitlement_path)
            except Exception:
                pass

    @staticmethod
    def _resolve_default_app_data_dir() -> str:
        """Resolve OS-standard application support directory for 2toolne AutoEdit."""
        sys_name = platform.system()
        if sys_name == "Darwin":
            base = os.path.expanduser("~/Library/Application Support")
        elif sys_name == "Windows":
            base = os.environ.get("APPDATA", os.path.expanduser("~\\AppData\\Roaming"))
        else:
            base = os.environ.get("XDG_CONFIG_HOME", os.path.expanduser("~/.config"))
        return os.path.join(base, "2toolne AutoEdit")

    @property
    def entitlement_path(self) -> str:
        return self._entitlement_path

    def load_envelope(self) -> Optional[Dict[str, Any]]:
        """
        Return active signed entitlement envelope from volatile in-memory session.
        Plaintext entitlement is never read from disk.
        """
        return self._in_memory_envelope

    def set_envelope(
        self,
        envelope: Dict[str, Any],
        trusted_server_time: Optional[int] = None,
        masked_key: Optional[str] = None,
        license_key_last4: Optional[str] = None
    ) -> None:
        """
        Secure handoff from Electron Main (Section 6).
        Stores signed entitlement in memory for process lifetime only.
        Never writes plaintext entitlement to disk.
        """
        self._in_memory_envelope = envelope
        if masked_key:
            self._in_memory_masked_key = masked_key
        if license_key_last4:
            self._in_memory_last4 = license_key_last4

        # Update clock guard (non-sensitive monotonic defense)
        now = int(time.time())
        trusted_time = trusted_server_time or envelope.get("payload", {}).get("issued_at", now)
        self._update_clock_guard(trusted_time, now)
        self._cached_status = None

    def save_envelope(
        self,
        envelope: Dict[str, Any],
        trusted_server_time: Optional[int] = None,
        masked_key: Optional[str] = None,
        license_key_last4: Optional[str] = None
    ) -> None:
        """Compatibility alias for set_envelope. Never writes plaintext to disk."""
        self.set_envelope(
            envelope=envelope,
            trusted_server_time=trusted_server_time,
            masked_key=masked_key,
            license_key_last4=license_key_last4
        )

    def clear_entitlement(self) -> None:
        """Purge in-memory entitlement session upon deactivation or lock."""
        self._in_memory_envelope = None
        self._in_memory_masked_key = None
        self._in_memory_last4 = None
        self._cached_status = None
        if os.path.isfile(self._entitlement_path):
            try:
                os.remove(self._entitlement_path)
            except Exception:
                pass

    def _update_clock_guard(self, trusted_server_time: int, local_recorded_time: int) -> None:
        data = {
            "last_trusted_server_time": max(trusted_server_time, self._get_last_trusted_time()),
            "last_local_check_time": local_recorded_time
        }
        try:
            with open(self._clock_guard_path, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2)
        except Exception:
            pass

    def _get_last_trusted_time(self) -> int:
        if not os.path.isfile(self._clock_guard_path):
            return 0
        try:
            with open(self._clock_guard_path, "r", encoding="utf-8") as f:
                data = json.load(f)
                return int(data.get("last_trusted_server_time", 0))
        except Exception:
            return 0

    def evaluate_entitlement(
        self,
        required_product: str = "2toolne.capcut.v2",
        required_feature: str = "capcut_autoedit"
    ) -> Tuple[bool, str, str, Optional[Dict[str, Any]]]:
        """
        Evaluates the local entitlement status.
        
        Returns:
            (is_authorized, state_code, human_message, payload_dict_or_none)
        """
        envelope = self.load_envelope()
        if not envelope:
            return False, STATE_NOT_ACTIVATED, "Chưa kích hoạt mã bản quyền trên thiết bị này.", None

        # 1. Cryptographic Signature Verification
        is_sig_valid, sig_err, payload = verify_signed_entitlement(envelope)
        if not is_sig_valid or not payload:
            return False, STATE_TOKEN_INVALID, "Chữ ký số bản quyền không hợp lệ hoặc đã bị can thiệp.", None

        # 2. Product Entitlement Check (Section 3)
        token_product = payload.get("product_id")
        if token_product != required_product:
            return False, STATE_WRONG_PRODUCT, (
                f"Mã bản quyền này thuộc về sản phẩm '{token_product}', "
                f"không dùng được cho '{required_product}'."
            ), payload

        # 3. Device ID Binding Check (Section 7, 31)
        token_device = payload.get("device_id")
        local_device = get_privacy_device_id()
        if token_device != local_device:
            return False, STATE_DEVICE_MISMATCH, (
                "Bản quyền này được cấp cho một thiết bị khác. "
                "Vui lòng kích hoạt mã bản quyền trên máy tính này."
            ), payload

        now = int(time.time())

        # 4. Clock Rollback Defense (Section 17)
        last_trusted = self._get_last_trusted_time()
        if last_trusted > 0 and (now < (last_trusted - MAX_CLOCK_DRIFT_TOLERANCE_SECONDS)):
            return False, STATE_ONLINE_CHECK_REQUIRED, (
                "Phát hiện đồng hồ hệ thống bị lùi thời gian bất thường. "
                "Cần kết nối mạng để xác thực lại bản quyền."
            ), payload

        # 5. Revocation Status Check (if explicitly marked in local payload cache)
        if payload.get("status") == "REVOKED":
            return False, STATE_REVOKED, "Bản quyền này đã bị thu hồi trên máy chủ.", payload

        # 6. Expiration Check (Section 33)
        expires_at = int(payload.get("expires_at", 0))
        if expires_at > 0 and now > expires_at:
            return False, STATE_EXPIRED, "Bản quyền của bạn đã hết hạn sử dụng.", payload

        # 7. Offline Grace Window Check (Section 16)
        offline_until = int(payload.get("offline_until", expires_at))
        if offline_until > 0 and now > offline_until:
            return False, STATE_ONLINE_CHECK_REQUIRED, (
                "Đã hết thời hạn ngoại tuyến cho phép (72h). "
                "Vui lòng kết nối Internet để làm mới bản quyền."
            ), payload

        # 8. Feature Entitlement Check
        features = payload.get("features", [])
        if required_feature and (required_feature not in features and "all" not in features):
            return False, STATE_WRONG_PRODUCT, (
                f"Bản quyền hiện tại không bao gồm tính năng '{required_feature}'."
            ), payload

        # 9. Active or Offline Grace
        # Update clock guard with legitimate time
        self._update_clock_guard(last_trusted, now)

        issued_at = int(payload.get("issued_at", now))
        # If running more than 24 hours since issuance without refresh, flag as OFFLINE_GRACE
        if now - issued_at > 86400:
            state = STATE_OFFLINE_GRACE
            msg = "Bản quyền đang trong chế độ ngoại tuyến hợp lệ."
        else:
            state = STATE_ACTIVE
            msg = "Bản quyền đang hoạt động bình thường."

        return True, state, msg, payload

    def require_entitlement(
        self,
        product: str = "2toolne.capcut.v2",
        feature: str = "capcut_autoedit"
    ) -> Dict[str, Any]:
        """
        Reusable Central Authorization Guard (Section 15).
        Raises LicenseEntitlementError if unauthorized.
        """
        is_auth, state, msg, payload = self.evaluate_entitlement(
            required_product=product,
            required_feature=feature
        )
        if not is_auth:
            raise LicenseEntitlementError(state=state, message=msg, details={"product": product, "feature": feature})
        return payload or {}

    def get_public_status(self) -> Dict[str, Any]:
        """
        Returns redacted, safe status dictionary for Electron UI consumption.
        """
        is_auth, state, msg, payload = self.evaluate_entitlement()
        device_id = get_privacy_device_id()
        
        status: Dict[str, Any] = {
            "authorized": is_auth,
            "state": state,
            "message": msg,
            "device_id": device_id,
            "product_id": "2toolne.capcut.v2"
        }

        if payload:
            masked = self._in_memory_masked_key
            if not masked:
                last4 = self._in_memory_last4 or payload.get("license_key_last4")
                if last4:
                    masked = f"2TL-CAP-****-****-{last4}"
                elif payload.get("license_key"):
                    masked = redact_license_key(payload.get("license_key"))
                else:
                    masked = "2TL-CAP-****-****-****"

            last4_hint = self._in_memory_last4 or payload.get("license_key_last4") or (masked[-4:] if masked and len(masked) >= 4 else "")

            status.update({
                "license_id": payload.get("license_id"),
                "plan": payload.get("plan", "PRO"),
                "expires_at": payload.get("expires_at"),
                "offline_until": payload.get("offline_until"),
                "masked_key": masked,
                "license_key_last4": last4_hint,
                "features": payload.get("features", [])
            })
        return status


# Global Guard Instance
_global_guard = LicenseGuard()

def get_license_guard() -> LicenseGuard:
    return _global_guard

def require_entitlement(product: str = "2toolne.capcut.v2", feature: str = "capcut_autoedit") -> Dict[str, Any]:
    return _global_guard.require_entitlement(product=product, feature=feature)
