# 🔍 AUDIT HIỆN TRẠNG REPOSITORY — FFMPEG V1 VÀ KHẢ NĂNG TÁI SỬ DỤNG CHO CAPCUT V2

**Document Version**: 1.0.0  
**Audit Date**: 2026-09-06  
**Auditor**: Antigravity Architecture Team  
**Scope**: Toàn bộ mã nguồn, cấu hình, kịch bản build và tài nguyên hiện hữu của repository `tamne204/ffmpeg-tool`.

---

## 1. CẤU TRÚC ỨNG DỤNG HIỆN TẠI (CURRENT APPLICATION STRUCTURE)

Repository hiện tại là một ứng dụng web monolithic kết hợp xử lý media qua FFmpeg, bao gồm các thành phần cốt lõi:

| Thư mục / Tập tin | Vai trò chính | Trạng thái kiến trúc |
| :--- | :--- | :--- |
| `app.py` | Flask Web Server (Port 8080), quản lý phiên làm việc, upload tệp, điều phối render nền, SSE progress, License gate, Update gate | **V1 Entrypoint — Stable** |
| `version.py` | Định nghĩa phiên bản duy nhất hiện tại (`__version__ = "2.3.9"`, `APP_NAME = "Slideshow Builder AI"`) | **V1 Version Control** |
| `subpixel_affine_engine.py` | Bộ dựng chuyển động nội suy subpixel affine bằng OpenCV (`cv2.warpAffine` + `cv2.INTER_LANCZOS4`), cố định tâm ($0.000\text{ px}$ drift), streaming rawvideo $O(1)$ RAM qua pipe tới FFmpeg | **V1 Motion Core — FROZEN** |
| `camera_engine.py` | Công cụ tính toán quỹ đạo camera cho bộ render cũ (`zoompan`) và hỗ trợ tính toán ma trận chuyển động | **V1 Legacy / Diagnostic** |
| `renderer_g.py` | Bộ dựng video đồ hoạ, kết hợp hình ảnh, transition và bộ lọc FFmpeg | **V1 Render Module** |
| `renderer_e_engine.py` | Bộ dựng tối ưu luồng video thay thế trước đây | **V1 Render Module** |
| `ffmpeg_utils.py` | Thư viện thao tác FFmpeg, kiểm tra định dạng ảnh/âm thanh, tính toán độ phân giải, kiểm tra an toàn đường dẫn (`validate_canonical_path`) | **V1 Core / Utility** |
| `subtitles_engine.py` | Xử lý phụ đề ASS/SRT, render subtitle overlay bitmap, đồng bộ hóa thời gian hiển thị phụ đề | **V1 Subtitle Engine** |
| `forced_alignment_engine.py` | Căn chỉnh phụ đề theo từng từ (word-level forced alignment) dùng Faster-Whisper | **V1 Subtitle Engine** |
| `tts_utils.py` | Tạo giọng nói AI Text-to-Speech (Edge-TTS, VoxCPM) | **V1 Audio / TTS** |
| `translation_utils.py` | Dịch thuật kịch bản / phụ đề | **V1 Utility** |
| `license_manager.py` | Kiểm tra HWID, gọi API xác thực bản quyền trực tuyến (`/api/license/`), kích hoạt license offline grace | **V1 Security & Licensing** |
| `diagnostic_collector.py` | Thu thập log, cấu hình hệ thống và gửi báo cáo chẩn đoán lỗi về máy chủ | **V1 Diagnostics** |
| `updater/` | Hệ thống tự động cập nhật qua GitHub Releases hoặc License Proxy | **V1 Auto-Updater** |
| `installer/` | Kịch bản đóng gói Windows (`VibeCode_Setup.iss`, `package_builder.py`) | **V1 Packaging** |
| `start_mac.command` | Kịch bản khởi động macOS | **V1 Launcher (Mac)** |
| `start_windows.bat` / `run.bat` | Kịch bản khởi động Windows | **V1 Launcher (Windows)** |

---

## 2. CHI TIẾT CÁC MODULE CỐT LÕI CỦA V1

### 2.1 Entrypoint và HTTP Pipeline
- **`app.py`**:
  - Khởi tạo Flask với giới hạn tải lên tối đa 4 GB (`MAX_CONTENT_LENGTH = 4 * 1024 * 1024 * 1024`).
  - Sử dụng session secret 32-byte ngẫu nhiên sinh bằng `secrets.token_hex(32)`.
  - Semaphore render: `RENDER_SEMAPHORE = threading.Semaphore(2)` (tối đa 2 tác vụ render song song để tránh cạn kiệt RAM/CPU).
  - License gate: `check_license_gate()` kiểm tra trước khi cấp quyền gọi API render, tts, subtitle.
  - Render endpoints: `/api/render`, `/api/progress/<pid>`, `/api/download/<pid>`.

### 2.2 Bộ dựng chuyển động (Rendering Modules) & Motion Engine
- **`subpixel_affine_engine.py`**:
  - **Trạng thái**: Đã nghiệm thu và đóng băng (`SUBPIXEL_AFFINE_PRODUCTION_FREEZE.md`).
  - **Toán học cốt lõi**: Ma trận Affine $2 \times 3$ với biến đổi tâm cố định:
    $$M = \begin{bmatrix} s & 0 & (1-s) \cdot cx \\ 0 & s & (1-s) \cdot cy \end{bmatrix}$$
  - **Nội suy**: `cv2.INTER_LANCZOS4` (4-lobe Lanczos), triệt tiêu rung giật (jitter), lệch tâm ($0.000\text{ px}$ drift) và răng cưa viền.
  - **Đường ống dẫn dữ liệu**: Xuất raw frames dạng `bgr24` trực tiếp vào stdin của tiến trình `ffmpeg` qua pipe, không ghi tệp trung gian ra đĩa.
  - **BẤT BIẾN**: Module này tuyệt đối không được sửa đổi, không được biến đổi thành CapCut converter.

### 2.3 Quản lý Dự án & Hàng đợi (Project Format & Queue)
- Dự án V1 được lưu trữ tại thư mục `projects/<project_id>/` hoặc theo đường dẫn tạm `outputs/<project_id>/`.
- Định dạng dữ liệu dự án V1 là `metadata.json` chứa thông tin tệp đầu vào, danh sách slide, cấu hình thời lượng, hiệu ứng chuyển động, đường dẫn âm thanh và phụ đề.
- Hàng đợi render dựa trên `threading.Thread` bất đồng bộ và `threading.Semaphore`.

### 2.4 Cấu hình và Cài đặt (Settings & Config)
- Lưu trữ cục bộ thông qua `license.json`, cấu hình trong `app.config`, và các biến môi trường (ví dụ `VIBECODE_DISABLE_LICENSE_GATE`, `VIBECODE_PORT`).

### 2.5 Hệ thống Bản quyền (Authentication & License Gate)
- `license_manager.py`:
  - Lấy mã định danh phần cứng máy tính (Hardware ID - HWID) qua MAC address và UUID bo mạch chủ.
  - Gửi yêu cầu xác thực tới máy chủ `https://www.2tamne.site/api/license/verify_hwid.php`.
  - Hỗ trợ thời gian ân hạn ngoại tuyến (offline grace period 7 ngày).
  - Có tiến trình kiểm tra heartbeat định kỳ (1800 giây).

### 2.6 Hệ thống Cập nhật (Update System)
- `updater/`:
  - Kiểm tra phiên bản mới qua API proxy `https://www.2tamne.site/api/license/check_update.php` hoặc trực tiếp GitHub Release API.
  - Hỗ trợ tải gói cập nhật, kiểm tra checksum SHA-256 và giải nén thay thế tập tin.

### 2.7 Xử lý Media, Âm thanh & Phụ đề (Media, Audio & Subtitles)
- **Hình ảnh**: Kiểm tra định dạng hỗ trợ bằng `ffmpeg_utils.is_supported_image` (PNG, JPG, JPEG, WEBP, BMP).
- **Âm thanh**: Kiểm tra bằng `ffmpeg_utils.is_supported_audio` (MP3, WAV, AAC, M4A, OGG, FLAC). Đo thời lượng âm thanh bằng `ffprobe`.
- **Phụ đề**: `subtitles_engine.py` phân tích cú pháp `.srt`, định dạng lại văn bản, tạo ảnh phụ đề trong suốt overlay hoặc nhúng trực tiếp qua filter `subtitles=`.

---

## 3. ĐÁNH GIÁ KHẢ NĂNG TÁI SỬ DỤNG CHO SẢN PHẨM V2 (CAPCUT EDITION)

| Module hiện hữu | Tái sử dụng cho V2? | Cơ sở đánh giá & Chiến lược tích hợp |
| :--- | :---: | :--- |
| `ffmpeg_utils.is_supported_image` | **CÓ (An toàn)** | Xác thực định dạng tệp ảnh đầu vào của người dùng trước khi đưa vào TimelineBuilder. |
| `ffmpeg_utils.is_supported_audio` | **CÓ (An toàn)** | Xác thực định dạng tệp âm thanh đầu vào. |
| `ffmpeg_utils.validate_canonical_path` | **CÓ (Bắt buộc)** | Bảo đảm an toàn đường dẫn, chống Directory Traversal khi đọc tệp đầu vào. |
| `subtitles_engine.parse_srt_file` | **CÓ (An toàn)** | Phân tích tệp phụ đề `.srt` thành các mốc thời gian start/end và nội dung chữ để đưa vào track phụ đề của CapCut. |
| `tts_utils.generate_tts` | **CÓ (Mở rộng sau)** | V2 POC hiện tại tập trung vào tệp âm thanh có sẵn, nhưng kiến trúc có thể dùng `tts_utils` khi cần sinh giọng nói tự động. |
| `license_manager` | **CÓ (Tích hợp sau)** | Dùng chung cơ chế xác thực bản quyền người dùng của hệ sinh thái 2toolne. Ở giai đoạn POC V2, tạo interface bọc độc lập để không ràng buộc cứng. |
| `diagnostic_collector` | **CÓ (An toàn)** | Cơ chế chuẩn hóa log và báo cáo lỗi cho V2. |
| `subpixel_affine_engine.py` | **KHÔNG** | Module này render trực tiếp ra MP4 bằng OpenCV+FFmpeg. V2 tạo timeline CapCut có thể chỉnh sửa (`.draft`), không render trước qua Subpixel Affine. |
| `camera_engine.py` | **KHÔNG** | V1 trajectory math đặc thù cho video render pixel-level; CapCut sử dụng keyframe bản địa (`common_keyframes`). |
| `renderer_g.py` / `renderer_e_engine.py` | **KHÔNG** | Thuộc về đường ống render V1. |
| `app.py` | **KHÔNG** | Web server của V1 trên cổng 8080. V2 cần app độc lập trên cổng 8088. |

---

## 4. KẾT LUẬN AUDIT VÀ QUY TẮC PHÂN TÁCH SẢN PHẨM

1. **V1 Hoàn toàn Độc lập**: V1 tiếp tục đóng vai trò là "Slideshow Studio" render video trực tiếp chất lượng cao. Mã nguồn và cấu hình V1 giữ nguyên trạng.
2. **Không có phụ thuộc vòng (Circular Dependencies)**: Toàn bộ mã nguồn V2 được đặt riêng biệt tại `apps/capcut-v2/`. Các module dùng chung chỉ được import theo chiều một chiều (V2 gọi helper V1, V1 không bao giờ biết đến sự tồn tại của V2).
3. **Môi trường thực thi độc lập**: V2 chạy tiến trình web server riêng biệt, ghi log riêng (`capcut-v2.log`), lưu cài đặt riêng (`~/.2toolne/autoedit-capcut/`), và sở hữu phiên bản độc lập (`CAPCUT_VERSION`).
