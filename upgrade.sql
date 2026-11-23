-- ======================================================================
-- Ikimina Management System - Phase 1 Database Upgrade
-- Safe upgrade script: Preserves all existing tables, views, and data.
-- Run this in phpMyAdmin or via MySQL CLI.
-- ======================================================================

USE `ikimina_db`;

-- 1. Upgrade users table with required columns for Phase 1 Authentication
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(150) DEFAULT NULL AFTER `username`,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`,
  ADD COLUMN IF NOT EXISTS `last_login` DATETIME DEFAULT NULL AFTER `is_active`;

-- 2. Create login_attempts table for brute-force protection
-- Locks an account or IP for 10 minutes after 5 failed attempts
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `username` VARCHAR(100) DEFAULT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_user_time` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
