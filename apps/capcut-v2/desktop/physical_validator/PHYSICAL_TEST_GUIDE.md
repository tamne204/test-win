# 2TOOLNE AUTOEDIT FOR CAPCUT V2
# PHYSICAL LAB TESTER GUIDE (FINAL RELEASE GATE)

---

## 1. MỤC TIÊU KIỂM ĐỊNH (OBJECTIVE)

Tài liệu này hướng dẫn chi tiết quy trình kiểm định vật lý tại hiện trường trên máy tính **Windows 11 (Build 26200)** chạy **CapCut Desktop 9.3.0.3970** nhằm nghiệm thu 4 cổng phát hành bắt buộc:
- **Gate A**: Xuất video đơn lẻ tự động 100% không cần can thiệp người dùng (`REAL_SINGLE_NATIVE_EXPORT_PHYSICAL`).
- **Gate B**: Xử lý tuần tự hàng đợi 5 dự án với 1 worker duy nhất (`REAL_FIVE_JOB_QUEUE_PHYSICAL`).
- **Gate C**: Tự động phục hồi sau sự cố thoát app đột ngột (`REAL_CRASH_RECOVERY_PHYSICAL`).
- **Gate D**: Khởi động bình thường và xác thực Version Guard trên ứng dụng thật (`NORMAL_WINDOWS_PRODUCT_LAUNCH`).

---

## 2. CHUẨN BỊ MÔI TRƯỜNG (ENVIRONMENT PREPARATION)

1. Máy tính mục tiêu:
   - Hệ điều hành: **Windows 11 x64** (Khuyến nghị Build 26200 trở lên).
   - Tỷ lệ hiển thị (DPI): **100%** (Độ phân giải chuẩn 1536x864 hoặc 1920x1080).
   - Phiên đăng nhập: Màn hình Desktop tương tác (Interactive Session, không khóa màn hình Win+L khi đang chạy test).
2. Phiên bản CapCut mục tiêu:
   - **CapCut Desktop 9.3.0.3970** (Product version: `9.3.0.6ab91e2a`).
   - Đường dẫn mặc định: `%LOCALAPPDATA%\CapCut\Apps\9.3.0.3970\CapCut.exe`.
3. Giải nén gói kiểm thử:
   - Giải nén tệp `2TOOLNE-AutoEdit-v2.0.0-RC-win64.zip` vào một thư mục bất kỳ (ví dụ: `C:\2TOOLNE_TEST`).

---

## 3. QUY TRÌNH THỰC HIỆN TỰ ĐỘNG (1-CLICK EXECUTION)

### Bước 1: Khởi động CapCut
- Mở **CapCut Desktop 9.3.0** và để cửa sổ ở trạng thái sẵn sàng trên màn hình.

### Bước 2: Chạy bộ kiểm thử tự động
- Mở thư mục `physical_validator`.
- Nhấp đúp chuột vào tệp: **`run_physical_validation.bat`**.
- Bộ kiểm thử sẽ tự động thực hiện:
  1. Quét thông số hệ thống, nhận diện PID `CapCut.exe` và kiểm tra checksum SHA256.
  2. Thực thi **Gate A**: Kích hoạt phím tắt `Ctrl+E` $\rightarrow$ xác nhận `Enter` $\rightarrow$ theo dõi heartbeat tệp MP4 $\rightarrow$ giải phóng khóa file $\rightarrow$ đóng popup $\rightarrow$ kiểm định `ffprobe`.
  3. Thực thi **Gate B**: Đẩy 5 dự án mẫu vào hàng đợi `RenderQueueManager` $\rightarrow$ xử lý tuần tự từng dự án (Single Worker) $\rightarrow$ xác minh 5 tệp video đầu ra.
  4. Thực thi **Gate C**: Kiểm tra máy trạng thái khôi phục sau sự cố (Crash Recovery) và chứng minh không xuất lặp lại (`DUPLICATE_EXPORT_STARTED = NO`).
  5. Thực thi **Gate D**: Thẩm định cơ chế khóa phiên bản `CapCutVersionGuard`.

### Bước 3: Kiểm định ứng dụng chính (Normal Launch)
- Trở lại thư mục `app\`, nhấp đúp vào **`2toolne AutoEdit.exe`**.
- Xác nhận:
  - Giao diện ứng dụng tải bình thường (Studio, Queue, Projects).
  - Trạng thái CapCut hiển thị "9.3.0.3970 - Hỗ trợ xuất tự động".
  - Các nút `[⚡ Render Ngay]` và `[➕ Thêm Hàng Đợi]` hoạt động bình thường.

---

## 4. THU THẬP BẰNG CHỨNG (EVIDENCE SUBMISSION)

Khi kịch bản `run_physical_validation.bat` chạy xong, hệ thống sẽ tự động tạo ra 2 tệp tại thư mục:
1. **`2TOOLNE_AUTOEDIT_CAPCUT_V2_FINAL_WINDOWS_PHYSICAL_ACCEPTANCE.md`**: Báo cáo nghiệm thu đầy đủ các trường dữ liệu theo Điều 22 của Chỉ thị CEO.
2. **`physical_evidence_report.zip`**: Gói nén chứa toàn bộ log, file JSON và bằng chứng kiểm thử.

**Hành động của Tester**:
- Sao chép tệp **`physical_evidence_report.zip`** gửi lại cho đội ngũ phát triển 2TOOLNE / CEO Agent để khóa bản phát hành thương mại chính thức (`v2.0.0`).
