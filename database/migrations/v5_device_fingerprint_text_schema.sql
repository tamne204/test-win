-- ==============================================================================
-- 2TOOLNE PRODUCTION SCHEMA MIGRATION V5
-- Target: devices table device_fingerprint TEXT migration + SHA-256 hash column
-- Compatibility: MySQL 5.6+ / 5.7 / 8.0 / MariaDB (InnoDB, strict mode safe)
-- ==============================================================================

-- 1. Drop old varchar(64) index if present
ALTER TABLE `devices` DROP INDEX `idx_device_fp`;

-- 2. Modify device_fingerprint to TEXT and add 64-char hash column
ALTER TABLE `devices`
  MODIFY COLUMN `device_fingerprint` TEXT NOT NULL,
  ADD COLUMN `device_fingerprint_hash` CHAR(64) NULL AFTER `device_fingerprint`,
  ADD INDEX `idx_device_fp_hash` (`device_fingerprint_hash`),
  ADD INDEX `idx_device_fp` (`device_fingerprint`(191));

-- 3. Backfill device_fingerprint_hash for existing records
UPDATE `devices` 
SET `device_fingerprint_hash` = SHA2(`device_fingerprint`, 256) 
WHERE `device_fingerprint_hash` IS NULL OR `device_fingerprint_hash` = '';
