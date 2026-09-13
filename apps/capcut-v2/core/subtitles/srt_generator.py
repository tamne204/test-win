"""
apps/capcut-v2/core/subtitles/srt_generator.py
Generates and validates standard UTF-8 SubRip (.srt) subtitle files.
Preserves Vietnamese diacritics, CJK characters, and Unicode punctuation.
"""
from __future__ import annotations

import re
from typing import List, Tuple

from .models import SubtitleCue


def format_srt_timestamp(seconds: float) -> str:
    """
    Format a timestamp in seconds to standard SRT format 'HH:MM:SS,mmm'.
    """
    total_ms = int(round(max(0.0, seconds) * 1000))
    hours = total_ms // 3_600_000
    minutes = (total_ms % 3_600_000) // 60_000
    secs = (total_ms % 60_000) // 1000
    millis = total_ms % 1000
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{millis:03d}"


def generate_srt(cues: List[SubtitleCue]) -> str:
    """
    Serialize a list of SubtitleCue objects into standard UTF-8 SRT format.
    """
    if not cues:
        return ""

    blocks: List[str] = []
    for i, cue in enumerate(cues, start=1):
        time_line = f"{format_srt_timestamp(cue.start_s)} --> {format_srt_timestamp(cue.end_s)}"
        text = cue.text.strip()
        block = f"{i}\n{time_line}\n{text}\n"
        blocks.append(block)

    return "\n".join(blocks) + "\n"


def validate_srt_content(srt_text: str) -> Tuple[bool, List[str]]:
    """
    Validate an SRT formatted string for structural and timing correctness.

    Returns:
        (is_valid, list_of_errors_or_warnings)
    """
    if not srt_text or not srt_text.strip():
        return False, ["SRT content is empty"]

    errors: List[str] = []
    blocks = re.split(r"\n\s*\n", srt_text.strip())

    timecode_pattern = re.compile(
        r"^(\d{2}:\d{2}:\d{2}[,\.]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[,\.]\d{3})$"
    )

    prev_end_ms = -1

    for idx, block in enumerate(blocks, start=1):
        lines = [l.strip() for l in block.splitlines() if l.strip()]
        if len(lines) < 2:
            errors.append(f"Block {idx}: incomplete SRT entry (less than 2 lines).")
            continue

        # Check entry index
        try:
            entry_idx = int(lines[0])
            if entry_idx != idx:
                errors.append(f"Block {idx}: expected index {idx}, got {entry_idx}.")
        except ValueError:
            errors.append(f"Block {idx}: non-numeric cue index '{lines[0]}'.")

        # Check timecode line
        tc_line = lines[1]
        m = timecode_pattern.match(tc_line)
        if not m:
            errors.append(f"Block {idx}: invalid timecode format '{tc_line}'.")
            continue

        start_str, end_str = m.group(1), m.group(2)
        start_ms = _parse_timestamp_ms(start_str)
        end_ms = _parse_timestamp_ms(end_str)

        if start_ms >= end_ms:
            errors.append(f"Block {idx}: cue start ({start_ms}ms) >= end ({end_ms}ms).")

        if prev_end_ms >= 0 and start_ms < prev_end_ms:
            errors.append(f"Block {idx}: cue start ({start_ms}ms) overlaps previous end ({prev_end_ms}ms).")

        prev_end_ms = end_ms

        # Check text
        text = " ".join(lines[2:]).strip()
        if not text:
            errors.append(f"Block {idx}: empty subtitle text.")

    return (len(errors) == 0, errors)


def _parse_timestamp_ms(ts_str: str) -> int:
    """Parse 'HH:MM:SS,mmm' or 'HH:MM:SS.mmm' into milliseconds."""
    clean = ts_str.strip().replace(".", ",")
    parts = clean.split(",")
    hms = parts[0].split(":")
    h, m, s = int(hms[0]), int(hms[1]), int(hms[2])
    ms = int(parts[1].ljust(3, "0")[:3])
    return (h * 3600 + m * 60 + s) * 1000 + ms
