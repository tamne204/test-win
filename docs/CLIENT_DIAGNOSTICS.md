# 🩺 HỆ THỐNG BÁO CÁO CHẨN ĐOÁN KỸ THUẬT (CLIENT DIAGNOSTICS)
## VIBECODE STUDIO v2.2.3.18+

> **Mã tài liệu:** `docs/CLIENT_DIAGNOSTICS.md`  
> **Mục đích:** Hỗ trợ người dùng gửi chẩn đoán kỹ thuật an toàn cho lập trình viên khi gặp sự cố phần cứng/GPU/FFmpeg mà không để lộ dữ liệu cá nhân.

---

## 1. DỮ LIỆU ĐƯỢC THU THẬP (WHAT IS COLLECTED)

Hệ thống chỉ thu thập các thông số kỹ thuật tối thiểu cần thiết để chẩn đoán:
- **Thông tin Ứng dụng & Hệ điều hành:** Phiên bản VibeCode, Python runtime, Hệ điều hành (Windows 11 build / macOS), kiến trúc CPU (x86_64 / ARM64), tổng dung lượng RAM và RAM khả dụng (sử dụng API hệ điều hành chuẩn, hoàn toàn không phụ thuộc `wmic`).
- **Phần cứng & GPU:** Tên card đồ họa, dung lượng VRAM, phiên bản Driver NVIDIA (qua `nvidia-smi`), trạng thái PyTorch CUDA / Apple MPS, khả năng lấy mẫu tensor `torch.nn.functional.grid_sample`.
- **Bộ mã hóa FFmpeg:** Đường dẫn binary FFmpeg tin cậy, phiên bản, hỗ trợ encoder phần cứng (`h264_nvenc`, `hevc_nvenc`, `h264_videotoolbox`, `libx264`).
- **Trạng thái Render & Lỗi gần nhất:** Độ phân giải xuất, tỷ lệ khung hình, FPS, thời gian chạy, mã lỗi và traceback lỗi Python/FFmpeg (nếu có sự cố xảy ra).

---

## 2. DỮ LIỆU TUYỆT ĐỐI KHÔNG THU THẬP (WHAT IS NEVER COLLECTED)

🔒 **Cam kết bảo mật dữ liệu tuyệt đối:**
- ❌ **KHÔNG thu thập hình ảnh, video, tệp âm thanh nguồn của khách hàng.**
- ❌ **KHÔNG thu thập nội dung kịch bản (script text), phụ đề (subtitles/SRT) hay tệp dự án cá nhân.**
- ❌ **KHÔNG thu thập mã bản quyền (License Key), Session Token, mật khẩu hay khóa bảo mật.**
- ❌ **KHÔNG thu thập lịch sử duyệt web hay dữ liệu ngoài phạm vi ứng dụng.**

---

## 3. CƠ CHẾ CHE GIẤU THÔNG TIN NHẠY CẢM (REDACTION & ANONYMIZATION)

Mọi dữ liệu trước khi lưu tệp hoặc truyền qua mạng đều đi qua bộ lọc khử định danh nhiều lớp (`diagnostic_collector.py`):
1. **Khử định danh đường dẫn thư mục:** Thay thế đường dẫn người dùng thật (ví dụ `C:\Users\JohnDoe\Documents\...` hoặc `/Users/alice/...`) thành `<USER_HOME>\Documents\...`.
2. **Che giấu mã bản quyền & Token:** Mọi chuỗi License Key dạng `2TAMNE-XXXX-XXXX-XXXX` được chuyển thành `2TAMNE-****-****-****`; các chuỗi bí mật hex 32/64 ký tự được làm mờ thành `<REDACTED_32HEX>` / `<REDACTED_64HEX>`.
3. **Che giấu Email:** Chuyển đổi mọi định dạng địa chỉ email thành `<REDACTED_EMAIL>`.

---

## 4. ĐỊNH DANH BÁO CÁO (DIAGNOSTIC ID)

Mỗi báo cáo được cấp một mã định danh duy nhất có cấu trúc:
$$\mathbf{VBC\text{-}YYYYMMDD\text{-}XXXXXX} \quad (\text{Ví dụ: } \texttt{VBC-20260830-8F31A2})$$
- Mã này sử dụng chuỗi hex ngẫu nhiên mã hóa mạnh (`secrets.token_hex`), **không chứa** thông tin phần cứng cá nhân, HWID, email hay tên máy.
- Người dùng chỉ cần cung cấp mã này cho bộ phận hỗ trợ kỹ thuật để tra cứu lỗi.

---

## 5. QUY TRÌNH MINH BẠCH & SỰ ĐỒNG Ý CỦA NGƯỜI DÙNG (USER CONSENT)

1. **Xem trước minh bạch (Local Preview):** Người dùng có thể nhấn **[👁️ Xem Chi Tiết]** để đọc toàn bộ tệp JSON kỹ thuật sẽ gửi đi.
2. **Chủ động gửi:** Hệ thống **tuyệt đối không tự động gửi ngầm** nếu người dùng chưa bấm nút **[🚀 Gửi Báo Cáo Kỹ Thuật]**.
3. **Hỗ trợ Chế độ Ngoại tuyến (Offline Save):** Nếu máy tính không có Internet hoặc máy chủ hỗ trợ bận, người dùng có thể nhấn **[💾 Lưu Tại Máy (Offline)]** để lưu tệp `diagnostic_report_VBC-*.json` vào thư mục `diagnostics/` và gửi thủ công sau.
