"""
apply_update.py
Standalone helper script that waits for Flask server process to stop,
swaps files from staging, and launches the updated server.
"""

import os
import sys
import time
import shutil
import subprocess
import argparse


def main():
    parser = argparse.ArgumentParser(description="Standalone Updater Process")
    parser.add_argument("--staging", required=True, help="Staging folder path")
    parser.add_argument("--target", required=True, help="Target application root directory")
    parser.add_argument("--pid", type=int, help="PID of main Flask process to wait for")
    args = parser.parse_args()

    # Wait for main process to exit
    if args.pid:
        print(f"[Updater] Waiting for PID {args.pid} to terminate...")
        for _ in range(30):
            try:
                os.kill(args.pid, 0)
                time.sleep(0.5)
            except OSError:
                break

    # Copy files
    print("[Updater] Applying new files...")
    for item in os.listdir(args.staging):
        if item in ("__pycache__", ".git", ".venv", "backups", "temp", "uploads", "outputs"):
            continue
        s_item = os.path.join(args.staging, item)
        d_item = os.path.join(args.target, item)
        try:
            if os.path.isdir(s_item):
                shutil.copytree(s_item, d_item, dirs_exist_ok=True)
            else:
                shutil.copy2(s_item, d_item)
        except Exception as e:
            print(f"[Updater] Error copying {item}: {e}")

    # Launch new process
    print("[Updater] Starting updated application...")
    python_bin = sys.executable
    app_py = os.path.join(args.target, "app.py")
    subprocess.Popen([python_bin, app_py], cwd=args.target)
    print("[Updater] Update complete.")


if __name__ == "__main__":
    main()
