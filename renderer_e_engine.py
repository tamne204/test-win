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


class RGSSQualityController:
    """
    Manages quality presets and dynamic sample allocations for Renderer E.
    """
    PRESETS = {
        "fast": {"num_samples": 2, "batch_size": 20},
        "balanced": {"num_samples": 4, "batch_size": 15},
        "ultra": {"num_samples": 8, "batch_size": 10}
    }

    @classmethod
    def get_offsets(cls, mode: str, width: int, height: int):
        preset = cls.PRESETS.get(mode, cls.PRESETS["balanced"])
        num_samples = preset["num_samples"]
        if num_samples == 2:
            offsets = [(-0.25 / width, -0.25 / height), (0.25 / width, 0.25 / height)]
        elif num_samples == 8:
            offsets = [
                (-0.375 / width, -0.375 / height), (-0.125 / width, -0.375 / height),
                (0.125 / width, -0.375 / height), (0.375 / width, -0.375 / height),
                (-0.375 / width, 0.375 / height), (-0.125 / width, 0.375 / height),
                (0.125 / width, 0.375 / height), (0.375 / width, 0.375 / height)
            ]
        else: # 4 samples (balanced)
            offsets = [
                (-0.375 / width, -0.125 / height), (0.125 / width, -0.375 / height),
                (-0.125 / width, 0.375 / height), (0.375 / width, 0.125 / height)
            ]
        return offsets, preset["batch_size"], num_samples


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
    sampling_mode: str = "balanced",
    codec: Optional[str] = None,
    crf: int = 18
) -> bool:
    """
    Render a single slide with high-speed batched GPU subpixel transformation.
    """
    if not _HAS_TORCH:
        logger.warning("PyTorch not installed, falling back to standard renderer.")
        return False

    caps = get_gpu_capabilities()
    if not codec:
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
        orig_img = Image.open(image_path).convert("RGB")
        img_w, img_h = orig_img.size
        
        target_aspect = width / height
        img_aspect = img_w / img_h
        
        if img_aspect > target_aspect:
            base_h = height * 2
            base_w = int(base_h * img_aspect)
        else:
            base_w = width * 2
            base_h = int(base_w / img_aspect)
        
        base_w += base_w % 2
        base_h += base_h % 2
        
        pre_img = orig_img.resize((base_w, base_h), Image.Resampling.LANCZOS)
        crop_w = width * 2
        crop_h = height * 2
        left = (base_w - crop_w) // 2
        top = (base_h - crop_h) // 2
        cropped = pre_img.crop((left, top, left + crop_w, top + crop_h))
        
        arr = np.array(cropped).astype(np.float32) / 255.0
        tensor_base = torch.from_numpy(arr).permute(2, 0, 1).unsqueeze(0).to(device)

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
        
        # Configure Quality Mode Offsets
        mode_key = sampling_mode.lower() if sampling_mode.lower() in RGSSQualityController.PRESETS else "balanced"
        offsets, batch_size, num_samples = RGSSQualityController.get_offsets(mode_key, width, height)
        offsets_t = torch.tensor(offsets, dtype=torch.float32, device=device)

        # Precompute camera trajectory
        transforms = []
        for on in range(total_frames):
            prog = float(on) / float(NF)
            tr = CameraMotionEngine.get_transform(prog, effect=effect, magnitude=magnitude, curve=curve)
            transforms.append(tr)

        # Vectorized batch processing
        for start_idx in range(0, total_frames, batch_size):
            end_idx = min(total_frames, start_idx + batch_size)
            curr_batch_len = end_idx - start_idx
            
            total_sub_batch = curr_batch_len * num_samples
            thetas_chunk = torch.zeros((total_sub_batch, 2, 3), dtype=torch.float32, device=device)
            
            for i in range(curr_batch_len):
                f_idx = start_idx + i
                tr = transforms[f_idx]
                z = max(0.5, tr.zoom)
                inv_z = 0.5 / z
                
                base_tx = -tr.x * (0.5 - inv_z) if abs(tr.x) > 1e-6 else 0.0
                base_ty = -tr.y * (0.5 - inv_z) if abs(tr.y) > 1e-6 else 0.0
                
                for s in range(num_samples):
                    sub_i = i * num_samples + s
                    thetas_chunk[sub_i, 0, 0] = inv_z
                    thetas_chunk[sub_i, 1, 1] = inv_z
                    thetas_chunk[sub_i, 0, 2] = base_tx + offsets_t[s, 0] * 2.0
                    thetas_chunk[sub_i, 1, 2] = base_ty + offsets_t[s, 1] * 2.0

            out_size = (total_sub_batch, 3, height, width)
            tensor_expanded = tensor_base.expand(total_sub_batch, -1, -1, -1)
            
            grid_chunk = F.affine_grid(thetas_chunk, out_size, align_corners=True)
            sampled_chunk = F.grid_sample(tensor_expanded, grid_chunk, mode='bilinear', padding_mode='reflection', align_corners=True)
            
            sampled_reshaped = sampled_chunk.view(curr_batch_len, num_samples, 3, height, width)
            frames_reduced = sampled_reshaped.mean(dim=1)
            
            frames_uint8 = torch.clamp(frames_reduced * 255.0, 0, 255).to(torch.uint8).permute(0, 2, 3, 1).cpu().numpy()
            proc.stdin.write(frames_uint8.tobytes())

        proc.stdin.close()
        proc.wait()
        
        return proc.returncode == 0 and os.path.exists(output_mp4) and os.path.getsize(output_mp4) > 1000

    except Exception as e:
        logger.error(f"Renderer E failed with error: {e}", exc_info=True)
        return False