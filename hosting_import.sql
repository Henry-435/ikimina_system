-- ======================================================================
-- Ikimina Management System - Complete Hosting Import
-- Compatible with InfinityFree, Hostinger, cPanel shared hosting
--
-- HOW TO USE:
--   1. In your hosting control panel, create a new MySQL database and user.
--   2. In phpMyAdmin, select that NEW empty database.
--   3. Click Import, choose this file, click Go.
--   4. Update config/config.php with the hosting database credentials.
--
-- WHAT IS DIFFERENT FROM THE LOCAL DUMP:
--   - No DEFINER clause (shared hosts deny it)
--   - No "USE `database_name`" (you already selected the right database)
--   - Stand-in tables removed (views created directly)
--   - Views use CREATE OR REPLACE VIEW (no ALGORITHM=UNDEFINED)
--   - Test member data removed (clean start)
--   - Phase 1 + Phase 2 columns included from the start (no separate upgrade files)
-- ======================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ======================================================================
-- TABLES
-- ======================================================================

-- expense_categories
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `expense_categories` (`id`, `category_name`) VALUES
(1, 'Ibikoresho'),
(2, 'Ubwikorezi'),
(3, 'Imishahara'),
(4, 'Amafaranga ya serivisi'),
(5, 'Ibindi');

-- income_sources
CREATE TABLE IF NOT EXISTS `income_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `income_sources` (`id`, `source_name`) VALUES
(1, 'Cantine'),
(2, 'Kogosha'),
(3, 'Other Income'),
(4, 'Ubworozi'),
(5, 'Umugabane');

-- financial_accounts
CREATE TABLE IF NOT EXISTS `financial_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(50) NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_name` (`account_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `financial_accounts` (`id`, `account_name`, `opening_balance`) VALUES
(1, 'Cash', 0.00),
(2, 'Bank', 0.00);

-- members
CREATE TABLE IF NOT EXISTS `members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- users (Phase 1: includes full_name, is_active, last_login)
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','Staff') DEFAULT 'Admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- login_attempts (Phase 1: brute-force protection)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_user_time` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- transactions (Phase 2: includes created_by, is_void, void workflow, member_id)
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) DEFAULT NULL,
  `transaction_type` enum('INCOME','EXPENSE') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `expense_category_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `source_id` (`source_id`),
  KEY `fk_transactions_account` (`account_id`),
  KEY `fk_transactions_expense` (`expense_category_id`),
  KEY `idx_type_void` (`transaction_type`, `is_void`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- loans (Phase 2: includes created_by, is_void, void workflow)
CREATE TABLE IF NOT EXISTS `loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `principal` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) DEFAULT 0.00,
  `total_due` decimal(15,2) NOT NULL,
  `loan_date` date NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('ACTIVE','COMPLETED','OVERDUE') DEFAULT 'ACTIVE',
  `disbursement_account_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `fk_loans_account` (`disbursement_account_id`),
  KEY `idx_void` (`is_void`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- repayments (Phase 2: includes created_by, is_void, void workflow)
CREATE TABLE IF NOT EXISTS `repayments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `fk_repayments_account` (`account_id`),
  KEY `idx_void` (`is_void`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- transfers (Phase 2: includes created_by, is_void, void workflow)
CREATE TABLE IF NOT EXISTS `transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_account_id` int(11) NOT NULL,
  `to_account_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transfer_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `from_account_id` (`from_account_id`),
  KEY `to_account_id` (`to_account_id`),
  KEY `idx_void` (`is_void`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- activity_logs (Phase 2: audit trail)
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ======================================================================
-- FOREIGN KEY CONSTRAINTS
-- ======================================================================

ALTER TABLE `loans`
  ADD CONSTRAINT `fk_loans_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `fk_loans_account` FOREIGN KEY (`disbursement_account_id`) REFERENCES `financial_accounts` (`id`);

ALTER TABLE `repayments`
  ADD CONSTRAINT `fk_repayments_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  ADD CONSTRAINT `fk_repayments_account` FOREIGN KEY (`account_id`) REFERENCES `financial_accounts` (`id`);

ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_source` FOREIGN KEY (`source_id`) REFERENCES `income_sources` (`id`),
  ADD CONSTRAINT `fk_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `financial_accounts` (`id`),
  ADD CONSTRAINT `fk_transactions_expense` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`);

ALTER TABLE `transfers`
  ADD CONSTRAINT `fk_transfers_from` FOREIGN KEY (`from_account_id`) REFERENCES `financial_accounts` (`id`),
  ADD CONSTRAINT `fk_transfers_to` FOREIGN KEY (`to_account_id`) REFERENCES `financial_accounts` (`id`);

-- ======================================================================
-- VIEWS (no DEFINER, no ALGORITHM — works on shared hosting)
-- If your host STILL denies CREATE VIEW, the PHP code will fall back
-- to inline queries automatically (see includes/auth.php).
-- ======================================================================

CREATE OR REPLACE VIEW `financial_summary` AS
SELECT
    a.id AS account_id,
    a.account_name,
    a.opening_balance
    + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id AND t.transaction_type = 'INCOME'  AND t.is_void = 0), 0)
    - COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id AND t.transaction_type = 'EXPENSE' AND t.is_void = 0), 0)
    - COALESCE((SELECT SUM(tr.amount) FROM transfers tr WHERE tr.from_account_id = a.id AND tr.is_void = 0), 0)
    + COALESCE((SELECT SUM(tr.amount) FROM transfers tr WHERE tr.to_account_id   = a.id AND tr.is_void = 0), 0)
    - COALESCE((SELECT SUM(l.principal) FROM loans l WHERE l.disbursement_account_id = a.id AND l.is_void = 0), 0)
    + COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.account_id = a.id AND r.is_void = 0), 0)
    AS current_balance
FROM financial_accounts a;

CREATE OR REPLACE VIEW `loan_summary` AS
SELECT
    l.id AS loan_id,
    l.member_id,
    m.full_name,
    l.principal,
    l.interest_amount,
    l.total_due,
    l.loan_date,
    l.due_date,
    COALESCE(SUM(CASE WHEN r.is_void = 0 THEN r.amount ELSE 0 END), 0) AS amount_paid,
    GREATEST(l.total_due - COALESCE(SUM(CASE WHEN r.is_void = 0 THEN r.amount ELSE 0 END), 0), 0) AS remaining_balance,
    CASE
        WHEN l.is_void = 1 THEN 'VOIDED'
        WHEN l.total_due <= COALESCE(SUM(CASE WHEN r.is_void = 0 THEN r.amount ELSE 0 END), 0) THEN 'COMPLETED'
        WHEN l.due_date < CURDATE() THEN 'OVERDUE'
        ELSE 'ACTIVE'
    END AS payment_status,
    TO_DAYS(l.due_date) - TO_DAYS(CURDATE()) AS days_remaining
FROM loans l
JOIN members m ON l.member_id = m.id
LEFT JOIN repayments r ON l.id = r.loan_id
WHERE l.is_void = 0
GROUP BY l.id, l.member_id, m.full_name, l.principal, l.interest_amount,
         l.total_due, l.loan_date, l.due_date, l.is_void;

CREATE OR REPLACE VIEW `overall_financial_summary` AS
SELECT
    (SELECT COALESCE(SUM(current_balance), 0) FROM financial_summary) AS total_cash_and_bank,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transaction_type = 'INCOME'  AND is_void = 0) AS total_income,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transaction_type = 'EXPENSE' AND is_void = 0) AS total_expenses,
    (SELECT COALESCE(SUM(total_due), 0) FROM loans WHERE is_void = 0) AS total_loans,
    (SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE is_void = 0) AS total_repaid,
    (SELECT COALESCE(SUM(remaining_balance), 0) FROM loan_summary WHERE payment_status != 'VOIDED') AS outstanding_loans;

-- ======================================================================
-- DONE
-- Next step: open setup_admin.php on your hosting URL to create the
-- first Admin account for Dorothee. Delete setup_admin.php afterwards.
-- ======================================================================
