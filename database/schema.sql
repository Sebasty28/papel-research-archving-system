--
-- PAPEL — database structure
--
-- Everything the app needs to run, and nothing that belongs to anybody: no
-- students, no papers, no notifications. Importing this gives you an empty
-- system, and scripts/setup.php will then create the first Director account so
-- you can sign in and build the rest from the app.
--
-- To use it directly:
--     mysql -u root -p < database/schema.sql
--
-- Or let the installer do it, which also checks the rest of the environment:
--     php scripts/setup.php
--
-- Generated from a working database on 2026-08-21.
-- Regenerate with scripts/dump_schema.php after any migration.
--

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `capstone_db`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `capstone_db`;

--
-- ai_processing_log
--
CREATE TABLE IF NOT EXISTS `ai_processing_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `paper_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `operation_type` varchar(50) DEFAULT NULL,
  `ip_protection_level` varchar(50) DEFAULT NULL,
  `content_filtered` tinyint(1) DEFAULT 0,
  `sensitive_data_removed` text DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- ai_rate_limits
--
CREATE TABLE IF NOT EXISTS `ai_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_action_time` (`user_id`,`action`,`created_at`),
  KEY `idx_action_time` (`action`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- analytics
--
CREATE TABLE IF NOT EXISTS `analytics` (
  `analytics_id` int(11) NOT NULL AUTO_INCREMENT,
  `paper_id` int(11) NOT NULL,
  `view_count` int(11) DEFAULT 0,
  `download_count` int(11) DEFAULT 0,
  `citation_count` int(11) DEFAULT 0,
  `approval_date` timestamp NULL DEFAULT NULL,
  `time_to_approval` int(11) DEFAULT NULL COMMENT 'Days from submission to approval',
  PRIMARY KEY (`analytics_id`),
  KEY `fk_analytics_paper` (`paper_id`),
  CONSTRAINT `fk_analytics_paper` FOREIGN KEY (`paper_id`) REFERENCES `research_papers` (`paper_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=404 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- approval_workflow
--
CREATE TABLE IF NOT EXISTS `approval_workflow` (
  `workflow_id` int(11) NOT NULL AUTO_INCREMENT,
  `paper_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `review_level` enum('faculty','admin','head_academic','super_admin') NOT NULL,
  `status` enum('pending','approved','declined') DEFAULT 'pending',
  `feedback` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_level` tinyint(4) DEFAULT NULL COMMENT 'Admin level that reviewed (1 or 2)',
  PRIMARY KEY (`workflow_id`),
  KEY `fk_workflow_paper` (`paper_id`),
  KEY `fk_workflow_reviewer` (`reviewer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=156 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- gdrive_settings
--
CREATE TABLE IF NOT EXISTS `gdrive_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- guest_sessions
--
CREATE TABLE IF NOT EXISTS `guest_sessions` (
  `guest_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `plain_password` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`guest_id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- imrad_checklist
--
CREATE TABLE IF NOT EXISTS `imrad_checklist` (
  `checklist_id` int(11) NOT NULL AUTO_INCREMENT,
  `paper_id` int(11) NOT NULL,
  `has_introduction` tinyint(1) DEFAULT 0,
  `has_methods` tinyint(1) DEFAULT 0,
  `has_results` tinyint(1) DEFAULT 0,
  `has_discussion` tinyint(1) DEFAULT 0,
  `has_abstract` tinyint(1) DEFAULT 0,
  `has_references` tinyint(1) DEFAULT 0,
  `checked_by` int(11) DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`checklist_id`),
  KEY `fk_imrad_paper` (`paper_id`),
  KEY `fk_imrad_checked_by` (`checked_by`),
  CONSTRAINT `fk_imrad_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_imrad_paper` FOREIGN KEY (`paper_id`) REFERENCES `research_papers` (`paper_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- login_attempts
--
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `scope` varchar(190) NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `first_at` datetime NOT NULL,
  `last_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`scope`),
  KEY `idx_last_at` (`last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- notification_schedule
--
CREATE TABLE IF NOT EXISTS `notification_schedule` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `scheduled_time` time NOT NULL COMMENT '10:00 and 15:30',
  `last_sent` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`schedule_id`),
  KEY `fk_notif_sched_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- notifications
--
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `paper_id` int(11) DEFAULT NULL,
  `notification_type` enum('submission','approval','decline','reminder','comment','security','support','account') NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `fk_notif_user` (`user_id`),
  KEY `fk_notif_paper` (`paper_id`)
) ENGINE=InnoDB AUTO_INCREMENT=293 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- paper_checklist
--
CREATE TABLE IF NOT EXISTS `paper_checklist` (
  `checklist_id` int(11) NOT NULL AUTO_INCREMENT,
  `paper_id` int(11) NOT NULL,
  `imrad_intro` tinyint(1) DEFAULT 0,
  `imrad_method` tinyint(1) DEFAULT 0,
  `imrad_result` tinyint(1) DEFAULT 0,
  `imrad_discussion` tinyint(1) DEFAULT 0,
  `imrad_references` tinyint(1) DEFAULT 0,
  `full_ch1` tinyint(1) DEFAULT 0,
  `full_ch2` tinyint(1) DEFAULT 0,
  `full_ch3` tinyint(1) DEFAULT 0,
  `full_ch4` tinyint(1) DEFAULT 0,
  `full_ch5` tinyint(1) DEFAULT 0,
  `full_references` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`checklist_id`),
  UNIQUE KEY `unique_paper` (`paper_id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- paper_favorites
--
CREATE TABLE IF NOT EXISTS `paper_favorites` (
  `favorite_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `paper_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`favorite_id`),
  UNIQUE KEY `unique_fav` (`user_id`,`paper_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- papers_archive
--
CREATE TABLE IF NOT EXISTS `papers_archive` (
  `paper_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author_names` text DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `research_date` date DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `imrad_content` longtext DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `publication_details` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `paper_type` varchar(50) DEFAULT NULL,
  `paper_format` enum('IMRAD','Manuscript') DEFAULT 'IMRAD',
  `gdrive_file_id` varchar(255) DEFAULT NULL,
  `ai_summary` text DEFAULT NULL,
  `ai_methodology` text DEFAULT NULL,
  `ai_sample_size` varchar(255) DEFAULT NULL,
  `ai_statistical_methods` text DEFAULT NULL,
  `ai_variables` text DEFAULT NULL,
  `ai_research_field` varchar(255) DEFAULT NULL,
  `upload_date` datetime DEFAULT NULL,
  `archived_date` datetime DEFAULT current_timestamp(),
  `archived_by` int(11) DEFAULT NULL,
  `research_type` varchar(100) DEFAULT NULL,
  `manuscript_type` varchar(100) DEFAULT NULL,
  `publication_status` varchar(255) DEFAULT 'Unpublished Paper',
  `publication_location` varchar(255) DEFAULT NULL,
  `program_category` varchar(100) DEFAULT NULL,
  `source_code_path` varchar(255) DEFAULT NULL,
  `current_status` varchar(32) DEFAULT NULL COMMENT 'status the paper held when it was archived',
  `is_imrad_complete` tinyint(1) DEFAULT 0,
  `ai_analyzed_at` timestamp NULL DEFAULT NULL,
  `backup_status` varchar(50) DEFAULT 'none',
  PRIMARY KEY (`paper_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- password_changes
--
CREATE TABLE IF NOT EXISTS `password_changes` (
  `change_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`change_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_changed_at` (`changed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- research_papers
--
CREATE TABLE IF NOT EXISTS `research_papers` (
  `paper_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(500) NOT NULL,
  `author_names` text NOT NULL,
  `year` int(11) NOT NULL,
  `research_date` date DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `imrad_content` longtext DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `publication_details` text DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `source_code_path` varchar(255) DEFAULT NULL,
  `gdrive_file_id` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `current_status` enum('draft','pending_faculty','pending_admin','pending_admin_l1','pending_head_academic','pending_super_admin','approved','declined','archived') DEFAULT 'draft',
  `paper_type` varchar(50) DEFAULT 'research',
  `research_type` varchar(100) DEFAULT NULL,
  `manuscript_type` varchar(100) DEFAULT NULL,
  `publication_status` varchar(255) DEFAULT 'Unpublished Paper',
  `publication_location` varchar(255) DEFAULT NULL,
  `program_category` varchar(255) DEFAULT NULL,
  `paper_format` enum('IMRAD','Manuscript') DEFAULT 'IMRAD',
  `is_imrad_complete` tinyint(1) DEFAULT 0,
  `ai_summary` text DEFAULT NULL COMMENT 'AI-generated summary of the research',
  `ai_methodology` varchar(255) DEFAULT NULL COMMENT 'Research methodology type',
  `ai_sample_size` varchar(255) DEFAULT NULL COMMENT 'Sample size information',
  `ai_statistical_methods` text DEFAULT NULL COMMENT 'Statistical methods used',
  `ai_variables` text DEFAULT NULL COMMENT 'Main variables studied',
  `ai_research_field` varchar(255) DEFAULT NULL COMMENT 'Field of research',
  `ai_analyzed_at` timestamp NULL DEFAULT NULL COMMENT 'When AI analysis was performed',
  `backup_status` varchar(50) DEFAULT 'none',
  PRIMARY KEY (`paper_id`),
  KEY `fk_papers_uploaded_by` (`uploaded_by`),
  KEY `idx_papers_year` (`year`),
  KEY `idx_papers_status` (`current_status`),
  KEY `idx_ai_research_field` (`ai_research_field`),
  KEY `idx_current_status` (`current_status`),
  KEY `idx_research_papers_research_date` (`research_date`),
  FULLTEXT KEY `ft_papers_text` (`title`,`abstract`,`keywords`)
) ENGINE=InnoDB AUTO_INCREMENT=203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- storage_usage
--
CREATE TABLE IF NOT EXISTS `storage_usage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `check_timestamp` datetime DEFAULT current_timestamp(),
  `gdrive_total_mb` decimal(10,2) DEFAULT 0.00,
  `local_backup_mb` decimal(10,2) DEFAULT 0.00,
  `total_papers` int(11) DEFAULT 0,
  `backed_up_papers` int(11) DEFAULT 0,
  `note` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- support_requests
--
CREATE TABLE IF NOT EXISTS `support_requests` (
  `request_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('password','account') NOT NULL,
  `requester_name` varchar(200) NOT NULL,
  `requester_email` varchar(150) NOT NULL,
  `requester_role` varchar(32) NOT NULL,
  `requester_ident` varchar(50) NOT NULL,
  `requester_user_id` int(11) DEFAULT NULL,
  `handler_role` varchar(32) NOT NULL,
  `handler_user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`request_id`),
  KEY `idx_handler` (`handler_user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_kind` (`kind`)
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- supporting_documents
--
CREATE TABLE IF NOT EXISTS `supporting_documents` (
  `doc_id` int(11) NOT NULL AUTO_INCREMENT,
  `paper_id` int(11) NOT NULL,
  `document_type` enum('ethics_clearance','consent_form','data_collection','other') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `gdrive_file_id` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`doc_id`),
  KEY `fk_docs_paper` (`paper_id`)
) ENGINE=InnoDB AUTO_INCREMENT=425 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- system_settings
--
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL DEFAULT '',
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- users
--
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `birthdate` date DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `user_role` enum('super_admin','admin','head_academic','faculty','librarian','student','guest') NOT NULL,
  `admin_type` enum('research_coordinator','librarian','hap','director') DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_otp_exempt` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Exempt from OTP, 0 = Requires OTP',
  `gdrive_token` text DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `faculty_id` varchar(50) DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `section` varchar(40) DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `admin_level` tinyint(4) DEFAULT 1 COMMENT '1=Research Coordinator, 2=HAP (Head of Academic Programs)',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `idx_student_id` (`student_id`),
  KEY `fk_users_created_by` (`created_by`),
  KEY `idx_reset_token` (`reset_token`)
) ENGINE=InnoDB AUTO_INCREMENT=351 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
