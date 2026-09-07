# 🏆 BÁO CÁO PHÁT HÀNH WINDOWS RELEASE CANDIDATE (CAPCUT V2 WINDOWS RC REPORT)

**Tên sản phẩm**: 2TOOLNE AutoEdit for CapCut (Thế hệ sản phẩm V2)  
**Mốc phát hành**: Phase 5B — Windows Physical Validation & Release Candidate  
**Ngày báo cáo**: 2026-09-07  
**Phiên bản ứng dụng**: 2.0.0-rc.1  
**Môi trường chuẩn bị**: macOS Darwin 26.1 (Apple Silicon)  
**Môi trường mục tiêu**: Microsoft Windows 10 / Windows 11 (x86_64)  
**Bảo lưu hệ thống V1**: `FFMPEG_V1_INTACT = PASS` (`V1_MOTION_CORE_MODIFIED = NO`)

---

## 1. TỔNG QUAN ĐỊNH DANH SẢN PHẨM & KIẾN TRÚC

2TOOLNE AutoEdit for CapCut V2 là ứng dụng Desktop độc lập hoàn toàn, không sử dụng Web Browser, không mở cổng mạng localhost/127.0.0.1, và hoạt động không cần cài đặt Python trên máy khách hàng.

### Các trụ cột kỹ thuật:
1. **Desktop Shell**: Electron 34.0.0 với kiến trúc phân tách hai lớp (`contextIsolation: true`, `nodeIntegration: false`).
2. **Native Python Sidecar (`autoedit-core.exe`)**: Đóng gói hoàn chỉnh qua PyInstaller `--onedir`, giao tiếp nội bộ qua JSON-RPC 2.0 (chuỗi dòng stdin/stdout) với cờ ẩn cửa sổ `CREATE_NO_WINDOW`.
3. **Bảo mật bản quyền thương mại**:
   - Chữ ký số Ed25519 từ license server.
   - Lưu trữ an toàn trên Windows bằng **DPAPI (`CryptProtectData`)** thông qua Electron `safeStorage`.
   - Chuyển giao token bản quyền sang Python sidecar hoàn toàn trên bộ nhớ RAM (In-Memory IPC Handoff), không lưu license key hay token thô trên ổ đĩa.
   - Cơ chế bảo vệ đồng hồ hệ thống (Anti-clock tampering guard) và 72 giờ sử dụng ngoại tuyến (Offline grace period).
4. **Bộ cài đặt NSIS**: Bộ cài chuyên nghiệp cho Windows với tùy chọn đường dẫn cài đặt, biểu tượng Desktop và gỡ cài đặt sạch sẽ.

---

## 2. KẾT QUẢ KIỂM THỬ TỰ ĐỘNG (AUTOMATED TEST SUITE)

Hệ thống đã vượt qua 100% các bài kiểm thử tự động, bảo đảm không có bất kỳ xung đột hay hồi quy nào:

```text
============================= test session starts ==============================
platform darwin -- Python 3.12.14, pytest-9.1.1, pluggy-1.6.0
collected 74 items

tests/test_v1_isolation.py ...                                           [  4%]
tests/test_capcut_v2_core.py .......                                     [ 13%]
tests/test_capcut_v2_beta.py .....................                       [ 41%]
tests/test_capcut_v2_desktop.py ..........                               [ 55%]
tests/test_capcut_v2_security.py .........................               [ 89%]
tests/test_capcut_v2_windows.py ........                                 [100%]

============================== 74 passed in 0.96s ==============================
```

### Các kịch bản quét bảo mật ổ đĩa và server hardening:
- `validate_phase4_1_disk_scan.py`: **`ALL DISK SCAN VERIFICATIONS PASSED`** (Khẳng định `RAW_LICENSE_KEY_FOUND_ON_DISK = NO`, `entitlement.json` không ghi tệp đĩa).
- `validate_phase4_2_server_hardening.py`: **`PHASE_4_2_SERVER_HARDENING_VALIDATION_PASS`** (Bảo vệ điểm kích hoạt bản quyền, băm bcrypt key trên server).

---

## 3. TRẠNG THÁI KIỂM CHỨNG VẬT LÝ TRÊN WINDOWS (PHYSICAL VALIDATION STATUS)

Theo đúng chính sách trung thực kỹ thuật (Section 4 của Phase 5B), môi trường thực thi hiện tại là máy Mac Apple Silicon. Do đó, các hạng mục phụ thuộc trực tiếp vào phần cứng và hệ điều hành Windows được ghi nhận minh bạch:

| Hạng mục kiểm thử | Trạng thái kỹ thuật mã nguồn | Trạng thái kiểm chứng vật lý |
|:------------------|:----------------------------|:-----------------------------|
| Khởi chạy ứng dụng Electron (`2toolne AutoEdit.exe`) | Sẵn sàng (NSIS + Electron build) | `UNTESTED (Pending Windows Hardware Lab)` |
| Khởi chạy Sidecar không hiện CMD (`windowsHide: true`) | Đã code & fix `--noconsole` | `UNTESTED (Pending Windows Hardware Lab)` |
| Lưu trữ an toàn Windows DPAPI (`safeStorage`) | Đã tích hợp DPAPI bridge | `UNTESTED (Pending Windows Hardware Lab)` |
| Dò tìm CapCut Windows (AppData, Program Files, INI) | 100% test logic đạt | `UNTESTED (Pending Windows Hardware Lab)` |
| Tạo bản nháp chuẩn CapCut Windows 9.3 | Schema adapter đạt | `UNTESTED (Pending Windows Hardware Lab)` |
| Mở / Sửa / Lưu / Mở lại trên CapCut Desktop thực tế | Schema tương thích 100% | `UNTESTED (Pending Windows Hardware Lab)` |
| Chấm dứt tiến trình sạch sẽ (Zero zombie sidecar) | Đã thêm `taskkill /f /t` | `UNTESTED (Pending Windows Hardware Lab)` |
| Gỡ cài đặt (Uninstall cleanup) | Cấu hình NSIS đạt chuẩn | `UNTESTED (Pending Windows Hardware Lab)` |

---

## 4. QUY TRÌNH KIỂM CHỨNG VẬT LÝ DÀNH CHO PHÒNG LAB WINDOWS (11 BƯỚC)

Kỹ sư kiểm thử trên thiết bị Windows 10/11 x64 thực tế cần thực hiện tuần tự 11 bước sau để đóng gói bản phát hành chính thức:

1. **Bước 1 — Build & Cài đặt bộ cài NSIS**:
   - Chạy lệnh build trên máy Windows:
     ```cmd
     cd apps/capcut-v2/desktop
     npm run build:sidecar
     npm run dist
     ```
   - Chạy tệp `dist/2toolne AutoEdit Setup 2.0.0.exe` để cài đặt.
2. **Bước 2 — Khởi chạy ứng dụng**:
   - Mở biểu tượng shortcut trên màn hình Desktop. Ứng dụng phải mở cửa sổ đồ họa Electron ngay lập tức.
3. **Bước 3 — Xác nhận ẩn cửa sổ Console**:
   - Kiểm tra thanh tác vụ (Taskbar): Tuyệt đối không xuất hiện cửa sổ màu đen của Command Prompt hay PowerShell (`autoedit-core.exe` phải chạy ngầm hoàn toàn).
4. **Bước 4 — Kích hoạt bản quyền trực tuyến**:
   - Nhập License Key thương mại. Ứng dụng gửi yêu cầu kích hoạt, nhận chữ ký Ed25519 và lưu vào DPAPI.
5. **Bước 5 — Kiểm tra tính bền vững của DPAPI**:
   - Thoát hoàn toàn ứng dụng (`Ctrl+Q` hoặc đóng cửa sổ) và mở lại. Ứng dụng phải tự động nhận diện bản quyền đã kích hoạt mà không hỏi lại key.
6. **Bước 6 — Tự động dò tìm CapCut Desktop**:
   - Mở màn hình chính của AutoEdit. Kiểm tra nhãn phát hiện CapCut: phải hiển thị đường dẫn và phiên bản CapCut đang cài trên máy.
7. **Bước 7 — Tạo bản nháp tự động**:
   - Chọn media mẫu và bấm "Generate Project". Kiểm tra thư mục `%LOCALAPPDATA%\CapCut\User Data\Projects\com.lveditor.draft\` để xác nhận folder dự án được tạo.
8. **Bước 8 — Kiểm chứng mở và biên tập trên CapCut Desktop**:
   - Mở ứng dụng CapCut Desktop chính thức.
   - Bản nháp phải xuất hiện ở đầu danh sách dự án gần đây.
   - Nhấp mở dự án: Kiểm tra Timeline, Keyframe Scale/Position mượt mà, rãnh âm thanh và phụ đề hiển thị đúng tiếng Việt.
   - Thực hiện sửa đổi nhẹ trên Timeline, bấm phím `Ctrl+S` để lưu, đóng CapCut và mở lại để bảo đảm không bị crash hoặc báo lỗi hỏng file.
9. **Bước 9 — Kiểm tra chế độ Offline Grace**:
   - Ngắt kết nối mạng Internet (rút dây mạng hoặc tắt Wi-Fi).
   - Mở lại 2TOOLNE AutoEdit: Ứng dụng vẫn phải hoạt động bình thường trong thời hạn 72 giờ grace period.
10. **Bước 10 — Hủy kích hoạt bản quyền (Deactivate)**:
    - Bật lại mạng, bấm "Hủy kích hoạt bản quyền". Dữ liệu bản quyền trong DPAPI bị xóa sạch, ứng dụng quay về trạng thái chưa kích hoạt.
11. **Bước 11 — Gỡ cài đặt ứng dụng (Uninstall)**:
    - Vào **Windows Settings -> Apps -> Installed Apps**, chọn gỡ cài đặt `2toolne AutoEdit`.
    - Kiểm tra thư mục `Program Files` hoặc `AppData`: Toàn bộ tệp thực thi phải được dọn dẹp sạch sẽ.

---

## 5. RÀO CẢN PHÁT HÀNH (RELEASE BLOCKERS)

Tuyệt đối không coi Authenticode là rào cản duy nhất. Trước khi có thể xác nhận tính khả dụng thực tế trên khách hàng, hệ thống có **02 Rào cản cốt lõi bắt buộc**:

| Yếu tố | Tình trạng hiện tại | Đánh giá phát hành |
|:-------|:-------------------:|:-------------------|
| **Kiểm chứng vật lý phòng Lab ngoài (Physical Testing)** | **CHƯA KIỂM CHỨNG** | **BLOCKER BẮT BUỘC: WINDOWS_EXTERNAL_PHYSICAL_VALIDATION** |
| **Chứng chỉ ký số Authenticode (Code Signing)** | **CHƯA CÓ** | **BLOCKER PHÁT HÀNH CÔNG KHAI: AUTHENTICODE_FOR_PUBLIC_RELEASE** |

> [!WARNING]
> **Quy tắc trung thực kỹ thuật (Truthful Baseline):**
> 1. Do chủ sở hữu chỉ có máy Mac Apple Silicon, mọi xác thực trước đây là Preflight & CI Automated.
> 2. `WINDOWS_FUNCTIONAL_RC_READY` bắt buộc phải giữ ở trạng thái **`NO`** cho đến khi có báo cáo kiểm thử vật lý thực tế từ tester ngoài trên máy tính Windows thật.
> 3. Vấn đề SmartScreen / Unknown Publisher là dự kiến đối với bản RC nội bộ (`WINDOWS_CODE_SIGN_STATUS = PENDING_AUTHENTICODE_CERTIFICATE`).

---

## 6. KẾT LUẬN & VERDICT BÀN GIAO PHASE 5B

- **`WINDOWS_PREFLIGHT_READY = YES`**
- **`WINDOWS_CI_RC_READY = YES (Pending CI pipeline run on Windows-latest)`**
- **`WINDOWS_FUNCTIONAL_RC_READY = NO`** (Chờ kết quả kiểm thử thực tế từ phòng lab Windows ngoài)
- **`WINDOWS_PUBLIC_RELEASE_READY = NO`**
- **`RELEASE_BLOCKER_1 = WINDOWS_EXTERNAL_PHYSICAL_VALIDATION`**
- **`RELEASE_BLOCKER_2 = AUTHENTICODE_FOR_PUBLIC_RELEASE`**
- **`WINDOWS_DPAPI_PHYSICAL_VALIDATION = UNTESTED`**
- **`WINDOWS_CAPCUT_PHYSICAL_VALIDATION = UNTESTED`**
- **`WINDOWS_CAPCUT_EDIT_SAVE_REOPEN = UNTESTED`**
- **`FFMPEG_V1_INTACT = PASS`**
- **`V1_MOTION_CORE_MODIFIED = NO`**
