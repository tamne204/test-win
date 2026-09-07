# BÁO CÁO BÀN GIAO KỸ THUẬT TOÀN DIỆN: GIAO THỨC WEB, DATABASE & QUY CHUẨN HỆ THỐNG (2TOOLNE UPSCALE)

**Dành cho:** Agent Quản Lý & Phát Triển Ứng Dụng Desktop (2toolne Upscale)  
**Đơn vị phát hành:** Agent Quản Trị & Tích Hợp Web (`2tamne.site`)  
**Phiên bản API:** `v1.0.0 Production`  
**Cập nhật lần cuối:** 04/09/2026  

---

## ⛔ 1. NGUYÊN TẮC BẮT BUỘC VỀ KIẾN TRÚC (ARCHITECTURAL CONSTRAINTS)

> [!CAUTION]
> **NGHIÊM CẤM TỰ Ý TẠO THÊM CÁC FILE TRANG WEB MỚI (NO ORPHAN HTML PAGES):**
> - Website `2tamne.site` vận hành theo **Mô hình Cổng Hợp Nhất (Unified Single Portal)**.
> - **Tuyệt đối không** tự ý sinh ra các trang web lẻ như `/upscale/dashboard.html` hay `/upscale/admin.html` (các trang này trước đây gây phân mảnh trải nghiệm người dùng, vỡ phân quyền và đã bị xoá bỏ hoàn toàn).
> - Mọi thành phần hiển thị trên web đã được phân định ranh giới cố định:
>   1. **Trang Giới Thiệu (Landing Page):** Chỉ duy nhất tại `https://www.2tamne.site/upscale/index.html` (chứa thông tin tính năng, bảng giá và nút tải).
>   2. **Cổng Khách Hàng (User Portal):** Tích hợp hợp nhất tại `https://www.2tamne.site/index.php` (các tab: Ví Token `#tab-wallet-view`, Tải App `#tab-downloads`, Mua Gói `#tab-buy-key`, Báo Lỗi `#tab-bugs`, Góp Ý `#tab-features`).
>   3. **Cổng Quản Trị Viên (Admin Portal):** Duy nhất tại `https://www.2tamne.site/license_admin.php` (duyệt đơn nạp token, điều chỉnh ví, phân quyền RBAC).
>   4. **Giao tiếp giữa App Desktop và Web Server:** **100% thông qua RESTful JSON API** tại `https://www.2tamne.site/api/v1/`. App không được phụ thuộc vào web view nhúng bất kỳ trang nội bộ nào.

---

## 🌐 2. HỆ THỐNG GIAO THỨC RESTful API (`/api/v1/*`)

- **Base URL:** `https://www.2tamne.site/api/v1`
- **Định dạng dữ liệu:** `application/json; charset=utf-8`
- **Quy chuẩn Header gửi lên từ Desktop App:**
  ```http
  Content-Type: application/json
  Accept: application/json
  X-Device-Id: <DEVICE_FINGERPRINT_SHA256>
  Authorization: Bearer <JWT_TOKEN> (nếu đã đăng nhập)
  ```

---

### 2.1. Nhóm Xác Thực Tài Khoản (Authentication)

#### 🔹 Đăng ký tài khoản (`POST /auth/register`)
- Khách hàng đăng ký mới sẽ được tự động kích hoạt ví với **50 Tokens dùng thử miễn phí** (`FREE_SIGNUP_TOKENS`).
- **Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "Password123@",
    "device_id": "a1b2c3d4e5f6...",
    "platform": "windows"
  }
  ```
- **Response thành công (200):**
  ```json
  {
    "success": true,
    "user_id": 15,
    "email": "user@example.com",
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "free_tokens": 50
  }
  ```

#### 🔹 Đăng nhập (`POST /auth/login`)
- **Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "Password123@"
  }
  ```
- **Response thành công (200):**
  ```json
  {
    "success": true,
    "user_id": 15,
    "email": "user@example.com",
    "role": "user",
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
  }
  ```

---

### 2.2. Nhóm Kiểm Soát Thiết Bị (Hardware Fingerprint & Limits)

Mỗi tài khoản được giới hạn số lượng thiết bị kích hoạt đồng thời theo gói đăng ký (`BASIC`: 1 máy, `PRO`: 3 máy, `STUDIO`: 5 máy, `ENTERPRISE`: không giới hạn).

#### 🔹 Kích hoạt thiết bị (`POST /devices/activate`)
- **Body:**
  ```json
  {
    "user_id": "15",
    "device_id": "SHA256_HWID_FINGERPRINT",
    "device_alias": "Desktop-RTX3050Ti-Studio",
    "platform": "windows"
  }
  ```
- **Response thành công (200):**
  ```json
  {
    "success": true,
    "message": "Device activated successfully.",
    "device_id": "SHA256_HWID_FINGERPRINT",
    "active_devices": 1,
    "max_allowed": 3
  }
  ```
- **Lỗi vượt quá số máy (403):** `DEVICE_LIMIT_EXCEEDED` $\rightarrow$ Desktop App cần thông báo người dùng vào `2tamne.site` để gỡ máy cũ.

#### 🔹 Hủy kích hoạt thiết bị (`POST /devices/deactivate`)
- **Body:**
  ```json
  {
    "user_id": "15",
    "device_id": "SHA256_HWID_FINGERPRINT"
  }
  ```

#### 🔹 Kiểm tra trạng thái máy (`GET /devices/status?device_id=...`)
- **Response (200):**
  ```json
  {
    "success": true,
    "status": "ACTIVE",
    "user_id": "15",
    "last_seen_at": "2026-09-04 21:30:00"
  }
  ```

---

### 2.3. Cấp Giấy Phép Bản Quyền Ký Số (HMAC License Signing - Hỗ Trợ 72h Offline)

Nhằm đảm bảo máy tính khách hàng vẫn xử lý ảnh mượt mà khi mất mạng Internet đột ngột trong lúc đang render batch, hệ thống cung cấp chữ ký HMAC-SHA256 có hiệu lực **72 giờ** (`OFFLINE_GRACE_HOURS`).

#### 🔹 Xin cấp giấy phép (`POST /license/issue`)
- **Body:**
  ```json
  {
    "user_id": "15",
    "device_id": "SHA256_HWID_FINGERPRINT"
  }
  ```
- **Response thành công (200):**
  ```json
  {
    "success": true,
    "license": {
      "user_id": "15",
      "email": "user@example.com",
      "plan": "PRO",
      "device_id": "SHA256_HWID_FINGERPRINT",
      "credit_mode": "METERED",
      "token_balance": 3200,
      "issued_at": "2026-09-04 21:00:00",
      "expires_at": "2026-09-07 21:00:00",
      "signature": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    }
  }
  ```
- **Quy tắc kiểm tra chữ ký Offline trên Desktop App:**
  ```javascript
  // Desktop App (Node.js / Electron)
  const crypto = require('crypto');
  function verifyLicenseOffline(license, secretKey) {
    const { signature, ...payload } = license;
    const serialized = JSON.stringify(payload);
    const expected = crypto.createHmac('sha256', secretKey).update(serialized).digest('hex');
    const isValid = crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
    const notExpired = new Date(license.expires_at) > new Date();
    return isValid && notExpired;
  }
  ```
- **Khoá bí mật (HMAC_SECRET):** `2toolne_license_signature_key_2026_super_secure`

---

### 2.4. Vòng Đời Token 3 Bước Tuyệt Đối (Strict 3-Step Token Lifecycle)

Quy trình chống trừ tiền oan, chống mất điện/mất mạng giữa chừng và chống gửi trùng lặp tác vụ (Idempotency).

```
   [BẮT ĐẦU BATCH] ────────> 1. RESERVE (POST /credits/reserve)
                                  │
                                  ├─ Khóa tạm `amount` token vào `reserved_balance`.
                                  ├─ Trả về `reservation_id`.
                                  │
   [MỖI ẢNH THÀNH CÔNG] ───> 2. COMMIT (POST /credits/commit)
                                  │
                                  ├─ Gửi kèm `idempotency_key` (duy nhất cho từng ảnh).
                                  ├─ Trừ vĩnh viễn token tương ứng (1 token = 2K, 2 token = 4K).
                                  ├─ Ghi dòng `credit_transactions` (Type: UPSCALE).
                                  │
   [HỦY HOẶC GẶP SỰ CỐ] ───> 3. RELEASE (POST /credits/release)
                                  │
                                  └─ Hoàn trả 100% token chưa dùng từ `reserved_balance` về `balance`.
```

#### 🔸 Bước 1: Khóa Tạm Token Trước Khi Render (`POST /credits/reserve`)
- **Body:**
  ```json
  {
    "device_id": "SHA256_HWID_FINGERPRINT",
    "project_id": "proj_20260904_batch01",
    "amount": 20,
    "reservation_id": "res_a8b9c1d2e3" 
  }
  ```
  *(Ví dụ: Batch 10 ảnh 4K $\times$ 2 tokens = cần giữ 20 tokens).*
- **Response thành công (200):**
  ```json
  {
    "success": true,
    "reservation_id": "res_a8b9c1d2e3",
    "remaining_balance": 3180,
    "reserved_amount": 20
  }
  ```
- **Lỗi không đủ token (402):** `INSUFFICIENT_TOKENS`
- **Gói Unlimited Studio:** Tự động trả về `success: true` và `remaining_balance: 999999` mà không giữ token.

#### 🔸 Bước 2: Khấu Trừ Thực Tế Từng Ảnh Đã Xuất Xong (`POST /credits/commit`)
- **Body:**
  ```json
  {
    "reservation_id": "res_a8b9c1d2e3",
    "project_id": "proj_20260904_batch01",
    "committed_amount": 2,
    "idempotency_key": "commit_proj01_img_001_sha256hash"
  }
  ```
- **Cơ chế Idempotency:** Nếu mạng chập chờn và Desktop App gửi lại cùng một `idempotency_key`, máy chủ **tuyệt đối không trừ lần 2**, mà trả về ngay `already_committed: true` kèm số dư an toàn.
- **Response thành công (200):**
  ```json
  {
    "success": true,
    "committed_amount": 2,
    "total_committed": 2,
    "balance_after": 3180
  }
  ```

#### 🔸 Bước 3: Hoàn Trả Token Khi Dự Án Bị Dừng / Hủy / Lỗi GPU (`POST /credits/release`)
- **Body:**
  ```json
  {
    "reservation_id": "res_a8b9c1d2e3",
    "project_id": "proj_20260904_batch01",
    "unspent_amount": 18,
    "reason": "User cancelled at image 2/10"
  }
  ```
- **Response thành công (200):**
  ```json
  {
    "success": true,
    "released_amount": 18,
    "new_balance": 3198,
    "new_reserved_balance": 0
  }
  ```

---

### 2.5. Tra Cứu Ví & Biến Động Số Dư (Wallet Endpoints)

#### 🔹 Xem số dư ví (`GET /wallet/balance?user_id=15`)
- **Response (200):**
  ```json
  {
    "success": true,
    "balance": 3198,
    "reserved_balance": 0,
    "currency": "TOKENS",
    "plan": "PRO",
    "credit_mode": "METERED"
  }
  ```

#### 🔹 Xem lịch sử biến động (`GET /wallet/transactions?user_id=15&limit=20`)
- **Response (200):**
  ```json
  {
    "success": true,
    "transactions": [
      {
        "id": "tx_7b9f...",
        "amount": -2,
        "balance_after": 3180,
        "type": "UPSCALE",
        "reference_id": "proj_20260904_batch01",
        "description": "Local image upscale completed",
        "created_at": "2026-09-04 21:05:12"
      }
    ]
  }
  ```

---

## 🗄️ 3. CƠ SỞ DỮ LIỆU & TRUY VẤN MẪU (DATABASE & SQL DICTIONARY)

- **Hệ quản trị:** MariaDB / MySQL 8.x
- **Tên Database:** `ecxaebka_bot`
- **Bảng mã ký tự:** `utf8mb4_unicode_ci`

### 3.1. Sơ Đồ Cấu Trúc Bảng Dữ Liệu (Schema Details)

```
       ┌────────────────────────┐             ┌────────────────────────┐
       │         users          │ 1         1 │     credit_wallets     │
       ├────────────────────────┼─────────────┼────────────────────────┤
       │ id (PK, INT)           │             │ id (PK, VARCHAR)       │
       │ username               │             │ user_id (FK)           │
       │ email                  │             │ balance (BIGINT)       │
       │ password_hash          │             │ reserved_balance       │
       │ role (user/admin)      │             │ currency               │
       │ permissions (JSON/TXT) │             │ updated_at             │
       └───────────┬────────────┘             └────────────────────────┘
                   │ 1
                   ├──────────────────────────┐ 1
                   │                          │
       ┌───────────▼────────────┐ 1         1 ┌▼───────────────────────┐
       │        devices         │             │  license_entitlements  │
       ├────────────────────────┤             ├────────────────────────┤
       │ id (PK, VARCHAR)       │             │ id (PK, VARCHAR)       │
       │ user_id (FK)           │             │ user_id (FK)           │
       │ device_fingerprint     │             │ plan (BASIC/PRO/STUDIO)│
       │ device_alias           │             │ credit_mode (METERED/  │
       │ platform (win/mac)     │             │              UNLIMITED)│
       │ status (ACTIVE/REVOKED)│             │ max_devices (INT)      │
       │ last_seen_at           │             │ expires_at             │
       └───────────┬────────────┘             └────────────────────────┘
                   │ 1
                   │
                   ▼ *
       ┌────────────────────────┐             ┌────────────────────────┐
       │  credit_reservations   │             │  credit_transactions   │
       ├────────────────────────┤             ├────────────────────────┤
       │ reservation_id (PK)    │             │ id (PK, VARCHAR)       │
       │ user_id (FK)           │             │ user_id (FK)           │
       │ device_id (FK)         │             │ amount (BIGINT)        │
       │ project_id             │             │ balance_after (BIGINT) │
       │ amount (giữ ban đầu)   │             │ type (UPSCALE/PURCHASE)│
       │ committed_amount       │             │ idempotency_key        │
       │ status (PENDING/REL...)│             │ reference_id           │
       │ created_at             │             │ created_at             │
       └────────────────────────┘             └────────────────────────┘
```

---

### 3.2. Bảng Mô Tả Trường Của Các Bảng Trọng Yếu

#### 1. Bảng `credit_wallets` (Quản lý ví Token)
| Trường | Kiểu | Mô tả |
| :--- | :--- | :--- |
| `id` | `VARCHAR(64)` **PK** | Mã định danh ví (`wal_...`) |
| `user_id` | `VARCHAR(64)` | ID tài khoản sở hữu ví |
| `balance` | `BIGINT(20)` | **Số dư khả dụng** (dùng để chạy tác vụ mới) |
| `reserved_balance` | `BIGINT(20)` | **Số token đang bị tạm khóa** bởi các batch đang chạy |
| `currency` | `VARCHAR(16)` | Mặc định `TOKENS` |
| `updated_at` | `DATETIME` | Thời điểm cập nhật số dư gần nhất |

#### 2. Bảng `license_entitlements` (Gói Bản Quyền & Chế Độ Trừ Phí)
| Trường | Kiểu | Mô tả |
| :--- | :--- | :--- |
| `id` | `VARCHAR(64)` **PK** | Khóa chính |
| `user_id` | `VARCHAR(64)` | ID tài khoản |
| `plan` | `ENUM('BASIC','PRO','STUDIO')` | Hạng gói tài khoản |
| `credit_mode` | `ENUM('METERED','UNLIMITED')` | **METERED:** Trừ token theo ảnh.<br>**UNLIMITED:** Không trừ token (Gói Studio). |
| `max_devices` | `INT(11)` | Số lượng thiết bị tối đa cho phép kích hoạt |
| `expires_at` | `DATETIME` | Ngày hết hạn gói dịch vụ |

#### 3. Bảng `credit_reservations` (Sổ Tạm Khóa Token Batch)
| Trường | Kiểu | Mô tả |
| :--- | :--- | :--- |
| `reservation_id` | `VARCHAR(64)` **PK** | Mã phiên tạm khóa (`res_...`) |
| `user_id` | `VARCHAR(64)` | ID người dùng |
| `device_id` | `VARCHAR(64)` | Thiết bị gửi lệnh giữ token |
| `project_id` | `VARCHAR(64)` | Mã dự án batch trên Desktop App |
| `amount` | `BIGINT(20)` | Tổng token tạm giữ lúc bắt đầu batch |
| `committed_amount` | `BIGINT(20)` | Số token đã trừ thực tế theo ảnh thành công |
| `status` | `ENUM('PENDING','COMMITTED','RELEASED')` | Trạng thái phiên giữ |

#### 4. Bảng `credit_transactions` (Sổ Cái Bất Biến - Immutable Ledger)
| Trường | Kiểu | Mô tả |
| :--- | :--- | :--- |
| `id` | `VARCHAR(64)` **PK** | Mã giao dịch (`tx_...`) |
| `user_id` | `VARCHAR(64)` | ID người dùng |
| `amount` | `BIGINT(20)` | Số token biến động (+ cộng tiền, - trừ tiền) |
| `balance_after` | `BIGINT(20)` | Số dư ví tại thời điểm phát sinh giao dịch |
| `type` | `ENUM(...)` | `PURCHASE`, `UPSCALE`, `PROMOTION`, `ADMIN_ADJUSTMENT`, `REFUND` |
| `idempotency_key`| `VARCHAR(128)` | Khóa chống trùng lặp giao dịch |
| `reference_id` | `VARCHAR(128)` | Mã tham chiếu đơn hàng hoặc `project_id` |

---

### 3.3. Các Câu Truy Vấn SQL Mẫu (ADQL / SQL Snippets)

#### 🔸 Kiểm tra thiết bị có hợp lệ và lấy số dư ví:
```sql
SELECT 
    d.id AS device_uuid,
    d.user_id,
    d.status AS device_status,
    l.plan,
    l.credit_mode,
    l.max_devices,
    w.balance,
    w.reserved_balance
FROM devices d
JOIN license_entitlements l ON d.user_id = l.user_id
JOIN credit_wallets w ON d.user_id = w.user_id
WHERE (d.device_fingerprint = :device_id OR d.id = :device_id)
  AND d.status = 'ACTIVE'
LIMIT 1;
```

#### 🔸 Khóa dòng an toàn (Pessimistic Lock) khi xử lý Token:
```sql
START TRANSACTION;

SELECT balance, reserved_balance 
FROM credit_wallets 
WHERE user_id = :user_id 
FOR UPDATE;

-- Nếu đủ tiền, chuyển 20 token sang reserved_balance:
UPDATE credit_wallets 
SET balance = balance - 20, 
    reserved_balance = reserved_balance + 20,
    updated_at = NOW() 
WHERE user_id = :user_id;

COMMIT;
```

#### 🔸 Kiểm tra Idempotency Key trước khi trừ tiền ảnh:
```sql
SELECT id, balance_after 
FROM credit_transactions 
WHERE idempotency_key = :idempotency_key 
LIMIT 1;
```

---

## 📁 4. HƯỚNG DẪN CHI TIẾT QUY TRÌNH LƯU FILE & PHÂN PHỐI QUA GOOGLE DRIVE

> [!IMPORTANT]
> **TẠI SAO PHẢI LƯU FILE VÀO GOOGLE DRIVE?**
> - Gói hosting DirectAdmin (`ecxaebka`) của `2tamne.site` có hạn mức ổ đĩa là **200 MB**.
> - Bản cài Windows là **148.89 MB**, bản macOS DMG là **125.03 MB** ($148.89 + 125.03 = 273.92\text{ MB} > 200\text{ MB}$). Do đó, máy chủ web không thể chứa cùng lúc cả 2 bản cài đặt mà không bị tràn đĩa.
> - Bằng cách lưu trữ trên **Google Drive** của quản trị viên (có dung lượng trống gần **80 GB**) và cấu hình cơ chế **HTTP 302 Smart Redirect**, toàn bộ file nặng tiêu tốn **0 bytes** ổ cứng của hosting, máy chủ chỉ làm nhiệm vụ chuyển hướng tải về tốc độ cao.

---

### 4.1. Đường Dẫn Thư Mục Lưu Trữ Cục Bộ Trên Máy Mac

Google Drive for Desktop đã được gắn kết (mounted) trực tiếp vào hệ thống tệp của máy Mac:

- **Đường dẫn hiển thị trong Finder:**  
  `Google Drive` ➔ `Drive của tôi` ➔ `2tamne.site` ➔ `download`
- **Đường dẫn POSIX tuyệt đối (sử dụng trong Terminal, Node.js, Python, Bash):**  
  ```bash
  /Users/2tamne/Library/CloudStorage/GoogleDrive-tam2504az@gmail.com/Drive của tôi/2tamne.site/download/
  ```
  *(Lưu ý: Ký tự "của tôi" là Unicode tổ hợp chuẩn tiếng Việt của macOS, nên dùng `glob` hoặc đường dẫn tuyệt đối như trên).*

---

### 4.2. Danh Mục File Phát Hành & Google Drive File ID Hiện Tại

| STT | Tên File Bản Cài Đặt | Dung Lượng | Google Drive File ID | Loại Bản Cài |
| :--- | :--- | :--- | :--- | :--- |
| 1 | **`2toolne Upscale Setup 1.0.0.exe`** | **148.89 MB** | `1OS1DdEGRoGmr1oaprHIfDOdE7uXgDpkE` | Bộ cài Windows chính thức (NSIS Installer) |
| 2 | **`2toolne Upscale-1.0.0-arm64.dmg`** | **125.03 MB** | `1q1lo6uIE4HgYUHKz3VZ6e44WcCxCAkSV` | Gói cài đặt macOS Apple Silicon (.dmg) |
| 3 | **`2toolne Upscale 1.0.0.exe`** | **148.67 MB** | `1R5Vgv3QbVXLKbYoFtp2fcSdAZoFV0QxR` | Bản Portable Windows 64-bit chạy ngay |
| 4 | **`2toolne Upscale-1.0.0-arm64-mac.zip`** | **125.16 MB** | `16B7zF7wVWnPeXarjwkHdEDHkEEXa8XQW` | Bản Portable macOS Universal (.zip) |
| 5 | **`SlideshowBuilder_Windows_latest.zip`** | **6.23 MB** | `1GXFGS6z4boaH7lG3W2EGZbAYTtlgJw2D` | Bộ công cụ Video Slideshow Windows |
| 6 | **`SlideshowBuilder_macOS_latest.zip`** | **6.23 MB** | `1k5VmWgmqMbo7-QxrqdAzNUMXLYbq5lEL` | Bộ công cụ Video Slideshow macOS |
| 7 | **`2tamne_Labs_Extension_latest.zip`** | **0.12 MB** | `1ahoWoT2dmLNN1AgVpjUfF7-rgZJDk98M` | Tiện ích mở rộng Google Labs Extension |

---

### 4.3. Quy Trình Xuất Bản Phiên Bản Mới (Build & Publish Workflow)

Mỗi khi Desktop App Agent phát hành phiên bản mới (ví dụ `1.0.1`), quy trình thực hiện gồm 4 bước chuẩn hóa:

#### 🔹 Bước 1: Build file cài đặt Desktop
Tại thư mục mã nguồn app `/Users/2tamne/Documents/toolupscale/`:
```bash
npm run build:win   # Sinh ra file tại release/
npm run build:mac   # Sinh ra file tại release/
```

#### 🔹 Bước 2: Copy file đã build vào thư mục Google Drive
Sử dụng lệnh copy terminal hoặc script tự động:
```bash
GOOGLE_DRIVE_DIR="/Users/2tamne/Library/CloudStorage/GoogleDrive-tam2504az@gmail.com/Drive của tôi/2tamne.site/download"

cp "release/2toolne Upscale Setup 1.0.1.exe" "$GOOGLE_DRIVE_DIR/"
cp "release/2toolne Upscale-1.0.1-arm64.dmg" "$GOOGLE_DRIVE_DIR/"
```
*Google Drive for Desktop sẽ tự động phát hiện và đồng bộ file lên máy chủ đám mây của Google.*

#### 🔹 Bước 3: Lấy Google Drive File ID của phiên bản mới
Có 2 cách để lấy File ID:
- **Cách 1 (Tự động bằng Terminal - Khuyên dùng):**
  Google Drive for Desktop tự động ghi ID vào thuộc tính mở rộng (Extended Attribute `xattr`) của file:
  ```bash
  xattr -p com.google.drivefs.item-id#S "$GOOGLE_DRIVE_DIR/2toolne Upscale Setup 1.0.1.exe"
  # Kết quả trả về trực tiếp chuỗi ID, ví dụ: 1OS1DdEGRoGmr1oaprHIfDOdE7uXgDpkE
  ```
- **Cách 2 (Giao diện Finder):**
  Mở Finder $\rightarrow$ tìm đến file trong `Google Drive > Drive của tôi > 2tamne.site > download` $\rightarrow$ Chuột phải $\rightarrow$ Chọn **Chia sẻ (Share)** $\rightarrow$ **Sao chép liên kết (Copy link)**.  
  Chuỗi ký tự nằm giữa `/d/` và `/view` chính là File ID.

#### 🔹 Bước 4: Cấu trúc Link Tải Trực Tiếp & Cập nhật `.htaccess` Web Server

##### Cú pháp Link Tải Trực Tiếp (Bypass Cảnh Báo Quét Virus):
Đối với file dung lượng lớn (> 100 MB như file `.exe` 149 MB và `.dmg` 125 MB), Google Drive sẽ chặn bằng trang xác nhận quét virus nếu dùng link thông thường.  
**Cú pháp chuẩn bắt buộc để tải thẳng 100% không bị chặn:**
```
https://drive.usercontent.google.com/download?id={FILE_ID}&export=download&confirm=t
```
*(Tham số `&confirm=t` là bắt buộc để tải thẳng không hiện popup).*

##### Cập nhật tập tin `/public_html/downloads/.htaccess` trên Hosting:
Mở file `.htaccess` trong thư mục downloads và cập nhật quy tắc chuyển hướng (RewriteRule):
```apache
RewriteEngine On

# Windows Setup Installer (.exe - 149 MB)
RewriteRule ^(2toolne_Upscale_Setup_latest\.exe|2toolne-upscale-setup-x64\.exe)$ "https://drive.usercontent.google.com/download?id={NEW_WIN_ID}&export=download&confirm=t" [R=302,L]

# macOS Apple Silicon Package (.dmg - 125 MB)
RewriteRule ^(2toolne_Upscale_latest\.dmg)$ "https://drive.usercontent.google.com/download?id={NEW_MAC_ID}&export=download&confirm=t" [R=302,L]
```

##### Quy tắc Xóa Cache Trình Duyệt (Cache Busting Parameter `?v=X.X.X`):
Khi người dùng tải file, trình duyệt (Chrome, Cốc Cốc, Edge) thường lưu cache URL tải. Để ép buộc tất cả khách hàng phải tải bản mới nhất (không bị nhận lại file cũ trong cache), **mọi liên kết trên web bắt buộc phải gắn tham số phiên bản**:
- Link Windows: `https://www.2tamne.site/downloads/2toolne_Upscale_Setup_latest.exe?v=1.0.1`
- Link macOS: `https://www.2tamne.site/downloads/2toolne_Upscale_latest.dmg?v=1.0.1`

---

## 📋 5. CHECKLIST KIỂM THỬ DÀNH CHO DESKTOP APP AGENT

Trước khi phát hành phiên bản Desktop mới, Agent quản lý app cần tự kiểm tra qua các bước sau:

- [ ] **1. Đăng ký & Kích hoạt:** Đăng ký tài khoản mới $\rightarrow$ Kiểm tra nhận đủ 50 Tokens trải nghiệm $\rightarrow$ Kích hoạt thiết bị thành công.
- [ ] **2. Ký số HMAC Offline:** Tắt kết nối WiFi $\rightarrow$ Kiểm tra xem Desktop App có duy trì quyền upscale trong vòng 72 giờ bằng chữ ký HMAC-SHA256 offline hay không.
- [ ] **3. Vòng đời Token:** 
  - Tạo batch 5 ảnh 4K $\rightarrow$ Gọi `reserve` (giữ 10 tokens).
  - Xuất thành công ảnh 1 $\rightarrow$ Gọi `commit` kèm `idempotency_key` $\rightarrow$ Thử gọi lại `commit` cùng key xem server có chặn trừ lần 2 hay không.
  - Hủy giữa chừng ở ảnh 3 $\rightarrow$ Gọi `release` $\rightarrow$ Kiểm tra số dư khả dụng được hoàn trả chính xác.
- [ ] **4. Gói Unlimited Studio:** Kích hoạt tài khoản Studio $\rightarrow$ Xác nhận app nhận diện `credit_mode = 'UNLIMITED'` và cho phép render tự do không trừ token.
- [ ] **5. Quy trình Lưu File Google Drive:** Build file $\rightarrow$ Copy vào thư mục Google Drive cục bộ $\rightarrow$ Lấy ID qua `xattr` $\rightarrow$ Cập nhật `.htaccess` $\rightarrow$ Kiểm tra curl trả về `HTTP 302` và `HTTP 200` với đầy đủ `content-length`.
- [ ] **6. Tuyệt đối không sinh trang web mới:** Bất kỳ nhu cầu thay đổi giao diện nào trên web phải bàn giao cho Web Agent để tích hợp trực tiếp vào `index.php` hoặc `license_admin.php`.
