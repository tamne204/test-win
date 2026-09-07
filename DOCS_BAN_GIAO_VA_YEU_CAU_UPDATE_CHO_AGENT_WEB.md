# 📜 TÀI LIỆU BÀN GIAO & YÊU CẦU NÂNG CẤP DÀNH CHO AGENT QUẢN LÝ WEB
## HỆ THỐNG ĐỒNG BỘ BẢN QUYỀN, TOKEN VÍ & DỰ ÁN CAPCUT V2 (PORTAL 2TAMNE.SITE)

- **Người lập**: Agent Quản Lý Desktop App (2TOOLNE Studio)
- **Người nhận**: Agent Quản Lý Web & Cơ Sở Dữ Liệu (`2tamne.site`)
- **Ngày lập**: 07/09/2026
- **Mục tiêu**: Đồng bộ hóa kiến trúc giữa ứng dụng Desktop AutoEdit CapCut V2 (đã hợp nhất giao diện App Upscale) với Hệ thống Web API / MySQL Production.

---

### 1. Bối Cảnh & Kiến Trúc Nâng Cấp
Ứng dụng Desktop **2TOOLNE AutoEdit for CapCut V2** đã được chuyển đổi toàn diện sang giao diện hiện đại đồng bộ với **2TOOLNE Upscale**, tích hợp:
1. **Module Studio Tạo Dự Án 3 Cột**: Nhập liệu đa năng (hỗ trợ ảnh, ZIP, RAR, Folder), Subtitle Studio (Forced Alignment âm học từ bản mẫu FFmpeg), Bộ điều khiển chuyển động ngẫu nhiên (Ken Burns %).
2. **Hàng Đợi Xử Lý (Queue Manager)**: Quản lý hàng loạt tác vụ nền.
3. **Phân Hệ AI Upscale Tích Hợp**: Hỗ trợ nâng cấp ảnh 2K / 4K trực tiếp trước khi đưa vào timeline CapCut.

Để hệ sinh thái vận hành đồng bộ và người dùng có thể sử dụng chung 1 tài khoản, 1 ví Token và 1 mã bản quyền, Web Management Agent cần rà soát và cập nhật các API sau.

---

### 2. Danh Sách API Web Cần Xác Nhận & Cập Nhật

#### 🔹 API 1: Lấy Thông Tin Tài Khoản & Số Dư Ví Token
- **Endpoint**: `GET /api/v1/user/profile` hoặc `POST /api/v1/license/profile`
- **Mục đích**: Desktop app gọi khi khởi động để hiển thị số dư Token lên Header (`Ví: XX Token`) và kiểm tra số dư trước khi Upscale.
- **Request Headers**:
  ```http
  Authorization: Bearer <LICENSE_OR_USER_TOKEN>
  X-Device-Id: <SHA256_HWID_FINGERPRINT>
  ```
- **Response Format mong đợi**:
  ```json
  {
    "success": true,
    "user_id": 1024,
    "username": "user_vip",
    "tokens_balance": 150,
    "plan_name": "PRO_MONTHLY",
    "license_status": "ACTIVE",
    "expires_at": "2026-12-31T23:59:59Z"
  }
  ```

---

#### 🔹 API 2: Ghi Nhận Log Trừ Token Khi Upscale (`/api/v1/upscale/log`)
- **Endpoint**: `POST /api/v1/upscale/log`
- **Quy tắc trừ Token**:
  - **Ảnh độ phân giải 2K** (cạnh $\le 2560\text{px}$): Trừ **1 Token**.
  - **Ảnh độ phân giải 4K** (cạnh $> 2560\text{px}$): Trừ **2 Token**.
- **Payload từ Desktop gửi lên**:
  ```json
  {
    "device_id": "e2ab75b240d25660de3fec347c70d10d",
    "license_key_last4": "836C",
    "file_name": "scene_001_portrait.png",
    "resolution_type": "4K",
    "tokens_consumed": 2,
    "processing_time_seconds": 3.8
  }
  ```
- **Phản hồi mong đợi**:
  ```json
  {
    "success": true,
    "tokens_consumed": 2,
    "remaining_tokens": 148,
    "message": "Trừ 2 token thành công."
  }
  ```

---

#### 🔹 API 3: Xác Thực Kích Hoạt & Cấp Phép Signed Entitlement (Hiện Đã Chuẩn Hóa)
- **Endpoint**: `POST /api/v1/capcut/activate`
- **Payload**:
  ```json
  {
    "license_key": "2TL-CAP-XXXX-XXXX-XXXX",
    "device_id": "SHA256_HWID_FINGERPRINT",
    "platform": "darwin",
    "app_version": "2.0.0"
  }
  ```
- **Phản hồi**: Trả về `signed_entitlement` được ký bằng Ed25519 private key của Server để Desktop Sidecar giải mã an toàn offline.

---

### 3. Quy Ước Đặt Tên Dự Án Mặc Định (Đã Thống Nhất)
Nếu người dùng để trống tên dự án khi tạo, hệ thống desktop tự động đặt tên theo cấu trúc:
```text
2toolne_HHddMMyy
```
- Trong đó:
  - `HH`: Giờ tạo (2 chữ số, ví dụ `02`)
  - `dd`: Ngày (2 chữ số, ví dụ `07`)
  - `MM`: Tháng (2 chữ số, ví dụ `09`)
  - `yy`: Năm (2 chữ số, ví dụ `26`)
  - *Ví dụ mẫu*: `2toolne_02070926`

Web Dashboard nếu có chức năng hiển thị danh sách dự án đồng bộ Cloud nên tuân thủ định dạng hiển thị ngày giờ tương ứng để người dùng dễ nhận diện.

---

### 4. Checklist Dành Cho Web Management Agent
- [ ] Kiểm tra bảng `users` hoặc `licenses` trong database MySQL `ecxaebka_bot` đã có trường `token_balance` (hoặc `tokens_balance`).
- [ ] Đảm bảo API `POST /api/v1/upscale/log` có transaction atomic để trừ token an toàn, không bị race-condition.
- [ ] Đảm bảo API `POST /api/v1/capcut/activate` và `deactivate` hoạt động ổn định trên Production `https://www.2tamne.site`.
- [ ] (Tùy chọn) Bổ sung webhook IPN SePay tự động cộng token ví khi người dùng quét mã thanh toán thành công.
