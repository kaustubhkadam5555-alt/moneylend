-- ============================================================
-- MoneyLend Database Schema
-- Project: MoneyLend - Simple Lending. Smarter Tracking.
-- Suitable for: MySQL 5.7+ / MariaDB 10.3+ / XAMPP on macOS
-- ============================================================



-- ------------------------------------------------------------
-- 1. Table structure for table `users`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'staff') NOT NULL DEFAULT 'admin',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_status` (`status`)
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
-- 6. Table structure for table `activity_logs` (Phase 10)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL COMMENT 'e.g. login, logout, borrower_create, loan_create, repayment_create, etc.',
  `entity_type` VARCHAR(50) DEFAULT NULL COMMENT 'e.g. user, borrower, loan, repayment, settings',
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_activity_user` (`user_id`),
  INDEX `idx_activity_action` (`action`),
  INDEX `idx_activity_entity` (`entity_type`, `entity_id`),
  INDEX `idx_activity_created` (`created_at`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Table structure for table `notifications` (Phase 11)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `borrower_id` INT UNSIGNED DEFAULT NULL,
  `loan_id` INT UNSIGNED DEFAULT NULL,
  `repayment_id` INT UNSIGNED DEFAULT NULL,
  `type` VARCHAR(50) NOT NULL COMMENT 'loan_due_soon, loan_due_today, loan_overdue, repayment_reminder, repayment_recorded, loan_paid, system',
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `severity` ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
  `event_key` VARCHAR(100) NOT NULL COMMENT 'Idempotency key e.g. due_soon:loan_1:2026-10-15',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_notifications_user_event` (`user_id`, `event_key`),
  INDEX `idx_notifications_user` (`user_id`),
  INDEX `idx_notifications_is_read` (`is_read`),
  INDEX `idx_notifications_type` (`type`),
  INDEX `idx_notifications_loan` (`loan_id`),
  INDEX `idx_notifications_borrower` (`borrower_id`),
  INDEX `idx_notifications_created` (`created_at`),
  INDEX `idx_notifications_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_borrower` FOREIGN KEY (`borrower_id`)
    REFERENCES `borrowers` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_loan` FOREIGN KEY (`loan_id`)
    REFERENCES `loans` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_repayment` FOREIGN KEY (`repayment_id`)
    REFERENCES `repayments` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Table structure for table `login_attempts` (Phase 12)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(150) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_successful` TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_login_email_time` (`email`, `attempted_at`),
  INDEX `idx_login_ip_time` (`ip_address`, `attempted_at`)
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
  ('default_payment_method', 'Cash'),
  ('notify_due_soon', '1'),
  ('notify_due_today', '1'),
  ('notify_overdue', '1'),
  ('notify_repayments', '1'),
  ('notify_loan_paid', '1'),
  ('reminder_due_days', '3'),
  ('reminder_overdue_interval', '7'),
  ('session_timeout_minutes', '30'),
  ('login_max_attempts', '5'),
  ('login_lockout_minutes', '15'),
  ('min_password_length', '8'),
  ('audit_retention_days', '90')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ------------------------------------------------------------
-- Optional Seed Data for Initial Admin User (Phase 2 readiness)
-- Password for demo admin is: admin123
-- ------------------------------------------------------------
INSERT INTO `users` (`name`, `email`, `password`, `role`, `status`, `created_at`)
VALUES
  ('Admin User', 'admin@moneylend.local', '$2y$12$7hFRW2CJzwAUebdpKhWCKOhX3Or6Cs1yvNUgitINHOfiCIt7ycg9i', 'admin', 'active', NOW())
ON DUPLICATE KEY UPDATE `id` = `id`;



