# 🎬 Slideshow Builder AI (`ffmpeg-tool`)

> Phần mềm tự động hóa sản xuất video trình chiếu ảnh khớp nhạc, lồng tiếng AI và phụ đề thông minh dành cho nhà sáng tạo nội dung, TikTok/Shorts/Reels và Affiliate Marketing.

---

## 🌟 Tính Năng Nổi Bật

- **🎥 Chuyển Động Điện Ảnh Ken Burns Subpixel**: Zoom In, Zoom Out, Pan Trái/Phải, Tilt Lên/Xuống với gia tốc **Sine Ease-In-Out** siêu mượt, loại bỏ hoàn toàn hiện tượng giật hình.
- **📺 Đa Độ Phân Giải (1080p · 2K · 4K)**: Tự động tính toán Canvas đệm độc lập cho mọi tỷ lệ khung hình (16:9, 9:16, 1:1, 4:5, 21:9).
- **📼 Bộ Mã Hóa Tiên Tiến**: Tùy chọn **H.264** (tương thích mọi thiết bị) hoặc **H.265 / HEVC** (siêu nén dung lượng) với CRF chất lượng tùy chỉnh.
- **🎙️ Tích Hợp Lồng Tiếng AI (TTS)**: Hỗ trợ Edge-TTS Microsoft Cloud (0% RAM) và VoxCPM.
- **🔤 Phụ Đề Thông Minh & Font Noonnu Hàn Quốc**: Tự động xuống dòng (Auto Word-Wrap), tùy chỉnh giãn chữ, giãn dòng, viền chữ và hộp đen bo tròn (Capsule Pill).
- **🔐 Quản Lý Bản Quyền Theo Máy (HWID)**: Xác thực trực tiếp với hệ thống [2tamne.site](https://www.2tamne.site/).
- **🚀 Tự Động Cập Nhật (Auto-Updater)**: Cập nhật nguyên tử (Atomic Update) với cơ chế bảo vệ mã nguồn và Rollback an toàn khi có lỗi.

---

## 💻 Cài Đặt & Khởi Chạy

### 1. Trên Windows (Khuyên dùng):
Chỉ cần nhấp đúp chuột vào file:
```bat
start_windows.bat
```
*(File bat sẽ tự động kích hoạt môi trường `.venv`, nạp FFmpeg cục bộ trong `bin/` và mở trình duyệt `http://localhost:8080`)*.

### 2. Trên macOS / Linux:
```bash
# 1. Kích hoạt môi trường ảo
source .venv/bin/activate

# 2. Cài đặt các thư viện
pip install -r requirements.txt

# 3. Khởi chạy server
python app.py
```
Truy cập giao diện tại: **`http://localhost:8080`**

---

## 🏗️ Cấu Trúc Dự Án

```text
ffmpeg-tool/
├── app.py                      # Flask Server & API Routing
├── version.py                  # Single source of truth cho Version
├── license_manager.py          # HWID & 2tamne.site License Client
├── ffmpeg_utils.py             # Lõi Render FFmpeg & Motion Engine
├── subtitles_engine.py         # Nhận diện âm thanh & tạo phụ đề
├── tts_utils.py                # Lồng tiếng AI (Edge-TTS, VoxCPM)
├── translation_utils.py        # Dịch thuật phụ đề đa ngôn ngữ
├── updater/                    # Package Auto-Updater độc lập
│   ├── version_manager.py      # Xử lý & so sánh Semantic Versioning
│   ├── platform_detector.py    # Nhận diện OS & CPU Architecture
│   ├── checksum.py             # Tính toán & đối soát SHA-256
│   ├── github_release_client.py# Kết nối GitHub Releases / 2tamne.site Proxy
│   ├── rollback_manager.py     # Sao lưu & khôi phục tự động
│   ├── update_manager.py       # Điều phối quy trình cập nhật
│   └── apply_update.py         # Helper script thay thế file an toàn
├── templates/                  # Giao diện Jinja2 HTML
├── static/                     # CSS, JS, WaveSurfer, Font chữ Noonnu
├── tests/                      # Bộ kiểm thử tự động (Unit Tests)
└── start_windows.bat           # File khởi chạy 1-click cho Windows
```

---

## 🔄 Quy Trình Phát Triển Git & GitHub

Dự án tuân thủ nghiêm ngặt quy trình Git nhánh:

```bash
# 1. Tạo nhánh tính năng mới
git checkout -b feature/ten-tinh-nang

# 2. Kiểm tra thay đổi và chạy test
python -m unittest tests/test_updater.py

# 3. Commit theo chuẩn Conventional Commits
git commit -m "feat: mo ta tinh nang moi"

# 4. Đẩy lên repository
git push origin feature/ten-tinh-nang
```

---

## 📄 Bản Quyền & Giấy Phép

Phát triển bởi **2tamne.site**. Mọi quyền được bảo lưu.
