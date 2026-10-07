-- ============================================================
-- MoneyLend Database Schema
-- Project: MoneyLend - Simple Lending. Smarter Tracking.
-- Suitable for: MySQL 5.7+ / MariaDB 10.3+ / XAMPP on macOS
-- ============================================================

-- Create database if it does not exist
CREATE DATABASE IF NOT EXISTS `moneylend`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `moneylend`;

-- ------------------------------------------------------------
-- 1. Table structure for table `users`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'staff') NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Table structure for table `borrowers`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `borrowers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_borrowers_phone` (`phone`),
  INDEX `idx_borrowers_email` (`email`),
  INDEX `idx_borrowers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Table structure for table `loans`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loans` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `borrower_id` INT UNSIGNED NOT NULL,
  `principal_amount` DECIMAL(12, 2) NOT NULL,
  `interest_rate` DECIMAL(5, 2) NOT NULL DEFAULT 0.00 COMMENT 'Interest percentage rate',
  `interest_type` ENUM('flat', 'simple') NOT NULL DEFAULT 'flat',
  `duration_value` INT UNSIGNED NOT NULL DEFAULT 1,
  `duration_unit` ENUM('months', 'years') NOT NULL DEFAULT 'months',
  `start_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `total_interest` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `total_payable` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `amount_repaid` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `remaining_balance` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'partially_paid', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_loans_borrower` (`borrower_id`),
  INDEX `idx_loans_status` (`status`),
  INDEX `idx_loans_due_date` (`due_date`),
  CONSTRAINT `fk_loans_borrower` FOREIGN KEY (`borrower_id`)
    REFERENCES `borrowers` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Table structure for table `repayments`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `repayments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `loan_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash' COMMENT 'Cash, UPI, Bank Transfer, Cheque',
  `notes` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_repayments_loan` (`loan_id`),
  INDEX `idx_repayments_date` (`payment_date`),
  INDEX `idx_repayments_user` (`user_id`),
  CONSTRAINT `fk_repayments_loan` FOREIGN KEY (`loan_id`)
    REFERENCES `loans` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_repayments_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Table structure for table `settings` (Phase 6)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Seed Data for Default System Configuration
-- ------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('app_name', 'MoneyLend'),
  ('currency_symbol', '₹'),
  ('currency_name', 'Indian Rupee (₹)'),
  ('date_format', 'd/m/Y'),
  ('default_interest_rate', '10.00'),
  ('default_interest_model', 'flat'),
  ('default_duration_value', '6'),
  ('default_duration_unit', 'months'),
  ('default_payment_method', 'Cash')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ------------------------------------------------------------
-- Optional Seed Data for Initial Admin User (Phase 2 readiness)
-- Password for demo admin is: admin123
-- ------------------------------------------------------------
INSERT INTO `users` (`name`, `email`, `password`, `role`, `created_at`)
VALUES
  ('Admin User', 'admin@moneylend.local', '$2y$12$7hFRW2CJzwAUebdpKhWCKOhX3Or6Cs1yvNUgitINHOfiCIt7ycg9i', 'admin', NOW())
ON DUPLICATE KEY UPDATE `id` = `id`;

