-- =============================================================================
-- 2TOOLNE UPSCALE & AUTOEDIT V2 — MIGRATION: PRODUCTION IDEMPOTENCY KEY UNIQUE
-- Target Database: MySQL 5.7 / MariaDB 10.x (InnoDB Engine)
-- Database: ecxaebka_bot
-- Purpose: Authoritative database-level guarantee against double token charges.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Ensure idempotency_key column has correct length and indexable type
ALTER TABLE `credit_transactions`
  MODIFY COLUMN `idempotency_key` VARCHAR(128) NULL DEFAULT NULL;

-- 2. Add authoritative UNIQUE constraint for idempotency_key
-- Prevents duplicate deductions under concurrent requests or client retries
ALTER TABLE `credit_transactions`
  ADD UNIQUE KEY `uk_credit_transactions_idempotency` (`idempotency_key`);

SET FOREIGN_KEY_CHECKS = 1;
