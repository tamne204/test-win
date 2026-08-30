# 🖥️ QUY TRÌNH HỖ TRỢ KHÁCH HÀNG SỬ DỤNG WINDOWS NVIDIA
## VIBECODE STUDIO v2.2.3.18-RC1

> **Mã tài liệu:** `docs/NVIDIA_CUSTOMER_SUPPORT.md`  
> **Áp dụng cho:** Khách hàng sử dụng máy tính Windows 10/11 có card đồ họa rời NVIDIA GeForce / RTX / Quadro.

---

## 1. QUY TRÌNH TIÊU CHUẨN DÀNH CHO KHÁCH HÀNG NVIDIA

1. **Khởi động ứng dụng:** Mở `SlideshowStudio.vbs`. Hệ thống tự động nhận diện GPU NVIDIA qua PyTorch CUDA và kích hoạt **Renderer G (Glide GPU Subpixel)**.
2. **Kích hoạt Bản Quyền:** Nhập key hợp lệ để mở khóa toàn bộ chức năng.
3. **Render Video 60 FPS:** Động cơ Renderer G kết hợp với bộ mã hóa phần cứng `h264_nvenc` sẽ xuất video với thông lượng cao ($> 120\text{ FPS}$).

---

## 2. QUY TRÌNH BÁO LỖI & HỖ TRỢ KỸ THUẬT (TROUBLESHOOTING WORKFLOW)

Nếu xảy ra bất kỳ sự cố nào trong quá trình render trên máy NVIDIA:

### Bước 1: Mở Bảng Chẩn Đoán Kỹ Thuật
- Trên thông báo lỗi render: Bấm nút **[📤 Gửi Báo Cáo Kỹ Thuật]**.
- Hoặc vào **Cài đặt (Settings)** $\rightarrow$ Chọn **Báo Cáo Chẩn Đoán (Diagnostics)**.

### Bước 2: Xem Trước & Gửi Báo Cáo
- Bấm **[👁️ Xem Chi Tiết]** để kiểm tra dữ liệu kỹ thuật sẽ gửi (GPU, Driver, CUDA, Lỗi render).
- Bấm **[🚀 Gửi Báo Cáo Kỹ Thuật]** (hoặc **[💾 Lưu Tại Máy]** nếu không có mạng).

### Bước 3: Cung Cấp Mã Chẩn Đoán Cho Đội Ngũ Hỗ Trợ
- Sau khi gửi, màn hình sẽ hiển thị mã định danh dạng:
  $$\mathbf{VBC\text{-}YYYYMMDD\text{-}XXXXXX} \quad (\text{Ví dụ: } \texttt{VBC-20260830-8F31A2})$$
- Khách hàng **CHỈ CẦN** nhắn mã này cho lập trình viên/hỗ trợ kỹ thuật.

---

## 3. 🔒 NGUYÊN TẮC BẢO VỆ DỮ LIỆU KHÁCH HÀNG

Khách hàng **TUYỆT ĐỐI KHÔNG CẦN VÀ KHÔNG NÊN** gửi các thông tin sau:
- ❌ **Không gửi License Key** (Hệ thống đã tự che giấu trong báo cáo).
- ❌ **Không gửi File Video, Ảnh, Nhạc hoặc Kịch bản** (Báo cáo chỉ chứa thông số kỹ thuật GPU và log lỗi).
- ❌ **Không gửi Mật khẩu máy tính hay quyền điều khiển từ xa**.

Lập trình viên sẽ tra cứu mã `VBC-*` trên máy chủ `2tamne.site` để phân tích chính xác phiên bản Driver NVIDIA, thông số CUDA và mã lỗi FFmpeg để hỗ trợ ngay lập tức.
