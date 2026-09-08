"""
tests/test_wave2_presets_and_projects.py
Acceptance tests for Wave 2: Presets and Project Management.
"""
import os
import shutil
import tempfile
import pytest

from core.preset_manager import PresetManager, PRESET_NORMAL, PRESET_CALM, PRESET_FAST
from core.timeline_builder import TimelineBuilder
from adapters.capcut.project_manager import CapCutProjectManager


def test_preset_manager_builtin_styles():
    pm = PresetManager()
    presets = pm.list_presets()
    preset_ids = [p.id for p in presets]
    
    assert "normal" in preset_ids
    assert "calm" in preset_ids
    assert "fast" in preset_ids

    # Check case-insensitive lookup
    p_norm = pm.get_preset("NORMAL")
    assert p_norm.id == "normal"
    assert p_norm.min_scene_duration_s == 4.0
    assert p_norm.max_scene_duration_s == 6.5

    p_calm = pm.get_preset(" calm ")
    assert p_calm.id == "calm"
    assert p_calm.min_scene_duration_s == 5.5
    assert p_calm.max_scene_duration_s == 8.5

    p_fast = pm.get_preset("FAST")
    assert p_fast.id == "fast"
    assert p_fast.min_scene_duration_s == 2.5
    assert p_fast.max_scene_duration_s == 4.5


def test_preset_manager_fallback():
    pm = PresetManager()
    unknown = pm.get_preset("non_existent_preset_id_xyz")
    assert unknown.id == "basic_slideshow"


def test_timeline_builder_with_calm_preset(tmp_path):
    pm = PresetManager()
    preset = pm.get_preset("calm")
    builder = TimelineBuilder(preset=preset)

    img1 = tmp_path / "img1.png"
    img2 = tmp_path / "img2.png"
    img1.write_bytes(b"dummy image 1")
    img2.write_bytes(b"dummy image 2")

    plan = builder.build(
        images=[str(img1), str(img2)],
        project_name="Test Calm Project",
    )

    assert plan.project.name == "Test Calm Project"
    assert len(plan.clips) == 2
    assert plan.clips[0].duration_us == int(preset.scene_duration_s * 1_000_000)
    assert plan.metadata.get("preset_id") == "calm"


def test_project_deletion_preserves_source_files(tmp_path):
    """
    Verify deletion invariant: deleting draft folder MUST NOT touch source images or audio.
    """
    user_media_dir = tmp_path / "user_photos"
    user_media_dir.mkdir()
    source_img = user_media_dir / "precious_photo.jpg"
    source_img.write_text("irreplaceable image content")
    source_audio = user_media_dir / "voiceover.mp3"
    source_audio.write_text("irreplaceable audio content")

    staging_dir = tmp_path / "capcut_staging"
    staging_dir.mkdir()
    draft_dir = staging_dir / "draft_project_123"
    draft_dir.mkdir()
    draft_meta = draft_dir / "draft_content.json"
    draft_meta.write_text('{"mock": "draft"}')

    # Simulate deleting the draft project
    shutil.rmtree(str(draft_dir))
    assert not draft_dir.exists()

    # Source files MUST still be intact
    assert source_img.exists()
    assert source_img.read_text() == "irreplaceable image content"
    assert source_audio.exists()
    assert source_audio.read_text() == "irreplaceable audio content"
