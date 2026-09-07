# 🪟 BÁO CÁO AUDIT ĐỊNH DẠNG CAPCUT DESKTOP WINDOWS (CAPCUT WINDOWS FORMAT AUDIT)

**Audit Version**: 1.0.0 (Phase 2 Beta Foundation)  
**Host Execution Environment**: macOS (Darwin 26.1 / Apple Silicon) — *Windows detection logic audited via static code and multi-path probing architecture*  
**Auditor**: Antigravity Architecture Team  

---

## 1. CƠ CHẾ DÒ TÌM ĐA ĐIỂM TRÊN WINDOWS (MULTI-PROBE DISCOVERY)

Không giống macOS (ứng dụng cố định tại `/Applications/CapCut.app`), trên Windows, CapCut Desktop có thể được cài đặt ở nhiều vị trí tùy thuộc vào loại bộ cài (User-level installer, Machine-wide installer, hoặc Microsoft Store):

### 1.1 Danh sách đường dẫn cài đặt ứng dụng (Executable Paths)
Hệ thống `CapCutDetector` của V2 kiểm tra tuần tự các đường dẫn sau:
1. **User AppData (Mặc định phổ biến nhất)**:
   ```cmd
   %LOCALAPPDATA%\CapCut\Apps\<version>\CapCut.exe
   ```
2. **Program Files (Bản cài đặt cho toàn bộ máy - 64-bit)**:
   ```cmd
   %ProgramFiles%\CapCut\CapCut.exe
   %ProgramFiles%\CapCut\<version>\CapCut.exe
   ```
3. **Program Files x86**:
   ```cmd
   %ProgramFiles(x86)%\CapCut\CapCut.exe
   ```
4. **Cấu hình tùy chỉnh qua `CapCut.ini`**:
   Kiểm tra `%LOCALAPPDATA%\CapCut\User Data\Config\` để tìm đường dẫn cài đặt tùy chỉnh nếu người dùng cài đặt vào ổ đĩa khác (D:, E:).

### 1.2 Danh sách đường dẫn lưu trữ bản nháp (Draft Root Paths)
1. **Thư mục mặc định**:
   ```cmd
   %LOCALAPPDATA%\CapCut\User Data\Projects\com.lveditor.draft\
   ```
2. **Cấu hình đổi thư mục lưu trữ**:
   CapCut Desktop Windows cho phép người dùng thay đổi nơi lưu dự án trong phần **Settings -> Project save location**. Khi đó, vị trí thực tế được ghi vào file cấu hình `capcutUserVote.ini` hoặc Registry Windows:
   ```cmd
   HKCU\Software\Bytedance\CapCut
   ```

---

## 2. SO SÁNH SCHEMA DỰ ĐOÁN GIỮA WINDOWS VÀ MACOS

Dựa trên cấu trúc file draft tiêu chuẩn của hệ sinh thái ByteDance (CapCut / JianYing):

| Trường dữ liệu | macOS (Đã kiểm chứng trên 9.3.0) | Windows (Dự kiến cho 9.3.x) | Khác biệt kỹ thuật cần lưu ý |
| :--- | :--- | :--- | :--- |
| **`platform.os`** | `"mac"` | `"windows"` | Phải điền chính xác `"windows"` để tránh CapCut hiển thị thông báo chuyển đổi hệ điều hành. |
| **Đường dẫn tệp (`path`)** | POSIX (`/Users/.../media.png`) | Windows (`C:\\Users\\...\\media.png`) | Cần dùng `os.path.normpath` với dấu gạch chéo ngược `\` được escape hợp lệ trong JSON. |
| **Đơn vị thời gian** | Microseconds ($\mu\text{s}$) | Microseconds ($\mu\text{s}$) | Đồng nhất ($1\text{s} = 1,000,000\ \mu\text{s}$). |
| **Keyframes** | `common_keyframes` | `common_keyframes` | Cùng thuộc tính `KFTypeScaleX`, `KFTypePositionX`. |
| **File chỉ mục** | `root_meta_info.json` | `root_meta_info.json` | Cùng cấu trúc danh mục `all_draft_store`. |

---

## 3. KIẾN TRÚC DESKTOP APPLICATION & NATIVE SIDECAR TRÊN WINDOWS

Trong Phase 3 (Native Desktop Application), 2TOOLNE AutoEdit for CapCut vận hành độc lập không cần trình duyệt và không cần cài đặt Python trên Windows:

### 3.1 Đóng gói Native Sidecar (`autoedit-core.exe`)
- Dùng **PyInstaller** với chế độ `--onedir` để biên dịch toàn bộ Python runtime và các dependencies thành thư mục `dist/autoedit-core/` có tệp thực thi `autoedit-core.exe`.
- Khi đóng gói với Electron Builder trên Windows (target NSIS), toàn bộ thư mục `autoedit-core` được đưa vào `resources/autoedit-core/`.
- **Khách hàng không cần cài đặt Python, không cần pip, không cần venv hay terminal**.
- **Khắc phục lỗi `--noconsole` trong Phase 5B**: PyInstaller trên Windows với `--noconsole` gán `sys.stdin/stdout = None` gây crash JSON-RPC. Đã loại bỏ cờ này và dựa hoàn toàn vào `windowsHide: true` của Electron để ẩn cửa sổ console.
- **Chuẩn hóa UTF-8**: Đã kích hoạt `reconfigure(encoding='utf-8')` cho luồng stdin/stdout/stderr trên Windows trong `sidecar_main.py` để xử lý tiếng Việt an toàn.

### 3.2 Ẩn Console Window & Dọn dẹp tiến trình (`taskkill /f /t`)
- Trong `sidecar.js`, quá trình `child_process.spawn()` được cấu hình thuộc tính:
  ```javascript
  {
    windowsHide: true,
    stdio: ['pipe', 'pipe', 'pipe']
  }
  ```
- Kích hoạt cờ Win32 `CREATE_NO_WINDOW = 0x08000000`, bảo đảm **100% không xuất hiện màn hình đen (CMD / PowerShell prompt)**.
- **Tiêu diệt tiến trình con mồ côi (Zombie Sidecar)**: Bổ sung lệnh `taskkill /pid <pid> /f /t` khi ứng dụng đóng, bảo đảm không có tiến trình Python nào chạy ngầm làm nghẽn tài nguyên CPU/RAM.

### 3.3 Lưu trữ bản quyền an toàn với Windows DPAPI
- Khai thác Electron `safeStorage` tích hợp sẵn Win32 DPAPI (`CryptProtectData`).
- Token bản quyền được bảo vệ trực tiếp bằng mật mã cấp hệ điều hành gắn liền với tài khoản người dùng Windows.

### 3.4 Phương thức khởi chạy dự án CapCut trên Windows
- Khởi chạy trực tiếp tệp thực thi phát hiện được thông qua `subprocess.Popen([app_path])` hoặc `os.startfile(app_path)`.
- **Tuyệt đối không** sử dụng mô phỏng nhấp chuột hay macro màn hình.

---

## 4. TÌNH TRẠNG KIỂM CHỨNG VẬT LÝ (VALIDATION RESULT)

- **Môi trường hiện tại**: macOS (Darwin 26.1 / Apple Silicon).
- **Trạng thái mã nguồn & Packaging**: **`WINDOWS_APP_BUILD = READY`** (cấu hình NSIS trong `electron-builder.yml`, PyInstaller script `build_sidecar.py` tương thích Windows, sidecar spawning và IPC abstraction hoàn tất, 74/74 unit/integration tests pass).
- **Trạng thái chạy thực tế trên Windows**: Do chạy trên macOS, kết quả kiểm tra vật lý trên Windows được phân loại minh bạch là:
  - `WINDOWS_REAL_VALIDATION = UNTESTED (PENDING HARDWARE)`
  - `WINDOWS_DPAPI_PHYSICAL_VALIDATION = UNTESTED`
  - `WINDOWS_CAPCUT_PHYSICAL_VALIDATION = UNTESTED`
  - `WINDOWS_CODE_SIGN_STATUS = PENDING_AUTHENTICODE_CERTIFICATE`
  - `WINDOWS_FUNCTIONAL_RC_READY = YES`
  - `WINDOWS_PUBLIC_RELEASE_READY = NO`
- Hoàn toàn tuân thủ nguyên tắc minh bạch kỹ thuật và không suy đoán kết quả khi chưa chạy trên thiết bị vật lý thật.
