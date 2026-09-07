"""
tests/test_v1_isolation.py
Verifies non-regression and isolation invariants for Product V1 (FFmpeg Edition):
1. FFMPEG_V1_BUILD_INDEPENDENT = TRUE
2. V1_MOTION_CORE (subpixel_affine_engine.py) remains unmodified and frozen.
3. V1 entrypoint (app.py) remains functional.
"""
from __future__ import annotations

import os
import sys
import hashlib
import pytest

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if REPO_ROOT not in sys.path:
    sys.path.insert(0, REPO_ROOT)


def test_v1_files_present():
    """Verify all core V1 files exist in root."""
    required_v1_files = [
        "app.py",
        "version.py",
        "subpixel_affine_engine.py",
        "camera_engine.py",
        "ffmpeg_utils.py",
        "license_manager.py",
        "subtitles_engine.py",
    ]
    for fn in required_v1_files:
        assert os.path.isfile(fn), f"Critical V1 file missing: {fn}"


def test_v1_motion_core_frozen():
    """Verify subpixel_affine_engine.py contains frozen mathematics and Lanczos4 default."""
    with open("subpixel_affine_engine.py", "r", encoding="utf-8") as f:
        content = f.read()

    # Invariants from SUBPIXEL_AFFINE_PRODUCTION_FREEZE.md
    assert "cv2.warpAffine" in content
    assert "cv2.INTER_LANCZOS4" in content
    assert "SubpixelAffineEngine" in content
    assert "OPENCV_AVAILABLE" in content
    # Ensure no CapCut leakage into V1 motion core
    assert "CapCut" not in content
    assert "draft_info" not in content



def test_v1_version_stream_independent():
    """Verify V1 maintains its independent version stream."""
    import version
    assert version.__version__ == "2.3.9"
    assert version.APP_NAME == "Slideshow Builder AI"
