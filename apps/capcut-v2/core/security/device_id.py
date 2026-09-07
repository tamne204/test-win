"""
device_id.py - Privacy-Conscious Stable Device Identifier for 2TOOLNE AutoEdit.
Generates an irreversible, stable cryptographic hash (SHA-256) of normalized local signals.
Raw hardware details are never transmitted or persisted.
"""

import hashlib
import os
import platform
import subprocess
import sys
from typing import Optional


def _get_raw_os_device_uuid() -> Optional[str]:
    """Extract primary OS-level installation UUID."""
    system = platform.system()
    
    if system == "Darwin":
        # macOS: IOPlatformUUID via ioreg
        try:
            cmd = ["ioreg", "-rd1", "-c", "IOPlatformExpertDevice"]
            res = subprocess.run(cmd, capture_output=True, text=True, timeout=2)
            if res.returncode == 0:
                for line in res.stdout.splitlines():
                    if "IOPlatformUUID" in line and '"' in line:
                        return line.split('"')[-2].strip().lower()
        except Exception:
            pass

    elif system == "Windows":
        # Windows 10/11: MachineGuid from Cryptography Registry (WMIC-free)
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography")
            guid, _ = winreg.QueryValueEx(key, "MachineGuid")
            if guid:
                return str(guid).strip().lower()
        except Exception:
            pass

        # Secondary: PowerShell Get-CimInstance
        try:
            kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
            ps_cmd = ["powershell", "-NoProfile", "-NonInteractive", "-Command",
                      "(Get-CimInstance Win32_ComputerSystemProduct).UUID"]
            res = subprocess.run(ps_cmd, capture_output=True, text=True, timeout=2, **kwargs)
            if res.returncode == 0 and res.stdout.strip():
                return res.stdout.strip().lower()
        except Exception:
            pass

    elif system == "Linux":
        # Linux: machine-id
        for path in ("/etc/machine-id", "/var/lib/dbus/machine-id"):
            if os.path.exists(path):
                try:
                    with open(path, "r", encoding="utf-8") as f:
                        content = f.read().strip()
                        if content:
                            return content.lower()
                except Exception:
                    pass

    return None


def get_privacy_device_id() -> str:
    """
    Generate a privacy-conscious stable device identifier.
    Canonicalization: v1:{system}:{normalized_uuid_or_fallback}
    Output: 'dev_' + SHA256 (64 hex characters)
    """
    system = platform.system().lower()
    raw_uuid = _get_raw_os_device_uuid()
    
    if not raw_uuid:
        # Stable fallback based on node hostname and architecture
        import uuid
        mac_addr = hex(uuid.getnode())
        raw_uuid = f"{platform.node().lower()}:{mac_addr}:{platform.machine()}"

    canonical_input = f"2toolne_v2:{system}:{raw_uuid.strip()}"
    digest = hashlib.sha256(canonical_input.encode("utf-8")).hexdigest()
    return f"dev_{digest}"
