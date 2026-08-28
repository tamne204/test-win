import tempfile
import uuid
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
from typing import List, Tuple, Dict, Any, Callable, Optional
try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    Image = None
    ImageDraw = None
    ImageFont = None

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

SUPPORTED_IMAGE_EXTENSIONS = {'.jpg', '.jpeg', '.png', '.webp', '.bmp', '.tiff', '.tif', '.gif'}
SUPPORTED_AUDIO_EXTENSIONS = {'.mp3', '.wav', '.aac', '.m4a', '.ogg', '.flac'}


# ---------------------------------------------------------------------------
# Utility helpers
# ---------------------------------------------------------------------------

import platform

_cached_hw_encoder = None

def detect_best_hw_encoder(ffmpeg_bin: str) -> Dict[str, Any]:
    """
    Detect the fastest available hardware GPU encoder on the system:
    1. NVIDIA GPU (NVENC): h264_nvenc (Ultra-fast Windows/Linux)
    2. Intel GPU (QuickSync): h264_qsv (Windows/Linux)
    3. AMD GPU (AMF): h264_amf (Windows)
    4. Apple Silicon (VideoToolbox): h264_videotoolbox (macOS)
    5. Fallback: libx264 (CPU Multi-threaded)
    """
    global _cached_hw_encoder
    if _cached_hw_encoder is not None:
        return _cached_hw_encoder

    system = platform.system()
    candidates = []

    if system == "Darwin":
        candidates.append({
            'name': 'Apple VideoToolbox (GPU)',
            'codec': 'h264_videotoolbox',
            'extra_args': ['-b:v', '8M', '-allow_sw', '1']
        })
    elif system == "Windows":
        # 1. NVIDIA GeForce NVENC (Standard Auto GPU)
        candidates.append({
            'name': 'NVIDIA GeForce RTX / GTX (NVENC Turbo GPU)',
            'codec': 'h264_nvenc',
            'extra_args': ['-preset', 'fast', '-cq', '22', '-b:v', '0']
        })
        # 2. Intel QuickSync (Intel Core GPU)
        candidates.append({
            'name': 'Intel QuickSync (QSV GPU)',
            'codec': 'h264_qsv',
            'extra_args': ['-preset', 'veryfast', '-global_quality', '23']
        })
        # 3. AMD Radeon AMF (AMD GPU)
        candidates.append({
            'name': 'AMD Radeon (AMF GPU)',
            'codec': 'h264_amf',
            'extra_args': ['-quality', 'speed']
        })
        # 4. Windows Media Foundation Hardware GPU
        candidates.append({
            'name': 'Windows Media Foundation (D3D11 GPU)',
            'codec': 'h264_mf',
            'extra_args': ['-quality', '1']
        })

    # Test candidate on a 1-frame dummy encoding
    for cand in candidates:
        try:
            test_cmd = [
                ffmpeg_bin, '-y', '-f', 'lavfi', '-i', 'color=c=black:s=128x128:d=0.1',
                '-c:v', cand['codec'], *cand['extra_args'], '-f', 'null', '-'
            ]
            kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
            res = subprocess.run(test_cmd, capture_output=True, text=True, timeout=3, **kwargs)
            if res.returncode == 0:
                _cached_hw_encoder = cand
                print(f"🚀 [Turbo GPU] Activated Hardware Encoder: {cand['name']}")
                return cand
        except Exception:
            pass

    # CPU Fallback with all cores multi-threading
    cpu_encoder = {
        'name': 'CPU Multi-Core (libx264 Ultra-Speed)',
        'codec': 'libx264',
        'extra_args': ['-preset', 'veryfast', '-crf', '22', '-threads', '0', '-x264-params', 'no-mbtree=1:lookahead=10']
    }
    _cached_hw_encoder = cpu_encoder
    print(f"⚙️ [Render Engine] Activated CPU Multi-Core Engine ({cpu_encoder['name']})")
    return cpu_encoder

def get_filter_complex_file_arg(ffmpeg_bin: str, script_file: str) -> List[str]:
    """Return the correct filter script flag depending on FFmpeg version."""
    try:
        kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
        res = subprocess.run([ffmpeg_bin, '-/filter_complex', script_file], capture_output=True, text=True, timeout=2, **kwargs)
        if 'Option not found' not in res.stderr and 'Unrecognized' not in res.stderr:
            return ['-/filter_complex', script_file]
    except Exception:
        pass
    return ['-filter_complex_script', script_file]

def get_ffmpeg_bin() -> str:
    """Return best available ffmpeg binary, supporting Windows, macOS, and Linux."""
    local_dir = os.path.dirname(os.path.abspath(__file__))
    candidates = [
        os.path.join(local_dir, 'bin', 'ffmpeg.exe'),
        os.path.join(local_dir, 'bin', 'ffmpeg'),
        os.path.join(local_dir, 'ffmpeg.exe'),
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
        '/opt/homebrew/opt/ffmpeg-full/bin/ffmpeg',
        '/opt/homebrew/Cellar/ffmpeg-full/9.0.1/bin/ffmpeg',
        '/usr/local/opt/ffmpeg-full/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        'ffmpeg.exe',
        'ffmpeg'
    ]
    for c in candidates:
        if os.path.isabs(c) and os.path.isfile(c):
            return c
    return 'ffmpeg'


def check_ffmpeg() -> bool:
    """Return True if ffmpeg is installed and reachable."""
    try:
        bin_path = get_ffmpeg_bin()
        kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
        result = subprocess.run(
            [bin_path, '-version'],
            capture_output=True, text=True, timeout=5, **kwargs
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
    base_sizes = {'720P': 720, '1080P': 1080, '1440P': 1440, '2K': 1440, '4K': 2160}
    dim = base_sizes.get(str(res_preset).upper(), 1080)

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
    Return deterministic, absolute frame-based z / x / y expressions for FFmpeg's zoompan filter.
    Uses constant perceptual velocity (Linear) to prevent zero-velocity subpixel freeze quantization.
    """
    NF = max(1, total_frames - 1)
    M = max(0.01, min(float(magnitude), 1.0))
    z_max = f"{1.0 + M:.5f}"
    
    # Normalized frame progress t in [0, 1] (deterministic absolute index)
    t_str = f"(on/{NF})"

    if effect in ('none', 'static'):
        return dict(
            z="1.0",
            x="(iw-iw/zoom)/2",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'zoom_in':
        return dict(
            z=f"1.0+{M:.5f}*{t_str}",
            x="(iw-iw/zoom)/2",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'zoom_out':
        return dict(
            z=f"1.0+{M:.5f}*(1.0-{t_str})",
            x="(iw-iw/zoom)/2",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'pan_lr':      # pan left → right
        return dict(
            z=z_max,
            x=f"{t_str}*(iw-iw/zoom)",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'pan_rl':      # pan right → left
        return dict(
            z=z_max,
            x=f"(1.0-{t_str})*(iw-iw/zoom)",
            y="(ih-ih/zoom)/2"
        )
    elif effect == 'tilt_ud':     # tilt top → bottom
        return dict(
            z=z_max,
            x="(iw-iw/zoom)/2",
            y=f"{t_str}*(ih-ih/zoom)"
        )
    elif effect == 'tilt_du':     # tilt bottom → top
        return dict(
            z=z_max,
            x="(iw-iw/zoom)/2",
            y=f"(1.0-{t_str})*(ih-ih/zoom)"
        )

    # Fallback: static
    return dict(z="1.0", x="(iw-iw/zoom)/2", y="(ih-ih/zoom)/2")


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

def _get_subtitle_font(size: int, font_name: str = 'paperlogy', sample_text: str = ''):
    # Use absolute path of THIS file's directory to correctly resolve fonts
    # regardless of working directory (critical for Windows .vbs launch)
    _this_dir = os.path.dirname(os.path.abspath(__file__))
    static_fonts_dir = os.path.join(_this_dir, 'static', 'fonts')
    win_fonts = os.path.join(os.environ.get('WINDIR', 'C:\\Windows'), 'Fonts')
    print(f"🔍 [Font] static_fonts_dir = {static_fonts_dir!r} (exists={os.path.isdir(static_fonts_dir)})")

    # Detect if text contains Korean Hangul glyphs
    is_korean = bool(re.search(r'[\uac00-\ud7a3\u1100-\u11ff\u3130-\u318f]', sample_text)) if sample_text else False

    font_map = {
        'paperlogy': [
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'GongGothicBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            os.path.join(win_fonts, 'arialbd.ttf'),
            os.path.join(win_fonts, 'arial.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf'
        ],
        'jalnan': [
            os.path.join(static_fonts_dir, 'Jalnan.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'gonggothic': [
            os.path.join(static_fonts_dir, 'GongGothicBold.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'isamanru': [
            os.path.join(static_fonts_dir, 'GongGothicBold.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'fromsol': [
            os.path.join(static_fonts_dir, 'Griun_Fromsol.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'griun_fromsol': [
            os.path.join(static_fonts_dir, 'Griun_Fromsol.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'katuri': [
            os.path.join(static_fonts_dir, 'Katuri.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'applesd': [
            '/System/Library/Fonts/AppleSDGothicNeo.ttc',
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(static_fonts_dir, 'GongGothicBold.ttf'),
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf')
        ],
        'montserrat': [
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            os.path.join(win_fonts, 'arialbd.ttf'),
            os.path.join(win_fonts, 'arial.ttf'),
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ],
        'tahoma': [
            os.path.join(static_fonts_dir, 'Tahoma-Bold.ttf'),
            os.path.join(static_fonts_dir, 'Tahoma-Regular.ttf'),
            os.path.join(win_fonts, 'tahomabd.ttf'),
            os.path.join(win_fonts, 'tahoma.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            '/System/Library/Fonts/Supplemental/Tahoma Bold.ttf',
            '/System/Library/Fonts/Supplemental/Tahoma.ttf'
        ],
        'arial': [
            os.path.join(win_fonts, 'arialbd.ttf'),
            os.path.join(win_fonts, 'arial.ttf'),
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf')
        ],
        'roboto': [
            os.path.join(static_fonts_dir, 'Montserrat-Bold.ttf'),
            os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf'),
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/AppleSDGothicNeo.ttc'
        ]
    }

    if ImageFont is None:
        return None

    # If text is Korean and user selected a Latin-only font, prioritize Paperlogy to avoid missing glyphs
    requested_key = str(font_name).lower().strip()
    if is_korean and requested_key in ('montserrat', 'tahoma', 'arial', 'roboto'):
        requested_key = 'paperlogy'

    candidates = font_map.get(requested_key, font_map['paperlogy'])
    loaded_font = None
    for c in candidates:
        abs_c = os.path.abspath(c)
        if os.path.isfile(abs_c):
            try:
                loaded_font = ImageFont.truetype(abs_c, size, index=0)
                print(f"✅ [Font] Loaded: {os.path.basename(abs_c)} @ {size}px")
                return loaded_font
            except Exception as fe:
                print(f"⚠️ [Font] Failed to load {abs_c}: {fe}")

    # Check any available .ttf in static_fonts_dir (prioritizing Paperlogy)
    paperlogy_ttf = os.path.join(static_fonts_dir, 'Paperlogy-8ExtraBold.ttf')
    if os.path.isfile(paperlogy_ttf):
        try:
            f = ImageFont.truetype(paperlogy_ttf, size)
            print(f"✅ [Font] Fallback to Paperlogy @ {size}px")
            return f
        except Exception:
            pass

    if os.path.isdir(static_fonts_dir):
        for fname in os.listdir(static_fonts_dir):
            if fname.lower().endswith('.ttf'):
                fpath = os.path.join(static_fonts_dir, fname)
                try:
                    f = ImageFont.truetype(fpath, size)
                    print(f"✅ [Font] Fallback to {fname} @ {size}px")
                    return f
                except Exception:
                    pass

    print(f"❌ [Font] No usable TTF found in {static_fonts_dir}! Falling back to bitmap default (WILL LOOK WRONG).")
    try:
        return ImageFont.load_default()
    except Exception:
        return None



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
    sub_font: str = 'paperlogy',
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
def parse_srt_cues_universal(srt_text: str) -> List[Tuple[float, float, str]]:
    """Universal robust SRT/VTT parser that handles all timecode and newline variations without leaking cue numbers."""
    if not srt_text:
        return []
    clean_text = srt_text.lstrip('\ufeff').replace('\r\n', '\n').replace('\r', '\n')
    tc_pattern = re.compile(
        r'(?:(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})(?:[,.](\d{1,4}))?)\s*-->\s*(?:(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})(?:[,.](\d{1,4}))?)'
    )
    
    def _parse_time(h, m, s, ms) -> float:
        h_val = int(h) if h else 0
        m_val = int(m) if m else 0
        s_val = int(s) if s else 0
        ms_val = float(f"0.{ms}") if ms else 0.0
        return h_val * 3600.0 + m_val * 60.0 + s_val + ms_val

    def _is_next_cue_ahead(start_idx: int, lines_list: List[str], max_l: int) -> bool:
        k = start_idx
        while k < max_l and not lines_list[k].strip():
            k += 1
        if k >= max_l:
            return False
        first_non_empty = lines_list[k].strip()
        if tc_pattern.search(first_non_empty):
            return True
        if first_non_empty.isdigit():
            k2 = k + 1
            while k2 < max_l and not lines_list[k2].strip():
                k2 += 1
            if k2 < max_l and tc_pattern.search(lines_list[k2].strip()):
                return True
        return False

    cues = []
    lines = clean_text.split('\n')
    i = 0
    num_lines = len(lines)
    
    while i < num_lines:
        line = lines[i].strip()
        m = tc_pattern.search(line)
        if m:
            h1, m1, s1, ms1, h2, m2, s2, ms2 = m.groups()
            st = _parse_time(h1, m1, s1, ms1)
            et = _parse_time(h2, m2, s2, ms2)
            if et <= st:
                et = st + 1.0  # Fallback 1s duration
                
            text_parts = []
            i += 1
            while i < num_lines:
                next_line = lines[i].strip()
                if not next_line:
                    if _is_next_cue_ahead(i + 1, lines, num_lines):
                        break
                    i += 1
                    continue
                if tc_pattern.search(next_line):
                    break
                if next_line.isdigit() and _is_next_cue_ahead(i, lines, num_lines):
                    break
                text_parts.append(next_line)
                i += 1
                
            # Filter out any accidentally captured lone digits at the end of text
            while text_parts and text_parts[-1].strip().isdigit():
                text_parts.pop()

            txt = " ".join(text_parts).strip()
            if txt:
                cues.append((round(st, 3), round(et, 3), txt))
        else:
            i += 1
    return cues


def generate_subtitle_overlay_concat(
    subtitle_path: str,
    W: int,
    H: int,
    is_vertical: bool = False,
    sub_font: str = 'paperlogy',
    sub_size: int = 36,
    sub_color: str = '#ffffff',
    sub_stroke_enabled: bool = True,
    sub_stroke_color: str = '#000000',
    sub_stroke_width: int = 4,
    sub_bg_enabled: bool = False,
    sub_bg_color: str = '#000000',
    sub_bg_opacity: float = 0.55,
    sub_bg_radius: int = 14,
    sub_pos_y: int = 82,
    sub_pos_x: int = 50,
    sub_letter_spacing: int = 0,
    sub_line_spacing: int = 8,
    sub_align: str = 'center'
) -> Optional[str]:
    """
    Render beautiful modern rounded capsule subtitles to PNG sequence and build a gapless
    ffconcat script for FFmpeg overlay (matching Preview Canvas 1:1).
    """
    if not subtitle_path or not os.path.isfile(subtitle_path):
        return None

    if Image is None or ImageDraw is None:
        return None

    try:
        with open(subtitle_path, 'r', encoding='utf-8') as f:
            srt_text = f.read()

        cues = parse_srt_cues_universal(srt_text)

        if not cues:
            print(f"⚠️ [Subtitle Overlay] No cues parsed from {subtitle_path}")
            return None

        # Temp directory next to subtitle file
        sub_dir = os.path.dirname(subtitle_path)
        overlay_dir = os.path.join(sub_dir, 'sub_overlay_frames')
        os.makedirs(overlay_dir, exist_ok=True)

        # Scale font size with video resolution & match visual preview scale 1:1
        if is_vertical:
            font_size = max(22, int(sub_size * 3.74 * (H / 1920.0)))
            stroke_w = max(1, int(sub_stroke_width * 3.74 * (H / 1920.0) * 0.5)) if sub_stroke_enabled else 0
        else:
            font_size = max(18, int(sub_size * 2.2 * (H / 1080.0)))
            stroke_w = max(1, int(sub_stroke_width * 2.2 * (H / 1080.0) * 0.5)) if sub_stroke_enabled else 0

        sample_txt = " ".join(c[2] for c in cues[:5])
        font = _get_subtitle_font(font_size, sub_font, sample_text=sample_txt)

        blank_png = os.path.join(overlay_dir, 'blank.png')
        Image.new('RGBA', (W, H), (0, 0, 0, 0)).save(blank_png)

        # Proportional padding and radius
        pad_x = max(16, int(font_size * 0.35))
        pad_y = max(8, int(font_size * 0.18))

        # Position calculations
        if sub_pos_y is None:
            sub_pos_y = 14.0 if is_vertical else 6.5
        bottom_margin = int(H * (float(sub_pos_y) / 100.0))

        text_rgba = _hex_to_rgba(sub_color, 255)
        stroke_rgba = _hex_to_rgba(sub_stroke_color, 255) if sub_stroke_enabled else None

        bg_alpha = int(255 * (float(sub_bg_opacity) / 100.0))
        bg_rgba = _hex_to_rgba(sub_bg_color, bg_alpha)
        radius = max(6, int(sub_bg_radius * (font_size / 50.0)))

        # Letter spacing & Line spacing scaling
        scale_ref = (H / 1080.0) if not is_vertical else (H / 1920.0)
        l_space = int(float(sub_letter_spacing) * scale_ref * 2.0)
        line_height_multiplier = max(1.0, float(sub_line_spacing)) if sub_line_spacing else 1.25
        max_content_w = int(W * 0.88)

        def _render_single_cue(idx, st, et, raw_text):
            fpath = os.path.join(overlay_dir, f'cue_{idx}.png')
            img = Image.new('RGBA', (W, H), (0, 0, 0, 0))
            draw = ImageDraw.Draw(img)

            # Auto word-wrap into lines based on whole-string width measurement
            paragraphs = raw_text.split('\n')
            lines = []
            for p in paragraphs:
                words = p.split()
                if not words:
                    continue
                curr_words = []
                for w in words:
                    test_str = " ".join(curr_words + [w])
                    bb = draw.textbbox((0, 0), test_str, font=font, stroke_width=stroke_w)
                    tw = bb[2] - bb[0]
                    if tw > max_content_w and curr_words:
                        lines.append(" ".join(curr_words))
                        curr_words = [w]
                    else:
                        curr_words.append(w)
                if curr_words:
                    lines.append(" ".join(curr_words))

            if not lines:
                lines = [raw_text]

            # Measure all lines cleanly as full strings
            line_metrics = []
            max_line_w = 0
            for l in lines:
                bb = draw.textbbox((0, 0), l, font=font, stroke_width=stroke_w)
                lw = bb[2] - bb[0]
                lh = bb[3] - bb[1]
                line_metrics.append((l, lw, lh, bb[0], bb[1]))
                if lw > max_line_w:
                    max_line_w = lw

            line_step = int(font_size * line_height_multiplier)
            total_text_h = (len(lines) - 1) * line_step + line_metrics[-1][2]
            box_w = max_line_w + pad_x * 2
            box_h = total_text_h + pad_y * 2

            # X & Y coordinates
            cx = int(W * 0.5 + W * (float(sub_pos_x) / 100.0))
            y1 = H - bottom_margin

            # If subtitle has only 1 line, vertically center it at the midpoint of the 2-line text position
            if len(lines) == 1:
                y1 = y1 - int(line_step / 2.0)

            y0 = y1 - box_h
            x0 = cx - box_w // 2
            x1 = cx + box_w // 2

            # 1. Background Box (if enabled)
            if sub_bg_enabled:
                draw.rounded_rectangle([x0, y0, x1, y1], radius=radius, fill=bg_rgba)

            # 2. Native full-line text rendering with perfect OpenType typography
            curr_y = y0 + pad_y
            for l_str, lw, lh, bb_x0, bb_y0 in line_metrics:
                lx = cx - lw // 2 - bb_x0
                ly = curr_y - bb_y0
                if sub_stroke_enabled and stroke_w > 0:
                    draw.text(
                        (lx, ly),
                        l_str,
                        font=font,
                        fill=text_rgba,
                        stroke_width=stroke_w,
                        stroke_fill=stroke_rgba
                    )
                else:
                    draw.text(
                        (lx, ly),
                        l_str,
                        font=font,
                        fill=text_rgba
                    )
                curr_y += line_step

            img.save(fpath)
            return (idx, st, et, fpath)

        import concurrent.futures
        workers = min(8, os.cpu_count() or 4)
        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
            futures = [executor.submit(_render_single_cue, idx, st, et, raw_text) for idx, (st, et, raw_text) in enumerate(cues)]
            results = [f.result() for f in futures]

        results.sort(key=lambda x: x[0])
        cue_files = [(r[1], r[2], r[3]) for r in results]

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

        # Trailing blank frame: 86400s (24h) to guarantee subtitle stream never finishes before long videos
        concat_lines.append(f"file '{abs_blank}'")
        concat_lines.append("duration 86400.000")
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
            raw_cues = parse_srt_cues_universal(srt_txt)
            if len(raw_cues) == n:
                image_durations = [max(1.0, round(et - st, 3)) for st, et, _ in raw_cues]
        except Exception:
            pass

    # Synchronize image durations with audio duration to prevent early cutoff
    has_audio = bool(audio_path and os.path.isfile(audio_path))
    audio_dur = 0.0
    if has_audio:
        try:
            from subtitles_engine import get_audio_duration
            audio_dur = get_audio_duration(audio_path)
        except Exception:
            audio_dur = 0.0

    if has_audio and audio_dur > 0:
        if image_durations and len(image_durations) == n:
            sum_durs = sum(image_durations)
            if sum_durs < audio_dur:
                # Extend the last image to guarantee video covers 100% of the audio
                diff = round(audio_dur - sum_durs, 3)
                image_durations[-1] = round(image_durations[-1] + diff, 3)
        elif not image_durations:
            dur_per_img = max(1.0, audio_dur / max(1, n))
            image_durations = [dur_per_img] * n

    # Per-image specific effects or non-repeating random assignment
    custom_effects = settings.get('image_effects')
    if custom_effects and isinstance(custom_effects, list) and len(custom_effects) == n:
        effects = custom_effects
    else:
        effects = assign_effects(n, weights)

    # --- IN / OUT REGION SELECTION RENDER (Mark In / Mark Out) ---
    render_in_val = settings.get('render_in')
    render_out_val = settings.get('render_out')
    has_in_out = False
    render_in = 0.0
    render_out = None

    if render_in_val is not None:
        try:
            render_in = max(0.0, float(render_in_val))
            has_in_out = True
        except (ValueError, TypeError):
            render_in = 0.0

    if render_out_val is not None:
        try:
            render_out = max(render_in + 0.3, float(render_out_val))
            has_in_out = True
        except (ValueError, TypeError):
            render_out = None

    if has_in_out and (render_in > 0.0 or render_out is not None):
        print(f"🎯 [In/Out Render] Slicing timeline from {render_in:.2f}s to {render_out if render_out is not None else 'END'}s...")
        # 1. Slice Subtitles
        if subtitle_path and os.path.isfile(subtitle_path):
            try:
                with open(subtitle_path, 'r', encoding='utf-8') as fs:
                    srt_content = fs.read()
                raw_cues = parse_srt_cues_universal(srt_content)
                trimmed_cues = []
                for st, et, txt in raw_cues:
                    if et > render_in and (render_out is None or st < render_out):
                        n_st = max(0.0, st - render_in)
                        n_et = (min(render_out, et) - render_in) if render_out else (et - render_in)
                        if n_et > n_st:
                            trimmed_cues.append((n_st, n_et, txt))
                
                if trimmed_cues:
                    trimmed_srt_path = os.path.join(os.path.dirname(subtitle_path), f"trimmed_{int(render_in)}_{int(render_out or 0)}.srt")
                    with open(trimmed_srt_path, 'w', encoding='utf-8') as fts:
                        for c_i, (c_st, c_et, c_txt) in enumerate(trimmed_cues):
                            def _format_srt_time(sec: float) -> str:
                                h = int(sec // 3600)
                                m = int((sec % 3600) // 60)
                                s = int(sec % 60)
                                ms = int(round((sec - int(sec)) * 1000))
                                return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"
                            fts.write(f"{c_i + 1}\n{_format_srt_time(c_st)} --> {_format_srt_time(c_et)}\n{c_txt}\n\n")
                    subtitle_path = trimmed_srt_path
            except Exception as e:
                print(f"⚠️ Error slicing subtitles for In/Out: {e}")

        # 2. Slice Audio
        if audio_path and os.path.isfile(audio_path):
            try:
                ffmpeg_bin_local = get_ffmpeg_bin()
                trimmed_audio_path = os.path.join(os.path.dirname(audio_path), f"trimmed_{int(render_in)}_{int(render_out or 0)}.wav")
                trim_cmd = [ffmpeg_bin_local, '-y', '-ss', str(render_in)]
                if render_out is not None:
                    trim_cmd += ['-to', str(render_out)]
                trim_cmd += ['-i', audio_path, trimmed_audio_path]
                kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
                res_trim = subprocess.run(trim_cmd, capture_output=True, text=True, **kwargs)
                if res_trim.returncode == 0 and os.path.isfile(trimmed_audio_path):
                    audio_path = trimmed_audio_path
            except Exception as e:
                print(f"⚠️ Error slicing audio for In/Out: {e}")

        # 3. Slice Images & Durations
        actual_durs = image_durations if (image_durations and len(image_durations) == n) else [duration] * n
        actual_effs = effects if (effects and len(effects) == n) else ['zoom_in'] * n

        new_images = []
        new_durs = []
        new_effs = []

        cur_timeline_st = 0.0
        for i_idx in range(n):
            img_d = actual_durs[i_idx]
            cur_timeline_et = cur_timeline_st + img_d
            if cur_timeline_et > render_in and (render_out is None or cur_timeline_st < render_out):
                sub_st = max(render_in, cur_timeline_st)
                sub_et = min(render_out, cur_timeline_et) if render_out is not None else cur_timeline_et
                slice_dur = max(0.5, round(sub_et - sub_st, 3))
                new_images.append(image_paths[i_idx])
                new_durs.append(slice_dur)
                new_effs.append(actual_effs[i_idx])
            cur_timeline_st = cur_timeline_et

        if new_images:
            image_paths = new_images
            image_durations = new_durs
            effects = new_effs
            n = len(image_paths)

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

    # Generate exact subtitle overlay (Pillow pill-capsule style)
    has_sub = subtitle_path is not None and os.path.isfile(subtitle_path)
    concat_txt_path = None
    if has_sub:
        is_vertical = (H > W)
        # Support both key names: 'sub_font' (legacy) and 'subtitle_font' (new)
        sub_font          = str(settings.get('subtitle_font', settings.get('sub_font', 'paperlogy')))
        sub_size          = int(settings.get('subtitle_font_size', settings.get('sub_size', 36)))
        sub_color         = str(settings.get('subtitle_color', settings.get('sub_color', '#ffffff')))
        sub_stroke_enabled = str(settings.get('sub_stroke_enabled', 'true')).lower() in ('true', '1', 'yes')
        sub_stroke_color  = str(settings.get('sub_stroke_color', '#000000'))
        sub_stroke_width  = int(settings.get('sub_stroke_width', 4))
        sub_bg_enabled    = str(settings.get('sub_bg_enabled', 'false')).lower() in ('true', '1', 'yes')
        sub_bg_color      = str(settings.get('sub_bg_color', '#000000'))
        sub_bg_opacity    = int(settings.get('sub_bg_opacity', 75))
        sub_bg_radius     = int(settings.get('sub_bg_radius', 12))
        sub_pos_y         = float(settings.get('sub_pos_y', 14.0 if is_vertical else 6.5))
        sub_pos_x         = float(settings.get('sub_pos_x', 0.0))
        sub_letter_spacing = float(settings.get('sub_letter_spacing', 0.0))
        sub_line_spacing  = float(settings.get('sub_line_spacing', 1.25))
        sub_align         = str(settings.get('sub_align', 'center'))

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
        if concat_txt_path:
            print(f"✅ [Subtitle] PIL overlay generated: {concat_txt_path}")
        else:
            print("⚠️ [Subtitle] PIL overlay failed — subtitle will be burned via ffmpeg subtitles filter")

    sub_input_idx = None
    use_subtitles_filter = False   # fallback: burn via ffmpeg subtitles= vf
    if concat_txt_path and os.path.isfile(concat_txt_path):
        cmd += ['-f', 'concat', '-safe', '0', '-i', concat_txt_path]
        sub_input_idx = len(image_paths) + (1 if has_audio else 0)
    elif has_sub:
        # Fallback: burn subtitle directly via FFmpeg's subtitles filter
        use_subtitles_filter = True

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
    accum_time = 0.0
    accum_frames = 0

    for i, effect in enumerate(effects):
        # Determine duration for image i
        if image_durations and i < len(image_durations):
            dur_i = max(1.0, float(image_durations[i]))
        else:
            dur_i = max(1.0, duration)

        total_video_duration += dur_i
        accum_time += dur_i
        target_total_frames = max(1, int(round(accum_time * fps)))
        total_frames_i = max(1, target_total_frames - accum_frames)
        accum_frames += total_frames_i

        td_i = min(td, dur_i / 2.0) if use_trans and n > 1 else 0.0

        mag = float(mag_for_effect.get(effect, zoom_mag))
        
        # Adaptive high-density supersampling (up to 7.6K canvas) to minimize subpixel quantization
        if max(W, H) <= 1920:
            scale_w = W * 4
            scale_h = H * 4
        elif max(W, H) <= 2560:
            scale_w = W * 3
            scale_h = H * 3
        else:
            scale_w = W * 2
            scale_h = H * 2

        # Get deterministic linear camera expressions (jitter-free constant perceptual velocity)
        zp = _zoompan_params(effect, mag, total_frames_i)
        z_expr = zp['z']
        x_expr = zp['x']
        y_expr = zp['y']

        base_chain = (
            f"scale={scale_w}:{scale_h}:force_original_aspect_ratio=increase,"
            f"crop={scale_w}:{scale_h},"
            f"zoompan=z='{z_expr}':x='{x_expr}':y='{y_expr}':d={total_frames_i}:s={W}x{H}:fps={fps},"
            f"format=yuv420p,setsar=1"
        )
        parts = [f"[{i}:v]", base_chain]

        if use_trans and td_i > 0:
            parts += [
                f",fade=t=in:st=0:d={td_i:.4f}",
                f",fade=t=out:st={dur_i - td_i:.4f}:d={td_i:.4f}",
            ]

        parts.append(f"[v{i}]")
        filter_parts.append("".join(parts))

    # Subtitle burn filter (Modern Semi-Transparent Rounded Capsule Style matching Preview)
    if sub_input_idx is not None:
        # PIL pill-capsule overlay via PNG concat stream
        if n == 1:
            filter_parts.append(f"[v0][{sub_input_idx}:v]overlay=0:0:eof_action=pass[vout]")
        else:
            concat_in = "".join(f"[v{i}]" for i in range(n))
            filter_parts.append(f"{concat_in}concat=n={n}:v=1:a=0[v_concat];[v_concat][{sub_input_idx}:v]overlay=0:0:eof_action=pass[vout]")
    elif use_subtitles_filter:
        # Fallback: burn subtitle text via FFmpeg subtitles= filter (no PIL needed)
        escaped_srt = subtitle_path.replace('\\', '/').replace(':', '\\:')
        if n == 1:
            filter_parts.append(f"[v0]subtitles=filename='{escaped_srt}'[vout]")
        else:
            concat_in = "".join(f"[v{i}]" for i in range(n))
            filter_parts.append(f"{concat_in}concat=n={n}:v=1:a=0[v_raw];[v_raw]subtitles=filename='{escaped_srt}'[vout]")
    else:
        if n == 1:
            filter_parts.append("[v0]null[vout]")
        else:
            concat_in = "".join(f"[v{i}]" for i in range(n))
            filter_parts.append(f"{concat_in}concat=n={n}:v=1:a=0[vout]")

    filter_complex = ";".join(filter_parts)

        # Use -filter_complex_script to bypass Windows [WinError 206] command length limit
    filter_script_path = os.path.join(tempfile.gettempdir(), f"filter_{uuid.uuid4().hex[:8]}.txt")
    with open(filter_script_path, 'w', encoding='utf-8') as f_sc:
        f_sc.write(filter_complex)

        ffmpeg_bin = get_ffmpeg_bin()
    cmd += get_filter_complex_file_arg(ffmpeg_bin, filter_script_path)
    cmd += ['-map', '[vout]']

    if has_audio:
        cmd += ['-map', f'{audio_idx}:a', '-c:a', 'aac', '-b:a', '128k', '-shortest']

    # Turbo GPU & CPU Acceleration Engine
    hw_info = detect_best_hw_encoder(ffmpeg_bin)
    user_codec = str(settings.get('codec', 'auto')).lower()

    if user_codec in ('nvenc', 'nvidia', 'h264_nvenc'):
        cmd += [
            '-c:v', 'h264_nvenc',
            '-preset', 'fast',
            '-cq', '23',
            '-pix_fmt', 'yuv420p',
            '-threads', '0',
            '-r', str(fps),
            output_path,
        ]
    elif user_codec in ('qsv', 'intel', 'h264_qsv'):
        cmd += [
            '-c:v', 'h264_qsv',
            '-preset', 'veryfast',
            '-global_quality', '23',
            '-pix_fmt', 'yuv420p',
            '-threads', '0',
            '-r', str(fps),
            output_path,
        ]
    elif user_codec in ('amf', 'amd', 'h264_amf'):
        cmd += [
            '-c:v', 'h264_amf',
            '-quality', 'speed',
            '-pix_fmt', 'yuv420p',
            '-threads', '0',
            '-r', str(fps),
            output_path,
        ]
    elif user_codec in ('hevc', 'h265', 'libx265'):
        cmd += [
            '-c:v', 'libx265',
            '-preset', 'veryfast',
            '-crf', str(crf),
            '-pix_fmt', 'yuv420p',
            '-tag:v', 'hvc1',
            '-threads', '0',
            '-r', str(fps),
            output_path,
        ]
    elif user_codec == 'h264_cpu':
        cmd += [
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', str(crf),
            '-pix_fmt', 'yuv420p',
            '-threads', '0',
            '-r', str(fps),
            output_path,
        ]
    else:
        # Auto Turbo Hardware Acceleration (NVENC / QSV / AMF / VideoToolbox)
        cmd += [
            '-c:v', hw_info['codec'],
            *hw_info['extra_args'],
            '-pix_fmt', 'yuv420p',
            '-threads', '0',
            '-r', str(fps),
            output_path,
        ]

    return cmd, total_video_duration


# ---------------------------------------------------------------------------
# Main render entry point
# ---------------------------------------------------------------------------

def render_video_single_pass(
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
        _kwargs = dict(
            stderr=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            universal_newlines=True,
            bufsize=1
        )
        if sys.platform == 'win32':
            _kwargs['creationflags'] = 0x08000000  # CREATE_NO_WINDOW
        proc = subprocess.Popen(current_cmd, **_kwargs)

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

    # Automatic Universal Fallback if GPU hardware encoder (NVENC / QSV / AMF) encounters a driver error
    hw_codecs = ['h264_nvenc', 'h264_qsv', 'h264_amf', 'h264_videotoolbox']
    used_hw = next((c for c in hw_codecs if c in cmd), None)

    if code != 0 and used_hw:
        print(f"⚠️ [FFmpeg] GPU encoder {used_hw} encountered driver limit. Auto-retrying with Intel QSV / CPU Multi-Core...")
        # 1. Try Intel QSV if NVENC failed on Windows
        if used_hw == 'h264_nvenc' and platform.system() == 'Windows':
            qsv_cmd = []
            skip = False
            for idx, token in enumerate(cmd):
                if skip:
                    skip = False
                    continue
                if token == '-c:v' and idx + 1 < len(cmd) and cmd[idx + 1] == 'h264_nvenc':
                    qsv_cmd.extend(['-c:v', 'h264_qsv', '-preset', 'veryfast', '-global_quality', '23'])
                    skip = True
                elif token in ('-preset', '-cq', '-b:v') and idx + 1 < len(cmd):
                    skip = True
                else:
                    qsv_cmd.append(token)
            
            code, err_lines = run_ffmpeg_proc(qsv_cmd)
            if code == 0:
                print("✅ [Auto-Fallback] Successfully rendered using Intel QuickSync GPU!")

        # 2. If still failing, fallback to CPU Multi-Core
        if code != 0:
            cpu_cmd = []
            skip = False
            for idx, token in enumerate(cmd):
                if skip:
                    skip = False
                    continue
                if token == '-c:v' and idx + 1 < len(cmd) and cmd[idx + 1] in hw_codecs:
                    cpu_cmd.extend(['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-threads', '0'])
                    skip = True
                elif token in ('-preset', '-cq', '-global_quality', '-quality', '-b:v') and idx + 1 < len(cmd):
                    skip = True
                else:
                    cpu_cmd.append(token)

            code, err_lines = run_ffmpeg_proc(cpu_cmd)
            if code == 0:
                print("✅ [Auto-Fallback] Successfully rendered using CPU Multi-Core Engine!")

    if code != 0:
        err_tail = "\n".join(err_lines[-30:])
        raise RuntimeError(
            f"FFmpeg exited with code {code}.\n\n"
            f"Last output:\n{err_tail}"
        )

    progress_callback(100, "Done!")


def render_video_chunked(
    images: List[str],
    audio_path: Optional[str],
    output_path: str,
    settings: Dict[str, Any],
    progress_callback: Callable[[int, str], None],
    subtitle_path: Optional[str] = None
) -> None:
    """
    Renders large slideshows (>12 slides) in memory-safe chunks (O(1) Constant RAM).
    Prevents FFmpeg [Cannot allocate memory] errors on long videos.
    """
    import shutil
    CHUNK_SIZE = 10
    total_imgs = len(images)
    chunks = [images[i:i + CHUNK_SIZE] for i in range(0, total_imgs, CHUNK_SIZE)]
    num_chunks = len(chunks)

    temp_dir = tempfile.mkdtemp(prefix="slideshow_chunks_")
    chunk_video_files = []

    # Ensure total image durations match full audio duration
    has_audio = bool(audio_path and os.path.isfile(audio_path))
    if has_audio:
        try:
            from subtitles_engine import get_audio_duration
            a_dur = get_audio_duration(audio_path)
            if a_dur > 0:
                img_durs = settings.get('image_durations')
                if img_durs and len(img_durs) == total_imgs:
                    sum_durs = sum(img_durs)
                    if sum_durs < a_dur:
                        img_durs[-1] = round(img_durs[-1] + (a_dur - sum_durs), 3)
                        settings['image_durations'] = img_durs
                elif not img_durs:
                    dur_per = max(1.0, a_dur / max(1, total_imgs))
                    settings['image_durations'] = [dur_per] * total_imgs
        except Exception:
            pass

    try:
        # 1. Render each chunk independently
        for idx, chunk_imgs in enumerate(chunks):
            chunk_out = os.path.join(temp_dir, f"chunk_{idx:04d}.mp4")
            chunk_video_files.append(chunk_out)

            start_idx = idx * CHUNK_SIZE
            end_idx = start_idx + len(chunk_imgs)

            img_durs = settings.get('image_durations')
            chunk_durs = img_durs[start_idx:end_idx] if img_durs else None

            all_effects = settings.get('effects') or settings.get('image_effects')
            chunk_effects = all_effects[start_idx:end_idx] if all_effects else None

            chunk_settings = dict(settings)
            if chunk_durs:
                chunk_settings['image_durations'] = chunk_durs
            if chunk_effects:
                chunk_settings['effects'] = chunk_effects
                chunk_settings['image_effects'] = chunk_effects

            base_pct = int((idx / num_chunks) * 85)
            pct_span = int(85 / num_chunks)

            def chunk_progress(p, msg):
                actual_pct = base_pct + int(p * pct_span / 100)
                progress_callback(min(85, actual_pct), f"[Đoạn {idx+1}/{num_chunks}] {msg}")

            render_video_single_pass(
                image_paths=chunk_imgs,
                audio_path=None,
                output_path=chunk_out,
                settings=chunk_settings,
                progress_callback=chunk_progress,
                subtitle_path=None
            )

        # 2. Concat all chunks seamlessly using concat demuxer
        progress_callback(86, "Đang ghép các đoạn video mượt mà (Lossless Concat)...")
        concat_list_file = os.path.join(temp_dir, "concat_list.txt")
        with open(concat_list_file, "w", encoding="utf-8") as f_list:
            for c_file in chunk_video_files:
                escaped_path = c_file.replace('\\', '/')
                f_list.write(f"file '{escaped_path}'\n")

        merged_video_path = os.path.join(temp_dir, "merged_visual.mp4")
        ffmpeg_bin = get_ffmpeg_bin()

        cmd_concat = [
            ffmpeg_bin, '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', concat_list_file,
            '-c', 'copy',
            merged_video_path
        ]
        _run_kw = {}
        if sys.platform == 'win32':
            _run_kw['creationflags'] = 0x08000000  # CREATE_NO_WINDOW
        res = subprocess.run(cmd_concat, capture_output=True, text=True, **_run_kw)
        if res.returncode != 0:
            raise RuntimeError(f"Concat failed: {res.stderr}")


        # 3. Final Pass: Attach Audio & Burn Subtitles (if any)
        progress_callback(90, "Đang hoàn tất âm thanh & phụ đề...")

        ffmpeg_bin = get_ffmpeg_bin()
        has_audio = bool(audio_path and os.path.isfile(audio_path))
        has_subs  = bool(subtitle_path and os.path.isfile(subtitle_path))

        # Build input list with tracked indices
        final_cmd = [ffmpeg_bin, '-y']
        final_cmd += ['-i', merged_video_path]   # index 0: video
        input_idx = 1

        audio_input_idx = None
        if has_audio:
            final_cmd += ['-i', audio_path]
            audio_input_idx = input_idx
            input_idx += 1

        # Generate subtitle overlay frames (PIL pill-capsule style)
        concat_sub_txt = None
        if has_subs:
            aspect = settings.get('aspect_ratio', '16:9')
            res = settings.get('resolution', '1080p')
            W_sub, H_sub = build_resolution(aspect, res)
            is_vert = (H_sub > W_sub)
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

            concat_sub_txt = generate_subtitle_overlay_concat(
                subtitle_path, W_sub, H_sub,
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
            if concat_sub_txt:
                print(f"✅ [Chunked Final Pass] PIL overlay generated: {concat_sub_txt}")
            else:
                print(f"⚠️ [Chunked Final Pass] PIL overlay failed — fallback to ffmpeg subtitles filter")

        sub_input_idx = None
        use_subtitles_filter = False
        if concat_sub_txt and os.path.isfile(concat_sub_txt):
            final_cmd += ['-f', 'concat', '-safe', '0', '-i', concat_sub_txt]
            sub_input_idx = input_idx
            input_idx += 1
        elif has_subs:
            use_subtitles_filter = True

        # ---- Map streams (ALWAYS explicit) ----
        need_reencode = False
        if sub_input_idx is not None:
            # Overlay subtitle PNG frames onto video
            final_cmd += [
                '-filter_complex',
                f'[0:v][{sub_input_idx}:v]overlay=0:0:eof_action=pass[vout]',
                '-map', '[vout]'
            ]
            need_reencode = True
        elif use_subtitles_filter:
            escaped_srt = subtitle_path.replace('\\', '/').replace(':', '\\:')
            final_cmd += [
                '-vf', f"subtitles='{escaped_srt}'",
                '-map', '0:v'
            ]
            need_reencode = True
        else:
            # Always map video stream 0 explicitly
            final_cmd += ['-map', '0:v']

        if audio_input_idx is not None:
            final_cmd += ['-map', f'{audio_input_idx}:a', '-c:a', 'aac', '-b:a', '128k', '-shortest']

        # ---- Video codec ----
        if need_reencode:
            hw_info = detect_best_hw_encoder(ffmpeg_bin)
            user_codec = str(settings.get('codec', 'auto')).lower()
            if user_codec in ('nvenc', 'nvidia', 'h264_nvenc'):
                final_cmd += ['-c:v', 'h264_nvenc', '-preset', 'fast', '-cq', '23', '-pix_fmt', 'yuv420p', '-threads', '0']
            else:
                final_cmd += ['-c:v', hw_info['codec'], *hw_info['extra_args'], '-pix_fmt', 'yuv420p', '-threads', '0']
        else:
            # No subtitle re-encode needed — lossless copy
            final_cmd += ['-c:v', 'copy']

        final_cmd.append(output_path)

        _run_kw = {}
        if sys.platform == 'win32':
            _run_kw['creationflags'] = 0x08000000  # CREATE_NO_WINDOW

        res_final = subprocess.run(final_cmd, capture_output=True, text=True, **_run_kw)
        if res_final.returncode != 0:
            # Universal CPU fallback
            print("⚠️ [Final Pass] Primary failed, retrying with CPU libx264...")
            cpu_final_cmd = []
            skip = False
            for idx, token in enumerate(final_cmd):
                if skip:
                    skip = False
                    continue
                if token == '-c:v' and idx + 1 < len(final_cmd) and final_cmd[idx + 1] not in ('copy', 'libx264'):
                    cpu_final_cmd.extend(['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-threads', '0'])
                    skip = True
                elif token in ('-preset', '-cq', '-b:v', '-global_quality', '-quality', '-allow_sw', '-tune', '-rc') and idx + 1 < len(final_cmd):
                    skip = True
                else:
                    cpu_final_cmd.append(token)
            res_final = subprocess.run(cpu_final_cmd, capture_output=True, text=True, **_run_kw)

            if res_final.returncode != 0:
                raise RuntimeError(f"Final merge failed: {res_final.stderr}")


        progress_callback(100, "Done!")

    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)


def render_video(
    image_paths: List[str],
    audio_path: Optional[str],
    output_path: str,
    settings: Dict[str, Any],
    progress_callback: Callable[[int, str], None],
    subtitle_path: Optional[str] = None
) -> None:
    """
    Intelligently route to Single-Pass Turbo Engine (<= 250 slides) or Chunked Engine (> 250 slides).
    Single-pass eliminates 100% redundant re-encoding passes and renders 171+ slides in minutes.
    """
    images = sort_images(image_paths)
    if not images:
        raise ValueError("No images provided")

    if len(images) > 250:
        print(f"📦 [Render Router] Large slideshow ({len(images)} slides > 250) -> Chunked Engine")
        render_video_chunked(
            images=images,
            audio_path=audio_path,
            output_path=output_path,
            settings=settings,
            progress_callback=progress_callback,
            subtitle_path=subtitle_path
        )
    else:
        print(f"🚀 [Render Router] Slideshow ({len(images)} slides <= 250) -> Single-Pass Turbo Engine")
        render_video_single_pass(
            image_paths=images,
            audio_path=audio_path,
            output_path=output_path,
            settings=settings,
            progress_callback=progress_callback,
            subtitle_path=subtitle_path
        )
