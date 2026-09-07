"""
tests/test_a0_paragraph_mapping.py
Unit tests for Milestone M0-E: Paragraph Hard Boundary & Scene Mapping (ERR-A0-02 Resolution).
"""
import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

import pytest
from core.subtitles.models import (
    ScriptToken,
    AlignedToken,
    ConfidenceLevel,
    MatchType,
    SubtitleCue,
)
from core.subtitles.script_normalizer import tokenize_script
from core.subtitles.subtitle_segmenter import SubtitleSegmenter
from core.srt_timeline import (
    SubtitleEntry,
    compute_script_paragraphs_scene_boundaries,
    compute_script_paragraphs_scene_boundaries_v2,
)


def test_hard_paragraph_boundary_in_segmenter():
    """
    INVIOLABLE RULE: A subtitle cue may NOT cross a strong paragraph boundary.
    When token[i].paragraph_id != token[i+1].paragraph_id, segmenter MUST break cue.
    """
    script = "Đoạn một rất ngắn.\n\nĐoạn hai cũng rất ngắn."
    tokens = tokenize_script(script, language="vi")

    # Construct aligned tokens spanning both paragraphs
    aligned = []
    for i, t in enumerate(tokens):
        aligned.append(
            AlignedToken(
                script_token=t,
                start_s=i * 0.5,
                end_s=(i + 1) * 0.5,
                confidence=ConfidenceLevel.HIGH,
                match_type=MatchType.EXACT,
                token_confidence=0.95,
            )
        )

    segmenter = SubtitleSegmenter()
    cues = segmenter.segment(aligned)

    assert len(cues) >= 2
    # Verify every cue contains tokens from EXACTLY ONE paragraph
    for c in cues:
        assert len(c.paragraph_ids) == 1, f"Cue {c.index} crossed paragraph boundary: {c.paragraph_ids}"
        # All tokens in cue must have identical paragraph_id
        p_ids = {t.script_token.paragraph_id for t in c.tokens}
        assert len(p_ids) == 1


def test_single_paragraph_splits_into_multiple_cues_stays_in_same_scene():
    """
    CRITICAL RESOLUTION FOR ERR-A0-02:
    A single long paragraph splitting into 4 subtitle cues must ALL remain
    grouped in Scene 0. It must NOT desynchronize paragraph indexing!
    """
    script = (
        "Đoạn văn thứ nhất là một đoạn văn rất dài và chi tiết, được phân tách thành nhiều câu phụ đề khác nhau để người xem có thể đọc kịp trên màn hình video.\n\n"
        "Đoạn văn thứ hai ngắn hơn rất nhiều."
    )
    tokens = tokenize_script(script, language="vi")
    p0_tokens = [t for t in tokens if t.paragraph_id == 0]
    p1_tokens = [t for t in tokens if t.paragraph_id == 1]

    aligned = []
    t_curr = 0.0
    for t in tokens:
        aligned.append(
            AlignedToken(
                script_token=t,
                start_s=round(t_curr, 2),
                end_s=round(t_curr + 0.4, 2),
                confidence=ConfidenceLevel.HIGH,
                match_type=MatchType.EXACT,
                token_confidence=0.92,
            )
        )
        t_curr += 0.4

    segmenter = SubtitleSegmenter()
    cues = segmenter.segment(aligned)

    # Paragraph 0 should have split into multiple cues (> 1)
    p0_cues = [c for c in cues if 0 in c.paragraph_ids]
    p1_cues = [c for c in cues if 1 in c.paragraph_ids]
    assert len(p0_cues) >= 2, f"Paragraph 0 should produce multiple cues, got {len(p0_cues)}"
    assert len(p1_cues) >= 1

    # Map to scenes
    scenes = compute_script_paragraphs_scene_boundaries_v2(subtitles=cues, script_text=script)

    # Must produce exactly 2 scenes: Scene 0 for Paragraph 0, Scene 1 for Paragraph 1!
    assert len(scenes) == 2, f"Expected 2 scenes, got {len(scenes)}"
    assert len(scenes[0].subtitles) == len(p0_cues), "Scene 0 must contain ALL cues belonging to Paragraph 0"
    assert len(scenes[1].subtitles) == len(p1_cues), "Scene 1 must contain ALL cues belonging to Paragraph 1"


def test_backward_compatible_with_existing_test_case():
    """
    Verify existing baseline test from test_capcut_v2_core.py continues to pass 100%.
    """
    script = "Câu 1A\nCâu 1B\n\nCâu 2A\n\nCâu 3A\nCâu 3B"
    subtitles = [
        SubtitleEntry(1, 0, 2000000, "Câu 1A"),
        SubtitleEntry(2, 2000000, 4000000, "Câu 1B"),
        SubtitleEntry(3, 4000000, 7000000, "Câu 2A"),
        SubtitleEntry(4, 7000000, 9000000, "Câu 3A"),
        SubtitleEntry(5, 9000000, 11000000, "Câu 3B"),
    ]
    scenes = compute_script_paragraphs_scene_boundaries(script, subtitles)
    assert len(scenes) == 3
    # Scene 1 contains 2 subtitles
    assert len(scenes[0].subtitles) == 2
    assert scenes[0].start_us == 0
    assert scenes[0].duration_us == 4000000
    # Scene 2 contains 1 subtitle
    assert len(scenes[1].subtitles) == 1
    assert scenes[1].start_us == 4000000
    assert scenes[1].duration_us == 3000000
    # Scene 3 contains 2 subtitles
    assert len(scenes[2].subtitles) == 2
    assert scenes[2].start_us == 7000000
    assert scenes[2].duration_us == 4000000
