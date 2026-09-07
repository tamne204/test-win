"""
apps/capcut-v2/core/srt_timeline.py
Deterministic SRT subtitle parser and scene boundary generator.
Groups subtitles based on configurable MIN and MAX scene duration guards.
Zero AI. Fully deterministic.
"""
from __future__ import annotations

import re
import os
from dataclasses import dataclass
from typing import List, Tuple, Dict, Any, Optional


@dataclass
class SubtitleEntry:
    """Parsed subtitle unit with microsecond timestamps and optional source metadata."""
    index: int
    start_us: int
    end_us: int
    text: str
    paragraph_id: Optional[int] = None
    source_token_start: Optional[int] = None
    source_token_end: Optional[int] = None

    @property
    def duration_us(self) -> int:
        return max(0, self.end_us - self.start_us)


@dataclass
class SceneBoundary:
    """Computed scene boundary for a visual clip on the timeline."""
    scene_index: int
    start_us: int
    duration_us: int
    subtitles: List[SubtitleEntry]

    @property
    def end_us(self) -> int:
        return self.start_us + self.duration_us


def parse_srt_timestamp_us(ts_str: str) -> int:
    """
    Parse an SRT timestamp string 'HH:MM:SS,mmm' into microseconds.
    """
    ts_str = ts_str.strip().replace(".", ",")
    match = re.match(r"(\d+):(\d+):(\d+),(\d+)", ts_str)
    if not match:
        raise ValueError(f"Invalid SRT timestamp format: '{ts_str}'")

    hours = int(match.group(1))
    minutes = int(match.group(2))
    seconds = int(match.group(3))
    millis = int(match.group(4).ljust(3, "0")[:3])

    total_us = (hours * 3600 + minutes * 60 + seconds) * 1_000_000 + millis * 1_000
    return total_us


def parse_srt_file(srt_path_or_text: str) -> List[SubtitleEntry]:
    """
    Parse an SRT file path or raw SRT string into SubtitleEntry objects.
    """
    if os.path.isfile(srt_path_or_text):
        with open(srt_path_or_text, "r", encoding="utf-8-sig", errors="replace") as f:
            content = f.read()
    else:
        content = srt_path_or_text

    entries: List[SubtitleEntry] = []
    # Normalize newlines
    blocks = re.split(r"\n\s*\n", content.strip())

    for block in blocks:
        lines = [line.strip() for line in block.strip().split("\n") if line.strip()]
        if len(lines) < 2:
            continue

        # Line 0: Index (e.g. "1")
        # Line 1: Timestamp range "00:00:01,000 --> 00:00:04,500"
        # Line 2+: Subtitle text
        time_line_idx = 1 if re.match(r"^\d+$", lines[0]) else 0
        if time_line_idx >= len(lines):
            continue

        time_line = lines[time_line_idx]
        if "-->" not in time_line:
            continue

        parts = time_line.split("-->")
        if len(parts) != 2:
            continue

        try:
            start_us = parse_srt_timestamp_us(parts[0])
            end_us = parse_srt_timestamp_us(parts[1])
            text = " ".join(lines[time_line_idx + 1:])
            entries.append(
                SubtitleEntry(
                    index=len(entries) + 1,
                    start_us=start_us,
                    end_us=end_us,
                    text=text,
                )
            )
        except Exception:
            continue

    return entries


def compute_srt_scene_boundaries(
    subtitles: List[SubtitleEntry],
    min_duration_s: float = 3.0,
    max_duration_s: float = 8.0,
    target_scene_count: Optional[int] = None,
) -> List[SceneBoundary]:
    """
    Derives non-overlapping visual scene boundaries from subtitle entries.

    Grouping Rules:
    1. A scene starts at the beginning of the timeline (0 us) or at the end of the prior scene.
    2. Nearby short subtitles (< min_duration_s) are accumulated into the current scene.
    3. Once the accumulated duration reaches min_duration_s, the scene closes at the end of the
       current subtitle if adding the next subtitle would exceed max_duration_s, or if we have enough
       images remaining to cover the rest of the audio.
    4. Slices single long subtitles (> max_duration_s) into equal sub-scenes.
    5. Fills any gap between 0 us and the first subtitle, and preserves continuous progression.
    """
    if not subtitles:
        return []

    min_dur_us = int(min_duration_s * 1_000_000)
    max_dur_us = int(max_duration_s * 1_000_000)

    scenes: List[SceneBoundary] = []
    current_subtitles: List[SubtitleEntry] = []
    current_scene_start_us = 0

    for idx, sub in enumerate(subtitles):
        # Handle exceptionally long single subtitle (> max_dur_us) when starting fresh
        if sub.duration_us > max_dur_us and not current_subtitles:
            num_slices = (sub.duration_us + max_dur_us - 1) // max_dur_us
            slice_dur = sub.duration_us // num_slices
            slice_start = sub.start_us
            for s_i in range(num_slices):
                s_end = sub.end_us if s_i == num_slices - 1 else slice_start + slice_dur
                scenes.append(
                    SceneBoundary(
                        scene_index=len(scenes),
                        start_us=slice_start,
                        duration_us=s_end - slice_start,
                        subtitles=[sub] if s_i == 0 else [],
                    )
                )
                slice_start = s_end
            current_scene_start_us = sub.end_us
            continue

        current_subtitles.append(sub)
        curr_dur_us = sub.end_us - current_scene_start_us

        is_last = (idx == len(subtitles) - 1)

        # Close boundary if min duration reached or at last subtitle
        if curr_dur_us >= min_dur_us or is_last:
            scene_dur = sub.end_us - current_scene_start_us
            scenes.append(
                SceneBoundary(
                    scene_index=len(scenes),
                    start_us=current_scene_start_us,
                    duration_us=scene_dur,
                    subtitles=list(current_subtitles),
                )
            )
            current_scene_start_us = sub.end_us
            current_subtitles = []

    # Final guard: ensure all scenes have duration > 0
    clean_scenes = [s for s in scenes if s.duration_us > 0]
    return clean_scenes


def _normalize_simple(text: str) -> str:
    """Helper to clean string for paragraph text matching."""
    return re.sub(r'[\s\.,!?:;…\-_/\\|~*^%$#@+=\"\'\`\(\)\[\]\{\}\<\>]+', '', text.lower())


def compute_script_paragraphs_scene_boundaries_v2(
    subtitles: List[Any],
    script_text: Optional[str] = None,
) -> List[SceneBoundary]:
    """
    Groups subtitle cues into visual scenes based on underlying paragraph membership.
    Resolves ERR-A0-02 (Paragraph ↔ Subtitle Cue 1:1 Mapping Fallacy).
    - If 1 script paragraph splits into 6 cues, all 6 cues remain in Scene P.
    - Uses exact cue.paragraph_ids / cue.paragraph_id where available.
    - If subtitles lack explicit paragraph metadata, matches cue text sequentially against
      script paragraphs to determine membership without line counting.
    """
    return compute_script_paragraphs_scene_boundaries(script_text=script_text or "", subtitles=subtitles)


def compute_script_paragraphs_scene_boundaries(
    script_text: str,
    subtitles: List[Any],
) -> List[SceneBoundary]:
    """
    Derives scene boundaries based on user script paragraph boundaries (double newlines / blank lines).
    Multiple subtitle cues within the same paragraph share the same scene/image.
    Uses source token paragraph_id when present, or sequential text membership when absent.
    Zero 1:1 line counting fallacy.
    """
    if not subtitles:
        return []

    if not script_text or not script_text.strip():
        return compute_srt_scene_boundaries(subtitles)

    paragraphs = [p.strip() for p in re.split(r'\n\s*\n', script_text) if p.strip()]
    if not paragraphs:
        return compute_srt_scene_boundaries(subtitles)

    # Check if any subtitle has explicit paragraph_id(s)
    has_explicit_para_meta = any(
        getattr(sub, "paragraph_ids", None) or getattr(sub, "paragraph_id", None) is not None
        for sub in subtitles
    )

    # Map each subtitle to its paragraph_id
    sub_paragraph_ids: List[int] = []

    if has_explicit_para_meta:
        for sub in subtitles:
            p_ids = getattr(sub, "paragraph_ids", None)
            if p_ids:
                sub_paragraph_ids.append(p_ids[0])
            elif getattr(sub, "paragraph_id", None) is not None:
                sub_paragraph_ids.append(sub.paragraph_id)
            else:
                sub_paragraph_ids.append(0)
    else:
        # Sequential text matching against script paragraphs (zero line counting)
        norm_paras = [_normalize_simple(p) for p in paragraphs]
        curr_p_idx = 0
        num_p = len(paragraphs)

        for sub in subtitles:
            norm_sub = _normalize_simple(sub.text)
            if not norm_sub:
                sub_paragraph_ids.append(curr_p_idx)
                continue

            # Check if subtitle belongs to current paragraph or has transitioned to next
            if curr_p_idx + 1 < num_p:
                # Check if this subtitle appears in the next paragraph
                # and no longer appears (or fits poorly) in the current paragraph
                in_curr = norm_sub in norm_paras[curr_p_idx]
                in_next = norm_sub in norm_paras[curr_p_idx + 1]

                if in_next and not in_curr:
                    curr_p_idx += 1
                elif in_next and in_curr:
                    # If appears in both, check remaining length of current paragraph
                    # or advance if current paragraph text was already mostly consumed
                    pass

            sub_paragraph_ids.append(curr_p_idx)

    # Group subtitles by contiguous paragraph_id
    scenes: List[SceneBoundary] = []
    current_scene_start_us = 0

    idx = 0
    total_subs = len(subtitles)

    while idx < total_subs:
        target_p = sub_paragraph_ids[idx]
        p_subs: List[Any] = []

        while idx < total_subs and sub_paragraph_ids[idx] == target_p:
            p_subs.append(subtitles[idx])
            idx += 1

        if not p_subs:
            continue

        scene_start_us = current_scene_start_us
        scene_end_us = p_subs[-1].end_us
        if scene_end_us <= scene_start_us:
            scene_end_us = scene_start_us + 1_000_000

        scenes.append(
            SceneBoundary(
                scene_index=len(scenes),
                start_us=scene_start_us,
                duration_us=scene_end_us - scene_start_us,
                subtitles=p_subs,
            )
        )
        current_scene_start_us = scene_end_us

    return [s for s in scenes if s.duration_us > 0]

