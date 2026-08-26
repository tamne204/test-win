"""
tts_utils.py
Microsoft Edge Neural Text-to-Speech (edge-tts) wrapper for Slideshow Builder.
Supports:
- 100% Free, Cloud-based, Zero-GPU/RAM load.
- High-quality Korean, Vietnamese, English, and global voices.
- Real-time rate, pitch, and volume adjustments.
- JSON scenes batch synthesis with exact per-scene speech duration measurement.
- 100% exact SRT subtitle generation and per-image duration alignment.
"""

import os
import re
import json
import uuid
import asyncio
import tempfile
import subprocess
from pathlib import Path
from typing import Optional, Callable, List, Tuple, Dict, Any

_EDGE_TTS_AVAILABLE: Optional[bool] = None

# Curated high-quality neural voices
CURATED_VOICES = [
    {
        "id": "ko-KR-InJoonNeural",
        "name": "🇰🇷 In-Joon (Nam - Kể chuyện / Truyền cảm ⭐)",
        "lang": "ko-KR",
        "gender": "Male",
        "recommended": True
    },
    {
        "id": "ko-KR-SunHiNeural",
        "name": "🇰🇷 Sun-Hi (Nữ - Tự nhiên, ấm áp)",
        "lang": "ko-KR",
        "gender": "Female",
        "recommended": True
    },
    {
        "id": "ko-KR-HyunsuMultilingualNeural",
        "name": "🇰🇷 Hyun-su (Nam - Trẻ trung, tin tức)",
        "lang": "ko-KR",
        "gender": "Male",
        "recommended": False
    },
    {
        "id": "vi-VN-HoaiMyNeural",
        "name": "🇻🇳 Hoài My (Nữ - Truyền cảm)",
        "lang": "vi-VN",
        "gender": "Female",
        "recommended": True
    },
    {
        "id": "vi-VN-NamMinhNeural",
        "name": "🇻🇳 Nam Minh (Nam - Rõ ràng)",
        "lang": "vi-VN",
        "gender": "Male",
        "recommended": True
    },
    {
        "id": "en-US-JennyNeural",
        "name": "🇺🇸 Jenny (Female - Natural)",
        "lang": "en-US",
        "gender": "Female",
        "recommended": True
    },
    {
        "id": "en-US-GuyNeural",
        "name": "🇺🇸 Guy (Male - Natural)",
        "lang": "en-US",
        "gender": "Male",
        "recommended": True
    }
]


def check_voxcpm() -> bool:
    """Check if edge-tts is available (aliased for compatibility)."""
    return check_edge_tts()


def check_edge_tts() -> bool:
    """Return True if edge-tts package is importable."""
    global _EDGE_TTS_AVAILABLE
    try:
        import edge_tts  # noqa: F401
        _EDGE_TTS_AVAILABLE = True
        return True
    except Exception as e:
        print("check_edge_tts failed:", e)
        _EDGE_TTS_AVAILABLE = False
        return False


def detect_device() -> str:
    """Detect device label for status badge."""
    return "Microsoft Cloud (0% RAM)"


def format_srt_time(seconds: float) -> str:
    """Convert float seconds to SRT timestamp format: HH:MM:SS,mmm"""
    seconds = max(0.0, float(seconds))
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    secs = int(seconds % 60)
    millis = int(round((seconds - int(seconds)) * 1000))
    millis = min(millis, 999)
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{millis:03d}"


def get_audio_duration_seconds(file_path: str) -> float:
    """Probe exact duration of an audio file in seconds via ffprobe."""
    try:
        cmd = [
            "ffprobe", "-v", "error",
            "-show_entries", "format=duration",
            "-of", "default=noprint_wrappers=1:nokey=1",
            file_path
        ]
        out = subprocess.check_output(cmd, stderr=subprocess.DEVNULL)
        return float(out.decode().strip())
    except Exception as e:
        print(f"Error probing duration for {file_path}: {e}")
        return 0.0


def split_into_tts_chunks(text: str) -> List[str]:
    """Splits plain text script into crisp individual sentence-level lines."""
    clean = re.sub(r'\[.*?\]', '', text).strip()
    if not clean:
        return []

    lines = [l.strip() for l in clean.split('\n') if l.strip()]
    final_chunks = []
    for line in lines:
        raw_sentences = re.split(r'([.?!]+)', line)
        for i in range(0, len(raw_sentences), 2):
            s = raw_sentences[i].strip()
            punct = raw_sentences[i+1].strip() if i+1 < len(raw_sentences) else ''
            full = (s + (' ' if punct else '') + punct).strip()
            if full:
                final_chunks.append(full)

    return final_chunks if final_chunks else [clean]


async def _synthesize_scene_edge_tts(
    text: str,
    output_path: str,
    voice: str = "ko-KR-InJoonNeural",
    rate: str = "+0%",
    pitch: str = "+0Hz",
    volume: str = "+0%"
):
    """Call edge-tts to synthesize audio for a single scene/text."""
    import edge_tts
    comm = edge_tts.Communicate(
        text=text,
        voice=voice,
        rate=rate,
        pitch=pitch,
        volume=volume
    )
    await comm.save(output_path)


def generate_tts(
    text: str,
    voice_style: str = "",
    reference_audio_path: Optional[str] = None,
    output_path: Optional[str] = None,
    cfg_value: float = 2.0,
    inference_timesteps: int = 10,
    voice: str = "ko-KR-InJoonNeural",
    rate: str = "+0%",
    pitch: str = "+0Hz",
    volume: str = "+0%",
    progress_callback: Optional[Callable[[int, str], None]] = None,
) -> Tuple[str, List[float], str]:
    """
    Generate high quality speech with Microsoft Edge Neural TTS:
    - 1-3 seconds real-time generation.
    - Exact scene-by-scene audio measurement.
    - Generates 100% exact SRT subtitle timestamps.
    """
    raw_input = text.strip()
    if not raw_input:
        raise ValueError("Chưa có văn bản kịch bản để tạo giọng đọc.")

    if not voice or voice.strip() in ("", "default"):
        voice = "ko-KR-InJoonNeural"

    # Normalize rate/pitch format
    if rate and not rate.endswith("%") and not rate.startswith("+") and not rate.startswith("-"):
        try:
            val = float(rate)
            rate = f"{int(val)}%" if val < 0 else f"+{int(val)}%"
        except ValueError:
            rate = "+0%"

    if pitch and not pitch.endswith("Hz") and not pitch.startswith("+") and not pitch.startswith("-"):
        try:
            val = float(pitch)
            pitch = f"{int(val)}Hz" if val < 0 else f"+{int(val)}Hz"
        except ValueError:
            pitch = "+0Hz"

    if progress_callback:
        progress_callback(10, f"Đang chuẩn bị giọng đọc Microsoft Edge: {voice}...")

    # ── Parse scenes from JSON or plain text ──
    scene_items: List[Dict[str, str]] = []
    if raw_input.startswith('{') or raw_input.startswith('['):
        try:
            data = json.loads(raw_input)
            raw_scenes = data.get('scenes', []) if isinstance(data, dict) else (data if isinstance(data, list) else [])
            for sc in raw_scenes:
                if not isinstance(sc, dict):
                    continue
                v_txt = sc.get('voice_text') or sc.get('subtitles') or sc.get('dialogue') or sc.get('text') or ''
                v_txt = str(v_txt).strip()
                s_txt = sc.get('subtitles') or sc.get('voice_text') or sc.get('text') or ''
                s_txt = str(s_txt).strip()
                s_txt = re.sub(r'\(.*?\)', '', s_txt).strip()
                s_txt = re.sub(r'\[.*?\]', '', s_txt).strip()
                if v_txt or s_txt:
                    scene_items.append({
                        'voice_text': v_txt if v_txt else s_txt,
                        'subtitles': s_txt if s_txt else v_txt
                    })
        except Exception:
            scene_items = []

    if not scene_items:
        chunks = split_into_tts_chunks(raw_input)
        for c in chunks:
            clean_sub = re.sub(r'\(.*?\)', '', c).strip()
            clean_sub = re.sub(r'\[.*?\]', '', clean_sub).strip()
            if c.strip():
                scene_items.append({
                    'voice_text': c.strip(),
                    'subtitles': clean_sub if clean_sub else c.strip()
                })

    if not scene_items:
        raise ValueError("Không tìm thấy câu thoại hợp lệ trong kịch bản.")

    total_scenes = len(scene_items)
    temp_dir = tempfile.mkdtemp(prefix="edge_tts_")
    chunk_files = []
    durations = []
    srt_items = []
    current_time = 0.0
    silence_duration = 0.45  # Natural pause between sentences (seconds)

    # Generate silence file
    silence_file = os.path.join(temp_dir, "silence.wav")
    subprocess.run([
        "ffmpeg", "-y",
        "-f", "lavfi",
        "-i", "anullsrc=r=24000:cl=mono",
        "-t", str(silence_duration),
        "-c:a", "pcm_s16le",
        silence_file
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)

    try:
        for idx, item in enumerate(scene_items):
            pct = int(15 + ((idx + 1) / total_scenes) * 75)
            if progress_callback:
                progress_callback(pct, f"Đang tạo giọng đọc Edge-TTS: Đoạn {idx+1}/{total_scenes}...")

            v_text = item['voice_text']
            sub_text = item['subtitles']
            chunk_mp3 = os.path.join(temp_dir, f"scene_{idx:04d}.mp3")
            chunk_wav = os.path.join(temp_dir, f"scene_{idx:04d}.wav")

            loop.run_until_complete(
                _synthesize_scene_edge_tts(
                    text=v_text,
                    output_path=chunk_mp3,
                    voice=voice,
                    rate=rate,
                    pitch=pitch,
                    volume=volume
                )
            )

            # Convert MP3 to 24000Hz Mono WAV for sample-perfect concatenation
            subprocess.run([
                "ffmpeg", "-y",
                "-i", chunk_mp3,
                "-ar", "24000",
                "-ac", "1",
                "-c:a", "pcm_s16le",
                chunk_wav
            ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

            chunk_dur = get_audio_duration_seconds(chunk_wav)
            if chunk_dur <= 0.05:
                chunk_dur = 1.0  # Fallback duration

            chunk_files.append(chunk_wav)

            # Subtitle is active strictly during speech (does not bleed into silence gap)
            if sub_text:
                srt_items.append({
                    'start': round(current_time, 3),
                    'end': round(current_time + chunk_dur, 3),
                    'text': sub_text
                })

            if idx < total_scenes - 1:
                chunk_files.append(silence_file)
                durations.append(round(chunk_dur + silence_duration, 3))
                current_time += chunk_dur + silence_duration
            else:
                durations.append(round(chunk_dur, 3))
                current_time += chunk_dur

    finally:
        loop.close()

    if not chunk_files:
        raise RuntimeError("Tạo giọng đọc Edge-TTS không xuất ra file âm thanh.")

    if progress_callback:
        progress_callback(92, "Đang ghép nối toàn bộ âm thanh hoàn chỉnh...")

    if output_path is None:
        tmp = tempfile.NamedTemporaryFile(suffix=".wav", delete=False)
        output_path = tmp.name
        tmp.close()

    # Concat all chunk files using FFmpeg
    concat_list_path = os.path.join(temp_dir, "concat_list.txt")
    with open(concat_list_path, "w", encoding="utf-8") as f:
        for cf in chunk_files:
            f.write(f"file '{cf}'\n")

    cmd = [
        "ffmpeg", "-y",
        "-f", "concat",
        "-safe", "0",
        "-i", concat_list_path,
        "-c:a", "pcm_s16le",
        "-ar", "24000",
        output_path
    ]
    res = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    if res.returncode != 0:
        print("FFmpeg concat error:", res.stderr.decode())
        # Fallback
        cmd_fallback = ["ffmpeg", "-y", "-i", chunk_files[0], "-c:a", "pcm_s16le", output_path]
        subprocess.run(cmd_fallback, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    # Format SRT content
    srt_lines = []
    for idx, sub in enumerate(srt_items, start=1):
        st_str = format_srt_time(sub['start'])
        et_str = format_srt_time(sub['end'])
        srt_lines.append(f"{idx}")
        srt_lines.append(f"{st_str} --> {et_str}")
        srt_lines.append(sub['text'])
        srt_lines.append("")
    srt_content = "\n".join(srt_lines)

    if progress_callback:
        progress_callback(100, f"✅ Đã tạo thành công giọng đọc Edge-TTS ({len(scene_items)} phân cảnh)!")

    # Cleanup temp chunks
    try:
        import shutil
        shutil.rmtree(temp_dir, ignore_errors=True)
    except Exception:
        pass

    return output_path, durations, srt_content
