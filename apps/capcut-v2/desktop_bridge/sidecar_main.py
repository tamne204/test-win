"""
apps/capcut-v2/desktop_bridge/sidecar_main.py
Main entry point for Python Core Sidecar executable (autoedit-core / autoedit-core.exe).
Operates over stdin / stdout with JSON-RPC single-line protocol.
Zero HTTP, zero browser, zero localhost.
"""
from __future__ import annotations

import os
import sys
import signal
import traceback

# Force UTF-8 on Windows and POSIX
try:
    if sys.stdin is not None and hasattr(sys.stdin, "reconfigure"):
        sys.stdin.reconfigure(encoding="utf-8", errors="replace")
    if sys.stdout is not None and hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    if sys.stderr is not None and hasattr(sys.stderr, "reconfigure"):
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# Ensure apps/capcut-v2 is in sys.path
V2_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if V2_ROOT not in sys.path:
    sys.path.insert(0, V2_ROOT)

from desktop_bridge.protocol import (
    parse_message,
    serialize_message,
    create_error,
    ERR_INVALID_REQUEST,
    ERR_SIDECAR_INTERNAL_ERROR,
)
from desktop_bridge.bridge import DesktopBridge


def send_to_stdout(msg: dict):
    """Write serialized JSON line to stdout and flush immediately."""
    try:
        line = serialize_message(msg)
        sys.stdout.write(line)
        sys.stdout.flush()
    except Exception as e:
        sys.stderr.write(f"[Sidecar Error Writing Stdout] {e}\n")
        sys.stderr.flush()


def handle_sigterm(signum, frame):
    """Graceful shutdown handler."""
    sys.stderr.write(f"[Sidecar] Received signal {signum}. Shutting down cleanly.\n")
    sys.stderr.flush()
    sys.exit(0)


def main():
    # Register termination signals
    try:
        signal.signal(signal.SIGTERM, handle_sigterm)
        signal.signal(signal.SIGINT, handle_sigterm)
    except Exception:
        pass

    sys.stderr.write("==================================================\n")
    sys.stderr.write("2TOOLNE AUTOEDIT PYTHON SIDECAR STARTED\n")
    sys.stderr.write(f"PID: {os.getpid()} | Python: {sys.version}\n")
    sys.stderr.write(f"SIDECAR_VERSION: 2.0.5\n")
    sys.stderr.write("SIDECAR_BUILD_ID: 2TOOLNE-AUTOEDIT-2.0.5-WIN-X64\n")
    sys.stderr.write("DETECTOR_MODULE: adapters.capcut.detector.CapCutDetector\n")
    sys.stderr.write("DETECTOR_API_VERSION: 9.3.0.3970-verified-detect-only\n")
    sys.stderr.write("==================================================\n")
    sys.stderr.flush()

    bridge = DesktopBridge(
        notification_callback=send_to_stdout,
    )

    if sys.stdin is None or sys.stdout is None:
        if sys.stderr is not None:
            sys.stderr.write("[Sidecar] Launched without standard IO handles. Exiting.\n")
            sys.stderr.flush()
        sys.exit(0)

    # Main event loop over stdin
    while True:
        try:
            line = sys.stdin.readline()
            if not line:
                # EOF reached (Electron Main process closed stdin or terminated)
                sys.stderr.write("[Sidecar] EOF reached on stdin. Exiting.\n")
                break

            line_str = line.strip()
            if not line_str:
                continue

            try:
                req = parse_message(line_str)
            except Exception as parse_err:
                err_resp = create_error(
                    None,
                    ERR_INVALID_REQUEST,
                    f"Invalid JSON request: {parse_err}",
                )
                send_to_stdout(err_resp)
                continue

            # Dispatch request
            response = bridge.dispatch(req)
            send_to_stdout(response)

        except Exception as exc:
            sys.stderr.write(f"[Sidecar Uncaught Loop Exception] {exc}\n")
            traceback.print_exc(file=sys.stderr)
            sys.stderr.flush()
            err_resp = create_error(
                None,
                ERR_SIDECAR_INTERNAL_ERROR,
                f"Sidecar loop error: {exc}",
            )
            send_to_stdout(err_resp)

    sys.stderr.write("[Sidecar] Terminated gracefully.\n")
    sys.stderr.flush()
    sys.exit(0)


if __name__ == "__main__":
    main()
