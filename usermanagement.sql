-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 08, 2026 at 09:50 AM
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
-- Database: `usermanagement`
--

-- --------------------------------------------------------

--
-- Table structure for table `incoming_goods`
--

CREATE TABLE `incoming_goods` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `invoice_date` date NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `vendor` varchar(255) NOT NULL,
  `allocation_plan` varchar(255) DEFAULT NULL,
  `project` varchar(255) DEFAULT NULL,
  `item_type` enum('Sparepart','Alat dan Perlengkapan','Oli, Grease, and Coolant') NOT NULL,
  `part_number` varchar(100) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(50) NOT NULL DEFAULT 'pcs',
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `price`) STORED,
  `total_price` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `price` - `discount` + `tax`) STORED,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_stock`
--

CREATE TABLE `inventory_stock` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `invoice_date` date NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `vendor` varchar(255) NOT NULL,
  `allocation_plan` varchar(255) DEFAULT NULL,
  `project` varchar(255) DEFAULT NULL,
  `item_type` enum('Sparepart','Alat dan Perlengkapan','Oli, Grease, and Coolant') NOT NULL,
  `part_number` varchar(100) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `initial_quantity` int(11) NOT NULL DEFAULT 0,
  `current_quantity` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(50) NOT NULL DEFAULT 'pcs',
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) GENERATED ALWAYS AS (`initial_quantity` * `price`) STORED,
  `total_price` decimal(15,2) GENERATED ALWAYS AS (`initial_quantity` * `price` - `discount` + `tax`) STORED,
  `stock_type` enum('INCOMING','AUDIT') NOT NULL DEFAULT 'INCOMING',
  `incoming_goods_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `outgoing_goods`
--

CREATE TABLE `outgoing_goods` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `inventory_stock_id` int(11) NOT NULL,
  `outgoing_date` date NOT NULL,
  `spb_number` varchar(100) NOT NULL,
  `allocation_plan` varchar(255) NOT NULL,
  `part_number` varchar(100) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(50) NOT NULL DEFAULT 'pcs',
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `price`) STORED,
  `total_price` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `price` - `discount` + `tax`) STORED,
  `invoice_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_outgoing_history`
--

CREATE TABLE `stock_outgoing_history` (
  `id` int(11) NOT NULL,
  `inventory_stock_id` int(11) NOT NULL,
  `outgoing_goods_id` int(11) NOT NULL,
  `outgoing_date` date NOT NULL,
  `spb_number` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `remaining_stock` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('PENDING','ACTIVE','SUSPENDED') NOT NULL DEFAULT 'PENDING',
  `activation_token` varchar(64) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `status`, `activation_token`, `reset_token`, `reset_token_expiry`, `created_at`, `updated_at`) VALUES
(2, 'salsachayaa@gmail.com', '$2y$10$2.ssw6cCMBmvLI376u9PeeyUqkkhjER8TLeG28boFMTUWiYNFrGRe', 'Salsabila Cahaya Hairinisya', '08998554154', 'ACTIVE', NULL, NULL, NULL, '2026-01-08 07:36:20', '2026-01-08 07:37:31');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `incoming_goods`
--
ALTER TABLE `incoming_goods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_invoice_date` (`invoice_date`),
  ADD KEY `idx_invoice_number` (`invoice_number`),
  ADD KEY `idx_vendor` (`vendor`),
  ADD KEY `idx_item_type` (`item_type`),
  ADD KEY `idx_part_number` (`part_number`);

--
-- Indexes for table `inventory_stock`
--
ALTER TABLE `inventory_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_part_number` (`part_number`),
  ADD KEY `idx_location` (`location`),
  ADD KEY `idx_item_type` (`item_type`),
  ADD KEY `idx_current_quantity` (`current_quantity`),
  ADD KEY `idx_incoming_goods_id` (`incoming_goods_id`);

--
-- Indexes for table `outgoing_goods`
--
ALTER TABLE `outgoing_goods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_inventory_stock_id` (`inventory_stock_id`),
  ADD KEY `idx_outgoing_date` (`outgoing_date`),
  ADD KEY `idx_spb_number` (`spb_number`);

--
-- Indexes for table `stock_outgoing_history`
--
ALTER TABLE `stock_outgoing_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_stock_id` (`inventory_stock_id`),
  ADD KEY `idx_outgoing_goods_id` (`outgoing_goods_id`),
  ADD KEY `idx_outgoing_date` (`outgoing_date`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_activation_token` (`activation_token`),
  ADD KEY `idx_reset_token` (`reset_token`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `incoming_goods`
--
ALTER TABLE `incoming_goods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `inventory_stock`
--
ALTER TABLE `inventory_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `outgoing_goods`
--
ALTER TABLE `outgoing_goods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `stock_outgoing_history`
--
ALTER TABLE `stock_outgoing_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `incoming_goods`
--
ALTER TABLE `incoming_goods`
  ADD CONSTRAINT `fk_incoming_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_stock`
--
ALTER TABLE `inventory_stock`
  ADD CONSTRAINT `fk_stock_incoming` FOREIGN KEY (`incoming_goods_id`) REFERENCES `incoming_goods` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_stock_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `outgoing_goods`
--
ALTER TABLE `outgoing_goods`
  ADD CONSTRAINT `fk_outgoing_stock` FOREIGN KEY (`inventory_stock_id`) REFERENCES `inventory_stock` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_outgoing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_outgoing_history`
--
ALTER TABLE `stock_outgoing_history`
  ADD CONSTRAINT `fk_history_outgoing` FOREIGN KEY (`outgoing_goods_id`) REFERENCES `outgoing_goods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_history_stock` FOREIGN KEY (`inventory_stock_id`) REFERENCES `inventory_stock` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
