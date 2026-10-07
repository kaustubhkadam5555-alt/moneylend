-- ============================================================
-- MoneyLend Demonstration Seed Data
-- Project: MoneyLend — Personal Money Lending Management
-- Purpose: Populates sample borrowers, loans, and repayments
--          for academic evaluation and local demonstration.
-- ============================================================

USE `moneylend`;

-- ------------------------------------------------------------
-- 1. Demo Borrowers
-- ------------------------------------------------------------
INSERT INTO `borrowers` (`id`, `full_name`, `phone`, `email`, `address`, `status`, `created_at`) VALUES
  (3, 'Priya Sharma', '9123456780', 'priya@example.com', 'Flat 402, Sunshine Apartments, MG Road, Pune', 'active', '2026-10-01 10:00:00'),
  (6, 'Ramesh Patel', '9876534252', 'ramesh.773@example.com', '12 Lake View Residency, Ahmedabad', 'active', '2026-10-02 11:30:00'),
  (7, 'Ramesh Patel', '9876523581', 'ramesh.224@example.com', '45 Green Park Avenue, Vadodara', 'active', '2026-10-02 12:00:00'),
  (8, 'Ramesh Patel', '9876521003', 'ramesh.858@example.com', '88 Commercial Complex, Surat', 'active', '2026-10-03 09:15:00'),
  (9, 'Ramesh Patel', '9876589737', 'ramesh.371@example.com', '102 Royal Orchid, Rajkot', 'active', '2026-10-03 14:45:00'),
  (10, 'Ramesh Patel', '9876588116', 'ramesh.587@example.com', '24 Shivam Towers, Gandhinagar', 'active', '2026-10-04 16:20:00'),
  (11, 'Ramesh Patel', '9876570029', 'ramesh.441@example.com', '57 Central Heights, Bhavnagar', 'active', '2026-10-04 18:00:00')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ------------------------------------------------------------
-- 2. Demo Loans
-- ------------------------------------------------------------
INSERT INTO `loans` (`id`, `borrower_id`, `principal_amount`, `interest_rate`, `interest_type`, `duration_value`, `duration_unit`, `start_date`, `due_date`, `total_interest`, `total_payable`, `amount_repaid`, `remaining_balance`, `status`, `notes`, `created_at`) VALUES
  (5, 3, 50000.00, 10.00, 'flat', 6, 'months', '2026-10-01', '2027-04-01', 5000.00, 55000.00, 0.00, 55000.00, 'active', 'Initial business capital loan', '2026-10-01 10:15:00'),
  (6, 7, 40000.00, 10.00, 'flat', 6, 'months', '2026-10-02', '2027-04-02', 4000.00, 44000.00, 0.00, 44000.00, 'active', 'Equipment upgrade financing', '2026-10-02 12:15:00'),
  (7, 8, 40000.00, 10.00, 'flat', 6, 'months', '2026-10-03', '2027-04-03', 4000.00, 44000.00, 0.00, 44000.00, 'active', 'Working capital loan', '2026-10-03 09:30:00'),
  (8, 9, 40000.00, 10.00, 'flat', 6, 'months', '2026-10-03', '2027-04-03', 4000.00, 44000.00, 12345.00, 31655.00, 'partially_paid', 'Retail inventory purchase', '2026-10-03 15:00:00'),
  (9, 10, 40000.00, 10.00, 'flat', 6, 'months', '2026-10-04', '2027-04-04', 4000.00, 44000.00, 25000.00, 19000.00, 'partially_paid', 'Supplier invoice advance', '2026-10-04 16:30:00'),
  (10, 11, 40000.00, 10.00, 'flat', 6, 'months', '2026-10-04', '2027-04-04', 4000.00, 44000.00, 20000.00, 24000.00, 'partially_paid', 'Store renovation advance', '2026-10-04 18:15:00')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ------------------------------------------------------------
-- 3. Demo Repayments
-- ------------------------------------------------------------
INSERT INTO `repayments` (`id`, `loan_id`, `amount`, `payment_date`, `payment_method`, `notes`, `user_id`, `created_at`) VALUES
  (3, 8, 10000.00, '2026-10-05', 'UPI', 'First installment via PhonePe UPI', 1, '2026-10-05 11:00:00'),
  (4, 9, 10000.00, '2026-10-05', 'UPI', 'First installment payment', 1, '2026-10-05 11:30:00'),
  (5, 9, 10000.00, '2026-10-05', 'Bank Transfer', 'Second installment via IMPS', 1, '2026-10-05 14:00:00'),
  (7, 8, 2345.00, '2026-10-06', 'Cash', 'Partial counter cash payment', 1, '2026-10-06 10:15:00'),
  (8, 10, 10000.00, '2026-10-05', 'UPI', 'Installment #1 paid via GPay', 1, '2026-10-05 16:00:00'),
  (9, 10, 10000.00, '2026-10-05', 'Bank Transfer', 'Installment #2 NEFT settlement', 1, '2026-10-05 17:30:00'),
  (11, 9, 5000.00, '2026-10-06', 'Cash', 'Third cash installment at branch', 1, '2026-10-06 15:45:00')
ON DUPLICATE KEY UPDATE `id` = `id`;
