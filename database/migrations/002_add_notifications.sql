-- ============================================================
-- Migration: 002_add_notifications.sql
-- Purpose: Adds the notifications table and default notification
--          preferences for Phase 11.
-- ============================================================

USE `moneylend`;

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

-- Notification Preference Defaults in Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('notify_due_soon', '1'),
  ('notify_due_today', '1'),
  ('notify_overdue', '1'),
  ('notify_repayments', '1'),
  ('notify_loan_paid', '1'),
  ('reminder_due_days', '3'),
  ('reminder_overdue_interval', '7')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
