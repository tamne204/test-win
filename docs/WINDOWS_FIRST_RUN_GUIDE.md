# 🚀 HƯỚNG DẪN BẮT ĐẦU NHANH: VIBECODE STUDIO (WINDOWS)

> **Phiên bản:** `v2.2.3.18-RC1`  
> **Hệ điều hành hỗ trợ:** Windows 10 / Windows 11 (64-bit)

---

## 1. CÁC BƯỚC KHỞI ĐỘNG (STEP-BY-STEP)

1. **Tải về và Giải nén:**
   - Tải tệp `SlideshowBuilder_Windows_latest.zip` từ trang chủ `https://www.2tamne.site/`.
   - Chuột phải vào file zip $\rightarrow$ Chọn **Extract All... (Giải nén toàn bộ)** ra một thư mục (ví dụ `D:\VibeCode` hoặc `C:\VibeCode`).
2. **Yêu cầu Tiên quyết (Prerequisite):**
   - Máy tính cần có **Python 64-bit** (Khuyên dùng Python 3.11 hoặc 3.12 từ [python.org](https://www.python.org/downloads/)).
   - ⚠️ *Lưu ý quan trọng:* Khi cài Python, nhớ tích chọn ô **"Add python.exe to PATH"**.
3. **Khởi Chạy Ứng Dụng:**
   - Click đúp vào file **`SlideshowStudio.vbs`** (hoặc `start_windows.bat`).
   - Lần đầu chạy, hệ thống sẽ tự động cài đặt các thư viện cần thiết (mất khoảng 1–2 phút).
   - Trình duyệt sẽ tự động mở giao diện tại: `http://localhost:8080`.
4. **Kích Hoạt Bản Quyền:**
   - Khi mở lên, hệ thống hiển thị bảng nhập mã bản quyền.
   - Dán mã bản quyền của bạn (dạng `2TAMNE-XXXX-XXXX-XXXX`) và nhấn **Kích Hoạt**.
5. **Dựng & Xuất Video:**
   - Tải ảnh, chọn nhạc, chỉnh sửa timeline và nhấn **🎬 Render Video**.

---

## 2. XỬ LÝ SỰ CỐ THƯỜNG GẶP (COMMON PROBLEMS)

| Hiện tượng | Nguyên nhân | Cách khắc phục nhanh |
| :--- | :--- | :--- |
| **Báo lỗi: "Python was not found..."** | Chưa cài Python hoặc chưa tích "Add to PATH". | Tải Python 64-bit từ python.org, chạy installer và tích chọn "Add python.exe to PATH". |
| **Cài đặt thư viện bị đứng/lỗi mạng** | Kết nối Internet chập chờn khi pip tải thư viện. | Kiểm tra kết nối mạng và chạy lại `start_windows.bat`. |
| **Không có card đồ họa NVIDIA** | Máy tính chỉ có CPU Intel/AMD. | Hệ thống tự động chuyển sang **Renderer D (Golden 4X)**, render video 60 FPS bình thường. |
| **Render bị lỗi bất ngờ** | Thiếu bộ nhớ hoặc xung đột driver. | Nhấn nút **[🩺 Báo Cáo Kỹ Thuật]** trên bảng lỗi $\rightarrow$ Gửi mã **Diagnostic ID** (`VBC-XXXXXXXX`) cho hỗ trợ. |
