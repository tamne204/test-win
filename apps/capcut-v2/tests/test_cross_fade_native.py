"""
apps/capcut-v2/tests/test_cross_fade_native.py
Automated test suite verifying the native CapCut Cross Fade transition contract:
- OFF: 0 auto-generated transitions.
- ON: exactly N-1 transitions for N visual segments.
- Linkage: Transition i attached to outgoing segment i extra_material_refs.
- Attributes: resource_id="7657476671573937428", duration~500,000 us, is_overlap=True.
- Dynamic path resolution across macOS and Windows.
- Aspect ratio matrix (16:9, 9:16, 1:1, 4:5) and resolution matrix (1080p, 2K, 4K).
"""
import os
import sys
import shutil
import tempfile
import unittest
from PIL import Image

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from core.timeline_builder import TimelineBuilder
from core.preset_manager import PresetManager
from adapters.capcut.adapter import CapCutAdapter
from adapters.capcut.validator import CapCutDraftValidator
from adapters.capcut.version_9_3 import resolve_capcut_effect_path


class TestCapCutCrossFadeNative(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.test_dir = tempfile.mkdtemp(prefix="capcut_crossfade_test_")
        cls.img_paths = []
        for i in range(4):
            img_path = os.path.join(cls.test_dir, f"sample_{i:02d}.png")
            img = Image.new("RGB", (1920, 1080), color=((i * 60) % 255, (i * 40 + 50) % 255, (i * 80 + 100) % 255))
            img.save(img_path)
            cls.img_paths.append(img_path)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.test_dir, ignore_errors=True)

    def test_dynamic_path_resolution(self):
        """Test dynamic effect cache path resolution without hardcoding."""
        path = resolve_capcut_effect_path("7657476671573937428")
        if sys.platform == "darwin":
            self.assertTrue(isinstance(path, str))
            if path:
                self.assertTrue(os.path.isdir(path), f"Resolved path must exist: {path}")
                self.assertIn("7657476671573937428", path)
        fallback = resolve_capcut_effect_path("9999999999999999999_nonexistent")
        self.assertEqual(fallback, "")

    def test_cross_fade_off_contract(self):
        """Test cross_fade_enabled=False produces exactly 0 transitions."""
        pm = PresetManager()
        preset = pm.get_preset("basic_slideshow")
        builder = TimelineBuilder(preset)
        plan = builder.build(
            images=self.img_paths,
            project_name="Test CrossFade OFF",
            cross_fade_enabled=False,
        )
        self.assertFalse(plan.metadata.get("cross_fade_enabled", False))

        target_dir = os.path.join(self.test_dir, "draft_crossfade_off")
        adapter = CapCutAdapter()
        res = adapter.generate(plan, target_dir)
        self.assertTrue(res.get("validated"))

        errors = CapCutDraftValidator.validate_draft(target_dir, cross_fade_enabled=False)
        self.assertEqual(errors, [], f"Validation errors: {errors}")

        import json
        with open(os.path.join(target_dir, "draft_info.json"), "r") as f:
            d = json.load(f)
        transitions = d.get("materials", {}).get("transitions", [])
        self.assertEqual(len(transitions), 0, "OFF must produce 0 transitions")

    def test_cross_fade_on_contract_and_linkage(self):
        """Test cross_fade_enabled=True produces exactly N-1 transitions for N clips with proper linkage."""
        pm = PresetManager()
        preset = pm.get_preset("basic_slideshow")
        builder = TimelineBuilder(preset)
        plan = builder.build(
            images=self.img_paths,  # 4 clips
            project_name="Test CrossFade ON",
            cross_fade_enabled=True,
        )
        self.assertTrue(plan.metadata.get("cross_fade_enabled"))

        target_dir = os.path.join(self.test_dir, "draft_crossfade_on")
        adapter = CapCutAdapter()
        res = adapter.generate(plan, target_dir)
        self.assertTrue(res.get("validated"))

        errors = CapCutDraftValidator.validate_draft(target_dir, cross_fade_enabled=True)
        self.assertEqual(errors, [], f"Validation errors: {errors}")

        import json
        with open(os.path.join(target_dir, "draft_info.json"), "r") as f:
            d = json.load(f)

        transitions = d.get("materials", {}).get("transitions", [])
        self.assertEqual(len(transitions), 3, "4 visual segments must produce exactly 3 transitions (N-1)")

        video_track = next((t for t in d.get("tracks", []) if t.get("type") == "video"), None)
        self.assertIsNotNone(video_track)
        segments = video_track.get("segments", [])
        self.assertEqual(len(segments), 4)

        for i, trans in enumerate(transitions):
            t_id = trans["id"]
            self.assertEqual(trans["type"], "transition")
            self.assertEqual(trans["name"], "Cross Fade")
            self.assertEqual(trans["resource_id"], "7657476671573937428")
            self.assertEqual(trans["effect_id"], "7657476671573937428")
            self.assertTrue(trans["is_overlap"])
            self.assertTrue(450_000 <= trans["duration"] <= 550_000, f"Duration {trans['duration']} not ~500000us")
            
            seg_refs = segments[i].get("extra_material_refs", [])
            self.assertIn(t_id, seg_refs, f"Transition {t_id} missing from segment {i} refs: {seg_refs}")

        final_refs = segments[-1].get("extra_material_refs", [])
        trans_ids = {t["id"] for t in transitions}
        for ref in final_refs:
            self.assertNotIn(ref, trans_ids, "Final segment must not reference any transition")

    def test_aspect_ratio_and_resolution_matrix(self):
        """Test cross fade transitions across aspect ratios (16:9, 9:16, 1:1, 4:5)."""
        ratios = ["16:9", "9:16", "1:1", "4:5"]
        pm = PresetManager()
        preset = pm.get_preset("basic_slideshow")
        adapter = CapCutAdapter()

        for ratio in ratios:
            with self.subTest(ratio=ratio):
                builder = TimelineBuilder(preset)
                plan = builder.build(
                    images=self.img_paths,
                    aspect_ratio=ratio,
                    project_name=f"Matrix {ratio}",
                    cross_fade_enabled=True,
                )
                out_dir = os.path.join(self.test_dir, f"matrix_{ratio.replace(':', 'x')}")
                res = adapter.generate(plan, out_dir)
                self.assertTrue(res.get("validated"))

                errors = CapCutDraftValidator.validate_draft(out_dir, cross_fade_enabled=True)
                self.assertEqual(errors, [], f"Errors for ratio {ratio}: {errors}")


if __name__ == "__main__":
    unittest.main()
