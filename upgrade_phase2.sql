USE `ikimina_db`;

-- 1. Add audit & voiding columns to transactions
ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) NULL AFTER `account_id`,
  ADD COLUMN IF NOT EXISTS `is_void` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(255) NULL AFTER `is_void`,
  ADD COLUMN IF NOT EXISTS `voided_by` INT(11) NULL AFTER `void_reason`,
  ADD COLUMN IF NOT EXISTS `voided_at` DATETIME NULL AFTER `voided_by`,
  ADD COLUMN IF NOT EXISTS `member_id` INT(11) NULL AFTER `expense_category_id`;

-- 2. Add audit & voiding columns to loans
ALTER TABLE `loans`
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `is_void` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `voided_by` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `voided_at` DATETIME NULL;

-- 3. Add audit & voiding columns to repayments
ALTER TABLE `repayments`
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `is_void` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `voided_by` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `voided_at` DATETIME NULL;

-- 4. Add audit & voiding columns to transfers
ALTER TABLE `transfers`
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `is_void` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `voided_by` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `voided_at` DATETIME NULL;

-- 5. Create activity_logs table for audit tracking
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT(11) NOT NULL,
  `details` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Update financial_summary view to exclude voided records
CREATE OR REPLACE VIEW `financial_summary` AS 
SELECT 
    `a`.`id` AS `account_id`,
    `a`.`account_name` AS `account_name`,
    `a`.`opening_balance`
    + COALESCE((SELECT SUM(`t`.`amount`) FROM `transactions` `t` WHERE `t`.`account_id` = `a`.`id` AND `t`.`transaction_type` = 'INCOME' AND `t`.`is_void` = 0), 0)
    - COALESCE((SELECT SUM(`t`.`amount`) FROM `transactions` `t` WHERE `t`.`account_id` = `a`.`id` AND `t`.`transaction_type` = 'EXPENSE' AND `t`.`is_void` = 0), 0)
    - COALESCE((SELECT SUM(`tr`.`amount`) FROM `transfers` `tr` WHERE `tr`.`from_account_id` = `a`.`id` AND `tr`.`is_void` = 0), 0)
    + COALESCE((SELECT SUM(`tr`.`amount`) FROM `transfers` `tr` WHERE `tr`.`to_account_id` = `a`.`id` AND `tr`.`is_void` = 0), 0)
    - COALESCE((SELECT SUM(`l`.`principal`) FROM `loans` `l` WHERE `l`.`disbursement_account_id` = `a`.`id` AND `l`.`is_void` = 0), 0)
    + COALESCE((SELECT SUM(`r`.`amount`) FROM `repayments` `r` WHERE `r`.`account_id` = `a`.`id` AND `r`.`is_void` = 0), 0)
    AS `current_balance` 
FROM `financial_accounts` `a`;

-- 7. Update loan_summary view to exclude voided loans and voided repayments
CREATE OR REPLACE VIEW `loan_summary` AS 
SELECT 
    `l`.`id` AS `loan_id`,
    `l`.`member_id` AS `member_id`,
    `m`.`full_name` AS `full_name`,
    `l`.`principal` AS `principal`,
    `l`.`interest_amount` AS `interest_amount`,
    `l`.`total_due` AS `total_due`,
    `l`.`loan_date` AS `loan_date`,
    `l`.`due_date` AS `due_date`,
    COALESCE(SUM(CASE WHEN `r`.`is_void` = 0 THEN `r`.`amount` ELSE 0 END), 0) AS `amount_paid`,
    GREATEST(`l`.`total_due` - COALESCE(SUM(CASE WHEN `r`.`is_void` = 0 THEN `r`.`amount` ELSE 0 END), 0), 0) AS `remaining_balance`,
    CASE 
        WHEN `l`.`is_void` = 1 THEN 'VOIDED'
        WHEN `l`.`total_due` <= COALESCE(SUM(CASE WHEN `r`.`is_void` = 0 THEN `r`.`amount` ELSE 0 END), 0) THEN 'COMPLETED'
        WHEN `l`.`due_date` < CURDATE() THEN 'OVERDUE'
        ELSE 'ACTIVE'
    END AS `payment_status`,
    TO_DAYS(`l`.`due_date`) - TO_DAYS(CURDATE()) AS `days_remaining`
FROM `loans` `l`
JOIN `members` `m` ON `l`.`member_id` = `m`.`id`
LEFT JOIN `repayments` `r` ON `l`.`id` = `r`.`loan_id`
WHERE `l`.`is_void` = 0
GROUP BY `l`.`id`, `l`.`member_id`, `m`.`full_name`, `l`.`principal`, `l`.`interest_amount`, `l`.`total_due`, `l`.`loan_date`, `l`.`due_date`, `l`.`is_void`;

-- 8. Update overall_financial_summary view
CREATE OR REPLACE VIEW `overall_financial_summary` AS 
SELECT 
    (SELECT COALESCE(SUM(`current_balance`), 0) FROM `financial_summary`) AS `total_cash_and_bank`,
    (SELECT COALESCE(SUM(`amount`), 0) FROM `transactions` WHERE `transaction_type` = 'INCOME' AND `is_void` = 0) AS `total_income`,
    (SELECT COALESCE(SUM(`amount`), 0) FROM `transactions` WHERE `transaction_type` = 'EXPENSE' AND `is_void` = 0) AS `total_expenses`,
    (SELECT COALESCE(SUM(`total_due`), 0) FROM `loans` WHERE `is_void` = 0) AS `total_loans`,
    (SELECT COALESCE(SUM(`amount`), 0) FROM `repayments` WHERE `is_void` = 0) AS `total_repaid`,
    (SELECT COALESCE(SUM(`remaining_balance`), 0) FROM `loan_summary` WHERE `payment_status` != 'VOIDED') AS `outstanding_loans`;
