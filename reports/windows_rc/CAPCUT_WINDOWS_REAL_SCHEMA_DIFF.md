# 📋 ĐẶC TẢ SCHEMA KỲ VỌNG TRÊN WINDOWS: WINDOWS_TARGET_SCHEMA (WINDOWS_SCHEMA_EXPECTATION)

> [!NOTE]
> **Định danh kỹ thuật**: `WINDOWS_TARGET_SCHEMA` (trước đây ghi nháp: `CAPCUT_WINDOWS_REAL_SCHEMA_DIFF.md`).
> Tài liệu này phản ánh **Kỳ vọng Schema danh định mục tiêu (Schema Expectation)** dựa trên đặc tả kỹ thuật và mô phỏng tiền phát hành.
> Tuyệt đối **không gọi là REAL SCHEMA** khi chưa có bằng chứng kiểm chứng tệp draft thực tế từ phòng lab Windows ngoài. Sau khi tester ngoài cung cấp tệp draft thực tế từ CapCut Windows, tài liệu này sẽ được cập nhật đối chiếu chính thức.

**Product**: 2TOOLNE AutoEdit for CapCut (Product Generation V2)  
**Phase**: 5B.1 — Windows CI Build & External Lab Preparation  
**Trạng thái Schema**: `WINDOWS_SCHEMA_EXPECTATION` (Target Specification)  
**Target Version**: CapCut Desktop 9.3.x  
**File Reference**: `draft_content.json` và `root_meta_info.json`  

---

## 1. TỔNG QUAN CẤU TRÚC BẢN NHÁP MỤC TIÊU (TARGET DRAFT ARCHITECTURE)

Hệ thống CapCut Desktop trên cả macOS và Windows chia sẻ cấu trúc JSON nền tảng từ engine JianYing/CapCut của ByteDance. Tuy nhiên, khi vận hành trên Windows, có những sai khác cốt lõi về môi trường thực thi (OS metadata) và cú pháp đường dẫn tệp (file path syntax) mà `CapCutVersionAdapter_9_3` phải xử lý chính xác.

---

## 2. BẢNG SO SÁNH CHI TIẾT CÁC TRƯỜNG DỮ LIỆU CỐT LÕI

| Khối dữ liệu | Trường JSON | macOS (9.3.0 Verified) | Windows (9.3.x Target) | Hành vi của `CapCutVersionAdapter_9_3` |
|:-------------|:------------|:-----------------------|:-----------------------|:---------------------------------------|
| **Metadata** | `platform.os` | `"mac"` | `"windows"` | Tự động phát hiện OS máy chủ hoặc cho phép truyền qua tham số adapter. Tránh pop-up "Convert project" của CapCut. |
| **Metadata** | `platform.app_version` | `"9.3.0"` | `"9.3.0"` | Đồng bộ phiên bản danh định |
| **Đường dẫn Media** | `materials.videos[].path` | `/Users/.../clip.mp4` | `C:\Users\...\clip.mp4` | Chuẩn hóa qua `os.path.normpath()`. Ký tự backslash được JSON encoder tự động escape thành `\\`. |
| **Đường dẫn Audio** | `materials.audios[].path` | `/Users/.../voice.mp3` | `C:\Users\...\voice.mp3` | Đồng nhất với media clip; hỗ trợ đường dẫn chứa dấu tiếng Việt Unicode đầy đủ. |
| **Keyframes** | `materials.keyframes[].keyframe_list` | Cấu trúc `KFTypeScaleX`, `KFTypePositionX` | Giữ nguyên 100% cấu trúc Microseconds & Normalized value (0.0 - 1.0) | Đồng nhất thuật toán nội suy keyframe native giữa hai nền tảng. |
| **Text/Subtitles** | `materials.texts[].content` | Chuỗi JSON escape XML string (`<font ...>`) | Giữ nguyên định dạng XML string escaping | Tương thích hoàn toàn phông chữ hệ thống Windows (Segoe UI, Arial, Roboto). |
| **Timeline Tracks** | `tracks[].segments` | Time mapping $(\mu\text{s})$ | Time mapping $(\mu\text{s})$ | Đồng nhất: $1\text{s} = 1,000,000\ \mu\text{s}$. |
| **Draft Registration** | `root_meta_info.json` | `draft_root_path: ...` | `draft_root_path: C:\Users\...\AppData\Local\...` | Ghi nhận chính xác đường dẫn tuyệt đối theo cú pháp Windows Drive letter (`C:\`). |

---

## 3. SO SÁNH CẤU TRÚC JSON CỤ THỂ (CODE DIFF)

### 3.1 Khối `platform` trong `draft_content.json`
```diff
--- macOS draft_content.json
+++ Windows draft_content.json
@@ -2,7 +2,7 @@
   "platform": {
     "app_version": "9.3.0",
-    "os": "mac",
+    "os": "windows",
     "os_version": "10.0.19045"
   },
```

### 3.2 Khối vật liệu Media (`materials.videos`)
```diff
--- macOS materials.videos
+++ Windows materials.videos
@@ -5,7 +5,7 @@
       "id": "video_mat_001",
       "type": "video",
-      "path": "/Users/user/Movies/CapCut/media_01.mp4",
+      "path": "C:\\Users\\User\\Videos\\CapCut\\media_01.mp4",
       "duration": 5000000,
       "width": 1080,
       "height": 1920
```

### 3.3 Khối chỉ mục `root_meta_info.json`
```diff
--- macOS root_meta_info.json
+++ Windows root_meta_info.json
@@ -8,8 +8,8 @@
     {
       "draft_id": "draft_test_windows_001",
       "draft_name": "Project_Test_Windows",
-      "draft_root_path": "/Users/user/Movies/CapCut/User Data/Projects/com.lveditor.draft",
-      "draft_fold_path": "/Users/user/Movies/CapCut/User Data/Projects/com.lveditor.draft/draft_test_windows_001",
+      "draft_root_path": "C:\\Users\\User\\AppData\\Local\\CapCut\\User Data\\Projects\\com.lveditor.draft",
+      "draft_fold_path": "C:\\Users\\User\\AppData\\Local\\CapCut\\User Data\\Projects\\com.lveditor.draft\\draft_test_windows_001",
       "tm_draft_create": 1757200000000000,
       "tm_draft_modify": 1757200000000000
     }
```

---

## 4. BẢO VỆ GIAO THỨC TRUY CẬP TẬP TIN TRÊN WINDOWS

1. **Khóa tập tin (`File Locking`)**:
   - macOS sử dụng `fcntl.flock()`.
   - Windows không có `fcntl`. `CapCutProjectManager` đã cài đặt cơ chế khóa tệp đa tầng (`file_lock`) với graceful fallback trên Windows, tránh xung đột tiến trình khi CapCut Desktop đang mở.
2. **Ghi tệp nguyên tử (`Atomic File Replacement`)**:
   - Sử dụng `os.replace(temp_path, target_path)`. Trên Windows (kể từ Python 3.3+), `os.replace` ánh xạ trực tiếp vào Win32 API `MoveFileExW` với cờ `MOVEFILE_REPLACE_EXISTING`, bảo đảm tính nguyên tử và chống hỏng file `root_meta_info.json` khi mất điện đột ngột.
3. **Mã hóa ký tự Unicode**:
   - Toàn bộ thao tác đọc/ghi JSON trên Windows được cưỡng chế `encoding="utf-8"`, bảo đảm hỗ trợ đầy đủ các đường dẫn chứa ký tự tiếng Việt có dấu.
