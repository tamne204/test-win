# 📋 TÀI LIỆU BÀN GIAO DỰ ÁN (PROJECT HANDOVER)
**Dự án**: Slideshow Builder AI (Slideshow Studio)  
**Phiên bản hiện tại**: `v2.3.9`  
**Ngày bàn giao**: 06/09/2026  

---

## 1. THÔNG TIN HỆ THỐNG & MÁY CHỦ (CREDENTIALS & HOSTING)

### A. Máy Chủ Hosting / FTP
- **Host**: `2tamne.site`
- **Port**: `21` (FTP Passive Mode)
- **FTP User**: `2tamne@2tamne.site`
- **FTP Password**: `@2Tamne25122004`
- **Thư mục Web Root**: `/public_html/`
- **Thư mục Downloads**: `/public_html/downloads/`
- **Thư mục Storage cấu hình**: `/public_html/storage/`
- **Thư mục API License & Update**: `/public_html/api/license/`

### B. Cơ Sở Dữ Liệu MySQL
- **Database Name**: `ecxaebka_bot`
- **DB User**: `ecxaebka_bot`
- **DB Password**: `JTV3SQ6bPqkwZ5UAVa7e`

### C. Cổng Quản Trị Bản Quyền (License Admin Portal)
- **URL**: `https://www.2tamne.site/license_admin.php`
- **Admin Password**: `@2TamneAdmin2026`

### D. File Cấu Hình & Endpoint Cập Nhật Trực Tuyến
- **API Check Update**: `https://www.2tamne.site/api/license/check_update.php?client_platform={windows|mac}`
- **File System Config trên Server**: `/public_html/storage/system_config.json`
- **Link tải gói cài đặt Windows**: `https://www.2tamne.site/downloads/SlideshowBuilder_Windows_v2.3.9.zip` (Alias: `SlideshowBuilder_Windows_latest.zip`)
- **Link tải gói cài đặt macOS**: `https://www.2tamne.site/downloads/SlideshowBuilder_macOS_v2.3.9.zip` (Alias: `SlideshowBuilder_macOS_latest.zip`)

### E. Thông Tin Google Cloud & OAuth 2.0 Client (Web & Cloud Storage)
- **Project ID**: `toolne-cloud`
- **Client ID**: `672703700939-vovbmvjtakmah8p1ge6c05etfos54uo2.apps.googleusercontent.com`
- **Client Secret**: `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`
- **Auth URI**: `https://accounts.google.com/o/oauth2/auth`
- **Token URI**: `https://oauth2.googleapis.com/token`
- **Auth Provider Cert URL**: `https://www.googleapis.com/oauth2/v1/certs`
- **Redirect URI**: `https://www.2tamne.site/api/v1/admin/cloud/google/callback`

---

## 2. QUY TẮC BẮT BUỘC CỦA NGƯỜI DÙNG (CRITICAL USER RULES)

> [!IMPORTANT]
> Các quy tắc dưới đây là cam kết bắt buộc với User, AI tiếp quản **phải tuân thủ tuyệt đối**:
> 1. **Duyệt trước khi làm**: Luôn trình bày giải pháp kỹ thuật cụ thể và xin phép user duyệt (`duyệt`) trước khi thực hiện các thay đổi lớn.
> 2. **Duyệt trước khi Deploy**: Luôn hỏi ý kiến user trước khi build package zip và upload lên hosting `2tamne.site`.
> 3. **Phần cứng GPU trên Windows**: Bắt buộc ưu tiên sử dụng Card rời NVIDIA GeForce RTX (`h264_nvenc` / `hevc_nvenc`), tuyệt đối không được dùng Intel iGPU tích hợp.
> 4. **Tách biệt 2 nền tảng**:
>    - Bản Windows: Chứa `start_windows.bat`, `run.bat`, `SlideshowStudio.vbs`; loại trừ `start_mac.command`.
>    - Bản macOS: Chứa `start_mac.command` (ưu tiên `.venv/bin/python3`); loại trừ các file `.bat` và `.vbs`.
> 5. **Tôn trọng lựa chọn của người dùng**: Khi user chọn chế độ nào (Cloud hoặc Offline), hệ thống phải thực thi đúng chế độ đó, không được tự ý chuyển đổi qua lại.
> 6. **Bắt buộc đánh số phiên bản mới cho mọi update (Mandatory Version Bump)**: Bất kỳ cập nhật, sửa lỗi hay bản vá nào dù lớn hay nhỏ đều **bắt buộc phải tăng số phiên bản mới** (Semantic Versioning: ví dụ `2.3.2` -> `2.3.3` -> `2.3.4`). Tuyệt đối không được giữ nguyên số phiên bản cũ khi cập nhật code để đảm bảo khách hàng và hệ thống phân biệt rành mạch, triệt tiêu lỗi lưu cache của trình duyệt và file tải về.

---

## 3. CẤU TRÚC THƯ MỤC & CÁC MODULE CỐT LÕI

```text
/Users/2tamne/tool ffmpeg/
├── app.py                      # Flask Web Backend (Khởi tạo server, API routes, quản lý Render jobs, Licensing)
├── ffmpeg_utils.py             # Render Engine (Single-pass, Chunked pass, Turbo GPU NVENC/VideoToolbox, Subtitle Overlay)
├── forced_alignment_engine.py  # So khớp kịch bản (Gemini Cloud API & Stable-Whisper DTW Offline)
├── subtitles_engine.py         # Nhận diện âm thanh STT, Faster-Whisper, Clean SRT & LLM Verify
├── version.py                  # Định nghĩa phiên bản hiện tại (hiện tại: "2.2.3")
├── requirements.txt            # Thư viện: flask, requests, Pillow, edge-tts, stable-ts
├── start_windows.bat           # File khởi động Windows (Auto check python, install pip, launch browser)
├── start_mac.command           # File khởi động macOS (Auto check .venv, launch localhost:8080)
├── SlideshowStudio.vbs         # Script chạy ẩn cửa sổ console trên Windows
├── static/
│   ├── js/
│   │   └── main.js             # Logic Frontend (Timeline multi-track, Preview monitor, Inspector, Subtitle styles)
│   ├── css/
│   │   └── style.css            # Giao diện UI Dark Mode CapCut style, @font-face declarations
│   └── fonts/                  # Thư viện font TTF (Paperlogy-8ExtraBold, Montserrat, Tahoma, GongGothic, Jalnan, v.v.)
└── templates/
    └── index.html              # Template giao diện HTML chính
```

---

## 4. CHI TIẾT CÁC MODULE KỸ THUẬT QUAN TRỌNG

### 1. `ffmpeg_utils.py` (Bộ Xử Lý Render Video & Subtitle)
- **Hỗ trợ 2 kiến trúc Render**:
  - `render_video_single_pass`: Dành cho video ngắn ($\le 12$ ảnh).
  - `render_video_chunked`: Dành cho video dài hàng trăm ảnh ($> 12$ ảnh, ví dụ video 171 ảnh / 27 phút). Chia làm 2 giai đoạn:
    1. Render từng chunk nhỏ (không sub).
    2. Lossless Concat + Final Pass ghép audio và burn subtitle overlay.
- **Tỷ lệ phụ đề & Canvas**:
  - Tự động dùng `build_resolution(aspect, res)` để xác định đúng kích thước (16:9 $\rightarrow 1920\times 1080$, 9:16 $\rightarrow 1080\times 1920$).
  - Bộ vẽ phụ đề bằng Pillow sử dụng **Full-Line Typography** với font TrueType trong `static/fonts/`, bảo toàn độ dày nét viền `stroke` và ngắt dòng tự động.
- **Loại bỏ gia tốc**: Mọi chuyển động (Zoom In/Out, Pan L/R, Tilt U/D) sử dụng công thức tuyến tính 100% `_s = _t = n / NF` (không dùng cosine hay smoothstep).

### 2. `forced_alignment_engine.py` (So Khớp Kịch Bản)
- **Chế độ 1 - `gemini`**: Tải file audio lên Google Cloud Files API $\rightarrow$ gọi Gemini Flash Multimodal phân tích mốc thời gian từng câu thoại.
- **Chế độ 2 - `stable-whisper`**: Sử dụng `stable-whisper` cục bộ với thuật toán **Dynamic Time Warping (DTW)** so khớp ma trận sóng âm từng từ mili-giây.

### 3. `static/js/main.js` (Frontend Điều Khiển)
- **Đồng bộ hóa Inspector**: Hàm `executeRenderVideo()` đọc trực tiếp các tham số Font (`#insp-sel-sub-font`), Cỡ chữ (`#insp-sl-sub-size`), Viền (`#insp-sl-sub-stroke-w`), Vị trí Y (`#insp-sl-sub-pos-y`) gửi vào `FormData` sang máy chủ.
- **Preview Monitor**: Hàm `applySubtitleStylesToMonitor()` hiển thị trực quan kích thước và font chữ trùng khớp 1:1 với video render.

---

## 5. HƯỚNG DẪN ĐÓNG GÓI & DEPLOY PHIÊN BẢN MỚI

Khi cần phát hành phiên bản mới (ví dụ `v2.2.4`):
1. Cập nhật `version.py` thành phiên bản mới.
2. Đóng gói 2 file zip riêng biệt:
   - `SlideshowBuilder_Windows_v{ver}.zip` (loại trừ `.command`, `.git`, `.venv`).
   - `SlideshowBuilder_macOS_v{ver}.zip` (loại trừ `.bat`, `.vbs`, `.git`, `.venv`).
3. Upload qua FTP lên `/public_html/downloads/` kèm các bản alias `_latest.zip`.
4. Cập nhật `/public_html/api/license/check_update.php` và `/public_html/storage/system_config.json` với version và release notes mới.
