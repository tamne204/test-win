# KIẾN TRÚC ỨNG DỤNG NATIVE DESKTOP — 2TOOLNE AUTOEDIT FOR CAPCUT (V2)
**Tài liệu kỹ thuật:** `docs/CAPCUT_V2_DESKTOP_ARCHITECTURE.md`  
**Phiên bản:** 2.0.0 Desktop  
**Trạng thái:** ACTIVE / PRODUCTION-READY  

---

## 1. TỔNG QUAN KIẾN TRÚC (ARCHITECTURE OVERVIEW)

2TOOLNE AutoEdit for CapCut (Product V2) hoạt động như một ứng dụng Native Desktop hoàn chỉnh trên macOS và Windows, hoàn toàn loại bỏ giao diện web và máy chủ HTTP (Flask/localhost/port 8088).

```
+-------------------------------------------------------------------------+
|                        ELECTRON DESKTOP SHELL                           |
|                                                                         |
|  +-------------------------------------------------------------------+  |
|  |                  DESKTOP RENDERER UI (HTML/CSS/JS)                |  |
|  |   - Native dark theme UI           - Visual media asset list      |  |
|  |   - Preset & Timing selector       - Real-time 7-stage progress   |  |
|  |   - CapCut status indicator        - Success & Error modals       |  |
|  +---------------------------------+---------------------------------+  |
|                                    | window.autoedit (typed API)        |
|  +---------------------------------v---------------------------------+  |
|  |                       PRELOAD SECURITY BRIDGE                     |  |
|  |   - contextIsolation = true        - nodeIntegration = false      |  |
|  |   - Zero raw fs/child_process      - Zero shell execution         |  |
|  +---------------------------------+---------------------------------+  |
|                                    | IPC invoke (main channel)          |
|  +---------------------------------v---------------------------------+  |
|  |                   ELECTRON MAIN PROCESS (Node.js)                 |  |
|  |   - Window lifecycle               - Native file dialogs          |  |
|  |   - Sidecar Process Manager        - Signal & exit cleanup        |  |
|  +---------------------------------+---------------------------------+  |
+------------------------------------|------------------------------------+
                                     | stdin / stdout (Line JSON-RPC)
                                     | No HTTP / No localhost / No ports
+------------------------------------v------------------------------------+
|                     PYTHON CORE SIDECAR (autoedit-core)                 |
|                                                                         |
|  +-------------------------------------------------------------------+  |
|  |                   DESKTOP BRIDGE & PROTOCOL ROUTER                |  |
|  |   - protocol.py: JSON-RPC v1       - bridge.py: Command dispatch  |  |
|  +-------------------------------------------------------------------+  |
|  |                       DETERMINISTIC V2 CORE                       |  |
|  |   - TimelineBuilder                - RuleEngine (Pure math)       |  |
|  |   - PresetManager                  - SRT Scene Timing Math        |  |
|  |   - EditPlan Specification         - Zero AI / Deterministic      |  |
|  +-------------------------------------------------------------------+  |
|  |                       TRANSACTIONAL ADAPTER                       |  |
|  |   - CapCutDetector (Multi-probe)   - CapCutAdapterRegistry        |  |
|  |   - CapCutVersionAdapter_9_3       - CapCutDraftValidator         |  |
|  |   - CapCutProjectManager (Lock/Fsync/Rollback)                    |  |
|  |   - CapCutLauncher (Safe native open)                             |  |
|  +-------------------------------------------------------------------+  |
+-------------------------------------------------------------------------+
```

---

## 2. GIAO THỨC IPC (LINE-DELIMITED JSON-RPC PROTOCOL)

Giao tiếp giữa Electron Main và Python Sidecar hoàn toàn qua đường ống chuẩn `stdin` và `stdout` của tiến trình con. Không dùng bất kỳ cổng mạng (port) nào.

### 2.1. Cấu trúc bản tin Request (Electron -> Python)
Mỗi bản tin là một dòng JSON kết thúc bằng ký tự `\n`:
```json
{
  "id": "e8d64112-9bf1-4286-bf55-0814bb65ec52",
  "protocol": 1,
  "method": "GENERATE_CAPCUT_PROJECT",
  "params": {
    "images": ["/path/to/img1.png", "/path/to/img2.png"],
    "audio_path": "/path/to/audio.wav",
    "srt_source": "/path/to/subs.srt",
    "timing_mode": "SRT_DRIVEN",
    "preset_id": "basic_slideshow",
    "project_name": "My AutoEdit Project",
    "auto_install": true
  }
}
```

### 2.2. Cấu trúc bản tin Response (Python -> Electron)
```json
{
  "id": "e8d64112-9bf1-4286-bf55-0814bb65ec52",
  "protocol": 1,
  "ok": true,
  "result": {
    "project_id": "84B8E1DE-4A2D-4B44-B52E-DF70DE14E691",
    "project_name": "My AutoEdit Project",
    "final_draft_dir": "/Users/.../com.lveditor.draft/2toolne_1788710375_...",
    "duration_s": 12.0,
    "clip_count": 3
  }
}
```

### 2.3. Cấu trúc bản tin Notification (Python -> Electron)
Được phát ra trong quá trình xử lý tác vụ dài mà không cần chờ request hoàn tất:
```json
{
  "type": "notification",
  "protocol": 1,
  "event": "progress",
  "data": {
    "stage": "GENERATING_CAPCUT_DRAFT",
    "percent": 65,
    "message": "Sinh cấu trúc dự án CapCut trong staging..."
  }
}
```

### 2.4. Danh mục lệnh hỗ trợ (12 Commands)
1. `PING`: Kiểm tra kết nối và tính tương thích của phiên bản giao thức.
2. `GET_APP_INFO`: Lấy thông tin ứng dụng, phiên bản sản phẩm `2.0.0`, platform OS.
3. `DETECT_CAPCUT`: Dò tìm cài đặt CapCut Desktop trên macOS hoặc Windows.
4. `GET_PRESETS`: Lấy danh sách các preset có sẵn và preset mặc định.
5. `SAVE_CUSTOM_PRESET`: Lưu preset do người dùng tùy biến dạng JSON.
6. `DELETE_CUSTOM_PRESET`: Xóa preset tùy biến.
7. `VALIDATE_INPUTS`: Kiểm tra sự tồn tại của tệp media trước khi chạy.
8. `BUILD_EDIT_PLAN`: Sinh cấu trúc EditPlan trung gian.
9. `GENERATE_CAPCUT_PROJECT`: Thực hiện toàn bộ quy trình dựng và cài đặt giao dịch an toàn.
10. `OPEN_CAPCUT`: Khởi chạy ứng dụng CapCut Desktop.
11. `GET_PROJECT_STATUS`: Đọc trạng thái từ manifest workspace.
12. `GET_DIAGNOSTICS`: Lấy báo cáo chẩn đoán hệ thống an toàn.
13. `GENERATE_SRT_FROM_SCRIPT`: Căn chỉnh kịch bản văn bản với âm thanh và sinh cấu trúc SubtitleCue + SRT.
14. `GET_SUBTITLE_ALIGNMENT_STATUS`: Truy vấn tiến độ tiến trình căn chỉnh phụ đề thời gian thực.
15. `CANCEL_SUBTITLE_ALIGNMENT`: Hủy tác vụ căn chỉnh phụ đề đang thực thi.
16. `EXPORT_SRT`: Xuất nội dung phụ đề SRT ra tệp độc lập theo đường dẫn người dùng chọn.

---

## 3. BẢO MẬT & RANH GIỚI TIẾN TRÌNH (SECURITY BOUNDARIES)

1. **Context Isolation:** `contextIsolation: true` bắt buộc trong BrowserWindow. Mã JavaScript của giao diện người dùng (Renderer) chạy trong ngữ cảnh tách biệt hoàn toàn khỏi Node.js runtime.
2. **Node Integration:** `nodeIntegration: false` ngăn chặn Renderer truy cập trực tiếp vào `require()`, `process`, `Buffer`, hay module hệ thống.
3. **Preload Whitelist:** `preload.js` sử dụng `contextBridge.exposeInMainWorld('autoedit', ...)` chỉ công khai các hàm tác vụ được định kiểu chặt chẽ.
4. **Không Shell Injection:** Tuyệt đối không dùng `shell=True` trong Python hay chuỗi nối lệnh shell trong Electron. Việc mở ứng dụng dùng danh sách đối số trực tiếp:
   - macOS: `subprocess.Popen(["open", "-a", "CapCut"])`
   - Windows: `subprocess.Popen([status.app_path])`
   - Mở thư mục dự án dùng `shell.openPath(folderPath)`.

---

## 4. QUẢN LÝ TIẾN TRÌNH SIDECAR (PROCESS LIFECYCLE)

1. **Khởi động:** Khi Electron sẵn sàng (`app.whenReady`), `SidecarManager` tự động spawn tiến trình `autoedit-core`. Ngay sau khi spawn, gửi lệnh `PING` kiểm tra handshake.
2. **Chế độ ẩn trên Windows:** Trên Windows, sidecar được khởi chạy với cờ `windowsHide: true`, bảo đảm không xuất hiện bất kỳ cửa sổ CMD hay PowerShell nào trên màn hình khách hàng.
3. **Đóng ứng dụng:** Khi sự kiện `before-quit` hoặc `window-all-closed` xảy ra, Electron đóng luồng stdin của sidecar (phát tín hiệu EOF) và gửi `SIGTERM`. Nếu tiến trình chưa dừng sau 2 giây, gửi `SIGKILL` để tuyệt đối không để lại tiến trình zombie.

---

## 5. ĐÓNG GÓI ĐỘC LẬP (STANDALONE PACKAGING)

Khách hàng tải ứng dụng về không cần cài đặt Python, pip, hay virtualenv:
- **Python Runtime:** Được đóng gói trọn gói bằng PyInstaller (`apps/capcut-v2/packaging/build_sidecar.py`):
  - macOS: `packaging/dist/autoedit-core/autoedit-core`
  - Windows: `packaging/dist/autoedit-core/autoedit-core.exe`
- **Electron Shell:** Được đóng gói bằng `electron-builder`:
  - macOS: Tệp `2toolne AutoEdit.app` và gói cài đặt `.dmg`. Sidecar được đặt tại `Contents/Resources/autoedit-core/`.
  - Windows: Gói cài đặt NSIS `.exe` và bản portable.

---

## 6. ĐƯỜNG DẪN DỮ LIỆU & WORKSPACE CỤC BỘ (APP DATA & WORKSPACE)

Hệ thống tuân thủ chuẩn lưu trữ của từng hệ điều hành, không ghi vào thư mục cài đặt ứng dụng:
- **macOS:** `~/Library/Application Support/2toolne AutoEdit/`
  - `projects/`: Chứa các workspace dự án (`<project_id>/metadata/project.json`, `generated/`).
  - `presets/`: Chứa các preset người dùng lưu trữ.
- **Windows:** `%APPDATA%\2toolne AutoEdit\`
  - `projects\`: Workspace dự án.
  - `presets\`: Preset tùy biến.

---

## 7. QUY TRÌNH DỰNG VIDEO GIAO DỊCH (7-STAGE TRANSACTIONAL PIPELINE)

1. **`VALIDATING_INPUT` (15%):** Kiểm tra tệp ảnh, video, audio, SRT tồn tại trên đĩa.
2. **`BUILDING_TIMELINE` (30%):** Dùng `TimelineBuilder` tính toán timing theo mode (Fixed hoặc SRT-Driven).
3. **`VALIDATING_EDIT_PLAN` (45%):** Áp dụng 7 cổng kiểm soát nghiêm ngặt của `EditPlan`.
4. **`GENERATING_CAPCUT_DRAFT` (65%):** Sinh cấu trúc `draft_info.json`, `draft_meta_info.json`, `draft_cover.jpg` trong staging.
5. **`VALIDATING_DRAFT` (75%):** Dùng `CapCutDraftValidator` kiểm tra cấu trúc schema, tính đơn nhất UUID, keyframe đơn điệu.
6. **`INSTALLING_PROJECT` (85%):** Khóa tệp `fcntl.flock`, sao lưu `root_meta_info.json.bak`, sao chép thư mục draft, ghi nguyên tử với `os.fsync`.
7. **`READY` (100%):** Hoàn tất, trả về thông tin dự án cho người dùng mở trực tiếp vào CapCut Desktop.

---

## 8. HỆ THỐNG PHỤ ĐỀ SCRIPT-TO-SRT (PHASE 5C)

Từ phiên bản Phase 5C, hệ thống bổ sung phân hệ phụ đề `core.subtitles`:
- **Nguồn chân lý duy nhất (Source of Truth):** Kịch bản gốc của người dùng là tuyệt đối. ASR (Whisper) chỉ dùng để trích xuất timestamp, không bao giờ viết đè nội dung.
- **Quy chuẩn `2TOOLNE_STANDARD_SUBTITLE`:** Tối đa ~12 từ/cue, ngắt theo dấu câu, thời lượng 0.6s - 5.0s, không để cụt 1 từ, không chồng lấn thời gian.
- **Giao diện tương tác Preview & Căn chỉnh:** Hộp thoại modal cho phép người dùng kiểm tra các mốc cue, chỉnh sửa text, sửa thời gian, gộp câu hoặc xuất tệp `.srt` độc lập trước khi gắn vào dự án CapCut.
- **Bảo mật bản quyền:** Phương thức `GENERATE_SRT_FROM_SCRIPT` được bảo vệ qua `LicenseGuard.require_entitlement("script_to_srt")`.
