-- ============================================================
-- Migration: 001_add_activity_log.sql
-- Purpose: Adds the activity_logs table for audit trail and
--          activity history tracking in Phase 10.
-- ============================================================

USE `moneylend`;

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
