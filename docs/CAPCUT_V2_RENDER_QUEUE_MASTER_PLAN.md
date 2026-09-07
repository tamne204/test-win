# 2TOOLNE AUTOEDIT FOR CAPCUT V2
# KẾ HOẠCH TỔNG THỂ: CAPCUT NATIVE EXPORT AUTOMATION & RENDER QUEUE
## TRẠNG THÁI: PLAN-ONLY (ĐÃ ĐÓNG BĂNG — KHÔNG TRIỂN KHAI MÃ NGUỒN Ở PHASE NÀY)

---

### BẢNG ĐIỀU HÀNH VÀ QUYẾT ĐỊNH CUỐI CÙNG (SECTION 38)

```text
================================================================================
           2TOOLNE AUTOEDIT — CAPCUT RENDER QUEUE MASTER PLAN
================================================================================

CAPCUT_OFFICIAL_HEADLESS_API        = NO
CAPCUT_NATIVE_EXPORT_AUTOMATION     = TARGET_FEATURE
CAPCUT_UI_VISIBLE_REQUIREMENT       = INTERACTIVE_DESKTOP_REQUIRED
USER_PRIMARY_INTERFACE              = 2TOOLNE
CAPCUT_BACKGROUND_OPERATION_TARGET  = YES_WHERE_PHYSICALLY_VERIFIED
MULTI_PROJECT_RENDER_QUEUE          = YES
PARALLEL_CAPCUT_INSTANCES           = NO_FOR_V1 (ONE INSTANCE / ONE ACTIVE JOB)

CAPCUT_MAC_SUPPORTED_VERSION        = 9.4.0 (Draft Workflow Supported, Render Queue Unverified)
CAPCUT_WINDOWS_SUPPORTED_VERSION    = 9.3.0 (Exact Verified Build — Phase 5E Render Target)
PLATFORM_INDEPENDENT_TARGETS        = YES
VERSION_LOCK                        = STRICT_EXACT_VERIFIED_BUILD_PER_PLATFORM

CAPCUT_CLI_STRATEGY                 = REFERENCE_ONLY
AUTOMATION_STACK                    = PENDING_WINDOWS_UI_CAPABILITY_PROBE
AUTOMATION_ARCHITECTURE             = HYBRID_ORCHESTRATOR
OUTPUT_VERIFICATION                 = MANDATORY (MULTI-LAYER VERIFICATION)
QUEUE_PERSISTENCE                   = ATOMIC_QUEUE_STATE_SNAPSHOT
WINDOWS_PHYSICAL_VALIDATION         = MANDATORY

NEXT_GATE                           = PHASE 5E.0 (WINDOWS CAPCUT UI CAPABILITY PROBE)
CODE_MODIFICATION_AT_THIS_STAGE     = STRICTLY_FORBIDDEN
================================================================================
```

---

## 1. MỤC TIÊU SẢN PHẨM (PRODUCT GOAL)
Người dùng thao tác chủ yếu trong giao diện 2TOOLNE Desktop:
* Trong mỗi thẻ dự án có 3 nút điều hướng:
  * `[Open in CapCut]`: Mở trực tiếp dự án trong CapCut để chỉnh sửa thủ công.
  * `[Render Now]`: Đẩy dự án vào tiến trình xuất ngay lập tức.
  * `[Add to Render Queue]`: Thêm dự án vào danh sách chờ xuất hàng loạt.
* Khu vực **RENDER QUEUE**:
  * Dự án A: `DONE`
  * Dự án B: `RENDERING` (Kèm thời gian/trạng thái thực)
  * Dự án C: `WAITING`
  * Dự án D: `WAITING`
* **Giá trị cốt lõi:** Người dùng không còn phải tự mở từng dự án, bấm nút Export, chọn đường dẫn xuất, chờ xuất xong rồi chuyển sang dự án tiếp theo. 2TOOLNE sẽ tự động điều phối CapCut Desktop theo hàng đợi tuần tự.

---

## 2. SỰ THẬT KỸ THUẬT (TECHNICAL TRUTH)
* CapCut Desktop **hoàn toàn không phải là Headless Render Engine**.
* Không tồn tại bất kỳ lệnh dòng lệnh nào dạng `CapCut.exe --render ...`, không có official render CLI, không có official headless API.
* Bản chất kiến trúc là: **GUI AUTOMATION OF CAPCUT DESKTOP**.
* CapCut vẫn phải chạy trong một phiên đăng nhập Windows tương tác thực tế (**interactive Windows desktop session**).
* **Quy chuẩn ngôn từ sản phẩm:** Gọi là `"CapCut Automated Render Queue"`, tuyệt đối không gọi là `"CapCut Headless Render"`.

---

## 3. MỤC TIÊU TRẢI NGHIỆM NGƯỜI DÙNG (USER EXPERIENCE TARGET)
* 2TOOLNE luôn là ứng dụng chính nằm ở foreground (phía trước).
* CapCut Desktop:
  * Tự động được khởi động khi cần xuất video.
  * Tự động mở đúng dự án mục tiêu.
  * Tự động kích hoạt hộp thoại Export và bắt đầu xuất.
  * Nằm phía sau (background/z-order) 2TOOLNE khi có thể.
  * Không đòi hỏi bất kỳ thao tác chuột/phím thủ công nào từ người dùng.
* **Không hứa hẹn những điều phi thực tế:** Không hứa CapCut hoàn toàn vô hình (`SW_HIDE`), không chạy như Windows Service ngầm (Session 0), không xuất khi màn hình Windows bị khóa (Win+L).
* Nếu một số bước automation cần focus (như gửi phím tắt `Ctrl+E`): CapCut có thể nổi lên foreground trong thời gian cực ngắn (< 1-2 giây), sau đó trả focus ngay về cho 2TOOLNE.

---

## 4. BẢO TOÀN NGUYÊN VẸN KIẾN TRÚC HIỆN TẠI
Kiến trúc cốt lõi hiện có của 2TOOLNE:
$$\text{EditPlan} \longrightarrow \text{CapCutAdapter} \longrightarrow \text{CapCut Draft Files}$$
được **GIỮ NGUYÊN VẸN 100% VÀ ĐÓNG BĂNG**.

Phân hệ Render chỉ là tầng hạ nguồn đứng sau:
$$\text{CapCut Draft} \longrightarrow \text{RenderQueueManager} \longrightarrow \text{CapCut Automation} \longrightarrow \text{CapCut Desktop} \longrightarrow \text{MP4 Output}$$

Tuyệt đối không sửa:
* FFmpeg V1 (`subpixel_affine_engine.py`)
* EditPlan & TimelineBuilder
* RuleEngine & Ken Burns motion
* Script-to-SRT Alignment Engine
* License security & storage architecture
* CapCut Draft generation logic

---

## 5. VAI TRÒ CỦA `renezander030/capcut-cli`
* Chỉ sử dụng làm: **REFERENCE IMPLEMENTATION** (Tài liệu tham khảo ý tưởng).
* **Tuyệt đối không bundle package này** vào 2TOOLNE.
* **Tuyệt đối không thay thế backend hiện tại bằng `capcut-cli`**.
* Tiếp thu các kinh nghiệm thực tế: Phím tắt `Ctrl+E`, cách định danh process `CapCut`.
* Không tái sử dụng logic queue của CLI vì không đạt tiêu chuẩn thương mại (không phát hiện render xong, không thẩm định đầu ra, không xử lý popup, không quản lý state machine).

---

## 6. YÊU CẦU BẮT BUỘC: VERSION LOCK & NỀN TẢNG ĐỘC LẬP
* **Hai mục tiêu tương thích độc lập hoàn toàn (Platform-Independent Baselines):**
  * **macOS:** `CAPCUT_MAC_SUPPORTED_VERSION = 9.4.0` (Tất cả thẩm định schema và tạo bản nháp Mac từ nay dùng chuẩn 9.4.0. Bản 9.3.0 trước đây được xếp loại là dữ liệu lịch sử/historical).
  * **Windows:** `CAPCUT_WINDOWS_SUPPORTED_VERSION = 9.3.0` (Mục tiêu duy nhất cho Phase 5E Render Automation ban đầu).
* Render Queue trên Windows chỉ được phép bật cho **CHÍNH XÁC PHIÊN BẢN BUILD CỦA CAPCUT 9.3.0 ĐÃ ĐƯỢC XÁC MINH VẬT LÝ (EXACT VERIFIED BUILD)**.
  * `CapCut 9.3.0 Build Verified` = VERIFIED (Phase 5E Target).
  * `CapCut 9.3.x Wildcard` = NOT VERIFIED (Bị chặn).
  * `CapCut 9.4.x / Latest trên Windows` = NOT VERIFIED (Bị chặn cho tới khi nghiệm thu máy thật).
* **Quy tắc bảo vệ Version Guard theo nền tảng:**
  ```python
  if platform == "windows" and capcut_build == "9.3.0_verified":
      enable_render_queue()
  else:
      disable_render_queue()  # macOS 9.4.0 hỗ trợ Draft nhưng Render Queue chưa mở
  ```
* **Ma trận phân tách 4 năng lực độc lập:**
  * `DRAFT_GENERATION_SUPPORTED`
  * `PROJECT_OPEN_SUPPORTED`
  * `EDIT_SAVE_REOPEN_VERIFIED`
  * `RENDER_AUTOMATION_SUPPORTED`
  * *Ví dụ:*
    * macOS / 9.4.0: `DRAFT_GENERATION = YES`, `PROJECT_OPEN = YES`, `EDIT_SAVE_REOPEN = VERIFIED`, `RENDER_AUTOMATION = UNVERIFIED`.
    * Windows / 9.3.0: `DRAFT_GENERATION = YES (Static)`, `RENDER_AUTOMATION = PHASE_5E_TARGET`.

---

## 7. THIẾT KẾ RENDER PROFILE & ĐỊNH DANH BẢN BUILD (BUILD IDENTITY)
* **Các Profile ý niệm độc lập:**
  * `windows_capcut_9_3_0` (Mục tiêu chính yếu của Phase 5E).
  * `macos_capcut_9_4_0` (Profile ý niệm tương lai của macOS).
* **Định danh bản build thực tế (Build Identity) khi có dữ liệu máy lab:**
  * `displayed_version` (Phiên bản hiển thị trên UI, e.g. "9.3.0" hoặc "9.4.0").
  * `executable_file_version` (Phiên bản file nhị phân PE trên Windows / Mach-O trên Mac).
  * `build_number` (Số build nội bộ của CapCut).
  * `architecture` (`x86_64` hoặc `arm64`).
  * `executable_sha256` (Mã băm SHA256 của file `CapCut.exe` để truy vết tuyệt đối trong lab).
* Mỗi profile là một module cấu hình chuyên biệt đại diện cho một bản build:
  * `main_window_fingerprint` (Class, Title)
  * `export_shortcut` (`Ctrl+E`)
  * `export_dialog_fingerprint` (AutomationId, Class)
  * `uia_selectors` (Các bộ chọn UI Automation đã kiểm chứng)
  * `keyboard_navigation_map` (Bản đồ phím tab/enter thay thế)
  * `window_relative_fallback_data` (Tọa độ tương đối theo khung cửa sổ)
  * `vision_templates` (Ảnh mẫu nút Export nếu UIA bị mù)
  * `known_popup_fingerprints` (Danh sách popup đã biết)
  * `timeouts` & `output_behavior`
  * `automation_capability_flags`

> **Quy tắc:** Không triển khai profile khi chưa có dữ liệu kiểm chứng trên máy Windows thật.

---

## 8. CHƯA CHỐT CỐ ĐỊNH STACK AUTOMATION
Chưa vội đóng băng công nghệ (pywinauto, FlaUI, UIA3, Win32, hay Vision) cho đến khi có dữ liệu từ **Windows UI Capability Probe**.
Kiến trúc ứng viên là **Hybrid Orchestrator**:
```text
AutomationOrchestrator
        │
        ├── UIABackend
        ├── KeyboardBackend
        ├── Win32Backend
        └── VisionBackend
```
Backend được lựa chọn động dựa trên `RenderProfile` và kết quả Probe.

---

## 9. THỨ TỰ ƯU TIÊN TỰ ĐỘNG HÓA
1. **Windows UI Automation (UIA3 / Accessibility)** (Ưu tiên cao nhất, độc lập với DPI/màn hình)
2. **Keyboard shortcuts / navigation** (`Ctrl+E`, `Enter`, `Tab`)
3. **Win32 window management** (Focus, Z-order, Process Handle)
4. **Window-relative positioning** (Tọa độ tương đối theo góc cửa sổ)
5. **Vision / template matching** (So khớp hình ảnh mẫu)
6. **Absolute screen coordinates** (Fallback cuối cùng, tuyệt đối không phụ thuộc)

---

## 10. BƯỚC ĐI THỰC TẾ ĐẦU TIÊN: WINDOWS CAPCUT UI CAPABILITY PROBE
Trước khi viết mã nguồn Render Queue thật, bước đầu tiên là xây dựng một công cụ độc lập siêu nhẹ:
* **Tên concept:** `CapCutUiProbe.exe`
* Người kiểm thử (Tester) trên Windows **KHÔNG CẦN**: Git, Python, Node.js, Antigravity, hay source code.
* Tester chỉ cần: Máy Windows, ứng dụng CapCut và file `CapCutUiProbe.exe`.

---

## 11. DỮ LIỆU BỘ PROBE THU THẬP
* Thông số Windows: Phiên bản OS, tỷ lệ DPI (100%, 125%, 150%), độ phân giải màn hình.
* Thông số CapCut: Phiên bản hiển thị, phiên bản file binary, PID, HWND chính, window title, window class.
* Toàn bộ cây UI Automation (UIA Tree): `AutomationId`, `Name`, `ControlType`, `BoundingRectangle`.
* Các Control Pattern được hỗ trợ: `InvokePattern`, `ValuePattern`, `SelectionPattern`, `TogglePattern`, `RangeValuePattern`.
* Các cửa sổ con, dialog, popup xuất hiện.

---

## 12. QUY TRÌNH THỰC HIỆN CỦA BỘ PROBE
Tester thực hiện 4 bước trên máy Windows thật:
1. **BƯỚC 1:** Mở màn hình chỉnh sửa dự án CapCut $\rightarrow$ Bấm nút `Capture`.
2. **BƯỚC 2:** Mở hộp thoại Export thủ công $\rightarrow$ Bấm nút `Capture`.
3. **BƯỚC 3:** Bắt đầu xuất video thử nghiệm $\rightarrow$ Bấm nút `Capture`.
4. **BƯỚC 4:** Chờ video xuất hoàn tất $\rightarrow$ Bấm nút `Capture`.
* Probe tự động nén toàn bộ log chẩn đoán thành tệp `capcut_probe_report.zip` (hoàn toàn không chứa video hay media cá nhân của tester).

---

## 13. RA QUYẾT ĐỊNH DỰA TRÊN KẾT QUẢ PROBE
* **TRƯỜNG HỢP A (UIA Expose Đầy Đủ):** Nút Export, Resolution, FPS, Output Path, Nút Start Export, Progress đều có UIA Element $\rightarrow$ **UIA3 trở thành Backend chính**.
* **TRƯỜNG HỢP B (UIA Expose Một Phần):** Hộp thoại hiển thị nhưng nút bấm không có InvokePattern $\rightarrow$ **Dùng kiến trúc Hybrid: UIA + Keyboard + Win32**.
* **TRƯỜNG HỢP C (UIA Gần Như Bị Mù Do DirectX):** Giao diện CapCut vẽ hoàn toàn trên canvas DirectComposition $\rightarrow$ **Dùng Hybrid: Win32 + Keyboard + Window-relative + Vision**.
* **Nguyên tắc:** Không từ bỏ Render Queue chỉ vì UIA không toàn diện; linh hoạt theo kết quả thực tế.

---

## 14. TỐI THIỂU HÓA BỀ MẶT TỰ ĐỘNG HÓA GIAO DIỆN
Không tự động hóa toàn bộ CapCut từ đầu đến cuối:
* 2TOOLNE đã hoàn tất việc tạo và đăng ký Draft chuẩn xác vào thư mục dữ liệu của CapCut.
* Do đó, tự động hóa GUI chỉ bắt đầu từ phạm vi hẹp nhất:
  $$\text{OPEN PROJECT} \longrightarrow \text{TRIGGER EXPORT} \longrightarrow \text{START} \longrightarrow \text{WATCH PROGRESS}$$
* Càng ít tương tác UI thì độ ổn định thương mại càng cao.

---

## 15. CƠ CHẾ SỞ HỮU TIẾN TRÌNH (CAPCUT CONTROL OWNERSHIP)
Thiết kế trạng thái kiểm soát: `CAPCUT_OWNER`:
* `NONE`: CapCut chưa chạy.
* `MANUAL`: Người dùng đang tự mở và chỉnh sửa thủ công.
  * Nếu người dùng bấm `Open in CapCut`, hệ thống đặt `CAPCUT_OWNER = MANUAL`.
  * Hàng đợi Render Queue tự động chuyển sang: `PAUSED_USER_EDITING`.
* `RENDER_QUEUE`: Hàng đợi tự động đang điều phối CapCut.
  * Người dùng không được can thiệp vào CapCut khi đang ở trạng thái này. Nếu muốn sửa thủ công, phải bấm `Pause Queue` trước.
* **Nguyên tắc cốt tử:** Không bao giờ để người dùng và automation cùng điều khiển CapCut một lúc.

---

## 16. TRẢI NGHIỆM ĐIỀU KHIỂN HÀNG ĐỢI (RENDER QUEUE UX)
* **Thao tác theo từng dự án:**
  * `[Open in CapCut]`
  * `[Render Now]`
  * `[Add to Queue]`
* **Thao tác quản trị toàn cục (Global Queue Actions):**
  * `PAUSE`: Tạm dừng sau job hiện tại.
  * `RESUME`: Tiếp tục hàng đợi.
  * `RETRY`: Thử lại job lỗi.
  * `SKIP`: Bỏ qua job lỗi.
  * `CANCEL`: Hủy job đang chạy an toàn.
  * `STOP_AFTER_CURRENT`: Hoàn thành job hiện tại rồi dừng.
  * `CLEAR_COMPLETED`: Xóa sạch danh sách đã hoàn thành.

---

## 17. MÔ HÌNH ĐƠN CÔNG NHÂN (SINGLE CAPCUT WORKER)
* Trong phiên bản V1 của Render Queue: **Tuyệt đối không render nhiều cửa sổ CapCut song song**.
* Chỉ chạy **1 INSTANCE CAPCUT DUY NHẤT** xử lý **1 ACTIVE RENDER JOB**:
  $$\text{Project 1} \rightarrow \text{Render} \rightarrow \text{Verify} \rightarrow \text{Done} \longrightarrow \text{Project 2} \rightarrow \text{Render} \rightarrow \text{Verify} \rightarrow \dots$$

---

## 18. MÔ HÌNH DỮ LIỆU RENDER JOB
Cấu trúc thực thể `RenderJob` (lưu trữ trong bộ nhớ và snapshot JSON):
* `job_id`, `project_id`, `draft_id`, `draft_path`
* `output_path`, `output_filename`
* `render_profile_id`
* `render_settings` (độ phân giải, fps...)
* `status` (QUEUED, RENDERING, DONE, ERROR...)
* `created_at`, `started_at`, `finished_at`
* `retry_count`, `last_error`

---

## 19. CHÍNH SÁCH THIẾT LẬP RENDER (RENDER SETTINGS)
2TOOLNE có thể hiển thị các tùy chọn cấu hình: Resolution, FPS, Codec, Bitrate, Output Folder, Filename.
**NHƯNG:** Chỉ cho phép người dùng điều chỉnh thông số nào mà `RenderProfile` cụ thể đã xác minh là có thể tự động hóa được. Không giả định mọi dropdown trong hộp thoại Export đều có thể can thiệp được.

---

## 20. CÁC TRẠNG THÁI CỦA STATE MACHINE
1. `QUEUED`: Đang chờ trong hàng đợi.
2. `PRECHECK`: Kiểm tra điều kiện tiên quyết (bản quyền, file draft, version CapCut).
3. `STARTING_CAPCUT`: Khởi chạy tiến trình CapCut.
4. `WAITING_CAPCUT_READY`: Chờ cửa sổ CapCut ổn định.
5. `OPENING_PROJECT`: Mở dự án chỉ định.
6. `VERIFYING_PROJECT`: Xác thực timeline đã sẵn sàng.
7. `OPENING_EXPORT_DIALOG`: Kích hoạt hộp thoại xuất (`Ctrl+E`).
8. `CONFIGURING_EXPORT`: Cấu hình thông số xuất (nếu profile hỗ trợ).
9. `STARTING_EXPORT`: Bấm nút bắt đầu xuất video.
10. `RENDERING`: Đang trong tiến trình mã hóa video.
11. `VERIFYING_OUTPUT`: Thẩm định file video đầu ra.
12. `DONE`: Hoàn tất 100%.

---

## 21. CÁC TRẠNG THÁI LỖI (ERROR STATES)
* `CAPCUT_NOT_FOUND`: Không tìm thấy binary CapCut.
* `WRONG_CAPCUT_VERSION`: Bản CapCut không nằm trong danh bạ verified.
* `PROJECT_NOT_FOUND`: Thư mục draft bị thiếu.
* `PROJECT_OPEN_FAILED`: CapCut không mở được timeline.
* `EXPORT_DIALOG_NOT_FOUND`: Phím tắt không mở được hộp thoại xuất.
* `EXPORT_CONFIG_FAILED`: Lỗi khi điền thông số.
* `EXPORT_START_FAILED`: Không bấm được nút xác nhận xuất.
* `UNKNOWN_POPUP`: Xuất hiện popup lạ che khuất màn hình.
* `EXPORT_STALLED`: Tiến trình render bị đứng hình (mất nhịp tim).
* `EXPORT_TIMEOUT`: Vượt ngưỡng thời gian an toàn tối đa.
* `CAPCUT_CRASH`: Tiến trình CapCut biến mất đột ngột.
* `OUTPUT_INVALID`: Tệp MP4 đầu ra bị lỗi hoặc thiếu stream.
* `USER_INTERRUPTION`: Người dùng can thiệp làm gián đoạn.

---

## 22. CHIẾN LƯỢC PHÁT HIỆN TREO & TIMEOUT (HEARTBEAT / STALL DETECTION)
* **Không dùng công thức cứng** $\text{duration} \times 2 + 60$ làm chân lý duy nhất.
* **Ưu tiên cơ chế giám sát nhịp tim (Heartbeat Evidence):**
  * Phần trăm tiến trình trên UI có thay đổi?
  * File video đầu ra đã xuất hiện chưa?
  * Kích thước file đầu ra có đang tăng liên tục?
  * Thời gian sửa đổi (`mtime`) của file có nhảy?
  * Mức tiêu thụ GPU/CPU của `CapCut.exe` có đang hoạt động?
* Nếu có dấu hiệu hoạt động (heartbeat): Tiếp tục chờ đợi.
* Nếu không có bất kỳ hoạt động nào trong ngưỡng thời gian cho phép (ví dụ 60-90 giây): Đánh dấu `EXPORT_STALLED`.
* Absolute Timeout chỉ là ngưỡng bảo vệ trần cuối cùng (safety ceiling rộng).

---

## 23. THẨM ĐỊNH TỆP ĐẦU RA (OUTPUT VERIFICATION)
Không đánh dấu `DONE` chỉ vì hộp thoại xuất biến mất. Quy trình xác thực tuần tự:
1. Đường dẫn tệp video mong muốn.
2. Tệp xuất hiện trên ổ cứng.
3. Kích thước tệp tăng dần.
4. Kích thước tệp đạt trạng thái ổn định (ngừng tăng).
5. Tệp không còn bị chiếm quyền ghi bởi CapCut (giải phóng file lock).
6. Bộ phân tích container (`ffprobe`) đọc được cấu trúc hợp lệ.
7. Có luồng Video Stream hoàn chỉnh.
8. Thời lượng video khớp xấp xỉ với dòng thời gian thiết kế ($\pm 1.0\text{s}$).
9. Chính thức ghi nhận: `DONE`.
*(Lưu ý: Luồng Audio không bắt buộc nếu video không có âm thanh; không hard-code ngưỡng 500KB cứng nhắc).*

---

## 24. HIỂN THỊ TIẾN TRÌNH TRUNG THỰC (PROGRESS DISPLAY)
* Nếu CapCut UI expose được phần trăm: Hiển thị đúng phần trăm thực tế.
* Nếu CapCut UI không expose được phần trăm: **Tuyệt đối không vẽ phần trăm giả (không fake %)**.
* Hiển thị theo từng giai đoạn rõ ràng:
  * `Đang khởi động CapCut...`
  * `Đang mở dự án...`
  * `Đang chuẩn bị hộp thoại xuất...`
  * `Đang xuất video...` (Hiển thị heartbeat nếu có)
  * `Đang thẩm định tệp đầu ra...`

---

## 25. CHIẾN LƯỢC XỬ LÝ POP-UP AN TOÀN
* **KNOWN POPUP (Popup đã biết):**
  * Nhận dạng đa tín hiệu: PID, HWND, Window Class, AutomationId, Control Fingerprint (Text chỉ là tín hiệu phụ).
  * Thực thi hành động an toàn đã được định nghĩa trước trong Profile (ví dụ: bấm nút "✕" hoặc "Nhắc tôi sau").
* **UNKNOWN POPUP (Popup lạ / bất thường):**
  * Tự động chuyển Queue sang `PAUSE`.
  * Chụp ảnh màn hình lưu vào thư mục logs chẩn đoán.
  * Bắn thông báo lên giao diện 2TOOLNE cho người dùng.
  * **TUYỆT ĐỐI KHÔNG CLICK MÙ.**

---

## 26. VẬN HÀNH PHÍA SAU KHI ĐANG XUẤT (USER BACKGROUND USE)
* Sau khi tiến trình xuất đã bước vào trạng thái `RENDERING`: Người dùng có thể thoải mái sử dụng các phần mềm khác (2TOOLNE, Trình duyệt web, Photoshop, Excel...).
* Người dùng không được thao tác vào CapCut khi `CAPCUT_OWNER = RENDER_QUEUE`.
* Nếu các bước mở hộp thoại cần focus: CapCut chỉ nổi lên trong giây lát, sau khi `RENDERING` bắt đầu thì lập tức ưu tiên trả focus về cho 2TOOLNE.

---

## 27. CHÍNH SÁCH CỬA SỔ CAPCUT (WINDOW POLICY)
* Không giả định CapCut có thể ẩn hoàn toàn (`SW_HIDE`).
* Mục tiêu thương mại:
  * Tiến trình CapCut: `RUNNING`
  * Cửa sổ CapCut: `NORMAL`
  * Thứ tự hiển thị (Z-order): Nằm phía sau 2TOOLNE khi có thể.
* Thử nghiệm thu nhỏ (`MINIMIZE DURING RENDER`) chỉ được bật nếu kiểm chứng vật lý chứng minh CapCut không bị dừng render khi minimize.
* Không nhắm tới các môi trường phi thực tế: Windows Service, Session 0, Desktop bị khóa.

---

## 28. LƯU TRỮ TRẠNG THÁI HÀNG ĐỢI (QUEUE PERSISTENCE)
* Tên giải pháp: **ATOMIC_QUEUE_STATE_SNAPSHOT** (Không gọi là WAL).
* Tệp lưu: `%APPDATA%\2toolne-autoedit\render_queue_state.json`.
* Quy trình ghi: Ghi ra tệp `.tmp` $\rightarrow$ `flush/fsync` $\rightarrow$ thay thế nguyên tử (`atomic replace`).
* Tùy chọn tương lai: Bổ sung tệp `queue_events.jsonl` để lưu lịch sử sự kiện.

---

## 29. PHỤC HỒI SAU SỰ CỐ SẬP ỨNG DỤNG (CRASH RECOVERY)
Khi 2TOOLNE khởi động lại:
1. Đọc tệp trạng thái hàng đợi snapshot.
2. Tìm kiếm công việc chưa hoàn thành (unfinished job).
3. Kiểm tra xem tiến trình CapCut tương ứng còn chạy hay không.
4. Kiểm tra sự tồn tại của tệp video đầu ra.
5. Chạy module `OutputVerifier`.
6. Phân loại kết quả rõ ràng:
   * `DONE` (Đã xuất xong trong lúc sập)
   * `RETRY_REQUIRED` (Bị gián đoạn giữa chừng)
   * `INTERRUPTED` (Tiến trình bị ngắt)
   * `UNKNOWN` (Không xác định)
* **Tuyệt đối không tự ý khởi động lại việc xuất một cách mù quáng.**

---

## 30. NHẬT KÝ CHẨN ĐOÁN (DIAGNOSTICS)
Mỗi công việc xuất video đều ghi lại chi tiết:
* Phiên bản CapCut, mã Profile.
* Mốc thời gian bắt đầu, kết thúc, chuyển đổi trạng thái.
* Window fingerprints, sự kiện popup đã xử lý.
* Automation backend đã dùng.
* Đường dẫn xuất, kết quả thẩm định, mã lỗi nếu có.
* **Chụp ảnh màn hình (screenshot):** Chỉ chụp khi xảy ra lỗi hoặc xuất hiện popup lạ. Tuyệt đối không chụp màn hình liên tục để bảo vệ quyền riêng tư của người dùng.

---

## 31. BẢO MẬT HỆ THỐNG (SECURITY ARCHITECTURE)
* Tuyệt đối không tạo lệnh shell từ chuỗi nhập tự do của người dùng.
* Mọi đường dẫn tệp phải được truyền dưới dạng mảng tham số (Argument Arrays).
* PID phải lấy từ tiến trình CapCut đã xác thực.
* Không cho phép giao diện Renderer gọi trực tiếp lệnh OS Automation; toàn bộ automation phải nằm an toàn phía sau Python Core Sidecar đặc quyền.

---

## 32. CHIẾN LƯỢC NỀN TẢNG: WINDOWS FIRST & MACOS SCOPE
* Render Queue V1 tập trung **100% ƯU TIÊN NỀN TẢNG WINDOWS** với mục tiêu duy nhất là **CapCut 9.3.0 exact build**.
* macOS Render Queue là mục tiêu của tương lai (Conceptual Profile: `macos_capcut_9_4_0`), tuyệt đối không tự động tuyên bố hỗ trợ cho đến khi có kiểm chứng automation riêng.
* Toàn bộ quy trình tạo và xác thực bản nháp dự án (Draft Workflow) trên macOS từ nay hoạt động chuẩn hóa trên **CapCut 9.4.0** (các kết quả 9.3.0 trước đây được bảo lưu như dữ liệu lịch sử/historical).

---

## 33. CÁC GIAI ĐOẠN TRIỂN KHAI TRONG TƯƠNG LAI (FUTURE IMPLEMENTATION GATES)

### CỔNG 1: PHASE 5E.0 — WINDOWS CAPCUT UI CAPABILITY DISCOVERY
* **Mục tiêu:** Xây dựng bộ công cụ thăm dò `CapCutUiProbe.exe`, thu thập dữ liệu UIA thật trên máy Windows, chốt lựa chọn công nghệ automation.
* **Quy tắc:** Tuyệt đối chưa viết mã nguồn Render Queue ở cổng này.

### CỔNG 2: PHASE 5E.1 — SINGLE PROJECT NATIVE EXPORT PROTOTYPE
* **Mục tiêu:** Thử nghiệm xuất tự động thành công cho 1 dự án đơn lẻ (1 Draft $\rightarrow$ Mở $\rightarrow$ Bấm xuất $\rightarrow$ Thẩm định file MP4).
* **Tiêu chí đạt:** 1-Click từ 2TOOLNE, người dùng không cần chạm vào hộp thoại Export của CapCut, xác thực tệp thành công.

### CỔNG 3: PHASE 5E.2 — SEQUENTIAL RENDER QUEUE
* **Mục tiêu:** Xây dựng hàng đợi tuần tự cho 5 dự án liên tiếp (5 drafts $\rightarrow$ 5 MP4s).
* **Kiểm thử:** Thứ tự hàng đợi, Pause, Resume, Retry, Skip, Cancel, Stop-after-current.

### CỔNG 4: PHASE 5E.3 — COMMERCIAL HARDENING
* **Mục tiêu:** Đóng gói bộ Version Profiles hoàn chỉnh, xử lý popup, khôi phục sau sự cố, lưu trạng thái tệp snapshot, kiểm soát quyền sở hữu người dùng, hoàn thiện ma trận tương thích.

---

## 34. MA TRẬN MÔI TRƯỜNG KIỂM THỬ VẬT LÝ TƯƠNG LAI
Kiểm thử trên phần cứng Windows thật theo các biến thể:
* Phiên bản CapCut: Đúng bản build **Windows x64 CapCut 9.3.0** đã xác minh (không dùng wildcard).
* Hệ điều hành: Windows 10, Windows 11.
* Tỷ lệ phóng đại DPI: 100%, 125%, 150%.
* Độ phân giải: 1920x1080, 2K/4K nếu có.
* Ngôn ngữ giao diện CapCut: Tiếng Anh, Tiếng Việt (nếu có).
* Trạng thái tài khoản CapCut: Đã đăng nhập, Chưa đăng nhập, Gói Free, Gói Pro.

---

## 35. DANH MỤC KIỂM THỬ VẬT LÝ CỐT LÕI (CORE PHYSICAL TESTS)
Kiểm thử chi tiết:
Khởi động CapCut $\rightarrow$ Mở đúng dự án $\rightarrow$ Hành vi `Ctrl+E` $\rightarrow$ Nhận diện hộp thoại xuất $\rightarrow$ Cây UIA $\rightarrow$ Kích hoạt nút Export $\rightarrow$ Đổi đường dẫn $\rightarrow$ Đổi độ phân giải/FPS $\rightarrow$ Nhận biết render bắt đầu $\rightarrow$ Đọc tiến độ $\rightarrow$ Nhịp tim output $\rightarrow$ Bắt sự kiện hoàn thành $\rightarrow$ Thẩm định 6 lớp $\rightarrow$ Chuyển dự án tiếp theo $\rightarrow$ Chạy hàng đợi 5 video $\rightarrow$ Xử lý popup $\rightarrow$ Mô phỏng CapCut crash $\rightarrow$ Mô phỏng 2TOOLNE crash $\rightarrow$ Người dùng chuyển tab $\rightarrow$ Người dùng chạm vào CapCut $\rightarrow$ Khôi phục sau khi bật lại app.

---

## 36. QUY TẮC PHÁT HÀNH THƯƠNG MẠI (COMMERCIAL RELEASE RULE)
Tính năng Render Queue chỉ được phép bật khi:
1. Bản build CapCut đã được kiểm chứng vật lý trên máy thật.
2. Đã có tệp `RenderProfile` hợp lệ tương ứng.
3. Vượt qua bài kiểm tra Single Export.
4. Vượt qua bài kiểm tra Multi-job Queue (5 dự án).
5. Vượt qua toàn bộ bộ lọc của `OutputVerifier`.
6. Vượt qua bài kiểm tra khôi phục sau sự cố (Crash recovery).
7. Vượt qua bài kiểm tra xử lý Known Popups.

* **Đối với các bản CapCut chưa được xác minh:**
  * Nút "Render Queue" tự động bị vô hiệu hóa (disabled).
  * Hiển thị thông báo minh bạch cho người dùng:  
    `"Phiên bản CapCut hiện tại chưa được xác minh cho Render Queue tự động. Bạn vẫn có thể tạo dự án và mở CapCut để Export thủ công bình thường."`

---

## 37. SƠ ĐỒ KIẾN TRÚC MỤC TIÊU (TARGET ARCHITECTURE)

```text
2TOOLNE Desktop
        │
        ├── Timeline Pipeline (ĐÃ KHÓA & BẢO TOÀN)
        │       ↓
        │   CapCut Draft
        │
        └── Render UI
                ↓
        RenderQueueManager
                ↓
        CapCutOwnershipManager
                ↓
           VersionGuard
                ↓
       RenderProfileRegistry
                ↓
      AutomationOrchestrator
        ┌───────┼─────────┐
        ↓       ↓         ↓
       UIA   Keyboard   Vision
        └───────┼─────────┘
                ↓
         CapCut Desktop
                ↓
         Native Export
                ↓
         OutputVerifier
                ↓
              DONE
```

---

## 39. BƯỚC ĐI TIẾP THEO (NEXT STEP)

> **BƯỚC TIẾP THEO TUYỆT ĐỐI KHÔNG PHẢI LÀ VIẾT MÃ NGUỒN RENDER QUEUE.**

Bước đi tiếp theo là:
**PHASE 5E.0 — WINDOWS CAPCUT UI CAPABILITY DISCOVERY**

Mục tiêu duy nhất:
* Xác định thực nghiệm xem CapCut trên Windows expose những gì cho UI Automation.
* Liệu các nút điều khiển trong hộp thoại Export có thể được kích hoạt mà không cần click chuột tọa độ hay không.
* Liệu CapCut có thể giữ nguyên vị trí phía sau 2TOOLNE hay không.
* Liệu CapCut có thể thu nhỏ (minimize) sau khi bắt đầu render hay không.
* Liệu tiến độ render có thể quan sát được qua hệ điều hành hay không.

Chỉ sau khi những dữ kiện thực nghiệm trên máy Windows thật được làm sáng tỏ, ngăn xếp công nghệ tự động hóa (Automation Stack) mới được phép đóng băng và chuyển sang viết mã nguồn.

---

## 40. LỆNH DỪNG (STOP)

* Văn bản này là **KẾ HOẠCH DUY NHẤT ĐƯỢC CHẤP THUẬN (PLAN ONLY)**.
* Tuyệt đối chưa triển khai Phase 5E ở thời điểm này.
* Tuyệt đối không sửa đổi mã nguồn sản phẩm.
* Bảo toàn nguyên vẹn: FFmpeg V1, Kiến trúc bản quyền, EditPlan, CapCutAdapter, và Script-to-SRT Engine.
* **DỪNG TOÀN BỘ CÔNG VIỆC SAU KHI HOÀN TẤT KẾ HOẠCH NÀY.**
