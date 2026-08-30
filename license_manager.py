"""
license_manager.py
Handles Hardware ID (HWID) extraction, license activation, and verification
against the license server at https://www.2tamne.site/
Hardened for Windows 11 24H2+ (WMIC-free), log redaction, and multi-factor hardware identity.
"""
from __future__ import annotations
from typing import List, Tuple, Dict, Any, Optional, Union, Callable, Set

import os
import sys
import json
import time
import hashlib
import platform
import threading
import subprocess
import requests
from version import __version__ as TOOL_VERSION

LICENSE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "license.json")
ACTIVATE_API = "https://www.2tamne.site/api/license/activate.php"
VERIFY_API = "https://www.2tamne.site/api/license/verify.php"

# In-memory cached status
_current_status = {
    "ok": False,
    "status": "unverified",
    "tier": "FREE",
    "days_left": 0,
    "expires_at": "",
    "message": "Chưa kích hoạt bản quyền.",
    "features": []
}


def redact_license_key(key: str) -> str:
    """Safely redact sensitive license key for logging (e.g. 2TAMNE-****-****-YYYY)."""
    if not key or len(key) < 8:
        return "****"
    parts = key.strip().split("-")
    if len(parts) >= 3:
        return f"{parts[0]}-****-****-{parts[-1]}"
    return f"{key[:4]}****{key[-4:]}"


def redact_token(token: str) -> str:
    """Safely redact session tokens for logging."""
    if not token or len(token) < 8:
        return "****"
    return f"{token[:4]}...{token[-4:]}"


def get_hwid() -> str:
    """
    Extract a stable, multi-signal hardware identifier (HWID) across Windows, macOS, and Linux.
    Completely eliminates deprecated WMIC dependencies (compatible with Windows 11 24H2+).
    """
    system = platform.system()
    raw_signals: List[str] = []

    try:
        if system == "Windows":
            # 1. Primary Signal: Windows Registry MachineGuid (Universal, Stable)
            try:
                import winreg
                key = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography")
                guid, _ = winreg.QueryValueEx(key, "MachineGuid")
                if guid:
                    raw_signals.append(f"guid:{str(guid).strip()}")
            except Exception:
                pass

            # 2. Secondary Signal: PowerShell Get-CimInstance (Modern Windows 11 / 10 API, no WMIC)
            if not raw_signals:
                try:
                    kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
                    ps_cmd = ["powershell", "-NoProfile", "-NonInteractive", "-Command",
                              "(Get-CimInstance Win32_ComputerSystemProduct).UUID"]
                    res = subprocess.run(ps_cmd, capture_output=True, text=True, timeout=3, **kwargs)
                    if res.returncode == 0 and res.stdout.strip():
                        raw_signals.append(f"cim:{res.stdout.strip()}")
                except Exception:
                    pass

            # 3. Tertiary Signal: Volume Serial Number of C:
            try:
                kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
                vol_cmd = ["cmd", "/c", "vol", "c:"]
                res = subprocess.run(vol_cmd, capture_output=True, text=True, timeout=3, **kwargs)
                if res.returncode == 0 and res.stdout.strip():
                    raw_signals.append(f"vol:{res.stdout.strip()}")
            except Exception:
                pass

        elif system == "Darwin":  # macOS
            try:
                cmd = ["ioreg", "-rd1", "-c", "IOPlatformExpertDevice"]
                res = subprocess.run(cmd, capture_output=True, text=True, timeout=3)
                if res.returncode == 0 and "IOPlatformUUID" in res.stdout:
                    for line in res.stdout.splitlines():
                        if "IOPlatformUUID" in line and '"' in line:
                            raw_signals.append(f"mac_uuid:{line.split('\"')[-2].strip()}")
                            break
            except Exception:
                pass

        else:  # Linux
            for path in ("/etc/machine-id", "/var/lib/dbus/machine-id"):
                if os.path.exists(path):
                    try:
                        with open(path, "r") as f:
                            raw_signals.append(f"linux_id:{f.read().strip()}")
                        break
                    except Exception:
                        pass

    except Exception:
        pass

    # Universal Fallback Signals (Node hostname, CPU Processor, Machine Architecture)
    try:
        import uuid
        mac_addr = hex(uuid.getnode())
        raw_signals.append(f"mac:{mac_addr}")
    except Exception:
        pass

    raw_signals.append(f"node:{platform.node()}")
    raw_signals.append(f"proc:{platform.processor()}")
    raw_signals.append(f"arch:{platform.machine()}")

    combined = "|".join(raw_signals)
    return hashlib.sha256(combined.encode("utf-8")).hexdigest().upper()


def get_device_name() -> str:
    """Return friendly computer name."""
    try:
        return platform.node() or "MY-DESKTOP"
    except Exception:
        return "DESKTOP-CLIENT"


def get_saved_license() -> Optional[Dict[str, Any]]:
    """Read saved license data from local file."""
    if not os.path.isfile(LICENSE_FILE):
        return None
    try:
        with open(LICENSE_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return None


def save_license(data: Dict[str, Any]) -> None:
    """Save license data to local file."""
    try:
        with open(LICENSE_FILE, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
    except Exception as e:
        print(f"[License] Error saving license file: {e}")


def clear_license() -> None:
    """Remove local license file."""
    try:
        if os.path.isfile(LICENSE_FILE):
            os.remove(LICENSE_FILE)
    except Exception:
        pass


def activate_license(license_key: str) -> Dict[str, Any]:
    """
    Call activate.php to bind the key with this device's HWID.
    """
    global _current_status
    hwid = get_hwid()
    device_name = get_device_name()

    payload = {
        "license_key": license_key.strip(),
        "hwid": hwid,
        "device_name": device_name,
        "tool_version": TOOL_VERSION
    }

    try:
        res = requests.post(ACTIVATE_API, json=payload, timeout=12, headers={"User-Agent": "TamneSlideshow/2.0"})
        data = res.json()

        if res.status_code == 200 and data.get("ok"):
            lic_info = data.get("license", {})
            save_license({
                "license_key": license_key.strip(),
                "tier": lic_info.get("tier", "VIP"),
                "expires_at": lic_info.get("expires_at", ""),
                "session_token": data.get("session_token", "")
            })
            _current_status = {
                "ok": True,
                "status": "active",
                "tier": lic_info.get("tier", "VIP"),
                "days_left": lic_info.get("days_left", 30),
                "expires_at": lic_info.get("expires_at", ""),
                "message": data.get("message", "Kích hoạt bản quyền thành công!"),
                "features": lic_info.get("features", ["all"])
            }
            return _current_status
        else:
            return {
                "ok": False,
                "status": data.get("error_code", "ACTIVATION_FAILED"),
                "message": data.get("message", "Mã bản quyền không hợp lệ hoặc đã hết lượt kích hoạt.")
            }
    except requests.exceptions.RequestException as e:
        return {
            "ok": False,
            "status": "NETWORK_ERROR",
            "message": f"Không thể kết nối đến máy chủ xác thực 2tamne.site: {e}"
        }


def verify_license() -> Dict[str, Any]:
    """
    Verify current license with verify.php.
    """
    global _current_status
    saved = get_saved_license()
    if not saved or not saved.get("license_key"):
        _current_status = {
            "ok": False,
            "status": "unactivated",
            "tier": "NONE",
            "days_left": 0,
            "expires_at": "",
            "message": "Chưa nhập mã bản quyền.",
            "features": []
        }
        return _current_status

    license_key = saved["license_key"]
    hwid = get_hwid()

    payload = {
        "license_key": license_key.strip(),
        "hwid": hwid,
        "timestamp": int(time.time())
    }

    try:
        res = requests.post(VERIFY_API, json=payload, timeout=10, headers={"User-Agent": "TamneSlideshow/2.0"})
        data = res.json()

        if res.status_code == 200 and data.get("ok"):
            _current_status = {
                "ok": True,
                "status": "valid",
                "tier": saved.get("tier", "VIP"),
                "days_left": data.get("days_left", 30),
                "expires_at": data.get("expires_at", ""),
                "message": "Bản quyền hợp lệ",
                "features": data.get("features", ["all"])
            }
            return _current_status
        else:
            _current_status = {
                "ok": False,
                "status": data.get("error_code", "INVALID"),
                "tier": "NONE",
                "days_left": 0,
                "expires_at": "",
                "message": data.get("message", "Key bản quyền đã hết hạn hoặc không khớp thiết bị."),
                "features": []
            }
            return _current_status
    except requests.exceptions.RequestException as e:
        _current_status = {
            "ok": True if saved.get("license_key") else False,
            "status": "offline_cache",
            "tier": saved.get("tier", "VIP"),
            "days_left": 30,
            "expires_at": saved.get("expires_at", ""),
            "message": f"Chế độ ngoại tuyến (Không kết nối được server 2tamne.site)",
            "features": ["all"]
        }
        return _current_status


def get_status() -> Dict[str, Any]:
    """Return cached status with HWID and redacted license info."""
    status = dict(_current_status)
    status["hwid"] = get_hwid()
    status["device_name"] = get_device_name()
    status["tool_version"] = TOOL_VERSION
    saved = get_saved_license()
    if saved and saved.get("license_key"):
        status["redacted_key"] = redact_license_key(saved["license_key"])
    return status


def is_licensed() -> bool:
    """Return boolean whether tool is currently licensed."""
    return _current_status.get("ok", False)


def _heartbeat_worker(interval_seconds: int = 1800):
    """Background worker to check license periodically."""
    while True:
        time.sleep(interval_seconds)
        try:
            verify_license()
        except Exception:
            pass


def start_heartbeat(interval_seconds: int = 1800):
    """Launch background heartbeat thread."""
    t = threading.Thread(target=_heartbeat_worker, args=(interval_seconds,), daemon=True)
    t.start()
