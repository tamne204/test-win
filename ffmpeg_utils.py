"""
ffmpeg_utils.py
Core FFmpeg logic for the Slideshow Builder.
Handles image sorting, Ken Burns effect generation, and rendering.
"""

import os
import sys
import re
import random
import subprocess
from typing import List, Dict, Any, Callable, Optional
from PIL import Image, ImageDraw, ImageFont

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

SUPPORTED_IMAGE_EXTENSIONS = {'.jpg', '.jpeg', '.png', '.webp', '.bmp', '.tiff', '.tif', '.gif'}
SUPPORTED_AUDIO_EXTENSIONS = {'.mp3', '.wav', '.aac', '.m4a', '.ogg', '.flac'}


# ---------------------------------------------------------------------------
# Utility helpers
# ---------------------------------------------------------------------------

def get_ffmpeg_bin() -> str:
    """Return best available ffmpeg binary, preferring ffmpeg-full with libass support."""
    candidates = [
        '/opt/homebrew/opt/ffmpeg-full/bin/ffmpeg',
        '/opt/homebrew/Cellar/ffmpeg-full/9.0.1/bin/ffmpeg',
        '/usr/local/opt/ffmpeg-full/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        'ffmpeg'
    ]
    for c in candidates:
        if os.path.isabs(c) and os.path.isfile(c) and os.access(c, os.X_OK):
            return c
    return 'ffmpeg'


def check_ffmpeg() -> bool:
    """Return True if ffmpeg is installed and reachable."""
    try:
        bin_path = get_ffmpeg_bin()
        result = subprocess.run(
            [bin_path, '-version'],
            capture_output=True, text=True, timeout=5
        )
        return result.returncode == 0
    except Exception:
        return False


def is_supported_image(filename: str) -> bool:
    return os.path.splitext(filename.lower())[1] in SUPPORTED_IMAGE_EXTENSIONS


def is_supported_audio(filename: str) -> bool:
    return os.path.splitext(filename.lower())[1] in SUPPORTED_AUDIO_EXTENSIONS


def sort_images(image_paths: List[str]) -> List[str]:
    """Sort images by trailing number in filename, then alphabetically."""
    def sort_key(path: str):
        name = os.path.splitext(os.path.basename(path))[0]
        numbers = re.findall(r'\d+', name)
        trailing = int(numbers[-1]) if numbers else 0
        return (trailing, os.path.basename(path).lower())

    return sorted(image_paths, key=sort_key)


def build_resolution(aspect: str, res_preset: str) -> tuple:
    """
    Convert aspect ratio + resolution preset to (width, height).
    Supports 16:9, 9:16, 1:1, 4:5, 21:9, 4:3 and 720p, 1080p, 1440p, 4K.
    Ensures both dimensions are even integers.
    """
    base_sizes = {'720p': 720, '1080p': 1080, '1440p': 1440, '2K': 1440, '4K': 2160}
    dim = base_sizes.get(res_preset, 1080)

    aspect_map = {
        '16:9': (16, 9),
        '9:16': (9, 16),
        '1:1':  (1, 1),
        '4:5':  (4, 5),
        '21:9': (21, 9),
        '4:3':  (4, 3),
    }

    if aspect in aspect_map:
        aw, ah = aspect_map[aspect]
    else:
        try:
            aw, ah = map(int, aspect.split(':'))
        except Exception:
            aw, ah = 16, 9

    if aw >= ah:
        # Landscape / Square: dim is Height
        H = dim
        W = int(H * aw / ah)
    else:
        # Portrait (9:16, 4:5): dim is Width
        W = dim
        H = int(W * ah / aw)

    # Ensure even dimensions (required for yuv420p)
    W += W % 2
    H += H % 2
    return W, H


def normalize_weights(weights: Dict[str, float]) -> Dict[str, float]:
    """
    Auto-normalize effect weights so they sum to 1.0.
    If all are zero, fall back to equal distribution.
    """
    total = sum(weights.values())
    if total <= 0:
        n = len(weights)
        return {k: 1.0 / n for k in weights}
    return {k: v / total for k, v in weights.items()}


# ---------------------------------------------------------------------------
# Ken Burns effect expressions (FFmpeg zoompan filter)
# ---------------------------------------------------------------------------

def _zoompan_params(effect: str, magnitude: float, total_frames: int) -> dict:
    """
    Return z / x / y expressions for FFmpeg's zoompan filter with jitter-free subpixel interpolation.
    """
    NF = max(total_frames, 2)
    M = max(0.01, min(float(magnitude), 1.0))
    z_max = f"{1.0 + M:.5f}"
    step = f"{M / NF:.7f}"
    t = f"(on-1)/{NF}"          # normalised time  0 → ≈1

    if effect in ('none', 'static'):
        return dict(
            z="1.0",
            x="(iw-iw/zoom)/2",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'zoom_in':
        return dict(
            z=f"if(lte(on,1),1.0,min(zoom+{step},{z_max}))",
            x="(iw-iw/zoom)/2",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'zoom_out':
        return dict(
            z=f"if(lte(on,1),{z_max},max(zoom-{step},1.0))",
            x="(iw-iw/zoom)/2",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'pan_lr':      # pan left → right
        return dict(
            z=z_max,
            x=f"{t}*(iw-iw/zoom)",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'pan_rl':      # pan right → left
        return dict(
            z=z_max,
            x=f"(1-{t})*(iw-iw/zoom)",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'tilt_ud':     # tilt top → bottom
        return dict(
            z=z_max,
            x="(iw-iw/zoom)/2",
            y=f"{t}*(ih-ih/zoom)"
        )
    elif effect == 'tilt_du':     # tilt bottom → top
        return dict(
            z=z_max,
            x="(iw-iw/zoom)/2",
            y=f"(1-{t})*(ih-ih/zoom)"
        )

    # Fallback: static
    return dict(z="1", x="0", y="0")


# ---------------------------------------------------------------------------
# Effect assignment
# ---------------------------------------------------------------------------

def assign_effects(n: int, weights: Optional[Dict[str, float]] = None) -> List[str]:
    """
    Assign distinct effects to n images, ensuring no two consecutive images
    share the same effect (pure non-repeating sequence).
    """
    pool = ['zoom_in', 'zoom_out', 'pan_lr', 'pan_rl', 'tilt_ud', 'tilt_du']
    effects = []
    last_eff = None
    for i in range(n):
        choices = [e for e in pool if e != last_eff]
        eff = random.choice(choices)
        effects.append(eff)
        last_eff = eff
    return effects


# ---------------------------------------------------------------------------
# Pillow Subtitle Overlay Generator (Exact UI Preview Styles & Custom Fonts)
# ---------------------------------------------------------------------------

def _get_subtitle_font(size: int, font_name: str = 'paperlogy'):
    static_fonts_dir = os.path.join(os.path.dirname(__file__), 'static', 'fonts')
    
    font_map = {
        'paperlogy': [
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.woff2'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'jalnan': [
            os.path.join(static_fonts_dir, 'Jalnan.ttf'),
            os.path.join(static_fonts_dir, 'Jalnan.woff'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'isamanru': [
            os.path.join(static_fonts_dir, 'GongGothicBold.ttf'),
            os.path.join(static_fonts_dir, 'GongGothicBold.woff'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'gonggothic': [
            os.path.join(static_fonts_dir, 'GongGothicBold.ttf'),
            os.path.join(static_fonts_dir, 'GongGothicBold.woff'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'fromsol': [
            os.path.join(static_fonts_dir, 'Griun_Fromsol.ttf'),
            os.path.join(static_fonts_dir, 'Griun_Fromsol.woff2'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'griun_fromsol': [
            os.path.join(static_fonts_dir, 'Griun_Fromsol.ttf'),
            os.path.join(static_fonts_dir, 'Griun_Fromsol.woff2'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'katuri': [
            os.path.join(static_fonts_dir, 'Katuri.ttf'),
            os.path.join(static_fonts_dir, 'Katuri.woff'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'montserrat': [
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf'
        ],
        'tahoma': [
            os.path.join(static_fonts_dir, 'Tahoma-Bold.ttf'),
            '/System/Library/Fonts/Supplemental/Tahoma Bold.ttf',
            '/System/Library/Fonts/Supplemental/Tahoma.ttf',
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'applesd': [
            '/System/Library/Fonts/AppleSDGothicNeo.ttc',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf'
        ],
        'arial': [
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'roboto': [
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ]
    }

    candidates = font_map.get(str(font_name).lower(), font_map['paperlogy'])
    for c in candidates:
        if os.path.isfile(c):
            try:
                return ImageFont.truetype(c, size, index=0)
            except Exception:
                pass
    return ImageFont.load_default()


def _hex_to_rgba(hex_str: str, alpha: int = 255) -> tuple:
    if not hex_str:
        return (255, 255, 255, alpha)
    hex_str = str(hex_str).strip().lstrip('#')
    try:
        if len(hex_str) == 6:
            r = int(hex_str[0:2], 16)
            g = int(hex_str[2:4], 16)
            b = int(hex_str[4:6], 16)
            return (r, g, b, alpha)
        elif len(hex_str) == 8:
            r = int(hex_str[0:2], 16)
            g = int(hex_str[2:4], 16)
            b = int(hex_str[4:6], 16)
            a = int(hex_str[6:8], 16)
            return (r, g, b, a)
    except Exception:
        pass
    return (255, 255, 255, alpha)


def generate_subtitle_overlay_concat(
    subtitle_path: str,
    W: int,
    H: int,
    is_vertical: bool = False,
    sub_font: str = 'montserrat',
    sub_size: int = 36,
    sub_color: str = '#ffffff',
    sub_stroke_enabled: bool = True,
    sub_stroke_color: str = '#000000',
    sub_stroke_width: int = 4,
    sub_bg_enabled: bool = False,
    sub_bg_color: str = '#000000',
    sub_bg_opacity: int = 75,
    sub_bg_radius: int = 12,
    sub_pos_y: Optional[float] = None,
    sub_pos_x: float = 0.0,
    sub_letter_spacing: float = 0.0,
    sub_line_spacing: float = 1.25,
    sub_align: str = 'center',
    sub_style: Optional[str] = None
) -> Optional[str]:
    """
    Generate an exact subtitle overlay stream from an SRT file with independent:
    - Text color & Font
    - Auto word-wrapping (max width bounded)
    - Letter spacing & Line spacing
    - Stroke outline (Toggle, Width, Color)
    - Background box (Toggle, Color, Opacity, Radius)
    - Position X & Y (Height %, Center offset %)
    """
    if not subtitle_path or not os.path.isfile(subtitle_path):
        return None

    try:
        with open(subtitle_path, 'r', encoding='utf-8') as f:
            srt_text = f.read()

        blocks = [b.strip() for b in re.split(r'\n\s*\n', srt_text.strip()) if b.strip()]
        cues = []
        for b in blocks:
            lines = [l.strip() for l in b.split('\n') if l.strip()]
            if len(lines) >= 2:
                t_line = None
                text_lines = []
                for idx, l in enumerate(lines):
                    if '-->' in l:
                        t_line = l
                        text_lines = lines[idx+1:]
                        break
                if t_line and text_lines:
                    m = re.search(r'(\d{2}):(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})[,.](\d{3})', t_line)
                    if m:
                        h1, m1, s1, ms1, h2, m2, s2, ms2 = map(int, m.groups())
                        st = h1 * 3600 + m1 * 60 + s1 + ms1 / 1000.0
                        et = h2 * 3600 + m2 * 60 + s2 + ms2 / 1000.0
                        txt = " ".join(text_lines)
                        if txt.strip() and et > st:
                            cues.append((st, et, txt.strip()))

        if not cues:
            return None

        # Temp directory next to subtitle file
        sub_dir = os.path.dirname(subtitle_path)
        overlay_dir = os.path.join(sub_dir, 'sub_overlay_frames')
        os.makedirs(overlay_dir, exist_ok=True)

        # Scale font size with video resolution & match visual preview scale
        scale_factor = (H / 1080.0) if not is_vertical else (H / 1920.0)
        base_size = int(sub_size * scale_factor * 1.5)
        font_size = max(20, base_size)
        font = _get_subtitle_font(font_size, sub_font)

        blank_png = os.path.join(overlay_dir, 'blank.png')
        Image.new('RGBA', (W, H), (0, 0, 0, 0)).save(blank_png)

        # Scale padding dynamically based on font size for perfect proportions
        pad_x = max(18, int(font_size * 0.45))
        pad_y = max(8, int(font_size * 0.22))

        # Position calculations
        if sub_pos_y is None:
            sub_pos_y = 14.0 if is_vertical else 6.5
        bottom_margin = int(H * (float(sub_pos_y) / 100.0))

        text_rgba = _hex_to_rgba(sub_color, 255)
        stroke_w = max(1, int(sub_stroke_width * scale_factor * 1.5)) if sub_stroke_enabled else 0
        stroke_rgba = _hex_to_rgba(sub_stroke_color, 255) if sub_stroke_enabled else None

        bg_alpha = int(255 * (float(sub_bg_opacity) / 100.0))
        bg_rgba = _hex_to_rgba(sub_bg_color, bg_alpha)
        radius = max(6, int(sub_bg_radius * scale_factor * 1.3))

        # Letter spacing & Line spacing scaling
        l_space = int(float(sub_letter_spacing) * scale_factor * 1.5)
        line_height_multiplier = max(1.0, float(sub_line_spacing)) if sub_line_spacing else 1.25
        max_content_w = int(W * 0.88)

        cue_files = []
        for idx, (st, et, raw_text) in enumerate(cues):
            img = Image.new('RGBA', (W, H), (0, 0, 0, 0))
            draw = ImageDraw.Draw(img)

            # Auto word-wrap into lines based on max_content_w
            paragraphs = raw_text.split('\n')
            lines = []
            for p in paragraphs:
                words = p.split()
                if not words:
                    continue
                curr_words = []
                for w in words:
                    test_str = " ".join(curr_words + [w])
                    # Measure test_str with l_space
                    tw = 0
                    for ch in test_str:
                        cb = draw.textbbox((0, 0), ch, font=font, stroke_width=stroke_w)
                        tw += (cb[2] - cb[0]) + l_space
                    tw -= l_space
                    if tw > max_content_w and curr_words:
                        lines.append(" ".join(curr_words))
                        curr_words = [w]
                    else:
                        curr_words.append(w)
                if curr_words:
                    lines.append(" ".join(curr_words))

            if not lines:
                lines = [raw_text]

            # Measure all lines
            line_metrics = []
            max_line_w = 0
            for l in lines:
                lw = 0
                lh = 0
                for ch in l:
                    cb = draw.textbbox((0, 0), ch, font=font, stroke_width=stroke_w)
                    lw += (cb[2] - cb[0]) + l_space
                    ch_h = cb[3] - cb[1]
                    if ch_h > lh:
                        lh = ch_h
                lw = max(0, lw - l_space)
                lh = max(lh, int(font_size * 0.9))
                line_metrics.append((l, lw, lh))
                if lw > max_line_w:
                    max_line_w = lw

            line_step = int(font_size * line_height_multiplier)
            total_text_h = (len(lines) - 1) * line_step + line_metrics[-1][2]
            box_w = max_line_w + pad_x * 2
            box_h = total_text_h + pad_y * 2

            # X offset
            cx = int(W * 0.5 + W * (float(sub_pos_x) / 100.0))
            y1 = H - bottom_margin
            y0 = y1 - box_h
            x0 = cx - box_w // 2
            x1 = cx + box_w // 2

            # 1. Background Box (if enabled)
            if sub_bg_enabled:
                draw.rounded_rectangle([x0, y0, x1, y1], radius=radius, fill=bg_rgba)

            # 2. Text rendering line by line
            curr_y = y0 + pad_y
            for l_str, lw, lh in line_metrics:
                start_x = cx - lw // 2
                cur_x = start_x
                for ch in l_str:
                    cb = draw.textbbox((0, 0), ch, font=font, stroke_width=stroke_w)
                    cw = cb[2] - cb[0]
                    if sub_stroke_enabled and stroke_w > 0:
                        draw.text(
                            (cur_x - cb[0], curr_y - cb[1]),
                            ch,
                            font=font,
                            fill=text_rgba,
                            stroke_width=stroke_w,
                            stroke_fill=stroke_rgba
                        )
                    else:
                        draw.text(
                            (cur_x - cb[0], curr_y - cb[1]),
                            ch,
                            font=font,
                            fill=text_rgba
                        )
                    cur_x += cw + l_space
                curr_y += line_step

            fpath = os.path.join(overlay_dir, f'cue_{idx}.png')
            img.save(fpath)
            cue_files.append((st, et, fpath))

        abs_blank = os.path.abspath(blank_png).replace('\\', '/')
        concat_txt = os.path.join(overlay_dir, 'sub_concat.txt')
        current_time = 0.0
        concat_lines = ['ffconcat version 1.0']

        for st, et, fpath in cue_files:
            abs_fpath = os.path.abspath(fpath).replace('\\', '/')
            if st > current_time:
                gap = st - current_time
                concat_lines.append(f"file '{abs_blank}'")
                concat_lines.append(f"duration {gap:.3f}")
            dur = et - st
            concat_lines.append(f"file '{abs_fpath}'")
            concat_lines.append(f"duration {dur:.3f}")
            current_time = et

        concat_lines.append(f"file '{abs_blank}'")
        concat_lines.append("duration 10.0")
        concat_lines.append(f"file '{abs_blank}'")

        with open(concat_txt, 'w', encoding='utf-8') as f:
            f.write('\n'.join(concat_lines) + '\n')

        return os.path.abspath(concat_txt)
    except Exception as e:
        print(f"⚠️ Error generating subtitle overlay: {e}")
        return None


# ---------------------------------------------------------------------------
# FFmpeg command builder
# ---------------------------------------------------------------------------

def build_command(
    image_paths: List[str],
    audio_path: Optional[str],
    output_path: str,
    settings: Dict[str, Any],
    subtitle_path: Optional[str] = None
) -> tuple:
    """
    Build the full FFmpeg command list.
    Returns (cmd: List[str], total_video_duration: float).
    """

    # --- Parse settings ---
    fps          = int(settings.get('fps', 25))
    duration     = float(settings.get('duration_per_image', 5.0))
    aspect       = settings.get('aspect_ratio', '16:9')
    res          = settings.get('resolution', '1080p')
    zoom_mag     = float(settings.get('zoom_magnitude', 0.2))
    pan_mag      = float(settings.get('pan_magnitude', 0.2))
    tilt_mag     = float(settings.get('tilt_magnitude', 0.2))
    use_trans    = bool(settings.get('use_transition', True))
    td           = float(settings.get('transition_duration', 0.5))
    crf          = int(settings.get('crf', 23))
    preset       = settings.get('preset', 'medium')
    sub_lang     = str(settings.get('subtitle_language', 'vi'))
    weights      = {
        'zoom_in':  float(settings.get('weight_zoom_in', 25)),
        'zoom_out': float(settings.get('weight_zoom_out', 25)),
        'pan':      float(settings.get('weight_pan', 25)),
        'tilt':     float(settings.get('weight_tilt', 25)),
    }

    W, H = build_resolution(aspect, res)
    n = len(image_paths)

    # Dynamic duration per image support
    image_durations = settings.get('image_durations')
    if not image_durations and subtitle_path and os.path.isfile(subtitle_path):
        try:
            with open(subtitle_path, 'r', encoding='utf-8') as f_sub:
                srt_txt = f_sub.read()
            matches = re.findall(r'(\d{2}):(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})[,.](\d{3})', srt_txt)
            if len(matches) == n:
                image_durations = []
                for m in matches:
                    st_sec = int(m[0])*3600 + int(m[1])*60 + int(m[2]) + int(m[3])/1000.0
                    et_sec = int(m[4])*3600 + int(m[5])*60 + int(m[7])/1000.0
                    image_durations.append(max(1.0, round(et_sec - st_sec, 3)))
        except Exception:
            pass

    # Per-image specific effects or non-repeating random assignment
    custom_effects = settings.get('image_effects')
    if custom_effects and isinstance(custom_effects, list) and len(custom_effects) == n:
        effects = custom_effects
    else:
        effects = assign_effects(n, weights)

    # --- Build command ---
    ffmpeg_bin = get_ffmpeg_bin()
    cmd = [ffmpeg_bin, '-y']

    # Inputs: single image per input, zoompan will expand to `total_frames_i`
    for img in image_paths:
        cmd += ['-i', img]

    has_audio = audio_path is not None
    if has_audio:
        cmd += ['-i', audio_path]
    audio_idx = n  # index of audio input stream

    # Generate exact subtitle overlay (Pillow with chosen font, stroke, background, and X/Y/Z positions)
    has_sub = subtitle_path is not None and os.path.isfile(subtitle_path)
    concat_txt_path = None
    if has_sub:
        is_vertical = (aspect == '9:16' or aspect == '4:5')
        sub_font = str(settings.get('sub_font', 'montserrat'))
        sub_size = int(settings.get('sub_size', 36))
        sub_color = str(settings.get('sub_color', '#ffffff'))
        sub_stroke_enabled = str(settings.get('sub_stroke_enabled', 'true')).lower() in ('true', '1', 'yes')
        sub_stroke_color = str(settings.get('sub_stroke_color', '#000000'))
        sub_stroke_width = int(settings.get('sub_stroke_width', 4))
        sub_bg_enabled = str(settings.get('sub_bg_enabled', 'false')).lower() in ('true', '1', 'yes')
        sub_bg_color = str(settings.get('sub_bg_color', '#000000'))
        sub_bg_opacity = int(settings.get('sub_bg_opacity', 75))
        sub_bg_radius = int(settings.get('sub_bg_radius', 12))
        sub_pos_y = float(settings.get('sub_pos_y', 14.0 if is_vertical else 6.5))
        sub_pos_x = float(settings.get('sub_pos_x', 0.0))
        sub_letter_spacing = float(settings.get('sub_letter_spacing', 0.0))
        sub_line_spacing = float(settings.get('sub_line_spacing', 1.25))
        sub_align = str(settings.get('sub_align', 'center'))

        concat_txt_path = generate_subtitle_overlay_concat(
            subtitle_path, W, H,
            is_vertical=is_vertical,
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

    sub_input_idx = None
    if concat_txt_path:
        cmd += ['-f', 'concat', '-safe', '0', '-i', concat_txt_path]
        sub_input_idx = len(image_paths) + (1 if has_audio else 0)

    # --- filter_complex ---
    filter_parts: List[str] = []

    mag_for_effect = {
        'zoom_in':  zoom_mag,
        'zoom_out': zoom_mag,
        'pan_lr':   pan_mag,
        'pan_rl':   pan_mag,
        'tilt_ud':  tilt_mag,
        'tilt_du':  tilt_mag,
    }

    total_video_duration = 0.0

    for i, effect in enumerate(effects):
        # Determine duration for image i
        if image_durations and i < len(image_durations):
            dur_i = max(1.0, float(image_durations[i]))
        else:
            dur_i = max(1.0, duration)

        total_video_duration += dur_i
        total_frames_i = max(1, int(fps * dur_i))
        td_i = min(td, dur_i / 2.0) if use_trans and n > 1 else 0.0

        mag = mag_for_effect.get(effect, zoom_mag)
        max_w = int(round(W * (1.0 + mag)))
        max_h = int(round(H * (1.0 + mag)))

        # Professional Memory-Safe Motion Engine with Cinematic Sine Easing
        if effect == 'zoom_in':
            z_f = f"zoompan=z='1.0+{mag:.5f}*(1-cos(PI*on/{total_frames_i}))/2':x='(iw-iw/zoom)/2':y='(ih-ih/zoom)/2':d={total_frames_i}:s={W}x{H}:fps={fps}"
            base_chain = f"scale={max_w}:{max_h}:force_original_aspect_ratio=increase,crop={max_w}:{max_h},format=yuv420p,setsar=1,{z_f},setsar=1,fps={fps}"
            parts = [f"[{i}:v]", base_chain]
        elif effect == 'zoom_out':
            z_f = f"zoompan=z='{1.0+mag:.5f}-{mag:.5f}*(1-cos(PI*on/{total_frames_i}))/2':x='(iw-iw/zoom)/2':y='(ih-ih/zoom)/2':d={total_frames_i}:s={W}x{H}:fps={fps}"
            base_chain = f"scale={max_w}:{max_h}:force_original_aspect_ratio=increase,crop={max_w}:{max_h},format=yuv420p,setsar=1,{z_f},setsar=1,fps={fps}"
            parts = [f"[{i}:v]", base_chain]
        else:
            prog = fr"(1-cos(PI*min(1\,n/{total_frames_i})))/2"
            if effect == 'pan_lr':
                crop_f = f"crop={W}:{H}:'(in_w-{W})*{prog}':(in_h-{H})/2"
            elif effect == 'pan_rl':
                crop_f = f"crop={W}:{H}:'(in_w-{W})*(1-{prog})':(in_h-{H})/2"
            elif effect == 'tilt_ud':
                crop_f = f"crop={W}:{H}:(in_w-{W})/2:'(in_h-{H})*{prog}'"
            elif effect == 'tilt_du':
                crop_f = f"crop={W}:{H}:(in_w-{W})/2:'(in_h-{H})*(1-{prog})'"
            else: # static / none
                crop_f = f"crop={W}:{H}:(in_w-{W})/2:(in_h-{H})/2"

            base_chain = (
                f"scale={max_w}:{max_h}:force_original_aspect_ratio=increase,"
                f"crop={max_w}:{max_h},format=yuv420p,setsar=1,"
                f"loop=loop={total_frames_i}:size=1:start=0,setpts=N/({fps}*TB)"
            )
            final_scale = f"scale={W}:{H}:flags=bicubic,setsar=1,fps={fps}"
            parts = [f"[{i}:v]", base_chain, ",", crop_f, ",", final_scale]

        if use_trans and td_i > 0:
            parts += [
                f",fade=t=in:st=0:d={td_i:.4f}",
                f",fade=t=out:st={dur_i - td_i:.4f}:d={td_i:.4f}",
            ]

        parts.append(f"[v{i}]")
        filter_parts.append("".join(parts))

    # Subtitle burn filter (Modern Semi-Transparent Rounded Capsule Style matching Preview)
    if sub_input_idx is not None:
        if n == 1:
            filter_parts.append(f"[v0][{sub_input_idx}:v]overlay=0:0:shortest=1[vout]")
        else:
            concat_in = "".join(f"[v{i}]" for i in range(n))
            filter_parts.append(f"{concat_in}concat=n={n}:v=1:a=0[v_concat];[v_concat][{sub_input_idx}:v]overlay=0:0:shortest=1[vout]")
    else:
        if n == 1:
            filter_parts.append("[v0]null[vout]")
        else:
            concat_in = "".join(f"[v{i}]" for i in range(n))
            filter_parts.append(f"{concat_in}concat=n={n}:v=1:a=0[vout]")

    filter_complex = ";".join(filter_parts)

    cmd += ['-filter_complex', filter_complex]
    cmd += ['-map', '[vout]']

    if has_audio:
        cmd += ['-map', f'{audio_idx}:a', '-c:a', 'aac', '-b:a', '128k', '-shortest']

    codec = str(settings.get('codec', 'h264')).lower()
    if codec in ('hevc', 'h265', 'libx265'):
        cmd += [
            '-c:v', 'libx265',
            '-preset', preset,
            '-crf', str(crf),
            '-pix_fmt', 'yuv420p',
            '-tag:v', 'hvc1',
            '-r', str(fps),
            output_path,
        ]
    else:
        cmd += [
            '-c:v', 'libx264',
            '-preset', preset,
            '-crf', str(crf),
            '-pix_fmt', 'yuv420p',
            '-r', str(fps),
            output_path,
        ]

    total_video_duration = n * duration
    return cmd, total_video_duration


# ---------------------------------------------------------------------------
# Main render entry point
# ---------------------------------------------------------------------------

def render_video(
    image_paths: List[str],
    audio_path: Optional[str],
    output_path: str,
    settings: Dict[str, Any],
    progress_callback: Callable[[int, str], None],
    subtitle_path: Optional[str] = None
) -> None:
    """
    Sort images, build FFmpeg command, run it, stream progress.
    Calls progress_callback(percent: int, message: str) during render.
    Raises RuntimeError if FFmpeg exits with a non-zero code.
    """
    images = sort_images(image_paths)
    if not images:
        raise ValueError("No images provided")

    cmd, total_dur = build_command(images, audio_path, output_path, settings, subtitle_path=subtitle_path)

    progress_callback(0, "Starting FFmpeg...")

    def run_ffmpeg_proc(current_cmd):
        proc = subprocess.Popen(
            current_cmd,
            stderr=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            universal_newlines=True,
            bufsize=1
        )

        stderr_lines: List[str] = []

        for line in iter(proc.stderr.readline, ''):
            line = line.rstrip()
            if line:
                stderr_lines.append(line)

            # Parse "time=HH:MM:SS.ss" from FFmpeg progress output
            m = re.search(r'time=(\d+):(\d+):(\d+\.?\d*)', line)
            if m:
                h, mi, s = m.groups()
                current = int(h) * 3600 + int(mi) * 60 + float(s)
                pct = min(int(current / max(total_dur, 0.001) * 100), 99)
                progress_callback(pct, line)

        proc.wait()
        return proc.returncode, stderr_lines

    code, err_lines = run_ffmpeg_proc(cmd)

    # Fallback to software CPU libx264 if hardware encoder failed
    if code != 0 and '-c:v' in cmd and 'h264_videotoolbox' in cmd:
        print("⚠️ [FFmpeg] Hardware encoder failed, retrying with software libx264...")
        cpu_cmd = []
        skip_next = False
        for idx, token in enumerate(cmd):
            if skip_next:
                skip_next = False
                continue
            if token == '-c:v' and idx + 1 < len(cmd) and cmd[idx + 1] == 'h264_videotoolbox':
                cpu_cmd.extend(['-c:v', 'libx264', '-preset', 'medium', '-crf', '23'])
                skip_next = True
            elif token == '-b:v' and idx + 1 < len(cmd):
                skip_next = True
            else:
                cpu_cmd.append(token)

        code, err_lines = run_ffmpeg_proc(cpu_cmd)

    if code != 0:
        err_tail = "\n".join(err_lines[-30:])
        raise RuntimeError(
            f"FFmpeg exited with code {code}.\n\n"
            f"Last output:\n{err_tail}"
        )

    progress_callback(100, "Done!")
