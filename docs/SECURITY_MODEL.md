# 🛡️ MÔ HÌNH BẢO MẬT & PHÂN TÍCH NGUY CƠ (SECURITY MODEL & THREAT ANALYSIS)

> **Mã tài liệu:** `docs/SECURITY_MODEL.md`  
> **Phiên bản:** `v2.2.3.18-PROD`  
> **Phạm vi:** Ứng dụng Desktop / Web Server Cục bộ VibeCode Slideshow Studio

---

## 1. MÔ HÌNH BẢO VỆ & RANH GIỚI AN NINH (SECURITY BOUNDARY)

VibeCode hoạt động như một ứng dụng máy khách (Desktop Application) chạy máy chủ web cục bộ (`127.0.0.1`). Ranh giới an ninh được thiết kế để bảo vệ người dùng trước các cuộc tấn công qua mạng và bảo vệ quyền sở hữu trí tuệ bản quyền.

---

## 2. MA TRẬN PHÂN TÍCH MỐI ĐE DỌA (THREAT MATRIX)

| Mối đe dọa (Threat) | Mức độ Bảo vệ | Cơ chế Phòng vệ (Mitigation) | Hạn chế còn tồn tại (Limitations) |
| :--- | :---: | :--- | :--- |
| **Truy cập trái phép qua mạng (LAN / Network Access)** | 🟢 **PROTECTED** | • Ràng buộc máy chủ chỉ lắng nghe `127.0.0.1` / Loopback.<br>• Chặn Host Header không thuộc localhost.<br>• Yêu cầu mã phiên `X-App-Token` (32-byte secret). | Nếu máy tính bị cài đặt phần mềm chuyển tiếp cổng (Port forwarding / Reverse proxy) có chủ đích thì traffic loopback có thể bị chuyển tiếp. |
| **Leo thang thư mục & Tấn công File (Path Traversal & Injection)** | 🟢 **PROTECTED** | • Hàm `validate_canonical_path()` kiểm tra `is_relative_to()`.<br>• Chặn triệt để `..`, ổ đĩa `C:`, đường dẫn UNC `\\\\`, null byte `\\0`. | Không ngăn chặn được nếu chính người dùng cố ý cấu hình thư mục dự án trỏ vào vùng nhạy cảm. |
| **Chèn mã độc vào tiến trình FFmpeg (Command Injection)** | 🟢 **PROTECTED** | • 100% lệnh gọi subprocess truyền qua danh sách tham số `List[str]`.<br>• Không dùng `shell=True` với chuỗi nối biến thô. | Không áp dụng cho các script do người dùng tự cài đặt bên ngoài tool. |
| **Tấn công giả mạo bản cập nhật (Tampered Update / MitM)** | 🟢 **PROTECTED** | • Bắt buộc kết nối qua HTTPS.<br>• Chữ ký điện tử HMAC/Ed25519 cho metadata cập nhật.<br>• Đối soát mã băm SHA-256 gói nén trước khi giải nén. | Nếu chứng chỉ root SSL của hệ điều hành bị can thiệp ở mức Kernel/Admin. |
| **Gian lận sao chép bản quyền đơn giản (Casual Piracy / Copy-Paste)** | 🟢 **PROTECTED** | • Mã định danh phần cứng đa tín hiệu (HWID) từ Registry `MachineGuid` + Motherboard UUID + MAC + CPU.<br>• Khóa cứng License Key trên Server `2tamne.site`. | Người dùng am hiểu sâu về kỹ thuật có thể thử nghiệm giả lập Registry hoặc môi trường ảo hóa sandbox. |
| **Đánh cắp Key & Trộm cắp phiên (Stolen License / Account Sharing)** | 🟡 **PARTIALLY PROTECTED** | • Background Heartbeat Worker tự động kiểm tra mỗi 30 phút.<br>• Giới hạn số lượng thiết bị kích hoạt đồng thời trên máy chủ. | Nếu 2 thiết bị thay phiên nhau sử dụng lệch giờ thì cần dựa vào log phát hiện bất thường trên máy chủ. |
| **Bảo vệ Bộ nhớ tiến trình (Malicious Local Process / Memory Dump)** | 🟡 **PARTIALLY PROTECTED** | • Zero-Disk Pipe Streaming (không ghi file ảnh thô trung gian ra đĩa).<br>• Mã hóa token phiên trong RAM. | Một tiến trình chạy dưới quyền Administrator/Root trên cùng máy vẫn có thể đọc bộ nhớ RAM của Python. |
| **Dịch ngược mã nguồn (Reverse Engineering / Bytecode Decompilation)** | 🔴 **NOT PROTECTED** | • Mã nguồn Python thuần được đóng gói kèm môi trường thực thi. | Python bytecode có thể bị decompile bằng các công cụ dịch ngược mã nguồn mở nếu không dùng công cụ làm rối (Obfuscator). |
