"""
subpixel_affine_engine.py
=========================
High-Precision Subpixel Affine Motion Rendering Engine for Slideshow Studio.

Architectural highlights:
1. True floating-point affine transforms (cv2.warpAffine with cv2.INTER_LANCZOS4 / cv2.INTER_CUBIC).
2. Direct-to-output resolution rendering without integer crop stepping or oversized intermediate canvas.
3. Pure zero-center-drift geometric anchoring in single-pass transform matrix.
4. Streamed rawvideo piping directly to FFmpeg stdin (O(1) memory footprint).
5. Pluggable trajectory easing (linear, smoothstep).
6. Full compatibility with existing audio, TTS, subtitles, and hardware encoders.
"""

from __future__ import annotations
import os
import sys
import math
import time
import subprocess
import numpy as np
from pathlib import Path
from typing import List, Dict, Any, Optional, Tuple, Callable

try:
    import cv2
    OPENCV_AVAILABLE = True
except ImportError:
    cv2 = None
    OPENCV_AVAILABLE = False

try:
    import psutil
    PSUTIL_AVAILABLE = True
except ImportError:
    psutil = None
    PSUTIL_AVAILABLE = False


class SubpixelAffineEngine:
    """
    Subpixel Floating-Point Affine Motion Renderer.
    Renders butter-smooth camera zooms without zoompan quantization.
    """

    @staticmethod
    def is_available() -> bool:
        """Return True if OpenCV is available."""
        return OPENCV_AVAILABLE and cv2 is not None

    @staticmethod
    def easing_linear(progress: float) -> float:
        """Standard linear progression."""
        return float(progress)

    @staticmethod
    def easing_smoothstep(progress: float) -> float:
        """Hermite smoothstep easing: S(t) = t*t*(3 - 2*t)."""
        t = max(0.0, min(1.0, float(progress)))
        return float(t * t * (3.0 - 2.0 * t))

    @classmethod
    def compute_affine_matrix(
        cls,
        w_in: int,
        h_in: int,
        w_out: int,
        h_out: int,
        effect: str = "zoom_in",
        progress: float = 0.0,
        amplitude: float = 0.20,
        easing: str = "linear"
    ) -> Tuple[np.ndarray, Dict[str, float]]:
        """
        Compute the 2x3 floating-point affine transformation matrix mapping
        source image coordinates directly to destination output frame coordinates.
        Guarantees exact center visual anchoring with ZERO center drift.
        """
        p = max(0.0, min(1.0, float(progress)))
        t = cls.easing_smoothstep(p) if easing == "smoothstep" else cls.easing_linear(p)
        amp = float(amplitude)

        # 1. Zoom scale calculation (pure float)
        eff = str(effect).lower()
        if eff == "zoom_in":
            zoom_scale = 1.0 + amp * t
        elif eff == "zoom_out":
            zoom_scale = 1.0 + amp * (1.0 - t)
        elif eff in ("none", "static"):
            zoom_scale = 1.0
        else:
            zoom_scale = 1.0

        # 2. Aspect Cover base scaling
        # Ensures image completely fills the output frame without black bars,
        # preserving the exact framing behavior of the existing product.
        s_base = max(float(w_out) / float(w_in), float(h_out) / float(h_in))
        total_scale = s_base * zoom_scale

        # 3. Source and Destination Floating-Point Centers
        cin_x = float(w_in) / 2.0
        cin_y = float(h_in) / 2.0
        cout_x = float(w_out) / 2.0
        cout_y = float(h_out) / 2.0

        # 4. Translation offsets anchoring source center to destination center
        # Mapping: dst = S * src + T => cout = S * cin + T => T = cout - S * cin
        tx = cout_x - total_scale * cin_x
        ty = cout_y - total_scale * cin_y

        matrix = np.array([
            [total_scale, 0.0, tx],
            [0.0, total_scale, ty]
        ], dtype=np.float32)

        meta = {
            "progress": p,
            "t": t,
            "zoom_scale": zoom_scale,
            "total_scale": total_scale,
            "tx": tx,
            "ty": ty,
            "cout_x": cout_x,
            "cout_y": cout_y
        }

        return matrix, meta

    @staticmethod
    def render_frame(
        img_bgr: np.ndarray,
        matrix: np.ndarray,
        w_out: int,
        h_out: int,
        resample_mode: str = "LANCZOS4"
    ) -> np.ndarray:
        """
        Warp source image using subpixel affine transformation directly to destination size.
        """
        if not OPENCV_AVAILABLE:
            raise RuntimeError("OpenCV (cv2) is required for SubpixelAffineEngine.")

        mode_str = str(resample_mode).upper()
        if mode_str == "CUBIC":
            flag = cv2.INTER_CUBIC
        elif mode_str == "LINEAR":
            flag = cv2.INTER_LINEAR
        else:
            flag = cv2.INTER_LANCZOS4

        return cv2.warpAffine(
            img_bgr,
            matrix,
            (int(w_out), int(h_out)),
            flags=flag,
            borderMode=cv2.BORDER_REFLECT_101
        )

    @classmethod
    def render_video(
        cls,
        image_paths: List[str],
        audio_path: Optional[str],
        output_path: str,
        settings: Dict[str, Any],
        progress_callback: Callable[[int, str], None],
        subtitle_path: Optional[str] = None
    ) -> None:
        """
        Execute end-to-end video render streaming raw subpixel affine frames
        directly into FFmpeg stdin.
        """
        if not cls.is_available():
            raise RuntimeError("SubpixelAffineEngine cannot run: OpenCV (cv2) is not installed.")

        import ffmpeg_utils
        images = ffmpeg_utils.sort_images(image_paths)
        if not images:
            raise ValueError("No images provided to render_video")

        fps = max(30, int(settings.get('fps', 60)))
        aspect = str(settings.get('aspect_ratio', '16:9'))
        res = str(settings.get('resolution', '1080p'))
        W, H = ffmpeg_utils.build_resolution(aspect, res)
        duration_default = float(settings.get('duration_per_image', 5.0))
        zoom_mag = float(settings.get('zoom_magnitude', 0.20))
        use_trans = bool(settings.get('use_transition', True))
        td = float(settings.get('transition_duration', 1.0))
        easing = str(settings.get('easing', 'linear')).lower()
        resample_mode = str(settings.get('resample_mode', 'LANCZOS4')).upper()

        # Assigned effects
        effects = settings.get('effects') or settings.get('image_effects')
        if not effects or len(effects) != len(images):
            weights = {
                'zoom_in': float(settings.get('weight_zoom_in', 25)),
                'zoom_out': float(settings.get('weight_zoom_out', 25)),
                'pan': float(settings.get('weight_pan', 25)),
                'tilt': float(settings.get('weight_tilt', 25)),
            }
            effects = ffmpeg_utils.assign_effects(len(images), weights)

        # Durations per image
        image_durations = settings.get('image_durations')
        has_audio = bool(audio_path and os.path.isfile(audio_path))
        if has_audio and (not image_durations or len(image_durations) != len(images)):
            try:
                from subtitles_engine import get_audio_duration
                a_dur = get_audio_duration(audio_path)
                if a_dur > 0:
                    dur_per = max(1.0, a_dur / len(images))
                    image_durations = [dur_per] * len(images)
            except Exception:
                pass

        # Deterministic global frame counting (eliminates frame drift)
        total_video_duration = 0.0
        accum_time = 0.0
        accum_frames = 0
        image_frame_counts: List[int] = []

        for i in range(len(images)):
            dur_i = max(1.0, float(image_durations[i])) if (image_durations and i < len(image_durations)) else max(1.0, duration_default)
            total_video_duration += dur_i
            accum_time += dur_i
            target_total = max(1, int(round(accum_time * fps)))
            frames_i = max(1, target_total - accum_frames)
            image_frame_counts.append(frames_i)
            accum_frames += frames_i

        total_frames = accum_frames

        # Prepare Subtitle Overlay (if any)
        has_subs = bool(subtitle_path and os.path.isfile(subtitle_path))
        concat_txt_path = None
        if has_subs:
            try:
                is_vert = (H > W)
                sub_font = str(settings.get('subtitle_font', settings.get('sub_font', 'paperlogy')))
                sub_size = int(settings.get('subtitle_font_size', settings.get('sub_size', 36)))
                sub_color = str(settings.get('subtitle_color', settings.get('sub_color', '#FFFFFF')))
                sub_stroke_enabled = str(settings.get('sub_stroke_enabled', 'true')).lower() in ('true', '1', 'yes')
                sub_stroke_color = str(settings.get('sub_stroke_color', '#000000'))
                sub_stroke_width = int(settings.get('sub_stroke_width', 4))
                sub_bg_enabled = str(settings.get('sub_bg_enabled', 'false')).lower() in ('true', '1', 'yes')
                sub_bg_color = str(settings.get('sub_bg_color', '#000000'))
                sub_bg_opacity = int(settings.get('sub_bg_opacity', 75))
                sub_bg_radius = int(settings.get('sub_bg_radius', 12))
                sub_pos_y = float(settings.get('sub_pos_y', 14.0 if is_vert else 6.5))
                sub_pos_x = float(settings.get('sub_pos_x', 0.0))
                sub_letter_spacing = float(settings.get('sub_letter_spacing', 0.0))
                sub_line_spacing = float(settings.get('sub_line_spacing', 1.25))
                sub_align = str(settings.get('sub_align', 'center'))

                concat_txt_path = ffmpeg_utils.generate_subtitle_overlay_concat(
                    subtitle_path, W, H,
                    is_vertical=is_vert,
                    sub_font=sub_font,
                    sub_size=sub_size,
                    sub_color=sub_color,
                    sub_stroke_enabled=sub_stroke_enabled,
                    sub_stroke_color=sub_stroke_color,
                    sub_stroke_width=sub_stroke_width,
                    sub_bg_enabled=sub_bg_enabled,
                    sub_bg_color=sub_bg_color,
                    sub_bg_opacity=sub_bg_opacity,
                    sub_bg_radius=sub_bg_radius,
                    sub_pos_y=sub_pos_y,
                    sub_pos_x=sub_pos_x,
                    sub_letter_spacing=sub_letter_spacing,
                    sub_line_spacing=sub_line_spacing,
                    sub_align=sub_align
                )
            except Exception as e:
                print(f"⚠️ [SubpixelAffineEngine] Subtitle generation failed: {e}")

        # Assemble FFmpeg process reading from pipe:0
        ffmpeg_bin = ffmpeg_utils.get_ffmpeg_bin()
        cmd = [
            ffmpeg_bin, '-y',
            '-f', 'rawvideo',
            '-pix_fmt', 'bgr24',
            '-s', f'{W}x{H}',
            '-r', str(fps),
            '-i', 'pipe:0',
        ]

        input_count = 1
        audio_idx = None
        if has_audio:
            cmd += ['-i', audio_path]
            audio_idx = input_count
            input_count += 1

        sub_idx = None
        if concat_txt_path and os.path.isfile(concat_txt_path):
            cmd += ['-f', 'concat', '-safe', '0', '-i', concat_txt_path]
            sub_idx = input_count
            input_count += 1

        # Stream mapping & subtitle overlay filter
        if sub_idx is not None:
            cmd += [
                '-filter_complex',
                f'[0:v][{sub_idx}:v]overlay=0:0:eof_action=pass[vout]',
                '-map', '[vout]'
            ]
        else:
            cmd += ['-map', '0:v']

        if audio_idx is not None:
            cmd += ['-map', f'{audio_idx}:a', '-c:a', 'aac', '-b:a', '128k', '-shortest']

        # Hardware Encoder Selection
        hw_info = ffmpeg_utils.detect_best_hw_encoder(ffmpeg_bin)
        user_codec = str(settings.get('codec', 'auto')).lower()
        crf = int(settings.get('crf', 23))

        if user_codec in ('nvenc', 'nvidia', 'h264_nvenc'):
            cmd += ['-c:v', 'h264_nvenc', '-preset', 'fast', '-cq', '23', '-pix_fmt', 'yuv420p', '-threads', '0']
        elif user_codec in ('qsv', 'intel', 'h264_qsv'):
            cmd += ['-c:v', 'h264_qsv', '-preset', 'veryfast', '-global_quality', '23', '-pix_fmt', 'yuv420p', '-threads', '0']
        elif user_codec in ('amf', 'amd', 'h264_amf'):
            cmd += ['-c:v', 'h264_amf', '-quality', 'speed', '-pix_fmt', 'yuv420p', '-threads', '0']
        elif user_codec in ('hevc', 'h265', 'libx265'):
            cmd += ['-c:v', 'libx265', '-preset', 'veryfast', '-crf', str(crf), '-pix_fmt', 'yuv420p', '-tag:v', 'hvc1', '-threads', '0']
        elif user_codec == 'h264_cpu':
            cmd += ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', str(crf), '-pix_fmt', 'yuv420p', '-threads', '0']
        else:
            cmd += ['-c:v', hw_info['codec'], *hw_info['extra_args'], '-pix_fmt', 'yuv420p', '-threads', '0']

        cmd += ['-r', str(fps), output_path]

        # Launch FFmpeg pipe
        _kwargs: Dict[str, Any] = {
            'stdin': subprocess.PIPE,
            'stdout': subprocess.DEVNULL,
            'stderr': subprocess.PIPE,
            'bufsize': 10 * 1024 * 1024
        }
        if sys.platform == 'win32':
            _kwargs['creationflags'] = 0x08000000  # CREATE_NO_WINDOW

        proc = subprocess.Popen(cmd, **_kwargs)

        t_start = time.perf_counter()
        frames_streamed = 0
        peak_ram_bytes = 0

        progress_callback(5, "Khởi động động cơ Subpixel Affine Engine...")

        try:
            for img_idx, img_path in enumerate(images):
                # Load source image into BGR
                src_bgr = cv2.imread(img_path, cv2.IMREAD_COLOR)
                if src_bgr is None:
                    # Fallback via PIL
                    from PIL import Image
                    with Image.open(img_path) as pim:
                        src_rgb = np.array(pim.convert('RGB'))
                        src_bgr = cv2.cvtColor(src_rgb, cv2.COLOR_RGB2BGR)

                h_in, w_in = src_bgr.shape[:2]
                n_frames_slide = image_frame_counts[img_idx]
                effect = effects[img_idx] if img_idx < len(effects) else 'zoom_in'
                fade_frames = int(round(td * fps)) if (use_trans and img_idx > 0) else 0

                for frame_idx in range(n_frames_slide):
                    progress = float(frame_idx) / max(1.0, float(n_frames_slide - 1))
                    matrix, _ = cls.compute_affine_matrix(
                        w_in=w_in,
                        h_in=h_in,
                        w_out=W,
                        h_out=H,
                        effect=effect,
                        progress=progress,
                        amplitude=zoom_mag,
                        easing=easing
                    )

                    frame = cls.render_frame(src_bgr, matrix, W, H, resample_mode=resample_mode)

                    # Fade-in from black transition (if enabled for slide > 0)
                    if fade_frames > 0 and frame_idx < fade_frames:
                        alpha = float(frame_idx) / float(fade_frames)
                        frame = cv2.convertScaleAbs(frame, alpha=alpha)

                    try:
                        proc.stdin.write(frame.tobytes())
                    except (BrokenPipeError, IOError):
                        break

                    frames_streamed += 1

                    if frames_streamed % 30 == 0:
                        pct = min(95, int((frames_streamed / max(1, total_frames)) * 90) + 5)
                        progress_callback(pct, f"Render Subpixel Frame {frames_streamed}/{total_frames} ({frames_streamed/max(0.001, time.perf_counter()-t_start):.1f} FPS)")

                if PSUTIL_AVAILABLE:
                    try:
                        rss = psutil.Process().memory_info().rss
                        if rss > peak_ram_bytes:
                            peak_ram_bytes = rss
                    except Exception:
                        pass

            # Close pipe
            proc.stdin.close()
            stderr_bytes = proc.stderr.read()
            proc.wait()

            if proc.returncode != 0:
                err_text = stderr_bytes.decode('utf-8', errors='replace') if stderr_bytes else 'Unknown error'
                raise RuntimeError(f"FFmpeg encoding failed with code {proc.returncode}:\n{err_text[-800:]}")

            total_elapsed = time.perf_counter() - t_start
            fps_speed = frames_streamed / max(0.001, total_elapsed)
            print(f"✅ [SubpixelAffineEngine] Completed {frames_streamed} frames in {total_elapsed:.2f}s ({fps_speed:.1f} FPS, Peak RAM: {peak_ram_bytes/(1024*1024):.1f} MB)")
            progress_callback(100, "Done!")

        except Exception:
            if proc.poll() is None:
                proc.kill()
            raise


# Quick verification
if __name__ == "__main__":
    print("SubpixelAffineEngine available:", SubpixelAffineEngine.is_available())
