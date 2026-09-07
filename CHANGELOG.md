# Changelog

All notable changes to **Slideshow Builder AI** will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.3.9] - 2026-09-06

### Added
- **Subpixel Affine Motion Engine**: True floating-point affine matrix rendering via OpenCV (`cv2.warpAffine`) streaming rawvideo to FFmpeg encoder.
- **Zero Jitter & Micro-Stutter Elimination**: Replaced FFmpeg `zoompan` integer canvas truncation with subpixel analytical glide.
- **Lanczos4 Single-Pass Resampling**: Direct source-to-target transform using `cv2.INTER_LANCZOS4`, retaining +10% to +20% higher edge contrast.
- **Production Motion Default**: Promoted `MOTION_RENDER_ENGINE = 'SUBPIXEL_AFFINE'` to default with zero silent fallback (`MOTION_ENGINE_UNAVAILABLE`).
- **Windows Integration**: Bundled `opencv-python-headless>=4.8.0` and `numpy>=1.24.0` in `requirements.txt` with auto-installer synchronization.

## [2.0.0] - 2026-08-26

### Added
- **Modular Auto-Updater System**: Decoupled package `updater/` supporting GitHub Releases and secure 2tamne.site Proxy for private repositories.
- **Atomic Update & Automated Rollback**: Staging folder extraction, checksum SHA-256 verification, and automatic rollback on failure.
- **Hardware ID (HWID) License Authentication**: Client integration with `2tamne.site` license server (`activate.php`, `verify.php`, background heartbeat).
- **Cinematic Sine Easing & Subpixel Motion**: Ultra-smooth Ken Burns zoom, pan, tilt with pre-scaled canvas architecture.
- **Multi-Resolution Engine**: Native support for 1080p (Full HD), 2K (1440p), and 4K (2160p) across all aspect ratios (16:9, 9:16, 1:1, 4:5, 21:9).
- **Next-Gen Video Codecs**: User-selectable H.264 (`libx264`) and H.265/HEVC (`libx265`) with custom CRF quality controls.
- **Advanced Subtitle Pill/Capsule**: Auto word-wrap, letter spacing, line spacing, and 5 Korean Noonnu fonts support.
- **Windows Portable Launcher**: 1-click execution via `start_windows.bat`.

### Fixed
- Fixed integer crop truncation jitter in FFmpeg slideshows.
- Fixed `SIGBUS` exit code -10 memory allocation leak on long video rendering.
- Fixed static frame issue on `zoom_in` and `zoom_out` by utilizing pre-scaled canvas zoompan.

---

## [1.0.0] - Initial Release
- Basic FFmpeg slideshow generation with cross-dissolve transitions.
- Audio waveform visualizer and subtitle overlay burning.
