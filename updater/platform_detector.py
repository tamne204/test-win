"""
platform_detector.py
Identifies operating system and CPU architecture for downloading correct release binaries.
"""

import sys
import platform


def detect_os() -> str:
    """
    Return normalized OS identifier:
    - 'windows'
    - 'darwin' (macOS)
    - 'linux'
    """
    p = sys.platform.lower()
    if p.startswith("win"):
        return "windows"
    elif p.startswith("darwin"):
        return "darwin"
    elif p.startswith("linux"):
        return "linux"
    return p


def detect_arch() -> str:
    """
    Return normalized CPU architecture:
    - 'arm64' (Apple Silicon / ARM64)
    - 'x64' (Intel / AMD 64-bit)
    """
    machine = platform.machine().lower()
    if machine in ("arm64", "aarch64"):
        return "arm64"
    elif machine in ("x86_64", "amd64", "x64"):
        return "x64"
    return machine


def get_platform_tag() -> str:
    """
    Return standard target tag for GitHub release assets:
    - 'win-x64'
    - 'mac-arm64'
    - 'mac-x64'
    - 'linux-x64'
    """
    os_name = detect_os()
    arch = detect_arch()

    if os_name == "windows":
        return f"win-{arch}"
    elif os_name == "darwin":
        return f"mac-{arch}"
    elif os_name == "linux":
        return f"linux-{arch}"
    return f"{os_name}-{arch}"
