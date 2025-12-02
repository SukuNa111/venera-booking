-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Nov 12, 2025 at 03:40 AM
-- Server version: 11.5.2-MariaDB
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `booking_app`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE IF NOT EXISTS `app_settings` (
  `key` varchar(64) NOT NULL,
  `value` text NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`key`, `value`, `updated_at`) VALUES
('date_format', '\"YYYY-MM-DD\"', '2025-11-11 08:27:50'),
('default_clinic', '\"venera\"', '2025-11-11 08:27:50'),
('slot_minutes', '\"30\"', '2025-11-11 08:27:50'),
('status_colors', '{\"online\":\"#3b82f6\",\"arrived\":\"#f59e0b\",\"paid\":\"#10b981\",\"pending\":\"#a855f7\",\"cancelled\":\"#ef4444\"}', '2025-11-11 08:27:50'),
('time_format', '\"HH:mm\"', '2025-11-11 08:27:50'),
('timezone', '\"Asia/Ulaanbaatar\"', '2025-11-11 08:27:50'),
('week_start', '\"monday\"', '2025-11-11 08:27:50'),
('work_end', '\"18:00\"', '2025-11-11 08:27:50'),
('work_start', '\"09:00\"', '2025-11-11 08:27:50');

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_id` int(11) DEFAULT NULL,
  `clinic` varchar(50) DEFAULT 'venera',
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `patient_name` varchar(255) NOT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `visit_count` int(11) DEFAULT 1,
  `phone` varchar(20) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `service_name` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `status` varchar(20) DEFAULT 'online',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `source` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_clinic_date` (`clinic`,`date`),
  KEY `idx_doctor_date` (`doctor_id`,`date`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `doctor_id`, `clinic`, `date`, `start_time`, `end_time`, `patient_name`, `gender`, `visit_count`, `phone`, `note`, `service_name`, `price`, `status`, `created_at`, `source`) VALUES
(31, 3, 'venera', '2025-11-11', '10:45:00', '11:15:00', 'Ganaa', 'male', 2, '89370128', 'vddd', 'botox', 0.00, 'paid', '2025-11-11 06:10:26', NULL),
(32, 6, 'luxor', '2025-11-11', '10:45:00', '11:15:00', 'Sodoo', 'male', 1, '89370120', 'v', 'nud', 0.00, 'online', '2025-11-11 07:40:45', NULL),
(24, 2, 'venera', '2025-11-11', '12:15:00', '12:45:00', 'Ganaa', 'male', 1, '89370128', 'vddd', 'botox', 0.00, 'online', '2025-11-11 05:31:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `clinics`
--

DROP TABLE IF EXISTS `clinics`;
CREATE TABLE IF NOT EXISTS `clinics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `theme_color` varchar(20) DEFAULT '#0f3b57',
  `active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `clinics`
--

INSERT INTO `clinics` (`id`, `code`, `name`, `theme_color`, `active`, `sort_order`, `created_at`) VALUES
(1, 'venera', 'Венера', '#0f3b57', 1, 1, '2025-11-11 08:19:40'),
(2, 'luxor', 'Голден Луксор', '#1b5f84', 1, 2, '2025-11-11 08:19:40'),
(3, 'khatan', 'Гоо Хатан', '#7c3aed', 1, 3, '2025-11-11 08:19:40');

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
--

DROP TABLE IF EXISTS `doctors`;
CREATE TABLE IF NOT EXISTS `doctors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `specialty` varchar(100) DEFAULT 'Ерөнхий эмч',
  `color` varchar(7) DEFAULT '#0d6efd',
  `clinic` varchar(50) DEFAULT 'venera',
  `active` tinyint(4) DEFAULT 1,
  `show_in_calendar` tinyint(4) DEFAULT 1,
  `sort_order` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`id`, `name`, `specialty`, `color`, `clinic`, `active`, `sort_order`, `created_at`) VALUES
(1, 'Эрдэнэбүрзэн', 'Ерөнхий эмч', '#3b82f6', 'venera', 1, NULL, '2025-11-08 08:27:21'),
(2, 'Номин', 'Ерөнхий эмч', '#10b981', 'venera', 1, NULL, '2025-11-08 08:27:21'),
(3, 'Сүхэ', 'Ерөнхий эмч', '#f59e0b', 'venera', 1, NULL, '2025-11-08 08:27:21'),
(4, 'Анхаа', 'Ерөнхий эмч', '#a855f7', 'luxor', 1, NULL, '2025-11-08 08:31:42'),
(5, 'Гэлэг', 'Ерөнхий эмч', '#f59e0b', 'luxor', 1, NULL, '2025-11-08 08:31:42'),
(6, 'Сарнай', 'Ерөнхий эмч', '#10b981', 'luxor', 1, NULL, '2025-11-08 08:31:42'),
(7, 'Цэнэ', 'Ерөнхий эмч', '#3b82f6', 'khatan', 1, NULL, '2025-11-08 08:31:42'),
(8, 'Болд', 'Ерөнхий эмч', '#ef4444', 'khatan', 1, NULL, '2025-11-08 08:31:42'),
(9, 'Сэтэм', 'Ерөнхий эмч', '#14b8a6', 'khatan', 1, NULL, '2025-11-08 08:31:42'),
(10, 'Номин', 'Ерөнхий эмч', '#3b82f6', 'venera', 1, 0, '2025-11-12 03:35:56');

-- --------------------------------------------------------

--
-- Table structure for table `sms_log`
--

DROP TABLE IF EXISTS `sms_log`;
CREATE TABLE IF NOT EXISTS `sms_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(20) DEFAULT 'sent',
  `http_code` int(11) DEFAULT NULL,
  `response` text DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking` (`booking_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

DROP TABLE IF EXISTS `feedback`;
CREATE TABLE IF NOT EXISTS `feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(150) DEFAULT NULL,
  `user_role` varchar(20) DEFAULT NULL,
  `clinic_id` varchar(50) DEFAULT NULL,
  `topic` varchar(150) DEFAULT NULL,
  `message` text NOT NULL,
  `status` varchar(20) DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `working_hours`
--

DROP TABLE IF EXISTS `working_hours`;
CREATE TABLE IF NOT EXISTS `working_hours` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '0=Sunday,1=Monday,...,6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_doctor` (`doctor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping sample working hours for existing doctors (Mon-Fri 09:00-18:00, Sat 09:00-17:00)
INSERT INTO `working_hours` (`doctor_id`, `day_of_week`, `start_time`, `end_time`, `is_available`) VALUES
(1,1,'09:00:00','18:00:00',1),(1,2,'09:00:00','18:00:00',1),(1,3,'09:00:00','18:00:00',1),(1,4,'09:00:00','18:00:00',1),(1,5,'09:00:00','18:00:00',1),(1,6,'09:00:00','17:00:00',1),
(2,1,'09:00:00','18:00:00',1),(2,2,'09:00:00','18:00:00',1),(2,3,'09:00:00','18:00:00',1),(2,4,'09:00:00','18:00:00',1),(2,5,'09:00:00','18:00:00',1),(2,6,'09:00:00','17:00:00',1),
(3,1,'09:00:00','18:00:00',1),(3,2,'09:00:00','18:00:00',1),(3,3,'09:00:00','18:00:00',1),(3,4,'09:00:00','18:00:00',1),(3,5,'09:00:00','18:00:00',1),(3,6,'09:00:00','17:00:00',1),
(4,1,'09:00:00','18:00:00',1),(4,2,'09:00:00','18:00:00',1),(4,3,'09:00:00','18:00:00',1),(4,4,'09:00:00','18:00:00',1),(4,5,'09:00:00','18:00:00',1),(4,6,'09:00:00','17:00:00',1),
(5,1,'09:00:00','18:00:00',1),(5,2,'09:00:00','18:00:00',1),(5,3,'09:00:00','18:00:00',1),(5,4,'09:00:00','18:00:00',1),(5,5,'09:00:00','18:00:00',1),(5,6,'09:00:00','17:00:00',1),
(6,1,'09:00:00','18:00:00',1),(6,2,'09:00:00','18:00:00',1),(6,3,'09:00:00','18:00:00',1),(6,4,'09:00:00','18:00:00',1),(6,5,'09:00:00','18:00:00',1),(6,6,'09:00:00','17:00:00',1),
(7,1,'09:00:00','18:00:00',1),(7,2,'09:00:00','18:00:00',1),(7,3,'09:00:00','18:00:00',1),(7,4,'09:00:00','18:00:00',1),(7,5,'09:00:00','18:00:00',1),(7,6,'09:00:00','17:00:00',1),
(8,1,'09:00:00','18:00:00',1),(8,2,'09:00:00','18:00:00',1),(8,3,'09:00:00','18:00:00',1),(8,4,'09:00:00','18:00:00',1),(8,5,'09:00:00','18:00:00',1),(8,6,'09:00:00','17:00:00',1),
(9,1,'09:00:00','18:00:00',1),(9,2,'09:00:00','18:00:00',1),(9,3,'09:00:00','18:00:00',1),(9,4,'09:00:00','18:00:00',1),(9,5,'09:00:00','18:00:00',1),(9,6,'09:00:00','17:00:00',1),
(10,1,'09:00:00','18:00:00',1),(10,2,'09:00:00','18:00:00',1),(10,3,'09:00:00','18:00:00',1),(10,4,'09:00:00','18:00:00',1),(10,5,'09:00:00','18:00:00',1),(10,6,'09:00:00','17:00:00',1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `role` enum('admin','reception','doctor') DEFAULT 'reception',
  `clinic_id` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `phone`, `pin_hash`, `role`, `clinic_id`, `created_at`) VALUES
(1, 'Админ', '99999999', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'admin', 'all', '2025-11-07 03:40:43'),
(2, 'Болор', '88888888', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'reception', 'venera', '2025-11-07 03:40:43'),
(3, 'Эрдэнэбүрэн', '77777777', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'doctor', 'venera', '2025-11-07 03:40:43');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
