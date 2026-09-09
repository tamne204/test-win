-- =============================================================================
-- 2TOOLNE CLOUD V2 — PRIORITY 5: CLOUD SHARES SCHEMA MIGRATION
-- Migration: v3_cloud_shares_schema.sql
-- Database: ecxaebka_bot (MySQL 5.7 / 8.x / MariaDB compatible)
-- Forward-only, non-destructive migration for Public File & Folder Sharing
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- BẢNG LIÊN KẾT CHIA SẺ CÔNG KHAI (CLOUD SHARES)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cloud_shares` (
  `id` VARCHAR(64) NOT NULL COMMENT 'Mã định danh bản ghi chia sẻ (ví dụ sh_...)',
  `cloud_space_id` VARCHAR(64) NOT NULL COMMENT 'Không gian sở hữu tài sản',
  `item_type` ENUM('FILE', 'FOLDER') NOT NULL COMMENT 'Loại tài sản: Tệp hoặc Thư mục',
  `item_id` VARCHAR(64) NOT NULL COMMENT 'ID của cloud_files hoặc cloud_folders',
  `share_token_hash` VARCHAR(64) NOT NULL COMMENT 'Mã băm SHA-256 của token 32 byte để tra cứu nhanh O(1) công khai',
  `share_token_ciphertext` TEXT NOT NULL COMMENT 'Bản mã hóa bảo mật AES-256-GCM chứa token thô chỉ dành cho chủ sở hữu khôi phục URL',
  `access_level` ENUM('VIEW_ONLY', 'ALLOW_DOWNLOAD') NOT NULL DEFAULT 'VIEW_ONLY' COMMENT 'Cấp độ quyền truy cập',
  `expires_at` DATETIME NULL DEFAULT NULL COMMENT 'Thời hạn hiệu lực (NULL = Không bao giờ hết hạn)',
  `revoked_at` DATETIME NULL DEFAULT NULL COMMENT 'Thời điểm chủ sở hữu thu hồi liên kết',
  `idempotency_key` VARCHAR(128) NULL DEFAULT NULL COMMENT 'Khóa chống tạo lặp trùng lặp link',
  `created_by_user_id` VARCHAR(64) NOT NULL COMMENT 'Thành viên tạo liên kết',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo',
  `last_accessed_at` DATETIME NULL DEFAULT NULL COMMENT 'Lần cuối cùng giải mã phiên truy cập công khai',
  `access_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tổng số phiên truy cập công khai hợp lệ',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cshares_token_hash` (`share_token_hash`),
  UNIQUE KEY `uk_cshares_idempotency` (`idempotency_key`),
  KEY `idx_cshares_item` (`cloud_space_id`, `item_type`, `item_id`, `revoked_at`),
  KEY `idx_cshares_creator` (`created_by_user_id`),
  KEY `idx_cshares_expires` (`expires_at`),
  CONSTRAINT `fk_cshares_space` FOREIGN KEY (`cloud_space_id`) REFERENCES `cloud_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
