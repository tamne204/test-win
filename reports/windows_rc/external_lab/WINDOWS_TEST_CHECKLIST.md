# 📋 BẢNG DANH MỤC KIỂM THỬ VẬT LÝ WINDOWS (27 BƯỚC)
## WINDOWS TEST CHECKLIST — 2TOOLNE AUTOEDIT FOR CAPCUT V2

**Tên sản phẩm**: 2TOOLNE AutoEdit for CapCut Desktop (V2)  
**Mục tiêu**: Kiểm chứng toàn diện vòng đời ứng dụng trên môi trường Windows 10/11 x64 thực tế.

---

### PHẦN 1: CHUẨN BỊ MÔI TRƯỜNG & CÀI ĐẶT
- [ ] **Bước 1**: Cài đặt CapCut Desktop chính thức từ trang chủ CapCut (bản 9.x hoặc mới nhất).
- [ ] **Bước 2**: Ghi lại phiên bản CapCut chính xác (Vào menu CapCut -> Settings / About, ví dụ: `CapCut Desktop 9.3.0`).
- [ ] **Bước 3**: Nhấp đúp chạy bộ cài đặt `2toolne AutoEdit Setup 2.0.0.exe` để cài đặt vào máy.
- [ ] **Bước 4**: Khởi chạy ứng dụng từ biểu tượng shortcut ngoài màn hình Desktop.
- [ ] **Bước 5**: Xác nhận **KHÔNG xuất hiện cửa sổ Command Prompt màu đen (No CMD window)** khi ứng dụng và engine chạy ngầm.

---

### PHẦN 2: KIỂM TRA BẢN QUYỀN & LƯU TRỮ BẢO MẬT (DPAPI)
- [ ] **Bước 6**: Nhấp vào huy hiệu bản quyền, nhập mã License Key thử nghiệm được cung cấp và bấm Kích hoạt. Xác nhận kích hoạt thành công.
- [ ] **Bước 7**: Đóng hoàn toàn ứng dụng 2toolne AutoEdit (`Ctrl+Q` hoặc dấu X), sau đó mở lại app.
- [ ] **Bước 8**: Xác nhận trạng thái bản quyền được **tự động phục hồi ngay lập tức** (bảo vệ bởi Windows DPAPI), không phải nhập lại key.
- [ ] **Bước 9**: Ngắt kết nối mạng Internet của máy tính (rút dây mạng LAN hoặc ngắt Wi-Fi).
- [ ] **Bước 10**: Mở lại ứng dụng và xác nhận app thông báo đang hoạt động ở **Chế độ ngoại tuyến (Offline Grace)** bình thường.

---

### PHẦN 3: TẠO DỰ ÁN VỚI TỆP TIN MẪU & SCRIPT-TO-SRT
- [ ] **Bước 11**: Trong giao diện 2toolne AutoEdit, nạp 3 tệp ảnh mẫu: `clip_01_portrait.png`, `clip_02_architecture.png`, `clip_03_abstract.png` và tệp âm thanh `voice_sample.wav`.
- [ ] **Bước 12 (Thử nghiệm Script-to-SRT)**:
  - Chọn tùy chọn phụ đề: **Từ kịch bản + giọng nói (Script to SRT)**.
  - Chọn tệp kịch bản `script_sample.txt` (hoặc dán đoạn kịch bản mẫu).
  - Nhấp nút **"Căn chỉnh SRT từ kịch bản"**.
  - Xác nhận hộp thoại **"Xem trước phụ đề đã căn chỉnh" (Subtitle Preview)** xuất hiện.
  - Xác nhận toàn bộ chữ và dấu câu tiếng Việt được giữ nguyên 100% (không bị Whisper viết đè).
  - Thử chỉnh sửa 1 từ trong bảng phụ đề và bấm nút **"Áp dụng vào dự án"**.
- [ ] **Bước 13**: Bấm nút **"Tạo Dự Án CapCut"**. Xác nhận tiến trình chạy đạt 100% và thông báo tạo dự án thành công.

---

### PHẦN 4: KIỂM CHỨNG TRÊN CAPCUT DESKTOP THỰC TẾ
- [ ] **Bước 14**: Mở ứng dụng CapCut Desktop. Xác nhận dự án mới tạo xuất hiện ngay ở đầu danh sách dự án gần đây và nhấp mở dự án.
- [ ] **Bước 15**: Xác nhận đầy đủ **03 đoạn video/clip** xuất hiện ngay ngắn trên Timeline.
- [ ] **Bước 16**: Phát thử video, xác nhận các chuyển động máy quay hoạt động chuẩn xác:
  - Đoạn 1: Phóng to (Zoom In).
  - Đoạn 2: Thu nhỏ (Zoom Out).
  - Đoạn 3: Quét ngang (Pan Left).
- [ ] **Bước 17**: Xác nhận rãnh âm thanh phát tiếng rõ ràng, khớp với video.
- [ ] **Bước 18**: Xác nhận rãnh phụ đề (Text Track) hiển thị đúng phụ đề tiếng Việt đã được căn chỉnh từ kịch bản.
- [ ] **Bước 19**: Thử dùng chuột kéo di chuyển vị trí của 1 clip trên Timeline.
- [ ] **Bước 20**: Nhấp đúp vào 1 dòng phụ đề và gõ chỉnh sửa thêm vài từ.
- [ ] **Bước 21**: Bấm tổ hợp phím `Ctrl + S` trong CapCut để lưu dự án.
- [ ] **Bước 22**: Đóng hoàn toàn phần mềm CapCut Desktop.
- [ ] **Bước 23**: Mở lại CapCut Desktop và mở lại dự án đó.
- [ ] **Bước 24**: Xác nhận dự án mở lên mượt mà, không bị báo lỗi hỏng file (Corrupted Project), các thay đổi (clip đã dời, text đã sửa) được bảo toàn nguyên vẹn.

---

### PHẦN 5: DỌN DẸP & HỦY KÍCH HOẠT
- [ ] **Bước 25**: Kết nối lại mạng Internet.
- [ ] **Bước 26**: Mở lại 2toolne AutoEdit, nhấp vào huy hiệu bản quyền, chọn nút **"Hủy kích hoạt thiết bị này" (Deactivate)**.
- [ ] **Bước 27**: Khởi động lại ứng dụng 2toolne AutoEdit.
- [ ] **Bước 28**: Xác nhận ứng dụng quay về trạng thái chưa kích hoạt và yêu cầu License Key khi muốn tạo dự án.
- [ ] **Bước 29**: Vào **Windows Settings -> Apps -> Installed Apps**, chọn gỡ cài đặt (Uninstall) `2toolne AutoEdit`. Xác nhận gỡ cài đặt sạch sẽ, không để lại rác tiến trình.
