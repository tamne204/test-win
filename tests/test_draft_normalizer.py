"""
tests/test_draft_normalizer.py
Unit and integration tests for DraftNormalizer and canonical snapshot testing.
"""
import copy
import json
import os
import sys
import tempfile
import pytest
from PIL import Image

V2_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "apps", "capcut-v2"))
if V2_DIR not in sys.path:
    sys.path.append(V2_DIR)

from core.dev_tools.draft_normalizer import DraftNormalizer, assert_draft_matches_golden
from core.edit_plan import EditPlan, EditPlanProject, EditPlanClip, EditPlanAudio, EditPlanCaption
from core.rule_engine import MOTION_ZOOM_IN, MOTION_ZOOM_OUT
from adapters.capcut.adapter import CapCutAdapter


@pytest.fixture
def sample_draft_info():
    fixture_path = os.path.join(os.path.dirname(__file__), "fixtures", "capcut_9_3", "draft_info_fixture.json")
    with open(fixture_path, "r", encoding="utf-8") as f:
        return json.load(f)


def test_draft_normalizer_idempotence(sample_draft_info):
    norm1 = DraftNormalizer.normalize_draft_info(sample_draft_info)
    norm2 = DraftNormalizer.normalize_draft_info(sample_draft_info)

    s1 = DraftNormalizer.to_canonical_json(norm1)
    s2 = DraftNormalizer.to_canonical_json(norm2)
    assert s1 == s2
    assert norm1["id"] == "NORMALIZED_DRAFT_ID"
    assert norm1["create_time"] == 0
    assert norm1["tracks"][0]["id"] == "TRACK_VIDEO_00"
    assert norm1["tracks"][0]["segments"][0]["id"] == "SEG_VIDEO_0000"


def test_draft_normalizer_detects_structural_diffs(sample_draft_info):
    norm1 = DraftNormalizer.normalize_draft_info(sample_draft_info)
    norm2 = copy.deepcopy(norm1)

    # Identical
    assert DraftNormalizer.compare_normalized(norm1, norm2) == []

    # Mutate a semantic duration
    norm2["tracks"][0]["segments"][0]["target_timerange"]["duration"] = 9999999
    diff = DraftNormalizer.compare_normalized(norm1, norm2)
    assert len(diff) > 0
    diff_str = "".join(diff)
    assert "9999999" in diff_str


def test_golden_draft_end_to_end_snapshot(tmp_path):
    # 1. Create dummy image and audio
    img_path = str(tmp_path / "slide_01.png")
    img = Image.new("RGB", (1080, 1920), color=(20, 40, 80))
    img.save(img_path)

    # 2. Build EditPlan
    project = EditPlanProject(name="GoldenTest", width=1080, height=1920, fps=60.0, duration_us=10000000)
    c1 = EditPlanClip(clip_id="c1", media_path=img_path, media_type="image", start_us=0, duration_us=5000000, motion_type=MOTION_ZOOM_IN)
    c2 = EditPlanClip(clip_id="c2", media_path=img_path, media_type="image", start_us=5000000, duration_us=5000000, motion_type=MOTION_ZOOM_OUT)
    plan = EditPlan(project=project, clips=[c1, c2])

    # 3. Generate Draft via CapCutAdapter
    draft_dir = str(tmp_path / "actual_draft")
    adapter = CapCutAdapter()
    res = adapter.generate(edit_plan=plan, target_dir=draft_dir, draft_root_path=str(tmp_path))
    assert res["validated"] is True

    # 4. Save and establish golden snapshot
    golden_file = os.path.join(os.path.dirname(__file__), "fixtures", "golden", "snapshots", "basic_v2_draft.golden.json")
    assert_draft_matches_golden(draft_dir, golden_file, update_golden=True)
    assert os.path.isfile(golden_file)

    # 5. Verify match against golden
    assert_draft_matches_golden(draft_dir, golden_file, update_golden=False)

    # 6. Verify mutation detection
    with open(os.path.join(draft_dir, "draft_info.json"), "r") as f:
        tampered = json.load(f)
    tampered["fps"] = 24.0  # Tamper fps
    with open(os.path.join(draft_dir, "draft_info.json"), "w") as f:
        json.dump(tampered, f)

    with pytest.raises(AssertionError, match="Draft does not match golden snapshot"):
        assert_draft_matches_golden(draft_dir, golden_file, update_golden=False)
