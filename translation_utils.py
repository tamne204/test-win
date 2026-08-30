"""
translation_utils.py
Automatic translation engine for scripts and subtitles.
Supports fast batch translation from Korean (ko), Chinese (zh), English (en), etc., to Vietnamese (vi)
while preserving exact timestamps (start, end, id).
"""
from __future__ import annotations

import json
import re
import html
import urllib.request
import urllib.parse
from typing import List, Tuple, Dict, Any, Optional, Union, Callable, Set


def translate_text(text: str, source_lang: str = 'auto', target_lang: str = 'vi') -> str:
    """Translate a single text string using Google Translate with multiple resilient fallbacks."""
    if not text or not text.strip():
        return text

    # Tier 1: dict-chrome-ex endpoint
    try:
        url = (
            f"https://translate.googleapis.com/translate_a/single?client=dict-chrome-ex&sl={urllib.parse.quote(source_lang)}&tl={urllib.parse.quote(target_lang)}&dt=t&q="
            + urllib.parse.quote(text)
        )
        req = urllib.request.Request(
            url,
            headers={
                "User-Agent": (
                    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
                )
            },
        )
        with urllib.request.urlopen(req, timeout=8) as response:
            result = json.loads(response.read().decode("utf-8"))
            translated = "".join([part[0] for part in result[0] if part and part[0]])
            if translated:
                return html.unescape(translated)
    except Exception:
        pass

    # Tier 2: Google Web M endpoint
    try:
        url_m = f"https://translate.google.com/m?sl={urllib.parse.quote(source_lang)}&tl={urllib.parse.quote(target_lang)}&q=" + urllib.parse.quote(text)
        req_m = urllib.request.Request(
            url_m,
            headers={
                "User-Agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36"
            },
        )
        with urllib.request.urlopen(req_m, timeout=8) as resp_m:
            html_content = resp_m.read().decode("utf-8")
            match = re.search(r'<div class="result-container">(.*?)</div>', html_content)
            if match:
                return html.unescape(match.group(1))
    except Exception:
        pass

    # Fallback to original text if all endpoints fail
    return text


def translate_subtitles_batch(
    subtitles: List[Dict[str, Any]],
    source_lang: str = 'auto',
    target_lang: str = 'vi',
    progress_callback=None
) -> List[Dict[str, Any]]:
    """
    Translates a list of subtitle objects [{'id': 1, 'start': 0.0, 'end': 2.5, 'text': '...'}]
    Preserves exact start, end, and id.
    """
    if not subtitles:
        return []

    translated_subs = []
    total = len(subtitles)

    BATCH_SIZE = 20
    DELIMITER = "\n---SEG---\n"

    for i in range(0, total, BATCH_SIZE):
        batch = subtitles[i : i + BATCH_SIZE]
        texts = [s.get('text', '').strip() for s in batch]
        joined_text = DELIMITER.join(texts)

        translated_joined = translate_text(joined_text, source_lang=source_lang, target_lang=target_lang)
        translated_texts = translated_joined.split("---SEG---")

        for idx, sub in enumerate(batch):
            orig_text = sub.get('text', '')
            if idx < len(translated_texts) and translated_texts[idx].strip():
                trans_text = translated_texts[idx].strip()
            else:
                trans_text = translate_text(orig_text, source_lang=source_lang, target_lang=target_lang)

            if not trans_text:
                trans_text = orig_text

            translated_subs.append({
                'id': sub.get('id', len(translated_subs) + 1),
                'start': sub.get('start', 0.0),
                'end': sub.get('end', 0.0),
                'text': trans_text,
                'original_text': orig_text
            })

        if progress_callback:
            pct = int(min(100, (i + len(batch)) / total * 100))
            progress_callback(pct, f"Đã dịch {min(total, i + BATCH_SIZE)}/{total} câu...")

    return translated_subs


def translate_script_json(script_data: Any, source_lang: str = 'auto', target_lang: str = 'vi') -> Tuple[Any, List[Dict[str, Any]]]:
    """
    Translates a script JSON object (with 'scenes' list) or raw text.
    Returns (translated_json, translated_scenes_list).
    """
    if isinstance(script_data, str):
        try:
            parsed = json.loads(script_data)
        except Exception:
            trans_text = translate_text(script_data, source_lang=source_lang, target_lang=target_lang)
            return trans_text, []
    else:
        parsed = script_data

    if isinstance(parsed, dict) and 'scenes' in parsed:
        scenes = parsed['scenes']
        subs_to_trans = []
        for idx, sc in enumerate(scenes):
            txt = sc.get('subtitles') or sc.get('voice_text') or sc.get('text') or ''
            subs_to_trans.append({'id': idx + 1, 'start': 0.0, 'end': 0.0, 'text': txt})

        translated = translate_subtitles_batch(subs_to_trans, source_lang=source_lang, target_lang=target_lang)
        
        for idx, trans in enumerate(translated):
            if idx < len(scenes):
                scenes[idx]['translated_subtitles'] = trans['text']
                scenes[idx]['original_subtitles'] = trans['original_text']
                scenes[idx]['subtitles'] = trans['text']

        parsed['language'] = target_lang
        return parsed, translated

    return parsed, []