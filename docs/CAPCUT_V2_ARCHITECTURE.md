# 🏗️ THIẾT KẾ KIẾN TRÚC CAPCUT V2 — 2TOOLNE AUTOEDIT EDITION

**Architecture Version**: 1.0.0  
**Status**: APPROVED & IMPLEMENTING  
**Module Location**: `apps/capcut-v2/`

---

## 1. TỔNG QUAN KIẾN TRÚC HỆ THỐNG (SYSTEM OVERVIEW)

2toolne AutoEdit là thế hệ ứng dụng mới được thiết kế để tự động tạo ra các dự án video có thể biên tập được trên các phần mềm dựng phim phi tuyến tính (NLE - Non-Linear Editor), với mục tiêu ưu tiên số 1 là **CapCut Desktop**.

Hệ thống hoạt động theo nguyên tắc **Deterministic Pipeline** (đường ống xác định, không dùng AI/LLM):

```
       [Raw Inputs] (Images, Audio, SRT)
            │
            ▼
   ┌──────────────────┐
   │ TimelineBuilder  │ ──> Tính toán mốc thời gian, gán track, phân bổ clip
   └────────┬─────────┘
            │
            ▼
   ┌──────────────────┐
   │    RuleEngine    │ ──> Áp dụng quy tắc chuyển động (Zoom In, Zoom Out, Pan...)
   └────────┬─────────┘
            │
            ▼
   ┌──────────────────┐
   │     EditPlan     │ ──> [SOURCE OF TRUTH] Định dạng trung gian độc lập NLE
   └────────┬─────────┘
            │
            ▼
   ┌──────────────────┐
   │  CapCutAdapter   │ ──> Phân giải Adapter theo phiên bản (CapCutVersionAdapter)
   └────────┬─────────┘
            │
            ▼
   ┌──────────────────┐
   │  ProjectManager  │ ──> Ghi bản nháp, bảo vệ dữ liệu, cập nhật root_meta_info
   └────────┬─────────┘
            │
            ▼
   ┌──────────────────┐
   │  CapCutLauncher  │ ──> Khởi chạy CapCut Desktop và mở dự án
   └──────────────────┘
```

---

## 2. CÁC THÀNH PHẦN CỐT LÕI (CORE COMPONENTS)

### 2.1 EditPlan — Nguồn Chân Lý Độc Lập (Source of Truth)
`EditPlan` là mô hình dữ liệu trung gian chuẩn hóa mô tả toàn bộ dự án video trước khi được chuyển đổi sang bất kỳ định dạng NLE cụ thể nào.

Mô hình cấu trúc:
- **`EditPlanProject`**:
  - `name`: Tên dự án.
  - `width`: Chiều rộng canvas (mặc định 1080).
  - `height`: Chiều cao canvas (mặc định 1920 cho 9:16 vertical).
  - `fps`: Tốc độ khung hình (60.0).
  - `duration_us`: Tổng thời lượng dự án (microsecond).
- **`EditPlanClip`**:
  - `clip_id`: UUID phân đoạn.
  - `media_path`: Đường dẫn tệp media gốc.
  - `media_type`: `"image"` hoặc `"video"`.
  - `start_us`: Thời điểm bắt đầu trên timeline (microsecond).
  - `duration_us`: Thời lượng hiển thị (microsecond).
  - `motion_type`: Loại chuyển động (`"ZOOM_IN"`, `"ZOOM_OUT"`, `"PAN_LEFT"`, `"PAN_RIGHT"`, `"NONE"`).
  - `keyframe_params`: Các tham số bổ trợ chuyển động.
- **`EditPlanAudio`**:
  - `audio_id`: UUID âm thanh.
  - `audio_path`: Đường dẫn tệp âm thanh.
  - `start_us`: Thời điểm bắt đầu.
  - `duration_us`: Thời lượng.
  - `volume`: Âm lượng (1.0).
- **`EditPlanCaption`**:
  - `caption_id`: UUID phụ đề.
  - `text`: Nội dung văn bản.
  - `start_us`: Thời điểm bắt đầu.
  - `duration_us`: Thời lượng hiển thị.
  - `style`: Cấu hình màu sắc, kích thước font.

### 2.2 RuleEngine — Động cơ Quy tắc Biên tập (Deterministic Rules)
- Hoàn toàn **Deterministic & Rule-Based**, không dùng mạng nơ-ron hoặc LLM.
- Hỗ trợ các mẫu chuyển động cơ bản:
  - `ZOOM_IN`: Phóng to dần từ $1.00 \rightarrow 1.15$.
  - `ZOOM_OUT`: Thu nhỏ dần từ $1.15 \rightarrow 1.00$.
  - `PAN_LEFT`: Dịch chuyển camera từ phải qua trái ($X = +0.10 \rightarrow -0.10$).
  - `PAN_RIGHT`: Dịch chuyển camera từ trái qua phải ($X = -0.10 \rightarrow +0.10$).
- Trình tự phân bổ chuyển động mặc định cho danh sách slide:
  $$\text{Slide } 0 \rightarrow \text{ZOOM\_IN}, \quad \text{Slide } 1 \rightarrow \text{ZOOM\_OUT}, \quad \text{Slide } 2 \rightarrow \text{PAN\_LEFT}, \quad \text{Slide } 3 \rightarrow \text{PAN\_RIGHT} \quad (\text{lặp lại})$$

### 2.3 TimelineBuilder — Xây dựng Dòng Thời gian
- Tiếp nhận danh sách tệp ảnh, tệp âm thanh, phụ đề tùy chọn và preset cấu hình.
- Tính toán mốc thời gian không chồng chéo (non-overlapping):
  - Phân đoạn 1: $0 \rightarrow 5,000,000\ \mu\text{s}$ (0–5s).
  - Phân đoạn 2: $5,000,000 \rightarrow 10,000,000\ \mu\text{s}$ (5–10s).
  - Phân đoạn 3: $10,000,000 \rightarrow 15,000,000\ \mu\text{s}$ (10–15s).
- Gán track và chuyển động thông qua `RuleEngine` để tạo ra `EditPlan`.

---

## 3. LỚP TƯƠNG THÍCH CAPCUT (CAPCUT ADAPTER LAYER)

### 3.1 Detector (`detector.py`)
- Kiểm tra sự hiện diện của CapCut trên hệ thống:
  - macOS: `/Applications/CapCut.app` (kiểm tra `Info.plist`).
  - Windows: `%LOCALAPPDATA%\CapCut\Apps`.
- Đọc số phiên bản cài đặt (VD: `9.3.0`).
- Trả về mã trạng thái chuẩn hóa:
  - `CAPCUT_VERSION_SUPPORTED`
  - `CAPCUT_VERSION_UNTESTED`
  - `CAPCUT_NOT_FOUND`

### 3.2 CapCutAdapter & Version Adapters
- `CapCutAdapter`: Lớp điều phối cấp cao.
- `CapCutVersionAdapter_9_3`: Lớp chuyên biệt sinh dữ liệu tương thích định dạng CapCut 9.3.0:
  - Sao chép media vào thư mục bền vững của dự án (`media/`, `audio/`).
  - Khởi tạo đầy đủ danh mục `materials` (`videos`, `audios`, `texts`, `speeds`, `canvases`, `placeholder_infos`, `sound_channel_mappings`, `material_colors`, `vocal_separations`).
  - Tạo cấu trúc tracks (`video`, `audio`, `text`).
  - Gắn native keyframes (`KFTypeScaleX`, `KFTypePositionX`) vào từng segment.
  - Xuất ra `draft_info.json`, `draft_meta_info.json`, và thumbnail `draft_cover.jpg`.

### 3.3 ProjectManager (`project_manager.py`)
- Quản lý không gian sinh dự án cách ly (`projects_capcut/<project_id>/`).
- **Cơ chế an toàn tuyệt đối**:
  - Sao lưu tự động `root_meta_info.json` thành `root_meta_info.json.bak.<ts>`.
  - Sao chép dự án hoàn thiện vào thư mục `com.lveditor.draft/<project_name>`.
  - Ghi bản ghi mới vào đầu mảng `all_draft_store` của `root_meta_info.json`.
  - Tự động kiểm tra lại tính toàn vẹn (structural integrity) của file JSON trước khi kết thúc.

### 3.4 CapCutLauncher (`launcher.py`)
- Khởi chạy CapCut Desktop bằng cơ chế gọi hệ điều hành an toàn:
  - macOS: `open -a CapCut`
  - Windows: `start "" "%LOCALAPPDATA%\CapCut\..."`
- Không sử dụng tự động hóa tọa độ chuột hay macro màn hình.

---

## 4. TIỀM NĂNG MỞ RỘNG TRONG TƯƠNG LAI (FUTURE ADAPTERS)

Mô hình `EditPlan` độc lập cho phép dễ dàng cắm thêm các Adapter mới trong các phiên bản sau mà không cần thay đổi TimelineBuilder hay RuleEngine:
- `PremiereAdapter` (xuất FCPXML hoặc Premiere Pro XML)
- `DaVinciAdapter` (xuất DaVinci Resolve EDL / OTIO / FCPXML)
- `FinalCutAdapter` (xuất FCPXML)
- `CapCutVersionAdapter_10_x` (khi CapCut nâng cấp phiên bản lớn)
