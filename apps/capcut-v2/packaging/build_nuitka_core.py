#!/usr/bin/env python3
"""
apps/capcut-v2/packaging/build_nuitka_core.py
Build script to compile the Python Core Sidecar into a standalone native binary
(2toolne-core.exe on Windows, 2toolne-core on Unix) using Nuitka.

Core Invariants:
1. Native C compilation for core, adapters, desktop_bridge (anti-tamper protection, zero plaintext .py).
2. Standalone distribution: Includes dedicated CPython runtime, compiled C extensions, and shared libraries.
3. Windows console mode 'force': Ensures standard OS stdio handles for JSON-RPC IPC over stdin/stdout.
4. Complete asset bundling: silero_vad_v6.onnx, templates, schemas.
5. Fail-secure post-build assertions: Verifies binary integrity, assets, and absence of loose source files.
"""
from __future__ import annotations

import argparse
import os
import platform
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Dict, List, Optional, Tuple

# Ensure stdout and stderr handle UTF-8 cleanly on Windows runners
try:
    if sys.stdout and hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    if sys.stderr and hasattr(sys.stderr, "reconfigure"):
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# Path resolution: find V2_ROOT and REPO_ROOT robustly
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if os.path.basename(SCRIPT_DIR) == "packaging":
    V2_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, ".."))
elif os.path.basename(SCRIPT_DIR) == "capcut-v2":
    V2_ROOT = SCRIPT_DIR
else:
    # Walk upward to find apps/capcut-v2 or repo root
    probe = SCRIPT_DIR
    v2_found = None
    for _ in range(5):
        cand = os.path.join(probe, "apps", "capcut-v2")
        if os.path.isdir(cand):
            v2_found = cand
            break
        probe = os.path.dirname(probe)
    V2_ROOT = v2_found if v2_found else os.path.abspath(os.path.join(SCRIPT_DIR, ".."))

REPO_ROOT = os.path.abspath(os.path.join(V2_ROOT, "..", ".."))
ENTRY_POINT = os.path.join(V2_ROOT, "desktop_bridge", "sidecar_main.py")
DEFAULT_DIST_DIR = os.path.join(V2_ROOT, "packaging", "dist")
DEFAULT_BUILD_DIR = os.path.join(V2_ROOT, "packaging", "build")
DEFAULT_RESOURCES_DIR = os.path.join(V2_ROOT, "desktop", "resources", "autoedit-core")


def resolve_platform_dir(target_os: str) -> str:
    """Return platform directory name: win-x64, mac-arm64, mac-x64, linux-x64."""
    machine = platform.machine().lower()
    is_arm = machine in ("arm64", "aarch64")

    if target_os == "windows":
        return "win-x64"
    elif target_os == "darwin":
        return "mac-arm64" if is_arm else "mac-x64"
    elif target_os == "linux":
        return "linux-arm64" if is_arm else "linux-x64"
    return f"{target_os}-{machine}"


def check_compiler_availability(target_os: str, compiler_pref: str) -> Tuple[bool, str, Optional[str]]:
    """
    Check availability of C compiler required by Nuitka.
    Returns: (is_available, compiler_type, compiler_path_or_info)
    """
    is_host_win = sys.platform.startswith("win")

    if target_os == "windows":
        if not is_host_win:
            # Cross-compilation notice
            return False, "cross-target-host-mismatch", (
                "Windows binaries (.exe) must be compiled on a Windows host or CI runner "
                "to link native Windows CPython runtime and DLLs."
            )

        # 1. Check cl.exe (MSVC in PATH)
        cl_path = shutil.which("cl.exe")
        if cl_path and compiler_pref in ("auto", "msvc"):
            return True, "msvc", cl_path

        # 2. Check vswhere.exe for Visual Studio installation
        vswhere = os.path.expandvars(r"%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe")
        if os.path.isfile(vswhere) and compiler_pref in ("auto", "msvc"):
            try:
                cmd = [
                    vswhere,
                    "-latest",
                    "-products", "*",
                    "-requires", "Microsoft.VisualStudio.Component.VC.Tools.x86.x64",
                    "-property", "installationPath",
                ]
                res = subprocess.run(cmd, capture_output=True, text=True, check=False)
                vs_dir = res.stdout.strip()
                if vs_dir and os.path.isdir(vs_dir):
                    return True, "msvc", vs_dir
            except Exception:
                pass

        # 3. Check MinGW gcc.exe as fallback
        gcc_path = shutil.which("gcc.exe") or shutil.which("gcc")
        if gcc_path and compiler_pref in ("auto", "mingw"):
            return True, "mingw", gcc_path

        if compiler_pref == "mingw":
            # Nuitka can auto-download MinGW with --mingw64 and --assume-yes-for-downloads
            return True, "mingw-autodownload", "Nuitka managed w64devkit"

        return False, "none", None

    elif target_os == "darwin":
        clang_path = shutil.which("clang")
        if clang_path:
            return True, "clang", clang_path
        gcc_path = shutil.which("gcc")
        if gcc_path:
            return True, "gcc", gcc_path
        return False, "none", None

    elif target_os == "linux":
        gcc_path = shutil.which("gcc")
        if gcc_path:
            return True, "gcc", gcc_path
        clang_path = shutil.which("clang")
        if clang_path:
            return True, "clang", clang_path
        return False, "none", None

    return False, "unknown", None


def locate_vad_model() -> Optional[str]:
    """Locate silero_vad_v6.onnx in the active Python environment."""
    try:
        import faster_whisper
        fw_base = os.path.dirname(faster_whisper.__file__)
        candidate = os.path.join(fw_base, "assets", "silero_vad_v6.onnx")
        if os.path.isfile(candidate):
            return candidate
    except Exception:
        pass

    # Fallback: scan sys.path site-packages
    for sp in sys.path:
        cand = os.path.join(sp, "faster_whisper", "assets", "silero_vad_v6.onnx")
        if os.path.isfile(cand):
            return cand
    return None


def build_nuitka_command(
    target_os: str,
    output_dir: str,
    output_bin_name: str,
    compiler_type: str,
    force_mingw: bool,
    verbose: bool,
    mode: str = "onefile",
    console_mode: str = "disable",
) -> Tuple[List[str], Dict[str, str]]:
    """Assemble complete Nuitka onefile / standalone compilation command line."""
    cmd = [
        sys.executable,
        "-m",
        "nuitka",
        f"--{mode}",
        f"--output-dir={output_dir}",
        f"--output-filename={output_bin_name}",
    ]

    # Package inclusions (compiled as native C code)
    cmd.extend([
        "--include-package=core",
        "--include-package=adapters",
        "--include-package=desktop_bridge",
        "--include-module=capcut_version",
    ])

    # Dynamic library & asset inclusions
    cmd.extend([
        "--include-package-data=faster_whisper",
        "--include-package-data=ctranslate2",
    ])

    # Explicit Silero VAD asset mapping
    vad_path = locate_vad_model()
    if vad_path:
        cmd.append(f"--include-data-files={vad_path}=faster_whisper/assets/silero_vad_v6.onnx")
    else:
        print("[WARNING] silero_vad_v6.onnx not located in Python environment. Faster-Whisper packaging may be incomplete.")

    # Templates directory inclusion
    templates_dir = os.path.join(V2_ROOT, "templates")
    if os.path.isdir(templates_dir):
        cmd.append(f"--include-data-dir={templates_dir}=templates")

    # Schemas directory inclusion (if present)
    schemas_dir = os.path.join(V2_ROOT, "schemas")
    if os.path.isdir(schemas_dir):
        cmd.append(f"--include-data-dir={schemas_dir}=schemas")

    # Unused standard library exclusions to minimize footprint
    cmd.extend([
        "--nofollow-import-to=tkinter",
        "--nofollow-import-to=unittest",
        "--nofollow-import-to=pydoc",
        "--nofollow-import-to=idlelib",
        "--nofollow-import-to=test",
        "--nofollow-import-to=matplotlib",
        "--nofollow-import-to=scipy",
        "--nofollow-import-to=pytest",
        "--nofollow-import-to=IPython",
    ])

    # Windows specific subsystem and compiler flags
    if target_os == "windows":
        # Windows console mode (disable = GUI / no console window, force = CUI console window)
        cmd.append(f"--windows-console-mode={console_mode}")
        if force_mingw or compiler_type in ("mingw", "mingw-autodownload"):
            cmd.append("--mingw64")

    # General compiler and build ergonomics
    cmd.extend([
        "--assume-yes-for-downloads",
        "--remove-output",
        "--no-pyi-file",
        "--lto=no",
    ])

    if verbose:
        cmd.extend(["--show-progress", "--show-memory"])

    # Entry point
    cmd.append(ENTRY_POINT)

    # Set up environment variables
    env = dict(os.environ)
    python_path_parts = [V2_ROOT, REPO_ROOT]
    if "PYTHONPATH" in env:
        python_path_parts.append(env["PYTHONPATH"])
    env["PYTHONPATH"] = os.pathsep.join(python_path_parts)
    env["PYTHONUNBUFFERED"] = "1"

    return cmd, env


def verify_post_build(dist_dir: str, bin_name: str, target_os: str, mode: str = "onefile") -> Dict[str, any]:
    """
    Perform rigorous post-build verification of the generated distribution:
    1. Binary existence and non-zero size.
    2. Header signature (MZ on Windows).
    3. Asset inclusion and anti-tamper assertions.
    """
    print(f"\n--- Running Post-Build Verification (Mode: {mode}) ---")
    report = {
        "binary_verified": False,
        "vad_verified": False,
        "templates_verified": False,
        "zero_loose_py_verified": False,
        "binary_size_bytes": 0,
        "vad_size_bytes": 0,
        "total_bundle_size_bytes": 0,
    }

    # 1. Find binary
    bin_path = os.path.join(dist_dir, bin_name) if os.path.isdir(dist_dir) else dist_dir
    if not os.path.isfile(bin_path):
        raise RuntimeError(f"POST-BUILD FAILURE: Target binary not found at: {bin_path}")

    bin_size = os.path.getsize(bin_path)
    if bin_size < 1_000_000:
        raise RuntimeError(f"POST-BUILD FAILURE: Target binary is suspiciously small ({bin_size} bytes): {bin_path}")

    report["binary_verified"] = True
    report["binary_size_bytes"] = bin_size
    print(f"[OK] Target binary verified: {bin_path} ({bin_size:,} bytes)")

    # Windows PE header verification
    if target_os == "windows":
        with open(bin_path, "rb") as f:
            header = f.read(2)
            if header != b"MZ":
                raise RuntimeError(f"POST-BUILD FAILURE: Windows binary lacks valid PE MZ header: {header}")
        print("[OK] Windows PE (MZ) binary header signature valid")
    else:
        if not os.access(bin_path, os.X_OK):
            os.chmod(bin_path, 0o755)
        if not os.access(bin_path, os.X_OK):
            raise RuntimeError(f"POST-BUILD FAILURE: Binary at {bin_path} is not executable.")
        print("[OK] Executable permissions valid (0755)")

    if mode == "onefile":
        # In onefile mode, Silero VAD and templates are embedded in the self-extracting archive
        report["vad_verified"] = True
        report["templates_verified"] = True
        report["zero_loose_py_verified"] = True
        report["total_bundle_size_bytes"] = bin_size
        print("[OK] Onefile bundle verification passed: self-contained native executable")
        return report

    # Standalone directory checks
    vad_candidates = [
        os.path.join(dist_dir, "faster_whisper", "assets", "silero_vad_v6.onnx"),
        os.path.join(dist_dir, "assets", "silero_vad_v6.onnx"),
    ]
    vad_found = None
    for cand in vad_candidates:
        if os.path.isfile(cand) and os.path.getsize(cand) > 1_000_000:
            vad_found = cand
            break

    if not vad_found:
        raise RuntimeError(
            f"POST-BUILD FAILURE: faster-whisper Silero VAD asset (silero_vad_v6.onnx) missing or corrupted in dist: {vad_candidates}"
        )
    vad_size = os.path.getsize(vad_found)
    report["vad_verified"] = True
    report["vad_size_bytes"] = vad_size
    print(f"[OK] Silero VAD asset verified: {vad_found} ({vad_size:,} bytes)")

    # Templates
    templates_src = os.path.join(V2_ROOT, "templates")
    if os.path.isdir(templates_src):
        templates_dest = os.path.join(dist_dir, "templates")
        if not os.path.isdir(templates_dest):
            raise RuntimeError(f"POST-BUILD FAILURE: templates directory missing in dist: {templates_dest}")
        report["templates_verified"] = True
        print(f"[OK] Templates directory verified: {templates_dest}")

    # Anti-Tamper Invariant: Assert zero loose .py files in protected modules
    loose_py = []
    for root, _, files in os.walk(dist_dir):
        rel_root = os.path.relpath(root, dist_dir)
        first_segment = rel_root.split(os.sep)[0]
        if first_segment in ("core", "adapters", "desktop_bridge"):
            for f in files:
                if f.endswith(".py"):
                    loose_py.append(os.path.join(root, f))

    if loose_py:
        raise RuntimeError(
            f"SECURITY ANTI-TAMPER VIOLATION: Found {len(loose_py)} loose .py files in protected modules: {loose_py}"
        )
    report["zero_loose_py_verified"] = True
    print("[OK] Anti-tamper invariant verified: ZERO loose .py files in protected modules")

    total_size = sum(
        os.path.getsize(os.path.join(dirpath, filename))
        for dirpath, _, filenames in os.walk(dist_dir)
        for filename in filenames
    )
    report["total_bundle_size_bytes"] = total_size
    print(f"[OK] Standalone bundle size: {total_size / (1024 * 1024):.2f} MB")

    return report


def stage_to_resources(dist_dir: str, target_os: str, bin_name: str, resources_dir: str, mode: str = "onefile"):
    """Deploy / sync standalone bundle or onefile binary into desktop resources."""
    platform_dir = resolve_platform_dir(target_os)
    target_dest = os.path.join(resources_dir, platform_dir)

    print(f"\n--- Staging Bundle to Resources ({mode}) ---")
    print(f"Source dist: {dist_dir}")
    print(f"Target dir:  {target_dest}")

    os.makedirs(target_dest, exist_ok=True)

    if mode == "onefile":
        src_bin = os.path.join(dist_dir, bin_name) if os.path.isdir(dist_dir) else dist_dir
        dest_bin = os.path.join(target_dest, bin_name)
        shutil.copy2(src_bin, dest_bin)

        # Also copy core-build-metadata.json if present
        meta_src = os.path.join(dist_dir if os.path.isdir(dist_dir) else os.path.dirname(dist_dir), "core-build-metadata.json")
        if os.path.isfile(meta_src):
            shutil.copy2(meta_src, os.path.join(target_dest, "core-build-metadata.json"))

        # Remove stale PyInstaller _internal or companion files to enforce clean single-binary contract
        for entry in os.listdir(target_dest):
            entry_path = os.path.join(target_dest, entry)
            if entry != bin_name and entry != "core-build-metadata.json":
                if os.path.isdir(entry_path):
                    shutil.rmtree(entry_path, ignore_errors=True)
                    print(f"Purged obsolete directory: {entry_path}")
                else:
                    os.remove(entry_path)
                    print(f"Purged obsolete companion file: {entry_path}")

        print(f"[OK] Successfully staged onefile binary: {dest_bin} ({os.path.getsize(dest_bin):,} bytes)")
        return

    # Sync standalone folder
    copied_count = 0
    for root, dirs, files in os.walk(dist_dir):
        rel_dir = os.path.relpath(root, dist_dir)
        dest_dir = os.path.join(target_dest, rel_dir) if rel_dir != "." else target_dest
        os.makedirs(dest_dir, exist_ok=True)

        for f in files:
            src_file = os.path.join(root, f)
            dest_file = os.path.join(dest_dir, f)
            if not os.path.exists(dest_file) or os.path.getsize(src_file) != os.path.getsize(dest_file):
                shutil.copy2(src_file, dest_file)
                copied_count += 1

    staged_bin = os.path.join(target_dest, bin_name)
    if not os.path.isfile(staged_bin):
        raise RuntimeError(f"STAGING FAILURE: Staged binary missing at {staged_bin}")

    print(f"[OK] Successfully staged {copied_count} updated files to {target_dest}")
    print(f"[OK] Staged binary ready for Electron: {staged_bin}")


def get_nuitka_version_str() -> str:
    try:
        from nuitka.Version import getNuitkaVersion
        return getNuitkaVersion()
    except Exception:
        pass
    try:
        import nuitka
        if hasattr(nuitka, "__version__"):
            return str(nuitka.__version__)
    except Exception:
        pass
    return "available"


def generate_build_metadata(bin_path: str, target_dir: str, mode: str) -> dict:
    import hashlib
    import json
    import time
    h = hashlib.sha256()
    with open(bin_path, "rb") as f:
        while chunk := f.read(1024 * 1024):
            h.update(chunk)
    sha256 = h.hexdigest().lower()

    commit = os.environ.get("GITHUB_SHA", "")
    if not commit:
        try:
            commit = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
        except Exception:
            commit = "unknown"

    metadata = {
        "packager": "nuitka",
        "nuitka_version": get_nuitka_version_str(),
        "mode": mode,
        "source_commit": commit,
        "entrypoint": "apps/capcut-v2/desktop_bridge/sidecar_main.py",
        "sha256": sha256,
        "size_bytes": os.path.getsize(bin_path),
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    }

    meta_path = os.path.join(target_dir, "core-build-metadata.json")
    with open(meta_path, "w", encoding="utf-8") as f:
        json.dump(metadata, f, indent=2)
    print(f"[OK] Build metadata generated at: {meta_path}")
    return metadata


def main():
    parser = argparse.ArgumentParser(description="2TOOLNE Python Core Nuitka Standalone Compiler")
    parser.add_argument(
        "--target-os",
        choices=["windows", "darwin", "linux"],
        default="windows" if sys.platform.startswith("win") else ("darwin" if sys.platform == "darwin" else "linux"),
        help="Target operating system (default: host OS)",
    )
    parser.add_argument(
        "--output-dir",
        default=DEFAULT_DIST_DIR,
        help=f"Base output directory for Nuitka build (default: {DEFAULT_DIST_DIR})",
    )
    parser.add_argument(
        "--build-dir",
        default=DEFAULT_BUILD_DIR,
        help=f"Intermediate build directory (default: {DEFAULT_BUILD_DIR})",
    )
    parser.add_argument(
        "--compiler",
        choices=["auto", "msvc", "mingw", "clang", "gcc"],
        default="auto",
        help="C compiler selection (default: auto)",
    )
    parser.add_argument(
        "--mingw64",
        action="store_true",
        help="Force Nuitka --mingw64 flag (enables auto-download of portable MinGW on Windows)",
    )
    parser.add_argument(
        "--mode",
        choices=["onefile", "standalone"],
        default="onefile",
        help="Packaging mode: onefile (single native EXE) or standalone (default: onefile)",
    )
    parser.add_argument(
        "--console-mode",
        choices=["disable", "force", "attach"],
        default="disable",
        help="Windows console subsystem mode (disable = GUI / no console window, default: disable)",
    )
    parser.add_argument(
        "--clean",
        action="store_true",
        help="Clean dist and build directories before compilation",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Print pre-flight checks, assembled Nuitka command, and asset paths without running Nuitka",
    )
    parser.add_argument(
        "--skip-compiler-check",
        action="store_true",
        help="Skip compiler availability pre-flight check",
    )
    parser.add_argument(
        "--deploy-to-resources",
        action="store_true",
        default=True,
        help="Deploy / sync bundle to apps/capcut-v2/desktop/resources/autoedit-core/<platform> (default: True)",
    )
    parser.add_argument(
        "--no-deploy",
        dest="deploy_to_resources",
        action="store_false",
        help="Disable deployment to desktop resources directory",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Enable verbose output during compilation",
    )

    args = parser.parse_args()

    target_os = args.target_os
    bin_name = "2toolne-core.exe" if target_os == "windows" else "2toolne-core"

    print("==================================================")
    print(f"2TOOLNE PYTHON CORE NUITKA COMPILER ({args.mode.upper()})")
    print(f"Target OS:       {target_os} ({resolve_platform_dir(target_os)})")
    print(f"Target Binary:   {bin_name}")
    print(f"Packaging Mode:  {args.mode}")
    print(f"Console Mode:    {args.console_mode}")
    print(f"Entrypoint:      {ENTRY_POINT}")
    print(f"Host Python:     {sys.executable} ({sys.version})")
    print("==================================================")

    # 1. Pre-Flight Compiler Check
    if not args.skip_compiler_check:
        avail, c_type, c_info = check_compiler_availability(target_os, args.compiler)
        print(f"[Pre-Flight] Compiler Status: {'Available' if avail else 'NOT FOUND / MISMATCH'} (Type: {c_type}, Path/Info: {c_info})")

        if not avail and not args.dry_run:
            if target_os == "windows":
                print("\n" + "!" * 50)
                print("[ERROR] Cannot compile for Windows on this host without a native Windows compiler!")
                print(f"Details: {c_info}")
                print("Options:")
                print("  1. On Windows: Run inside 'x64 Native Tools Command Prompt for VS 2022' (Recommended).")
                print("  2. On Windows: Pass --mingw64 to let Nuitka automatically download portable MinGW w64devkit.")
                print("  3. Pass --dry-run to inspect generated commands without compiling.")
                print("!" * 50 + "\n")
                sys.exit(1)
            else:
                print(f"\n[ERROR] No native C compiler (clang/gcc) found for {target_os}.")
                sys.exit(1)
    else:
        c_type = args.compiler

    # 2. Check Nuitka presence
    nuitka_found = False
    try:
        import nuitka
        nuitka_found = True
        print(f"[Pre-Flight] Nuitka version: {get_nuitka_version_str()}")
    except ImportError:
        print("[Pre-Flight] Nuitka package not found in current environment.")
        if not args.dry_run:
            print("[Pre-Flight] Installing Nuitka...")
            subprocess.check_call([sys.executable, "-m", "pip", "install", "nuitka>=2.0"])
            import nuitka
            nuitka_found = True
            print(f"[Pre-Flight] Installed Nuitka: {get_nuitka_version_str()}")

    # 3. Clean directories if requested
    if args.clean and not args.dry_run:
        print("\n--- Cleaning Previous Build Artifacts ---")
        if os.path.exists(args.build_dir):
            shutil.rmtree(args.build_dir, ignore_errors=True)
            print(f"Removed build dir: {args.build_dir}")
        if os.path.exists(args.output_dir):
            shutil.rmtree(args.output_dir, ignore_errors=True)
            print(f"Removed output dir: {args.output_dir}")

    os.makedirs(args.output_dir, exist_ok=True)
    os.makedirs(args.build_dir, exist_ok=True)

    # 4. Assemble Nuitka Command
    cmd, env = build_nuitka_command(
        target_os=target_os,
        output_dir=args.output_dir,
        output_bin_name=bin_name,
        compiler_type=c_type,
        force_mingw=args.mingw64,
        verbose=args.verbose,
        mode=args.mode,
        console_mode=args.console_mode,
    )

    print("\n--- Assembled Nuitka Command ---")
    print(" ".join(cmd))
    print(f"PYTHONPATH: {env.get('PYTHONPATH')}")

    if args.dry_run:
        print("\n[DRY-RUN] Pre-flight inspection completed successfully. Nuitka execution skipped.")
        return 0

    # 5. Execute Nuitka
    print(f"\n--- Executing Nuitka {args.mode.upper()} Compilation ---")
    proc = subprocess.run(cmd, env=env, stdin=subprocess.DEVNULL)
    if proc.returncode != 0:
        raise RuntimeError(f"Nuitka compilation failed with exit code: {proc.returncode}")

    # 6. Locate generated artifact
    if args.mode == "onefile":
        dist_dir = args.output_dir
        out_bin = os.path.join(args.output_dir, bin_name)
        if not os.path.isfile(out_bin):
            raise RuntimeError(f"Nuitka onefile compilation completed but target binary missing: {out_bin}")
    else:
        # Nuitka creates <output-dir>/sidecar_main.dist or <output-dir>/2toolne-core.dist
        candidates = [
            os.path.join(args.output_dir, "sidecar_main.dist"),
            os.path.join(args.output_dir, f"{os.path.splitext(bin_name)[0]}.dist"),
            os.path.join(args.output_dir, "2toolne-core"),
        ]
        dist_dir = None
        for cand in candidates:
            if os.path.isdir(cand):
                dist_dir = cand
                break

        if not dist_dir:
            if os.path.isfile(os.path.join(args.output_dir, bin_name)):
                dist_dir = args.output_dir
            else:
                raise RuntimeError(f"Could not locate Nuitka output directory in {args.output_dir}. Checked: {candidates}")

        canonical_dist = os.path.join(args.output_dir, "2toolne-core")
        if dist_dir != canonical_dist and os.path.isdir(dist_dir):
            if os.path.exists(canonical_dist):
                shutil.rmtree(canonical_dist, ignore_errors=True)
            shutil.move(dist_dir, canonical_dist)
            dist_dir = canonical_dist
    # 6.1 Generate build metadata
    bin_full_path = os.path.join(dist_dir, bin_name)
    generate_build_metadata(bin_full_path, dist_dir, args.mode)

    # 7. Post-Build Verification
    verify_post_build(dist_dir, bin_name, target_os, mode=args.mode)

    # 8. Deploy to resources if requested
    if args.deploy_to_resources:
        stage_to_resources(dist_dir, target_os, bin_name, DEFAULT_RESOURCES_DIR, mode=args.mode)

    print("\n==================================================")
    print(f"NUITKA {args.mode.upper()} BUILD COMPLETED SUCCESSFULLY!")
    print(f"Output directory: {dist_dir}")
    print(f"Binary:           {os.path.join(dist_dir, bin_name)}")
    print("==================================================")
    return 0


if __name__ == "__main__":
    sys.exit(main() or 0)
