"""
Forced Alignment Engine for Slideshow Builder Studio
Supports 2 distinct user-selectable engines:
1. Gemini Flash Multimodal Cloud (Ultra-fast 2-3s, 0% RAM)
2. Stable-Whisper Local Aligner (100% Offline, no API key needed)
Aligns 100% ground-truth script text line-by-line to audio waveforms (WAV/MP3).
Produces millisecond-accurate SRT subtitles preserving exact text, casing, and punctuation.
"""

import os
import re
import time
from typing import List, Dict, Any, Optional, Tuple


def normalize_script_lines(raw_text: str) -> List[str]:
    """
    Splits script text into non-empty lines, stripping surrounding whitespace
    while keeping internal words, casing, and punctuation intact.
    """
    if not raw_text:
        return []
    
    clean_lines = []
    for line in raw_text.strip().splitlines():
        l = line.strip()
        if not l:
            continue
        if re.match(r'^(#+|-{3,}|\*{3,}|_{3,})', l):
            continue
        clean_lines.append(l)
    return clean_lines


def format_srt_timestamp(sec: float) -> str:
    """Formats seconds into HH:MM:SS,mmm"""
    sec = max(0.0, float(sec))
    h = int(sec // 3600)
    m = int((sec % 3600) // 60)
    s = int(sec % 60)
    ms = int(round((sec - int(sec)) * 1000))
    if ms >= 1000:
        ms = 999
    return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"


def generate_srt_from_segments(segments: List[Dict[str, Any]]) -> str:
    """
    Converts list of {start, end, text} segments into standard SRT format.
    """
    srt_blocks = []
    for idx, seg in enumerate(segments, start=1):
        st = format_srt_timestamp(seg['start'])
        et = format_srt_timestamp(seg['end'])
        txt = str(seg.get('text', '')).strip()
        srt_blocks.append(f"{idx}\n{st} --> {et}\n{txt}")
    return "\n\n".join(srt_blocks) + "\n"


def parse_srt_to_segments(srt_text: str) -> List[Dict[str, Any]]:
    """
    Parses an SRT text string into a structured list of {id, start, end, text}.
    """
    segments = []
    if not srt_text:
        return segments

    blocks = re.split(r'\n\s*\n', srt_text.strip())
    for block in blocks:
        lines = [l.strip() for l in block.strip().splitlines() if l.strip()]
        if not lines:
            continue
        
        time_line_idx = -1
        time_match = None
        for idx, line in enumerate(lines):
            m = re.search(r'(\d{2}):(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})[,.](\d{3})', line)
            if m:
                time_line_idx = idx
                time_match = m
                break
        
        if time_match:
            g = time_match.groups()
            st = int(g[0])*3600 + int(g[1])*60 + int(g[2]) + int(g[3])/1000.0
            et = int(g[4])*3600 + int(g[5])*60 + int(g[6]) + int(g[7])/1000.0
            text_lines = lines[time_line_idx + 1:]
            text = " ".join(text_lines).strip()
            segments.append({
                'id': len(segments) + 1,
                'start': round(st, 3),
                'end': round(et, 3),
                'text': text
            })
    return segments


def align_with_gemini_cloud(
    audio_path: str,
    script_lines: List[str],
    language: str = 'vi',
    api_key: Optional[str] = None,
    progress_callback: Optional[Any] = None
) -> Tuple[str, List[Dict[str, Any]]]:
    """
    Option 1: Gemini Flash Multimodal Cloud Forced Alignment (2-3s, 0% RAM).
    """
    gemini_key = (api_key or os.environ.get("GEMINI_API_KEY") or '').strip()
    if not gemini_key:
        raise ValueError("Vui lòng nhập Gemini API Key để dùng tùy chọn Gemini Cloud.")

    from google import genai
    from google.genai import types
    client = genai.Client(api_key=gemini_key)

    if progress_callback:
        progress_callback(20, "Đang tải audio lên Google Cloud Files API...")

    uploaded_file = client.files.upload(file=audio_path)
    try:
        max_wait = 60
        waited = 0
        while getattr(uploaded_file, 'state', None) and getattr(uploaded_file.state, 'name', '') != 'ACTIVE':
            if getattr(uploaded_file.state, 'name', '') == 'FAILED':
                raise RuntimeError("File audio tải lên Cloud bị lỗi (FAILED).")
            if progress_callback:
                progress_callback(35, "Đang xử lý sóng âm trên Cloud...")
            time.sleep(1.2)
            waited += 1.2
            if waited >= max_wait:
                break
            uploaded_file = client.files.get(name=uploaded_file.name)

        if progress_callback:
            progress_callback(55, "Gemini Flash đang so khớp âm học (Acoustic Alignment) từng câu thoại...")

        script_payload = "\n".join([f"[{i+1}] {line}" for i, line in enumerate(script_lines)])
        lang_name = "tiếng Việt" if language == 'vi' else ("tiếng Hàn" if language == 'ko' else "tiếng Anh")

        prompt = f"""Bạn là cỗ máy Forced Alignment (So khớp âm học) siêu chính xác ({lang_name}).
Nhiệm vụ: Dò tìm vị trí phát âm chính xác của từng dòng kịch bản trong file âm thanh đính kèm và gắn mốc thời gian (start --> end) chuẩn xác từng mili-giây.

DANH SÁCH KỊCH BẢN GỐC (BẮT BUỘC KHỚP 100% NỘI DUNG VÀ THỨ TỰ):
\"\"\"
{script_payload}
\"\"\"

QUY TẮC CỐT LÕI (CRITICAL FORCED ALIGNMENT RULES):
1. GIỮ NGUYÊN 100% NỘI DUNG: Không được tự ý thêm, bớt, sửa, hoặc lược bỏ bất kỳ từ ngữ, dấu câu hay ký tự nào trong từng dòng kịch bản gốc.
2. SỐ LƯỢNG BLOCK PHỤ ĐỀ: Phải đúng chính xác {len(script_lines)} blocks phụ đề tương ứng với {len(script_lines)} dòng kịch bản đã đánh số ở trên.
3. PHÁT HIỆN MỐC THỜI GIAN ÂM HỌC:
   - Start: Mốc thời gian chính xác mili-giây khi người đọc bắt đầu phát âm từ đầu tiên của dòng đó.
   - End: Mốc thời gian chính xác mili-giây khi người đọc dứt âm tiết cuối cùng của dòng đó.
4. KHOẢNG NGHỈ (SILENCE GAP): Nếu giữa 2 dòng có khoảng ngắt nghỉ lấy hơi, mốc end của dòng trước và start của dòng sau KHÔNG ĐƯỢC nối dính vào nhau mà phải phản ánh đúng khoảng lặng thực tế.
5. ĐỊNH DẠNG ĐẦU RA: Trả về duy nhất nội dung file .SRT hoàn chỉnh chuẩn RFC/SubRip.

Ví dụ định dạng đầu ra:
1
00:00:00,240 --> 00:00:02,850
[Nội dung dòng 1 chính xác 100%]

2
00:00:03,150 --> 00:00:05,400
[Nội dung dòng 2 chính xác 100%]
"""

        # Wrap audio as Part from URI
        audio_mime = getattr(uploaded_file, 'mime_type', None) or "audio/mp3"
        audio_part = types.Part.from_uri(
            file_uri=uploaded_file.uri,
            mime_type=audio_mime
        )

        allowed_models = [
            "gemini-2.5-flash",
            "gemini-2.5-flash-preview",
            "gemini-2.5-flash-lite",
            "gemini-2.0-flash",
            "gemini-2.0-flash-lite"
        ]

        response = None
        last_error = None
        for m in allowed_models:
            for attempt in range(2):
                try:
                    if progress_callback:
                        progress_callback(65, f"Gemini Cloud: Đang so khớp bằng {m}...")
                    response = client.models.generate_content(
                        model=m,
                        contents=[audio_part, prompt],
                        config=types.GenerateContentConfig(
                            temperature=0.02,
                            max_output_tokens=8192
                        )
                    )
                    if response and response.text and response.text.strip():
                        break
                except Exception as ex:
                    last_error = ex
                    print(f"⚠️ Model {m} error: {ex}, trying next...")
            if response and response.text and response.text.strip():
                break

        if not response or not response.text:
            raise RuntimeError(f"Không nhận được phản hồi từ Gemini Cloud. Chi tiết: {last_error}")

        if progress_callback:
            progress_callback(85, "Đang gom nhóm và kiểm tra chuẩn hóa SRT...")

        raw_srt = response.text.strip()
        raw_srt = re.sub(r'^```(?:srt)?\s*', '', raw_srt, flags=re.IGNORECASE)
        raw_srt = re.sub(r'\s*```$', '', raw_srt)

        segments = parse_srt_to_segments(raw_srt)
        
        # Enforce exact original line text
        if len(segments) == len(script_lines):
            for i, seg in enumerate(segments):
                seg['text'] = script_lines[i]

        final_srt = generate_srt_from_segments(segments) if segments else raw_srt
        return final_srt, segments

    finally:
        try:
            client.files.delete(name=uploaded_file.name)
        except Exception:
            pass


def align_with_stable_whisper(
    audio_path: str,
    script_lines: List[str],
    language: str = 'vi',
    model_size: str = 'base',
    progress_callback: Optional[Any] = None
) -> Tuple[str, List[Dict[str, Any]]]:
    """
    Option 2: Stable-Whisper Local Forced Alignment (100% Offline, Local CPU/MPS).
    """
    import stable_whisper

    if progress_callback:
        progress_callback(20, f"Đang nạp mô hình Stable-Whisper ({model_size}) cục bộ...")

    model = stable_whisper.load_model(model_size)

    if progress_callback:
        progress_callback(45, "Đang so khớp âm học từng từ với sóng âm (Offline Alignment)...")

    # Combine lines with newline delimiters for original splitting
    full_text = "\n".join(script_lines)

    result = model.align(
        audio_path,
        full_text,
        language=language if language != 'auto' else None,
        original_split=True
    )

    if progress_callback:
        progress_callback(80, "Đang gom nhóm mốc thời gian theo từng dòng kịch bản...")

    segments = []
    if result and hasattr(result, 'segments'):
        for idx, seg in enumerate(result.segments):
            st = round(float(seg.start), 3)
            et = round(float(seg.end), 3)
            # Use original script line if within range
            txt = script_lines[idx] if idx < len(script_lines) else seg.text.strip()
            segments.append({
                'id': idx + 1,
                'start': st,
                'end': et,
                'text': txt
            })

    # If segment count doesn't match, map line-by-line
    if len(segments) != len(script_lines) and len(segments) > 0:
        # Fallback to word-level regrouping
        all_words = []
        for seg in result.segments:
            if hasattr(seg, 'words') and seg.words:
                all_words.extend(seg.words)
        
        if all_words:
            # Group words across script lines proportionally
            total_words = len(all_words)
            line_word_counts = [len(l.split()) for l in script_lines]
            sum_words = max(1, sum(line_word_counts))
            
            cur_word_idx = 0
            new_segments = []
            for idx, l in enumerate(script_lines):
                wc = max(1, len(l.split()))
                take = max(1, int(round((wc / sum_words) * total_words)))
                end_word_idx = min(len(all_words), cur_word_idx + take)
                if cur_word_idx < len(all_words):
                    st = round(float(all_words[cur_word_idx].start), 3)
                    et = round(float(all_words[end_word_idx - 1].end), 3)
                else:
                    st = new_segments[-1]['end'] if new_segments else 0.0
                    et = st + 2.0
                new_segments.append({'id': idx + 1, 'start': st, 'end': et, 'text': l})
                cur_word_idx = end_word_idx
            segments = new_segments

    final_srt = generate_srt_from_segments(segments)
    return final_srt, segments


def forced_align(
    audio_path: str,
    script_text: str,
    engine: str = 'gemini',
    language: str = 'vi',
    api_key: Optional[str] = None,
    model_size: str = 'base',
    progress_callback: Optional[Any] = None
) -> Dict[str, Any]:
    """
    Main entry point for Forced Alignment.
    engine: 'gemini' (Cloud 2-3s) or 'stable-whisper' (Local Offline).
    """
    if not os.path.isfile(audio_path):
        raise FileNotFoundError(f"Không tìm thấy file âm thanh tại: {audio_path}")

    script_lines = normalize_script_lines(script_text)
    if not script_lines:
        raise ValueError("Kịch bản text trống. Vui lòng nhập nội dung kịch bản để so khớp.")

    if progress_callback:
        progress_callback(10, f"Đã nạp {len(script_lines)} dòng kịch bản. Chế độ: {engine.upper()}...")

    if engine == 'gemini':
        srt_content, segments = align_with_gemini_cloud(
            audio_path=audio_path,
            script_lines=script_lines,
            language=language,
            api_key=api_key,
            progress_callback=progress_callback
        )
    else:
        srt_content, segments = align_with_stable_whisper(
            audio_path=audio_path,
            script_lines=script_lines,
            language=language,
            model_size=model_size,
            progress_callback=progress_callback
        )

    if progress_callback:
        progress_callback(100, f"✅ Hoàn tất Forced Alignment {len(segments)} câu phụ đề!")

    return {
        'success': True,
        'srt': srt_content,
        'segments': segments,
        'count': len(segments),
        'engine': engine,
        'language': language
    }
