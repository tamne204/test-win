# Slideshow Builder AI - Phiên Bản macOS

## Hệ Thống Yêu Cầu
- macOS 12 Monterey trở lên
- Python 3.9 trở lên
- FFmpeg: `brew install ffmpeg`

## Khởi Động
1. **Click đúp `start_mac.command`** trong Finder
   - Lần đầu: Control+Click → Mở → Cho phép
2. Terminal tự mở, tự cài thư viện, tự mở http://localhost:8080

## Tăng Tốc GPU
- Apple Silicon M1/M2/M3/M4: Tự động dùng VideoToolbox (Metal GPU)
- Intel Mac: Tự động dùng VideoToolbox hoặc CPU multi-core
