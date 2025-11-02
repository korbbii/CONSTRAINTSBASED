-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Nov 02, 2025 at 01:05 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `automate_schedule`
--

-- --------------------------------------------------------

--
-- Table structure for table `drafts`
--

CREATE TABLE `drafts` (
  `draft_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `draft_name` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `draft_entries`
--

CREATE TABLE `draft_entries` (
  `draft_entry_id` bigint(20) UNSIGNED NOT NULL,
  `draft_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `subject_id` bigint(20) UNSIGNED NOT NULL,
  `instructor_id` bigint(20) UNSIGNED NOT NULL,
  `section_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('planned','confirmed') NOT NULL DEFAULT 'planned',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `draft_meetings`
--

CREATE TABLE `draft_meetings` (
  `draft_meeting_id` bigint(20) UNSIGNED NOT NULL,
  `draft_entry_id` bigint(20) UNSIGNED NOT NULL,
  `instructor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `day` enum('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room_id` bigint(20) UNSIGNED NOT NULL,
  `meeting_type` enum('lecture','lab') NOT NULL DEFAULT 'lecture',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `instructors`
--

CREATE TABLE `instructors` (
  `instructor_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `employment_type` enum('FULL-TIME','PART-TIME') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '2014_10_12_000000_create_users_table', 1),
(2, '2014_10_12_100000_create_password_reset_tokens_table', 1),
(3, '2019_08_19_000000_create_failed_jobs_table', 1),
(4, '2019_12_14_000001_create_personal_access_tokens_table', 1),
(5, '2024_01_01_000001_5_create_schedule_groups_table', 1),
(6, '2024_01_01_000001_create_rooms_table', 1),
(7, '2024_01_01_000002_create_schedule_entries_table', 1),
(8, '2024_01_01_000003_create_drafts_table', 1),
(9, '2024_01_01_000004_add_employment_type_to_schedule_entries_table', 1),
(10, '2025_01_15_000001_create_reference_schedules_table', 1),
(11, '2025_08_07_213322_add_draft_name_to_drafts_table', 1),
(12, '2025_09_04_000001_create_instructors_table', 1),
(13, '2025_09_04_000002_create_subjects_table', 1),
(14, '2025_09_04_000003_create_sections_table', 1),
(15, '2025_09_04_000008_create_schedule_meetings_table', 1),
(16, '2025_09_04_000009_create_draft_entries_table', 1),
(17, '2025_09_04_000010_create_draft_meetings_table', 1),
(18, '2025_09_04_000011_alter_rooms_add_unique_and_active_flag', 1),
(19, '2025_09_04_000012_alter_schedule_groups_add_composite_unique', 1),
(20, '2025_09_04_000013_alter_schedule_entries_normalize_structure', 1),
(21, '2025_09_04_000014_alter_drafts_add_created_by_and_unique', 1),
(22, '2025_10_10_222044_add_building_and_floor_level_to_rooms_table', 1),
(23, '2025_10_11_001434_add_subject_to_reference_schedules_table', 1),
(24, '2025_10_11_025706_update_reference_schedules_table_structure', 1),
(25, '2025_10_15_094436_add_conflict_detection_indexes', 1),
(26, '2025_10_30_221600_add_indexes_to_schedule_meetings', 1),
(27, '2025_10_30_221700_add_indexes_to_schedule_entries', 1),
(28, '2025_10_31_000001_add_schedule_indexes', 1);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reference_groups`
--

CREATE TABLE `reference_groups` (
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `school_year` varchar(255) NOT NULL,
  `education_level` varchar(255) NOT NULL,
  `year_level` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reference_schedules`
--

CREATE TABLE `reference_schedules` (
  `reference_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `time` varchar(255) NOT NULL,
  `day` enum('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  `room` varchar(255) NOT NULL,
  `instructor` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `room_id` bigint(20) UNSIGNED NOT NULL,
  `room_name` varchar(255) NOT NULL,
  `building` varchar(255) DEFAULT NULL,
  `floor_level` varchar(255) DEFAULT NULL,
  `capacity` int(11) NOT NULL,
  `is_lab` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`room_id`, `room_name`, `building`, `floor_level`, `capacity`, `is_lab`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'ANNEX 101', 'Annex Building', '1', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(2, 'ANNEX 102', 'Annex Building', '1', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(3, 'ANNEX 103', 'Annex Building', '1', 45, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(4, 'ANNEX 104', 'Annex Building', '1', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(5, 'ANNEX 105', 'Annex Building', '1', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(6, 'ANNEX 106', 'Annex Building', '1', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(7, 'ANNEX 208', 'Annex Building', '2', 50, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(8, 'ANNEX 207', 'Annex Building', '2', 45, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(9, 'ANNEX 209', 'Annex Building', '2', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(10, 'ANNEX 204', 'Annex Building', '2', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(11, 'ANNEX 203', 'Annex Building', '2', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(12, 'ANNEX 202', 'Annex Building', '2', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(13, 'ANNEX 301', 'Annex Building', '3', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(14, 'ANNEX 302', 'Annex Building', '3', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(15, 'ANNEX 303', 'Annex Building', '3', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(16, 'ANNEX 304', 'Annex Building', '3', 45, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(17, 'ANNEX 305', 'Annex Building', '3', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(18, 'ANNEX 306', 'Annex Building', '3', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(19, 'ANNEX 307', 'Annex Building', '3', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(20, 'ANNEX 407', 'Annex Building', '4', 50, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(21, 'ANNEX 406', 'Annex Building', '4', 45, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(22, 'ANNEX 405', 'Annex Building', '4', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(23, 'ANNEX 404', 'Annex Building', '4', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(24, 'ANNEX 403', 'Annex Building', '4', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(25, 'ANNEX 402', 'Annex Building', '4', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(26, 'ANNEX 401', 'Annex Building', '4', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(27, 'SHS 109', 'SHS Building', '1', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(28, 'SHS 111', 'SHS Building', '1', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(29, 'SHS 108', 'SHS Building', '1', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(30, 'SHS 107', 'SHS Building', '1', 25, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(31, 'SSH 112', 'SHS Building', '1', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(32, 'SHS 205', 'SHS Building', '2', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(33, 'SHS 206', 'SHS Building', '2', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(34, 'SHS 209', 'SHS Building', '2', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(35, 'HS 215', 'HS Building', '2', 45, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(36, 'HS 214', 'HS Building', '2', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(37, 'HS 210', 'HS Building', '2', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(38, 'HS 209', 'HS Building', '2', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(39, 'HS 208', 'HS Building', '2', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(40, 'HS 206', 'HS Building', '2', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(41, 'HS 205', 'HS Building', '2', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(42, 'HS 204', 'HS Building', '2', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(43, 'HS 203', 'HS Building', '2', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(44, 'HS 309', 'HS Building', '3', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(45, 'HS 310', 'HS Building', '3', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(46, 'HS 308', 'HS Building', '3', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(47, 'HS 307', 'HS Building', '3', 45, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(48, 'HS 306', 'HS Building', '3', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(49, 'HS 311', 'HS Building', '3', 40, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(50, 'HS 312', 'HS Building', '3', 30, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(51, 'HS 313', 'HS Building', '3', 35, 0, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(52, 'LAB 1', 'Laboratory Building', '1', 25, 1, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(53, 'LAB 2', 'Laboratory Building', '1', 30, 1, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(54, 'HS Lab', 'HS Building', '1', 20, 1, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32'),
(55, 'SHS Lab', 'SHS Building', '1', 25, 1, 1, '2025-11-01 16:05:32', '2025-11-01 16:05:32');

-- --------------------------------------------------------

--
-- Table structure for table `schedule_entries`
--

CREATE TABLE `schedule_entries` (
  `entry_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `subject_id` bigint(20) UNSIGNED NOT NULL,
  `section_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('planned','confirmed') NOT NULL DEFAULT 'confirmed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedule_groups`
--

CREATE TABLE `schedule_groups` (
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `department` varchar(255) NOT NULL,
  `school_year` varchar(255) NOT NULL,
  `semester` enum('1st Semester','2nd Semester','Summer') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedule_meetings`
--

CREATE TABLE `schedule_meetings` (
  `meeting_id` bigint(20) UNSIGNED NOT NULL,
  `entry_id` bigint(20) UNSIGNED NOT NULL,
  `instructor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `day` enum('Mon','Tue','Wed','Thu','Fri','Sat') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room_id` bigint(20) UNSIGNED NOT NULL,
  `meeting_type` enum('lecture','lab') NOT NULL DEFAULT 'lecture',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `section_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `year_level` tinyint(3) UNSIGNED NOT NULL,
  `department` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `subject_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `units` tinyint(3) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `drafts`
--
ALTER TABLE `drafts`
  ADD PRIMARY KEY (`draft_id`),
  ADD UNIQUE KEY `drafts_group_draft_name_unique` (`group_id`,`draft_name`),
  ADD KEY `drafts_created_by_foreign` (`created_by`);

--
-- Indexes for table `draft_entries`
--
ALTER TABLE `draft_entries`
  ADD PRIMARY KEY (`draft_entry_id`),
  ADD UNIQUE KEY `draft_entries_core_unique` (`draft_id`,`subject_id`,`instructor_id`,`section_id`),
  ADD KEY `draft_entries_group_id_foreign` (`group_id`),
  ADD KEY `draft_entries_subject_id_foreign` (`subject_id`),
  ADD KEY `draft_entries_instructor_id_foreign` (`instructor_id`),
  ADD KEY `draft_entries_section_id_foreign` (`section_id`);

--
-- Indexes for table `draft_meetings`
--
ALTER TABLE `draft_meetings`
  ADD PRIMARY KEY (`draft_meeting_id`),
  ADD KEY `draft_meetings_draft_entry_id_foreign` (`draft_entry_id`),
  ADD KEY `draft_meetings_room_id_foreign` (`room_id`),
  ADD KEY `draft_meetings_instructor_id_day_start_time_end_time_index` (`instructor_id`,`day`,`start_time`,`end_time`),
  ADD KEY `draft_meetings_day_start_time_end_time_room_id_index` (`day`,`start_time`,`end_time`,`room_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `instructors`
--
ALTER TABLE `instructors`
  ADD PRIMARY KEY (`instructor_id`),
  ADD KEY `instructors_name_index` (`name`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `reference_groups`
--
ALTER TABLE `reference_groups`
  ADD PRIMARY KEY (`group_id`),
  ADD UNIQUE KEY `unique_reference_group` (`school_year`,`education_level`,`year_level`),
  ADD KEY `reference_groups_school_year_education_level_year_level_index` (`school_year`,`education_level`,`year_level`);

--
-- Indexes for table `reference_schedules`
--
ALTER TABLE `reference_schedules`
  ADD PRIMARY KEY (`reference_id`),
  ADD KEY `reference_schedules_day_time_index` (`day`,`time`),
  ADD KEY `reference_schedules_room_day_time_index` (`room`,`day`,`time`),
  ADD KEY `reference_schedules_instructor_day_time_index` (`instructor`,`day`,`time`),
  ADD KEY `reference_schedules_group_id_index` (`group_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`room_id`),
  ADD UNIQUE KEY `rooms_room_name_unique` (`room_name`);

--
-- Indexes for table `schedule_entries`
--
ALTER TABLE `schedule_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD UNIQUE KEY `schedule_entries_unique_core` (`group_id`,`subject_id`,`section_id`),
  ADD KEY `schedule_entries_subject_id_foreign` (`subject_id`),
  ADD KEY `idx_entries_group_subject_section` (`group_id`,`subject_id`,`section_id`),
  ADD KEY `idx_entries_section_id` (`section_id`),
  ADD KEY `idx_entries_group_subject` (`group_id`,`subject_id`),
  ADD KEY `idx_entries_group_section` (`group_id`,`section_id`);

--
-- Indexes for table `schedule_groups`
--
ALTER TABLE `schedule_groups`
  ADD PRIMARY KEY (`group_id`);

--
-- Indexes for table `schedule_meetings`
--
ALTER TABLE `schedule_meetings`
  ADD PRIMARY KEY (`meeting_id`),
  ADD KEY `schedule_meetings_instructor_id_day_start_time_end_time_index` (`instructor_id`,`day`,`start_time`,`end_time`),
  ADD KEY `schedule_meetings_day_start_time_end_time_room_id_index` (`day`,`start_time`,`end_time`,`room_id`),
  ADD KEY `idx_meetings_day_time_room` (`day`,`start_time`,`end_time`,`room_id`),
  ADD KEY `idx_meetings_day_time_instructor` (`day`,`start_time`,`end_time`,`instructor_id`),
  ADD KEY `idx_meetings_entry_id` (`entry_id`),
  ADD KEY `idx_meetings_day_start_end` (`day`,`start_time`,`end_time`),
  ADD KEY `idx_meetings_instructor_id` (`instructor_id`),
  ADD KEY `idx_meetings_room_id` (`room_id`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`section_id`),
  ADD UNIQUE KEY `sections_code_unique` (`code`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`subject_id`),
  ADD UNIQUE KEY `subjects_code_unique` (`code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `drafts`
--
ALTER TABLE `drafts`
  MODIFY `draft_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `draft_entries`
--
ALTER TABLE `draft_entries`
  MODIFY `draft_entry_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `draft_meetings`
--
ALTER TABLE `draft_meetings`
  MODIFY `draft_meeting_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `instructors`
--
ALTER TABLE `instructors`
  MODIFY `instructor_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reference_groups`
--
ALTER TABLE `reference_groups`
  MODIFY `group_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reference_schedules`
--
ALTER TABLE `reference_schedules`
  MODIFY `reference_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `schedule_entries`
--
ALTER TABLE `schedule_entries`
  MODIFY `entry_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_groups`
--
ALTER TABLE `schedule_groups`
  MODIFY `group_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_meetings`
--
ALTER TABLE `schedule_meetings`
  MODIFY `meeting_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `section_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `subject_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `drafts`
--
ALTER TABLE `drafts`
  ADD CONSTRAINT `drafts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `drafts_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `schedule_groups` (`group_id`) ON DELETE CASCADE;

--
-- Constraints for table `draft_entries`
--
ALTER TABLE `draft_entries`
  ADD CONSTRAINT `draft_entries_draft_id_foreign` FOREIGN KEY (`draft_id`) REFERENCES `drafts` (`draft_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `draft_entries_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `schedule_groups` (`group_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `draft_entries_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `instructors` (`instructor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `draft_entries_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `draft_entries_subject_id_foreign` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE;

--
-- Constraints for table `draft_meetings`
--
ALTER TABLE `draft_meetings`
  ADD CONSTRAINT `draft_meetings_draft_entry_id_foreign` FOREIGN KEY (`draft_entry_id`) REFERENCES `draft_entries` (`draft_entry_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `draft_meetings_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `instructors` (`instructor_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `draft_meetings_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`) ON DELETE CASCADE;

--
-- Constraints for table `reference_schedules`
--
ALTER TABLE `reference_schedules`
  ADD CONSTRAINT `reference_schedules_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `reference_groups` (`group_id`) ON DELETE CASCADE;

--
-- Constraints for table `schedule_entries`
--
ALTER TABLE `schedule_entries`
  ADD CONSTRAINT `schedule_entries_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `schedule_groups` (`group_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedule_entries_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`),
  ADD CONSTRAINT `schedule_entries_subject_id_foreign` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`);

--
-- Constraints for table `schedule_meetings`
--
ALTER TABLE `schedule_meetings`
  ADD CONSTRAINT `schedule_meetings_entry_id_foreign` FOREIGN KEY (`entry_id`) REFERENCES `schedule_entries` (`entry_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedule_meetings_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `instructors` (`instructor_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `schedule_meetings_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
