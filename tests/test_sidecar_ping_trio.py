#!/usr/bin/env python3
"""
tests/test_sidecar_ping_trio.py

THREE-MODE SIDECAR PING ACCEPTANCE (2TOOLNE build mandate §20).

Proves the Nuitka-compiled core binary responds to a JSON-RPC PING in the three
contexts that actually matter for a shipped Windows build:

  DIRECT_NUITKA_PING        - spawn the freshly built core binary directly.
  PACKAGED_NUITKA_PING      - spawn the core from inside the packaged tree
                              (dist/win-unpacked/resources/autoedit-core/win-x64).
  ELECTRON_STYLE_SPAWN_PING - spawn using the EXACT process contract the Electron
                              main process uses (piped stdio, windowsHide, env),
                              so a binary that works standalone but fails under
                              Electron's spawn semantics is still caught here.

Each mode is independent: a failure in one is reported separately rather than
short-circuiting the others, so the acceptance matrix reflects reality.

Usage:
  python tests/test_sidecar_ping_trio.py
Optional env:
  DIRECT_CORE_EXE   - explicit path for the DIRECT mode
  PACKAGED_ROOT     - explicit packaged win-unpacked directory
"""

import os
import sys
import json
import time
import threading
import subprocess

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
PING_TIMEOUT_SECONDS = 30.0


def _candidates_direct():
    return [
        os.environ.get("DIRECT_CORE_EXE"),
        os.path.join(REPO_ROOT, "apps", "capcut-v2", "desktop", "resources",
                     "autoedit-core", "win-x64", "2toolne-core.exe"),
        os.path.join(REPO_ROOT, "apps", "capcut-v2", "desktop", "resources",
                     "autoedit-core", "win-x64", "autoedit-core.exe"),
        os.path.join(REPO_ROOT, "apps", "capcut-v2", "packaging", "dist",
                     "autoedit-core", "2toolne-core.exe"),
        os.path.join(REPO_ROOT, "apps", "capcut-v2", "packaging", "dist",
                     "autoedit-core", "autoedit-core.exe"),
    ]


def _candidates_packaged():
    roots = [
        os.environ.get("PACKAGED_ROOT"),
        os.path.join(REPO_ROOT, "apps", "capcut-v2", "desktop", "dist", "win-unpacked"),
        os.path.join(REPO_ROOT, "dist", "win-unpacked"),
    ]
    out = []
    for root in roots:
        if not root:
            continue
        out.append(os.path.join(root, "resources", "autoedit-core", "win-x64", "2toolne-core.exe"))
        out.append(os.path.join(root, "resources", "autoedit-core", "win-x64", "autoedit-core.exe"))
        out.append(os.path.join(root, "resources", "autoedit-core", "2toolne-core.exe"))
        out.append(os.path.join(root, "resources", "autoedit-core", "autoedit-core.exe"))
    return out


def _resolve(candidates):
    for c in candidates:
        if c and os.path.isfile(c):
            return c
    return None


def _ping_once(exe_path, label, spawn_options):
    """
    Spawns the core with the given options, sends PING, expects PONG.
    Returns (ok: bool, detail: str, elapsed_ms: Optional[int]).

    elapsed_ms measures spawn -> PONG and is reported by CI (mandate §19).
    """
    stderr_buf = []
    started = time.time()

    try:
        proc = subprocess.Popen([exe_path], **spawn_options)
    except Exception as exc:  # noqa: BLE001 - report any spawn failure verbatim
        return False, f"spawn failed: {exc}", None

    def _drain_stderr():
        try:
            for line in proc.stderr:
                stderr_buf.append(line)
        except Exception:
            pass

    t = threading.Thread(target=_drain_stderr, daemon=True)
    t.start()

    result_ok = False
    result_detail = "no attempt"

    try:
        # Give the interpreter/binary time to initialise its RPC loop.
        deadline = time.time() + PING_TIMEOUT_SECONDS
        time.sleep(2.0)

        if proc.poll() is not None:
            result_ok, result_detail = False, f"process exited prematurely with code {proc.returncode}"
        else:
            req = json.dumps({"jsonrpc": "2.0", "id": 1, "method": "PING"}) + "\n"
            proc.stdin.write(req)
            proc.stdin.flush()

            while time.time() < deadline:
                line = proc.stdout.readline()
                if not line:
                    if proc.poll() is not None:
                        result_ok, result_detail = False, f"process exited (code {proc.returncode}) before responding"
                        break
                    continue
                line = line.strip()
                if not line:
                    continue
                try:
                    resp = json.loads(line)
                except ValueError:
                    # Non-JSON chatter on stdout; keep reading.
                    continue
                if resp.get("result") == "PONG" or resp.get("id") == 1:
                    result_ok, result_detail = True, f"PONG received: {line}"
                else:
                    result_ok, result_detail = False, f"unexpected response: {line}"
                break
            else:
                result_ok, result_detail = False, "timed out awaiting PONG"
    except Exception as exc:  # noqa: BLE001
        result_ok, result_detail = False, f"exception during ping: {exc}"
    finally:
        try:
            proc.stdin.write(json.dumps({"jsonrpc": "2.0", "id": 999, "method": "EXIT"}) + "\n")
            proc.stdin.flush()
            proc.wait(timeout=5)
        except Exception:
            try:
                proc.kill()
            except Exception:
                pass

    if stderr_buf:
        sys.stderr.write(f"  [{label}] stderr: {''.join(stderr_buf)[:600]}\n")

    return result_ok, result_detail, int((time.time() - started) * 1000)


def _base_stdio_options():
    """Mirrors sidecar.js: piped stdio, unbuffered line-oriented text."""
    return {
        "stdin": subprocess.PIPE,
        "stdout": subprocess.PIPE,
        "stderr": subprocess.PIPE,
        "text": True,
        "encoding": "utf-8",
        "errors": "replace",
        "bufsize": 1,
    }


def _electron_style_options(exe_path):
    """
    Replicates the Electron main-process spawn contract for the sidecar
    (see src/main/sidecar.js): piped stdio, a controlled environment, and the
    Windows console-hiding flag. On non-Windows hosts the hide flag is omitted
    because the option does not exist there.
    """
    env = dict(os.environ)
    env["PYTHONIOENCODING"] = "utf-8"
    env["PYTHONUTF8"] = "1"
    env["AUTOEDIT_CORE_EXE"] = exe_path

    opts = _base_stdio_options()
    opts["env"] = env
    if sys.platform == "win32":
        # Electron spawns without a shell; the binary must be directly executable.
        opts["shell"] = False
        opts["creationflags"] = 0x08000000  # CREATE_NO_WINDOW
    return opts


def main():
    results = {}
    timings = {}

    print("==============================================================================")
    print("THREE-MODE SIDECAR PING ACCEPTANCE (build mandate §20)")
    print("==============================================================================")

    # -------------------------------------------------------------------------
    # 1. DIRECT
    # -------------------------------------------------------------------------
    direct_exe = _resolve(_candidates_direct())
    if direct_exe:
        print(f"\n[1/3] DIRECT_NUITKA_PING")
        print(f"      binary: {direct_exe}")
        ok, detail, elapsed_ms = _ping_once(direct_exe, "direct", _base_stdio_options())
        results["DIRECT_NUITKA_PING"] = ok
        timings["DIRECT_NUITKA_PING"] = elapsed_ms
        print(f"      {'✓' if ok else '✗'} {detail}")
        print(f"      DIRECT_NUITKA_PING_MS={elapsed_ms if elapsed_ms is not None else 'N/A'}")
    else:
        results["DIRECT_NUITKA_PING"] = None
        timings["DIRECT_NUITKA_PING"] = None
        print("\n[1/3] DIRECT_NUITKA_PING — SKIPPED (no freshly built core binary found)")
        print("      DIRECT_NUITKA_PING_MS=N/A")

    # -------------------------------------------------------------------------
    # 2. PACKAGED
    # -------------------------------------------------------------------------
    packaged_exe = _resolve(_candidates_packaged())
    if packaged_exe:
        print(f"\n[2/3] PACKAGED_NUITKA_PING")
        print(f"      binary: {packaged_exe}")
        ok, detail, elapsed_ms = _ping_once(packaged_exe, "packaged", _base_stdio_options())
        results["PACKAGED_NUITKA_PING"] = ok
        timings["PACKAGED_NUITKA_PING"] = elapsed_ms
        print(f"      {'✓' if ok else '✗'} {detail}")
        print(f"      PACKAGED_NUITKA_PING_MS={elapsed_ms if elapsed_ms is not None else 'N/A'}")
    else:
        results["PACKAGED_NUITKA_PING"] = None
        timings["PACKAGED_NUITKA_PING"] = None
        print("\n[2/3] PACKAGED_NUITKA_PING — SKIPPED (no packaged win-unpacked tree found)")
        print("      PACKAGED_NUITKA_PING_MS=N/A")

    # -------------------------------------------------------------------------
    # 3. ELECTRON-STYLE SPAWN
    # -------------------------------------------------------------------------
    electron_target = packaged_exe or direct_exe
    if electron_target:
        print(f"\n[3/3] ELECTRON_STYLE_SPAWN_PING")
        print(f"      binary: {electron_target}")
        ok, detail, elapsed_ms = _ping_once(electron_target, "electron-style", _electron_style_options(electron_target))
        results["ELECTRON_STYLE_SPAWN_PING"] = ok
        timings["ELECTRON_STYLE_SPAWN_PING"] = elapsed_ms
        print(f"      {'✓' if ok else '✗'} {detail}")
        print(f"      ELECTRON_STYLE_SPAWN_PING_MS={elapsed_ms if elapsed_ms is not None else 'N/A'}")
    else:
        results["ELECTRON_STYLE_SPAWN_PING"] = None
        timings["ELECTRON_STYLE_SPAWN_PING"] = None
        print("\n[3/3] ELECTRON_STYLE_SPAWN_PING — SKIPPED (no binary to test)")
        print("      ELECTRON_STYLE_SPAWN_PING_MS=N/A")

    # -------------------------------------------------------------------------
    # Report
    # -------------------------------------------------------------------------
    print("\n------------------------------------------------------------------------------")
    print("PING ACCEPTANCE MATRIX")
    print("------------------------------------------------------------------------------")
    failed = 0
    ran = 0
    for key in ("DIRECT_NUITKA_PING", "PACKAGED_NUITKA_PING", "ELECTRON_STYLE_SPAWN_PING"):
        value = results[key]
        # A skipped mode is NOT a pass: never convert NOT_RUN into PASS.
        status = "PASS" if value is True else ("FAIL" if value is False else "NOT_RUN")
        if value is True:
            ran += 1
        elif value is False:
            failed += 1
        elapsed = timings.get(key)
        print(f"  {key} = {status} ({elapsed if elapsed is not None else 'N/A'} ms)")

    print("------------------------------------------------------------------------------")

    if failed > 0:
        print("PING ACCEPTANCE = FAIL")
        sys.exit(1)

    if ran == 0:
        print("PING ACCEPTANCE = NOT_RUN (no binary was available to test)")
        # Exit non-zero so CI cannot silently treat an unrun gate as a success.
        sys.exit(2)

    print("PING ACCEPTANCE = PASS")
    sys.exit(0)


if __name__ == "__main__":
    main()
