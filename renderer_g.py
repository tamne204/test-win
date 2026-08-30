"""
renderer_g.py
Renderer G: Production-Hardened Glide-Style GPU Subpixel Camera Engine.
Inspired by Loomos-hub/glide-ffmpeg.
Continuous floating-point affine transform + GPU torch.grid_sample + direct raw RGB streaming to FFmpeg.
Zero integer quantization on camera coordinates with automatic fallback to Renderer D (Golden Baseline).
"""
from __future__ import annotations

import os
import sys
import time
import subprocess
import logging
import threading
from typing import List, Tuple, Dict, Any, Optional, Union, Callable, Set
from PIL import Image
import numpy as np

logger = logging.getLogger("RendererG")

# Runtime Detection
_HAS_TORCH = False
_GPU_BACKEND = "none"
_DEFAULT_DEVICE = None

try:
    import torch
    import torch.nn.functional as F
    _HAS_TORCH = True
    if torch.backends.mps.is_available():
        _GPU_BACKEND = "apple_mps"
        _DEFAULT_DEVICE = torch.device("mps")
    elif torch.cuda.is_available():
        _GPU_BACKEND = "nvidia_cuda"
        _DEFAULT_DEVICE = torch.device("cuda")
    else:
        _GPU_BACKEND = "cpu_torch"
        _DEFAULT_DEVICE = torch.device("cpu")
except Exception as e:
    _HAS_TORCH = False
    _GPU_BACKEND = "unavailable"
    _DEFAULT_DEVICE = None


def check_gpu_runtime_capabilities() -> Dict[str, Any]:
    """
    Perform a complete runtime validation of PyTorch and GPU grid_sample support.
    Verifies that the hardware can execute subpixel tensor transformations.
    """
    if not _HAS_TORCH or _DEFAULT_DEVICE is None:
        return {
            "available": False,
            "backend": "none",
            "device": "none",
            "grid_sample_supported": False,
            "reason": "PyTorch is not installed or import failed."
        }

    try:
        # Diagnostic test: allocate small dummy tensor and run grid_sample
        dummy_img = torch.zeros((1, 3, 32, 32), device=_DEFAULT_DEVICE, dtype=torch.float32)
        dummy_grid = torch.zeros((1, 32, 32, 2), device=_DEFAULT_DEVICE, dtype=torch.float32)
        res = F.grid_sample(dummy_img, dummy_grid, mode="bilinear", padding_mode="reflection", align_corners=False)
        if _DEFAULT_DEVICE.type == "mps":
            torch.mps.synchronize()
        elif _DEFAULT_DEVICE.type == "cuda":
            torch.cuda.synchronize()
        
        return {
            "available": True,
            "backend": _GPU_BACKEND,
            "device": str(_DEFAULT_DEVICE),
            "grid_sample_supported": True,
            "torch_version": torch.__version__
        }
    except Exception as e:
        logger.warning(f"Renderer G GPU capability check failed on {_DEFAULT_DEVICE}: {e}")
        return {
            "available": False,
            "backend": _GPU_BACKEND,
            "device": str(_DEFAULT_DEVICE),
            "grid_sample_supported": False,
            "reason": str(e)
        }


def is_glide_gpu_available() -> bool:
    """Return True if Renderer G is ready for production on current hardware."""
    caps = check_gpu_runtime_capabilities()
    return caps.get("available", False) and caps.get("grid_sample_supported", False)


class GlideGPUEngine:
    """
    Enterprise-grade GPU Subpixel Pan & Zoom Engine.
    Streams transformed float32 textures via standard I/O pipe directly into FFmpeg.
    """
    def __init__(self, device: Optional[Any] = None):
        self.device = device or _DEFAULT_DEVICE
        self._cached_grid: Dict[Tuple[int, int, str], Any] = {}

    def get_base_grid(self, width: int, height: int) -> "torch.Tensor":
        """
        Get or create cached normalized meshgrid [-1.0, 1.0] on GPU.
        Reused across frames to eliminate grid allocation overhead.
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
                               source_scale_mode: str = "2x",
                               batch_size: int = 8,
                               encoder: str = "libx264",
                               crf: int = 18,
                               bitrate: str = "12M",
                               sampling_mode: str = "bilinear",
                               cancel_event: Optional[threading.Event] = None) -> Dict[str, Any]:
        """
        Render a Ken Burns slide directly to video with subpixel precision and robust process lifecycle.
        """
        if not _HAS_TORCH or self.device is None:
            raise RuntimeError("PyTorch GPU runtime unavailable for Renderer G.")

        t_start = time.perf_counter()
        total_frames = int(round(duration * fps))
        if total_frames < 1:
            total_frames = 1

        # 1. Load source image as float32 RGB tensor
        if not os.path.isfile(image_path):
            raise FileNotFoundError(f"Input image not found: {image_path}")

        img_pil = Image.open(image_path).convert("RGB")
        src_w, src_h = img_pil.size

        # Pre-scale strategy (G1: native, G2: 2x, G3: 4x)
        if source_scale_mode == "2x":
            target_src_w, target_src_h = width * 2, height * 2
            if src_w < target_src_w or src_h < target_src_h:
                img_pil = img_pil.resize((target_src_w, target_src_h), Image.Resampling.BICUBIC)
        elif source_scale_mode == "4x":
            target_src_w, target_src_h = width * 4, height * 4
            img_pil = img_pil.resize((target_src_w, target_src_h), Image.Resampling.BICUBIC)

        img_np = np.array(img_pil, dtype=np.float32) / 255.0  # (H, W, 3)
        img_tensor = torch.from_numpy(img_np).permute(2, 0, 1).unsqueeze(0).to(self.device)  # (1, 3, H, W)
        logger.info(
            f"🚀 [Renderer G] Device: {self.device} | Source: {src_w}x{src_h} ({source_scale_mode}) | "
            f"Output: {width}x{height} @ {fps}fps | Effect: {effect} (mag={magnitude}) | "
            f"Batch: {batch_size} | Encoder: {encoder}"
        )

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

        try:
            NF = max(1, total_frames - 1)
            mag = float(magnitude)

            frame_idx = 0
            while frame_idx < total_frames:
                if cancel_event and cancel_event.is_set():
                    logger.info("Renderer G task cancelled by user request.")
                    proc.kill()
                    raise InterruptedError("Render process cancelled.")

                cur_batch_size = min(batch_size, total_frames - frame_idx)
                
                # Compute camera parameters for batch (strictly continuous float)
                grids_in_batch = []
                for b in range(cur_batch_size):
                    f_num = frame_idx + b
                    t = float(f_num) / NF  # Normalized progress in [0.0, 1.0]

                    # Exact Linear Trajectory
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

        except Exception as e:
            if proc.poll() is None:
                proc.kill()
            raise e
        finally:
            if proc.poll() is None:
                proc.kill()

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


def render_camera_clip_with_fallback(
    image_path: str,
    output_path: str,
    duration: float,
    fps: int = 60,
    width: int = 1920,
    height: int = 1080,
    effect: str = "zoom_in",
    magnitude: float = 0.20,
    camera_renderer: str = "auto",
    encoder: str = "libx264"
) -> Dict[str, Any]:
    """
    Production Entry Point with Safe Fallback Router:
    - 'auto': Uses Renderer G if GPU/PyTorch is available; seamlessly falls back to Renderer D on any error.
    - 'g': Forces Renderer G.
    - 'legacy' / 'baseline': Uses Renderer D (4X Canvas + FFmpeg Zoompan).
    """
    if camera_renderer == "g" or (camera_renderer == "auto" and is_glide_gpu_available()):
        try:
            engine = GlideGPUEngine()
            return engine.render_segment_to_pipe(
                image_path=image_path,
                output_path=output_path,
                duration=duration,
                fps=fps,
                width=width,
                height=height,
                effect=effect,
                magnitude=magnitude,
                source_scale_mode="2x",
                encoder=encoder
            )
        except Exception as err:
            logger.warning(f"Renderer G encountered runtime error: {err}. Falling back to Golden Baseline (Renderer D).")

    # Fallback to Renderer D (Golden Baseline)
    total_frames = int(round(duration * fps))
    NF = max(1, total_frames - 1)
    mag = float(magnitude)
    t_str = f"(on/{NF})"

    if effect == "zoom_in":
        z_expr = f"1.0+{mag:.5f}*{t_str}"
    elif effect == "zoom_out":
        z_expr = f"1.0+{mag:.5f}*(1.0-{t_str})"
    elif effect == "pan_lr":
        z_expr = f"{1.0 + mag:.5f}"
    else:
        z_expr = f"1.0+{mag:.5f}*{t_str}"

    x_expr = "(iw-iw/zoom)/2"
    y_expr = "(ih-ih/zoom)/2"

    scale_w, scale_h = width * 4, height * 4
    vf = (
        f"scale={scale_w}:{scale_h}:force_original_aspect_ratio=increase,"
        f"crop={scale_w}:{scale_h},"
        f"zoompan=z='{z_expr}':x='{x_expr}':y='{y_expr}':d={total_frames}:s={width}x{height}:fps={fps},"
        f"format=yuv420p,setsar=1"
    )

    cmd = [
        "ffmpeg", "-y", "-loglevel", "error",
        "-loop", "1", "-i", image_path,
        "-vf", vf,
        "-c:v", encoder, "-preset", "veryfast", "-crf", "18", "-pix_fmt", "yuv420p",
        "-t", str(duration),
        output_path
    ]

    t0 = time.perf_counter()
    subprocess.run(cmd, check=True)
    t_render = time.perf_counter() - t0

    return {
        "output_path": output_path,
        "total_frames": total_frames,
        "render_time_s": round(t_render, 3),
        "fps_achieved": round(total_frames / (t_render + 1e-6), 1),
        "fallback_used": True,
        "renderer": "Renderer_D_GoldenBaseline"
    }