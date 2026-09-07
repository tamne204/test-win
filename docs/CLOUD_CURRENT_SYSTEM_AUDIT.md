# 2TOOLNE CLOUD — BÁO CÁO KIỂM TOÁN HỆ THỐNG HIỆN TẠI
**Tài liệu:** `docs/CLOUD_CURRENT_SYSTEM_AUDIT.md`  
**Phiên bản:** `1.0.0-AUDIT`  
**Ngày thực hiện:** 05/09/2026  
**Phạm vi:** Kiểm toán mã nguồn cục bộ, cấu hình máy chủ hosting `2tamne.site`, cơ sở dữ liệu MySQL/MariaDB, cơ sở dữ liệu SQLite cục bộ, và hệ sinh thái ứng dụng Desktop 2TOOLNE.

---

## 1. TỔNG QUAN ĐỊNH DANH HỆ THỐNG (SYSTEM INVENTORY)

| Tham Số Kiểm Toán | Giá Trị Xác Minh Thực Tế | Trạng Thái Kiểm Tra |
| :--- | :--- | :---: |
| **`REPO_ROOT`** | `/Users/2tamne/tool ffmpeg` (Chứa Slideshow Studio, Web Portal, API v1, Admin Panel) & Không gian làm việc phụ `/Users/2tamne/Documents/toolupscale` (Chứa Desktop App 2toolne Upscale). | **EXISTS** |
| **`DESKTOP_APP_STACK`** | • **App 1 (2toolne Upscale):** Electron 32+, React 18, Vite 5, TypeScript 5, Tailwind CSS, `better-sqlite3`, Node.js EventEmitter Queue.<br>• **App 2 (Slideshow Studio):** Python 3.9/3.12, Flask, Pillow, FFmpeg (NVENC/VideoToolbox), Faster-Whisper, Gemini Flash API. | **EXISTS** |
| **`WEBSITE_STACK`** | PHP 7.4.33, Vanilla JS (ES6+), CSS3 Module (Raycast / Linear Dark Theme trong `globals.css`), Apache / LiteSpeed Web Server trên DirectAdmin hosting `2tamne.site`. | **EXISTS** |
| **`BACKEND_STACK`** | PHP 7.4.33 RESTful API tại `/api/v1/*` (Lightweight Router hỗ trợ regex path parameters, Controller MVC, PDO MySQL Database Singleton). | **EXISTS** |
| **`DATABASE_ENGINE`** | • **Máy chủ Web:** MySQL `5.7.41-cll-lve` (CloudLinux), Database: `ecxaebka_bot`, Charset: `utf8mb4_unicode_ci`.<br>• **Máy khách Desktop:** SQLite 3 (Chế độ WAL `journal_mode = WAL`, `foreign_keys = ON`). | **EXISTS** |
| **`AUTH_SYSTEM`** | • **Server:** Bảng `users`, mã hóa mật khẩu `password_hash` (BCrypt), JWT/HMAC token signing (`JWT_AUTH_SECRET`), bảng `app_auth_sessions` hỗ trợ Browser-to-Desktop SSO Login.<br>• **Desktop:** Bảng `account_cache` trong SQLite lưu token phiên và thông tin bản quyền đã ký số. | **EXISTS** |
| **`LICENSE_SYSTEM`** | • Bảng `license_entitlements` (các gói `BASIC`, `PRO`, `STUDIO`), chế độ `credit_mode` (`METERED`, `UNLIMITED`).<br>• Bảng `devices` lưu trữ HWID đa tín hiệu (Registry GUID + Motherboard UUID + MAC + CPU) và giới hạn số máy.<br>• Chữ ký bản quyền số HMAC-SHA256 có hiệu lực ngoại tuyến 72 giờ (`OFFLINE_GRACE_HOURS`). | **EXISTS** |
| **`TOKEN_SYSTEM`** | Vòng đời 3 bước nghiêm ngặt (`credit_reservations`, `credit_transactions`, `credit_wallets`). Khóa dòng an toàn `FOR UPDATE`, bảo vệ chống trừ tiền kép bằng `idempotency_key`. | **EXISTS** |
| **`PROJECT_SYSTEM`** | • **Desktop (Upscale):** Bảng `projects` và `project_items` trong SQLite cục bộ (lưu `target_resolution`, `output_dir`, `status`, `completed_images`, v.v.).<br>• **Desktop (Slideshow):** Thư mục `projects/{project_id}/` (`project.json`, `images/`, `audio/`, `script/`, `subtitles/`, `export/`).<br>• **Server:** Hoàn toàn KHÔNG lưu thông tin chi tiết ảnh của người dùng (Zero-Knowledge Privacy). | **EXISTS** |
| **`PROJECT_QUEUE`** | Bảng `queue_entries` trong SQLite + `QueueManager` (Node.js EventEmitter trong `apps/desktop/src/main/queue_manager.ts`). Hỗ trợ khôi phục tiến trình sau sự cố (`recoverInterruptedJobs`). | **EXISTS** |
| **`ADMIN_PANEL`** | Trang quản trị tập trung `https://www.2tamne.site/license_admin.php`, phân quyền RBAC (`permissions`), theo dõi đơn hàng, ví token, nhật ký SePay IPN, và `admin_audit_logs`. | **EXISTS** |
| **`CURRENT_STORAGE_CODE`** | Chưa có hệ thống lưu trữ đám mây cho người dùng. Hiện chỉ có cơ chế phân phối bộ cài đặt ứng dụng qua Google Drive liên kết với `.htaccess` (HTTP 302 Redirect). | **MISSING** |
| **`CURRENT_GOOGLE_CODE`** | • Đã có mã gọi Google Gemini File API (`forced_alignment_engine.py`).<br>• Chưa có mã Google Drive API v3 (OAuth2 Client, Resumable Upload Session, Drive File Management). | **PARTIAL** |
| **`CURRENT_UPLOAD_SYSTEM`** | Chưa có hệ thống upload file lên đám mây cho người dùng. | **MISSING** |
| **`CURRENT_DOWNLOAD_SYSTEM`** | Phân phối file cài đặt qua link trực tiếp Google Drive với tham số `&confirm=t` bỏ qua quét virus. Chưa có hệ thống download file ảo hóa bảo mật cho user. | **PARTIAL** |
| **`CURRENT_DEPLOYMENT`** | Triển khai từ máy phát triển lên hosting `2tamne.site` qua FTP Passive Mode (Port 21, Thư mục `/public_html`). | **EXISTS** |
| **`CURRENT_HOSTING_CONSTRAINTS`** | Gói DirectAdmin hosting giới hạn dung lượng **khoảng 200 MB disk quota**, PHP 7.4.33, `max_execution_time` = 30s, `memory_limit` = 256MB, `upload_max_filesize` = 128MB. | **EXISTS** |
| **`CURRENT_API_STRUCTURE`** | Kiến trúc RESTful JSON tại `/api/v1/*` với bộ định tuyến `Router.php`, nhận `application/json`, kiểm soát mã lỗi HTTP chuẩn (200, 201, 400, 401, 403, 404, 409, 422, 500). | **EXISTS** |
| **`CURRENT_LOCAL_DATABASE`** | SQLite 3 (`database/local-sqlite/schema.sql`, `repository.ts`), 6 bảng (`projects`, `project_items`, `queue_entries`, `app_settings`, `account_cache`, `engine_runs`). | **EXISTS** |
| **`CURRENT_SERVER_DATABASE`** | MariaDB/MySQL 5.7.41 `ecxaebka_bot`, 13 bảng (`users`, `devices`, `license_entitlements`, `credit_wallets`, `credit_transactions`, `credit_reservations`, `token_packages`, `payments`, `admin_audit_logs`, `system_config`, `app_auth_sessions`, `licenses`, `orders`). | **EXISTS** |
| **`CURRENT_BACKGROUND_JOB_SYSTEM`** | • **Server:** `cron.php` chạy qua CLI (hạn chế). Đa số hoạt động theo cơ chế Client Polling (chu kỳ 8s) hoặc sự kiện Ajax.<br>• **Desktop:** In-process Queue Worker trên Node.js Main Process. | **PARTIAL** |
| **`CURRENT_FILE_METADATA_MODEL`** | Chưa có bảng hoặc mô hình dữ liệu lưu trữ metadata file đám mây của người dùng. | **MISSING** |
| **`CURRENT_SECURITY_MODEL`** | BCRYPT hash mật khẩu, chữ ký HMAC-SHA256 cho bản quyền, chống SQL Injection qua PDO Prepared Statements, lọc Path Traversal (`sanitize_project_name`), kiểm tra quyền sở hữu tài nguyên qua session. | **EXISTS** |

---

## 2. KIỂM TOÁN CHI TIẾT MÔI TRƯỜNG HOSTING THỰC TẾ (`2tamne.site`)

Qua chạy mã chẩn đoán thực tế trên môi trường máy chủ đang chạy tại `https://www.2tamne.site/`:

1. **Phiên bản PHP:**
   - Phiên bản chính thức: **`PHP 7.4.33`** (DirectAdmin CloudLinux).
   - *Lưu ý quan trọng:* Tuyệt đối không được sử dụng các cú pháp chỉ có từ PHP 8.0+ (như `match`, union types `int|string`, thuộc tính `readonly`, constructor property promotion). Phải sử dụng cú pháp tương thích hoàn toàn với PHP 7.4.
2. **Phiên bản MySQL:**
   - Phiên bản: **`MySQL 5.7.41-cll-lve`**.
   - Hỗ trợ đầy đủ: Kiểu dữ liệu `JSON`, khóa ngoại `FOREIGN KEY ... ON DELETE CASCADE`, giao dịch `InnoDB` với `SELECT ... FOR UPDATE`, chỉ mục `UNIQUE` và `INDEX`.
3. **Giới Hạn Thực Thi (PHP Runtime Limits):**
   - `max_execution_time`: **30 giây** (Không thể chạy tác vụ dài trên HTTP request).
   - `memory_limit`: **256 MB**.
   - `upload_max_filesize`: **128 MB**; `post_max_size`: **128 MB**.
4. **Các Tiện Ích PHP Mở Rộng:**
   - `curl`: **ĐÃ KÍCH HOẠT (true)**.
   - `openssl`: **ĐÃ KÍCH HOẠT (true)** (sẵn sàng cho AES-256-GCM và HTTPS calls).
   - `pdo_mysql`: **ĐÃ KÍCH HOẠT (true)**.
   - `outbound_https`: **ĐÃ KÍCH HOẠT (true)** (kết nối trực tiếp tới `googleapis.com` thành công 100%).
5. **Ràng Buộc Dung Lượng Đĩa Máy Chủ Web (Crucial Hosting Constraint):**
   - Hạn mức ổ cứng tài khoản hosting DirectAdmin (`ecxaebka`): **Xấp xỉ 200 MB**.
   - Thư mục web hiện đã chiếm khoảng 45 - 60 MB cho mã nguồn web, styles, hình ảnh giao diện, font chữ và các thư viện PHP/JS.
   - **Hệ quả kiến trúc bắt buộc:** Máy chủ hosting **tuyệt đối không được lưu trữ bất kỳ file nhị phân (binary files) nào của khách hàng**. Không tạo thư mục tạm đa gigabyte (staging/cache). Mọi tệp tin dữ liệu của khách hàng bắt buộc phải chuyển trực tiếp vào Google Drive thông qua giao thức Resumable Upload Session.

---

## 3. KIỂM TOÁN HỆ THỐNG DESKTOP VÀ KHẢ NĂNG TÍCH HỢP

### 3.1. Ứng Dụng 2toolne Upscale (`/Users/2tamne/Documents/toolupscale/`)
- **Kiến trúc:** Electron + Vite + React 18 (TypeScript).
- **Tiến trình chính (Main Process):**
  - Quản lý hàng đợi: `apps/desktop/src/main/queue_manager.ts`. Hàng đợi hiện tại chỉ xử lý tác vụ Upscale mô hình AI trên GPU nội bộ.
  - Giao tiếp máy chủ: `apps/desktop/src/main/license_client.ts` giao tiếp qua REST API `/api/v1/*` bằng `axios`/`fetch`.
  - Cơ sở dữ liệu nội bộ: `better-sqlite3` quản lý các bảng dự án và hàng đợi.
- **Tiến trình giao diện (Renderer Process):**
  - Giao diện Dark theme được cấu hình sẵn các Views: `HomeView`, `QueueView`, `ProjectsView`, `HistoryView`, `SettingsView`, `AccountView`.
  - Có thể mở rộng thêm view mới `CloudView` hoặc tích hợp chức năng "Cloud Backup" vào `ProjectsView` / `SettingsView`.

### 3.2. Ứng Dụng Slideshow Studio (`/Users/2tamne/tool ffmpeg/`)
- **Kiến trúc:** Python Flask Server cục bộ chạy trên cổng `localhost:8080`.
- **Cơ chế lưu trữ dự án:** Thư mục `projects/{project_id}/` chứa các thư mục con tiêu chuẩn: `images/`, `audio/`, `script/`, `subtitles/`, `export/`.
- **Tích hợp đám mây tương lai:** Sau khi render hoàn tất file video tại `export/{project_id}_1080p.mp4`, ứng dụng có thể kích hoạt dịch vụ nền để tải bản sao lưu lên 2toolne Cloud.

---

## 4. KIỂM TOÁN BẢNG DỮ LIỆU CÓ SẴN CÓ THỂ TÁI SỬ DỤNG

Hệ thống cơ sở dữ liệu `ecxaebka_bot` đã có sẵn các cấu trúc nền tảng rất vững chắc:
1. **`users`**: Quản lý định danh tài khoản, email, mật khẩu băm, vai trò `user`/`admin`.
2. **`license_entitlements`**: Quản lý gói cước (`BASIC`, `PRO`, `STUDIO`), thời hạn hết hạn. Đây là nơi lý tưởng để gắn thuộc tính hạn mức lưu trữ đám mây (`cloud_quota_bytes`).
3. **`credit_wallets` & `credit_transactions`**: Quản lý ví token và lịch sử giao dịch bất biến. Sẵn sàng cho việc bổ sung mua thêm dung lượng đám mây (Storage Add-ons).
4. **`devices`**: Quản lý định danh phần cứng máy tính và phiên làm việc của người dùng.
5. **`admin_audit_logs`**: Sổ cái nhật ký kiểm toán hành động quản trị, sẵn sàng ghi nhận các thao tác liên quan đến Storage Pool (thêm tài khoản Drive, ngắt kết nối, chuyển đổi trạng thái, điều chỉnh hạn mức).
6. **`system_config`**: Lưu cấu hình toàn cục dạng key-value, sẵn sàng lưu các tham số vận hành như `STORAGE_SAFETY_PERCENT`, `TRASH_RETENTION_DAYS`, `DEFAULT_CLOUD_QUOTA_PRO`.

---

## 5. KẾT LUẬN KIỂM TOÁN (AUDIT VERDICT)
- **Tình trạng hệ thống hiện tại:** Hệ sinh thái đang vận hành ổn định trên cả môi trường web và máy khách desktop.
- **Tính khả thi của 2TOOLNE Cloud:** Hoàn toàn khả thi về mặt kỹ thuật nếu tuân thủ nghiêm ngặt nguyên tắc **Zero-Host-Storage (Tệp tin không đi qua đĩa của máy chủ web)** và kiến trúc trừu tượng hóa nhà cung cấp **StorageAdapter**.
