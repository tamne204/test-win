"""
apps/capcut-v2/packaging/build_sidecar.py
[DEV_ONLY / DEPRECATED] Development-only PyInstaller packaging script.
WARNING: Prohibited from production release paths!
CANONICAL PRODUCTION PACKAGER: NUITKA (apps/capcut-v2/packaging/build_nuitka_core.py)
CORE_PRODUCTION_PACKAGER=NUITKA
PYINSTALLER_PRODUCTION_USAGE=0
"""
from __future__ import annotations

import os
import sys
import shutil
import subprocess

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
V2_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
ENTRY_POINT = os.path.join(V2_ROOT, "desktop_bridge", "sidecar_main.py")
DIST_DIR = os.path.join(V2_ROOT, "packaging", "dist")
BUILD_DIR = os.path.join(V2_ROOT, "packaging", "build")
SPEC_DIR = os.path.join(V2_ROOT, "packaging")


def build():
    print("==================================================")
    print("BUILDING 2TOOLNE PYTHON CORE SIDECAR")
    print(f"Platform: {sys.platform} ({sys.version})")
    print(f"Entrypoint: {ENTRY_POINT}")
    print("==================================================")

    os.makedirs(DIST_DIR, exist_ok=True)
    os.makedirs(BUILD_DIR, exist_ok=True)

    # Check if pyinstaller is installed in environment
    try:
        import PyInstaller
    except ImportError:
        print("PyInstaller not found in current environment. Installing PyInstaller...")
        subprocess.check_call([sys.executable, "-m", "pip", "install", "pyinstaller>=6.0"])

    spec_file = os.path.join(SPEC_DIR, "autoedit-core.spec")
    cmd = [
        sys.executable,
        "-m",
        "PyInstaller",
        "--noconfirm",
        "--clean",
        "--distpath", DIST_DIR,
        "--workpath", BUILD_DIR,
        spec_file,
    ]

    print(f"Running command: {' '.join(cmd)}")
    subprocess.check_call(cmd)

    output_bin = os.path.join(DIST_DIR, "autoedit-core", "autoedit-core.exe" if sys.platform.startswith("win") else "autoedit-core")
    if not os.path.exists(output_bin):
        raise RuntimeError(f"Packaging failed: Expected binary at {output_bin} not found!")

    # Hotfix Section 5: Assert physically that silero_vad_v6.onnx exists and size > 0
    vad_asset = os.path.join(
        DIST_DIR,
        "autoedit-core",
        "_internal",
        "faster_whisper",
        "assets",
        "silero_vad_v6.onnx",
    )
    if not os.path.isfile(vad_asset):
        raise RuntimeError(
            f"HOTFIX ASSERTION FAILED: faster-whisper VAD asset missing from packaged bundle: {vad_asset}"
        )
    vad_size = os.path.getsize(vad_asset)
    if vad_size == 0:
        raise RuntimeError(
            f"HOTFIX ASSERTION FAILED: faster-whisper VAD asset is empty (0 bytes): {vad_asset}"
        )

    print("==================================================")
    print("SIDECAR BUILD SUCCESSFUL!")
    print(f"Binary generated at: {output_bin}")
    print(f"Verified bundled VAD asset: {vad_asset} ({vad_size:,} bytes)")
    print("==================================================")


if __name__ == "__main__":
    build()
