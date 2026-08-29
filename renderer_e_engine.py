"""
renderer_e_engine.py
Renderer E: High-Precision Subpixel GPU Camera Engine.
Leverages continuous floating-point affine grid sampling on Apple MPS (Metal) / NVIDIA CUDA
with fallback to high-performance CPU subpixel interpolation and Renderer D.
"""

import os
import sys
import subprocess
import logging
from typing import Tuple, Optional, Dict, Any
from PIL import Image
import numpy as np

from camera_engine import CameraMotionEngine, CameraTransform

logger = logging.getLogger("RendererE")
logging.basicConfig(level=logging.INFO)

# Check PyTorch & GPU availability
_HAS_TORCH = False
_GPU_BACKEND = "none"

try:
    import torch
    import torch.nn.functional as F
    _HAS_TORCH = True
    if torch.backends.mps.is_available():
        _GPU_BACKEND = "apple_mps"
    elif torch.cuda.is_available():
        _GPU_BACKEND = "nvidia_cuda"
    else:
        _GPU_BACKEND = "cpu_torch"
except Exception as e:
    _HAS_TORCH = False
    _GPU_BACKEND = "unavailable"


def get_gpu_capabilities() -> Dict[str, Any]:
    """
    Detect system GPU acceleration capabilities and available encoders.
    """
    is_mac = sys.platform == "darwin"
    is_win = sys.platform.startswith("win")
    
    encoders = []
    try:
        res = subprocess.run(["ffmpeg", "-encoders"], capture_output=True, text=True, timeout=5)
        out = res.stdout.lower()
        if "h264_videotoolbox" in out:
            encoders.append("h264_videotoolbox")
        if "hevc_videotoolbox" in out:
            encoders.append("hevc_videotoolbox")
        if "h264_nvenc" in out:
            encoders.append("h264_nvenc")
        if "h264_qsv" in out:
            encoders.append("h264_qsv")
        if "h264_amf" in out:
            encoders.append("h264_amf")
    except Exception:
        pass
    encoders.append("libx264")

    return {
        "has_torch": _HAS_TORCH,
        "gpu_backend": _GPU_BACKEND,
        "is_mac": is_mac,
        "is_windows": is_win,
        "encoders": encoders,
        "recommended_encoder": encoders[0] if encoders else "libx264"
    }


def render_slide_subpixel(
    image_path: str,
    output_mp4: str,
    duration: float = 5.0,
    fps: int = 30,
    width: int = 1920,
    height: int = 1080,
    effect: str = "zoom_in",
    magnitude: float = 0.20,
    curve: str = "linear",
    sampling_mode: str = "prefiltered_gpu",
    codec: Optional[str] = None,
    crf: int = 18
) -> bool:
    """
    Render a single slide with subpixel GPU transformation.
    Falls back gracefully if GPU or PyTorch is unavailable.
    """
    if not _HAS_TORCH:
        logger.warning("PyTorch not installed, falling back to standard renderer.")
        return False

    caps = get_gpu_capabilities()
    if not codec:
        # Prefer fast native encoder if available
        if caps["is_mac"] and "h264_videotoolbox" in caps["encoders"]:
            encoder = "h264_videotoolbox"
            enc_args = ["-c:v", encoder, "-b:v", "12M", "-pix_fmt", "yuv420p"]
        elif caps["is_windows"] and "h264_nvenc" in caps["encoders"]:
            encoder = "h264_nvenc"
            enc_args = ["-c:v", encoder, "-preset", "p5", "-cq", str(crf), "-pix_fmt", "yuv420p"]
        else:
            encoder = "libx264"
            enc_args = ["-c:v", encoder, "-preset", "veryfast", "-crf", str(crf), "-pix_fmt", "yuv420p"]
    else:
        encoder = codec
        enc_args = ["-c:v", encoder, "-pix_fmt", "yuv420p"]

    device_str = "mps" if _GPU_BACKEND == "apple_mps" else ("cuda" if _GPU_BACKEND == "nvidia_cuda" else "cpu")
    device = torch.device(device_str)

    total_frames = max(2, int(round(duration * fps)))
    NF = total_frames - 1

    try:
        # Load source image
        orig_img = Image.open(image_path).convert("RGB")
        img_w, img_h = orig_img.size
        
        # Determine aspect ratio fit / fill
        target_aspect = width / height
        img_aspect = img_w / img_h
        
        # Base scale to fill destination window
        if img_aspect > target_aspect:
            base_h = height * 2
            base_w = int(base_h * img_aspect)
        else:
            base_w = width * 2
            base_h = int(base_w / img_aspect)
        
        # Ensure even dimensions
        base_w += base_w % 2
        base_h += base_h % 2
        
        # Pre-scale 2X for anti-aliased Lanczos boundary
        pre_img = orig_img.resize((base_w, base_h), Image.Resampling.LANCZOS)
        
        # Center crop to 2X destination aspect ratio
        crop_w = width * 2
        crop_h = height * 2
        left = (base_w - crop_w) // 2
        top = (base_h - crop_h) // 2
        cropped = pre_img.crop((left, top, left + crop_w, top + crop_h))
        
        # Convert to float32 tensor [1, 3, H, W] in [0, 1]
        arr = np.array(cropped).astype(np.float32) / 255.0
        tensor = torch.from_numpy(arr).permute(2, 0, 1).unsqueeze(0).to(device)

        # Launch FFmpeg pipe
        os.makedirs(os.path.dirname(os.path.abspath(output_mp4)), exist_ok=True)
        ffmpeg_cmd = [
            "ffmpeg", "-y",
            "-f", "rawvideo",
            "-vcodec", "rawvideo",
            "-s", f"{width}x{height}",
            "-pix_fmt", "rgb24",
            "-r", str(fps),
            "-i", "-"
        ] + enc_args + [output_mp4]

        proc = subprocess.Popen(ffmpeg_cmd, stdin=subprocess.PIPE, stderr=subprocess.DEVNULL)
        # RGSS Subpixel Sampling Pattern (Rotated Grid Super-Sampling)
        if sampling_mode == "rgss_4x" or sampling_mode == "prefiltered_gpu":
            num_samples = 4
            offsets = [
                (-0.375 / width, -0.125 / height),
                (0.125 / width, -0.375 / height),
                (-0.125 / width, 0.375 / height),
                (0.375 / width, 0.125 / height)
            ]
        elif sampling_mode == "rgss_2x":
            num_samples = 2
            offsets = [
                (-0.25 / width, -0.25 / height),
                (0.25 / width, 0.25 / height)
            ]
        else:
            num_samples = 1
            offsets = [(0.0, 0.0)]

        offsets_t = torch.tensor(offsets, dtype=torch.float32, device=device)
        out_size = (num_samples, 3, height, width)
        tensor_batch = tensor.expand(num_samples, -1, -1, -1)

        # Render frame sequence
        for on in range(total_frames):
            prog = float(on) / float(NF)
            transform = CameraMotionEngine.get_transform(
                progress=prog,
                effect=effect,
                magnitude=magnitude,
                curve=curve
            )

            # Map zoom and displacement to 2X canvas [-0.5, 0.5]
            z = max(0.5, transform.zoom)
            inv_z = 0.5 / z
            
            # Subpixel base offset
            base_tx = -transform.x * (0.5 - inv_z) if abs(transform.x) > 1e-6 else 0.0
            base_ty = -transform.y * (0.5 - inv_z) if abs(transform.y) > 1e-6 else 0.0

            thetas = torch.zeros((num_samples, 2, 3), dtype=torch.float32, device=device)
            thetas[:, 0, 0] = inv_z
            thetas[:, 1, 1] = inv_z
            thetas[:, 0, 2] = base_tx + offsets_t[:, 0] * 2.0
            thetas[:, 1, 2] = base_ty + offsets_t[:, 1] * 2.0
            
            grid_batch = F.affine_grid(thetas, out_size, align_corners=True)
            sampled_batch = F.grid_sample(tensor_batch, grid_batch, mode='bilinear', padding_mode='reflection', align_corners=True)
            
            accum = sampled_batch.mean(dim=0, keepdim=True)
            frame_bytes = (accum[0].permute(1, 2, 0).clamp(0.0, 1.0).cpu().numpy() * 255.0).astype(np.uint8).tobytes()
            proc.stdin.write(frame_bytes)

        proc.stdin.close()
        proc.wait()
        
        return proc.returncode == 0 and os.path.exists(output_mp4) and os.path.getsize(output_mp4) > 1000

    except Exception as e:
        logger.error(f"Renderer E failed with error: {e}", exc_info=True)
        return False