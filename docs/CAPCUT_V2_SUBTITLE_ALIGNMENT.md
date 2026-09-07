# HƯỚNG DẪN SỬ DỤNG VÀ VẬN HÀNH TÍNH NĂNG TỰ ĐỘNG CĂN CHỈNH PHỤ ĐỀ (SCRIPT-TO-SRT)
## 2TOOLNE AUTOEDIT FOR CAPCUT V2 — USER & DEVELOPER GUIDE
**Tài liệu:** `docs/CAPCUT_V2_SUBTITLE_ALIGNMENT.md`  
**Phiên bản:** 2.0.0  

---

## 1. GIỚI THIỆU TÍNH NĂNG

Tính năng **Script-to-SRT** trong 2TOOLNE AutoEdit for CapCut V2 cho phép bạn tạo ra tệp phụ đề chuẩn `.srt` và rãnh phụ đề chỉnh sửa được trên CapCut một cách hoàn toàn tự động từ:
1. **Nội dung kịch bản văn bản của bạn** (File `.txt` hoặc gõ trực tiếp).
2. **Tệp âm thanh giọng đọc tương ứng** (File `.wav`, `.mp3`, `.m4a`, v.v.).

### 🌟 Điểm khác biệt quan trọng nhất:
- Các công cụ thông thường dùng AI nhận diện giọng nói (ASR) thường phiên âm sai tên riêng, sai từ tiếng Anh, thiếu dấu hoặc tự ý đổi từ ngữ của bạn.
- **2TOOLNE AutoEdit cam kết**: Văn bản kịch bản ban đầu của bạn là **nguồn chân lý duy nhất**. Công nghệ căn chỉnh âm học chỉ tìm mốc thời gian phát âm, tuyệt đối **không bao giờ viết lại hay thay đổi bất kỳ ký tự nào** trong kịch bản gốc.

---

## 2. HƯỚNG DẪN DÀNH CHO NGƯỜI DÙNG TRÊN GIAO DIỆN DESKTOP

### Bước 1: Chọn chế độ phụ đề
1. Khởi chạy ứng dụng **2TOOLNE AutoEdit**.
2. Ở phần **"Cấu hình Phụ đề"**, tích chọn mục **"Từ kịch bản + giọng nói (Script to SRT)"**.

### Bước 2: Nạp kịch bản và tệp âm thanh
1. Nhập hoặc dán nội dung kịch bản vào khung văn bản, hoặc nhấp nút **"Chọn tệp kịch bản (.txt)"** để tải tệp từ máy.
2. Ở mục **"Tệp âm thanh"**, chọn tệp ghi âm giọng đọc của bạn.
3. (Tùy chọn) Chọn ngôn ngữ kịch bản: `Tự động nhận diện (Khuyến nghị)`, `Tiếng Việt`, `Tiếng Anh`, `Tiếng Nhật`, `Tiếng Hàn`.
4. (Tùy chọn) Điều chỉnh số từ tối đa mỗi câu phụ đề (Mặc định là 12 từ) và thời lượng hiển thị (Mặc định: 0.6s - 5.0s).

### Bước 3: Căn chỉnh phụ đề và xem trước
1. Nhấp nút **"Căn chỉnh SRT từ kịch bản"**.
2. Hệ thống sẽ hiển thị thanh tiến độ xử lý âm học (chạy hoàn toàn trên máy bạn, không gửi file lên mạng).
3. Khi hoàn thành, hộp thoại **"Xem trước phụ đề đã căn chỉnh"** sẽ xuất hiện:
   - Bạn có thể xem từng câu phụ đề kèm mốc thời gian bắt đầu và kết thúc.
   - Bạn có thể nhấp trực tiếp vào ô văn bản hoặc ô thời gian để sửa đổi nếu muốn.
   - Bấm nút **"Gộp câu"** để nối câu hiện tại với câu tiếp theo.
   - Bấm nút **"Xóa"** để bỏ bớt các câu không cần thiết.

### Bước 4: Áp dụng hoặc Xuất tệp
- **Áp dụng vào dự án**: Nhấp **"Áp dụng vào dự án"** để tự động gắn phụ đề vào dự án CapCut khi bạn bấm **"Tạo Dự Án CapCut"**.
- **Xuất tệp SRT độc lập**: Nhấp **"Xuất tệp .srt"** nếu bạn muốn lưu phụ đề thành tệp rời để dùng cho phần mềm khác.

---

## 3. CÁC QUY TẮC CĂN CHỈNH PHỤ ĐỀ CHUẨN (2TOOLNE_STANDARD_SUBTITLE)

| Quy tắc | Giá trị mặc định | Mục đích kỹ thuật |
|:---|:---|:---|
| **Số từ tối đa mỗi câu** | 12 từ | Đảm bảo người xem đọc kịp, không bị tràn màn hình video dọc |
| **Thời lượng tối thiểu** | 0.6 giây | Tránh hiện tượng phụ đề nhấp nháy quá nhanh làm mỏi mắt |
| **Thời lượng tối đa** | 5.0 giây | Tránh phụ đề lưu lại quá lâu gây nhàm chán |
| **Khoảng hở tối thiểu** | 0.05 giây | Ngăn chặn hiện tượng 2 câu phụ đề chồng lấn timing trên CapCut |
| **Ưu tiên ngắt câu** | Theo dấu `. , ? ! ; :` | Ngắt theo nhịp thở và ngữ pháp tự nhiên của câu nói |
| **Chống cụt từ** | Không để 1 từ lẻ loi | Tự động ghép từ lẻ vào câu liền kề để câu phụ đề có nghĩa trọn vẹn |

---

## 4. KHẮC PHỤC SỰ CỐ THƯỜNG GẶP (TROUBLESHOOTING)

### 1. Thông báo lỗi "Kịch bản văn bản đang để trống"
- **Nguyên nhân**: Bạn chưa nhập nội dung vào khung kịch bản.
- **Khắc phục**: Dán nội dung văn bản kịch bản trước khi bấm nút Căn chỉnh.

### 2. Thông báo lỗi "Không tìm thấy tệp âm thanh"
- **Nguyên nhân**: Bạn chưa chọn tệp âm thanh hoặc tệp âm thanh đã bị di chuyển/xóa.
- **Khắc phục**: Chọn lại tệp âm thanh hợp lệ trong máy tính của bạn.

### 3. Tỷ lệ khớp từ thấp hoặc một số câu bị lệch thời gian
- **Nguyên nhân**: Tệp ghi âm có quá nhiều tạp âm lớn, tiếng nhạc nền quá to át tiếng người nói, hoặc người nói đọc thiếu/khác nhiều câu so với kịch bản bằng chữ.
- **Khắc phục**:
  - Dùng bản ghi âm giọng nói rõ ràng, hạn chế nhạc nền lớn trong quá trình căn chỉnh.
  - Kiểm tra lại xem bản ghi âm có đọc đúng theo kịch bản văn bản hay không.
  - Sử dụng bảng Xem trước (Preview) để tinh chỉnh thủ công các câu bị lệch trước khi tạo dự án.
