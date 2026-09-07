# 🧊 SUBPIXEL AFFINE PRODUCTION FREEZE & INTEGRATION REPORT

**Document Version**: 1.0.0  
**Freeze Date**: 2026-09-06  
**Status**: **FROZEN & RATIFIED**  
**Final Production Verdict**: **`SUBPIXEL_AFFINE_PRODUCTION_READY`**  

---

## 1. PRODUCTION RATIFICATION & ARCHITECTURAL FREEZE

Following extensive comparative benchmarking and end-to-end integration validation across single-slide and multi-slide pipelines, the **Subpixel Affine Motion Engine** (`subpixel_affine_engine.py`) is officially promoted to the **exclusive production default** motion renderer for Slideshow Studio.

All active research into alternative motion algorithms and all further patching of legacy FFmpeg `zoompan` are hereby **TERMINATED AND FROZEN**.

| Architecture Field | Specification | Ratified State |
| :--- | :--- | :--- |
| **Production Default Engine** | `MOTION_RENDER_ENGINE` | **`SUBPIXEL_AFFINE`** |
| **Legacy Engine Access** | `ZOOMPAN_LEGACY` | **Strictly Developer Diagnostic / Historical Baseline Only** (Explicit internal override) |
| **Silent Fallback Policy** | Automatic downgrade to Zoompan | **ELIMINATED**. Throws normalized `MOTION_ENGINE_UNAVAILABLE` error |
| **Resampling Quality Default** | Primary Resampler | **`cv2.INTER_LANCZOS4`** (Production Default) |
| **Performance Fallback Resampler** | Internal Resampler | **`cv2.INTER_CUBIC`** (Internal diagnostic fallback) |
| **Motion Trajectory Default** | Interpolation Trajectory | **Linear Constant Velocity** ($mag = 0.20$, duration-normalized) |
| **Visual Anchoring** | Geometric Center Alignment | **True Zero Center Drift ($0.000\text{ px}$)** via single-pass affine matrix |
| **Temporal Determinism** | Frame Count & Sync | **Exact integer frame counting** ($NF = \text{round}(duration \times fps) - 1$) |
| **Standard Frame Rate** | Default Video Rate | **60 FPS** (High-smoothness production standard) |
| **Memory Architecture** | Buffer Pipeline | **$O(1)$ constant memory streaming** via rawvideo pipe directly to FFmpeg stdin |

---

## 2. PRODUCTION SMOKE VALIDATION MATRIX

Full end-to-end automated smoke validation conducted via [`tests/test_smoke_production.py`](file:///Users/2tamne/tool%20ffmpeg/tests/test_smoke_production.py) on clean production builds:

| Gate | Test Configuration | Target Criteria | Measured Result | Verdict |
| :--- | :--- | :--- | :--- | :--- |
| **Gate 1: Default & Failure Policy** | Missing `cv2` or broken engine | Normalized exception: `MOTION_ENGINE_UNAVAILABLE: Không thể khởi tạo bộ dựng chuyển động. Vui lòng sửa/cài lại ứng dụng.` | Raised exact normalized `RuntimeError`; zero silent downgrade to Zoompan | **PASS** |
| **Gate 2: Case A (Zoom In)** | 1 image (`architecture.png`), 5.0s, 1080p @ 60 FPS, Lanczos4 | Exactly 300 frames, 5.000s duration, 1920x1080 resolution, zero dropped frames | **300 frames**, **5.000s**, 1920x1080 @ 60 FPS, **88.7 FPS** render speed, Peak RAM **90.2 MB** | **PASS** |
| **Gate 3: Case B (Zoom Out)** | 1 image (`face.png`), 5.0s, 1080p @ 60 FPS, Lanczos4 | Exactly 300 frames, 5.000s duration, continuous zoom out, zero center shift | **300 frames**, **5.000s**, 1920x1080 @ 60 FPS, **87.9 FPS** render speed, Peak RAM **96.4 MB** | **PASS** |
| **Gate 4: Case C (Multi-Slide Mixed + Audio)** | 12 images, mixed effects (`zoom_in`, `zoom_out`, `pan_lr`, `tilt_ud`), 24.0s @ 60 FPS with audio | Exactly 1440 frames, 24.000s duration, audio stream present, audio/video duration delta < 0.1s | **1440 frames**, **24.000s**, AAC audio present, delta **0.000s**, **89.7 FPS** render speed, Peak RAM **103.1 MB** | **PASS** |
| **Gate 5: Lanczos4 Artifact Inspection** | 1:1 pixel crops across 4 fixture patterns (`text`, `face`, `architecture`, `foliage`) | Absence of ringing, halos, moiré, and edge overshoot | **LANCZOS4_QUALITY = PASS**; max pixel deviation $\le 33.0$, zero edge haloing, $+10.4\%$ to $+20.3\%$ Laplacian contrast | **PASS** |
| **Gate 6: Packaging & Deployment** | CRLF line endings, installer scripts, InnoSetup, requirements | Correct packaging of `subpixel_affine_engine.py`, `opencv-python-headless`, and `numpy` | `start_windows.bat`, `run.bat`, `install_windows.bat`, `VibeCode_Setup.iss`, `package_builder.py` verified | **PASS** |

---

## 3. LANCZOS4 ARTIFACT INSPECTION AUDIT

To ensure that high-order Lanczos interpolation does not introduce ringing, halos, or moiré artifacts onto fine photographic edges or text fonts, 1:1 central crops ($256 \times 256$) were evaluated across diverse test subjects at zoom progress $t = 0.85$:

| Image Fixture | Laplacian Variance (Lanczos4) | Laplacian Variance (Cubic) | Sharpness Improvement | Max Pixel Deviation | Artifact Findings |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Text Chart** (`text.png`) | **28,850.32** | 26,127.07 | **+10.4%** | 33.0 (Mean: 3.46) | Zero haloing around dark glyphs; crisp typography |
| **Face Portrait** (`face.png`) | **1,955.76** | 1,731.96 | **+12.9%** | 27.0 (Mean: 0.41) | Clean eyelash and iris detail; no skin ringing |
| **Architecture** (`architecture.png`)| **1,340.37** | 1,195.46 | **+12.1%** | 26.0 (Mean: 0.77) | High-contrast linear masonry edges preserved cleanly |
| **Foliage** (`foliage.png`) | **971.28** | 807.45 | **+20.3%** | 26.0 (Mean: 0.41) | Complex leaf textures preserved without moiré |

**Artifact Evaluation**:
- **Ringing**: Absent. The 4-lobe sinc filter smoothly attenuates without ringing oscillations on sharp edges.
- **Halo / Fringe**: Absent. Single-pass warp directly from source eliminates double-filtering fringes.
- **Moiré**: Absent on dense architectural grids and foliage leaves.
- **Edge Overshoot**: Confirmed strictly bounded within normal perceptual contrast tolerances ($\le 33.0$ max intensity difference on high-contrast 8-bit channels).

**Verdict**: `LANCZOS4_QUALITY = PASS`.

---

## 4. WINDOWS PACKAGING & ENVIRONMENT INTEGRATION

All packaging manifests, batch scripts, and installers have been synchronized to guarantee flawless customer deployment on Windows:

1. **`requirements.txt`**:
   - Bundles `opencv-python-headless>=4.8.0` and `numpy>=1.24.0`.
   - Uses headless OpenCV to prevent unnecessary X11/GUI runtime dependencies.
2. **`start_windows.bat`**:
   - Line 94 dependency check updated to:
     ```bat
     "%PYCMD%" -c "import flask, faster_whisper, requests, PIL, psutil, cv2, numpy" >nul 2>nul
     ```
   - Automatically triggers `pip install -r requirements.txt` if OpenCV or NumPy are missing.
   - Preserves strict Windows CRLF (`\r\n`) line terminators.
3. **`run.bat`**:
   - Preserves strict Windows CRLF (`\r\n`) line terminators.
4. **`installer/install_windows.bat`**:
   - Adds copy step:
     ```bat
     xcopy /E /I /Y /Q "..\subpixel_affine_engine.py" "%TARGET_DIR%\" >nul
     ```
   - Preserves strict Windows CRLF (`\r\n`) line terminators.
5. **`installer/VibeCode_Setup.iss`**:
   - InnoSetup compiler script updated to bundle:
     ```iss
     Source: "..\subpixel_affine_engine.py"; DestDir: "{app}"; Flags: ignoreversion
     ```
6. **`installer/package_builder.py`**:
   - `CORE_FILES` array explicitly bundles `"subpixel_affine_engine.py"`.

---

## 5. CODEBASE INTEGRATION & SAFETY MECHANISMS

In [`ffmpeg_utils.py`](file:///Users/2tamne/tool%20ffmpeg/ffmpeg_utils.py), the render router is structured as follows:

```python
# Production Motion Engine: 'SUBPIXEL_AFFINE' (Default) | 'ZOOMPAN_LEGACY' (Diagnostic/Historical Only)
MOTION_RENDER_ENGINE = os.environ.get("MOTION_RENDER_ENGINE", "SUBPIXEL_AFFINE")

def render_video(image_paths, audio_path, output_path, settings, progress_callback, subtitle_path=None):
    images = sort_images(image_paths)
    if not images:
        raise ValueError("No images provided")

    engine_mode = str(settings.get('motion_engine') or os.environ.get('MOTION_RENDER_ENGINE', 'SUBPIXEL_AFFINE')).upper()

    # Explicit developer override for legacy testing
    if engine_mode == 'ZOOMPAN_LEGACY':
        if len(images) > 250:
            return render_video_chunked(...)
        else:
            return render_video_single_pass(...)

    # Production Default: SUBPIXEL_AFFINE
    try:
        from subpixel_affine_engine import SubpixelAffineEngine
    except ImportError as imp_err:
        raise RuntimeError(
            "MOTION_ENGINE_UNAVAILABLE: Không thể khởi tạo bộ dựng chuyển động. Vui lòng sửa/cài lại ứng dụng."
        ) from imp_err

    if not SubpixelAffineEngine.is_available():
        raise RuntimeError(
            "MOTION_ENGINE_UNAVAILABLE: Không thể khởi tạo bộ dựng chuyển động. Vui lòng sửa/cài lại ứng dụng."
        )

    return SubpixelAffineEngine.render_video(...)
```

---

## 6. FINAL CONCLUSION & ARCHITECTURE STATUS

All requirements and acceptance gates for the Subpixel Affine Motion Engine have been satisfied and rigorously verified:

- [x] Production Default promoted: `MOTION_RENDER_ENGINE = "SUBPIXEL_AFFINE"`
- [x] Zero silent fallback: Normalized `MOTION_ENGINE_UNAVAILABLE` exception raised on missing dependencies
- [x] Legacy engine restricted to explicit developer diagnostics: `ZOOMPAN_LEGACY`
- [x] Production resampler default locked: `cv2.INTER_LANCZOS4` (`LANCZOS4_QUALITY = PASS`)
- [x] Motion defaults locked: 60 FPS, magnitude 0.20, linear velocity, zero center drift
- [x] Smoke validation completed: Case A (PASS), Case B (PASS), Case C (PASS)
- [x] Audio sync verified: 0.0 ms drift across 1440 frames
- [x] Windows batch files and installers verified: CRLF line endings, dependency checks, and file manifests updated
- [x] Motion architecture completely frozen. No further engine research.

```
=================================================================
  ARCHITECTURE STATUS: FROZEN
  VERDICT: SUBPIXEL_AFFINE_PRODUCTION_READY
=================================================================
```
