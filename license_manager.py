"""
license_manager.py
Handles Hardware ID (HWID) extraction, license activation, and verification
against the license server at https://www.2tamne.site/
"""
from __future__ import annotations

import os
import sys
import json
import time
import hashlib
import platform
import threading
import subprocess
from typing import List, Tuple, Dict, Any, Optional, Union, Callable, Set
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


def get_hwid() -> str:
    """Extract a unique hardware identifier (HWID) across Windows, macOS, and Linux."""
    system = platform.system()
    raw_id = ""
    try:
        if system == "Windows":
            try:
                import winreg
                key = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography")
                guid, _ = winreg.QueryValueEx(key, "MachineGuid")
                if guid:
                    raw_id = str(guid).strip()
            except Exception:
                pass

            if not raw_id:
                try:
                    kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
                    cmd = "wmic csproduct get uuid"
                    out = subprocess.check_output(cmd, shell=True, timeout=3, **kwargs).decode(errors="ignore").splitlines()
                    if len(out) > 1:
                        raw_id = out[1].strip()
                    if not raw_id or "UUID" in raw_id:
                        cmd2 = "vol c:"
                        raw_id = subprocess.check_output(cmd2, shell=True, timeout=3, **kwargs).decode(errors="ignore").strip()
                except Exception:
                    pass
        elif system == "Darwin":  # macOS
            cmd = "ioreg -rd1 -c IOPlatformExpertDevice | grep IOPlatformUUID"
            out = subprocess.check_output(cmd, shell=True, timeout=3).decode(errors="ignore").strip()
            if '"' in out:
                raw_id = out.split('"')[-2].strip()
        else:  # Linux
            if os.path.exists("/etc/machine-id"):
                with open("/etc/machine-id", "r") as f:
                    raw_id = f.read().strip()
            elif os.path.exists("/var/lib/dbus/machine-id"):
                with open("/var/lib/dbus/machine-id", "r") as f:
                    raw_id = f.read().strip()
    except Exception:
        pass

    if not raw_id:
        raw_id = f"{platform.node()}_{platform.processor()}_{platform.machine()}"

    return hashlib.sha256(raw_id.strip().encode("utf-8")).hexdigest().upper()


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
            "message": f"Chế độ ngoại tuyến (Không kết nối được server 2tamne.site: {e})",
            "features": ["all"]
        }
        return _current_status


def get_status() -> Dict[str, Any]:
    """Return cached status with HWID."""
    status = dict(_current_status)
    status["hwid"] = get_hwid()
    status["device_name"] = get_device_name()
    status["tool_version"] = TOOL_VERSION
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