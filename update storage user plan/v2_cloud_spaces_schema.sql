-- =============================================================================
-- 2TOOLNE CLOUD V2 — MIGRATION SCRIPT: CLOUD SPACES & TEAM FOUNDATION SCHEMA
-- Target Database: MySQL / MariaDB 5.7.41 (ecxaebka_bot)
-- Character Set: utf8mb4 / utf8mb4_unicode_ci
-- Engine: InnoDB
-- Architecture: Conforms to existing ecxaebka_bot convention (indexed VARCHAR(64) user_id)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. BẢNG TÀI KHOẢN LƯU TRỮ VẬT LÝ (STORAGE ACCOUNTS - MULTI-DRIVE POOL)
-- -----------------------------------------------------------------------------
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
  `status` ENUM('ACTIVE', 'NEAR_FULL', 'DRAINING', 'EMPTY', 'DISCONNECTED', 'OFFLINE', 'AUTH_REQUIRED', 'ERROR', 'DISABLED') NOT NULL DEFAULT 'ACTIVE',
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

-- -----------------------------------------------------------------------------
-- 2. BẢNG GIÁM SÁT SỨC KHỎE Ổ ĐĨA (CLOUD STORAGE HEALTH CHECKS)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cloud_storage_health` (
  `id` VARCHAR(64) NOT NULL,
  `storage_account_id` VARCHAR(64) NOT NULL,
  `check_type` VARCHAR(32) NOT NULL DEFAULT 'PING',
  `status` ENUM('HEALTHY', 'DEGRADED', 'UNHEALTHY', 'ERROR') NOT NULL,
  `response_time_ms` INT UNSIGNED NULL DEFAULT NULL,
  `error_code` VARCHAR(64) NULL DEFAULT NULL,
  `error_message` TEXT NULL DEFAULT NULL,
  `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_csh_acc_checked` (`storage_account_id`, `checked_at` DESC),
  CONSTRAINT `fk_csh_acc` FOREIGN KEY (`storage_account_id`) REFERENCES `storage_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. BẢNG QUẢN LÝ ĐỘI NHÓM (TEAMS - CHỜ GÓI TEAM STARTER)
-- -----------------------------------------------------------------------------
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
  KEY `idx_teams_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. BẢNG THÀNH VIÊN ĐỘI NHÓM (TEAM MEMBERS)
-- -----------------------------------------------------------------------------
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
  CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. BẢNG GỐC KHÔNG GIAN LƯU TRỮ (CLOUD SPACES - TRỌNG TÂM V2)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cloud_spaces` (
  `id` VARCHAR(64) NOT NULL COMMENT 'Ví dụ: cs_pers_user123 hoặc cs_team_456',
  `owner_type` ENUM('USER', 'TEAM') NOT NULL DEFAULT 'USER',
  `owner_id` VARCHAR(64) NOT NULL COMMENT 'user_id nếu là USER, team_id nếu là TEAM',
  `name` VARCHAR(128) NOT NULL DEFAULT 'Personal Space',
  `status` ENUM('ACTIVE', 'OVER_QUOTA', 'SUSPENDED', 'CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cs_owner` (`owner_type`, `owner_id`),
  KEY `idx_cs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. BẢNG HẠN MỨC DUNG LƯỢNG THEO SPACE (CLOUD SPACE QUOTAS)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cloud_space_quotas` (
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `base_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 5368709120 COMMENT 'Hạn mức gốc từ gói cước (ví dụ 5GB, 25GB, 100GB)',
  `addon_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dung lượng mua thêm (Storage Add-ons)',
  `admin_adjustment_bytes` BIGINT NOT NULL DEFAULT 0 COMMENT 'Dung lượng cộng/trừ thủ công bởi Admin (+ hoặc -)',
  `effective_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 5368709120 COMMENT 'Dung lượng có hiệu lực = max(0, base + addon + adjustment)',
  `used_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dung lượng thực tế của các tệp ACTIVE',
  `reserved_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dung lượng đang bị khóa tạm bởi các upload đang chạy',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cloud_space_id`),
  CONSTRAINT `fk_csq_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. BẢNG SỔ CÁI ĐIỀU CHỈNH HẠN MỨC BẤT BIẾN (CLOUD QUOTA ADJUSTMENTS)
-- -----------------------------------------------------------------------------
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
  KEY `idx_cqa_space_created` (`cloud_space_id`, `created_at` DESC),
  CONSTRAINT `fk_cqa_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. BẢNG THƯ MỤC ẢO HÓA THEO SPACE (CLOUD FOLDERS)
-- -----------------------------------------------------------------------------
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
  KEY `idx_cfld_creator` (`created_by_user_id`),
  CONSTRAINT `fk_cfld_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cfld_parent` FOREIGN KEY (`parent_id`) REFERENCES `cloud_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. BẢNG TỆP TIN ẢO HÓA THEO SPACE (CLOUD FILES)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cloud_files` (
  `id` VARCHAR(64) NOT NULL COMMENT 'Mã định danh công khai (ví dụ cf_...)',
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `folder_id` VARCHAR(64) NULL DEFAULT NULL,
  `created_by_user_id` VARCHAR(64) NOT NULL COMMENT 'Thành viên tải lên',
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
  KEY `idx_cfiles_creator` (`created_by_user_id`),
  KEY `idx_cfiles_storage_acc` (`storage_account_id`),
  KEY `idx_cfiles_status` (`status`),
  CONSTRAINT `fk_cfiles_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cfiles_folder` FOREIGN KEY (`folder_id`) REFERENCES `cloud_folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cfiles_storage_acc` FOREIGN KEY (`storage_account_id`) REFERENCES `storage_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. BẢNG SỔ TẠM KHÓA DUNG LƯỢNG KHI UPLOAD (CLOUD UPLOAD RESERVATIONS)
-- -----------------------------------------------------------------------------
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
  KEY `idx_cur_user` (`user_id`),
  CONSTRAINT `fk_cur_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cur_file` FOREIGN KEY (`cloud_file_id`) REFERENCES `cloud_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cur_storage` FOREIGN KEY (`storage_account_id`) REFERENCES `storage_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. BẢNG THÙNG RÁC ẢO HÓA (CLOUD TRASH - 30 DAYS RETENTION)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cloud_trash` (
  `id` VARCHAR(64) NOT NULL,
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `cloud_file_id` VARCHAR(64) NOT NULL,
  `original_folder_id` VARCHAR(64) NULL DEFAULT NULL,
  `trashed_by_user_id` VARCHAR(64) NOT NULL,
  `trashed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `purge_due_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ctrash_file` (`cloud_file_id`),
  KEY `idx_ctrash_space_purge` (`cloud_space_id`, `purge_due_at`),
  KEY `idx_ctrash_user` (`trashed_by_user_id`),
  CONSTRAINT `fk_ctrash_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ctrash_file` FOREIGN KEY (`cloud_file_id`) REFERENCES `cloud_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
