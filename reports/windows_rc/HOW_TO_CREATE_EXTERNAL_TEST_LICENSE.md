# 🔑 HƯỚNG DẪN TẠO MÃ BẢN QUYỀN KIỂM THỬ NGOÀI (DISPOSABLE TEST LICENSE)
## 2TOOLNE AUTOEDIT V2 — EXTERNAL LAB LICENSE GENERATION

> [!CAUTION]
> **QUY TẮC BẢO MẬT TUYỆT ĐỐI:**
> 1. Tuyệt đối **KHÔNG hardcode** License Key vào mã nguồn repository hoặc GitHub Actions workflow.
> 2. Tuyệt đối **KHÔNG đưa License Key thật** vào gói artifact phát hành công khai.
> 3. Mã bản quyền kiểm thử phải được truyền **riêng biệt và bảo mật** trực tiếp cho Tester qua kênh riêng (Zalo, Telegram hoặc Email bảo mật).

---

### CÁC BƯỚC TẠO MÃ BẢN QUYỀN KIỂM THỬ (DÀNH CHO CHỦ SỞ HỮU / ADMIN):

1. **Đăng nhập vào Trang Quản Trị Bản Quyền:**
   - Truy cập trang quản trị Admin: `https://www.2tamne.site/license_admin.php`
   - Đăng nhập bằng tài khoản Quản trị viên hệ thống.

2. **Tạo License Key thử nghiệm có giới hạn (Disposable License):**
   - **Gói sản phẩm (Product Tier)**: Chọn `CAPCUT_V2_PRO` (hoặc `CAPCUT_V2_TESTER`).
   - **Thời hạn sử dụng (Duration)**: Chọn `7 ngày` hoặc `14 ngày` (đủ cho chu kỳ kiểm thử).
   - **Số thiết bị tối đa (Max Activations)**: Đặt giới hạn cố định là `1 thiết bị`.
   - **Ghi chú (Notes)**: Ghi rõ `External Lab Tester - [Tên Tester] - Phase 5B.1`.
   - Nhấn **"Tạo License Key"**.

3. **Bàn giao cho Tester:**
   - Sao chép License Key vừa tạo (định dạng `2TL-CAP-XXXX-XXXX-XXXX-XXXX`).
   - Gửi mã này trực tiếp cho Tester kèm theo đường dẫn tải gói `2toolne-autoedit-windows-rc-2.0.0`.
   - Nhắc Tester nhập mã này tại **Bước 6** của checklist kiểm thử.

4. **Thu hồi / Kiểm tra trạng thái:**
   - Trong bảng điều khiển `license_admin.php`, Admin có thể theo dõi thời điểm kích hoạt của Tester (`activated_at`), hệ điều hành nhận diện (`win32 x64`), và trạng thái sau khi Tester bấm hủy kích hoạt (`deactivated`).
