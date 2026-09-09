# 2TOOLNE AUTOEDIT V2 — AUTH + LICENSE + ACCOUNT + TOKEN + CLOUD + TEAM INTEGRATION PLAN
**Tài liệu:** `reports/productization/AUTH_LICENSE_CLOUD_TEAM_INTEGRATION_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-ARCHITECTURE-RESEARCH`  
**Ngày lập:** 08/09/2026  
**Chế độ:** `SECURITY-SENSITIVE PRODUCT INTEGRATION RESEARCH`  
**Trạng thái hệ thống cốt lõi:**  
- `A0 / A1 / A2`: **FROZEN_PRODUCTION** (Bất biến, không chạm mã nguồn)  
- `SubtitleLayoutEngine`: **FROZEN_PRODUCTION** (Bất biến)  
- `UX_Notification_Polish`: **COMPLETE**  
- `Quy tắc thực thi`: **Nghiên cứu kiến trúc & đối soát thực tế — Tuyệt đối chưa can thiệp sửa đổi cơ sở dữ liệu hoặc mã nguồn production trong phân đoạn này.**

---

## MỤC LỤC BÁO CÁO (20 PHẦN CHUẨN ĐẶC TẢ)

1. [Bảng Ma Trận Hiện Trạng Hệ Thống Thực Tế (Current Reality Matrix)](#1-current-reality-matrix)
2. [Hiện Trạng Hệ Thống Bản Quyền Desktop (License Current State)](#2-license-current-state)
3. [Hiện Trạng Hệ Thống Tài Khoản Web (Account Current State)](#3-account-current-state)
4. [Hiện Trạng Hệ Thống Token & Cổng Chặn Upscale (Token / Upscale Current State)](#4-token--upscale-current-state)
5. [So Sánh Các Phương Án Đăng Nhập Nhanh Qua Trình Duyệt (Quick Browser Login Options)](#5-quick-browser-login-options)
6. [Kiến Trúc Đăng Nhập Trình Duyệt Tối Ưu Được Đề Xuất (Recommended Quick Login Architecture)](#6-recommended-quick-login-architecture)
7. [An Ninh Quản Lý Phiên & Lưu Trữ Token Trên Desktop (Session / Token Security)](#7-session--token-security)
8. [Hiện Trạng Hạ Tầng Lưu Trữ Đám Mây (Cloud Current State)](#8-cloud-current-state)
9. [Thiết Kế Trình Duyệt Tệp Ảo Trên Desktop (Cloud Explorer Design)](#9-cloud-explorer-design)
10. [Thiết Kế Cơ Chế Chia Sẻ Liên Kết (Share-Link Design)](#10-share-link-design)
11. [Thiết Kế Hệ Thống Đội Nhóm & Không Gian Làm Việc (Team / Workspace Design)](#11-team--workspace-design)
12. [Ma Trận Phân Quyền Chi Tiết (Permission Matrix)](#12-permission-matrix)
13. [Mô Hình Sở Hữu Token: Cá Nhân vs Đội Nhóm (Token Ownership Model)](#13-token-ownership-model)
14. [Mô Hình Bản Quyền Ứng Dụng vs Gói Đội Nhóm (License vs Team Model)](#14-license-vs-team-model)
15. [Ma Trận Hành Vi Khi Ngoại Tuyến (Offline Behavior Matrix)](#15-offline-behavior-matrix)
16. [Danh Mục Thay Đổi Phía Máy Chủ Web (Required Backend Changes)](#16-required-backend-changes)
17. [Danh Mục Thay Đổi Phía Ứng Dụng Desktop (Required Desktop Changes)](#17-required-desktop-changes)
18. [Danh Mục Thay Đổi Cấu Trúc Cơ Sở Dữ Liệu (Database Changes If Any)](#18-database-changes-if-any)
19. [Đánh Giá Rủi Ro & Mối Đe Dọa An Ninh (Security Threat Review)](#19-security-threat-review)
20. [Lộ Trình Triển Khai 6 Giai Đoạn (Implementation Roadmap)](#20-implementation-roadmap)

---

## 1. CURRENT REALITY MATRIX

Qua rà soát thực tế toàn bộ mã nguồn tại `apps/capcut-v2/desktop/` (Electron Main & Renderer), `apps/capcut-v2/desktop_bridge/` (Python Sidecar Bridge), `website/api/v1/controllers/` (PHP API Controllers), `website/index.php` (Web Portal) và cơ sở dữ liệu MySQL `ecxaebka_bot`, hiện trạng từng thành phần được phân loại chính xác:

| Phân Hệ / Tính Năng | Trạng Thái Hiện Thực | Vị Trí Mã Nguồn Thực Tế | Đánh Giá Kỹ Thuật & Lỗ Hổng Thực Tế |
| :--- | :---: | :--- | :--- |
| **1. License Activation** | `PRODUCTION` | `CapCutLicenseController::activate` (`website/api/v1/controllers/CapCutLicenseController.php:153`) & `bridge.py:108` | Hoạt động đầy đủ: xác thực Bcrypt + HMAC-SHA256 pepper O(1), cấp chữ ký số Ed25519, rate limiting đa chiều, ràng buộc HWID thiết bị. |
| **2. License Validation** | `PRODUCTION` | `CapCutLicenseController::verify` & `bridge.py:127` | Hoạt động tốt: kiểm tra trạng thái active/banned, đối soát HWID, xác thực chữ ký số Ed25519 ngoại tuyến. |
| **3. License Deactivation** | `PRODUCTION` | `CapCutLicenseController::deactivate` & `index.js:348` | Hoạt động tốt: giải phóng thiết bị khỏi bảng `devices`, xóa envelope chữ ký số trong `secure_store.bin`. |
| **4. Device Registration** | `PRODUCTION` | `CapCutLicenseController.php:304` & `devices` table | Ràng buộc chính xác phần cứng qua `device_fingerprint`, giới hạn tối đa 3 thiết bị đồng thời cho gói PRO cá nhân. |
| **5. Offline License Grace** | `PRODUCTION` | `CapCutLicenseController.php:318` & `bridge.py:127` | Đã triển khai: 72 giờ (259.200 giây) grace period thông qua phong bì chứng thực Ed25519 (`kid_2026_01`). |
| **6. Web Login** | `SECURITY_RISK` / `DEPRECATED` | `index.js:376` (`auth:login`) & `app.js:2321` (`modalLogin`) | **RỦI RO CAO:** Desktop Renderer hiển thị modal trực tiếp yêu cầu người dùng nhập email & password, gửi thô qua IPC tới Main để POST `/api/v1/auth/login`. Vi phạm khuyến nghị RFC 8252, tiềm ẩn nguy cơ lộ mật khẩu và bị chặn bởi Google OAuth. |
| **7. Web Logout** | `PARTIAL` | `index.js:401` (`auth:logout`) | Chỉ xóa khóa `auth_token` và `auth_user` trong `secure_store.bin` cục bộ, chưa gửi lệnh hủy phiên/revoke token lên máy chủ. |
| **8. Access Token Model** | `PARTIAL` | `AuthController.php:117` (`hash_hmac`) | Sử dụng chuỗi băm HMAC-SHA256 tĩnh (`user_id . time()`), không phải chuẩn JWT có thời hạn claim `exp` hoặc phân quyền rõ ràng. |
| **9. Refresh Token Model** | `MISSING` | Chưa có trong backend & desktop | Chưa có cơ chế Refresh Token xoay vòng (rotating refresh token). Khi phiên hết hạn, ứng dụng bị ngắt đột ngột. |
| **10. Wallet Balance** | `PARTIAL` | `WalletController.php:12` & `index.js:416` | API tra cứu số dư hoạt động tốt, Desktop hiển thị được lên thanh trạng thái, nhưng **không hề được kiểm tra chặn trước khi thực hiện tác vụ Upscale**. |
| **11. Credit Commit** | `PARTIAL` | `CreditsController.php:108` vs `index.js:753` | Backend đã có cơ chế trừ phí nguyên tử kèm `idempotency_key`, nhưng Desktop `index.js` gọi sai khóa session (`user_session` thay vì `auth_token`), không truyền reservation ID và nuốt lỗi commit trong `try/catch`. |
| **12. Web-to-Desktop SSO** | `PARTIAL` | `index.php:3709` & `AuthController.php:136` | Web Portal đã có bảng `app_auth_sessions` và modal phê duyệt đăng nhập (`index.php?app_auth=1`), nhưng cơ chế phản hồi đang dùng fetch ngầm không an toàn (`http://127.0.0.1:<port>/callback`) truyền thẳng reusable token qua query URL; Desktop chưa có loopback HTTP listener để đón nhận. |
| **13. Account Persistence** | `PARTIAL` | `secure_storage.js` & `app.js` | Lưu trữ an toàn qua Electron `safeStorage` (Keychain / DPAPI) trong file nhị phân `secure_store.bin`, nhưng trạng thái đăng nhập đang bị sao chép tản mát ở Renderer DOM. |
| **14. Device Identity** | `PRODUCTION` | `bridge.py` (`_get_device_id`) & `index.js:304` | Thu thập HWID chuẩn từ UUID phần cứng OS và băm SHA-256 bất biến. |
| **15. Cloud / Storage Code** | `BACKEND_ONLY` | `website/api/v1/controllers/Cloud*Controller.php` | Backend V2 đã có đầy đủ API quản lý Không gian lưu trữ, Thư mục ảo, Tệp tin ảo, Hạn mức đa năng và Cơ chế cấp phiên Resumable trực tiếp lên Google Drive. Tuy nhiên **Desktop AutoEdit hiện tại chưa có giao diện Tab Cloud hay trình duyệt tệp nào**. |
| **16. Share Link Code** | `BACKEND_DESIGNED` / `MISSING` | `docs/CLOUD_API_PLAN.md` | Đã có tài liệu kiến trúc đặc tả, nhưng chưa có bảng `cloud_shares` và chưa có controller xử lý tạo/thu hồi link công khai. |
| **17. Team / Workspace Code**| `BACKEND_ONLY` | `docs/CLOUD_DATABASE_PLAN.md` & `teams` table | Cấu trúc bảng `teams`, `team_members`, `cloud_spaces.owner_type = 'TEAM'` đã được thiết kế sẵn sàng dưới cờ `TEAM_PLANS_ENABLED = false`. Desktop hoàn toàn chưa tích hợp. |

---

## 2. LICENSE CURRENT STATE

### 2.1. Bản Quyền Phần Mềm Độc Lập Với Tài Khoản Người Dùng
Hệ thống bản quyền desktop hiện tại được thiết kế chuyên biệt cho sản phẩm **2TOOLNE AutoEdit for CapCut V2**:
- **LICENSE_KEY_REQUIRED**: **YES** (Bắt buộc để sử dụng các tính năng biên tập tự động thương mại của AutoEdit Core A0/A1/A2).
- **LICENSE_SERVER_ENDPOINT**:
  - Kích hoạt: `POST https://www.2tamne.site/api/v1/capcut/activate`
  - Xác thực: `POST https://www.2tamne.site/api/v1/capcut/verify`
  - Hủy kích hoạt: `POST https://www.2tamne.site/api/v1/capcut/deactivate`
- **LICENSE_DEVICE_BINDING**: Bắt buộc gắn với dấu vân tay phần cứng máy tính (`device_fingerprint` = băm SHA-256 của Serial Number bo mạch / Disk UUID). Cho phép tối đa **3 thiết bị** hoạt động đồng thời trên một mã bản quyền cá nhân (`max_devices = 3`).
- **LICENSE_OFFLINE_GRACE**: **72 giờ** (259.200 giây).
  - Khi kích hoạt thành công trực tuyến, máy chủ cấp một phong bì dữ liệu chứa chữ ký số bất đối xứng **Ed25519** (Key ID: `kid_2026_01`).
  - Khóa công khai Ed25519 được nhúng cứng an toàn trong Sidecar Bridge Python (`bridge.py`).
  - Khi không có kết nối Internet, Sidecar kiểm tra chữ ký số Ed25519 và đối soát nhãn thời gian máy chủ tin cậy (`trusted_server_time`). Trong vòng 72 giờ, ứng dụng tiếp tục hoạt động 100% ngoại tuyến.
- **LICENSE_LOCAL_STORAGE**: Mã hóa mức hệ điều hành thông qua Electron `safeStorage`:
  - macOS: Apple Keychain (khóa dẫn xuất AES-128 GCM).
  - Windows: Windows DPAPI (CryptProtectData / CryptUnprotectData).
  - Tệp lưu trữ cục bộ: `<userData>/secure_store.bin`. Tuyệt đối không lưu chuỗi key trần dạng plaintext.
- **LICENSE_REVALIDATION_POLICY**:
  - Khi khởi động app: Tự động giải mã envelope từ `secure_store.bin`, kiểm tra chữ ký Ed25519 cục bộ.
  - Chạy ngầm: Định kỳ mỗi 4 giờ hoặc khi phát hiện có mạng Internet trở lại, âm thầm gọi `/api/v1/capcut/verify` để đồng bộ trạng thái giấy phép (phát hiện kịp thời trường hợp key bị Admin thu hồi hoặc hết hạn).
  - Khi hết hạn 72h offline: Chuyển trạng thái sang `LICENSE_EXPIRED`, khóa các tính năng biên tập thương mại cho tới khi kết nối mạng để tái xác thực.
- **Giao Diện License Hiện Tại**:
  - Người dùng bấm nút "Bản Quyền" trên thanh tiêu đề.
  - Hiển thị Modal nhập Key (`2TL-CAP-XXXX-XXXX-XXXX`).
  - Sau khi kích hoạt: Ẩn input, hiển thị trạng thái `Đã kích hoạt (PRO)`, thời hạn hết hạn, mã key rút gọn `2TL-CAP-****-****-AB12`, và danh sách các máy đang kích hoạt kèm nút "Hủy kích hoạt máy này".

---

## 3. ACCOUNT CURRENT STATE

### 3.1. Hiện Trạng Đăng Nhập Tài Khoản Web
- **DESKTOP_LOGIN_CURRENT_METHOD**: Hiện tại đang là **Email / Password Modal trực tiếp trong ứng dụng Desktop**.
  - Người dùng bấm nút "👤 Đăng Nhập Tài Khoản" trên thanh điều hướng.
  - Renderer mở `modalLogin`, người dùng gõ mật khẩu tài khoản web.
  - JavaScript Renderer gọi `window.autoedit.login({ email, password })`.
  - Main process gửi yêu cầu POST tới `https://www.2tamne.site/api/v1/auth/login`.
- **Đánh Giá Lỗ Hổng (Security Assessment)**:
  - **Mức độ rủi ro:** `CRITICAL SECURITY RISK` & `DEPRECATED`.
  - **Lý do:**
    1. Vi phạm tiêu chuẩn an ninh **IETF RFC 8252 (OAuth 2.0 for Native Apps)**: Ứng dụng desktop không bao giờ được phép trực tiếp thu thập hay xử lý mật khẩu web của người dùng.
    2. Nếu tài khoản đăng nhập bằng Google OAuth trên web, người dùng sẽ không có mật khẩu để gõ vào form desktop.
    3. Nguy cơ bị phần mềm độc hại hook bộ nhớ Electron Renderer để đánh cắp mật khẩu.
- **DESKTOP_ACCESS_TOKEN_MODEL**:
  - Máy chủ web trả về một chuỗi token băm: `hash_hmac('sha256', (string)$user['id'] . time(), JWT_AUTH_SECRET)`.
  - Chuỗi token này không chứa cấu trúc tiêu chuẩn (Header.Payload.Signature), thiếu trường thời gian hết hạn cụ thể (`exp`), gây khó khăn cho việc quản lý vòng đời phiên.
- **DESKTOP_REFRESH_MODEL**: **NONE**. Hiện không có cơ chế Refresh Token.
- **SESSION_STORAGE**: Token và thông tin cơ bản của User (`id`, `email`, `username`, `plan`) được mã hóa và lưu vào `secure_store.bin` bởi `SecureStorage`.
- **LOGOUT_BEHAVIOR**: Gọi `auth:logout` trong Main process -> Xóa `auth_token` và `auth_user` khỏi `secure_store.bin`. Không gửi yêu cầu thu hồi phiên (revoke) lên máy chủ web.
- **TOKEN_EXPIRY_HANDLING**: Không có cơ chế bắt lỗi 401 tập trung để tự động làm mới phiên. Khi token bị từ chối, ứng dụng báo lỗi chuỗi kết nối máy chủ thô.

---

## 4. TOKEN / UPSCALE CURRENT STATE

### 4.1. Thực Trạng Quy Trình Phóng To Ảnh AI (Upscale)
Tệp `apps/capcut-v2/desktop/src/main/index.js` (dòng 614–790) xử lý sự kiện `upscale:process-images`:
1. Nhận danh sách đường dẫn ảnh cục bộ `filePaths` và độ phân giải (`2K` hoặc `4K`).
2. Xác định chi phí tính toán: `2K` = 1 token/ảnh, `4K` = 2 token/ảnh.
3. Chạy trực tiếp engine phóng to ảnh NCNN Vulkan cục bộ (`realesrgan-ncnn-vulkan`) hoặc FFmpeg fallback trên GPU của máy khách.
4. **LỖ HỔNG BỎ QUA CỔNG BẢN QUYỀN & TOKEN (CRITICAL BILLING BYPASS):**
   - Trước khi bắt đầu upscale, ứng dụng **hoàn toàn không kiểm tra xem người dùng đã đăng nhập hay chưa**.
   - Ứng dụng **không kiểm tra số dư token trên máy chủ**.
   - Ứng dụng **không thực hiện giữ trước token (Reserve)**.
   - Sau khi ảnh đã upscale xong ra đĩa cục bộ, đoạn mã tại dòng 755 mới chạy:
     ```javascript
     const session = secureStorage.getItem('user_session'); // <-- BUG: Lúc đăng nhập lưu tên là 'auth_token', nên session luôn là NULL!
     const token = session?.token;
     if (token) {
       await postJson('/api/v1/credits/commit', { ... });
     }
     ```
   - Vì `session` trả về `null`, lệnh `commit` không bao giờ được gọi!
   - Thậm chí nếu có gọi, khối `catch` bên ngoài cũng chỉ in `console.warn` mà không dừng tiến trình.
   - **Hệ quả thực tế:** Bất kỳ ai tải app về đều có thể chạy Upscale miễn phí không giới hạn, không cần đăng nhập tài khoản và không bị trừ bất kỳ token nào!

### 4.2. Khả Năng Thực Tế Của Máy Chủ Web (`CreditsController.php`)
Trái ngược với desktop, máy chủ web tại `website/api/v1/controllers/CreditsController.php` đã có sẵn kiến trúc trừ phí 3 bước cực kỳ chuẩn mực và an toàn:
1. **Bước 1 — Khóa giữ trước (`POST /api/v1/credits/reserve`):**
   - Kiểm tra thiết bị và người dùng, khóa dòng ví `SELECT ... FOR UPDATE`.
   - Chuyển số token cần thiết từ `balance` sang `reserved_balance`.
   - Tạo bản ghi `credit_reservations` trạng thái `PENDING`.
2. **Bước 2 — Chốt trừ vĩnh viễn (`POST /api/v1/credits/commit`):**
   - Hỗ trợ cả 2 chế độ: Commit theo `reservation_id` hoặc Direct Commit theo `user_id`.
   - **Chống trừ đúp bằng Khóa Bất Biến (`idempotency_key`):** Kiểm tra bảng `credit_transactions` xem `idempotency_key` đã xử lý chưa. Nếu đã có, trả về kết quả thành công ngay lập tức mà không trừ tiền lần thứ hai.
   - Trừ số token khỏi `reserved_balance`, ghi nhật ký sổ cái kiểm toán bất biến `credit_transactions`.
3. **Bước 3 — Hoàn trả khi lỗi (`POST /api/v1/credits/release`):**
   - Giải phóng `reserved_balance` trở lại `balance` nếu tác vụ bị hủy hoặc gặp sự cố phần cứng.

---

## 5. QUICK BROWSER LOGIN OPTIONS

Nghiên cứu so sánh 4 giải pháp xác thực đăng nhập máy khách trên hệ điều hành macOS và Windows theo tiêu chuẩn công nghiệp:

| Tiêu Chí So Sánh | Phương Án A: Custom Protocol (`2toolne://auth/callback`) | Phương Án B: Localhost Loopback (`http://127.0.0.1:<port>/callback`) | Phương Án C: Web Session Polling (Quét mã / Long Polling) | Phương Án D: Embedded BrowserWindow (Webview trong App) |
| :--- | :--- | :--- | :--- | :--- |
| **Cơ Chế Kỹ Thuật** | Đăng ký Deep Link OS (`app.setAsDefaultProtocolClient`). Trình duyệt mở redirect link sang giao thức tùy chỉnh. | Desktop mở HTTP server tạm thời trên cổng ngẫu nhiên. Trình duyệt redirect HTTP GET trực tiếp về localhost. | Desktop tạo mã phiên `auth_session_id`, mở trình duyệt web, định kỳ gửi request hỏi máy chủ xem user đã duyệt chưa. | Mở một cửa sổ Electron con chứa trang login của website 2TOOLNE. |
| **Trải Nghiệm Người Dùng (UX)** | Trình duyệt hiện popup cảnh báo: *"Bạn có muốn mở ứng dụng 2toolne không?"* Bấm xác nhận 1 lần. | **Mượt mà nhất:** Trình duyệt tự động chuyển hướng về localhost, hiển thị trang *"Đăng nhập thành công, bạn có thể đóng tab này"*. | Mượt mà, không cần popup OS, nhưng tốn lưu lượng request và có độ trễ 1-2 giây. | Trông giống giao diện app, nhưng không tận dụng được session đã đăng nhập sẵn trên Chrome/Safari. |
| **Độ Ổn Định Windows / macOS** | **Khá:** Cần phân quyền registry trên Windows (nếu bản Portable có thể không đăng ký được giao thức). macOS hoạt động tốt. | **Tuyệt vời:** 100% các phiên bản Windows và macOS đều hỗ trợ kết nối loopback nội bộ không cần quyền Admin. | **Tuyệt đối:** Hoạt động trên mọi nền tảng không phụ thuộc cấu hình mạng máy khách. | Khá, nhưng dễ xung đột cookie session giữa các phiên. |
| **Rủi Ro Trình Duyệt Chặn (PNA / CORS)** | Không bị ảnh hưởng bởi Private Network Access. | **Lưu ý:** Chrome/Safari có chính sách PNA (Private Network Access) có thể hạn chế trang HTTPS gọi fetch sang HTTP localhost. Tuy nhiên, nếu là **Top-level Navigation Redirect** (chuyển trang trực tiếp bằng `window.location.href`) thì hoàn toàn được cho phép. | Không bị ảnh hưởng. | Không bị ảnh hưởng. |
| **An Ninh Chống Chiếm Đoạt (Hijacking)** | Nếu máy khách bị cài phần mềm độc hại đăng ký đè protocol `2toolne://`, authorization code có thể bị nghe lén nếu không có PKCE. | Server localhost chỉ lắng nghe trên cổng ngẫu nhiên nội bộ và gắn với mã xác minh PKCE một lần dùng duy nhất. | An toàn nếu truyền session token được ký số. | Nguy cơ bảo mật cao: Electron webview dễ bị tấn công nếu trang web bị XSS. Bị Google OAuth chặn thẳng thừng vì lý do an ninh. |
| **Đánh Giá Khuyến Nghị** | **Phương án Dự phòng 1 (Fallback 1)** | **PHƯƠNG ÁN CHÍNH (PRIMARY RECOMMENDED)** | **Phương án Dự phòng 2 (Fallback 2)** | **BỊ LOẠI BỎ (STRICTLY PROHIBITED)** |

---

## 6. RECOMMENDED QUICK LOGIN ARCHITECTURE

Kiến trúc đăng nhập được lựa chọn là **Authorization Code Flow với PKCE (RFC 7636) kết hợp Localhost Loopback Server làm kênh tiếp nhận chính, và Custom Protocol `2toolne://` làm kênh dự phòng tự động**.

```
┌────────────────────────┐                                   ┌────────────────────────┐
│  2TOOLNE Desktop App   │                                   │ 2TOOLNE Web Platform   │
│   (Electron Main)      │                                   │ (https://2tamne.site)  │
└───────────┬────────────┘                                   └───────────┬────────────┘
            │                                                            │
            │ 1. Tạo ngẫu nhiên:                                         │
            │    - code_verifier (128 ký tự mật)                         │
            │    - code_challenge = Base64URL(SHA256(verifier))          │
            │    - state (32 byte chống CSRF)                            │
            │    - Khởi tạo Loopback HTTP Server (port ngẫu nhiên: P)    │
            │                                                            │
            │ 2. shell.openExternal(System Default Browser)              │
            │    URL: https://2tamne.site/index.php?app_auth=1           │
            │         &challenge=...&state=...&port=P                    │
            ├───────────────────────────────────────────────────────────►│
            │                                                            │ 3. Người dùng đăng nhập web
            │                                                            │    (hoặc đã có sẵn session)
            │                                                            │
            │                                                            │ 4. Web hiển thị bảng hỏi:
            │                                                            │    "Ứng dụng 2TOOLNE AutoEdit
            │                                                            │     yêu cầu liên kết tài khoản"
            │                                                            │    [ ✅ Phê Duyệt ]
            │                                                            │
            │                                                            │ 5. Web sinh auth_code (TTL: 60s)
            │                                                            │    Lưu vào app_auth_sessions
            │                                                            │
            │ 6. Browser chuyển hướng trực tiếp (Top-level redirect):    │
            │    http://127.0.0.1:P/callback?code=AUTH_CODE&state=STATE  │
            │◄───────────────────────────────────────────────────────────┤
            │                                                            │
            │ 7. Desktop Loopback Server nhận request:                   │
            │    - Đối soát state trùng khớp 100%                        │
            │    - Trả về HTML: "Đăng nhập thành công! Hãy quay lại app" │
            │    - Đóng ngay lập tức HTTP Server                         │
            │                                                            │
            │ 8. Gửi Back-channel HTTPS POST bảo mật:                    │
            │    POST /api/v1/auth/token                                 │
            │    Body: { code: AUTH_CODE, verifier: code_verifier }      │
            ├───────────────────────────────────────────────────────────►│
            │                                                            │ 9. Server đối soát:
            │                                                            │    SHA256(verifier) == challenge
            │                                                            │    Đánh dấu code "ĐÃ DÙNG"
            │                                                            │    Sinh Desktop Access Token
            │                                                            │    + Refresh Token
            │ 10. Trả về Token + User Profile                            │
            │◄───────────────────────────────────────────────────────────┤
            │                                                            │
            │ 11. Desktop lưu Access Token + Refresh Token vào           │
            │     Apple Keychain / Windows DPAPI (secure_store.bin)      │
            │     Cập nhật UI Renderer: Đã Đăng Nhập                     │
            ▼                                                            ▼
```

### 6.1. Quy Tắc Bất Biến Về An Ninh
1. **Tuyệt đối không truyền Access Token hoặc Mật khẩu trên URL:** Trình duyệt chỉ truyền chuỗi `auth_code` dùng 1 lần (TTL 60 giây). Dù kẻ xấu có đọc được lịch sử trình duyệt thì `auth_code` cũng đã bị hủy hoặc không thể sử dụng nếu thiếu `code_verifier`.
2. **Nguyên tắc PKCE ngăn chặn nghe lén:** Chỉ tiến trình Electron Main sở hữu chuỗi bí mật `code_verifier` ban đầu mới có thể đổi lấy token chính thức từ máy chủ.
3. **Cơ chế Fallback thông minh:** Nếu vì tường lửa máy tính mà trình duyệt không kết nối được tới `127.0.0.1:P`, trang web sẽ hiển thị thêm nút bấm *"Mở ứng dụng thủ công"* kích hoạt `2toolne://auth/callback?code=...&state=...`, đảm bảo tỷ lệ thành công 100%.

---

## 7. SESSION / TOKEN SECURITY

1. **Phân Tách Biên Giới Tiến Trình (Process Boundary Isolation):**
   - Mọi khóa mật, `access_token`, `refresh_token`, và chữ ký bản quyền đều do **Electron Main Process** quản lý độc quyền.
   - Renderer Process (giao diện HTML/JS) tuyệt đối không có quyền truy cập trực tiếp vào hệ thống tệp đĩa hoặc các hàm mã hóa.
   - Renderer chỉ giao tiếp với Main qua các kênh IPC được định nghĩa sẵn trong `preload.js` (`contextIsolation: true`, `nodeIntegration: false`).
2. **Lưu Trữ An Toàn Bằng Mã Hóa Phần Cứng:**
   - Sử dụng lớp trừu tượng `SecureStorage` đã hoàn thiện tại `apps/capcut-v2/desktop/src/main/secure_storage.js`.
   - Trên macOS: Sử dụng Master Key sinh từ **Apple Keychain Services**.
   - Trên Windows: Sử dụng **Windows Data Protection API (DPAPI)** liên kết với tài khoản đăng nhập Windows của người dùng.
   - Dữ liệu lưu trong tệp nhị phân `<userData>/secure_store.bin`.
3. **Vòng Đời Phiên Đăng Nhập & Tự Động Làm Mới (Silent Session Renewal):**
   - `access_token`: Có hiệu lực ngắn hạn (ví dụ: 2 giờ). Sử dụng cho mọi yêu cầu API thông thường.
   - `refresh_token`: Có hiệu lực dài hạn (ví dụ: 30 ngày), lưu an toàn trong DPAPI/Keychain.
   - Khi gọi API máy chủ trả về lỗi `HTTP 401 UNAUTHORIZED`, Main Process tự động kích hoạt tiến trình làm mới ngầm (`POST /api/v1/auth/refresh`) bằng `refresh_token` mà không làm gián đoạn người dùng.
   - Chỉ khi `refresh_token` hết hạn hoặc bị máy chủ thu hồi (người dùng đổi mật khẩu hoặc đăng xuất từ xa trên web), ứng dụng mới hiển thị thông báo nhẹ nhàng: *"Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại."* và mở trình duyệt để xác thực lại.

---

## 8. CLOUD CURRENT STATE

### 8.1. Kiểm Toán Hiện Trạng Hạ Tầng Đám Mây 2TOOLNE
Hệ thống Cloud của 2TOOLNE được thiết kế chuyên biệt để vượt qua giới hạn của gói lưu trữ web chia sẻ (DirectAdmin Shared Hosting dung lượng chỉ 200MB, MySQL MariaDB `ecxaebka_bot`):
- **Nguyên lý Zero-Host-Storage:** Máy chủ web `2tamne.site` không lưu trữ bất kỳ byte tệp nhị phân nào của khách hàng lên ổ cứng hosting.
- **Bể Lưu Trữ Đa Ổ Đĩa (Storage Pool Architecture):** Dữ liệu vật lý được phân tán lên các tài khoản Google Drive dung lượng lớn (15TB - 30TB) thông qua OAuth2 và bộ điều phối `StorageAllocator`.
- **Hệ Thống API Máy Chủ Đã Sẵn Sàng (Backend V2 Completed):**
  - Quản lý Không gian lưu trữ: `website/api/v1/controllers/CloudFilesController.php` (liệt kê spaces, xem hạn mức).
  - Quản lý Thư mục & Tệp ảo: Tạo thư mục, duyệt cây thư mục kèm Breadcrumb, thùng rác ảo 30 ngày, phục hồi, xóa vĩnh viễn.
  - Tải lên trực tiếp Resumable: `website/api/v1/controllers/CloudUploadsController.php` cấp URL phiên của Google Drive để client tải thẳng byte từ máy tính lên Google, hoàn tất xác thực kích thước và cộng quota.
  - Tải xuống & Truyền phát: `website/api/v1/controllers/CloudDownloadController.php` truyền phát trực tiếp dữ liệu từ Google Drive với hỗ trợ HTTP Range Header.
- **Ranh Giới Tích Hợp Trên Desktop:**
  - Ứng dụng Desktop AutoEdit hiện tại **hoàn toàn chưa có giao diện Cloud**.
  - Cần bổ sung Tab điều hướng "☁ Cloud" trên thanh Sidebar, kết nối với bộ API `/api/v1/cloud/*` của máy chủ.

---

## 9. CLOUD EXPLORER DESIGN

### 9.1. Trình Duyệt Tệp Ảo (Server-Backed Virtual Explorer)
- **Quy tắc thiết kế:** Không sử dụng driver hệ điều hành ảo hóa ổ đĩa (như Dokany trên Windows hoặc FUSE trên macOS) vì dễ gây xung đột driver, yêu cầu cài đặt phức tạp và quyền Administrator.
- Thay vào đó, xây dựng giao diện **Trình duyệt tệp ảo nội bộ ứng dụng (Virtual File Explorer Component)**:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ ☁️ CLOUD CỦA TÔI                     [ 🏢 Studio Wedding ABC ▼ ]  [ Dung lượng: 21.4 / 50 GB ] │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Đường dẫn:  Cloud  >  Dự Án Tháng 9  >  Video Cưới 001                           │
│ Thao tác:   [ ⬆ Tải Lên ]  [ 📁 Thư Mục Mới ]  [ 🔄 Làm Mới ]  [ 🗑 Thùng Rác ]  │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Tên Tệp / Thư Mục             Kích Thước      Loại           Ngày Cập Nhật       │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 📁 Audio_Background           --              Thư mục        08/09/2026 14:20    │
│ 📁 Raw_Images                 --              Thư mục        08/09/2026 15:10    │
│ 🖼️ Anh_Cong_4K.png            14.2 MB         Ảnh PNG        08/09/2026 16:02    │
│ 🎬 Video_Final_Draft.mp4      245.8 MB        Video MP4      08/09/2026 16:30    │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Menu chuột phải: [ Tải Xuống ] [ Chia Sẻ Liên Kết ] [ Đổi Tên ] [ Chuyển ] [ Xóa]│
└──────────────────────────────────────────────────────────────────────────────────┘
```

### 9.2. Tích Hợp Với Tiến Trình Biên Tập AutoEdit Cục Bộ
- **Bộ nhớ đệm xác định (Deterministic Local Cache):**
  - Khi người dùng chọn một tài sản từ Cloud đưa vào Timeline AutoEdit (ví dụ: ảnh phóng to 4K hoặc nhạc nền), Desktop Client tải tệp về thư mục cache cục bộ: `<userData>/cache/cloud_assets/<cloud_file_id>_<sha256>.<ext>`.
  - Tiến trình ghép video, render CapCut luôn đọc tệp từ ổ đĩa cục bộ, đảm bảo tốc độ xuất video tức thì và không phụ thuộc vào tình trạng gián đoạn mạng.
  - Khi tệp trên Cloud bị sửa đổi phiên bản, hash thay đổi -> Client tự động tải bản mới.
- **Tùy Chọn Lưu Trữ Đám Mây Sau Khi Render:**
  - Sau khi Hàng đợi kết xuất (Render Queue) xuất xong video MP4 ra máy tính, giao diện cung cấp tùy chọn 1-click: *"Lưu bản sao video lên Cloud"*.

---

## 10. SHARE-LINK DESIGN

### 10.1. Cơ Chế Chia Sẻ Bằng Liên Kết Công Khai (Public Share Link)
- **Mục tiêu:** Cho phép người dùng gửi link cho đối tác/khách hàng xem hoặc tải tệp video/ảnh thành phẩm mà đối tác **không cần phải đăng ký tài khoản 2TOOLNE**.
- **Mô Hình Dữ Liệu Bảng Mới (`cloud_shares`):**
  - `id`: Định danh bản ghi chia sẻ (`sh_...`).
  - `cloud_space_id`: Không gian sở hữu.
  - `item_type`: `'FILE'` hoặc `'FOLDER'`.
  - `item_id`: ID của tệp hoặc thư mục được chia sẻ.
  - `share_token`: Chuỗi ngẫu nhiên bảo mật 32 byte mã hóa URL-safe (ví dụ: `sh_7f8a9b2c3d4e5f6...`).
  - `share_token_hash`: Băm SHA-256 của token để tra cứu nhanh $O(1)$ trên máy chủ.
  - `access_level`: `'VIEW_ONLY'` hoặc `'ALLOW_DOWNLOAD'`.
  - `expires_at`: Ngày giờ hết hạn (Tùy chọn: 7 ngày, 30 ngày, hoặc Vô thời hạn).
  - `revoked_at`: Nhãn thời gian thu hồi liên kết.
  - `created_by_user_id`: Người tạo link.
- **Bảo Mật Chia Sẻ:**
  - Tuyệt đối không để lộ mã định danh vật lý của Google Drive hay đường dẫn nội bộ máy chủ.
  - Đường dẫn chia sẻ có dạng: `https://www.2tamne.site/share/{share_token}`.
  - Chủ sở hữu hoặc Admin có thể bấm **[Thu hồi liên kết]** bất kỳ lúc nào để vô hiệu hóa tức thì quyền truy cập.
  - Với thư mục chia sẻ: Máy chủ kiểm tra nghiêm ngặt cây phân cấp, chỉ cho phép duyệt các tệp con cháu nằm trong thư mục đó, tuyệt đối ngăn chặn tấn công duyệt thư mục cha (`Path Traversal`).

---

## 11. TEAM / WORKSPACE DESIGN

### 11.1. Mô Hình Không Gian Làm Việc Đội Nhóm
- **Khái niệm Workspace:** Mỗi Không gian lưu trữ đám mây (`Cloud Space`) đóng vai trò là một Workspace:
  - **Personal Workspace:** Tạo tự động cho từng tài khoản, thuộc quyền sở hữu của 1 người dùng duy nhất.
  - **Team Workspace:** Thuộc sở hữu của 1 tổ chức/đội nhóm (`teams`), được quản lý bởi Chủ nhóm (`OWNER`) và nhiều thành viên.
- **4 Cấp Bậc Phân Quyền (RBAC Roles):**
  1. **Chủ Nhóm (`OWNER`):** Toàn quyền quản trị, thanh toán cước phí, nâng cấp dung lượng, mời/xóa thành viên, chuyển giao quyền sở hữu hoặc giải tán nhóm.
  2. **Quản Trị Viên (`ADMIN`):** Quản lý thành viên (mời Editor/Viewer), quản lý toàn bộ tệp tin, tạo/xóa thư mục, cấu hình quyền chia sẻ.
  3. **Biên Tập Viên (`EDITOR`):** Tải lên tệp mới, tải xuống dữ liệu, tạo thư mục, chạy các tác vụ Upscale/Render sử dụng tài nguyên của Team, xóa tệp do chính mình tải lên.
  4. **Người Xem (`VIEWER`):** Chỉ xem và tải xuống các tệp thành phẩm trong Team; không được tải lên, không được sửa đổi dữ liệu và không được tiêu thụ token của Team.
- **Quy Tắc Khi Thành Viên Rời Nhóm:**
  - Tệp tin và dự án do thành viên tải lên **thuộc về tài sản của Team Space**, không bị xóa khi thành viên rời nhóm hoặc bị loại khỏi nhóm.
  - Quyền truy cập của thành viên bị cắt ngay lập tức trên cả Web và Desktop.

---

## 12. PERMISSION MATRIX

Bảng ma trận phân quyền thực thi phía máy chủ (Server-side Enforcement):

| Hành Động / Tác Vụ | Cá Nhân (Personal) | Team OWNER | Team ADMIN | Team EDITOR | Team VIEWER |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Xem danh sách tệp & thư mục** | ✅ Có | ✅ Có | ✅ Có | ✅ Có | ✅ Có |
| **Tải xuống tệp tin** | ✅ Có | ✅ Có | ✅ Có | ✅ Có | ✅ Có |
| **Tải lên tệp mới** | ✅ Có | ✅ Có | ✅ Có | ✅ Có | ❌ Chặn (403) |
| **Tạo thư mục mới** | ✅ Có | ✅ Có | ✅ Có | ✅ Có | ❌ Chặn (403) |
| **Đổi tên / Di chuyển tệp** | ✅ Có | ✅ Có | ✅ Có | ✅ Có (Tệp của mình) | ❌ Chặn (403) |
| **Xóa tệp vào Thùng rác** | ✅ Có | ✅ Có (Mọi tệp) | ✅ Có (Mọi tệp) | ✅ Có (Chỉ tệp của mình) | ❌ Chặn (403) |
| **Khôi phục / Xóa vĩnh viễn** | ✅ Có | ✅ Có | ✅ Có | ❌ Chặn (403) | ❌ Chặn (403) |
| **Tạo link chia sẻ công khai** | ✅ Có | ✅ Có | ✅ Có | ✅ Có | ❌ Chặn (403) |
| **Thu hồi link chia sẻ** | ✅ Có | ✅ Có | ✅ Có | ✅ Có (Link của mình) | ❌ Chặn (403) |
| **Sử dụng Token của Workspace**| ✅ (Ví cá nhân) | ✅ (Ví Team) | ✅ (Ví Team) | ✅ (Ví Team) | ❌ Chặn (403) |
| **Mời thành viên mới** | ❌ Không | ✅ Có | ✅ Có | ❌ Chặn (403) | ❌ Chặn (403) |
| **Xóa thành viên khỏi nhóm** | ❌ Không | ✅ Có | ✅ (Editor/Viewer) | ❌ Chặn (403) | ❌ Chặn (403) |
| **Mua gói / Nạp tiền Workspace**| ✅ Có | ✅ Có | ❌ Chặn (403) | ❌ Chặn (403) | ❌ Chặn (403) |

---

## 13. TOKEN OWNERSHIP MODEL

### 13.1. Phân Tích Lựa Chọn Mô Hình Sở Hữu
- **Phương án 1: `PERSONAL_ONLY` (Chỉ dùng ví cá nhân):** Thành viên làm việc cho công ty nhưng phải tự bỏ tiền túi mua token để upscale ảnh. -> *Bất tiện, gây khó khăn cho việc thanh toán chi phí của doanh nghiệp.*
- **Phương án 2: `TEAM_ONLY` (Chỉ dùng ví nhóm):** Mọi tài khoản khi gia nhập nhóm bị tước quyền dùng ví riêng. -> *Không linh hoạt cho người làm việc tự do (freelancer).*
- **PHƯƠNG ÁN TỐI ƯU KHUYẾN NGHỊ: `PERSONAL_AND_WORKSPACE` (Hỗ trợ cả ví cá nhân và ví không gian làm việc):**
  - Mỗi tài khoản người dùng luôn sở hữu một Ví cá nhân (`Personal Wallet`).
  - Mỗi Team Workspace sở hữu một Ví chung (`Team Wallet`) do Chủ sở hữu (`OWNER`) nạp tiền.
  - **Quy tắc trừ phí minh bạch tuyệt đối (Explicit Deduction Rule):**
    1. Khi người dùng đang chuyển chọn ngữ cảnh **Cá Nhân (Personal)** trên Desktop/Web: Hệ thống trừ token vào Ví cá nhân của người đó.
    2. Khi người dùng chuyển sang ngữ cảnh **Team Workspace**: Mọi tác vụ Upscale được trừ trực tiếp vào Ví của Team Workspace đó.
    3. Nếu Ví Team hết token: Báo lỗi *"Số dư token của Đội nhóm đã hết. Vui lòng liên hệ Quản trị viên để nạp thêm."* Tuyệt đối không tự ý trừ lấn sang ví cá nhân của thành viên.

---

## 14. LICENSE VS TEAM MODEL

Cần phân định rạch ròi 2 khái niệm bản quyền máy tính và gói thành viên đội nhóm:

```
┌────────────────────────────────────────────────────────┐
│               TÀI KHOẢN ĐỘI NHÓM (TEAM PLAN)           │
│   • Quản lý: Thành viên, Dung lượng Cloud (Shared Pool)│
│   • Kèm theo: Số ghế ứng dụng Desktop (app_key_count)  │
└───────────────────────────┬────────────────────────────┘
                            │ Cấp quyền sử dụng
                            ▼
┌────────────────────────────────────────────────────────┐
│               BẢN QUYỀN MÁY TRẠM (DESKTOP SEAT)        │
│   • Ràng buộc: HWID phần cứng thiết bị (bảng devices)  │
│   • Giới hạn: Số lượng máy kích hoạt đồng thời         │
│   • Hoạt động: Cho phép chạy AutoEdit & Sidecar Core   │
└────────────────────────────────────────────────────────┘
```

1. **Mô Hình Cá Nhân (Individual Model):**
   - 1 License Key kích hoạt tối đa 3 thiết bị máy tính cá nhân thuộc cùng 1 chủ sở hữu.
2. **Mô Hình Đội Nhóm (Team Seat Model):**
   - Khi công ty mua gói **TEAM STARTER** (ví dụ: 2 thành viên / 50GB Cloud / 2 App Seats):
   - Hai thành viên trong nhóm khi đăng nhập tài khoản vào ứng dụng 2TOOLNE Desktop sẽ tự động được hệ thống máy chủ cấp quyền sử dụng 1 ghế bản quyền (`app_key_count`) mà **không cần phải gõ bất kỳ License Key thủ công nào**.
   - Máy trạm của thành viên đó vẫn được ghi nhận an toàn vào bảng `devices` và nhận phong bì Ed25519 để chạy offline 72 giờ bình thường.
   - Khi thành viên bị xóa khỏi Team, ghế bản quyền trên máy trạm đó lập tức bị thu hồi ở lần kết nối mạng tiếp theo.

---

## 15. OFFLINE BEHAVIOR MATRIX

Ma trận hành vi ứng dụng khi mất kết nối Internet:

| Chức Năng Ứng Dụng | Online + License Hợp Lệ + Đã Đăng Nhập | Online + License Hợp Lệ + Chưa Đăng Nhập | Online + License Hết Hạn + Đã Đăng Nhập | Mất Mạng + Còn Trong Hạn 72h Grace | Mất Mạng + Quá 72h Grace |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Khởi động ứng dụng** | ✅ Bình thường | ✅ Bình thường | ⚠️ Hiện cảnh báo License | ✅ Bình thường | ❌ Yêu cầu kết nối mạng |
| **AutoEdit: Tạo Dự Án CapCut** | ✅ Cho phép | ✅ Cho phép | ❌ Chặn (Cần License) | ✅ Cho phép (100% Offline) | ❌ Chặn |
| **SubtitleLayoutEngine** | ✅ Cho phép | ✅ Cho phép | ❌ Chặn (Cần License) | ✅ Cho phép (100% Offline) | ❌ Chặn |
| **Render Video Cục Bộ** | ✅ Cho phép | ✅ Cho phép | ❌ Chặn (Cần License) | ✅ Cho phép (100% Offline) | ❌ Chặn |
| **AI Upscale Ảnh** | ✅ Trừ Token ví | ❌ Yêu cầu Đăng nhập | ❌ Yêu cầu License | ❌ Tạm dừng (Cần Server) | ❌ Tạm dừng |
| **Duyệt tệp Cloud** | ✅ Tải danh mục | ❌ Yêu cầu Đăng nhập | ✅ Đọc bình thường | ⚠️ Chỉ đọc tệp trong Cache | ❌ Không khả dụng |
| **Tải lên / Tải xuống Cloud** | ✅ Hoạt động | ❌ Yêu cầu Đăng nhập | ✅ Hoạt động | ❌ Tạm dừng hàng đợi | ❌ Không khả dụng |
| **Chia sẻ liên kết** | ✅ Tạo link mới | ❌ Yêu cầu Đăng nhập | ✅ Hoạt động | ❌ Không khả dụng | ❌ Không khả dụng |
| **Quản lý Team** | ✅ Xem thành viên | ❌ Yêu cầu Đăng nhập | ✅ Xem bình thường | ❌ Không khả dụng | ❌ Không khả dụng |

> [!IMPORTANT]
> **Quy tắc cốt lõi:** Các tính năng biên tập video tự động cốt lõi của 2TOOLNE AutoEdit (A0, A1, A2, SubtitleLayoutEngine, Render Queue) **hoạt động 100% ngoại tuyến trên máy tính** trong suốt thời gian 72 giờ grace period mà không hề đòi hỏi phải có mạng hay tài khoản web!

---

## 16. REQUIRED BACKEND CHANGES

Các hạng mục bổ sung phía máy chủ web PHP (`website/`):
1. **Hoàn thiện Endpoint Cấp Token PKCE (`POST /api/v1/auth/token`):**
   - Tiếp nhận `code`, `code_verifier`, đối soát `code_challenge` SHA-256 đã lưu trong bảng `app_auth_sessions`.
   - Cấp phát cặp `access_token` và `refresh_token` cho ứng dụng Desktop.
2. **Bổ Sung Endpoint Làm Mới Phiên (`POST /api/v1/auth/refresh`):**
   - Tiếp nhận `refresh_token`, cấp mới `access_token` định kỳ mà không bắt người dùng đăng nhập lại.
3. **Chuẩn Hóa Bộ API Chia Sẻ Liên Kết (`/api/v1/cloud/shares`):**
   - `POST /api/v1/cloud/spaces/{spaceId}/shares`: Tạo liên kết chia sẻ (tùy chọn thời hạn).
   - `GET /api/v1/cloud/shares/{token}`: Endpoint công khai cho khách xem và tải tệp.
   - `POST /api/v1/cloud/shares/{id}/revoke`: Thu hồi liên kết lập tức.
4. **Cổng Chặn Token Upscale Bắt Buộc:**
   - Cập nhật `/api/v1/credits/reserve` và `/api/v1/credits/commit`: Yêu cầu phải có `auth_token` hợp lệ, xác thực danh tính người dùng hoặc team workspace trước khi cho phép giữ/trừ token.

---

## 17. REQUIRED DESKTOP CHANGES

Các hạng mục nâng cấp phía ứng dụng Electron Desktop (`apps/capcut-v2/desktop/`):
1. **Loại Bỏ Modal Nhập Mật Khẩu Web — Chuyển Sang Browser Quick Login:**
   - Gỡ bỏ `modalLogin` với các ô nhập password trực tiếp.
   - Thay thế bằng luồng gọi trình duyệt mặc định OS (`shell.openExternal`) kèm máy chủ Loopback HTTP Server và đăng ký giao thức `2toolne://`.
2. **Tái Cấu Trúc Khung Màn Hình "Tài Khoản" (Account Hub):**
   - Tích hợp gọn gàng 4 phân vùng trong 1 màn hình duy nhất:
     - Thẻ 1: **Tài Khoản Web** (Avatar, Email, Tên, Nút [Đăng xuất]).
     - Thẻ 2: **Số Dư Token** (Số lượt ảnh hiện có, Nút [Nạp thêm]).
     - Thẻ 3: **Bản Quyền Phần Mềm** (Trạng thái PRO, Ngày hết hạn, Nút [Nhập mã / Quản lý máy]).
     - Thẻ 4: **Không Gian Làm Việc (Workspace)** (Bộ chọn chuyển đổi Personal / Team).
3. **Đóng Chặt Cổng Chặn AI Upscale:**
   - Khi người dùng bấm [Upscale]: Kiểm tra đăng nhập -> Tra cứu số dư -> Gọi `/api/v1/credits/reserve` -> Chạy upscale NCNN -> Gọi `/api/v1/credits/commit` kèm `idempotency_key` duy nhất -> Hoàn trả qua `/api/v1/credits/release` nếu gặp lỗi.
4. **Phát Triển Giao Diện Tab "☁ Cloud":**
   - Bổ sung tab Cloud trên thanh menu chính.
   - Tích hợp bộ duyệt cây thư mục ảo, thanh hạn mức dung lượng, thanh breadcrumbs điều hướng, nút tải lên, tải xuống và nút tạo link chia sẻ.

---

## 18. DATABASE CHANGES IF ANY

Để đáp ứng đầy đủ tính năng Đăng nhập an toàn PKCE, Quản lý liên kết chia sẻ và Ví token theo Workspace, cơ sở dữ liệu MySQL `ecxaebka_bot` cần bổ sung một số cấu trúc phụ trợ:

```sql
-- 1. Bổ sung trường PKCE cho bảng app_auth_sessions
ALTER TABLE `app_auth_sessions`
  ADD COLUMN `code` VARCHAR(64) NULL DEFAULT NULL AFTER `challenge`,
  ADD COLUMN `code_challenge` VARCHAR(128) NULL DEFAULT NULL AFTER `code`,
  ADD COLUMN `code_challenge_method` VARCHAR(16) NOT NULL DEFAULT 'S256' AFTER `code_challenge`,
  ADD COLUMN `used_at` DATETIME NULL DEFAULT NULL AFTER `status`,
  ADD UNIQUE KEY `uk_app_auth_code` (`code`);

-- 2. Bảng quản lý liên kết chia sẻ tệp/thư mục công khai
CREATE TABLE IF NOT EXISTS `cloud_shares` (
  `id` VARCHAR(64) NOT NULL,
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `item_type` ENUM('FILE', 'FOLDER') NOT NULL DEFAULT 'FILE',
  `item_id` VARCHAR(64) NOT NULL,
  `share_token` VARCHAR(64) NOT NULL,
  `share_token_hash` VARCHAR(64) NOT NULL,
  `access_level` ENUM('VIEW_ONLY', 'ALLOW_DOWNLOAD') NOT NULL DEFAULT 'ALLOW_DOWNLOAD',
  `expires_at` DATETIME NULL DEFAULT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `created_by_user_id` VARCHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_share_token_hash` (`share_token_hash`),
  KEY `idx_shares_space_item` (`cloud_space_id`, `item_id`),
  CONSTRAINT `fk_shares_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bảng quản lý Refresh Token phiên Desktop
CREATE TABLE IF NOT EXISTS `desktop_refresh_tokens` (
  `id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `device_id` VARCHAR(128) NOT NULL,
  `refresh_token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refresh_token_hash` (`refresh_token_hash`),
  KEY `idx_drt_user_device` (`user_id`, `device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bổ sung trường sở hữu Team cho bảng credit_wallets (đảm bảo không phá vỡ dữ liệu cũ)
ALTER TABLE `credit_wallets`
  ADD COLUMN `workspace_id` VARCHAR(64) NULL DEFAULT NULL AFTER `user_id`,
  ADD KEY `idx_cw_workspace` (`workspace_id`);
```

---

## 19. SECURITY THREAT REVIEW

Đánh giá 11 mối đe dọa an ninh then chốt theo phương pháp phân tích nguy cơ OWASP:

| Mối Đe Dọa An Ninh | Mức Độ Rủi Ro | Biện Pháp Bảo Vệ Hiện Tại | Lỗ Hổng (Gap) Còn Tồn Tại | Khuyến Nghị Kiến Trúc Khắc Phục |
| :--- | :---: | :--- | :--- | :--- |
| **1. Browser Login Callback Hijacking** | `HIGH` | Chưa có | Nếu dùng protocol `2toolne://` đơn thuần, app độc hại trên máy có thể đăng ký nhận code. | **Bắt buộc dùng PKCE (RFC 7636).** Kẻ tấn công dù trộm được code cũng không có `code_verifier` để đổi token. |
| **2. Authorization Replay** | `HIGH` | Chưa có | Code có thể bị dùng lại nhiều lần nếu máy chủ không kiểm tra. | **One-time Burn:** Máy chủ hủy `auth_code` ngay ở request đầu tiên. Đặt thời gian sống (TTL) cực ngắn: 60 giây. |
| **3. Token Theft on Storage** | `CRITICAL` | `SecureStorage` (safeStorage) | Một số module renderer có thể ghi nhầm token vào `localStorage`. | **Cấm triệt để localStorage:** Toàn bộ token chỉ lưu trong `userData/secure_store.bin` mã hóa bằng DPAPI/Keychain. |
| **4. Electron Renderer Exposure** | `HIGH` | `contextIsolation: true` | Preload có thể để lộ hàm nhạy cảm qua `window.autoedit`. | Giới hạn tối thiểu các hàm trong `preload.js`, không bao giờ truyền token trực tiếp sang ngữ cảnh DOM. |
| **5. XSS Exposing Access Token** | `HIGH` | Strict CSP trên trang | Nếu trang web bị XSS, script có thể đọc được cookie thông thường. | Dùng cờ `HttpOnly` cho web cookie, dùng Bearer Token cách ly trong bộ nhớ Main Process của Desktop. |
| **6. Share-Link Enumeration** | `MEDIUM` | Chưa có | Nếu link dùng ID tăng dần (`/share/123`), kẻ xấu có thể quét cạn dữ liệu. | **Opaque Cryptographic Token:** Dùng chuỗi ngẫu nhiên 32 byte (`random_bytes(32)`) với không gian mẫu 2^256 không thể quét cạn. |
| **7. Team Privilege Escalation** | `HIGH` | `CloudFilesController` kiểm tra role | Thành viên gửi request giả mạo quyền Admin để xóa tệp người khác. | **Server-side Authorization:** Mọi mutation API bắt buộc xác thực lại vai trò từ bảng `team_members` trước khi thực thi. |
| **8. IDOR on Cloud File IDs** | `CRITICAL` | `CloudAuthHelper::authorizeSpaceAccess` | Người dùng đoán `file_id` của khách hàng khác để tải xuống. | **Ràng buộc chéo Space ID:** Máy chủ luôn truy vấn `WHERE id = :file_id AND cloud_space_id = :user_space_id`. |
| **9. Workspace Isolation Failure**| `HIGH` | Đã thiết kế cấu trúc `cloud_spaces` | Thành viên Team A nhìn thấy thư mục của Team B. | Phân vùng thư mục vật lý trên Google Drive theo cấu trúc: `Team_{team_id}/{user_id}`, không dùng chung thư mục gốc. |
| **10. Double Token Charge** | `HIGH` | `CreditsController` có `idempotency_key` | Desktop bị rớt mạng khi commit token dẫn đến trừ 2 lần. | **Unique Idempotency Key:** Client sinh UUID duy nhất cho từng lệnh Upscale, máy chủ từ chối trừ tiền nếu trùng key. |
| **11. Device / License Spoofing** | `MEDIUM` | Ed25519 asymmetric signature | Người dùng sửa đổi giờ hệ thống máy tính để gian lận thời hạn grace. | **Trusted Server Time:** So sánh thời gian file hệ thống và thời gian nhúng trong chữ ký số Ed25519; chặn lùi giờ máy tính. |

---

## 20. IMPLEMENTATION ROADMAP

Lộ trình triển khai được quy hoạch thành **6 giai đoạn tuần tự (6 Phased Milestones)**, đảm bảo tiến độ vững chắc, từng bước có thể nghiệm thu độc lập và **tuyệt đối không gây gián đoạn hệ thống đang vận hành**:

```
┌───────────┐     ┌───────────┐     ┌───────────┐     ┌───────────┐     ┌───────────┐     ┌───────────┐
│  PHASE 1  │ ──► │  PHASE 2  │ ──► │  PHASE 3  │ ──► │  PHASE 4  │ ──► │  PHASE 5  │ ──► │  PHASE 6  │
│ Phân tách │     │ Đăng nhập │     │ Cổng chặn │     │ Trình     │     │ Chia sẻ   │     │ Đội nhóm  │
│ License & │     │ Trình     │     │ Token &   │     │ duyệt tệp │     │ Liên kết  │     │ & Không   │
│ Account UI│     │ duyệt PKCE│     │ Upscale   │     │ Ảo Cloud  │     │ Công khai │     │ gian Team │
└───────────┘     └───────────┘     └───────────┘     └───────────┘     └───────────┘     └───────────┘
```

- **GIAI ĐOẠN 1 (PHASE 1): Phân Tách Rạch Ròi Bản Quyền & Tài Khoản Trên Desktop**
  - Tách giao diện "Bản Quyền" và "Tài Khoản" thành 2 phân vùng trực quan rõ rệt trong màn hình cài đặt.
  - Chuẩn hóa mô hình `AccountState` trong Main process: lưu trữ tách biệt giữa `entitlement_envelope` (License) và `user_session` (Account).
  - Nghiệm thu: Người dùng hiểu rõ việc nhập License Key khác hoàn toàn với việc đăng nhập tài khoản Web.

- **GIAI ĐOẠN 2 (PHASE 2): Triển Khai Đăng Nhập Nhanh Qua Trình Duyệt (Browser Quick Login)**
  - Phía Máy Chủ: Bổ sung endpoint đổi code PKCE `POST /api/v1/auth/token` và endpoint làm mới `POST /api/v1/auth/refresh`.
  - Phía Desktop: Triển khai bộ lắng nghe Localhost Loopback HTTP Server trên cổng ngẫu nhiên, sinh mã PKCE, mở trình duyệt mặc định qua `shell.openExternal`.
  - Gỡ bỏ hoàn toàn modal nhập mật khẩu trong Desktop.
  - Nghiệm thu: Đăng nhập 1-click mượt mà qua trình duyệt web mà không cần gõ mật khẩu vào ứng dụng Desktop.

- **GIAI ĐOẠN 3 (PHASE 3): Cổng Chặn Token An Toàn Cho Tác Vụ Upscale Ảnh**
  - Chỉnh sửa `apps/capcut-v2/desktop/src/main/index.js` (`upscale:process-images`):
    - Kiểm tra đăng nhập tài khoản trước khi xử lý.
    - Gọi `/api/v1/credits/reserve` để khóa giữ token trước khi gọi engine NCNN Vulkan.
    - Gọi `/api/v1/credits/commit` kèm `idempotency_key` duy nhất khi hoàn tất từng ảnh.
    - Gọi `/api/v1/credits/release` giải phóng token nếu tiến trình gặp sự cố.
  - Nghiệm thu: Xóa bỏ 100% lỗ hổng Upscale miễn phí trái phép; số dư hiển thị chính xác theo thời gian thực.

- **GIAI ĐOẠN 4 (PHASE 4): Trình Duyệt Tệp Ảo Đám Mây Trên Desktop (Cloud Explorer)**
  - Xây dựng Tab giao diện "☁ Cloud" trên Desktop AutoEdit.
  - Tích hợp các API duyệt thư mục ảo, thanh điều hướng Breadcrumb, tạo thư mục, đổi tên, xóa tệp.
  - Triển khai hàng đợi tải lên trực tiếp (Resumable Upload) lên Google Drive thông qua session URL máy chủ cấp.
  - Nghiệm thu: Người dùng tải lên, tải xuống và quản lý tài sản số đám mây mượt mà ngay trong app.

- **GIAI ĐOẠN 5 (PHASE 5): Tính Năng Chia Sẻ Bằng Liên Kết (Share Links)**
  - Tạo bảng cơ sở dữ liệu `cloud_shares`.
  - Xây dựng modal "Chia sẻ liên kết" trên Desktop với các tùy chọn: Cho phép tải xuống, thời hạn 7 ngày / 30 ngày / Vô thời hạn, nút sao chép link và nút thu hồi link.
  - Xây dựng trang xem tệp công khai trên Web Portal (`/share/{token}`).
  - Nghiệm thu: Khách hàng bên ngoài mở link xem và tải video/ảnh thành phẩm nhanh chóng, an toàn.

- **GIAI ĐOẠN 6 (PHASE 6): Không Gian Làm Việc Đội Nhóm & Phân Quyền (Team / Workspace)**
  - Bật cờ tính năng `TEAM_PLANS_ENABLED = true`.
  - Triển khai bộ chọn không gian làm việc (Workspace Switcher) trên Desktop: chuyển đổi giữa Personal Space và Team Space.
  - Áp dụng mô hình trừ token `PERSONAL_AND_WORKSPACE` theo ngữ cảnh làm việc được chọn.
  - Cấp quyền sử dụng ứng dụng Desktop tự động theo số ghế (`app_key_count`) của gói Team.
  - Nghiệm thu: Đội nhóm studio cộng tác trơn tru trên cùng một kho lưu trữ và cùng một quỹ token tập trung.

---
*Báo cáo được hoàn thành và xác thực độc lập theo chỉ thị kiến trúc bảo mật sản phẩm 2TOOLNE AutoEdit V2.*
