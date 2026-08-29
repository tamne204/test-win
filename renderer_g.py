"""
renderer_g.py
Renderer G: Glide-Style GPU Subpixel Camera Engine.
Inspired by Loomos-hub/glide-ffmpeg.
Continuous floating-point affine transform + GPU torch.grid_sample + direct raw RGB streaming to FFmpeg.
Zero integer quantization on camera coordinates.
"""

import os
import sys
import time
import subprocess
import logging
from typing import Optional, Dict, Any, List
from PIL import Image
import numpy as np

try:
    import torch
    import torch.nn.functional as F
    _HAS_TORCH = True
    if torch.backends.mps.is_available():
        _DEFAULT_DEVICE = torch.device("mps")
    elif torch.cuda.is_available():
        _DEFAULT_DEVICE = torch.device("cuda")
    else:
        _DEFAULT_DEVICE = torch.device("cpu")
except Exception:
    _HAS_TORCH = False
    _DEFAULT_DEVICE = None

logger = logging.getLogger("RendererG")


class GlideGPUEngine:
    """
    High-performance GPU Subpixel Pan & Zoom engine.
    Eliminates FFmpeg zoompan integer offset truncation by computing continuous
    fractional UV sampling directly on GPU hardware.
    """
    def __init__(self, device: Optional[Any] = None):
        self.device = device or _DEFAULT_DEVICE
        self._cached_grid = {}

    def get_base_grid(self, width: int, height: int) -> "torch.Tensor":
        """
        Get or create cached normalized meshgrid [-1.0, 1.0] on GPU.
        """
        key = (width, height, str(self.device))
        if key not in self._cached_grid:
            y = torch.linspace(-1.0, 1.0, height, device=self.device, dtype=torch.float32)
            x = torch.linspace(-1.0, 1.0, width, device=self.device, dtype=torch.float32)
            grid_y, grid_x = torch.meshgrid(y, x, indexing='ij')
            grid = torch.stack([grid_x, grid_y], dim=-1).unsqueeze(0)  # Shape: (1, H, W, 2)
            self._cached_grid[key] = grid
        return self._cached_grid[key]

    def render_segment_to_pipe(self,
                               image_path: str,
                               output_path: str,
                               duration: float,
                               fps: int = 60,
                               width: int = 1920,
                               height: int = 1080,
                               effect: str = "zoom_in",
                               magnitude: float = 0.20,
                               source_scale_mode: str = "1x",
                               batch_size: int = 8,
                               encoder: str = "libx264",
                               crf: int = 18,
                               bitrate: str = "12M",
                               sampling_mode: str = "bilinear") -> Dict[str, Any]:
        """
        Render a Ken Burns slide directly to video via GPU grid_sample + FFmpeg pipe.
        """
        if not _HAS_TORCH:
            raise RuntimeError("PyTorch is required for Renderer G.")

        t_start = time.perf_counter()
        total_frames = int(round(duration * fps))
        if total_frames < 1:
            total_frames = 1

        # 1. Load source image as float32 RGB tensor
        img_pil = Image.open(image_path).convert("RGB")
        src_w, src_h = img_pil.size

        # Source resolution strategy
        if source_scale_mode == "2x":
            target_src_w, target_src_h = width * 2, height * 2
            img_pil = img_pil.resize((target_src_w, target_src_h), Image.Resampling.BICUBIC)
        elif source_scale_mode == "4x":
            target_src_w, target_src_h = width * 4, height * 4
            img_pil = img_pil.resize((target_src_w, target_src_h), Image.Resampling.BICUBIC)

        img_np = np.array(img_pil, dtype=np.float32) / 255.0  # (H, W, 3)
        img_tensor = torch.from_numpy(img_np).permute(2, 0, 1).unsqueeze(0).to(self.device)  # (1, 3, H, W)

        # 2. Setup Base Grid on GPU
        base_grid = self.get_base_grid(width, height)

        # 3. Setup FFmpeg encoding pipe
        cmd = [
            "ffmpeg", "-y", "-loglevel", "error",
            "-f", "rawvideo", "-pix_fmt", "rgb24",
            "-s", f"{width}x{height}", "-r", str(fps),
            "-i", "-",
            "-t", str(duration)
        ]

        if encoder == "libx264":
            cmd += ["-c:v", "libx264", "-preset", "veryfast", "-crf", str(crf), "-pix_fmt", "yuv420p"]
        elif encoder in ("h264_videotoolbox", "hevc_videotoolbox"):
            cmd += ["-c:v", encoder, "-b:v", bitrate, "-pix_fmt", "yuv420p"]
        elif encoder in ("h264_nvenc", "hevc_nvenc"):
            cmd += ["-c:v", encoder, "-preset", "p4", "-cq", str(crf), "-pix_fmt", "yuv420p"]
        else:
            cmd += ["-c:v", "libx264", "-preset", "veryfast", "-crf", str(crf), "-pix_fmt", "yuv420p"]

        cmd.append(output_path)

        proc = subprocess.Popen(cmd, stdin=subprocess.PIPE, stderr=subprocess.PIPE)

        # 4. Stream transformed frames in batches
        NF = max(1, total_frames - 1)
        mag = float(magnitude)

        frame_idx = 0
        while frame_idx < total_frames:
            cur_batch_size = min(batch_size, total_frames - frame_idx)
            
            # Compute camera parameters for batch
            grids_in_batch = []
            for b in range(cur_batch_size):
                f_num = frame_idx + b
                t = float(f_num) / NF  # Normalized progress in [0.0, 1.0]

                # Exact Legacy Linear Trajectory
                if effect == "zoom_in":
                    z = 1.0 + mag * t
                    x_shift = 0.0
                    y_shift = 0.0
                elif effect == "zoom_out":
                    z = 1.0 + mag * (1.0 - t)
                    x_shift = 0.0
                    y_shift = 0.0
                elif effect == "pan_lr":
                    z = 1.0 + mag
                    x_shift = (2.0 * t - 1.0) * (1.0 - 1.0 / z)
                    y_shift = 0.0
                elif effect == "pan_rl":
                    z = 1.0 + mag
                    x_shift = (1.0 - 2.0 * t) * (1.0 - 1.0 / z)
                    y_shift = 0.0
                elif effect == "tilt_ud":
                    z = 1.0 + mag
                    x_shift = 0.0
                    y_shift = (2.0 * t - 1.0) * (1.0 - 1.0 / z)
                elif effect == "tilt_du":
                    z = 1.0 + mag
                    x_shift = 0.0
                    y_shift = (1.0 - 2.0 * t) * (1.0 - 1.0 / z)
                else: # static
                    z = 1.0
                    x_shift = 0.0
                    y_shift = 0.0

                # Continuous fractional UV transformation
                # Destination coordinate (u, v) maps to source coordinate (u/z + x_shift, v/z + y_shift)
                transformed = (base_grid / z)
                if x_shift != 0.0 or y_shift != 0.0:
                    shift_tensor = torch.tensor([[[[x_shift, y_shift]]]], device=self.device, dtype=torch.float32)
                    transformed = transformed + shift_tensor
                grids_in_batch.append(transformed)

            if cur_batch_size == 1:
                batch_grid = grids_in_batch[0]
                batch_img = img_tensor
            else:
                batch_grid = torch.cat(grids_in_batch, dim=0)  # (B, H, W, 2)
                batch_img = img_tensor.expand(cur_batch_size, -1, -1, -1)  # (B, 3, H, W)

            # GPU Subpixel Fractional Sampling
            sampled = F.grid_sample(
                batch_img,
                batch_grid,
                mode=sampling_mode,
                padding_mode="reflection",
                align_corners=False
            )

            # Convert to uint8 RGB bytes (B, 3, H, W) -> (B, H, W, 3) -> raw bytes
            sampled_uint8 = sampled.permute(0, 2, 3, 1).mul(255.0).clamp(0, 255).byte()
            raw_bytes = sampled_uint8.cpu().numpy().tobytes()
            
            try:
                proc.stdin.write(raw_bytes)
            except (BrokenPipeError, IOError):
                break

            frame_idx += cur_batch_size

        if proc.stdin:
            proc.stdin.close()
        proc.wait()

        t_render = time.perf_counter() - t_start
        fps_achieved = total_frames / (t_render + 1e-6)

        return {
            "output_path": output_path,
            "total_frames": total_frames,
            "render_time_s": round(t_render, 3),
            "fps_achieved": round(fps_achieved, 1),
            "file_size_kb": round(os.path.getsize(output_path) / 1024, 1) if os.path.isfile(output_path) else 0,
            "device": str(self.device),
            "encoder": encoder
        }


def is_glide_gpu_available() -> bool:
    return _HAS_TORCH and (_DEFAULT_DEVICE is not None)
