# 2TOOLNE CLOUD — LỘ TRÌNH TRIỂN KHAI THEO GIAI ĐOẠN (ROADMAP V2)
**Tài liệu:** `docs/CLOUD_IMPLEMENTATION_ROADMAP.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Cập nhật:** V2 Architecture Roadmap (Nền tảng Cloud Space, Quản trị Storage Pool & Hạn mức Admin, Vòng đời Rút cạn An toàn, Team Plan Backend sẵn sàng)  
**Nguyên tắc triển khai:** Chia nhỏ thành 15 giai đoạn độc lập (Phased Delivery), kiểm thử cuốn chiếu, đảm bảo không làm gián đoạn các tính năng đang chạy (Zero Regression).

---

## 1. BẢN ĐỒ PHỤ THUỘC KIẾN TRÚC V2 (DEPENDENCY GRAPH V2)

```
[Phase 0: Kiểm toán hệ thống hiện tại] (ĐÃ HOÀN THÀNH)
         │
         ▼
[Phase 1: Cơ sở dữ liệu V2 — Nền tảng Cloud Space & Team]
         │
         ▼
[Phase 2: StorageProvider Interface & AES-256 Secret Engine]
         │
         ▼
[Phase 3: GoogleDriveStorageAdapter & Single Drive Connect]
         │
         ▼
[Phase 4: Virtual File System & APIs theo phạm vi Cloud Space]
         │
         ▼
[Phase 5: Động cơ Hạn mức Space Quota & Khóa dòng nguyên tử]
         │
         ▼
[Phase 6: API Tải lên trực tiếp Resumable theo Cloud Space]
         │
         ├─────────────────────────────────────────┐
         ▼                                         ▼
[Phase 7: Web File Manager UI              [Phase 8: Desktop Cloud Queue
 (Space Switcher & Over-Quota Banner)]      (Space Selector & Over-Quota Pause)]
         │                                         │
         └────────────────────┬────────────────────┘
                              │
                              ▼
        [Phase 9: Bộ Cấp Phát Đa Tài Khoản (MOST_FREE_SPACE & 10% Buffer)]
                              │
                              ▼
        [Phase 10: Bảng Điều Khiển Quản Trị Storage Pool & Overcommit]
                              │
                              ▼
        [Phase 11: Sổ Cái Điều Chỉnh Hạn Mức Admin (Quota Ledger UI)]
                              │
                              ▼
        [Phase 12: Vòng Đời Rút Cạn An Toàn & Safe Disconnect Guard]
                              │
                              ▼
        [Phase 13: Thùng Rác Ảo & Tự Động Dọn Dẹp Sau 30 Ngày]
                              │
                              ▼
        [Phase 14: Tích Hợp Team Plan Backend (Cờ TEAM_PLANS_ENABLED = false)]
                              │
                              ▼
        [Phase 15: Kiểm Thử Bảo Mật Toàn Diện & Release Gate]
```

---

## 2. ĐẶC TẢ CHI TIẾT 15 GIAI ĐOẠN TRIỂN KHAI (PHASE BREAKDOWN V2)

### GIAI ĐOẠN 0: KIỂM TOÁN HỆ THỐNG HIỆN TẠI (PHASE 0 - HOÀN THÀNH)
- Kiểm toán toàn diện mã nguồn, phiên bản runtime (PHP 7.4.33, MySQL 5.7.41), cấu trúc cơ sở dữ liệu và hạn mức hosting 200MB.
- **Kết quả:** Đã hoàn thành và lưu trữ tại `docs/CLOUD_CURRENT_SYSTEM_AUDIT.md`.

---

### GIAI ĐOẠN 1: CƠ SỞ DỮ LIỆU V2 — NỀN TẢNG CLOUD SPACE & TEAM (PHASE 1 - P0)
- **Công việc:**
  - Chạy kịch bản migration bổ sung các bảng V2 vào MySQL `ecxaebka_bot`: `teams`, `team_members`, `cloud_spaces`, `cloud_space_quotas`, `cloud_quota_adjustments`, `storage_accounts`, `cloud_folders`, `cloud_files`, `cloud_upload_reservations`, `cloud_trash`, `cloud_storage_health`.
  - Khởi tạo tự động bản ghi `cloud_spaces` loại `PERSONAL` cho từng người dùng hiện hữu.
  - Bổ sung bảng `cloud_backup_jobs` có trường `cloud_space_id` vào SQLite trên máy khách Desktop.
- **Tiêu chuẩn nghiệm thu:** Toàn bộ bảng tạo thành công với đúng kiểu dữ liệu, khóa ngoại và chỉ mục tương thích MySQL 5.7.

---

### GIAI ĐOẠN 2: LỚP TRỪU TƯỢNG HÓA NHÀ CUNG CẤP & MÃ HÓA BẢO VỆ BÍ MẬT (PHASE 2 - P0)
- **Công việc:**
  - Xây dựng `website/api/v1/storage/StorageProviderInterface.php`.
  - Xây dựng `website/api/v1/storage/CryptoService.php`: Cung cấp hàm mã hóa/giải mã AES-256-GCM bảo vệ Refresh Token.
  - Thiết lập tập tin khóa chủ `storage/secrets/cloud_master.key` với phân quyền `0600`.
- **Tiêu chuẩn nghiệm thu:** Test mã hóa và giải mã chuỗi token khớp 100%, chống sửa đổi dữ liệu qua Authentication Tag.

---

### GIAI ĐOẠN 3: KẾT NỐI TÀI KHOẢN GOOGLE DRIVE ĐẦU TIÊN (PHASE 3 - P0)
- **Công việc:**
  - Xây dựng `website/api/v1/storage/GoogleDriveStorageAdapter.php`.
  - Thực thi các phương thức: `createUploadSession`, `verifyUploadedFile`, `getCapacityUsage`, `healthCheck`.
  - Đăng ký tài khoản Google Drive đầu tiên của operator vào bảng `storage_accounts`.
- **Tiêu chuẩn nghiệm thu:** Gọi API Google Drive thành công, tự động tạo thư mục gốc, truy vấn đúng dung lượng tổng và trống.

---

### GIAI ĐOẠN 4: HỆ THỐNG TỆP ẢO HÓA & APIS THEO PHẠM VI CLOUD SPACE (PHASE 4 - P0)
- **Công việc:**
  - Xây dựng Controller `website/api/v1/controllers/CloudFilesController.php`.
  - Triển khai các endpoint theo chuẩn RESTful scoped: `GET /spaces/{spaceId}/files`, `POST /spaces/{spaceId}/folders`, `PATCH /folders/{id}`, `DELETE /folders/{id}`.
  - Xác thực quyền sở hữu hoặc thành viên của Space trước khi xử lý.
- **Tiêu chuẩn nghiệm thu:** Tạo được cấu trúc thư mục lồng nhau; cô lập 100% dữ liệu giữa các Space khác nhau.

---

### GIAI ĐOẠN 5: ĐỘNG CƠ HẠN MỨC SPACE QUOTA & KHÓA DÒNG NGUYÊN TỬ (PHASE 5 - P0)
- **Công việc:**
  - Xây dựng `website/api/v1/storage/CloudQuotaManager.php`.
  - Tính toán hạn mức hiệu lực: $\text{effective\_quota} = \text{base\_plan\_quota} + \sum \text{delta\_bytes}$.
  - Thực thi kiểm tra hạn mức nguyên tử (`reserveQuota`, `commitReservation`, `releaseReservation`) sử dụng `SELECT ... FOR UPDATE`.
  - Tự động phát hiện trạng thái `OVER_QUOTA` khi dung lượng sử dụng vượt quá hạn mức hiệu lực.
- **Tiêu chuẩn nghiệm thu:** Chặn đứng race condition khi upload song song; ngăn chặn chính xác khi Space bị quá hạn mức.

---

### GIAI ĐOẠN 6: API TẢI LÊN TRỰC TIẾP RESUMABLE THEO CLOUD SPACE (PHASE 6 - P0)
- **Công việc:**
  - Thực thi `POST /spaces/{spaceId}/uploads/create`, `POST /uploads/{id}/finalize`, `POST /uploads/{id}/abort`.
  - Trả về Google Resumable Session URL cho máy khách.
- **Tiêu chuẩn nghiệm thu:** Tải thành công tệp thử nghiệm 100 MB trực tiếp lên Google Drive. Kích thước và mã SHA-256 khớp tuyệt đối. **Ổ cứng hosting tiêu tốn đúng 0 bytes**.

---

### GIAI ĐOẠN 7: GIAO DIỆN TRÌNH QUẢN LÝ TỆP WEB VỚI BỘ CHUYỂN ĐỔI SPACE (PHASE 7 - P1)
- **Công việc:**
  - Tích hợp Tab `#tab-cloud-storage` vào `website/index.php`.
  - Xây dựng Space Switcher Dropdown (`[Personal | Team ABC]`).
  - Hiển thị danh sách tệp chia sẻ theo nhóm, hiển thị tên người tải lên.
  - Tích hợp Banner cảnh báo khi Space rơi vào tình trạng `OVER_QUOTA`.
- **Tiêu chuẩn nghiệm thu:** Chuyển đổi mượt mà giữa các Space; giao diện chuẩn Responsive không tràn viền trên Mobile.

---

### GIAI ĐOẠN 8: HÀNG ĐỢI SAO LƯU TRÊN DESKTOP & BỘ CHỌN SPACE (PHASE 8 - P1)
- **Công việc:**
  - Cập nhật module `apps/desktop/src/main/cloud_backup_queue.ts` hỗ trợ `cloud_space_id`.
  - Bổ sung bộ chọn Cloud Space trong modal thiết lập dự án Desktop.
  - Tạm dừng tác vụ nhẹ nhàng khi gặp lỗi Over-Quota (`PAUSED_OVER_QUOTA`) mà không làm nghẽn tiến trình GPU.
- **Tiêu chuẩn nghiệm thu:** GPU hoàn tất lưu ảnh cục bộ an toàn; khôi phục Resume upload mượt mà khi kết nối lại mạng.

---

### GIAI ĐOẠN 9: BỘ CẤP PHÁT ĐA TÀI KHOẢN (MULTI-ACCOUNT ALLOCATOR) (PHASE 9 - P1)
- **Công việc:**
  - Xây dựng thuật toán `StorageAllocator::selectAccount()` theo chiến lược `MOST_FREE_SPACE`.
  - Tích hợp bộ đệm an toàn `STORAGE_SAFETY_PERCENT = 10%`.
  - Tự động loại trừ các tài khoản ở trạng thái `DRAINING`, `ERROR`, `OFFLINE`, `DISABLED`, `DISCONNECTED`.
- **Tiêu chuẩn nghiệm thu:** Phân bổ tải chính xác tới tài khoản Google Drive còn trống nhiều nhất.

---

### GIAI ĐOẠN 10: BẢNG ĐIỀU KHIỂN QUẢN TRỊ STORAGE POOL & OVERCOMMIT (PHASE 10 - P1)
- **Công việc:**
  - Xây dựng giao diện Storage Pool Dashboard trong `website/license_admin.php`.
  - Hiển thị 9 chỉ số đo lường vận hành: `POOL_TOTAL_PHYSICAL`, `POOL_USED_PHYSICAL`, `POOL_FREE_PHYSICAL`, `POOL_RESERVED`, `POOL_SAFETY_BUFFER`, `POOL_ALLOCATABLE_FREE`, `TOTAL_LOGICAL_QUOTA_ALLOCATED`, `TOTAL_LOGICAL_USED`, `OVERCOMMIT_RATIO`.
  - Hiển thị danh sách tài khoản, tình trạng kết nối và số tệp tin đang lưu trữ (`active_file_count`).
- **Tiêu chuẩn nghiệm thu:** Cập nhật số liệu tức thời; hiển thị cảnh báo trực quan khi tỷ lệ Overcommit vượt ngưỡng an toàn.

---

### GIAI ĐOẠN 11: SỔ CÁI ĐIỀU CHỈNH HẠN MỨC ADMIN (QUOTA ADJUSTMENTS LEDGER) (PHASE 11 - P1)
- **Công việc:**
  - Xây dựng giao diện Quota Adjustments trong `website/license_admin.php`.
  - Modal điều chỉnh dung lượng (+/- GB) với trường nhập **Lý do bắt buộc**.
  - Triển khai API `POST /api/v1/cloud/admin/spaces/{spaceId}/adjust-quota`.
  - Bảng hiển thị lịch sử biến động bất biến từ `cloud_quota_adjustments`.
- **Tiêu chuẩn nghiệm thu:** Ghi vết chính xác từng lần điều chỉnh; cập nhật ngay lập tức `effective_quota` của Space.

---

### GIAI ĐOẠN 12: VÒNG ĐỜI RÚT CẠN AN TOÀN & SAFE DISCONNECT GUARD (PHASE 12 - P1)
- **Công việc:**
  - Triển khai vòng đời tài khoản: `ACTIVE` $\rightarrow$ `DRAINING` $\rightarrow$ `EMPTY / MIGRATED` $\rightarrow$ `DISCONNECTED`.
  - Xây dựng logic khóa bảo vệ Safe Disconnect Guard: Chặn tuyệt đối xóa/ngắt tài khoản nếu `active_file_count > 0`.
  - Xây dựng tiến trình di chuyển nền (`Background Draining Worker`) chuyển tệp sang tài khoản khác.
- **Tiêu chuẩn nghiệm thu:** Ngăn chặn thành công 100% các nỗ lực ngắt kết nối tài khoản còn chứa tệp tin.

---

### GIAI ĐOẠN 13: THÙNG RÁC ẢO HÓA & TỰ ĐỘNG DỌN DẸP THEO SPACE (PHASE 13 - P2)
- **Công việc:**
  - Xây dựng cơ chế chuyển tệp vào thùng rác (`DELETE /files/{id}`) và khôi phục (`POST /files/{id}/restore`).
  - Lập lịch dọn dẹp các tệp tin trong thùng rác đã quá 30 ngày (`purge_due_at`).
- **Tiêu chuẩn nghiệm thu:** Khôi phục tệp về đúng thư mục gốc; xóa vĩnh viễn tệp quá hạn trên cả cơ sở dữ liệu và Google Drive.

---

### GIAI ĐOẠN 14: TÍCH HỢP GÓI TEAM PLANS SẴN SÀNG Ở BACKEND (PHASE 14 - P2)
- **Công việc:**
  - Thiết lập cờ cấu hình hệ thống: `define('TEAM_PLANS_ENABLED', false);`.
  - Thiết kế cấu trúc gói mẫu "Team Starter": 2 thành viên / 50 GB lưu trữ dùng chung / 2 Desktop App Keys.
  - Ánh xạ App Key vào bảng `devices` dưới dạng các thiết bị kích hoạt hợp lệ (`max_devices`).
  - Giữ ẩn hoàn toàn giao diện mua gói Team phía khách hàng cho đến khi có quyết định thương mại hóa.
- **Tiêu chuẩn nghiệm thu:** Kiểm thử tạo và quản lý Team Space thành công ở cấp độ Backend mà không làm lộ giao diện ra ngoài.

---

### GIAI ĐOẠN 15: KIỂM THỬ BẢO MẬT TOÀN DIỆN & RELEASE GATE (PHASE 15 - RELEASE GATE)
- **Công việc:**
  - Kiểm thử thâm nhập (Penetration Test): Cố tình truy cập tệp của Space khác, cố tình gửi yêu cầu ngắt tài khoản còn tệp, tải tệp vượt hạn mức.
  - Rà soát điều khoản thương mại bắt buộc: `GOOGLE_DRIVE_COMMERCIAL_STORAGE_USAGE_REVIEW`.
- **Tiêu chuẩn nghiệm thu:** 100% kịch bản kiểm thử vượt qua; toàn bộ tài liệu và hệ thống sẵn sàng đi vào vận hành.

---

## 3. ĐỊNH NGHĨA SẢN PHẨM KHẢ DỤNG TỐI THIỂU V2 (MVP V2 DEFINITION)

Hệ thống MVP V2 có thể đưa vào vận hành thử nghiệm nội bộ ngay sau khi hoàn tất **từ Giai đoạn 1 đến Giai đoạn 8 & Giai đoạn 10, 11**:
- Kiến trúc nền tảng chuẩn Cloud Space (tách bạch rõ ràng với người dùng cá nhân).
- 1-2 Tài khoản Google Drive vật lý hoạt động trong Storage Pool.
- Quản trị viên theo dõi được chỉ số Overcommit và điều chỉnh được dung lượng khách hàng.
- Trình quản lý tệp Web File Manager hỗ trợ xem tệp và tải lên trực tiếp không tốn đĩa máy chủ.
- Hàng đợi sao lưu Desktop hoạt động ổn định và xử lý lỗi Over-Quota êm dịu.
