-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 19, 2026 at 11:10 AM
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
-- Database: `flowdeskdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`admin_id`, `username`, `password`) VALUES
(1, 'admin123', '$2y$10$wrCtXI//51qzyhwReg5Wm.xVgQ3vaU0D8gyT.e4pdVJ.90V3sLK3S');

-- --------------------------------------------------------

--
-- Table structure for table `approval_numbers`
--

CREATE TABLE `approval_numbers` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `approval_numbers`
--

INSERT INTO `approval_numbers` (`setting_key`, `setting_value`, `updated_at`) VALUES
('requester_workflow_max_steps', '5', '2026-10-15 07:55:47');

-- --------------------------------------------------------

--
-- Table structure for table `approvers`
--

CREATE TABLE `approvers` (
  `approver_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `approver_user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `approvers`
--

INSERT INTO `approvers` (`approver_id`, `department_id`, `approver_user_id`) VALUES
(1, 1, 1),
(2, 2, 4),
(3, 3, 6);

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `company_id` int(11) NOT NULL,
  `company_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`company_id`, `company_name`) VALUES
(1, 'Leader Energy Group Berhad'),
(2, 'HNG Capital');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `company_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `company_id`) VALUES
(1, 'Information Technology', 1),
(2, 'Human Resource', 1),
(3, 'Finance', 1);

-- --------------------------------------------------------

--
-- Table structure for table `request_types`
--

CREATE TABLE `request_types` (
  `type_id` int(11) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `target_department_id` int(11) DEFAULT NULL,
  `workflow_route` varchar(20) NOT NULL,
  `form_schema` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`form_schema`)),
  `is_requester_workflow` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `request_types`
--

INSERT INTO `request_types` (`type_id`, `type_name`, `target_department_id`, `workflow_route`, `form_schema`, `is_requester_workflow`) VALUES
(1, 'IT Helpdesk', 1, 'L1', '[]', 0),
(2, 'Hardware Upgrade', 1, 'L2', '[{\"name\":\"hardware_type\",\"label\":\"Hardware Type\",\"type\":\"text\",\"required\":true,\"placeholder\":\"e.g: keyboard, mouse, etc\"}]', 0),
(3, 'Employment Verification Letter', 2, 'L1', '[{\"name\":\"purpose_of_request\",\"label\":\"Purpose of Request\",\"type\":\"select\",\"required\":true,\"options\":[\"Bank Loan Application\",\"Visa Application\",\"Housing Rental\"]}]', 0),
(4, 'External Training & Certification', 2, 'L2', '[{\"name\":\"course_title\",\"label\":\"Course Title\",\"type\":\"text\",\"required\":true,\"placeholder\":\"e.g: AWS Solutions Architect Certification\"},{\"name\":\"estimated_cost\",\"label\":\"Estimated Cost\",\"type\":\"number\",\"required\":true,\"placeholder\":\"e.g: 1299\"},{\"name\":\"training_date\",\"label\":\"Training Date\",\"type\":\"date\",\"required\":true,\"placeholder\":\"YYYY-MM-DD\"}]', 0),
(5, 'Corporate Card Limit Increase', 3, 'L1', '[{\"name\":\"requested_limit\",\"label\":\"Requested Limit\",\"type\":\"number\",\"required\":true,\"placeholder\":\"e.g: 5000\"},{\"name\":\"travel_event_details\",\"label\":\"Travel / Event Details\",\"type\":\"textarea\",\"required\":true,\"placeholder\":\"Provide trip or supplier payment breakdown...\"}]', 0),
(6, 'Expense Claim', 3, 'L2', '[{\"name\":\"claim_type\",\"label\":\"Claim Type\",\"type\":\"text\",\"required\":true,\"placeholder\":\"e.g: travel expenses\"},{\"name\":\"claim_amount\",\"label\":\"Claim Amount\",\"type\":\"number\",\"required\":true,\"placeholder\":\"e.g: 2000\"}]', 0);

-- --------------------------------------------------------

--
-- Table structure for table `rt_approval_steps`
--

CREATE TABLE `rt_approval_steps` (
  `request_type_id` int(11) NOT NULL,
  `step_number` int(11) NOT NULL,
  `department_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `ticket_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `requester_id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `meta_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_data`)),
  `attachment_file` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(100) DEFAULT NULL,
  `hod_remarks` text DEFAULT NULL,
  `assigned_staff_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`ticket_id`, `token`, `requester_id`, `type_id`, `remarks`, `meta_data`, `attachment_file`, `created_at`, `status`, `hod_remarks`, `assigned_staff_id`) VALUES
(1, 'sKzcM5T8G8tDK3s4O5fYnSDPoCuY0N0eFqBldEFLRLQ', 3, 6, 'Pergi training', '{\"claim_type\":\"Minyak motor\",\"claim_amount\":\"23\",\"submission_ip\":\"::1\",\"submitted_at\":\"2026-10-19 10:03:53\"}', NULL, '2026-10-19 08:03:53', 'Completed', 'Sure', 7),
(2, 'pq-bJiulrQwbu62T6t0IKlCdqnzguzAc6dl2RgvnNHk', 3, 6, '123', '{\"claim_type\":\"Minyak motor\",\"claim_amount\":\"23\",\"submission_ip\":\"::1\",\"submitted_at\":\"2026-10-19 10:07:43\"}', NULL, '2026-10-19 08:07:43', 'Completed', NULL, 7),
(3, 'zNCE3mHF1c_qmGZgxhHkN1AGvcVMLUYavq1zESu0LKg', 3, 6, '123', '{\"claim_type\":\"Minyak motor\",\"claim_amount\":\"23\",\"submission_ip\":\"::1\",\"submitted_at\":\"2026-10-19 10:13:03\"}', NULL, '2026-10-19 08:13:03', 'Assign to Staff', NULL, 7),
(4, 'jgzgTn9Suzy-IPif284qbp7RU6-bIQxUHLn4X_35q00', 3, 6, 'Travel claim for training', '{\"claim_type\":\"Travel\",\"claim_amount\":\"450\",\"submission_ip\":\"::1\",\"submitted_at\":\"2026-10-19 10:24:18\"}', 'uploads/3_imran.it@legb.com.pdf', '2026-10-19 08:24:18', 'Completed', NULL, 7);

-- --------------------------------------------------------

--
-- Table structure for table `ticket_approvals`
--

CREATE TABLE `ticket_approvals` (
  `approval_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `step_number` int(11) NOT NULL,
  `approver_user_id` int(11) NOT NULL,
  `approval_token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(100) NOT NULL DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `action_taken_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_approvals`
--

INSERT INTO `ticket_approvals` (`approval_id`, `ticket_id`, `step_number`, `approver_user_id`, `approval_token`, `created_at`, `status`, `remarks`, `action_taken_at`) VALUES
(1, 1, 1, 1, '-TRUnuXDsNljz5vgUB-C83YRAvMAYRfcI85mCtfNHhY', '2026-10-19 08:03:55', 'Approved', 'Sure', '2026-10-19 16:04:11'),
(2, 1, 2, 6, '4sNBi0LaaxTguLy3QAK0yJ9ErB6qHAxmYZTI3TfFc9U', '2026-10-19 08:04:11', '', 'Sure', '2026-10-19 16:09:52'),
(3, 2, 1, 1, 'fnI8dnm3JuB4FCqyheB0iGSYVUXAZrzal7l98-lNRVY', '2026-10-19 08:07:44', 'Approved', NULL, '2026-10-19 16:08:36'),
(4, 2, 2, 6, 'nuAWq0ioCHDBHn0BQ-HTg3-shsL56gSMt1l4wtYw1BA', '2026-10-19 08:08:36', '', NULL, '2026-10-19 16:09:54'),
(5, 3, 1, 1, '9hdF-OheCLP02r1kQfcbFLH6ztGa2uP02HXVq04xZiw', '2026-10-19 08:13:04', 'Approved', NULL, '2026-10-19 16:13:19'),
(6, 3, 2, 6, 'DRDMdbrBHIb34s75HaPHA-EUqWvKHCiDpoHuuG6dp48', '2026-10-19 08:13:19', 'Assign to Staff', NULL, '2026-10-19 16:14:13'),
(7, 4, 1, 1, 'zh9FJaZiXd6AZmFA2IiBcuu_bEgW-DnUSGsUic0vOSk', '2026-10-19 08:24:23', 'Approved', NULL, '2026-10-19 16:51:59'),
(8, 4, 2, 6, 'FDAMZx2wpGtjl0qAYJPiOFRreh_gWynPx0i_g9C4mxo', '2026-10-19 08:51:59', 'Assign to Staff', NULL, '2026-10-19 16:53:54');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_logs`
--

CREATE TABLE `ticket_logs` (
  `log_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `action_type` enum('Assigned','Completed','Request for Reassignment','Ticket Cancelled','Reassignment Rejected','Reassignment Approved') NOT NULL,
  `comments` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_logs`
--

INSERT INTO `ticket_logs` (`log_id`, `ticket_id`, `staff_id`, `action_type`, `comments`, `logged_at`) VALUES
(1, 1, 7, 'Assigned', 'Ticket assigned to staff by HOD.', '2026-10-19 08:09:52'),
(2, 2, 7, 'Assigned', 'Ticket assigned to staff by HOD.', '2026-10-19 08:09:54'),
(3, 2, 7, 'Completed', 'Done', '2026-10-19 08:11:24'),
(4, 1, 7, 'Completed', 'Done', '2026-10-19 08:11:31'),
(5, 3, 7, 'Assigned', 'Ticket assigned to staff by HOD.', '2026-10-19 08:14:13'),
(6, 4, 7, 'Assigned', 'Ticket assigned to staff by HOD.', '2026-10-19 08:53:54'),
(7, 4, 7, 'Completed', 'Request already done', '2026-10-19 08:55:37');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_worksteps`
--

CREATE TABLE `ticket_worksteps` (
  `ticket_id` int(11) NOT NULL,
  `step_number` smallint(5) UNSIGNED NOT NULL,
  `approver_user_id` int(11) NOT NULL,
  `status_label` varchar(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `role` enum('staff','hod') NOT NULL DEFAULT 'staff',
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `department_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `display_name`, `role`, `fullname`, `email`, `department_id`) VALUES
(1, 'Alex IT', 'hod', 'Alex Benjamin', 'alex.it@legb.com', 1),
(2, 'Ahmad IT', 'staff', 'Ahmad Albab', 'ahmad.it@legb.com', 1),
(3, 'Imran IT', 'staff', 'Imran Hakim', 'imran.it@legb.com', 1),
(4, 'Sarah HR', 'hod', 'Sarah Jenkins', 'sarah.hr@legb.com', 2),
(5, 'Elissa HR', 'staff', 'Elissa Sabela', 'eli.hr@legb.com', 2),
(6, 'Tyler FIN', 'hod', 'Tyler Durden', 'tyler.fin@legb.com', 3),
(7, 'David Fin', 'staff', 'David Fischer', 'david.fin@legb.com', 3);

-- --------------------------------------------------------

--
-- Table structure for table `workflow_steps`
--

CREATE TABLE `workflow_steps` (
  `workflow_id` int(11) NOT NULL,
  `workflow_route` varchar(20) NOT NULL,
  `step_number` int(11) NOT NULL,
  `approver_type` varchar(50) NOT NULL,
  `specific_user_id` int(11) DEFAULT NULL,
  `status_label` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `workflow_steps`
--

INSERT INTO `workflow_steps` (`workflow_id`, `workflow_route`, `step_number`, `approver_type`, `specific_user_id`, `status_label`) VALUES
(1, 'L1', 1, 'TARGET_HOD', NULL, 'Pending Step 1: Target Department HOD Approval'),
(2, 'L2', 1, 'REQUESTER_HOD', NULL, 'Pending Step 1: Requester Department HOD Approval'),
(3, 'L2', 2, 'TARGET_HOD', NULL, 'Pending Step 2: Target Department HOD Approval');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`);

--
-- Indexes for table `approval_numbers`
--
ALTER TABLE `approval_numbers`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `approvers`
--
ALTER TABLE `approvers`
  ADD PRIMARY KEY (`approver_id`),
  ADD UNIQUE KEY `uq_approvers_department_user` (`department_id`,`approver_user_id`),
  ADD KEY `approver_user_id` (`approver_user_id`),
  ADD KEY `idx_approvers_department_level` (`department_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`company_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `request_types`
--
ALTER TABLE `request_types`
  ADD PRIMARY KEY (`type_id`),
  ADD KEY `target_department_id` (`target_department_id`);

--
-- Indexes for table `rt_approval_steps`
--
ALTER TABLE `rt_approval_steps`
  ADD PRIMARY KEY (`request_type_id`,`step_number`),
  ADD KEY `idx_rtsa_department_id` (`department_id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`ticket_id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `requester_id` (`requester_id`),
  ADD KEY `type_id` (`type_id`),
  ADD KEY `assigned_staff_id` (`assigned_staff_id`);

--
-- Indexes for table `ticket_approvals`
--
ALTER TABLE `ticket_approvals`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `approver_user_id` (`approver_user_id`);

--
-- Indexes for table `ticket_logs`
--
ALTER TABLE `ticket_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `ticket_worksteps`
--
ALTER TABLE `ticket_worksteps`
  ADD PRIMARY KEY (`ticket_id`,`step_number`),
  ADD KEY `idx_ticket_workflow_approver` (`approver_user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  ADD PRIMARY KEY (`workflow_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `approvers`
--
ALTER TABLE `approvers`
  MODIFY `approver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `company_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `request_types`
--
ALTER TABLE `request_types`
  MODIFY `type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `ticket_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ticket_approvals`
--
ALTER TABLE `ticket_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `ticket_logs`
--
ALTER TABLE `ticket_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  MODIFY `workflow_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `approvers`
--
ALTER TABLE `approvers`
  ADD CONSTRAINT `fk_approvers_recovered_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `fk_approvers_recovered_user` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`);

--
-- Constraints for table `request_types`
--
ALTER TABLE `request_types`
  ADD CONSTRAINT `request_types_ibfk_1` FOREIGN KEY (`target_department_id`) REFERENCES `departments` (`department_id`);

--
-- Constraints for table `rt_approval_steps`
--
ALTER TABLE `rt_approval_steps`
  ADD CONSTRAINT `fk_rtsa_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `fk_rtsa_request_type` FOREIGN KEY (`request_type_id`) REFERENCES `request_types` (`type_id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`type_id`) REFERENCES `request_types` (`type_id`),
  ADD CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`assigned_staff_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `ticket_approvals`
--
ALTER TABLE `ticket_approvals`
  ADD CONSTRAINT `ticket_approvals_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`),
  ADD CONSTRAINT `ticket_approvals_ibfk_2` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `ticket_logs`
--
ALTER TABLE `ticket_logs`
  ADD CONSTRAINT `ticket_logs_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`),
  ADD CONSTRAINT `ticket_logs_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
