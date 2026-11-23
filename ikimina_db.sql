-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 22, 2026 at 04:49 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ikimina_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `category_name`) VALUES
(4, 'Amafaranga ya serivisi'),
(1, 'Ibikoresho'),
(5, 'Ibindi'),
(3, 'Imishahara'),
(2, 'Ubwikorezi');

-- --------------------------------------------------------

--
-- Table structure for table `financial_accounts`
--

CREATE TABLE `financial_accounts` (
  `id` int(11) NOT NULL,
  `account_name` varchar(50) NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `financial_accounts`
--

INSERT INTO `financial_accounts` (`id`, `account_name`, `opening_balance`, `created_at`) VALUES
(1, 'Cash', 0.00, '2026-11-05 17:00:00'),
(2, 'Bank', 0.00, '2026-11-05 17:00:00');

-- --------------------------------------------------------

--
-- Stand-in structure for view `financial_summary`
-- (See below for the actual view)
--
CREATE TABLE `financial_summary` (
`account_id` int(11)
,`account_name` varchar(50)
,`current_balance` decimal(43,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `income_sources`
--

CREATE TABLE `income_sources` (
  `id` int(11) NOT NULL,
  `source_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `income_sources`
--

INSERT INTO `income_sources` (`id`, `source_name`) VALUES
(1, 'Cantine'),
(2, 'Kogosha'),
(3, 'Other Income'),
(4, 'Ubworozi'),
(5, 'Umugabane');

-- --------------------------------------------------------

--
-- Table structure for table `loans`
--

CREATE TABLE `loans` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `principal` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) DEFAULT 0.00,
  `total_due` decimal(15,2) NOT NULL,
  `loan_date` date NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('ACTIVE','COMPLETED','OVERDUE') DEFAULT 'ACTIVE',
  `disbursement_account_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `loan_summary`
-- (See below for the actual view)
--
CREATE TABLE `loan_summary` (
`loan_id` int(11)
,`member_id` int(11)
,`full_name` varchar(150)
,`principal` decimal(15,2)
,`interest_amount` decimal(15,2)
,`total_due` decimal(15,2)
,`loan_date` date
,`due_date` date
,`amount_paid` decimal(37,2)
,`remaining_balance` decimal(38,2)
,`payment_status` varchar(9)
,`days_remaining` int(7)
);

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`id`, `full_name`, `phone`, `address`, `created_at`) VALUES
(1, 'mugiraneza mugisha prince', '078978688', 'kk', '2026-11-05 17:44:50'),
(2, 'mugiraneza mugisha prince', '4ewed', 'wd', '2026-11-05 17:55:37');

-- --------------------------------------------------------

--
-- Stand-in structure for view `overall_financial_summary`
-- (See below for the actual view)
--
CREATE TABLE `overall_financial_summary` (
`total_cash_and_bank` decimal(65,2)
,`total_income` decimal(37,2)
,`total_expenses` decimal(37,2)
,`total_loans` decimal(37,2)
,`total_repaid` decimal(37,2)
,`outstanding_loans` decimal(60,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `repayments`
--

CREATE TABLE `repayments` (
  `id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `transaction_type` enum('INCOME','EXPENSE') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `expense_category_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfers`
--

CREATE TABLE `transfers` (
  `id` int(11) NOT NULL,
  `from_account_id` int(11) NOT NULL,
  `to_account_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transfer_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','Staff') DEFAULT 'Admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure for view `financial_summary`
--
DROP TABLE IF EXISTS `financial_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `financial_summary`  AS SELECT `a`.`id` AS `account_id`, `a`.`account_name` AS `account_name`, `a`.`opening_balance`+ coalesce((select sum(`t`.`amount`) from `transactions` `t` where `t`.`account_id` = `a`.`id` and `t`.`transaction_type` = 'INCOME'),0) - coalesce((select sum(`t`.`amount`) from `transactions` `t` where `t`.`account_id` = `a`.`id` and `t`.`transaction_type` = 'EXPENSE'),0) - coalesce((select sum(`tr`.`amount`) from `transfers` `tr` where `tr`.`from_account_id` = `a`.`id`),0) + coalesce((select sum(`tr`.`amount`) from `transfers` `tr` where `tr`.`to_account_id` = `a`.`id`),0) - coalesce((select sum(`l`.`principal`) from `loans` `l` where `l`.`disbursement_account_id` = `a`.`id`),0) + coalesce((select sum(`r`.`amount`) from `repayments` `r` where `r`.`account_id` = `a`.`id`),0) AS `current_balance` FROM `financial_accounts` AS `a` ;

-- --------------------------------------------------------

--
-- Structure for view `loan_summary`
--
DROP TABLE IF EXISTS `loan_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `loan_summary`  AS SELECT `l`.`id` AS `loan_id`, `l`.`member_id` AS `member_id`, `m`.`full_name` AS `full_name`, `l`.`principal` AS `principal`, `l`.`interest_amount` AS `interest_amount`, `l`.`total_due` AS `total_due`, `l`.`loan_date` AS `loan_date`, `l`.`due_date` AS `due_date`, coalesce(sum(`r`.`amount`),0) AS `amount_paid`, greatest(`l`.`total_due` - coalesce(sum(`r`.`amount`),0),0) AS `remaining_balance`, CASE WHEN `l`.`total_due` <= coalesce(sum(`r`.`amount`),0) THEN 'COMPLETED' WHEN `l`.`due_date` < curdate() THEN 'OVERDUE' ELSE 'ACTIVE' END AS `payment_status`, to_days(`l`.`due_date`) - to_days(curdate()) AS `days_remaining` FROM ((`loans` `l` join `members` `m` on(`l`.`member_id` = `m`.`id`)) left join `repayments` `r` on(`l`.`id` = `r`.`loan_id`)) GROUP BY `l`.`id`, `l`.`member_id`, `m`.`full_name`, `l`.`principal`, `l`.`interest_amount`, `l`.`total_due`, `l`.`loan_date`, `l`.`due_date` ;

-- --------------------------------------------------------

--
-- Structure for view `overall_financial_summary`
--
DROP TABLE IF EXISTS `overall_financial_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `overall_financial_summary`  AS SELECT (select coalesce(sum(`financial_summary`.`current_balance`),0) from `financial_summary`) AS `total_cash_and_bank`, (select coalesce(sum(`transactions`.`amount`),0) from `transactions` where `transactions`.`transaction_type` = 'INCOME') AS `total_income`, (select coalesce(sum(`transactions`.`amount`),0) from `transactions` where `transactions`.`transaction_type` = 'EXPENSE') AS `total_expenses`, (select coalesce(sum(`loans`.`total_due`),0) from `loans`) AS `total_loans`, (select coalesce(sum(`repayments`.`amount`),0) from `repayments`) AS `total_repaid`, (select coalesce(sum(`loan_summary`.`remaining_balance`),0) from `loan_summary`) AS `outstanding_loans` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `financial_accounts`
--
ALTER TABLE `financial_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_name` (`account_name`);

--
-- Indexes for table `income_sources`
--
ALTER TABLE `income_sources`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `loans`
--
ALTER TABLE `loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `fk_loans_account` (`disbursement_account_id`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `repayments`
--
ALTER TABLE `repayments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_id` (`loan_id`),
  ADD KEY `fk_repayments_account` (`account_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `source_id` (`source_id`),
  ADD KEY `fk_transactions_account` (`account_id`),
  ADD KEY `fk_transactions_expense` (`expense_category_id`);

--
-- Indexes for table `transfers`
--
ALTER TABLE `transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `from_account_id` (`from_account_id`),
  ADD KEY `to_account_id` (`to_account_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `financial_accounts`
--
ALTER TABLE `financial_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `income_sources`
--
ALTER TABLE `income_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `loans`
--
ALTER TABLE `loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `repayments`
--
ALTER TABLE `repayments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfers`
--
ALTER TABLE `transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `loans`
--
ALTER TABLE `loans`
  ADD CONSTRAINT `fk_loans_account` FOREIGN KEY (`disbursement_account_id`) REFERENCES `financial_accounts` (`id`),
  ADD CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`);

--
-- Constraints for table `repayments`
--
ALTER TABLE `repayments`
  ADD CONSTRAINT `fk_repayments_account` FOREIGN KEY (`account_id`) REFERENCES `financial_accounts` (`id`),
  ADD CONSTRAINT `repayments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `financial_accounts` (`id`),
  ADD CONSTRAINT `fk_transactions_expense` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`source_id`) REFERENCES `income_sources` (`id`);

--
-- Constraints for table `transfers`
--
ALTER TABLE `transfers`
  ADD CONSTRAINT `transfers_ibfk_1` FOREIGN KEY (`from_account_id`) REFERENCES `financial_accounts` (`id`),
  ADD CONSTRAINT `transfers_ibfk_2` FOREIGN KEY (`to_account_id`) REFERENCES `financial_accounts` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
