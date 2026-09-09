-- =============================================================================
-- 2TOOLNE CLOUD V4 — MIGRATION: TEAM WORKSPACE, INVITATIONS, SEATS & WALLET
-- Target Database: ecxaebka_bot (MySQL / MariaDB 5.7+ Compatible)
-- Engine: InnoDB
-- Character Set: utf8mb4 / utf8mb4_unicode_ci
-- Architecture: Conforms to 2TOOLNE V2 cloud-space-centric RBAC specifications
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. BẢNG QUẢN LÝ ĐỘI NHÓM (TEAMS)
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
  KEY `idx_teams_owner` (`owner_user_id`),
  KEY `idx_teams_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. BẢNG THÀNH VIÊN ĐỘI NHÓM (TEAM MEMBERS — V1 ROLES: OWNER, ADMIN, EDITOR, VIEWER)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `team_members` (
  `id` VARCHAR(64) NOT NULL,
  `team_id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `role` ENUM('OWNER', 'ADMIN', 'EDITOR', 'VIEWER') NOT NULL DEFAULT 'EDITOR',
  `status` ENUM('ACTIVE', 'INVITED', 'REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_team_member` (`team_id`, `user_id`),
  KEY `idx_tm_user_status` (`user_id`, `status`),
  KEY `idx_tm_team_status` (`team_id`, `status`),
  CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modify role enum if team_members already existed with legacy ENUM
-- Note: Idempotent in MySQL 5.7+
ALTER TABLE `team_members`
  MODIFY COLUMN `role` ENUM('OWNER', 'ADMIN', 'EDITOR', 'VIEWER') NOT NULL DEFAULT 'EDITOR';

-- -----------------------------------------------------------------------------
-- 3. BẢNG QUẢN LÝ LỜI MỜI THÀNH VIÊN (TEAM INVITATIONS — EXPIRING, SINGLE-USE)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `team_invitations` (
  `id` VARCHAR(64) NOT NULL,
  `team_id` VARCHAR(64) NOT NULL,
  `invite_token_hash` VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of 32-byte cryptographic token',
  `offered_role` ENUM('ADMIN', 'EDITOR', 'VIEWER') NOT NULL DEFAULT 'EDITOR',
  `recipient_email` VARCHAR(191) NULL DEFAULT NULL,
  `created_by_user_id` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invite_token_hash` (`invite_token_hash`),
  KEY `idx_inv_team` (`team_id`),
  KEY `idx_inv_status` (`expires_at`, `used_at`, `revoked_at`),
  CONSTRAINT `fk_inv_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. BẢNG PHÂN BỔ GHẾ BẢN QUYỀN MÁY BÀN (TEAM DESKTOP LICENSE SEATS)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `team_seats` (
  `id` VARCHAR(64) NOT NULL,
  `team_id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `device_fingerprint` VARCHAR(128) NOT NULL,
  `device_alias` VARCHAR(128) NULL DEFAULT NULL,
  `status` ENUM('ACTIVE', 'REVOKED') NOT NULL DEFAULT 'ACTIVE',
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_seat_team_user_dev` (`team_id`, `user_id`, `device_fingerprint`),
  KEY `idx_seat_team_status` (`team_id`, `status`),
  KEY `idx_seat_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_seat_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. MỞ RỘNG BẢNG VÍ TOKEN (CREDIT WALLETS CHO TEAM WORKSPACE)
-- -----------------------------------------------------------------------------
-- Ensure team_id and workspace_id columns exist in credit_wallets
ALTER TABLE `credit_wallets`
  ADD COLUMN `team_id` VARCHAR(64) NULL DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `workspace_id` VARCHAR(64) NULL DEFAULT NULL AFTER `team_id`,
  ADD KEY `idx_cw_team` (`team_id`),
  ADD KEY `idx_cw_workspace` (`workspace_id`);

-- -----------------------------------------------------------------------------
-- 6. MỞ RỘNG BẢNG GIỮ CHỖ TOKEN (CREDIT RESERVATIONS CHO TEAM WORKSPACE)
-- -----------------------------------------------------------------------------
ALTER TABLE `credit_reservations`
  ADD COLUMN `team_id` VARCHAR(64) NULL DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `workspace_id` VARCHAR(64) NULL DEFAULT NULL AFTER `team_id`,
  ADD KEY `idx_cres_team` (`team_id`),
  ADD KEY `idx_cres_workspace` (`workspace_id`);

-- -----------------------------------------------------------------------------
-- 7. MỞ RỘNG BẢNG GIAO DỊCH TOKEN (CREDIT TRANSACTIONS CHO TEAM WORKSPACE)
-- -----------------------------------------------------------------------------
ALTER TABLE `credit_transactions`
  ADD COLUMN `team_id` VARCHAR(64) NULL DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `workspace_id` VARCHAR(64) NULL DEFAULT NULL AFTER `team_id`,
  ADD KEY `idx_ctx_team` (`team_id`),
  ADD KEY `idx_ctx_workspace` (`workspace_id`);

SET FOREIGN_KEY_CHECKS = 1;
