# 2TOOLNE CLOUD — MULTI-ACCOUNT GOOGLE DRIVE CLOUD STORAGE
## INTEGRATION MASTER PLAN & ARCHITECTURAL BLUEPRINT V2
**Mã tài liệu:** `2TOOLNE_CLOUD_INTEGRATION_MASTER_PLAN.md`  
**Trạng thái phê duyệt:** `PLANNING / ARCHITECTURE ONLY` (Không có mã nguồn thực thi hay migration database nào được chạy trong giai đoạn này)  
**Phiên bản kế hoạch:** `2.0.0-MASTER`  
**Cập nhật:** V2 Architecture Update (Cloud Space Domain Model, Shared Team Storage, Multi-Account Pool Operations, Safe Draining & Disconnect Guard, Admin Quota Adjustments Ledger)  
**Ngày hoàn thành:** 05/09/2026  
**Tác giả:** Antigravity AI — Lead Systems Architect & Infrastructure Engineer

---

## 💎 BÁO CÁO TỔNG QUAN DÀNH CHO BAN QUẢN LÝ (FINAL MANAGEMENT SUMMARY V2)

### 1. Chúng ta đã có những gì? (What do we already have?)
- **Hệ thống xác thực & tài khoản hoàn chỉnh:** Bảng `users`, mã hóa BCrypt, chữ ký JWT/HMAC, SSO đăng nhập nhanh từ Web sang Desktop (`app_auth_sessions`).
- **Hệ thống bản quyền & thiết bị:** Bảng `license_entitlements` (Gói `BASIC`, `PRO`, `STUDIO`), theo dõi định danh phần cứng HWID (`devices`), chữ ký HMAC-SHA256 ngoại tuyến 72 giờ.
- **Hệ thống ví Token & Giao dịch:** Vòng đời token 3 bước nguyên tử (`credit_reservations`, `credit_transactions`, `credit_wallets`) bảo vệ chống trừ tiền kép bằng `idempotency_key`.
- **Ứng dụng Desktop chuyên nghiệp (2toolne Upscale):** Electron + React 18 + Vite + TypeScript, cơ sở dữ liệu SQLite cục bộ (`better-sqlite3`), hàng đợi xử lý GPU (`QueueManager`) có khả năng tự khôi phục sau sự cố.
- **Cổng Web & Quản trị Hợp Nhất (Unified Portal):** Cổng khách hàng `index.php` và Cổng quản trị `license_admin.php` với phân quyền RBAC thời gian thực, polling đồng bộ dữ liệu sau 8 giây.
- **RESTful API Backend chuẩn mực:** Nền tảng PHP REST API tại `/api/v1/*` với bộ định tuyến `Router.php` hỗ trợ regex path parameters và kiến trúc Controller sạch sẽ.

### 2. Những gì bắt buộc phải bổ sung mới trong Kiến Trúc V2? (What must be added in V2?)
- **Mô hình Miền Không Gian Lưu Trữ (Cloud Space Domain Model):** Bảng `cloud_spaces` và `cloud_space_quotas`. Tách rời quyền sở hữu dung lượng khỏi người dùng cá nhân để hỗ trợ đồng thời Không gian Cá nhân (`PERSONAL`) và Không gian Dùng chung Đội nhóm (`TEAM`).
- **Sổ cái điều chỉnh hạn mức Quản trị viên (Admin Quota Adjustments Ledger):** Bảng `cloud_quota_adjustments`. Cho phép Quản trị viên tăng/giảm dung lượng với trường Lý do bắt buộc, bảo đảm truy vết 100%.
- **Hệ thống Quản trị Cụm Ổ đĩa Vận Hành (Storage Pool Operations Dashboard):** Theo dõi 9 chỉ số vận hành cốt lõi, đặc biệt là `OVERCOMMIT_RATIO` và `POOL_ALLOCATABLE_FREE`.
- **Cơ chế Khóa Ngắt Kết Nối An Toàn (Safe Disconnect Guard):** Chặn tuyệt đối việc ngắt/xóa tài khoản Google Drive vật lý nếu vẫn còn tệp tin hoạt động (`active_file_count > 0`).
- **Vòng đời Rút cạn Tài khoản (Safe Draining Lifecycle):** `ACTIVE` $\rightarrow$ `DRAINING` $\rightarrow$ `EMPTY / MIGRATED` $\rightarrow$ `DISCONNECTED` kết hợp tiến trình di chuyển tệp nền.
- **Nền tảng Gói Đội Nhóm sẵn sàng (Team Plans Backend Ready):** Bảng `teams` và `team_members`, cấu trúc gói Team Starter (2 slots / 50GB / 2 app keys), ẩn phía giao diện khách hàng thông qua cờ cấu hình `TEAM_PLANS_ENABLED = false`.
- **Bộ chuyển đổi Không gian trên Giao diện (Space Switcher Dropdown):** Tích hợp trên Web File Manager và Desktop App.
- **Cơ chế Ứng phó Vượt Hạn Mức Êm Dịu (Over-Quota Graceful Handling):** Chuyển sang chế độ Read-only cho tệp cũ (tải xuống và xóa bình thường, tạm dừng upload, không xóa tệp của khách).

### 3. Những phần hiện có nào cần phải thay đổi? (What existing parts must change?)
- **`website/storage/db.php`:** Bổ sung các hàm helper thao tác bảng Cloud Space, Quota Adjustments, Storage Pool.
- **`website/api/v1/index.php`:** Đăng ký các tuyến đường RESTful có tiền tố `/api/v1/cloud/spaces/{spaceId}/*` và `/api/v1/cloud/admin/*`.
- **`website/index.php`:** Bổ sung Tab `#tab-cloud-storage` có bộ chuyển đổi Space Switcher, Shared Team View và Banner cảnh báo Over-Quota.
- **`website/license_admin.php`:** Bổ sung Tab "Quản Lý Storage Pool" và Tab "Điều Chỉnh Dung Lượng Khách Hàng".
- **`apps/desktop/src/main/queue_manager.ts`:** Thêm hook phát sự kiện sau khi project hoàn tất để chuyển sang `CloudBackupQueue`.
- **`database/local-sqlite/schema.sql`:** Bổ sung bảng `cloud_backup_jobs` có trường `cloud_space_id`.

### 4. Những phần nào được giữ nguyên vẹn 100%? (What can remain untouched?)
- **Toàn bộ tiến trình xử lý AI GPU:** Module Upscale, Real-ESRGAN, Vulkan / DirectML, FFmpeg Render Engine giữ nguyên 100%. Khi Cloud bị Over-quota, tiến trình GPU vẫn hoàn thành bình thường.
- **Toàn bộ hệ thống Ví Token:** Quy trình nạp tiền, bảng `credit_wallets`, `credit_transactions`, SePay IPN không bị thay đổi.
- **Toàn bộ hệ thống Bản quyền Thiết bị & HWID:** Bảng `devices` và `license_entitlements` giữ nguyên cấu trúc. Mỗi ghế Team sử dụng 1 slot thiết bị trong `devices` mà không cần migration (`MIGRATION_REQUIRED = NO`).

### 5. Kiến trúc được đề xuất là gì? (What is the recommended architecture?)
- **Mô hình Tách Rời Tuyệt Đối & Ảo Hóa Ba Lớp (Three-Layer Decoupled Virtual Architecture):**
  1. **Lớp Miền Khách Hàng (Domain Layer):** `User` $\rightarrow$ Thành viên `Team` $\rightarrow$ Quyền truy cập vào `Cloud Space`. Hạn mức và tệp tin thuộc về Không gian lưu trữ.
  2. **Lớp Điều Khiển & Phân Quyền (Control & Security Layer):** 2TOOLNE REST API tại `2tamne.site` chịu trách nhiệm xác thực, phân quyền, kiểm tra hạn mức hiệu lực nguyên tử (`SELECT ... FOR UPDATE`), quản trị sổ cái và cấp phát tài khoản vật lý.
  3. **Lớp Dữ Liệu Vật Lý Trực Tiếp (Physical Storage Layer):** Cụm nhiều tài khoản Google Drive vật lý. Dữ liệu nhị phân truyền trực tiếp 100% giữa Client và Google Drive qua Resumable Session URL. **0 bytes đi qua đĩa máy chủ hosting.**

### 6. Những rủi ro kỹ thuật lớn nhất là gì? (What are the biggest technical risks?)
1. **Rủi ro ngắt tài khoản khi còn tệp (Data Loss via Premature Disconnect):** Giải pháp: Cơ chế Safe Disconnect Guard chặn tuyệt đối ở tầng API nếu `active_file_count > 0`.
2. **Rủi ro cam kết vượt mất kiểm soát (Overcommit Runaway):** Giải pháp: Giám sát chỉ số `OVERCOMMIT_RATIO` và `POOL_ALLOCATABLE_FREE` với cảnh báo trực quan đa cấp độ trên Admin Dashboard.
3. **Lộ Refresh Token của tài khoản Google Drive:** Giải pháp: Mã hóa cứng bằng AES-256-GCM, khóa chủ nằm ngoài cơ sở dữ liệu (`0600`), lọc secret trong mọi tầng log.
4. **Tràn dung lượng máy chủ hosting 200MB do vô tình tạo file tạm:** Giải pháp: Cấm tuyệt đối đệm file lên đĩa hosting, 100% upload/download sử dụng Direct Resumable URL và Stream Pipe.

### 7. Hệ thống này có thể vận hành trên hosting ~200MB hiện tại không? (Can this run with the current ~200MB hosting?)
- **CÓ, HOÀN TOÀN CÓ THỂ VẬN HÀNH 100% ỔN ĐỊNH.**
- Toàn bộ mã nguồn PHP mới cho Cloud API, Virtual File System và bộ điều hợp chỉ chiếm khoảng **1.8 MB – 2.2 MB** dung lượng ổ đĩa. Siêu dữ liệu (metadata) cơ sở dữ liệu cho 100.000 tệp tin chỉ chiếm ~25 MB trong MySQL. Dữ liệu tệp nhị phân thực tế đều được lưu trên Google Drive của operator.

### 8. Những phần nào về sau có thể cần đến VPS hoặc Cổng trung chuyển riêng? (What parts may eventually require a VPS or alternate gateway?)
- Khi nhu cầu của người dùng chuyển sang **xem trực tuyến (streaming video player) các tệp video MP4 dung lượng lớn (1GB – 10GB+) trên trình duyệt Web** hoặc tải xuống liên tục tệp nặng trên đường truyền mạng rất chậm, giới hạn `max_execution_time = 30s` của shared hosting sẽ trở thành rào cản. Khi đó, một VPS giá rẻ ($3–$5/tháng) hoặc Cloudflare Worker đóng vai trò **Storage Download Gateway** sẽ được bổ sung để truyền phát dữ liệu không giới hạn thời gian.

### 9. Thứ tự triển khai chuẩn xác là gì? (What is the correct implementation order?)
- Tuân thủ nghiêm ngặt **15 Giai đoạn (Phase 0 đến Phase 15)** theo tài liệu `docs/CLOUD_IMPLEMENTATION_ROADMAP.md`, bắt đầu từ nền tảng Cloud Space / Team V2 Schema.

### 10. Agent lập trình cần thực hiện việc gì ĐẦU TIÊN sau khi được phê duyệt? (What should the coding agent implement FIRST after approval?)
- **Triển khai Giai đoạn 1 (Phase 1):** Thực thi kịch bản tạo các bảng dữ liệu V2 trên cơ sở dữ liệu MySQL `ecxaebka_bot` (`teams`, `team_members`, `cloud_spaces`, `cloud_space_quotas`, `cloud_quota_adjustments`, `storage_accounts`, `cloud_folders`, `cloud_files`, `cloud_upload_reservations`, `cloud_trash`, `cloud_storage_health`) và tạo bản ghi Personal Space ban đầu cho người dùng hiện hữu.

---

## 🔄 BẢNG SO SÁNH BIẾN ĐỘNG KIẾN TRÚC V1 VS V2 (V1 VS V2 ARCHITECTURAL DELTA)

| Tiêu Chí Kiến Trúc | Kế Hoạch V1 (Ban Đầu) | Kế Hoạch V2 (Hiện Tại) | Lý Do & Lợi Ích Của Thay Đổi |
| :--- | :--- | :--- | :--- |
| **Thực thể sở hữu dung lượng** | Thuộc trực tiếp về `User` (`cloud_user_quotas`). | Thuộc về **`Cloud Space`** (`cloud_spaces` + `cloud_space_quotas`). | Cho phép mở rộng mượt mà sang Team Space dùng chung mà không phải đập bỏ cấu trúc cũ. |
| **Mô hình Đội nhóm (Team Model)** | Không hỗ trợ; mỗi tài khoản hoạt động cô lập. | Hỗ trợ mô hình `teams`, `team_members`, gói Team Starter (2 slots / 50GB / 2 app keys). | Chuẩn bị sẵn hạ tầng cho phân khúc khách hàng doanh nghiệp / studio ảnh cưới. |
| **Cờ kiểm soát tính năng Team** | Không có. | `define('TEAM_PLANS_ENABLED', false);` | Ẩn giao diện mua gói Team phía khách hàng cho đến khi có quyết định thương mại hóa chính thức. |
| **Ánh xạ Bản quyền Desktop** | Chưa định nghĩa cho đội nhóm. | Ánh xạ 1 App Key Team = 1 Thiết bị (`devices`) thuộc bản quyền Team (`MIGRATION_REQUIRED = NO`). | Tái sử dụng 100% bảng `devices` và `license_entitlements` hiện có, không rủi ro hồi quy. |
| **Điều chỉnh dung lượng Admin** | Ghi đè trực tiếp vào cột hạn mức gói cước. | **Sổ cái bất biến (`cloud_quota_adjustments`)** kèm trường **Lý do bắt buộc**. | Truy vết minh bạch mọi quyết định cộng/trừ dung lượng, chống thất thoát và nhầm lẫn. |
| **Cách tính hạn mức hiệu lực** | Cố định theo gói: `base_quota`. | $\text{effective\_quota} = \text{base\_plan\_quota} + \sum \text{delta\_bytes}$. | Cho phép cộng/trừ linh hoạt dung lượng mà không làm mất thông tin gói cước gốc. |
| **Xử lý khi bị Over-Quota** | Chặn toàn bộ tác vụ. | **Chế độ Ứng phó Êm dịu:** Đọc/Tải/Xóa bình thường, tạm dừng upload mới, không xóa tệp cũ. | Trải nghiệm khách hàng chuyên nghiệp, không gây mất dữ liệu đột ngột của người dùng. |
| **Vòng đời ngắt tài khoản Drive** | Admin bấm xóa trực tiếp tài khoản. | **Vòng đời 4 bước có bảo vệ:** `ACTIVE` $\rightarrow$ `DRAINING` $\rightarrow$ `EMPTY` $\rightarrow$ `DISCONNECTED`. | Chặn tuyệt đối nguy cơ xóa nhầm ổ đĩa đang chứa dữ liệu sống của khách hàng. |
| **Chốt chặn ngắt kết nối (Guard)**| Không có. | **Safe Disconnect Guard:** Chặn ngắt kết nối nếu `active_file_count > 0`. | Bảo vệ an toàn dữ liệu tuyệt đối ở cấp độ API backend. |
| **Giám sát Cam kết vượt** | Không theo dõi Overcommit. | Theo dõi 9 chỉ số vận hành & `OVERCOMMIT_RATIO` trên Dashboard Admin. | Kiểm soát an toàn tài chính và sức chứa vật lý của hệ sinh thái lưu trữ. |

---

## 🗄️ BẢNG CHI TIẾT BIẾN ĐỘNG CƠ SỞ DỮ LIỆU (DATABASE SCHEMA DELTA TABLE)

| Tên Bảng | Trạng Thái V2 | Thay Đổi Cụ Thể So Với V1 |
| :--- | :---: | :--- |
| **`teams`** | **MỚI (NEW)** | Bảng quản lý thông tin nhóm làm việc (`id`, `name`, `owner_user_id`, `created_at`). |
| **`team_members`** | **MỚI (NEW)** | Bảng quản lý thành viên nhóm (`team_id`, `user_id`, `role`, `joined_at`). |
| **`cloud_spaces`** | **MỚI (NEW)** | Bảng gốc định danh Không gian lưu trữ (`id`, `space_type` ['PERSONAL', 'TEAM'], `owner_user_id`, `team_id`, `name`). |
| **`cloud_space_quotas`** | **MỚI (NEW)** | **Thay thế bảng `cloud_user_quotas` của V1**. Quản lý hạn mức theo Space (`cloud_space_id`, `base_plan_quota_bytes`, `used_bytes`, `reserved_bytes`). |
| **`cloud_quota_adjustments`**| **MỚI (NEW)** | Sổ cái bất biến ghi nhận mọi thay đổi hạn mức do Admin thực hiện (`cloud_space_id`, `admin_user_id`, `delta_bytes`, `reason`, `created_at`). |
| **`cloud_user_quotas`** | **LOẠI BỎ (REMOVED)**| Bị thay thế hoàn toàn bởi `cloud_space_quotas` để tách rời dung lượng khỏi cá nhân. |
| **`cloud_files`** | **CHỈNH SỬA (MODIFIED)**| Bổ sung cột `cloud_space_id`, đổi `user_id` thành `created_by_user_id` để biết ai trong team tải lên. |
| **`cloud_folders`** | **CHỈNH SỬA (MODIFIED)**| Bổ sung cột `cloud_space_id`, đổi `user_id` thành `created_by_user_id`. |
| **`cloud_upload_reservations`**| **CHỈNH SỬA (MODIFIED)**| Bổ sung cột `cloud_space_id` để khóa tạm hạn mức chính xác theo Không gian lưu trữ. |
| **`cloud_trash`** | **CHỈNH SỬA (MODIFIED)**| Bổ sung cột `cloud_space_id` để phân vùng thùng rác theo Không gian lưu trữ. |
| **`storage_accounts`** | **CHỈNH SỬA (MODIFIED)**| Bổ sung trạng thái `DISCONNECTED` vào ENUM vòng đời tài khoản. |
| **`cloud_storage_health`** | **GIỮ NGUYÊN (RETAINED)**| Giữ nguyên cấu trúc theo dõi nhịp tim và lỗi API Google Drive theo từng tài khoản. |
| **`cloud_backup_jobs`** *(SQLite)*| **CHỈNH SỬA (MODIFIED)**| Bổ sung trường `cloud_space_id` để xác định chính xác Không gian sao lưu đích trên Desktop. |

---

## 1. TÓM TẮT TRẠNG THÁI HIỆN TẠI (CURRENT_STATE_SUMMARY)

Hệ thống 2TOOLNE hiện đang vận hành ở trạng thái ổn định cao trên 2 nền tảng:
1. **Nền tảng Web (`2tamne.site`):** Chạy trên DirectAdmin CloudLinux (PHP 7.4.33, MySQL 5.7.41), phục vụ cổng thông tin khách hàng, cổng quản trị bản quyền, API RESTful và hệ thống ví token.
2. **Nền tảng Desktop (2toolne Upscale & Slideshow Studio):** Hoạt động mượt mà với khả năng xử lý AI cục bộ, xác thực HWID, ký số bản quyền 72 giờ và hàng đợi render GPU.
3. **Hiện trạng lưu trữ:** Chưa có hệ thống lưu trữ đám mây cho khách hàng. Hệ thống hiện chỉ sử dụng Google Drive làm kho phân phối bộ cài đặt phần mềm thông qua chuyển hướng URL công khai `.htaccess`.

---

## 2. THÀNH PHẦN HIỆN CÓ TÁI SỬ DỤNG ĐƯỢC (EXISTING_REUSABLE_COMPONENTS)

- **`users` (MySQL):** Quản lý tài khoản, định danh `user_id`.
- **`license_entitlements` (MySQL):** Quản lý phân hạng gói dịch vụ để gắn hạn mức lưu trữ cơ sở (`base_plan_quota`).
- **`devices` (MySQL):** Định danh phần cứng gửi yêu cầu tải lên từ Desktop và kiểm soát số lượng ghế kích hoạt (App Keys) cho gói Team.
- **`credit_wallets` & `credit_transactions` (MySQL):** Tái sử dụng để phát hành các gói mua thêm dung lượng lưu trữ (Storage Add-ons) trong tương lai.
- **`admin_audit_logs` (MySQL):** Ghi nhật ký kiểm toán hành động quản trị Storage Pool và điều chỉnh hạn mức.
- **`website/api/v1/Router.php` & `Database.php`:** Nền tảng định tuyến RESTful API và kết nối PDO Singleton.
- **`website/globals.css`:** Hệ thống thiết kế Dark Mode cao cấp (Raycast / Linear) cho Web File Manager.
- **`database/local-sqlite/repository.ts`:** Nền tảng truy vấn SQLite cục bộ trên Desktop App.

---

## 3. THÀNH PHẦN CÒN THIẾU CẦN XÂY DỰNG MỚI (MISSING_COMPONENTS V2)

- **Lớp trừu tượng hóa nhà cung cấp:** `StorageProviderInterface.php`.
- **Bộ mã hóa bảo vệ bí mật:** `CryptoService.php` (AES-256-GCM).
- **Bộ điều hợp Google Drive:** `GoogleDriveStorageAdapter.php`.
- **Các bảng MySQL mới theo chuẩn V2:** `teams`, `team_members`, `cloud_spaces`, `cloud_space_quotas`, `cloud_quota_adjustments`, `storage_accounts`, `cloud_folders`, `cloud_files`, `cloud_upload_reservations`, `cloud_trash`, `cloud_storage_health`.
- **Các Controller API mới:** `CloudFilesController.php`, `CloudUploadsController.php`, `CloudAdminController.php`, `CloudQuotaController.php`.
- **Trình quản trị hạn mức & cấp phát V2:** `CloudQuotaManager.php`, `StorageAllocator.php`.
- **Giao diện Web File Manager V2:** Tab `#tab-cloud-storage` trong `index.php` với Space Switcher và Over-Quota Banner.
- **Giao diện Admin Storage Pool & Quota Ledger:** Trong `website/license_admin.php`.
- **Hàng đợi sao lưu Desktop V2:** `apps/desktop/src/main/cloud_backup_queue.ts` hỗ trợ `cloud_space_id`.

---

## 4. RỦI RO KIẾN TRÚC HIỆN TẠI VÀ BIỆN PHÁP KHẮC PHỤC (ARCHITECTURAL RISKS & MITIGATIONS)

- **Rủi ro 1: Tràn đĩa hosting do đệm tệp (Host Disk Exhaustion):** Gói hosting chỉ có ~200MB. Khắc phục: Cấm tuyệt đối đệm file ra đĩa hosting; 100% upload/download sử dụng Direct Resumable URL và Stream Pipe.
- **Rủi ro 2: Ngắt kết nối do quá thời gian thực thi (PHP 30s Execution Timeout):** Khắc phục: Tải lên đi trực tiếp từ client lên Google; tải xuống hỗ trợ Range Header và chia khối nhỏ.
- **Rủi ro 3: Xung đột phiên bản PHP:** Máy chủ chạy `PHP 7.4.33`. Khắc phục: Tuân thủ nghiêm ngặt cú pháp PHP 7.4 (không dùng `str_contains`, typed properties có khởi tạo, match expression của PHP 8).
- **Rủi ro 4: Ngắt nhầm tài khoản Google Drive còn chứa dữ liệu:** Khắc phục: Quy tắc Safe Disconnect Guard chặn tuyệt đối ở tầng API nếu `active_file_count > 0`.
- **Rủi ro 5: Rủi ro tràn đĩa vật lý do bán vượt dung lượng (Overcommit Runaway):** Khắc phục: Bảng theo dõi chỉ số `OVERCOMMIT_RATIO` và `POOL_ALLOCATABLE_FREE` trên Admin Dashboard với cảnh báo màu sắc đa cấp độ.

---

## 5. BẢNG PHÂN TÍCH KHOẢNG TRỐNG TÍNH NĂNG V2 (GAP ANALYSIS TABLE V2)

| Năng Lực Hệ Thống (Capability) | Hiện Trạng (Current) | Mục Tiêu V2 (Target) | Khoảng Trống (Gap) | Thay Đổi Bắt Buộc (Required Change) | Ưu Tiên |
| :--- | :---: | :---: | :---: | :--- | :---: |
| **Xác thực (Auth)** | EXISTS | Đa Không gian | Không có | Tái sử dụng token JWT và session hiện có | P0 |
| **Người dùng (Users)** | EXISTS | Đa Không gian | Không có | Liên kết khóa ngoại `user_id` | P0 |
| **Không gian lưu trữ (Spaces)** | MISSING | Personal & Team | Chưa có | Tạo bảng `cloud_spaces` và phân quyền Space | P0 |
| **Hạn mức Không gian (Quotas)** | MISSING | Theo Không gian | Chưa có | Tạo bảng `cloud_space_quotas` | P0 |
| **Sổ cái điều chỉnh Admin** | MISSING | Sổ cái bất biến | Chưa có | Tạo bảng `cloud_quota_adjustments` có lý do bắt buộc | P1 |
| **Bản quyền Team (Team Licenses)**| EXISTS | Dùng chung ghế | Cần cấu hình | Ánh xạ App Key vào bảng `devices` (`MIGRATION_REQUIRED = NO`) | P1 |
| **Cờ tính năng Team (Flag)** | MISSING | Ẩn phía khách | Chưa có | Thiết lập `TEAM_PLANS_ENABLED = false` | P1 |
| **Google OAuth (Operator)** | MISSING | Kết nối Drive Admin | Chưa có | Xây dựng luồng OAuth Server-to-Server lưu token | P0 |
| **Bộ chuyển đổi (Storage Adapter)**| MISSING | Đa nhà cung cấp | Chưa có | Xây dựng `StorageProviderInterface` & GoogleDriveAdapter | P0 |
| **Bể tài khoản (Storage Accounts)**| MISSING | Quản trị an toàn | Chưa có | Tạo `storage_accounts` với vòng đời 4 bước | P0 |
| **Khóa ngắt kết nối (Guard)** | MISSING | Chống mất dữ liệu | Chưa có | Chặn ngắt kết nối nếu `active_file_count > 0` | P0 |
| **Hệ thống tệp ảo (Virtual VFS)** | MISSING | Phân vùng theo Space| Chưa có | Xây dựng `cloud_folders` và `cloud_files` có `cloud_space_id` | P0 |
| **Tải lên trực tiếp (Direct Upload)**| MISSING | Không tốn đĩa host | Chưa có | Triển khai Resumable Session URL Google Drive | P0 |
| **Giao diện Web File Manager** | MISSING | Bộ chuyển Space | Chưa có | Tab `#tab-cloud-storage` có Space Switcher & Banner | P1 |
| **Hàng đợi Desktop Cloud Queue** | MISSING | Chọn Space đích | Chưa có | Module `cloud_backup_queue.ts` có `cloud_space_id` | P1 |
| **Bảng điều khiển Storage Pool** | MISSING | Giám sát Overcommit| Chưa có | Dashboard 9 chỉ số vận hành trên `license_admin.php` | P1 |
| **Giao diện điều chỉnh Quota** | MISSING | Nhập lý do bắt buộc| Chưa có | Modal điều chỉnh hạn mức và lịch sử trên `license_admin.php` | P1 |

---

## 6. BẢN ĐỒ THAY ĐỔI TỆP TIN VÀ MODULE (FILE / MODULE CHANGE LIST V2)

### 6.1. Các Tệp Tin Đã Xác Minh Trong Kho Cần Chỉnh Sửa

| Đường Dẫn Đã Xác Minh Trong Repo | Mục Đích Hiện Tại | Loại Thay Đổi | Trách Nhiệm Kỳ Vọng Sau Khi Cập Nhật |
| :--- | :--- | :---: | :--- |
| `website/storage/db.php` | Tầng truy cập dữ liệu MySQL trung tâm | **EXTEND** | Bổ sung các hàm helper: `db_get_space_quota()`, `db_adjust_space_quota()`, `db_get_pool_metrics()`. |
| `website/index.php` | Cổng khách hàng tập trung | **EXTEND** | Bổ sung tab `#tab-cloud-storage` có bộ chuyển đổi Space Switcher, Shared Team View và Over-Quota Banner. |
| `website/license_admin.php` | Cổng quản trị viên hệ thống | **EXTEND** | Bổ sung phân hệ "Quản Trị Storage Pool" (9 metrics + Disconnect Guard) và "Sổ Cái Điều Chỉnh Hạn Mức". |
| `website/api/v1/index.php` | Điểm vào định tuyến REST API v1 | **EXTEND** | Đăng ký các tuyến đường scoped `/api/v1/cloud/spaces/{spaceId}/*` và `/api/v1/cloud/admin/*`. |
| `website/api/v1/Router.php` | Bộ định tuyến RESTful | **NO_CHANGE** | Đã hỗ trợ đầy đủ regex path params `{spaceId}` và HTTP methods. Giữ nguyên. |
| `website/api/v1/Database.php` | Kết nối PDO MySQL Singleton | **NO_CHANGE** | Giữ nguyên kiến trúc kết nối hiện tại. |
| `website/globals.css` | Hệ thống thiết kế Dark theme | **EXTEND** | Bổ sung CSS cho Space Switcher, Over-Quota Banner, Metric Cards và bảng Quota Ledger. |
| `apps/desktop/src/main/queue_manager.ts` | Hàng đợi xử lý GPU AI | **EXTEND** | Phát sự kiện `project:completed` để `CloudBackupQueue` tiếp nhận sao lưu theo `cloud_space_id`. |
| `apps/desktop/src/main/ipc_handlers.ts` | Giao tiếp IPC Main - Renderer | **EXTEND** | Đăng ký IPC: `cloud:list-spaces`, `cloud:get-space-quota`, `cloud:set-target-space`. |
| `database/local-sqlite/schema.sql` | Cấu trúc SQLite Desktop | **EXTEND** | Bổ sung bảng `cloud_backup_jobs` có trường `cloud_space_id`. |
| `database/local-sqlite/repository.ts` | Truy vấn SQLite Desktop | **EXTEND** | Bổ sung hàm thao tác hàng đợi sao lưu đám mây theo Không gian lưu trữ. |

### 6.2. Các Tệp Tin / Module Mới Đề Xuất (PROPOSED_NEW_PATH V2)

| Đường Dẫn Đề Xuất Mới | Trách Nhiệm Module |
| :--- | :--- |
| `website/api/v1/storage/StorageProviderInterface.php` | Giao diện trừu tượng hóa cho nhà cung cấp lưu trữ |
| `website/api/v1/storage/CryptoService.php` | Tiện ích mã hóa / giải mã AES-256-GCM bảo vệ Refresh Token |
| `website/api/v1/storage/GoogleDriveStorageAdapter.php` | Triển khai giao tiếp với Google Drive REST API v3 qua cURL |
| `website/api/v1/storage/CloudQuotaManager.php` | Quản lý hạn mức theo Cloud Space, khóa tạm nguyên tử và tính toán Over-Quota |
| `website/api/v1/storage/StorageAllocator.php` | Thuật toán `MOST_FREE_SPACE` và kiểm soát bộ đệm an toàn 10% |
| `website/api/v1/controllers/CloudFilesController.php` | Controller xử lý tệp, thư mục, thùng rác theo phạm vi Cloud Space |
| `website/api/v1/controllers/CloudUploadsController.php` | Controller xử lý tạo phiên upload Resumable theo Cloud Space |
| `website/api/v1/controllers/CloudDownloadController.php`| Controller xử lý truyền phát tải xuống và xem trước (Range Stream) |
| `website/api/v1/controllers/CloudAdminController.php` | Controller xử lý Storage Pool Dashboard, Safe Draining và Quota Adjustments |
| `apps/desktop/src/main/cloud_backup_queue.ts` | Hàng đợi sao lưu chạy nền độc lập trên Desktop App có hỗ trợ chọn Space |

---

## 7. BẢNG THEO DÕI RỦI RO & BIỆN PHÁP GIẢM THIỂU (RISK REGISTER V2)

| Mã Rủi Ro | Mô Tả Rủi Ro | Mức Độ | Khả Năng | Biện Pháp Phòng Ngừa & Giảm Thiểu (Mitigation) | Đầu Mối (Owner) |
| :---: | :--- | :---: | :---: | :--- | :---: |
| **R-01** | Lộ lọt Refresh Token Google Drive của operator | **CỰC CAO** | Thấp | Mã hóa AES-256-GCM, lưu khóa chủ ngoài DB, lọc secret trong log, không gửi token về client. | Security Lead |
| **R-02** | Tràn ổ đĩa máy chủ hosting 200MB do vô tình tạo file tạm | **CỰC CAO** | Trung bình | Cấm tuyệt đối ghi file nhị phân ra đĩa hosting; 100% tệp tin stream trực tiếp từ client lên Google Drive. | Backend Lead |
| **R-03** | Mất dữ liệu do ngắt kết nối tài khoản Drive khi còn tệp sống | **CỰC CAO** | Trung bình | **Safe Disconnect Guard:** Chặn tuyệt đối ở tầng API nếu `active_file_count > 0`. Phải rút cạn (`DRAINING`) trước. | Backend Lead |
| **R-04** | Tràn đĩa vật lý do bán vượt dung lượng (Overcommit Runaway) | **CAO** | Trung bình | Bảng theo dõi `OVERCOMMIT_RATIO` và `POOL_ALLOCATABLE_FREE` trên Admin Dashboard với cảnh báo màu sắc. | Infra Lead |
| **R-05** | Tài khoản Google Drive vật lý bị đầy đột ngột | **CAO** | Trung bình | Bộ đệm an toàn 10%, tự động chuyển sang `NEAR_FULL` ở 85% và `DRAINING` ở 95%, chọn ổ trống nhiều nhất. | Infra Lead |
| **R-06** | Quyền OAuth của một tài khoản Drive bị thu hồi/hết hạn | **CAO** | Thấp | Bắt lỗi phân quyền, chuyển sang `AUTH_REQUIRED`, thông báo trên Admin Dashboard, giữ nguyên metadata tệp cũ. | Backend Lead |
| **R-07** | Giới hạn tần suất Google API (Rate Limit / 429) | **TRUNG BÌNH**| Trung bình | Bounded Exponential Backoff và Retry-After; xếp hàng chờ, không xoay vòng tài khoản lách luật. | Backend Lead |
| **R-08** | Tải tệp lớn bị đứt quãng do timeout PHP 30s | **TRUNG BÌNH**| Cao | Hỗ trợ HTTP Range Header; Desktop tải theo khối nhỏ; chuẩn bị lộ trình VPS Gateway cho các tệp video nặng. | Architect |
| **R-09** | Bất đồng bộ giữa Database và Google Drive (Lỗi mạng giữa chừng)| **TRUNG BÌNH**| Trung bình | Xác thực tệp tại bước `finalize` trước khi kích hoạt `ACTIVE`; tiến trình dọn dẹp hủy các phiên quá hạn 24h. | Backend Lead |
| **R-10** | Tranh chấp hạn mức khi tải nhiều tệp song song | **TRUNG BÌNH**| Trung bình | Khóa tạm dung lượng (`reserve`) bằng `SELECT ... FOR UPDATE` trước khi cấp phiên upload; Idempotency key. | Database Lead |
| **R-11** | Rủi ro pháp lý/thương mại về điều khoản dịch vụ của Google | **CAO** | Thấp | Thiết lập Release Blocker `GOOGLE_DRIVE_COMMERCIAL_STORAGE_USAGE_REVIEW` trước khi mở bán thương mại. | Management |

---

## 8. HỆ THỐNG CÁC TÀI LIỆU QUY HOẠCH CHI TIẾT (DOCUMENTATION INDEX)

Toàn bộ kế hoạch kiến trúc chi tiết được chia nhỏ thành các tài liệu chuyên đề sau:
1. `docs/CLOUD_CURRENT_SYSTEM_AUDIT.md`: Kiểm toán toàn diện hiện trạng mã nguồn, hosting và cơ sở dữ liệu.
2. `docs/CLOUD_TARGET_ARCHITECTURE.md`: Mô hình kiến trúc mục tiêu V2 (Cloud Space, 3 lớp tách rời, luồng dữ liệu).
3. `docs/CLOUD_SPACE_AND_TEAM_MODEL.md`: Đặc tả chi tiết Miền Cloud Space, Mô hình Team và Ánh xạ Bản quyền/Thiết bị.
4. `docs/CLOUD_ADMIN_OPERATIONS_PLAN.md`: Cẩm nang vận hành Storage Pool, Vòng đời Safe Removal và Sổ cái Quota.
5. `docs/CLOUD_DATABASE_PLAN.md`: Thiết kế lược đồ cơ sở dữ liệu V2 (MySQL & SQLite) và Bảng Delta chi tiết.
6. `docs/CLOUD_API_PLAN.md`: Đặc tả toàn bộ RESTful API scoped theo Không gian và các API Quản trị viên.
7. `docs/CLOUD_SECURITY_PLAN.md`: Ma trận phân quyền, Mã hóa AES-256-GCM, Cơ chế chống rò rỉ và Safe Guards.
8. `docs/CLOUD_STORAGE_POOL_PLAN.md`: Quản trị cụm Drive đa tài khoản, Chỉ số Overcommit và Thuật toán cấp phát V2.
9. `docs/CLOUD_DESKTOP_INTEGRATION_PLAN.md`: Tích hợp hàng đợi sao lưu trên Desktop, Space Selector và Resume engine.
10. `docs/CLOUD_WEB_INTEGRATION_PLAN.md`: Giao diện Web File Manager trên `index.php` và Admin Portal trên `license_admin.php`.
11. `docs/CLOUD_IMPLEMENTATION_ROADMAP.md`: Lộ trình triển khai 15 giai đoạn độc lập từ Phase 0 đến Phase 15.
12. `docs/CLOUD_APP_AGENT_INTEGRATION_GUIDE.md`: Cẩm nang bàn giao kỹ thuật chi tiết dành riêng cho Agent phát triển Desktop App (2TOOLNE Upscale).

---

## 9. TRẠNG THÁI CUỐI CÙNG (FINAL STATUS)

```
================================================================================
FINAL STATUS: CLOUD_PLAN_V2_READY_FOR_IMPLEMENTATION
================================================================================
```

Kế hoạch tích hợp kiến trúc Bể lưu trữ đám mây đa tài khoản Google Drive (2TOOLNE Cloud V2) đã được nâng cấp toàn diện:
- Đã hoàn tất chuyển đổi sang mô hình miền **Cloud Space** (Personal Space & Team Space).
- Đã hoàn thiện cẩm nang vận hành **Admin Storage Pool Operations**, cơ chế **Safe Disconnect Guard** và **Sổ cái điều chỉnh hạn mức bất biến**.
- Đã ánh xạ thành công bản quyền ghế làm việc cho Team trên bảng `devices` hiện hữu mà không cần migration (`MIGRATION_REQUIRED = NO`).
- Đã thiết lập cờ tính năng bảo vệ an toàn `TEAM_PLANS_ENABLED = false`.
- Đã thiết lập đầy đủ cẩm nang bàn giao kỹ thuật đồng bộ cho Agent phát triển Desktop App (`CLOUD_APP_AGENT_INTEGRATION_GUIDE.md`).
- Đảm bảo tương thích 100% với hạ tầng máy chủ thực tế (PHP 7.4.33, MySQL 5.7.41, 200MB Hosting Quota).

**AI ĐÃ HOÀN TẤT TOÀN BỘ 13 TÀI LIỆU QUY HOẠCH KIẾN TRÚC V2 & BÀN GIAO APP. THEO ĐÚNG CHỈ DẪN: AI DỪNG LẠI TẠI ĐÂY VÀ CHỜ PHÊ DUYỆT CỦA NGƯỜI DÙNG TRƯỚC KHI BẮT ĐẦU TRIỂN KHAI BẤT KỲ MÃ NGUỒN NÀO.**
