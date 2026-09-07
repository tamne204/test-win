# 🏛️ PHÂN TÁCH THẾ HỆ SẢN PHẨM: FFMPEG V1 VÀ CAPCUT V2 (PRODUCT GENERATION SPLIT)

**Ratification Date**: 2026-09-06  
**Status**: **OFFICIALLY RATIFIED & ENFORCED**  
**Classification**: Core Product Architecture Policy

---

## 1. QUYẾT ĐỊNH SẢN PHẨM KHÔNG THƯƠNG LƯỢNG (NON-NEGOTIABLE PRODUCT DECISION)

Từ thời điểm này, toàn bộ hệ sinh thái kỹ thuật của **2toolne** được phân tách thành **HAI THẾ HỆ SẢN PHẨM ĐỘC LẬP HOÀN TOÀN**:

```
                              ┌──────────────────────────────────────────────┐
                              │            2TOOLNE VIDEO ECOSYSTEM           │
                              └──────────────────────┬───────────────────────┘
                                                     │
                   ┌─────────────────────────────────┴─────────────────────────────────┐
                   ▼                                                                   ▼
    ┌─────────────────────────────┐                                     ┌─────────────────────────────┐
    │       PRODUCT GENERATION 1  │                                     │       PRODUCT GENERATION 2  │
    │ 2toolne Slideshow Studio    │                                     │ 2toolne AutoEdit for CapCut │
    │   (FFMPEG V1 EDITION)       │                                     │     (CAPCUT V2 EDITION)     │
    ├─────────────────────────────┤                                     ├─────────────────────────────┤
    │ • Direct MP4/Video Render   │                                     │ • Automated Timeline Builder│
    │ • Subpixel Affine (FROZEN)  │                                     │ • Native CapCut Draft Gen   │
    │ • Stable Maintenance Mode   │                                     │ • Rule-Based / Deterministic│
    │ • Direct FFmpeg Export      │                                     │ • Full Timeline Editability │
    └─────────────────────────────┘                                     └─────────────────────────────┘
```

---

## 2. PRODUCT V1 — FFMPEG EDITION (SLIDESHOW STUDIO)

### 2.1 Trách nhiệm & Phạm vi
- Render video MP4 trực tiếp thông qua FFmpeg.
- Duy trì quy trình làm việc (project workflow) và giao diện sản xuất hiện hữu.
- Duy trì hệ thống Audio, TTS (Edge-TTS, VoxCPM) và Subtitles overlay/burn-in hiện tại.
- Vận hành bộ dựng chuyển động subpixel affine độc quyền.

### 2.2 Trạng thái kiến trúc
- **Tình trạng**: **STABLE / MAINTENANCE MODE** (Ổn định / Bảo trì định kỳ).
- **Bộ chuyển động sản xuất mặc định**: **`SUBPIXEL_AFFINE`** (`subpixel_affine_engine.py`).
- **Bộ chuyển động lưu trữ lịch sử**: **`ZOOMPAN_LEGACY`** (Chỉ dùng đối chuẩn nội bộ).
- **Lõi chuyển động**: **`V1_MOTION_CORE = FROZEN`** (Đóng băng vĩnh viễn toán học chuyển động).

### 2.3 Ràng buộc nghiêm ngặt
- **TUYỆT ĐỐI KHÔNG** tái thiết kế kiến trúc của V1.
- **TUYỆT ĐỐI KHÔNG** chuyển đổi bộ dựng của V1 sang chế độ CapCut.
- **TUYỆT ĐỐI KHÔNG** sửa đổi toán học affine transform, neo tâm (center anchoring), bước tiến khung hình (frame progression), đường dẫn Lanczos4 và pipeline rawvideo.
- **`FFMPEG_V1_BUILD_INDEPENDENT = TRUE`**: V1 luôn luôn độc lập, có thể build và chạy bình thường mà không cần bất kỳ module nào của V2.

---

## 3. PRODUCT V2 — CAPCUT AUTOEDIT EDITION (AUTOEDIT FOR CAPCUT)

### 3.1 Trách nhiệm & Phạm vi
- Tự động xây dựng timeline video có cấu trúc đa tầng (tracks, clips, audio, text).
- Tạo dự án / bản nháp (drafts) tương thích với CapCut Desktop theo ma trận tương thích rõ ràng (**VERIFIED**: CapCut Desktop 9.3.0 macOS Apple Silicon; **UNTESTED** / **UNSUPPORTED** đối với các bản build khác; không tuyên bố tương thích 100% toàn cầu).
- Cho phép người dùng mở trực tiếp dự án trong CapCut Desktop (`[Open in CapCut]`).

- Bảo toàn tính năng chỉnh sửa (editability) toàn diện:
  - Từng hình ảnh / video là một clip riêng biệt trên track chính.
  - Từng đoạn âm thanh nằm trên track âm thanh riêng.
  - Phụ đề / văn bản là text element bản địa, có thể sửa chữ, đổi font, đổi vị trí trực tiếp trong CapCut.
  - Keyframe chuyển động (Zoom in, Zoom out, Pan left, Pan right) sử dụng keyframe bản địa (`common_keyframes`).

### 3.2 Quan hệ với V1
- **`V2_DOES_NOT_REPLACE_V1 = TRUE`**: V2 **KHÔNG PHẢI** là bản thay thế cho V1. Đây là dòng sản phẩm song song phục vụ người dùng muốn hậu kỳ linh hoạt trên phần mềm CapCut Desktop.
- V2 không render video MP4 hoàn thiện. V2 sản xuất timeline project.

---

## 4. MA TRẬN PHÂN TÁCH CHI TIẾT (ISOLATION MATRIX)

| Tiêu chí | Product V1 (FFmpeg Edition) | Product V2 (CapCut AutoEdit Edition) |
| :--- | :--- | :--- |
| **Tên sản phẩm (Display Name)** | `2toolne Slideshow Studio` | `2toolne AutoEdit for CapCut` |
| **Mã định danh nội bộ (Internal ID)**| `2toolne.ffmpeg.v1` | `2toolne.capcut.v2` |
| **Kênh phiên bản (Version Stream)** | `FFMPEG_VERSION` (duy trì stream `2.3.x` / `1.x.x`) | `CAPCUT_VERSION` (bắt đầu từ `2.0.0-poc.1`) |
| **Mục tiêu đầu ra (Target Output)** | Tệp video `.mp4` hoàn chỉnh | Thư mục dự án CapCut Draft (`com.lveditor.draft`) |
| **Độ sâu chỉnh sửa sau xuất** | Video phẳng (Flat MP4) | Timeline đầy đủ, chỉnh sửa từng layer trong CapCut |
| **Công nghệ chuyển động (Motion)** | `cv2.INTER_LANCZOS4` Subpixel Affine Engine | CapCut Native Keyframes (`KFTypeScaleX`, `KFTypePositionX`)|
| **Cổng Web Server mặc định** | `8080` | `8088` |
| **Thư mục cài đặt / Cấu hình** | `~/.2toolne/slideshow-studio/` | `~/.2toolne/autoedit-capcut/` |
| **Tệp nhật ký (Logs)** | `logs/ffmpeg-v1.log` | `logs/capcut-v2.log` |
| **Sử dụng AI Planner** | Không (Deterministic) | **TUYỆT ĐỐI KHÔNG** (`AI_PLANNER = DISABLED`) |
| **Kịch bản Build & Đóng gói** | `BUILD_FFMPEG_V1` | `BUILD_CAPCUT_V2` |
| **`V1_AND_V2_BUILD_SEPARATELY`** | **TRUE** | **TRUE** |

---

## 5. CHÍNH SÁCH BẢO VỆ MÃ NGUỒN V1 TRONG SUỐT QUÁ TRÌNH PHÁT TRIỂN V2

1. Bất kỳ lỗi hay thay đổi nào trong mã nguồn V2 tại `apps/capcut-v2/` đều **bị cách ly hoàn toàn**, không được phép ảnh hưởng đến khả năng build, đóng gói và phát hành bản bảo trì của V1.
2. V1 không phụ thuộc vào bất kỳ thư viện đặc thù nào của CapCut.
3. Trước và sau mỗi lần can thiệp mã nguồn, toàn bộ bài kiểm thử hồi quy của V1 (`tests/test_zoom_regression_golden.py`, `tests/test_camera_engine.py`, `tests/test_mandatory_license_gate.py`, v.v.) phải chạy và đạt 100% **PASS**.
