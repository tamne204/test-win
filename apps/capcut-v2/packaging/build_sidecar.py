"""
apps/capcut-v2/packaging/build_sidecar.py
Build script to package Python Core Sidecar (autoedit-core / autoedit-core.exe)
using PyInstaller.
Standalone binary: Customers do not need Python, pip, or virtualenv installed.
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

    cmd = [
        sys.executable,
        "-m",
        "PyInstaller",
        "--noconfirm",
        "--clean",
        "--onedir",
        "--name", "autoedit-core",
        "--distpath", DIST_DIR,
        "--workpath", BUILD_DIR,
        "--specpath", SPEC_DIR,
        "--paths", V2_ROOT,
        "--hidden-import", "core",
        "--hidden-import", "core.edit_plan",
        "--hidden-import", "core.rule_engine",
        "--hidden-import", "core.preset_manager",
        "--hidden-import", "core.srt_timeline",
        "--hidden-import", "core.timeline_builder",
        "--hidden-import", "core.subtitles",
        "--hidden-import", "core.subtitles.models",
        "--hidden-import", "core.subtitles.script_normalizer",
        "--hidden-import", "core.subtitles.speech_timestamp_provider",
        "--hidden-import", "core.subtitles.script_aligner",
        "--hidden-import", "core.subtitles.subtitle_segmenter",
        "--hidden-import", "core.subtitles.srt_generator",
        "--hidden-import", "core.subtitles.pipeline",
        "--hidden-import", "adapters",
        "--hidden-import", "adapters.capcut",
        "--hidden-import", "adapters.capcut.detector",
        "--hidden-import", "adapters.capcut.adapter",
        "--hidden-import", "adapters.capcut.registry",
        "--hidden-import", "adapters.capcut.version_9_3",
        "--hidden-import", "adapters.capcut.validator",
        "--hidden-import", "adapters.capcut.project_manager",
        "--hidden-import", "adapters.capcut.launcher",
        "--hidden-import", "desktop_bridge",
        "--hidden-import", "desktop_bridge.protocol",
        "--hidden-import", "desktop_bridge.bridge",
        "--hidden-import", "desktop_bridge.sidecar_main",
        "--hidden-import", "PIL",
        "--hidden-import", "PIL.Image",
        "--hidden-import", "PIL.ImageDraw",
        "--hidden-import", "PIL.ImageFont",
        "--collect-data", "faster_whisper",
    ]

    if not sys.platform.startswith("win"):
        cmd.extend(["--hidden-import", "fcntl", "--hidden-import", "plistlib"])
    # Note on Windows: do NOT use --noconsole because it destroys sys.stdin/sys.stdout pipes
    # in PyInstaller. Electron launches autoedit-core.exe with windowsHide: true (CREATE_NO_WINDOW),
    # which ensures zero visible terminal while preserving full JSON-RPC stdin/stdout IPC.

    cmd.append(ENTRY_POINT)

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
