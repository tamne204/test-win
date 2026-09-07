# 💻 HƯỚNG DẪN CÀI ĐẶT VÀ SỬ DỤNG 2TOOLNE AUTOEDIT TRÊN WINDOWS
## (CAPCUT V2 WINDOWS INSTALLATION & OPERATIONS GUIDE)

**Phiên bản tài liệu**: 2.0.0-rc.1  
**Mục tiêu**: Hướng dẫn cài đặt, kích hoạt bản quyền, cấu hình và xử lý sự cố cho ứng dụng Desktop `2TOOLNE AutoEdit for CapCut` trên hệ điều hành Windows 10 và Windows 11.

---

## 1. YÊU CẦU HỆ THỐNG (SYSTEM REQUIREMENTS)

| Thành phần | Yêu cầu tối thiểu | Yêu cầu khuyến nghị |
|:-----------|:------------------|:-------------------|
| **Hệ điều hành** | Windows 10 (64-bit, Build 19041+) | Windows 11 (64-bit, phiên bản 22H2 trở lên) |
| **Kiến trúc CPU** | Intel / AMD x86_64 (hỗ trợ tập lệnh AVX) | Intel Core i5/i7 thế hệ 10+ hoặc AMD Ryzen 5+ |
| **Bộ nhớ RAM** | 8 GB RAM | 16 GB RAM trở lên |
| **Dung lượng ổ cứng** | 1.5 GB trống (SSD) | 5 GB+ trống cho cache và media tạm |
| **Phần mềm đi kèm** | **CapCut Desktop (Phiên bản 9.3.0+)** | CapCut Desktop bản chính thức từ capcut.com |
| **Môi trường phụ trợ** | **KHÔNG CẦN CÀI PYTHON, NODE.JS HAY GIT** (Ứng dụng đã đóng gói 100% tự chủ) | Không yêu cầu quyền Administrator để chạy hàng ngày |

---

## 2. QUY TRÌNH CÀI ĐẶT BỘ CÀI NSIS (INSTALLATION STEPS)

### Bước 1: Tải bộ cài đặt
Tải tệp cài đặt chính thức:
```text
2toolne-AutoEdit-Setup-2.0.0.exe
```

### Bước 2: Chạy trình cài đặt NSIS
1. Nhấp đúp chuột vào tệp `2toolne-AutoEdit-Setup-2.0.0.exe`.
2. Nếu xuất hiện thông báo Windows SmartScreen:
   - Nhấp vào **"More info"** (Thêm thông tin).
   - Chọn **"Run anyway"** (Vẫn chạy).
   *(Lưu ý: Thông báo này sẽ biến mất khi phiên bản phát hành công khai được ký số bằng chứng chỉ EV Authenticode)*.
3. Chọn thư mục cài đặt mong muốn (Mặc định: `%LOCALAPPDATA%\Programs\2toolne AutoEdit` hoặc `C:\Program Files\2toolne AutoEdit`).
4. Tích chọn **"Create Desktop Shortcut"** để tạo biểu tượng trên màn hình chính.
5. Nhấp **"Install"** và chờ quá trình giải nén hoàn tất trong khoảng 15-30 giây.
6. Nhấp **"Finish"** để khởi chạy ứng dụng ngay.

---

## 3. KHỞI CHẠY VÀ KÍCH HOẠT BẢN QUYỀN (LICENSE ACTIVATION)

### 3.1 Khởi chạy lần đầu
- Ứng dụng sẽ xuất hiện dưới dạng cửa sổ Desktop độc lập.
- **Không có bất kỳ màn hình dòng lệnh màu đen nào xuất hiện** (Console được ẩn ngầm hoàn toàn bởi cờ `CREATE_NO_WINDOW`).
- Màn hình kích hoạt bản quyền sẽ hiển thị nếu ứng dụng chưa có bản quyền hợp lệ.

### 3.2 Kích hoạt bản quyền
1. Nhập **License Key** thương mại (gồm 29 ký tự, dạng `2TOOL-XXXX-XXXX-XXXX-XXXX`).
2. Nhấn nút **"Kích hoạt (Activate)"**.
3. Ứng dụng sẽ kết nối với License Server qua giao thức mã hóa HTTPS, nhận gói quyền lợi đã ký số bằng thuật toán Ed25519.
4. Token bản quyền được mã hóa và lưu trữ an toàn vào hệ điều hành bằng **Windows DPAPI** (`CryptProtectData`).
5. Khi thành công, ứng dụng tự động mở giao diện điều khiển chính.

### 3.3 Tính năng ngoại tuyến (Offline Grace Period)
- Người dùng có thể ngắt kết nối mạng Internet và sử dụng ứng dụng trong vòng **72 giờ** kể từ lần xác thực cuối.
- Khi có mạng trở lại, ứng dụng sẽ tự động làm mới thời hạn ngoại tuyến mà không làm gián đoạn công việc của người dùng.

---

## 4. TỰ ĐỘNG KẾT NỐI VÀ TẠO BẢN NHÁP CAPCUT DESKTOP

1. **Tự động nhận diện**:
   - Ứng dụng tự động quét các thư mục cài đặt chuẩn của CapCut (`%LOCALAPPDATA%\CapCut\Apps`, `Program Files`, v.v.).
   - Nếu tìm thấy, thanh trạng thái sẽ hiển thị: `CapCut 9.3.0: Đã sẵn sàng`.
2. **Cấu hình thư mục lưu trữ bản nháp**:
   - Thư mục mặc định:
     ```cmd
     %LOCALAPPDATA%\CapCut\User Data\Projects\com.lveditor.draft\
     ```
   - Nếu người dùng đã đổi thư mục lưu trữ trong phần Cài đặt của CapCut Desktop, AutoEdit sẽ tự động đọc tệp `capcutUserVote.ini` để xác định đúng đường dẫn mới.
3. **Quy trình tạo dự án**:
   - Thêm danh sách video, ảnh, audio vào giao diện AutoEdit.
   - Chọn template chuyển động, rãnh âm thanh và phụ đề.
   - Nhấn **"Xuất sang CapCut (Export to CapCut)"**.
   - Mở ứng dụng CapCut Desktop: Dự án mới sẽ nằm ngay đầu danh sách "Dự án gần đây" (Recent Projects) với đầy đủ keyframe chuyển động mượt mà.

---

## 5. HỦY KÍCH HOẠT VÀ GỠ CÀI ĐẶT (DEACTIVATION & UNINSTALL)

### 5.1 Hủy kích hoạt chuyển máy
- Vào mục **Cài đặt (Settings)** -> **Bản quyền (License)** -> Nhấn **"Hủy kích hoạt (Deactivate)"**.
- Token trong Windows DPAPI sẽ được dọn sạch và máy chủ sẽ giải phóng số lượt thiết bị cho mã bản quyền.

### 5.2 Gỡ cài đặt hoàn toàn
1. Mở **Start Menu** -> Vào **Settings** -> **Apps** -> **Installed apps**.
2. Tìm `2toolne AutoEdit`, chọn menu ba chấm (`...`) và chọn **Uninstall**.
3. Trình gỡ cài đặt NSIS sẽ tự động xóa sạch các tệp thực thi, thư mục sidecar và shortcut trên màn hình.

---

## 6. XỬ LÝ SỰ CỐ THƯỜNG GẶP (TROUBLESHOOTING)

### Sự cố 1: CapCut không hiển thị dự án vừa tạo
- **Nguyên nhân**: CapCut Desktop đang mở trong lúc AutoEdit ghi tệp chỉ mục `root_meta_info.json`.
- **Khắc phục**: Khởi động lại ứng dụng CapCut Desktop hoặc đóng CapCut trước khi bấm "Xuất sang CapCut".

### Sự cố 2: Lỗi đường dẫn chứa tiếng Việt có dấu
- **Nguyên nhân**: Trước đây các ứng dụng Python trên Windows có thể gặp lỗi mã trang OEM (CP1252).
- **Khắc phục**: Bản phát hành V2.0.0 đã được cấu hình cưỡng chế `UTF-8` cho toàn bộ luồng dữ liệu. Hãy bảo đảm bạn đang sử dụng bản cài đặt từ phiên bản 2.0.0 trở lên.

### Sự cố 3: Cảnh báo phần mềm diệt virus (False Positive)
- **Nguyên nhân**: Tệp Python sidecar đóng gói bằng PyInstaller (`autoedit-core.exe`) khi chưa có chữ ký số EV Authenticode có thể bị một số phần mềm diệt virus quét heuristic cảnh báo nhầm.
- **Khắc phục**: Thêm thư mục cài đặt của `2toolne AutoEdit` vào danh sách ngoại lệ (White-list / Exclusions) của phần mềm diệt virus (Windows Defender, Malwarebytes, v.v.).
