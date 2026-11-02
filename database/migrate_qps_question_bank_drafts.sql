-- ============================================================================
-- Holy Cross College (Autonomous) - Complete Database Schema & Migrations
-- Safe for fresh installations and existing databases on hccweb-2 / XAMPP
-- ============================================================================

-- 1. qps_question_bank_drafts
CREATE TABLE IF NOT EXISTS `qps_question_bank_drafts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_code` VARCHAR(100) NOT NULL DEFAULT 'STAFF',
  `dept_code` VARCHAR(50) NULL,
  `dept_name` VARCHAR(255) NULL,
  `paper_code` VARCHAR(100) NOT NULL DEFAULT '',
  `course_title` VARCHAR(255) NULL,
  `course_type` VARCHAR(50) NOT NULL DEFAULT 'OBE',
  `semester` VARCHAR(50) NOT NULL DEFAULT 'Semester 1',
  `academic_year` VARCHAR(50) NOT NULL DEFAULT '2026-2027',
  `exam_type` VARCHAR(120) NOT NULL DEFAULT 'Odd Semester End Examination',
  `regulation` VARCHAR(80) NULL,
  `degree_level` VARCHAR(30) NULL,
  `max_marks` INT NOT NULL DEFAULT 75,
  `total_questions` INT NOT NULL DEFAULT 0,
  `total_marks` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(50) NOT NULL DEFAULT 'DRAFT',
  `source_format` VARCHAR(20) NULL,
  `source_file_name` VARCHAR(255) NULL,
  `source_path` VARCHAR(700) NULL,
  `archive_path` VARCHAR(700) NULL,
  `questions_json` LONGTEXT NULL,
  `qbb_id` BIGINT UNSIGNED NULL,
  `qbb_checked` TINYINT(1) NOT NULL DEFAULT 0,
  `qbb_check_result_json` LONGTEXT NULL,
  `qbb_checked_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submitted_at` DATETIME NULL,
  `hod_reviewed_by` VARCHAR(100) NULL,
  `hod_reviewed_at` DATETIME NULL,
  `hod_remarks` TEXT NULL,
  `coe_reviewed_by` VARCHAR(100) NULL,
  `coe_reviewed_at` DATETIME NULL,
  `coe_remarks` TEXT NULL,
  `review_comments` TEXT NULL,
  `version_no` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_course` (`paper_code`, `semester`, `academic_year`),
  KEY `idx_staff` (`staff_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. qps_question_bank_draft_questions
CREATE TABLE IF NOT EXISTS `qps_question_bank_draft_questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `draft_id` BIGINT UNSIGNED NULL,
  `fixed_q_no` INT UNSIGNED NOT NULL DEFAULT 1,
  `staff_code` VARCHAR(100) NOT NULL DEFAULT 'STAFF',
  `paper_code` VARCHAR(100) NOT NULL DEFAULT '',
  `semester` VARCHAR(50) NULL,
  `academic_year` VARCHAR(50) NULL,
  `q_number` INT NOT NULL DEFAULT 0,
  `unit_no` INT NOT NULL DEFAULT 1,
  `sub_unit` VARCHAR(50) NOT NULL DEFAULT '1.1',
  `section_type` VARCHAR(50) NOT NULL DEFAULT 'SECTION-A',
  `question_type` VARCHAR(50) NOT NULL DEFAULT 'MCQ',
  `question_text` LONGTEXT NOT NULL,
  `marks` INT NOT NULL DEFAULT 1,
  `k_level` VARCHAR(20) NOT NULL DEFAULT 'K1',
  `co_level` VARCHAR(20) NOT NULL DEFAULT 'CO1',
  `options_json` LONGTEXT NULL,
  `answer_key` TEXT NULL,
  `match_column_a` LONGTEXT NULL,
  `match_column_b` LONGTEXT NULL,
  `assertion_text` TEXT NULL,
  `reason_text` TEXT NULL,
  `image_url` VARCHAR(700) NULL,
  `has_formula` TINYINT(1) NOT NULL DEFAULT 0,
  `formula_latex` TEXT NULL,
  `language` VARCHAR(20) NOT NULL DEFAULT 'en',
  `correction_status` VARCHAR(50) NOT NULL DEFAULT 'CLEAN',
  `remarks` TEXT NULL,
  `version_no` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_draft` (`draft_id`),
  KEY `idx_course` (`paper_code`, `semester`, `academic_year`),
  KEY `idx_staff` (`staff_code`),
  KEY `idx_unit` (`unit_no`, `sub_unit`),
  KEY `idx_correction` (`correction_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. qps_audit_log
CREATE TABLE IF NOT EXISTS `qps_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_code` VARCHAR(100) NULL,
  `staff_name` VARCHAR(255) NULL,
  `staff_mobile` VARCHAR(50) NULL,
  `role` VARCHAR(50) NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(100) NULL,
  `entity_id` VARCHAR(100) NULL,
  `details` LONGTEXT NULL,
  `ip_address` VARCHAR(100) NULL,
  `actor` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_code`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. qps_question_bank_blueprints
CREATE TABLE IF NOT EXISTS `qps_question_bank_blueprints` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_code` VARCHAR(100) NOT NULL,
  `semester` VARCHAR(50) NOT NULL DEFAULT 'Semester 1',
  `academic_year` VARCHAR(50) NOT NULL DEFAULT '2026-2027',
  `unit_no` INT NOT NULL DEFAULT 1,
  `sub_unit` VARCHAR(50) NOT NULL DEFAULT '1.1',
  `question_type` VARCHAR(50) NOT NULL DEFAULT 'MCQ',
  `k_level` VARCHAR(20) NOT NULL DEFAULT 'K1',
  `co_level` VARCHAR(20) NOT NULL DEFAULT 'CO1',
  `marks` INT NOT NULL DEFAULT 1,
  `available_count` INT NOT NULL DEFAULT 0,
  `version_no` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_qbb_cell` (`course_code`, `semester`, `academic_year`, `unit_no`, `sub_unit`, `question_type`, `k_level`, `marks`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Safe Column Alterations for Existing Installations
SET @dbname = DATABASE();

-- Alter qps_question_bank_draft_questions
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'staff_code', "VARCHAR(100) NOT NULL DEFAULT 'STAFF'");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'paper_code', "VARCHAR(100) NOT NULL DEFAULT ''");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'fixed_q_no', "INT UNSIGNED NOT NULL DEFAULT 1");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'match_column_a', "LONGTEXT NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'match_column_b', "LONGTEXT NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'assertion_text', "TEXT NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'reason_text', "TEXT NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'image_url', "VARCHAR(700) NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'correction_status', "VARCHAR(50) NOT NULL DEFAULT 'CLEAN'");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'remarks', "TEXT NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_question_bank_draft_questions', 'version_no', "INT UNSIGNED NOT NULL DEFAULT 1");

-- Alter qps_audit_log
CALL AddColumnUnlessExists(@dbname, 'qps_audit_log', 'staff_code', "VARCHAR(100) NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_audit_log', 'staff_name', "VARCHAR(255) NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_audit_log', 'staff_mobile', "VARCHAR(50) NULL");
CALL AddColumnUnlessExists(@dbname, 'qps_audit_log', 'ip_address', "VARCHAR(100) NULL");
