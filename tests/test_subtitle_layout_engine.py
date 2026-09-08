"""
tests/test_subtitle_layout_engine.py
Unit and integration tests for SubtitleLayoutEngine in 2TOOLNE AutoEdit V2.

Verifies:
- Short Korean sentence
- Very long Korean sentence
- Vietnamese
- English
- Mixed punctuation
- Long unbroken word / no spaces
- 16:9, 9:16, 1:1 aspect ratios
- Progressive font scale reduction (down to 85%)
- Strict SUBTITLE_LAYOUT_OVERFLOW == 0 enforcement
- A0 SubtitleCue.text and timing invariance
- Full evaluation on GOLDEN_LONG_01 (641 captions)
"""

from __future__ import annotations

import json
import os
import sys
import pytest
from typing import List

# Ensure apps/capcut-v2 is in python path
V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.subtitles.layout_engine import (
    LayoutOptions,
    CaptionLayoutResult,
    FontMetricsManager,
    SubtitleLayoutEngine,
    SubtitleLayoutValidator,
)
from core.subtitles.models import SubtitleCue
from core.edit_plan import EditPlanCaption
from core.timeline_builder import TimelineBuilder
from core.preset_manager import RulePreset
from adapters.capcut.version_9_3 import CapCutVersionAdapter_9_3


# --- Test Cases ---

def test_short_korean_sentence():
    """Short Korean sentence fits in 1 line without wrapping."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    res = engine.layout_caption("안녕하세요 반갑습니다.")
    assert res.line_count == 1
    assert "\n" not in res.display_text
    assert res.font_scale == 1.0
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px


def test_very_long_korean_sentence():
    """Very long Korean sentence wraps into 2 balanced lines, preferring punctuation break."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    text = "방금까지 누군가 있었다는 사실이, 오히려 더 없어진 것처럼 느껴지게 만들었습니다."
    res = engine.layout_caption(text)
    assert res.line_count == 2
    assert "\n" in res.display_text
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px
    lines = res.display_text.split("\n")
    assert len(lines) == 2
    # Verify line balancing: difference between line lengths should be moderate
    w1 = engine.metrics.measure_text(lines[0], res.font_size_px)
    w2 = engine.metrics.measure_text(lines[1], res.font_size_px)
    assert abs(w1 - w2) < 300.0  # Visually balanced


def test_vietnamese_with_diacritics():
    """Vietnamese with combining marks and complex diacritics wraps cleanly."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    text = "Xin chào tất cả các bạn đến với kênh YouTube của chúng tôi ngày hôm nay!"
    res = engine.layout_caption(text)
    assert res.line_count == 2
    assert "\n" in res.display_text
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px


def test_english_sentence():
    """English sentence wraps cleanly within safe area."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    text = "This is a clean English sentence designed to verify that subtitle layout engine works reliably."
    res = engine.layout_caption(text)
    assert res.line_count == 2
    assert "\n" in res.display_text
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px


def test_mixed_punctuation_wrapping():
    """Text with commas, periods, exclamation, semicolons prefers breaking after punctuation."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    text = "First clause has finished; now we transition smoothly to the second clause."
    res = engine.layout_caption(text)
    assert res.line_count == 2
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px
    lines = res.display_text.split("\n")
    # First line should ideally break near the semicolon
    assert lines[0].endswith(";") or ";" in lines[0]


def test_long_word_without_spaces():
    """Unbroken word or long token falls back to grapheme-boundary splitting without overflow."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    unbroken = "슈퍼칼리프래질리스틱익스피알리도셔스매우매우긴단어테스트"
    res = engine.layout_caption(unbroken)
    assert res.line_count == 2
    assert "\n" in res.display_text
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px


def test_aspect_ratios_16_9_and_9_16_and_1_1():
    """Tests 16:9, 9:16, and 1:1 canvas widths."""
    text = "방금까지 누군가 있었다는 사실이, 오히려 더 없어진 것처럼 느껴지게 만들었습니다."
    
    # 9:16 (1080x1920) -> safe_w = 885.6px -> needs 2 lines
    eng_9_16 = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, canvas_height=1920))
    res_9_16 = eng_9_16.layout_caption(text)
    assert res_9_16.line_count == 2
    assert res_9_16.max_line_width_px <= eng_9_16.options.safe_width_px

    # 1:1 (1080x1080) -> safe_w = 885.6px -> needs 2 lines
    eng_1_1 = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, canvas_height=1080))
    res_1_1 = eng_1_1.layout_caption(text)
    assert res_1_1.line_count == 2
    assert res_1_1.max_line_width_px <= eng_1_1.options.safe_width_px

    # 16:9 (1920x1080) -> safe_w = 1574.4px -> fits in 1 line!
    eng_16_9 = SubtitleLayoutEngine(LayoutOptions(canvas_width=1920, canvas_height=1080))
    res_16_9 = eng_16_9.layout_caption(text)
    assert res_16_9.line_count == 1
    assert res_16_9.max_line_width_px <= eng_16_9.options.safe_width_px


def test_progressive_font_scale_reduction():
    """Text that exceeds safe width in 2 lines at 1.0 font scale triggers progressive font reduction."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82, min_font_scale=0.85))
    # Dense text requiring scale reduction to fit in 2 lines
    text = "This is an extremely long and detailed sentence intentionally written to require font scale reduction to fit in two lines."
    res = engine.layout_caption(text)
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px
    assert res.font_scale < 1.0
    assert res.font_scale >= 0.85


def test_extreme_paragraph_zero_overflow():
    """Even a massive paragraph or continuous monolithic string guarantees SUBTITLE_LAYOUT_OVERFLOW == 0."""
    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    massive = "A" * 120
    res = engine.layout_caption(massive)
    assert not res.overflow
    assert res.max_line_width_px <= engine.options.safe_width_px
    assert res.font_scale >= 0.85


def test_a0_subtitle_cue_invariance():
    """Verifies that A0 SubtitleCue.text and timing properties are 100% untouched."""
    cue = SubtitleCue(
        index=1,
        start_s=10.5,
        end_s=14.2,
        text="방금까지 누군가 있었다는 사실이, 오히려 더 없어진 것처럼 느껴지게 만들었습니다.",
    )
    original_text = cue.text
    original_start = cue.start_s
    original_end = cue.end_s
    original_start_us = cue.start_us
    original_duration_us = cue.duration_us

    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080))
    res = engine.layout_caption(cue.text)

    # Assert SubtitleCue attributes remain completely immutable
    assert cue.text == original_text
    assert cue.start_s == original_start
    assert cue.end_s == original_end
    assert cue.start_us == original_start_us
    assert cue.duration_us == original_duration_us

    # Display text has wrapping, but original cue does not
    assert "\n" in res.display_text
    assert "\n" not in cue.text


def test_timeline_builder_and_adapter_integration():
    """Verifies TimelineBuilder assigns display_text and font_scale, and CapCut adapter serializes alignment & line_max_width."""
    cues = [
        SubtitleCue(
            index=1,
            start_s=0.0,
            end_s=2.0,
            text="안녕하세요 반갑습니다.",
        ),
        SubtitleCue(
            index=2,
            start_s=2.0,
            end_s=6.0,
            text="방금까지 누군가 있었다는 사실이, 오히려 더 없어진 것처럼 느껴지게 만들었습니다.",
        ),
    ]

    preset = RulePreset(
        id="test_preset",
        name="Test",
        description="Test Preset",
        canvas_ratio="9:16",
        caption_font_size=8.0,
        scene_duration_s=2.0,
    )
    import tempfile
    from PIL import Image

    with tempfile.TemporaryDirectory() as tmp_dir:
        img_path = os.path.join(tmp_dir, "test.png")
        Image.new("RGB", (100, 100), color="blue").save(img_path)

        builder = TimelineBuilder(preset=preset)
        plan = builder.build(
            project_name="SubtitleLayoutTest",
            images=[img_path, img_path, img_path],
            subtitle_cues=cues,
            timing_mode="SRT_DRIVEN",
        )

        assert len(plan.captions) == 2
        # Caption 1: short -> 1 line
        assert plan.captions[0].text == "안녕하세요 반갑습니다."
        assert plan.captions[0].display_text == "안녕하세요 반갑습니다."
        assert plan.captions[0].line_count == 1
        assert plan.captions[0].font_scale == 1.0

        # Caption 2: long -> 2 lines
        assert plan.captions[1].text == "방금까지 누군가 있었다는 사실이, 오히려 더 없어진 것처럼 느껴지게 만들었습니다."
        assert "\n" in plan.captions[1].display_text
        assert plan.captions[1].line_count == 2
        assert plan.captions[1].font_scale <= 1.0

        # Test CapCut adapter serialization
        draft_dir = os.path.join(tmp_dir, "draft")
        res = CapCutVersionAdapter_9_3.generate_draft(
            edit_plan=plan,
            target_dir=draft_dir,
            draft_root_path=tmp_dir,
        )
        draft_info_file = res["draft_info_file"]
        with open(draft_info_file, "r", encoding="utf-8") as f:
            draft = json.load(f)
        texts = draft.get("materials", {}).get("texts", [])
        assert len(texts) == 2
        for t in texts:
            assert t.get("alignment") == 1
            assert t.get("line_max_width") == 0.82
            payload = json.loads(t["content"])
            assert "text" in payload
            assert "styles" in payload
            assert len(payload["styles"]) > 0
            assert payload["styles"][0]["size"] > 0



def test_golden_long_01_metrics():
    """
    Evaluates SubtitleLayoutEngine on GOLDEN_LONG_01 (641 captions).
    Confirms:
    OVERFLOW_BEFORE = 135
    OVERFLOW_AFTER = 0
    A0_SUBTITLE_UNCHANGED = YES
    """
    cache_path = "reports/accuracy/a0/long_01_subtitles_cache.json"
    if not os.path.isfile(cache_path):
        pytest.skip(f"Golden cache fixture not found: {cache_path}")

    with open(cache_path, "r", encoding="utf-8") as f:
        cues_data = json.load(f)

    cues = [
        SubtitleCue(
            index=c.get("index", idx),
            start_s=c["start_s"],
            end_s=c["end_s"],
            text=c["text"],
        )
        for idx, c in enumerate(cues_data)
    ]
    assert len(cues) == 641, f"Expected 641 captions, found {len(cues)}"

    engine = SubtitleLayoutEngine(LayoutOptions(canvas_width=1080, safe_width_ratio=0.82))
    safe_w = engine.options.safe_width_px

    # Compute baseline overflow before layout
    overflow_before = sum(
        1 for c in cues if engine.metrics.measure_text(c.text, engine.options.base_font_size_px) > safe_w
    )
    assert overflow_before == 135, f"Expected OVERFLOW_BEFORE == 135, got {overflow_before}"

    # Perform layout
    results: List[CaptionLayoutResult] = []
    for c in cues:
        res = engine.layout_caption(c.text)
        results.append(res)

    # Validate results using SubtitleLayoutValidator
    val = SubtitleLayoutValidator.validate_captions(results, safe_w, engine.metrics)

    assert val["caption_count"] == 641
    assert val["SUBTITLE_LAYOUT_OVERFLOW"] == 0
    assert val["overflow_count"] == 0
    assert val["max_lines"] <= 2

    # Verify A0 cues were completely unchanged
    for orig, res in zip(cues, results):
        assert orig.text == res.original_text
        assert "\n" not in orig.text

    print("\n--- GOLDEN_LONG_01 METRICS ---")
    print(f"CAPTION_COUNT = {val['caption_count']}")
    print(f"CAPTIONS_WRAPPED = {val['captions_wrapped']}")
    print(f"CAPTIONS_FONT_REDUCED = {val['captions_font_reduced']}")
    print(f"MAX_LINES = {val['max_lines']}")
    print(f"OVERFLOW_BEFORE = {overflow_before}")
    print(f"OVERFLOW_AFTER = {val['SUBTITLE_LAYOUT_OVERFLOW']}")
    print(f"A0_SUBTITLE_UNCHANGED = YES")
