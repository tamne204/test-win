-- =============================================================================
-- 2TOOLNE AI GATEWAY — MIGRATION SCRIPT: AI ACCESS KEYS SCHEMA (V5)
-- Target Database: MySQL / MariaDB 5.7.41 (ecxaebka_bot)
-- Engine: InnoDB | Character Set: utf8mb4 / utf8mb4_unicode_ci
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_access_keys` (
  `id` VARCHAR(64) NOT NULL COMMENT 'Unique Key Identifier e.g. aikey_...',
  `user_id` VARCHAR(64) NOT NULL COMMENT 'Owner user ID',
  `workspace_type` ENUM('PERSONAL', 'TEAM') NOT NULL DEFAULT 'PERSONAL',
  `workspace_id` VARCHAR(64) NOT NULL COMMENT 'Bound Cloud Space ID (cloud_spaces.id)',
  `team_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Team ID if workspace_type is TEAM',
  `display_name` VARCHAR(128) NOT NULL COMMENT 'Human-readable key label e.g. Claude MacBook',
  `key_prefix` VARCHAR(32) NOT NULL COMMENT 'Prefix for O(1) lookup e.g. 2tl_ai_a1b2c3d4',
  `secret_hash` VARCHAR(128) NOT NULL COMMENT 'SHA-256 hash of high-entropy secret',
  `root_folder_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Jailed root folder ID in cloud_folders (AI Inputs)',
  `scopes` TEXT NOT NULL COMMENT 'JSON array of granted scopes e.g. ["CREATE_FOLDER","CREATE_SUBFOLDER","UPLOAD","LIST","READ"]',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME NULL DEFAULT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_aikey_prefix` (`key_prefix`),
  KEY `idx_aikey_user` (`user_id`),
  KEY `idx_aikey_workspace` (`workspace_id`),
  KEY `idx_aikey_team` (`team_id`),
  KEY `idx_aikey_revoked` (`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

