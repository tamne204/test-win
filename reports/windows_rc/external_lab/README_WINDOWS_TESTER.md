# 🧪 HƯỚNG DẪN KIỂM THỬ DÀNH CHO TESTER WINDOWS (EXTERNAL LAB)
## 2TOOLNE AUTOEDIT FOR CAPCUT V2 — WINDOWS TEST LAB PACKAGE

Chào bạn! Bạn đang tham gia kiểm thử phiên bản thử nghiệm **2TOOLNE AutoEdit for CapCut (V2)** trên máy tính Windows.

> [!IMPORTANT]
> **BẠN KHÔNG CẦN CÀI ĐẶT BẤT KỲ CÔNG CỤ LẬP TRÌNH NÀO:**
> - KHÔNG cần Git, Python, Node.js, npm, terminal hay Visual Studio.
> - Bạn chỉ cần: Máy tính chạy **Windows 10 hoặc Windows 11**, phần mềm **CapCut Desktop**, bộ cài đặt `2toolne AutoEdit Setup ...exe` và thư mục `TEST_MEDIA` đính kèm.

---

## 📦 1. BỘ TỆP TIN BẠN ĐÃ NHẬN ĐƯỢC

1. `2toolne AutoEdit Setup 2.0.0.exe` (Bộ cài đặt ứng dụng Desktop).
2. Thư mục `TEST_MEDIA/` chứa các tệp mẫu kiểm thử an toàn:
   - `clip_01_portrait.png`, `clip_02_architecture.png`, `clip_03_abstract.png` (3 ảnh mẫu).
   - `voice_sample.wav` (Giọng đọc mẫu).
   - `subtitles_sample.srt` (Phụ đề tiếng Việt mẫu có sẵn).
   - `script_sample.txt` (Kịch bản văn bản tiếng Việt mẫu để thử nghiệm tính năng Script-to-SRT).
3. `WINDOWS_TEST_CHECKLIST.md` (Checklist 30 bước thực hiện).
4. `WINDOWS_TEST_RESULT_TEMPLATE.md` (Mẫu điền kết quả gửi lại).
5. Mã bản quyền kiểm thử dùng 1 lần (Được admin gửi riêng qua kênh liên lạc bảo mật).

---

## 🛡️ 2. LƯU Ý VỀ CẢNH BÁO BẢO MẬT WINDOWS SMARTSCREEN

Vì đây là gói phần mềm thử nghiệm nội bộ (**Internal Release Candidate**) chưa tích hợp chứng chỉ số thương mại đắt tiền, Windows Defender SmartScreen có thể hiển thị bảng cảnh báo màu xanh lam:

> **"Windows protected your PC / Microsoft Defender SmartScreen prevented an unrecognized app from starting"**  
> *(Windows đã bảo vệ máy tính của bạn - Unknown Publisher)*

👉 **Cách thao tác để tiếp tục thử nghiệm:**
1. Nhấp chuột vào dòng chữ **"More info"** *(Thêm thông tin)*.
2. Nhấp vào nút **"Run anyway"** *(Vẫn chạy)*.

*(Lưu ý: Đây là hành vi hoàn toàn bình thường đối với bản phần mềm nội bộ dành cho phòng Lab, không gây hại cho máy tính).*

---

## 🔑 3. MÃ BẢN QUYỀN THỬ NGHIỆM (TEST LICENSE)

- Mã bản quyền thử nghiệm được tạo riêng cho đợt kiểm thử này và gửi tách biệt qua tin nhắn/email cho bạn.
- Tuyệt đối không chia sẻ mã này ra bên ngoài.
- Ứng dụng hỗ trợ cơ chế lưu trữ bảo mật Windows DPAPI và cho phép dùng thử 72 giờ không cần mạng (Offline Grace).

---

## 📋 4. QUY TRÌNH KIỂM THỬ CHÍNH

Bạn vui lòng mở tệp `WINDOWS_TEST_CHECKLIST.md` để theo dõi từng bước chi tiết:
1. Cài đặt CapCut Desktop và ghi lại phiên bản chính xác (ví dụ: CapCut 9.3.0).
2. Cài đặt `2toolne AutoEdit Setup 2.0.0.exe`.
3. Mở ứng dụng và kiểm tra không có cửa sổ đen Command Prompt nhấp nháy.
4. Kích hoạt mã bản quyền kiểm thử.
5. Đóng và mở lại app -> xác nhận tự động phục hồi bản quyền.
6. Ngắt kết nối mạng -> xác nhận chế độ dùng offline hoạt động.
7. **Kiểm thử Tính năng Script-to-SRT (Kịch bản -> Phụ đề tự động)**:
   - Chọn chế độ phụ đề: **Từ kịch bản + giọng nói (Script to SRT)**.
   - Nhập nội dung kịch bản tiếng Việt hoặc tải từ tệp `script_sample.txt`.
   - Chọn tệp âm thanh `voice_sample.wav`.
   - Bấm **"Căn chỉnh SRT từ kịch bản"** -> kiểm tra hộp thoại Preview phụ đề, thử sửa thời gian/chữ của 1 cue -> bấm **"Áp dụng vào dự án"**.
8. Nạp 3 ảnh trong `TEST_MEDIA/` -> bấm **"Tạo dự án CapCut"**.
9. Mở CapCut Desktop -> mở dự án vừa tạo:
   - Kiểm tra 3 clip trên Timeline.
   - Kiểm tra hiệu ứng Zoom In, Zoom Out, Pan Left mượt mà.
   - Kiểm tra âm thanh phát chuẩn và phụ đề tiếng Việt hiển thị chính xác.
   - Di chuyển thử 1 clip, sửa 1 dòng phụ đề, bấm `Ctrl+S` lưu lại.
   - Đóng CapCut và mở lại dự án -> xác nhận CapCut vẫn mở mượt mà và lưu giữ các chỉnh sửa.
10. Bật lại mạng -> Hủy kích hoạt bản quyền (Deactivate) -> Gỡ cài đặt (Uninstall).

---

## 📝 5. CÁCH GỬI BÁO CÁO KẾT QUẢ

Sau khi hoàn thành:
1. Mở tệp `WINDOWS_TEST_RESULT_TEMPLATE.md`, đánh dấu tích `[x]` vào các mục đã đạt.
2. Điền thông tin cấu hình máy tính (Windows version, CPU, RAM, GPU, phiên bản CapCut).
3. Gửi lại tệp kết quả kèm ảnh chụp màn hình (nếu có lỗi) cho quản trị viên dự án.

Cảm ơn bạn đã đồng hành cùng 2TOOLNE Studio!
