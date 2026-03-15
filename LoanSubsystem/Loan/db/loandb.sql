-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 15, 2026 at 09:00 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `loandb`
--

-- --------------------------------------------------------

--
-- Table structure for table `loan_applications`
--

CREATE TABLE `loan_applications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `loan_type_id` int(11) DEFAULT NULL,
  `user_email` varchar(255) NOT NULL,
  `loan_terms` varchar(50) DEFAULT NULL,
  `loan_amount` decimal(12,2) DEFAULT NULL,
  `monthly_payment` decimal(10,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `next_payment_due` date DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_applications`
--

INSERT INTO `loan_applications` (`id`, `user_id`, `loan_type_id`, `user_email`, `loan_terms`, `loan_amount`, `monthly_payment`, `due_date`, `next_payment_due`, `purpose`, `status`, `created_at`) VALUES
(1, 1, 8, 'franciscarpeso@gmail.com', '6 Months', 5000.00, 882.61, '2026-09-15', '2026-04-15', 'test', 'Active', '2026-03-15 04:32:22'),
(2, 3, 8, 'kurtcarpeso02@gmail.com', '6 Months', 5000.00, 882.61, '2026-09-15', '2026-04-15', 'test also', 'Active', '2026-03-15 07:02:48'),
(3, 3, 7, 'kurtcarpeso02@gmail.com', '24 Months', 700000.00, 35627.06, '2028-03-15', '2026-04-15', 'test again', 'Active', '2026-03-15 07:09:26'),
(4, 1, 5, 'franciscarpeso@gmail.com', '6 Months', 5000.00, 882.61, '2026-09-15', '2026-04-15', 'dry test', 'Active', '2026-03-15 07:16:43'),
(5, 3, 1, 'kurtcarpeso02@gmail.com', '6 Months', 5000.00, 882.61, '2026-09-15', '2026-04-15', 'last dry run', 'Active', '2026-03-15 07:54:33'),
(6, 1, 5, 'franciscarpeso@gmail.com', '36 Months', 6000.00, 222.98, '2029-03-15', '2026-04-15', 'last dry run', 'Rejected', '2026-03-15 07:57:42');

-- --------------------------------------------------------

--
-- Table structure for table `loan_approvals`
--

CREATE TABLE `loan_approvals` (
  `id` int(11) NOT NULL,
  `loan_application_id` int(11) DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_approvals`
--

INSERT INTO `loan_approvals` (`id`, `loan_application_id`, `approved_by`, `approved_by_user_id`, `approved_at`) VALUES
(1, 1, 'Kurt Francis Carpeso', 2, '2026-03-15 14:53:43'),
(2, 1, 'Kurt Francis Carpeso', 2, '2026-03-15 14:54:05'),
(3, 1, 'Kurt Francis Carpeso', 2, '2026-03-15 14:55:28'),
(5, 2, 'Kurt Francis Carpeso', 2, '2026-03-15 15:04:29'),
(7, 3, 'Kurt Francis Carpeso', 2, '2026-03-15 15:10:09'),
(9, 4, 'Kurt Francis Carpeso', 2, '2026-03-15 15:17:23'),
(11, 5, 'Kurt Francis Carpeso', 2, '2026-03-15 15:55:29');

-- --------------------------------------------------------

--
-- Table structure for table `loan_borrowers`
--

CREATE TABLE `loan_borrowers` (
  `id` int(11) NOT NULL,
  `loan_application_id` int(11) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `job` varchar(255) DEFAULT NULL,
  `monthly_salary` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_borrowers`
--

INSERT INTO `loan_borrowers` (`id`, `loan_application_id`, `full_name`, `account_number`, `contact_number`, `email`, `job`, `monthly_salary`) VALUES
(1, 1, 'Kurt Francis Carpeso', '12388902312', '09959228310', 'franciscarpeso@gmail.com', NULL, NULL),
(2, 2, 'Kurt', '1234455221', '09603281984', 'kurtcarpeso02@gmail.com', NULL, NULL),
(3, 3, 'Kurt', '1234455221', '09603281984', 'kurtcarpeso02@gmail.com', NULL, NULL),
(4, 4, 'Kurt Francis Carpeso', '12388902312', '09959228310', 'franciscarpeso@gmail.com', NULL, NULL),
(5, 5, 'Kurt', '1234455221', '09603281984', 'kurtcarpeso02@gmail.com', NULL, NULL),
(6, 6, 'Kurt Francis Carpeso', '12388902312', '09959228310', 'franciscarpeso@gmail.com', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `loan_documents`
--

CREATE TABLE `loan_documents` (
  `id` int(11) NOT NULL,
  `loan_application_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `proof_of_income` varchar(255) DEFAULT NULL,
  `coe_document` varchar(255) DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `pdf_approved` varchar(255) DEFAULT NULL,
  `pdf_active` varchar(255) DEFAULT NULL,
  `pdf_rejected` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_documents`
--

INSERT INTO `loan_documents` (`id`, `loan_application_id`, `file_name`, `proof_of_income`, `coe_document`, `pdf_path`, `pdf_approved`, `pdf_active`, `pdf_rejected`) VALUES
(1, 1, 'uploads/valid_id_1773549142_69b6365665ce0.png', 'uploads/proof_income_1773549142_69b636566647c.png', 'uploads/coe_1773549142_69b6365666986.docx', NULL, NULL, NULL, NULL),
(2, 2, 'uploads/valid_id_1773558168_69b659981aa87.png', 'uploads/proof_income_1773558168_69b659981b770.png', 'uploads/coe_1773558168_69b659981be92.pdf', NULL, NULL, NULL, NULL),
(3, 3, 'uploads/valid_id_1773558566_69b65b265d6ef.png', 'uploads/proof_income_1773558566_69b65b265db0c.png', 'uploads/coe_1773558566_69b65b265e342.docx', NULL, NULL, NULL, NULL),
(4, 4, 'uploads/valid_id_1773559003_69b65cdb1ddc0.png', 'uploads/proof_income_1773559003_69b65cdb2b064.png', 'uploads/coe_1773559003_69b65cdb2b5f7.docx', NULL, NULL, NULL, NULL),
(5, 5, 'uploads/valid_id_1773561273_69b665b9870fd.png', 'uploads/proof_income_1773561273_69b665b989ad8.png', 'uploads/coe_1773561273_69b665b98a524.pdf', NULL, NULL, NULL, NULL),
(6, 6, 'uploads/valid_id_1773561462_69b666767f3b9.png', 'uploads/proof_income_1773561462_69b666767fecf.png', 'uploads/coe_1773561462_69b666768055f.pdf', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `loan_rejections`
--

CREATE TABLE `loan_rejections` (
  `id` int(11) NOT NULL,
  `loan_application_id` int(11) DEFAULT NULL,
  `rejected_by` varchar(255) DEFAULT NULL,
  `rejected_by_user_id` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_rejections`
--

INSERT INTO `loan_rejections` (`id`, `loan_application_id`, `rejected_by`, `rejected_by_user_id`, `rejected_at`, `rejection_remarks`) VALUES
(1, 6, 'Kurt Francis Carpeso', 2, '2026-03-15 15:58:02', 'NOt Valid');

-- --------------------------------------------------------

--
-- Table structure for table `loan_types`
--

CREATE TABLE `loan_types` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `max_amount` decimal(18,2) DEFAULT NULL,
  `max_term_months` int(11) DEFAULT NULL,
  `interest_rate` decimal(6,4) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_types`
--

INSERT INTO `loan_types` (`id`, `code`, `name`, `max_amount`, `max_term_months`, `interest_rate`, `description`, `is_active`) VALUES
(1, 'SALARY', 'Salary Loan', 50000.00, 12, 0.0500, 'Employee salary loan', 1),
(2, 'EMERGENCY', 'Emergency Loan', 25000.00, 6, 0.0800, 'Emergency financial assistance', 1),
(3, 'HOUSING', 'Housing Loan', 500000.00, 60, 0.0600, 'Housing loan assistance', 1),
(4, 'EDUCATION', 'Education Loan', 100000.00, 24, 0.0400, 'Educational assistance loan', 1),
(5, 'VEHICLE', 'Vehicle Loan', 300000.00, 36, 0.0700, 'Vehicle purchase loan', 1),
(6, 'MEDICAL', 'Medical Loan', 15000.00, 12, 0.0300, 'Medical emergency loan', 1),
(7, 'APPLIANCE', 'Appliance Loan', 20000.00, 18, 0.0500, 'Home appliance loan', 1),
(8, 'PL', 'Personal Loan', 500000.00, 60, 12.5000, 'Personal loans for employees', 1),
(9, 'HL', 'Housing Loan (Extended)', 2000000.00, 360, 8.5000, 'Housing/Home loans with extended terms', 1),
(10, 'VL', 'Vehicle Loan (Extended)', 1000000.00, 60, 10.0000, 'Auto/Vehicle loans', 1),
(11, 'EL', 'Emergency Loan (Extended)', 100000.00, 12, 15.0000, 'Quick emergency loans', 1),
(12, 'SL', 'Salary Loan (Extended)', 200000.00, 24, 14.0000, 'Salary advance loans with higher limits', 1);

-- --------------------------------------------------------

--
-- Table structure for table `loan_valid_id`
--

CREATE TABLE `loan_valid_id` (
  `id` int(11) NOT NULL,
  `valid_id_type` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_valid_id`
--

INSERT INTO `loan_valid_id` (`id`, `valid_id_type`) VALUES
(1, 'Driver\'s License'),
(2, 'Postal Id'),
(3, 'GSIS'),
(4, 'NBI Clearance'),
(5, 'Passport'),
(6, 'National Id'),
(7, 'UMId'),
(8, 'Voter\'s ID'),
(9, 'PRC ID'),
(10, 'Postal ID'),
(11, 'PhilHealth ID'),
(12, 'Senior Citizen ID');

-- --------------------------------------------------------

--
-- Table structure for table `loan_valid_ids`
--

CREATE TABLE `loan_valid_ids` (
  `id` int(11) NOT NULL,
  `loan_application_id` int(11) DEFAULT NULL,
  `loan_valid_id_type` int(11) DEFAULT NULL,
  `valid_id_number` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan_valid_ids`
--

INSERT INTO `loan_valid_ids` (`id`, `loan_application_id`, `loan_valid_id_type`, `valid_id_number`) VALUES
(1, 1, 8, '123332112'),
(2, 2, 8, '1234554343'),
(3, 3, 8, '1206516516506'),
(4, 4, 7, '12412414141'),
(5, 5, 7, '12412414141'),
(6, 6, 11, '1241241414141');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `user_email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Admin','User') DEFAULT 'User',
  `account_number` varchar(20) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `user_email`, `password_hash`, `role`, `account_number`, `contact_number`, `created_at`) VALUES
(1, 'Kurt Francis Carpeso', 'franciscarpeso@gmail.com', '$2y$10$BeGR3CaCpjp21AnzkRwULewf4GfPdZEMrY2PCXJDQvH9y7zhr6nqS', 'User', '12388902312', '09959228310', '2026-03-09 11:01:05'),
(2, 'Kurt Francis Carpeso', 'carpeso0958432@gmail.com', '$2y$10$FmqeUbqcSgbluyG6DBeO3uYUqxek/S7lzU7K7QxB770RnyiBOF5SO', 'Admin', '10000', '09603281984', '2026-03-09 11:02:35'),
(3, 'Kurt', 'kurtcarpeso02@gmail.com', '$2y$10$.aiTFvCoTMlkw/45f/9IQ.fwn45lzCcHE4iaTzqfFihF7Q9zsU29W', 'User', '1234455221', '09603281984', '2026-03-11 11:22:42');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `loan_applications`
--
ALTER TABLE `loan_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_loan` (`user_id`);

--
-- Indexes for table `loan_approvals`
--
ALTER TABLE `loan_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_application_id` (`loan_application_id`);

--
-- Indexes for table `loan_borrowers`
--
ALTER TABLE `loan_borrowers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_application_id` (`loan_application_id`);

--
-- Indexes for table `loan_documents`
--
ALTER TABLE `loan_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_application_id` (`loan_application_id`);

--
-- Indexes for table `loan_rejections`
--
ALTER TABLE `loan_rejections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_application_id` (`loan_application_id`);

--
-- Indexes for table `loan_types`
--
ALTER TABLE `loan_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `code` (`code`);

--
-- Indexes for table `loan_valid_id`
--
ALTER TABLE `loan_valid_id`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `loan_valid_ids`
--
ALTER TABLE `loan_valid_ids`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_application_id` (`loan_application_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`user_email`),
  ADD UNIQUE KEY `user_email` (`user_email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `loan_applications`
--
ALTER TABLE `loan_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `loan_approvals`
--
ALTER TABLE `loan_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `loan_borrowers`
--
ALTER TABLE `loan_borrowers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `loan_documents`
--
ALTER TABLE `loan_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `loan_rejections`
--
ALTER TABLE `loan_rejections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `loan_types`
--
ALTER TABLE `loan_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `loan_valid_id`
--
ALTER TABLE `loan_valid_id`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `loan_valid_ids`
--
ALTER TABLE `loan_valid_ids`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `loan_applications`
--
ALTER TABLE `loan_applications`
  ADD CONSTRAINT `fk_user_loan` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `loan_approvals`
--
ALTER TABLE `loan_approvals`
  ADD CONSTRAINT `loan_approvals_ibfk_1` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`);

--
-- Constraints for table `loan_borrowers`
--
ALTER TABLE `loan_borrowers`
  ADD CONSTRAINT `loan_borrowers_ibfk_1` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_documents`
--
ALTER TABLE `loan_documents`
  ADD CONSTRAINT `loan_documents_ibfk_1` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_rejections`
--
ALTER TABLE `loan_rejections`
  ADD CONSTRAINT `loan_rejections_ibfk_1` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`);

--
-- Constraints for table `loan_valid_ids`
--
ALTER TABLE `loan_valid_ids`
  ADD CONSTRAINT `loan_valid_ids_ibfk_1` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
