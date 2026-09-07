"""
tests/test_pipeline_replay.py
Comprehensive test suite for AutoEdit V2 Pipeline Replay Harness.
"""
import os
import sys
import pytest
from PIL import Image

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.dev_tools.pipeline_replay import PipelineReplayHarness
from core.subtitles.models import SpeechWordTimestamp
from tests.fixtures.golden import GoldenCorpus


def _create_dummy_image(path: str, size=(1080, 1920)):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    img = Image.new("RGB", size, color=(30, 60, 90))
    img.save(path)


def test_replay_from_asr_short_vi(tmp_path):
    harness = PipelineReplayHarness(cache_dir=str(tmp_path / ".cache"))
    fixture = GoldenCorpus.get_fixture("GOLDEN_SHORT_VI")

    # Create dummy images
    img_paths = [str(tmp_path / f"vi_{i}.png") for i in range(len(fixture.images))]
    for p in img_paths:
        _create_dummy_image(p)

    speech_words = [
        SpeechWordTimestamp(
            word=w.word,
            start=w.start,
            end=w.end,
            confidence=w.confidence,
            original_index=w.original_index,
        )
        for w in fixture.words
    ]

    # Run 1: Cache Miss
    res1 = harness.replay_from_asr(
        script_text=fixture.script,
        speech_words=speech_words,
        images=img_paths,
        audio_duration_s=fixture.audio_duration_s,
        draft_target_dir=str(tmp_path / "draft_run1"),
        use_cache=True,
    )
    assert res1.aligned_tokens is not None and len(res1.aligned_tokens) > 0
    assert len(res1.subtitles) > 0
    assert res1.edit_plan is not None
    assert len(res1.edit_plan.clips) > 0
    assert res1.normalized_draft is not None
    assert res1.manifest.stages[0].cache_hit is False

    # Run 2: Cache Hit on alignment and subtitle
    res2 = harness.replay_from_asr(
        script_text=fixture.script,
        speech_words=speech_words,
        images=img_paths,
        audio_duration_s=fixture.audio_duration_s,
        draft_target_dir=str(tmp_path / "draft_run2"),
        use_cache=True,
    )
    assert res2.manifest.stages[0].cache_hit is True
    assert res2.manifest.stages[1].cache_hit is True


def test_replay_from_srt(tmp_path):
    harness = PipelineReplayHarness(cache_dir=str(tmp_path / ".cache"))

    srt_content = """1
00:00:01,000 --> 00:00:03,500
Xin chào Việt Nam.

2
00:00:04,000 --> 00:00:06,500
Hôm nay thời tiết rất đẹp.
"""
    img_paths = [str(tmp_path / f"img_{i}.png") for i in range(2)]
    for p in img_paths:
        _create_dummy_image(p)

    res = harness.replay_from_srt(
        srt_content_or_path=srt_content,
        images=img_paths,
        draft_target_dir=str(tmp_path / "draft_srt"),
    )
    assert res.edit_plan is not None
    assert len(res.edit_plan.clips) >= 2
    assert res.normalized_draft["id"] == "NORMALIZED_DRAFT_ID"


def test_replay_from_editplan(tmp_path):
    harness = PipelineReplayHarness(cache_dir=str(tmp_path / ".cache"))
    img_path = str(tmp_path / "single.png")
    _create_dummy_image(img_path)

    from core.edit_plan import EditPlan, EditPlanProject, EditPlanClip
    from core.rule_engine import MOTION_ZOOM_IN

    proj = EditPlanProject(name="DirectEditPlan", width=1080, height=1920, fps=60.0, duration_us=5000000)
    clip = EditPlanClip(clip_id="c1", media_path=img_path, media_type="image", start_us=0, duration_us=5000000, motion_type=MOTION_ZOOM_IN)
    plan = EditPlan(project=proj, clips=[clip])

    res = harness.replay_from_editplan(
        edit_plan=plan,
        draft_target_dir=str(tmp_path / "draft_ep"),
    )
    assert res.normalized_draft is not None
    assert len(res.normalized_draft["tracks"][0]["segments"]) == 1


def test_replay_manifest_telemetry(tmp_path):
    harness = PipelineReplayHarness(cache_dir=str(tmp_path / ".cache"))
    fixture = GoldenCorpus.get_fixture("GOLDEN_SHORT_KO")

    img_paths = [str(tmp_path / f"ko_{i}.png") for i in range(len(fixture.images))]
    for p in img_paths:
        _create_dummy_image(p)

    speech_words = [
        SpeechWordTimestamp(
            word=w.word,
            start=w.start,
            end=w.end,
            confidence=w.confidence,
            original_index=w.original_index,
        )
        for w in fixture.words
    ]

    res = harness.replay_from_asr(
        script_text=fixture.script,
        speech_words=speech_words,
        images=img_paths,
        audio_duration_s=fixture.audio_duration_s,
        draft_target_dir=str(tmp_path / "draft_ko"),
    )
    m = res.manifest.to_dict()
    assert "stages" in m
    assert len(m["stages"]) == 4
    assert m["total_duration_ms"] > 0
    stage_names = [s["stage_name"] for s in m["stages"]]
    assert stage_names == ["alignment", "subtitle_segmenter", "timeline_builder", "capcut_adapter"]


def test_replay_long_01_offline(tmp_path):
    """
    Reality trace test: Replay the entire 29m47s LONG_01 dataset offline
    through Hierarchical Alignment, Subtitle Segmentation, TimelineBuilder,
    CapCutAdapter, and DraftNormalizer in seconds without Whisper or CapCut.
    """
    harness = PipelineReplayHarness(cache_dir=str(tmp_path / ".cache"))
    fixture = GoldenCorpus.get_fixture("GOLDEN_LONG_01")

    # Fast creation of dummy images (10 images reused)
    dummy_imgs = [str(tmp_path / f"anh_kb{i:03d}.png") for i in range(1, 11)]
    for p in dummy_imgs:
        _create_dummy_image(p, size=(32, 32))

    speech_words = [
        SpeechWordTimestamp(
            word=w.word,
            start=w.start,
            end=w.end,
            confidence=w.confidence,
            original_index=w.original_index,
        )
        for w in fixture.words
    ]

    # Replay from ASR
    res = harness.replay_from_asr(
        script_text=fixture.script,
        speech_words=speech_words,
        images=dummy_imgs,
        audio_duration_s=fixture.audio_duration_s,
        draft_target_dir=str(tmp_path / "draft_long01"),
        use_cache=True,
    )

    assert res.edit_plan is not None
    assert res.normalized_draft is not None
    assert res.normalized_draft["id"] == "NORMALIZED_DRAFT_ID"
    # Verify execution took only a few seconds, not 165s!
    assert res.manifest.total_duration_ms < 10000.0
