# Slideshow Builder AI - Phiên Bản Windows

## Hệ Thống Yêu Cầu
- Windows 10/11 64-bit
- Python 3.9 trở lên
- FFmpeg (Gyan.dev Full Build) - cài qua Winget: `winget install Gyan.FFmpeg`
- NVIDIA GeForce Driver 610.00+ (để dùng NVENC acceleration)

## Khởi Động
1. **Click đúp `SlideshowStudio.vbs`** — Bật ẩn hoàn toàn (không hiện cửa sổ CMD)
2. Hoặc **click đúp `start_windows.bat`** — Bật có cửa sổ CMD (dễ debug)
3. Trình duyệt tự mở http://localhost:8080

## Tăng Tốc GPU
- NVIDIA GeForce RTX/GTX: Tự động dùng NVENC (ưu tiên cao nhất)
- Intel Core iX: Tự động dùng QuickSync
- AMD Radeon: Tự động dùng AMF
