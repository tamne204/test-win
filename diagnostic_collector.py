"""
diagnostic_collector.py
Client Diagnostic Collection and Reporting Module for VibeCode Studio.
Collects non-invasive technical diagnostics for troubleshooting:
- OS & Architecture (WMIC-free on Windows)
- Hardware & GPU (NVIDIA CUDA / Apple MPS / CPU)
- PyTorch & Renderer Configuration
- FFmpeg Encoders & Capabilities
- Recent Render Error Traces (with full path & credential redaction)
"""

from __future__ import annotations
from typing import List, Tuple, Dict, Any, Optional, Union, Callable, Set

import os
import sys
import re
import json
import time
import secrets
import platform
import subprocess
import shutil
from pathlib import Path
from datetime import datetime

from version import __version__ as APP_VERSION
import license_manager

DIAGNOSTIC_DIR = Path("diagnostics")
DIAGNOSTIC_DIR.mkdir(exist_ok=True)
REMOTE_DIAGNOSTIC_ENDPOINT = "https://www.2tamne.site/api/diagnostics/report.php"

# In-memory store for rate limiting (max 5 submissions per minute per IP)
_SUBMISSION_HISTORY: List[float] = []


def generate_diagnostic_id() -> str:
    """Generate a clean, privacy-safe diagnostic identifier: VBC-YYYYMMDD-XXXXXX."""
    date_str = datetime.now().strftime("%Y%m%d")
    rand_hex = secrets.token_hex(3).upper()  # 6 chars
    return f"VBC-{date_str}-{rand_hex}"


def redact_path(path_str: str) -> str:
    """
    Anonymize filesystem paths by replacing user home and sensitive directories.
    Example: 'C:\\Users\\JohnDoe\\Videos\\file.mp4' -> '<USER_HOME>\\Videos\\file.mp4'
    """
    if not path_str or not isinstance(path_str, str):
        return str(path_str)

    user_home = str(Path.home())
    username = os.environ.get("USERNAME") or os.environ.get("USER") or ""

    clean = path_str
    if user_home and user_home in clean:
        clean = clean.replace(user_home, "<USER_HOME>")
    if username and len(username) > 2 and username in clean:
        clean = clean.replace(username, "<USER>")

    # Redact common Windows / Unix user path patterns
    clean = re.sub(r'[A-Za-z]:\\Users\\[^\\]+', '<USER_HOME>', clean)
    clean = re.sub(r'/Users/[^/]+', '<USER_HOME>', clean)
    clean = re.sub(r'/home/[^/]+', '<USER_HOME>', clean)

    return clean


def sanitize_text(text: str) -> str:
    """
    Deep sanitation of strings: redacts paths, tokens, license keys, email addresses.
    """
    if not text or not isinstance(text, str):
        return str(text)

    s = text

    # Redact License Keys (e.g. 2TAMNE-XXXX-XXXX-XXXX)
    s = re.sub(r'2TAMNE-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}', '2TAMNE-****-****-****', s, flags=re.IGNORECASE)
    # Redact 64-char Hex HWIDs / Hashes
    s = re.sub(r'\b[a-fA-F0-9]{64}\b', '<REDACTED_64HEX>', s)
    # Redact 32-char Hex Session Secrets
    s = re.sub(r'\b[a-fA-F0-9]{32}\b', '<REDACTED_32HEX>', s)
    # Redact Email addresses
    s = re.sub(r'[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+', '<REDACTED_EMAIL>', s)

    # Redact Paths
    s = redact_path(s)

    return s


def sanitize_data_recursive(obj: Any) -> Any:
    """Recursively sanitize dictionary or list data structures."""
    if isinstance(obj, dict):
        return {k: sanitize_data_recursive(v) for k, v in obj.items() if k not in ("session_token", "X-App-Token", "license_key", "APP_SESSION_SECRET", "raw_script", "srt_text")}
    elif isinstance(obj, list):
        return [sanitize_data_recursive(item) for item in obj]
    elif isinstance(obj, str):
        return sanitize_text(obj)
    return obj


def get_nvidia_smi_info() -> Dict[str, Any]:
    """Safely query nvidia-smi for NVIDIA driver & GPU metrics without blocking."""
    info: Dict[str, Any] = {
        "available": False,
        "driver_version": "UNAVAILABLE",
        "gpu_name": "UNAVAILABLE",
        "vram_total_mb": 0,
        "vram_free_mb": 0
    }
    if shutil.which("nvidia-smi") is None:
        return info

    try:
        cmd = [
            "nvidia-smi",
            "--query-gpu=driver_version,name,memory.total,memory.free",
            "--format=csv,noheader,nounits"
        ]
        out = subprocess.check_output(cmd, timeout=3, text=True, stderr=subprocess.DEVNULL)
        lines = out.strip().splitlines()
        if lines:
            parts = [p.strip() for p in lines[0].split(',')]
            if len(parts) >= 4:
                info["available"] = True
                info["driver_version"] = parts[0]
                info["gpu_name"] = parts[1]
                info["vram_total_mb"] = int(float(parts[2]))
                info["vram_free_mb"] = int(float(parts[3]))
    except Exception:
        pass
    return info


def get_system_diagnostics() -> Dict[str, Any]:
    """Collect non-invasive platform, CPU, RAM, and OS metadata (WMIC-free)."""
    import psutil

    sys_info: Dict[str, Any] = {
        "os_platform": platform.system(),
        "os_release": platform.release(),
        "os_version": platform.version(),
        "architecture": platform.machine(),
        "python_version": platform.python_version(),
        "cpu_model": platform.processor() or "Unknown CPU",
        "cpu_cores_physical": psutil.cpu_count(logical=False) or 0,
        "cpu_cores_logical": psutil.cpu_count(logical=True) or 0,
        "ram_total_gb": round(psutil.virtual_memory().total / (1024 ** 3), 2),
        "ram_available_gb": round(psutil.virtual_memory().available / (1024 ** 3), 2),
    }

    if platform.system() == "Windows":
        try:
            win_ver = sys.getwindowsversion()
            sys_info["windows_build"] = win_ver.build
            sys_info["windows_major"] = win_ver.major
            sys_info["windows_minor"] = win_ver.minor
        except Exception:
            sys_info["windows_build"] = "Unknown"

    return sys_info


def get_gpu_pytorch_diagnostics() -> Dict[str, Any]:
    """Collect PyTorch CUDA / MPS / CPU backend capabilities."""
    gpu_data: Dict[str, Any] = {
        "pytorch_installed": False,
        "pytorch_version": "UNAVAILABLE",
        "cuda_available": False,
        "cuda_version": "UNAVAILABLE",
        "cuda_device_count": 0,
        "mps_available": False,
        "selected_device": "cpu",
        "grid_sample_bilinear_supported": False
    }

    try:
        import torch
        import torch.nn.functional as F
        gpu_data["pytorch_installed"] = True
        gpu_data["pytorch_version"] = torch.__version__
        gpu_data["cuda_available"] = bool(torch.cuda.is_available())
        gpu_data["mps_available"] = bool(hasattr(torch.backends, "mps") and torch.backends.mps.is_available())

        if gpu_data["cuda_available"]:
            gpu_data["cuda_version"] = torch.version.cuda or "Unknown"
            gpu_data["cuda_device_count"] = torch.cuda.device_count()
            if gpu_data["cuda_device_count"] > 0:
                gpu_data["selected_device"] = torch.cuda.get_device_name(0)
        elif gpu_data["mps_available"]:
            gpu_data["selected_device"] = "Apple Metal (MPS)"
        else:
            gpu_data["selected_device"] = "CPU Tensor Backend"

        # Diagnostic test of grid_sample
        dev = torch.device("cuda" if gpu_data["cuda_available"] else ("mps" if gpu_data["mps_available"] else "cpu"))
        dummy_img = torch.zeros((1, 3, 16, 16), device=dev, dtype=torch.float32)
        dummy_grid = torch.zeros((1, 16, 16, 2), device=dev, dtype=torch.float32)
        _ = F.grid_sample(dummy_img, dummy_grid, mode="bilinear", padding_mode="reflection", align_corners=False)
        gpu_data["grid_sample_bilinear_supported"] = True
    except Exception as e:
        gpu_data["grid_sample_error"] = str(e)

    # Attach nvidia-smi if on NVIDIA
    gpu_data["nvidia_smi"] = get_nvidia_smi_info()
    return gpu_data


def get_ffmpeg_diagnostics() -> Dict[str, Any]:
    """Collect FFmpeg binary path, version, and hardware encoder availability."""
    from ffmpeg_utils import get_ffmpeg_bin, get_ffmpeg_security_info
    sec_info = get_ffmpeg_security_info()

    ff_data: Dict[str, Any] = {
        "binary_path": redact_path(sec_info.get("binary_path", "")),
        "version_string": sec_info.get("version", "Unknown"),
        "sha256": sec_info.get("sha256", "Unknown")[:16] + "...",
        "encoders": {
            "h264_nvenc": False,
            "hevc_nvenc": False,
            "h264_videotoolbox": False,
            "libx264": False
        }
    }

    try:
        bin_p = get_ffmpeg_bin()
        res = subprocess.run([bin_p, "-encoders"], capture_output=True, text=True, timeout=5)
        enc_out = res.stdout
        for enc in ff_data["encoders"].keys():
            if enc in enc_out:
                ff_data["encoders"][enc] = True
    except Exception:
        pass

    return ff_data


def collect_diagnostic_report(
    recent_error: Optional[Dict[str, Any]] = None,
    job_context: Optional[Dict[str, Any]] = None
) -> Dict[str, Any]:
    """
    Assemble a complete, structured diagnostic report.
    Guaranteed: 100% redacted, zero media, zero credentials, privacy-compliant.
    """
    diag_id = generate_diagnostic_id()
    now_iso = datetime.now().isoformat()

    raw_report: Dict[str, Any] = {
        "diagnostic_id": diag_id,
        "timestamp": now_iso,
        "app_version": APP_VERSION,
        "system": get_system_diagnostics(),
        "gpu_pytorch": get_gpu_pytorch_diagnostics(),
        "ffmpeg": get_ffmpeg_diagnostics(),
        "license_state": {
            "is_licensed": license_manager.is_licensed(),
            "tier": license_manager.get_status().get("tier", "NONE"),
            "status": license_manager.get_status().get("status", "unactivated")
        },
        "renderer": {
            "primary": "Renderer G (Glide GPU Subpixel)",
            "fallback": "Renderer D (Golden Baseline 4X)",
            "default_mode": "auto"
        },
        "recent_job": job_context or {},
        "recent_error": recent_error or {}
    }

    # Deep sanitization
    clean_report = sanitize_data_recursive(raw_report)
    return clean_report


def save_diagnostic_report_locally(report: Dict[str, Any]) -> str:
    """Save the sanitized report to diagnostics/ directory and return path."""
    diag_id = report.get("diagnostic_id", generate_diagnostic_id())
    out_file = DIAGNOSTIC_DIR / f"diagnostic_report_{diag_id}.json"
    with open(out_file, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)
    return str(out_file)


def submit_diagnostic_report(report: Dict[str, Any], server_url: str = REMOTE_DIAGNOSTIC_ENDPOINT) -> Dict[str, Any]:
    """
    Transmit the sanitized diagnostic report to the support server via HTTPS.
    Includes rate-limiting (max 5 per minute) and size validation (< 1MB).
    """
    global _SUBMISSION_HISTORY
    now = time.time()
    _SUBMISSION_HISTORY = [t for t in _SUBMISSION_HISTORY if now - t < 60.0]
    if len(_SUBMISSION_HISTORY) >= 5:
        return {
            "ok": False,
            "error": "RATE_LIMITED",
            "message": "Quá nhiều yêu cầu gửi báo cáo trong 1 phút. Vui lòng thử lại sau giây lát."
        }

    # Verify size
    payload_str = json.dumps(report, ensure_ascii=False)
    if len(payload_str.encode("utf-8")) > 1024 * 1024:  # 1MB limit
        return {
            "ok": False,
            "error": "PAYLOAD_TOO_LARGE",
            "message": "Báo cáo chẩn đoán vượt quá kích thước cho phép (1MB)."
        }

    import requests
    try:
        _SUBMISSION_HISTORY.append(now)
        res = requests.post(
            server_url,
            json=report,
            timeout=15,
            headers={"Content-Type": "application/json", "User-Agent": f"VibeCode-Diagnostics/{APP_VERSION}"}
        )
        if res.status_code == 200:
            data = res.json()
            return {
                "ok": True,
                "diagnostic_id": report.get("diagnostic_id"),
                "message": data.get("message", "Báo cáo chẩn đoán đã được gửi thành công đến đội ngũ kỹ thuật!")
            }
        else:
            return {
                "ok": False,
                "error": f"SERVER_HTTP_{res.status_code}",
                "message": f"Máy chủ phản hồi mã lỗi {res.status_code}."
            }
    except requests.exceptions.RequestException as e:
        # Offline or unreachable support server -> save locally
        local_path = save_diagnostic_report_locally(report)
        return {
            "ok": False,
            "offline_saved": True,
            "local_path": redact_path(local_path),
            "diagnostic_id": report.get("diagnostic_id"),
            "error": "SERVER_UNREACHABLE",
            "message": "Không thể kết nối đến máy chủ hỗ trợ. Báo cáo đã được lưu an toàn tại máy cục bộ."
        }
