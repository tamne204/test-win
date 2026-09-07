# 2TOOLNE CLOUD — ĐẶC TẢ GIAO DIỆN LẬP TRÌNH RESTful API V2
**Tài liệu:** `docs/CLOUD_API_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Cập nhật V2:** Tái cấu trúc toàn bộ endpoint theo ngữ cảnh Không gian lưu trữ (`/spaces/{spaceId}/*`), bổ sung các API Quản trị Storage Pool chuyên sâu (Drain, Refresh, Safe Disconnect) và API Điều chỉnh hạn mức có kiểm toán (`quota-adjustments`).

---

## 1. DANH SÁCH ENDPOINT KHÁCH HÀNG (CUSTOMER CLOUD APIS)

### 1.1. Lấy Danh Sách Không Gian Lưu Trữ Khả Dụng (`GET /api/v1/cloud/spaces`)
- **Mô tả:** Trả về danh sách tất cả các Cloud Spaces mà người dùng hiện tại có quyền truy cập (gồm 1 Personal Space và các Team Spaces nếu có).
- **Phản hồi thành công (200 OK):**
  ```json
  {
    "success": true,
    "spaces": [
      {
        "id": "cs_usr_15",
        "name": "Không Gian Cá Nhân (My Cloud)",
        "owner_type": "USER",
        "role": "OWNER",
        "status": "ACTIVE",
        "effective_quota_bytes": 26843545600,
        "used_bytes": 8589934592,
        "usage_percent": 32.0,
        "formatted": {
          "quota": "25.0 GB",
          "used": "8.0 GB"
        }
      },
      {
        "id": "cs_team_studio_abc",
        "name": "Studio Wedding ABC",
        "owner_type": "TEAM",
        "team_id": "team_1029",
        "role": "MEMBER",
        "status": "ACTIVE",
        "effective_quota_bytes": 53687091200,
        "used_bytes": 21474836480,
        "usage_percent": 40.0,
        "formatted": {
          "quota": "50.0 GB",
          "used": "20.0 GB"
        }
      }
    ]
  }
  ```

---

### 1.2. Tra Cứu Hạn Mức Của Một Không Gian (`GET /api/v1/cloud/spaces/{spaceId}/quota`)
- **Mô tả:** Lấy thông tin hạn mức phân giải động, số byte đã dùng, đang tạm khóa và trạng thái cảnh báo (`ACTIVE` hoặc `OVER_QUOTA`).
- **Phản hồi thành công (200 OK):**
  ```json
  {
    "success": true,
    "space_id": "cs_team_studio_abc",
    "status": "ACTIVE",
    "is_over_quota": false,
    "base_quota_bytes": 53687091200,
    "addon_quota_bytes": 0,
    "admin_adjustment_bytes": 10737418240,
    "effective_quota_bytes": 64424509440,
    "used_bytes": 21474836480,
    "reserved_bytes": 1073741824,
    "available_bytes": 41875931136,
    "formatted": {
      "base_quota": "50.0 GB",
      "admin_adjustment": "+10.0 GB",
      "effective_quota": "60.0 GB",
      "used": "20.0 GB",
      "available": "39.0 GB"
    }
  }
  ```

---

### 1.3. Duyệt Tệp & Thư Mục Của Không Gian (`GET /api/v1/cloud/spaces/{spaceId}/files`)
- **Tham số:** `folder_id` (tùy chọn), `search`, `page`, `limit`.
- **Phản hồi thành công (200 OK):** Trả về cây thư mục và danh sách tệp của riêng `spaceId` đó.

---

### 1.4. Tạo Thư Mục Mới (`POST /api/v1/cloud/spaces/{spaceId}/folders`)
- **Body:** `{"name": "Batch_4K_Outputs", "parent_id": "fld_root"}`
- **Hành động máy chủ:** Tự động gán `created_by_user_id = auth_user_id` và `cloud_space_id = spaceId`.

---

### 1.5. Khởi Tạo Phiên Tải Lên Trực Tiếp (`POST /api/v1/cloud/spaces/{spaceId}/uploads/create`)
- **Body:**
  ```json
  {
    "filename": "wedding_4k_01.png",
    "size_bytes": 15728640,
    "mime_type": "image/png",
    "folder_id": "fld_batch01",
    "project_id": "proj_2026_wedding",
    "app_id": "UPSCALE",
    "checksum_sha256": "e3b0c442...",
    "idempotency_key": "res_up_wedding_01_hash"
  }
  ```
- **Xử lý nguyên tử:**
  - Kiểm tra `cloud_space_quotas` của `spaceId`.
  - Khóa dòng bi quan: `used_bytes + reserved_bytes + size_bytes <= effective_quota_bytes`.
  - Nếu `space.status === 'OVER_QUOTA'` $\rightarrow$ Từ chối với lỗi `403 FORBIDDEN` (`SPACE_OVER_QUOTA`).
  - Chọn tài khoản Google Drive tối ưu trong Pool theo `MOST_FREE_SPACE`.
  - Trả về `upload_session_url` của Google cho Client.

---

### 1.6. Hoàn Tất & Xác Thực Upload (`POST /api/v1/cloud/spaces/{spaceId}/uploads/{uploadId}/finalize`)
- **Mô tả:** Client gọi sau khi byte cuối cùng truyền xong tới Google. Máy chủ xác minh kích thước thực tế trên Google Drive, cộng `used_bytes`, trừ `reserved_bytes` trên `cloud_space_quotas` và kích hoạt tệp `ACTIVE`.

---

### 1.7. Tải Xuống & Xem Trước Tệp Tin
- **Download:** `GET /api/v1/cloud/files/{fileId}/download` (Hỗ trợ `Range: bytes=...`, kiểm tra quyền của người dùng đối với `cloud_space_id` chứa tệp).
- **Preview:** `GET /api/v1/cloud/files/{fileId}/preview` (Truyền phát ảnh nhỏ / thumbnail).

---

## 2. DANH MỤC ENDPOINT QUẢN TRỊ VIÊN (ADMIN STORAGE POOL & QUOTA APIS)

Dành riêng cho Quản trị viên trên `license_admin.php` (Yêu cầu quyền `perm_storage_pool` hoặc `super_admin`).

### 2.1. Giám Sát Bể Lưu Trữ Đa Ổ Đĩa (`GET /api/v1/admin/cloud/storage-pool`)
- **Phản hồi thành công (200 OK):**
  ```json
  {
    "success": true,
    "pool_metrics": {
      "pool_total_physical_tb": 15.0,
      "pool_used_physical_tb": 7.4,
      "pool_free_physical_tb": 7.6,
      "pool_reserved_gb": 42.0,
      "pool_safety_buffer_tb": 1.5,
      "pool_allocatable_free_tb": 6.0,
      "total_logical_quota_allocated_tb": 10.2,
      "total_logical_used_tb": 7.4,
      "overcommit_ratio": 0.68,
      "active_accounts_count": 2,
      "draining_accounts_count": 1,
      "error_accounts_count": 0
    },
    "accounts": [
      {
        "id": "sa_drive_01",
        "display_alias": "Storage Master Alpha",
        "provider": "GOOGLE_DRIVE",
        "status": "ACTIVE",
        "health_status": "HEALTHY",
        "total_bytes": 5497558138880,
        "used_bytes": 2308974418329,
        "physical_free_bytes": 3188583720551,
        "reserved_bytes": 10737418240,
        "allocatable_free_bytes": 2628080509652,
        "usage_percent": 42.0,
        "safety_reserve_percent": 10,
        "active_file_count": 12450,
        "last_usage_refresh": "2026-09-05 11:00:00",
        "last_health_check": "2026-09-05 11:00:00"
      }
    ]
  }
  ```

---

### 2.2. Các Thao Tác Trên Tài Khoản Google Drive Vật Lý
- **Làm mới dung lượng thực tế:** `POST /api/v1/admin/cloud/storage-accounts/{id}/refresh`
- **Chuyển sang chế độ rút dần (Drain):** `POST /api/v1/admin/cloud/storage-accounts/{id}/drain`
- **Vô hiệu hóa tạm thời:** `POST /api/v1/admin/cloud/storage-accounts/{id}/disable`
- **Cấp lại quyền OAuth:** `POST /api/v1/admin/cloud/storage-accounts/{id}/reauthorize`
- **Ngắt kết nối an toàn (Safe Disconnect):** `DELETE /api/v1/admin/cloud/storage-accounts/{id}`
  - *Quy tắc kiểm tra:* Nếu `active_file_count > 0`, trả về lỗi `409 Conflict`:  
    `{"success": false, "error": "Không thể ngắt kết nối! Còn 1.240 tệp tin đang lưu trữ trên ổ đĩa này. Vui lòng chuyển dữ liệu trước.", "active_files": 1240}`.

---

### 2.3. Điều Chỉnh Hạn Mức Có Kiểm Toán (`POST /api/v1/admin/cloud/spaces/{id}/quota-adjustments`)
- **Body gửi lên:**
  ```json
  {
    "delta_bytes": 10737418240,
    "type": "ADMIN_GRANT",
    "reason": "Bồi thường sự cố rớt mạng cho khách hàng",
    "force_over_quota": false
  }
  ```
  *(Để giảm dung lượng, truyền số âm: `"delta_bytes": -5368709120` và `"type": "ADMIN_REDUCTION"`).*
- **Phản hồi thành công (200 OK):**
  ```json
  {
    "success": true,
    "space_id": "cs_usr_15",
    "old_effective_quota": "25.0 GB",
    "new_effective_quota": "35.0 GB",
    "status": "ACTIVE",
    "ledger_entry_id": "cqa_9a8b7c"
  }
  ```
- **Xem lịch sử điều chỉnh:** `GET /api/v1/admin/cloud/spaces/{id}/quota-history` $\rightarrow$ Trả về toàn bộ lịch sử các lần tăng giảm dung lượng của Space đó từ sổ cái `cloud_quota_adjustments`.
