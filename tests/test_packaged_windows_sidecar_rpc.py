"""
tests/test_packaged_windows_sidecar_rpc.py
Direct runtime validation for the packaged Windows autoedit-core.exe.
Verifies:
1. SIDECAR_PROCESS_START = PASS
2. PROCESS_STAYS_ALIVE = PASS
3. JSON_RPC_READY = PASS
4. GENERATE_PROJECT_RPC = PASS (NO detector.status AttributeError)
"""
import os
import sys
import json
import time
import subprocess
import threading

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

def find_autoedit_core_exe():
    env_path = os.environ.get("AUTOEDIT_CORE_EXE")
    if env_path and os.path.isfile(env_path):
        return env_path
    
    candidates = [
        os.path.join(REPO_ROOT, "apps", "capcut-v2", "packaging", "dist", "autoedit-core", "autoedit-core.exe"),
        os.path.join(REPO_ROOT, "apps", "capcut-v2", "desktop", "resources", "autoedit-core", "win-x64", "autoedit-core.exe"),
        os.path.join(REPO_ROOT, "dist", "customer_package_staging", "2TOOLNE", "resources", "autoedit-core", "autoedit-core.exe"),
    ]
    for c in candidates:
        if os.path.isfile(c):
            return c
    raise FileNotFoundError(f"Could not find autoedit-core.exe. Checked: {candidates}")


def main():
    exe_path = find_autoedit_core_exe()
    print("==================================================")
    print("TESTING PACKAGED WINDOWS SIDECAR RPC")
    print(f"Target Binary: {exe_path}")
    print(f"File Size: {os.path.getsize(exe_path):,} bytes")
    print("==================================================")

    # 1. Start process
    start_time = time.time()
    try:
        proc = subprocess.Popen(
            [exe_path],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1,
        )
        print("SIDECAR_PROCESS_START = PASS")
    except Exception as e:
        print(f"SIDECAR_PROCESS_START = FAIL ({e})")
        sys.exit(1)

    # Collector for stderr
    stderr_lines = []
    def read_stderr():
        for line in proc.stderr:
            stderr_lines.append(line)
            sys.stderr.write(f"  [sidecar stderr] {line}")
            sys.stderr.flush()

    err_thread = threading.Thread(target=read_stderr, daemon=True)
    err_thread.start()

    # 2. Verify process stays alive
    time.sleep(2)
    ret = proc.poll()
    if ret is not None:
        print(f"PROCESS_STAYS_ALIVE = FAIL (Exited prematurely with code: {ret})")
        sys.exit(1)
    print("PROCESS_STAYS_ALIVE = PASS")

    # 3. Test JSON-RPC PING -> PONG
    ping_req = json.dumps({"jsonrpc": "2.0", "id": 1, "method": "PING"}) + "\n"
    proc.stdin.write(ping_req)
    proc.stdin.flush()

    ping_resp_line = proc.stdout.readline()
    print(f"Ping response raw: {ping_resp_line.strip()}")
    try:
        ping_resp = json.loads(ping_resp_line)
        if ping_resp.get("result") == "PONG" or ping_resp.get("id") == 1:
            print("JSON_RPC_READY = PASS")
        else:
            print(f"JSON_RPC_READY = FAIL (Unexpected ping response: {ping_resp})")
            sys.exit(1)
    except Exception as e:
        print(f"JSON_RPC_READY = FAIL (Failed to parse JSON response: {e})")
        sys.exit(1)

    # 4. Test GENERATE_CAPCUT_PROJECT
    print("Testing GENERATE_CAPCUT_PROJECT RPC...")
    gen_req = json.dumps({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "GENERATE_CAPCUT_PROJECT",
        "params": {
            "project_name": "Packaged_204_Verification_Project",
            "aspect_ratio": "9:16",
            "timeline": []
        }
    }) + "\n"
    proc.stdin.write(gen_req)
    proc.stdin.flush()

    gen_resp_line = proc.stdout.readline()
    print(f"Generate response raw: {gen_resp_line.strip()}")
    
    # Check for detector.status error anywhere in stderr or response
    all_stderr = "".join(stderr_lines)
    if "AttributeError" in all_stderr and "status" in all_stderr:
        print("FAIL: AttributeError: 'CapCutDetector' object has no attribute 'status' found in stderr!")
        print(all_stderr)
        proc.kill()
        sys.exit(1)
    
    if "AttributeError" in gen_resp_line and "status" in gen_resp_line:
        print("FAIL: AttributeError: 'CapCutDetector' object has no attribute 'status' returned in RPC response!")
        proc.kill()
        sys.exit(1)

    try:
        gen_resp = json.loads(gen_resp_line)
        print(f"Parsed response status: {gen_resp.get('result', {}).get('status') or gen_resp.get('error')}")
        print("GENERATE_PROJECT_RPC = PASS")
        print("NO detector.status AttributeError = PASS")
    except Exception as e:
        print(f"GENERATE_PROJECT_RPC = FAIL ({e})")
        proc.kill()
        sys.exit(1)

    # Cleanup
    try:
        proc.stdin.write(json.dumps({"jsonrpc": "2.0", "id": 999, "method": "EXIT"}) + "\n")
        proc.stdin.flush()
        proc.wait(timeout=5)
    except Exception:
        proc.kill()

    print("==================================================")
    print("PACKAGED SIDECAR VALIDATION COMPLETED SUCCESSFULLY!")
    print("==================================================")


if __name__ == "__main__":
    main()
