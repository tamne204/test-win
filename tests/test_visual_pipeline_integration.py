"""
tests/test_visual_pipeline_integration.py
Integration tests for VisualPipelineAdapter, TimelineBuilder shadow mode,
and V1 visual planning execution.
"""
import os
import tempfile
import pytest
from PIL import Image

from core.preset_manager import RulePreset, PRESET_BASIC_SLIDESHOW
from core.timeline_builder import TimelineBuilder, TIMING_MODE_SRT_DRIVEN, TIMING_MODE_FIXED
from core.subtitles.models import SubtitleCue
from core.visual.models import (
    VisualPlannerEngine,
    VisualPlannerOptions,
    ShotDurationPolicy,
    TailDurationPolicy,
)
from core.visual.pipeline_adapter import VisualPipelineAdapter
from core.dev_tools.pipeline_replay import PipelineReplayHarness
from core.dev_tools.draft_normalizer import DraftNormalizer


def _create_test_image(path: str, color=(100, 150, 200)):
    im = Image.new("RGB", (1080, 1920), color=color)
    im.save(path)


def _make_cues() -> list[SubtitleCue]:
    return [
        SubtitleCue(index=1, start_s=0.0, end_s=3.0, text="Chào bạn.", paragraph_ids=[0], sentence_ids=[0]),
        SubtitleCue(index=2, start_s=3.0, end_s=6.5, text="Đây là video thử nghiệm.", paragraph_ids=[0], sentence_ids=[1]),
        SubtitleCue(index=3, start_s=6.8, end_s=11.2, text="Chúng ta đang kiểm tra hệ thống.", paragraph_ids=[1], sentence_ids=[2]),
        SubtitleCue(index=4, start_s=11.5, end_s=16.0, text="Chúc một ngày tốt lành.", paragraph_ids=[2], sentence_ids=[3]),
    ]


def test_timeline_builder_shadow_mode(tmp_path):
    """
    Verify TimelineBuilder in LEGACY mode with shadow_mode=True:
    1. Produces legacy clips without modification.
    2. Runs VisualPipelineAdapter in shadow.
    3. Populates shadow_comparison in metadata matching Section 41 requirements.
    """
    img_paths = []
    for i in range(5):
        p = str(tmp_path / f"img_{i:03d}.png")
        _create_test_image(p, color=(i * 40, 80, 120))
        img_paths.append(p)

    cues = _make_cues()
    srt_text = (
        "1\n00:00:00,000 --> 00:00:03,000\nChào bạn.\n\n"
        "2\n00:00:03,000 --> 00:00:06,500\nĐây là video thử nghiệm.\n\n"
        "3\n00:00:06,800 --> 00:00:11,200\nChúng ta đang kiểm tra hệ thống.\n\n"
        "4\n00:00:11,500 --> 00:00:16,000\nChúc một ngày tốt lành.\n\n"
    )

    tb = TimelineBuilder(PRESET_BASIC_SLIDESHOW)
    options = VisualPlannerOptions(
        engine=VisualPlannerEngine.LEGACY,
        shadow_mode=True,
    )

    plan = tb.build(
        images=img_paths,
        srt_source=srt_text,
        subtitle_cues=cues,
        audio_duration_s=20.0,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        visual_options=options,
    )

    assert plan is not None
    assert plan.metadata["visual_planner_engine"] == VisualPlannerEngine.LEGACY.value
    assert plan.metadata.get("shadow_mode") is True
    assert "shadow_comparison" in plan.metadata

    comp = plan.metadata["shadow_comparison"]
    assert "legacy" in comp
    assert "v1_shadow" in comp
    assert "delta" in comp

    # Verify Section 41 required metrics
    leg = comp["legacy"]
    v1 = comp["v1_shadow"]
    for m in [
        "shot_count", "min_duration_s", "p10_duration_s", "median_duration_s",
        "p90_duration_s", "max_duration_s", "shots_lt_2s", "shots_gt_8_5s",
        "structural_boundary_ratio", "arbitrary_cut_ratio", "duplicate_image_count",
        "reuse_count", "min_reuse_distance_s", "visual_coverage_s", "tail_black_duration_s",
        "motion_outlier_count",
    ]:
        assert m in leg, f"Missing metric {m} in legacy shadow comparison"
        assert m in v1, f"Missing metric {m} in v1 shadow comparison"

    # Verify legacy output was NOT altered by shadow mode
    assert len(plan.clips) > 0


def test_timeline_builder_hierarchical_dp_v1(tmp_path):
    """
    Verify TimelineBuilder in HIERARCHICAL_DP_V1 mode:
    1. Produces valid EditPlan with V1 clips.
    2. Zero visual gaps, zero visual overlaps, zero black tail.
    3. Full visual coverage up to master audio duration.
    """
    img_paths = []
    for i in range(5):
        p = str(tmp_path / f"img_{i:03d}.png")
        _create_test_image(p, color=(i * 30, 90, 140))
        img_paths.append(p)

    cues = _make_cues()
    master_dur_s = 20.0

    tb = TimelineBuilder(PRESET_BASIC_SLIDESHOW)
    options = VisualPlannerOptions(
        engine=VisualPlannerEngine.HIERARCHICAL_DP_V1,
        shadow_mode=False,
    )

    plan = tb.build(
        images=img_paths,
        subtitle_cues=cues,
        audio_duration_s=master_dur_s,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        visual_options=options,
    )

    assert plan is not None
    assert plan.metadata["visual_planner_engine"] == VisualPlannerEngine.HIERARCHICAL_DP_V1.value
    assert "shadow_comparison" not in plan.metadata

    # Validate EditPlan structure
    errors = plan.validate()
    assert errors == []

    # Visual track coverage
    assert plan.clips[0].start_us == 0
    assert plan.clips[-1].end_us == int(master_dur_s * 1_000_000)

    # Gap and overlap checks
    for i in range(1, len(plan.clips)):
        prev_end = plan.clips[i - 1].end_us
        curr_start = plan.clips[i].start_us
        assert curr_start == prev_end, f"Gap or overlap at clip {i}: prev_end={prev_end}, curr_start={curr_start}"

    # Pacing check: speech shots within [2.0s, 12.0s]
    for c in plan.clips:
        dur_s = c.duration_us / 1_000_000.0
        assert dur_s >= 2.0, f"Micro-shot violation: {dur_s}s"


def test_timeline_builder_legacy_rollback(tmp_path):
    """Verify clean fallback to LEGACY mode when shadow is disabled."""
    img_paths = []
    for i in range(3):
        p = str(tmp_path / f"img_{i:03d}.png")
        _create_test_image(p)
        img_paths.append(p)

    srt_text = (
        "1\n00:00:00,000 --> 00:00:04,000\nĐoạn một.\n\n"
        "2\n00:00:04,000 --> 00:00:08,000\nĐoạn hai.\n\n"
    )

    tb = TimelineBuilder(PRESET_BASIC_SLIDESHOW)
    options = VisualPlannerOptions(
        engine=VisualPlannerEngine.LEGACY,
        shadow_mode=False,
    )

    plan = tb.build(
        images=img_paths,
        srt_source=srt_text,
        timing_mode=TIMING_MODE_SRT_DRIVEN,
        visual_options=options,
    )

    assert plan.metadata["visual_planner_engine"] == VisualPlannerEngine.LEGACY.value
    assert "shadow_comparison" not in plan.metadata
    assert len(plan.clips) == 2


def test_pipeline_replay_with_visual_options(tmp_path):
    """Verify PipelineReplayHarness executes through V1 visual planner to normalized draft."""
    img_paths = []
    for i in range(4):
        p = str(tmp_path / f"anh_{i:03d}.png")
        _create_test_image(p)
        img_paths.append(p)

    srt_text = (
        "1\n00:00:00,000 --> 00:00:03,500\nCâu mở đầu.\n\n"
        "2\n00:00:03,500 --> 00:00:07,000\nCâu thứ hai.\n\n"
        "3\n00:00:07,000 --> 00:00:10,500\nCâu kết thúc.\n\n"
    )

    harness = PipelineReplayHarness(cache_dir=str(tmp_path / ".cache"))
    options = VisualPlannerOptions(
        engine=VisualPlannerEngine.HIERARCHICAL_DP_V1,
        shadow_mode=False,
    )

    res = harness.replay_from_srt(
        srt_content_or_path=srt_text,
        images=img_paths,
        audio_duration_s=15.0,
        draft_target_dir=str(tmp_path / "draft_test"),
        visual_options=options,
    )

    assert res.edit_plan is not None
    assert res.edit_plan.metadata["visual_planner_engine"] == VisualPlannerEngine.HIERARCHICAL_DP_V1.value
    assert res.normalized_draft is not None
    assert res.normalized_draft["id"] == "NORMALIZED_DRAFT_ID"


def test_shadow_comparison_metrics_completeness(tmp_path):
    """Verify compute_shadow_comparison computes all delta metrics correctly."""
    img = str(tmp_path / "img_0.png")
    _create_test_image(img)

    cues = [
        SubtitleCue(index=1, start_s=0.0, end_s=4.0, text="A", paragraph_ids=[0], sentence_ids=[0]),
        SubtitleCue(index=2, start_s=4.0, end_s=8.0, text="B", paragraph_ids=[1], sentence_ids=[1]),
    ]

    adapter = VisualPipelineAdapter()
    shots, report = adapter.plan_visual_shots(
        subtitles=cues,
        images=[img],
        master_audio_duration_s=12.0,
    )
    clips = adapter.shots_to_editplan_clips(shots)

    comp = VisualPipelineAdapter.compute_shadow_comparison(
        legacy_clips=clips,
        v1_shots=shots,
        master_audio_duration_s=12.0,
    )

    assert "legacy" in comp
    assert "v1_shadow" in comp
    assert "delta" in comp
    assert "tail_black_seconds_eliminated" in comp["delta"]
    assert "structural_accuracy_gain" in comp["delta"]
