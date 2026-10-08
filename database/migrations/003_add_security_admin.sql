-- ============================================================
-- Migration: 003_add_security_admin.sql
-- Purpose: Adds user status, last login tracking, login attempts
--          rate-limiting table, and security preferences.
-- ============================================================

USE `moneylend`;

-- 1. Enhance users table with status and last_login_at
ALTER TABLE `users`
  ADD COLUMN `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER `role`,
  ADD COLUMN `last_login_at` DATETIME DEFAULT NULL AFTER `status`,
  ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- 2. Create login_attempts table for rate limiting and brute force protection
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(150) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_successful` TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_login_email_time` (`email`, `attempted_at`),
  INDEX `idx_login_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Seed default security configuration in settings table
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('session_timeout_minutes', '30'),
  ('login_max_attempts', '5'),
  ('login_lockout_minutes', '15'),
  ('min_password_length', '8'),
  ('audit_retention_days', '90')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
