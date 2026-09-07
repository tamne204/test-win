# 2TOOLNE CLOUD — THIẾT KẾ CƠ SỞ DỮ LIỆU V2 (DATABASE PLAN V2)
**Tài liệu:** `docs/CLOUD_DATABASE_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Hệ quản trị:** MySQL / MariaDB 5.7.41 (Máy chủ Web `ecxaebka_bot`) & SQLite 3 WAL (Máy khách Desktop)  
**Cập nhật kiến trúc V2:** Thay thế mô hình hạn mức người dùng đơn lẻ (`cloud_user_quotas`) bằng mô hình Không gian lưu trữ đa năng (**`cloud_spaces`**), bổ sung Sổ cái điều chỉnh hạn mức bất biến (**`cloud_quota_adjustments`**), và cấu trúc Đội nhóm tương lai (**`teams`**, **`team_members`**).

---

## 1. BẢNG BIẾN THIÊN CẤU TRÚC DỮ LIỆU (SCHEMA DELTA TABLE: V1 VS V2)

| Bảng Đề Xuất Ở Bản V1 | Quyết Định V2 | Bảng Mới / Cập Nhật Ở Bản V2 | Lý Do Thay Đổi |
| :--- | :---: | :--- | :--- |
| `cloud_user_quotas` | **THAY THẾ** | **`cloud_spaces`** & **`cloud_space_quotas`** | Hạn mức không thể gắn chết vào `user_id` khi cần hỗ trợ Không gian đội nhóm (Team Space) chia sẻ dung lượng giữa nhiều thành viên. |
| *(Chưa có ở V1)* | **MỚI (NEW)** | **`cloud_quota_adjustments`** | Sổ cái bất biến ghi vết toàn bộ các lần Admin tăng/giảm dung lượng, thay đổi gói cước hoặc mua thêm Add-on kèm lý do kiểm toán. |
| *(Chưa có ở V1)* | **MỚI (NEW)** | **`teams`** & **`team_members`** | Sẵn sàng cho gói cước Team Starter (2 slots / 50GB / 2 app keys) dưới cờ tính năng `TEAM_PLANS_ENABLED = false` mà không cần migration phá vỡ sau này. |
| `cloud_files` | **CẬP NHẬT** | `cloud_files` (chuyển `user_id` $\rightarrow$ `cloud_space_id`, thêm `created_by_user_id`) | Tệp tin thuộc sở hữu của Không gian lưu trữ (Team/Personal). Khi thành viên rời đội, tệp tin vẫn thuộc về Team. |
| `cloud_folders` | **CẬP NHẬT** | `cloud_folders` (chuyển `user_id` $\rightarrow$ `cloud_space_id`, thêm `created_by_user_id`) | Thư mục thuộc sở hữu của Không gian lưu trữ, cho phép các thành viên trong đội cùng nhìn thấy và thao tác chung. |
| `cloud_upload_reservations`| **CẬP NHẬT** | `cloud_upload_reservations` (thêm `cloud_space_id`) | Nhiều thành viên trong cùng một Team tải tệp lên đồng thời sẽ cùng khóa dung lượng vào một hạn mức chung duy nhất qua `SELECT ... FOR UPDATE`. |
| `cloud_trash` | **CẬP NHẬT** | `cloud_trash` (thêm `cloud_space_id`, `trashed_by_user_id`) | Quản lý thùng rác theo phạm vi từng Cloud Space. |
| `license_entitlements` | **GIỮ NGUYÊN** | `license_entitlements` | Không gắn trực tiếp `cloud_quota_bytes` làm nguồn chân lý duy nhất. Quota được phân giải động qua công thức: Base + Addon + Adjustment. |
| `storage_accounts` | **MỞ RỘNG** | `storage_accounts` (thêm các trường đo lường vật lý và an toàn) | Hỗ trợ vòng đời gỡ bỏ an toàn: `ACTIVE` $\rightarrow$ `DRAINING` $\rightarrow$ `EMPTY` $\rightarrow$ `DISCONNECTED`. |

---

## 2. SƠ ĐỒ QUAN HỆ THỰC THỂ MỚI (ERD V2)

```
        ┌─────────────────────────┐               ┌─────────────────────────┐
        │          users          │ 1           * │          teams          │
        ├─────────────────────────┼───────────────┼─────────────────────────┤
        │ id (PK)                 │               │ id (PK)                 │
        │ email, password_hash    │               │ name                    │
        │ role (user/admin)       │               │ owner_user_id (FK)      │
        └────────────┬────────────┘               │ member_slots, app_keys  │
                     │ 1                          └────────────┬────────────┘
                     │                                         │ 1
                     ├───────────────────┐                     │
                     │ 1                 │ *                   │ *
                     │       ┌───────────▼───────────┐         │
                     │       │      team_members     │◄────────┘
                     │       ├───────────────────────┤
                     │       │ id (PK)               │
                     │       │ team_id (FK), user_id │
                     │       │ role (OWNER/ADMIN/MEM)│
                     │       └───────────────────────┘
                     │
                     ▼ 1 (Owner)
        ┌──────────────────────────────────────────────┐
        │                 cloud_spaces                 │
        ├──────────────────────────────────────────────┤
        │ id (PK, VARCHAR)                             │
        │ owner_type ('USER', 'TEAM')                  │
        │ owner_id (user_id hoặc team_id)              │
        │ name ('My Cloud' hoặc 'Studio ABC')          │
        │ status ('ACTIVE', 'OVER_QUOTA', 'SUSPENDED') │
        └──────────────────────┬───────────────────────┘
                               │ 1
        ┌──────────────────────┼──────────────────────┬──────────────────────┐
        │ 1                    │ *                    │ *                    │ *
        ▼                      ▼                      ▼                      ▼
┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
│cloud_space_quotas│   │  cloud_folders   │   │   cloud_files    │   │cloud_quota_adjust│
├──────────────────┤   ├──────────────────┤   ├──────────────────┤   ├──────────────────┤
│cloud_space_id(PK)│   │id (PK)           │   │id (PK)           │   │id (PK)           │
│base_quota_bytes  │   │cloud_space_id(FK)│   │cloud_space_id(FK)│   │cloud_space_id(FK)│
│addon_quota_bytes │   │parent_id (FK)    │   │folder_id (FK)    │   │delta_bytes       │
│admin_adj_bytes   │   │name              │   │created_by_user_id│   │type, reason      │
│effective_quota   │   │created_by_user_id│   │storage_account_id│   │admin_user_id     │
│used_bytes        │   └──────────────────┘   │provider_file_id  │   │balance_after     │
│reserved_bytes    │                          │status, size_bytes│   └──────────────────┘
└──────────────────┘                          └──────────────────┘
```

---

## 3. CHI TIẾT DDL CƠ SỞ DỮ LIỆU MÁY CHỦ MYSQL (MYSQL 5.7 COMPATIBLE)

### 3.1. Bảng `teams` (Quản Lý Đội Nhóm — Feature Flag: `TEAM_PLANS_ENABLED`)
```sql
CREATE TABLE IF NOT EXISTS `teams` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `owner_user_id` VARCHAR(64) NOT NULL,
  `member_slots` INT NOT NULL DEFAULT 2 COMMENT 'Số chỗ thành viên tối đa (Team Starter = 2)',
  `app_key_count` INT NOT NULL DEFAULT 2 COMMENT 'Số ghế bản quyền ứng dụng Desktop (Team Starter = 2)',
  `status` ENUM('ACTIVE', 'SUSPENDED', 'CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teams_owner` (`owner_user_id`),
  CONSTRAINT `fk_teams_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2. Bảng `team_members` (Thành Viên Đội Nhóm)
```sql
CREATE TABLE IF NOT EXISTS `team_members` (
  `id` VARCHAR(64) NOT NULL,
  `team_id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `role` ENUM('OWNER', 'ADMIN', 'MEMBER') NOT NULL DEFAULT 'MEMBER',
  `status` ENUM('ACTIVE', 'INVITED', 'REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_team_member` (`team_id`, `user_id`),
  KEY `idx_tm_user` (`user_id`),
  CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3. Bảng `cloud_spaces` (Không Gian Lưu Trữ Độc Lập — Trọng Tâm V2)
```sql
CREATE TABLE IF NOT EXISTS `cloud_spaces` (
  `id` VARCHAR(64) NOT NULL COMMENT 'Ví dụ: cs_personal_user123 hoặc cs_team_456',
  `owner_type` ENUM('USER', 'TEAM') NOT NULL DEFAULT 'USER',
  `owner_id` VARCHAR(64) NOT NULL COMMENT 'user_id nếu là USER, team_id nếu là TEAM',
  `name` VARCHAR(128) NOT NULL DEFAULT 'My Cloud',
  `status` ENUM('ACTIVE', 'OVER_QUOTA', 'SUSPENDED', 'CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cs_owner` (`owner_type`, `owner_id`),
  KEY `idx_cs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.4. Bảng `cloud_space_quotas` (Hạn Mức Phân Giải Động Của Cloud Space)
```sql
CREATE TABLE IF NOT EXISTS `cloud_space_quotas` (
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `base_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 5368709120 COMMENT 'Hạn mức gốc từ gói cước (ví dụ 5GB, 25GB, 50GB)',
  `addon_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dung lượng mua thêm (Storage Add-ons)',
  `admin_adjustment_bytes` BIGINT NOT NULL DEFAULT 0 COMMENT 'Dung lượng cộng/trừ thủ công bởi Admin (+ hoặc -)',
  `effective_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 5368709120 COMMENT 'Dung lượng có hiệu lực = max(0, base + addon + adjustment)',
  `used_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dung lượng thực tế của các tệp ACTIVE',
  `reserved_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dung lượng đang bị khóa tạm bởi các upload đang chạy',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cloud_space_id`),
  CONSTRAINT `fk_csq_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.5. Bảng `cloud_quota_adjustments` (Sổ Cái Điều Chỉnh Hạn Mức Bất Biến)
```sql
CREATE TABLE IF NOT EXISTS `cloud_quota_adjustments` (
  `id` VARCHAR(64) NOT NULL,
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `delta_bytes` BIGINT NOT NULL COMMENT 'Số byte biến động (+ hoặc -)',
  `type` ENUM('ADMIN_GRANT', 'ADMIN_REDUCTION', 'PLAN_GRANT', 'STORAGE_ADDON', 'PROMOTION', 'CORRECTION') NOT NULL,
  `reason` VARCHAR(255) NOT NULL COMMENT 'Lý do bắt buộc (tối thiểu 5 ký tự)',
  `admin_user_id` VARCHAR(64) NOT NULL COMMENT 'Người thực hiện hoặc "SYSTEM"',
  `reference_id` VARCHAR(128) NULL DEFAULT NULL,
  `balance_after_bytes` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cqa_space_created` (`cloud_space_id`, `created_at DESC`),
  CONSTRAINT `fk_cqa_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.6. Bảng `cloud_folders` (Thư Mục Ảo Hóa Thuộc Cloud Space)
```sql
CREATE TABLE IF NOT EXISTS `cloud_folders` (
  `id` VARCHAR(64) NOT NULL,
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `parent_id` VARCHAR(64) NULL DEFAULT NULL,
  `name` VARCHAR(191) NOT NULL,
  `created_by_user_id` VARCHAR(64) NOT NULL COMMENT 'Thành viên thực hiện tạo thư mục',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cfld_space_parent` (`cloud_space_id`, `parent_id`),
  CONSTRAINT `fk_cfld_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cfld_parent` FOREIGN KEY (`parent_id`) REFERENCES `cloud_folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cfld_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.7. Bảng `cloud_files` (Tệp Tin Ảo Hóa Thuộc Cloud Space)
```sql
CREATE TABLE IF NOT EXISTS `cloud_files` (
  `id` VARCHAR(64) NOT NULL COMMENT 'Mã định danh công khai (ví dụ cf_...)',
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `folder_id` VARCHAR(64) NULL DEFAULT NULL,
  `created_by_user_id` VARCHAR(64) NOT NULL COMMENT 'Thành viên tải lên (giữ nguyên kể cả khi thành viên rời đội)',
  `app_id` VARCHAR(32) NOT NULL DEFAULT 'UPSCALE',
  `project_id` VARCHAR(64) NULL DEFAULT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `extension` VARCHAR(32) NOT NULL,
  `mime_type` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` VARCHAR(64) NULL DEFAULT NULL,
  `storage_account_id` VARCHAR(64) NOT NULL,
  `provider_file_id` VARCHAR(128) NOT NULL COMMENT 'Google Drive ID (ẩn 100% với client)',
  `status` ENUM('PENDING_UPLOAD', 'VERIFYING', 'ACTIVE', 'TRASHED', 'PURGING', 'DELETED', 'PROVIDER_TEMPORARILY_UNAVAILABLE') NOT NULL DEFAULT 'PENDING_UPLOAD',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cfiles_space_folder` (`cloud_space_id`, `folder_id`, `status`),
  KEY `idx_cfiles_storage_acc` (`storage_account_id`),
  KEY `idx_cfiles_status` (`status`),
  CONSTRAINT `fk_cfiles_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cfiles_folder` FOREIGN KEY (`folder_id`) REFERENCES `cloud_folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cfiles_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cfiles_storage_acc` FOREIGN KEY (`storage_account_id`) REFERENCES `storage_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.8. Bảng `cloud_upload_reservations` (Sổ Tạm Khóa Dung Lượng)
```sql
CREATE TABLE IF NOT EXISTS `cloud_upload_reservations` (
  `id` VARCHAR(64) NOT NULL,
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL COMMENT 'Thành viên thực hiện upload',
  `cloud_file_id` VARCHAR(64) NOT NULL,
  `storage_account_id` VARCHAR(64) NOT NULL,
  `session_url` TEXT NOT NULL,
  `reserved_bytes` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('RESERVED', 'COMMITTED', 'ABORTED', 'EXPIRED') NOT NULL DEFAULT 'RESERVED',
  `idempotency_key` VARCHAR(128) NULL DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cur_idempotency` (`idempotency_key`),
  KEY `idx_cur_space_status` (`cloud_space_id`, `status`),
  CONSTRAINT `fk_cur_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cur_file` FOREIGN KEY (`cloud_file_id`) REFERENCES `cloud_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cur_storage` FOREIGN KEY (`storage_account_id`) REFERENCES `storage_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.9. Bảng `storage_accounts` (Mở Rộng Cho Quản Trị Storage Pool V2)
```sql
CREATE TABLE IF NOT EXISTS `storage_accounts` (
  `id` VARCHAR(64) NOT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT 'GOOGLE_DRIVE',
  `display_alias` VARCHAR(128) NOT NULL,
  `encrypted_credentials` TEXT NOT NULL COMMENT 'AES-256-GCM encrypted OAuth token',
  `root_folder_id` VARCHAR(128) NULL DEFAULT NULL,
  `total_capacity_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `used_capacity_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `reserved_capacity_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `safety_reserve_percent` INT UNSIGNED NOT NULL DEFAULT 10,
  `status` ENUM('ACTIVE', 'NEAR_FULL', 'DRAINING', 'OFFLINE', 'AUTH_REQUIRED', 'ERROR', 'DISABLED') NOT NULL DEFAULT 'ACTIVE',
  `priority` INT NOT NULL DEFAULT 100,
  `health_status` ENUM('HEALTHY', 'DEGRADED', 'UNHEALTHY', 'UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  `last_health_check` DATETIME NULL DEFAULT NULL,
  `last_usage_refresh` DATETIME NULL DEFAULT NULL,
  `active_file_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số tệp tin vật lý đang lưu trữ (phải = 0 mới cho Disconnect)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sa_status_health` (`status`, `health_status`),
  KEY `idx_sa_priority` (`priority` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. CƠ SỞ DỮ LIỆU SQLITE TRÊN MÁY KHÁCH DESKTOP (LOCAL SQLITE V2)

Trong file `database/local-sqlite/schema.sql` của Desktop App (2toolne Upscale), cấu trúc hàng đợi được mở rộng thêm trường `cloud_space_id` để đảm bảo tác vụ sao lưu luôn gắn chặt vào đúng không gian được chỉ định:

```sql
CREATE TABLE IF NOT EXISTS cloud_backup_jobs (
  id TEXT PRIMARY KEY,
  project_id TEXT NOT NULL,
  cloud_space_id TEXT NOT NULL,
  item_id TEXT NULL,
  local_path TEXT NOT NULL,
  cloud_file_id TEXT,
  upload_session_url TEXT,
  total_bytes INTEGER NOT NULL,
  bytes_uploaded INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'CLOUD_PENDING' 
    CHECK (status IN (
      'CLOUD_PENDING', 'CLOUD_PREPARING', 'CLOUD_UPLOADING', 
      'CLOUD_VERIFYING', 'CLOUD_COMPLETE', 'CLOUD_FAILED', 
      'CLOUD_PAUSED', 'CLOUD_CANCELLED'
    )),
  retry_count INTEGER NOT NULL DEFAULT 0,
  error_message TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  completed_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_cbj_space ON cloud_backup_jobs(cloud_space_id);
CREATE INDEX IF NOT EXISTS idx_cbj_status ON cloud_backup_jobs(status);
```
