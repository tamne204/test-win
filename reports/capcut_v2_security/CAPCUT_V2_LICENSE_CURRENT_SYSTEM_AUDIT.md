# 🔐 BÁO CÁO AUDIT HỆ THỐNG XÁC THỰC & BẢN QUYỀN 2TOOLNE HIỆN HỮU
## (2TOOLNE AUTH & LICENSE CURRENT SYSTEM AUDIT)

**Audit Version**: 1.0.0 (Phase 4 Commercial Security)  
**Tác vụ**: Khảo sát toàn diện hệ thống người dùng, mật khẩu, phiên làm việc, mã bản quyền, liên kết thiết bị và cơ chế quản trị của 2TOOLNE.  
**Mục tiêu**: Đảm bảo mở rộng trên nền tảng sẵn có, tuyệt đối **không tạo ra hệ thống người dùng song song** (No parallel user system).

---

## 1. TỔNG QUAN HỆ THỐNG HIỆN HỮU

Hệ thống hạ tầng máy chủ của 2TOOLNE được vận hành trên hạ tầng DirectAdmin Shared Hosting (`https://www.2tamne.site/`) với cơ sở dữ liệu MySQL/MariaDB `ecxaebka_bot`. Hệ thống bao gồm 2 phân hệ dịch vụ:
1. **Phân hệ Legacy License API** (`website/api/license/` & `website/storage/db.php`):
   - Chuyên phục vụ cho **FFmpeg V1 (Slideshow Studio)** và tiện ích mở rộng **Labs Extension**.
   - Vận hành qua `activate.php` và `verify.php`.
2. **Phân hệ REST API v1** (`website/api/v1/`):
   - Chuyên phục vụ cho dịch vụ **2TOOLNE Upscale & Cloud V2**.
   - Kiến trúc RESTful hoàn chỉnh với Router, Database pooling, JWT Session, quản lý thiết bị (`devices`), ví token (`credit_wallets`) và quản lý hạn mức dung lượng.

---

## 2. CHI TIẾT KHẢO SÁT TỪNG THÀNH PHẦN KỸ THUẬT

### 2.1. Người dùng (Users)
- **Bảng cơ sở dữ liệu**: Bảng duy nhất `users` trong MySQL `ecxaebka_bot`.
- **Cấu trúc trường**:
  - `id` (INT AUTO_INCREMENT, Primary Key)
  - `username` (VARCHAR, duy nhất)
  - `email` (VARCHAR, duy nhất)
  - `fullname` (VARCHAR)
  - `phone` (VARCHAR)
  - `password_hash` (VARCHAR)
  - `role` (VARCHAR: `user`, `super_admin`, `admin`, `sales`, `tech_support`, `content_manager`, `custom`)
  - `permissions` (TEXT JSON: lưu trữ mảng quyền hạn chi tiết)
  - `registered_ip` (VARCHAR)
  - `created_at` (DATETIME)
- **Quy tắc Phase 4**: **Sử dụng trực tiếp bảng `users` này**, không tạo thêm bảng người dùng thứ hai.

### 2.2. Xác thực Mật khẩu (Password Authentication)
- Triển khai chuẩn bằng hàm `password_hash($password, PASSWORD_BCRYPT)` của PHP 8.
- So khớp đăng nhập thông qua `password_verify($password, $user['password_hash'])`.
- Kiểm tra độ phức tạp tối thiểu 8 ký tự khi đăng ký.

### 2.3. Xác thực Phiên & JWT (JWT / Session Authentication)
- Trong `AuthController::login`: Sau khi xác thực mật khẩu, tạo token phiên bằng HMAC-SHA256:
  ```php
  $token = hash_hmac('sha256', (string)$user['id'] . time(), JWT_AUTH_SECRET);
  ```
- Khóa bí mật `JWT_AUTH_SECRET` được bảo vệ phía máy chủ, đọc từ biến môi trường hoặc cấu hình `config.php`.

### 2.4. Mã Bản Quyền (License Keys)
- **Bảng cơ sở dữ liệu**: Bảng `licenses`.
- **Cấu trúc trường**:
  - `id` (INT AUTO_INCREMENT)
  - `license_key` (VARCHAR, duy nhất)
  - `product` (VARCHAR: `SLIDESHOW`, `LABS_EXTENSION`, `2TOOLNE`)
  - `tier` (VARCHAR: `VIP`, `TRIAL`, `LIFETIME`)
  - `duration_days` (INT: số ngày hiệu lực, 0 hoặc 9999 là Lifetime)
  - `status` (VARCHAR: `active`, `banned`, `expired`)
  - `hwid` (VARCHAR: mã phần cứng thiết bị được kích hoạt)
  - `device_name` (VARCHAR: tên máy)
  - `tool_version` (VARCHAR: phiên bản client)
  - `owner_username` (VARCHAR: liên kết với tài khoản `users.username`)
  - `note` (TEXT: ghi chú đơn hàng hoặc nguồn gốc key)
  - `activated_at` (DATETIME)
  - `expires_at` (DATETIME)
  - `created_at` (DATETIME)

### 2.5. Quyền Lợi Bản Quyền (License Entitlements) & Gói (Plans)
- Trong REST API v1, bảng `license_entitlements` định danh:
  - `id` (VARCHAR, e.g. `lic_...`)
  - `user_id` (INT)
  - `plan` (VARCHAR: `PRO`, `STUDIO`, `ENTERPRISE`)
  - `credit_mode` (VARCHAR: `METERED`, `UNLIMITED`)
  - `max_devices` (INT, mặc định là 3 thiết bị)
  - `expires_at` (DATETIME)

### 2.6. Khóa Thiết Bị / HWID (Device / HWID Binding)
- **Phía V1**: Sử dụng hàm `get_hwid()` trong `license_manager.py` kết hợp MachineGuid (Registry), CimInstance UUID (PowerShell), Volume Serial (CMD), hoặc `IOPlatformUUID` trên macOS, sau đó băm SHA-256 dạng chuỗi hex in hoa 64 ký tự.
- **Phía API v1**: Bảng `devices`:
  - `id` (`dev_...`)
  - `user_id`
  - `device_fingerprint`
  - `device_alias`
  - `platform` (`windows-x64`, `mac-arm64`, etc.)
  - `status` (`ACTIVE`, `REVOKED`, `DISABLED`)
  - `activated_at`, `last_seen_at`

### 2.7. Bản Quyền Ngoại Tuyến (Offline License)
- **Hiện trạng cũ trong `LicenseController::issue`**:
  - Server ký gói payload bản quyền bằng HMAC-SHA256 sử dụng khóa bí mật `HMAC_LICENSE_SECRET`.
  - **Hạn chế nghiêm trọng**: Vì là HMAC (chữ ký đối xứng), ứng dụng Desktop muốn thẩm định offline sẽ bắt buộc phải lưu `HMAC_LICENSE_SECRET`. Nếu kẻ tấn công reverse engineering client, họ sẽ chiếm được secret và có thể tự ký cấp phép cho bất kỳ máy nào!
  - **Yêu cầu nâng cấp cho CapCut V2**: Chuyển đổi sang **Ed25519 (Chữ ký bất đối xứng)**. Server giữ Private Key, Desktop chỉ giữ Public Key để kiểm tra.

### 2.8. Kích Hoạt Bản Quyền (License Activation)
- Endpoint `POST /api/license/activate.php`:
  - Nhận `license_key`, `hwid`, `device_name`, `tool_version`.
  - Nếu `hwid` trống: Kích hoạt lần đầu, ghi nhận `hwid`, tính toán `expires_at` dựa vào `duration_days`.
  - Nếu đã kích hoạt: So sánh `hwid`. Nếu không trùng, trả về lỗi `HWID_MISMATCH`.

### 2.9. Xác Thực Bản Quyền Định Kỳ (License Verification)
- Endpoint `POST /api/license/verify.php`:
  - Nhận `license_key`, `hwid`.
  - Kiểm tra trạng thái `banned`, `expired`, tính toán `days_left`.
  - Nếu không thể kết nối mạng, client V1 chuyển sang trạng thái `offline_cache`.

### 2.10. Hạn Mức Thiết Bị (Device Limits)
- Trong V1: Giới hạn cứng 1 key = 1 thiết bị. Muốn đổi máy phải bấm Reset HWID trên web hoặc liên hệ admin.
- Trong REST API v1 (`DeviceController`): Kiểm tra số lượng thiết bị đang `ACTIVE` với `max_devices`. Nếu vượt quá, trả về lỗi `DEVICE_LIMIT_EXCEEDED` (HTTP 403).

### 2.11. Thu Hồi Bản Quyền (Revocation)
- Khi key bị đổi trạng thái thành `banned` hoặc thiết bị bị đánh dấu `REVOKED`:
  - Lần kiểm tra online tiếp theo trả về `KEY_BANNED` / `DEVICE_UNAUTHORIZED`.
  - Ứng dụng client khóa ngay lập tức các tính năng thương mại.

### 2.12. Quản Trị Bản Quyền (Admin License Management)
- Tệp quản trị: `website/license_admin.php` (~7,200 dòng mã nguồn).
- Cung cấp giao diện quản trị phân quyền (RBAC) với các nhóm quyền: `keys.view`, `keys.create`, `keys.reset_hwid`, `keys.ban`, `keys.assign`, `keys.delete`.
- Hỗ trợ tạo key cho từng dòng sản phẩm với ghi chú đơn hàng.

### 2.13. Xác Thực Web-to-Desktop (Single Sign-On)
- Endpoints trong `AuthController.php`:
  - `POST /auth/session/create`: Client desktop yêu cầu tạo session với `challenge` và `port`. Nhận về `auth_url` để mở trang web đăng nhập.
  - `GET /auth/session/status`: Client desktop thăm dò (poll) trạng thái session. Khi người dùng đăng nhập thành công trên web, web trả về token và thông tin người dùng.

### 2.14. Nhật Ký Kiểm Toán (Audit Logs)
- Ghi nhận lịch sử giao dịch nạp tiền/token trong `credit_transactions`.
- Ghi nhận lịch sử chỉnh sửa tài khoản/ví trong `admin_audit_logs`.

### 2.15. Danh Sách Endpoints Hiện Hữu
| Endpoint | Phương thức | Chức năng |
| :--- | :---: | :--- |
| `/api/license/activate.php` | `POST` | Kích hoạt key với HWID (Legacy) |
| `/api/license/verify.php` | `POST` | Thẩm định key định kỳ (Legacy) |
| `/api/license/check_update.php` | `GET` | Kiểm tra phiên bản mới |
| `/api/v1/auth/register` | `POST` | Đăng ký tài khoản người dùng |
| `/api/v1/auth/login` | `POST` | Đăng nhập tài khoản lấy JWT |
| `/api/v1/auth/session/create` | `POST` | Tạo phiên SSO Web-to-Desktop |
| `/api/v1/auth/session/status` | `GET` | Kiểm tra trạng thái phiên SSO |
| `/api/v1/devices/activate` | `POST` | Kích hoạt thiết bị kèm hạn mức |
| `/api/v1/devices/deactivate` | `POST` | Hủy kích hoạt thiết bị |
| `/api/v1/license/issue` | `POST` | Cấp token offline (HMAC) |

---

## 3. KẾT LUẬN & ĐỊNH HƯỚNG MỞ RỘNG CHO CAPCUT V2

1. **Tuyệt đối không tạo bảng người dùng song song**: Tận dụng triệt để bảng `users` và bảng `licenses` hiện có.
2. **Định danh sản phẩm mới**: Bổ sung sản phẩm `2toolne.capcut.v2` vào cơ sở dữ liệu. Key của `SLIDESHOW` (V1) hoặc `UPSCALE` không thể kích hoạt cho CapCut V2.
3. **Nâng cấp sang Ed25519 Asymmetric Signature**:
   - Server sinh cặp khóa Ed25519 (Private Key / Public Key).
   - Server giữ Private Key trong biến môi trường / tệp bảo mật ngoài web root.
   - Desktop App (Electron & Sidecar) chỉ nhúng Public Key để thẩm định chữ ký số gói entitlement.
4. **Bổ sung API chuyên biệt cho CapCut V2**:
   - `POST /api/v1/capcut/activate`
   - `POST /api/v1/capcut/verify`
   - `POST /api/v1/capcut/deactivate`
   Hỗ trợ Rate Limiting, Privacy Device ID và định dạng key `2TL-CAP-XXXX-XXXX-XXXX-XXXX`.
