-- ============================================================================
-- PHASE 1: Audit + Schema + Shared Helpers + Static Logins + Role Guards + Audit Log
-- Holy Cross College (Autonomous) - COE Question Paper System
-- Safe for fresh installations and existing databases on hccweb / XAMPP
-- Run idempotently: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS
-- ============================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Helper procedure for safe column additions (MySQL/MariaDB)
-- ----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `qps_add_column_if_missing`;
DELIMITER $$
CREATE PROCEDURE `qps_add_column_if_missing`(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    DECLARE v_table INT DEFAULT 0;
    DECLARE v_column INT DEFAULT 0;
    SELECT COUNT(*) INTO v_table
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;

    IF v_table > 0 THEN
        SELECT COUNT(*) INTO v_column
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column;

        IF v_column = 0 THEN
            SET @qps_sql = CONCAT('ALTER TABLE `', REPLACE(p_table, '`', '``'), '` ADD COLUMN `', REPLACE(p_column, '`', '``'), '` ', p_definition);
            PREPARE qps_stmt FROM @qps_sql;
            EXECUTE qps_stmt;
            DEALLOCATE PREPARE qps_stmt;
        END IF;
    END IF;
END$$
DELIMITER ;

-- ============================================================================
-- 1. EXTEND EXISTING TABLES WITH MISSING COLUMNS
-- ============================================================================

-- 1.1 questions table: add used_in, status, verified fields
CALL qps_add_column_if_missing('questions', 'course_code', 'VARCHAR(100) NULL');
CALL qps_add_column_if_missing('questions', 'semester', 'VARCHAR(50) NULL');
CALL qps_add_column_if_missing('questions', 'academic_year', 'VARCHAR(50) NULL');
CALL qps_add_column_if_missing('questions', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_add_column_if_missing('questions', 'used_in', 'JSON NULL COMMENT "JSON array of academic years used"');
CALL qps_add_column_if_missing('questions', 'status', 'VARCHAR(50) NOT NULL DEFAULT "verified"');
CALL qps_add_column_if_missing('questions', 'verified_at', 'DATETIME NULL');
CALL qps_add_column_if_missing('questions', 'verified_by', 'VARCHAR(100) NULL');
CALL qps_add_column_if_missing('questions', 'updated_at', 'DATETIME NULL');

-- 1.2 qps_question_bank_draft_questions: ensure all spec columns exist
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'total_marks', 'INT NOT NULL DEFAULT 0');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'question_type', 'VARCHAR(50) NOT NULL DEFAULT "MCQ"');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'options_json', 'LONGTEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'answer_key', 'TEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'match_column_a', 'LONGTEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'match_column_b', 'LONGTEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'assertion_text', 'TEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'reason_text', 'TEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'image_url', 'VARCHAR(700) NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'has_formula', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'formula_latex', 'TEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'language', 'VARCHAR(20) NOT NULL DEFAULT "en"');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'correction_status', 'VARCHAR(50) NOT NULL DEFAULT "CLEAN"');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'remarks', 'TEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'version_no', 'INT UNSIGNED NOT NULL DEFAULT 1');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'hod_approved_at', 'DATETIME NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'coe_approved_at', 'DATETIME NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'coe_returned_at', 'DATETIME NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'master_question_id', 'BIGINT UNSIGNED NULL');
CALL qps_add_column_if_missing('qps_question_bank_draft_questions', 'status', 'VARCHAR(50) NOT NULL DEFAULT "verification_pending"');

-- 1.3 qps_question_bank_drafts: ensure all spec columns
CALL qps_add_column_if_missing('qps_question_bank_drafts', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_add_column_if_missing('qps_question_bank_drafts', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_add_column_if_missing('qps_question_bank_drafts', 'language', 'VARCHAR(20) NOT NULL DEFAULT "EN"');
CALL qps_add_column_if_missing('qps_question_bank_drafts', 'hod_remarks', 'TEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_drafts', 'coe_remarks', 'TEXT NULL');
CALL qps_add_column_if_missing('qps_question_bank_drafts', 'version_no', 'INT UNSIGNED NOT NULL DEFAULT 1');

-- 1.4 qps_final_blueprints: normalize slots into separate table
CALL qps_add_column_if_missing('qps_final_blueprints', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_add_column_if_missing('qps_final_blueprints', 'language', 'VARCHAR(20) NOT NULL DEFAULT "EN"');
CALL qps_add_column_if_missing('qps_final_blueprints', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_add_column_if_missing('qps_final_blueprints', 'dean_template_id', 'BIGINT UNSIGNED NULL');
CALL qps_add_column_if_missing('qps_final_blueprints', 'status', 'VARCHAR(50) NOT NULL DEFAULT "draft"');

-- 1.5 qps_submission_packages: add three independent status columns
CALL qps_add_column_if_missing('qps_submission_packages', 'question_bank_status', 'VARCHAR(50) NOT NULL DEFAULT "draft"');
CALL qps_add_column_if_missing('qps_submission_packages', 'master_blueprint_status', 'VARCHAR(50) NOT NULL DEFAULT "draft"');
CALL qps_add_column_if_missing('qps_submission_packages', 'final_blueprint_status', 'VARCHAR(50) NOT NULL DEFAULT "draft"');
CALL qps_add_column_if_missing('qps_submission_packages', 'question_bank_draft_id', 'BIGINT UNSIGNED NULL');
CALL qps_add_column_if_missing('qps_submission_packages', 'master_blueprint_id', 'BIGINT UNSIGNED NULL');
CALL qps_add_column_if_missing('qps_submission_packages', 'final_blueprint_id', 'BIGINT UNSIGNED NULL');
CALL qps_add_column_if_missing('qps_submission_packages', 'dean_template_id', 'BIGINT UNSIGNED NULL');
CALL qps_add_column_if_missing('qps_submission_packages', 'submitted_at', 'DATETIME NULL');
CALL qps_add_column_if_missing('qps_submission_packages', 'approved_at', 'DATETIME NULL');
CALL qps_add_column_if_missing('qps_submission_packages', 'language', 'VARCHAR(20) NOT NULL DEFAULT "EN"');

-- 1.6 qps_audit_log: ensure all audit columns (from migration already)
CALL qps_add_column_if_missing('qps_audit_log', 'staff_code', 'VARCHAR(100) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'staff_name', 'VARCHAR(255) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'staff_mobile', 'VARCHAR(50) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'role', 'VARCHAR(50) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'entity_type', 'VARCHAR(100) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'entity_id', 'VARCHAR(100) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'details', 'LONGTEXT NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'ip_address', 'VARCHAR(100) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'from_status', 'VARCHAR(50) NULL');
CALL qps_add_column_if_missing('qps_audit_log', 'to_status', 'VARCHAR(50) NULL');

-- Add index for audit log if missing
SET @qps_idx = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_audit_log' AND INDEX_NAME='idx_audit_status'),
    'SELECT 1',
    'ALTER TABLE `qps_audit_log` ADD INDEX `idx_audit_status` (`entity_type`, `from_status`, `to_status`, `created_at`)'
);
PREPARE qps_stmt FROM @qps_idx; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

-- 1.7 qps_settings: ensure settings keys exist (inserted below)

-- ============================================================================
-- 2. NEW TABLES PER SPEC
-- ============================================================================

-- 2.1 course_submissions: tracks three independent items per course
CREATE TABLE IF NOT EXISTS `course_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_code` VARCHAR(100) NOT NULL,
  `course_title` VARCHAR(255) NULL,
  `dept_code` VARCHAR(50) NULL,
  `dept_name` VARCHAR(255) NULL,
  `semester` VARCHAR(50) NOT NULL,
  `academic_year` VARCHAR(50) NOT NULL,
  `exam_type` VARCHAR(120) NOT NULL DEFAULT 'Odd Semester End Examination',
  `programme_year` VARCHAR(30) NULL,
  `language` VARCHAR(20) NOT NULL DEFAULT 'EN',
  `staff_code` VARCHAR(100) NOT NULL,
  `total_marks` INT NOT NULL DEFAULT 75,
  -- Three independent item statuses
  `question_bank_status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `master_blueprint_status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `final_blueprint_status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  -- Linked records
  `question_bank_draft_id` BIGINT UNSIGNED NULL,
  `master_blueprint_id` BIGINT UNSIGNED NULL,
  `final_blueprint_id` BIGINT UNSIGNED NULL,
  `dean_template_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submitted_at` DATETIME NULL,
  `approved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_course_submission` (`course_code`, `semester`, `academic_year`, `exam_type`, `programme_year`, `staff_code`),
  KEY `idx_staff` (`staff_code`),
  KEY `idx_dept` (`dept_code`),
  KEY `idx_status_qb` (`question_bank_status`),
  KEY `idx_status_mb` (`master_blueprint_status`),
  KEY `idx_status_fb` (`final_blueprint_status`),
  KEY `idx_submitted` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 corrections: unified corrections table per spec
CREATE TABLE IF NOT EXISTS `corrections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `item_type` ENUM('question_bank','master_blueprint','final_blueprint') NOT NULL,
  `target_type` ENUM('question','blueprint_cell') NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `cell_ref` VARCHAR(100) NULL,
  `text` TEXT NOT NULL,
  `raised_by_role` ENUM('HOD','DEAN','COE') NOT NULL,
  `raised_by` VARCHAR(100) NOT NULL,
  `status` ENUM('open','resolved','withdrawn') NOT NULL DEFAULT 'open',
  `resolver_note` TEXT NULL,
  `resolved_by` VARCHAR(100) NULL,
  `resolved_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_item_type` (`item_type`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_status` (`status`),
  KEY `idx_raised_by` (`raised_by_role`, `raised_by`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 master_blueprints: normalized blueprint cells (computed on read from draft)
CREATE TABLE IF NOT EXISTS `master_blueprints` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `course_code` VARCHAR(100) NOT NULL,
  `semester` VARCHAR(50) NOT NULL,
  `academic_year` VARCHAR(50) NOT NULL,
  `programme_year` VARCHAR(30) NULL,
  `language` VARCHAR(20) NOT NULL DEFAULT 'EN',
  `unit_no` INT NOT NULL,
  `sub_unit` VARCHAR(50) NOT NULL,
  `question_type` VARCHAR(50) NOT NULL,
  `marks` INT NOT NULL,
  `k_level` VARCHAR(20) NOT NULL,
  `co_level` VARCHAR(20) NOT NULL,
  `available_count` INT NOT NULL DEFAULT 0,
  `version_no` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mb_cell` (`submission_id`, `unit_no`, `sub_unit`, `question_type`, `marks`, `k_level`, `co_level`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_course` (`course_code`, `semester`, `academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.4 final_blueprint_slots: normalized slots (separate from qps_final_blueprints JSON)
CREATE TABLE IF NOT EXISTS `final_blueprint_slots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `final_blueprint_id` BIGINT UNSIGNED NOT NULL,
  `slot_key` VARCHAR(100) NOT NULL,
  `slot_number` INT NOT NULL,
  `section_type` VARCHAR(50) NOT NULL,
  `unit_no` INT NULL,
  `sub_unit` VARCHAR(50) NULL,
  `question_type` VARCHAR(50) NULL,
  `marks` INT NOT NULL,
  `k_level` VARCHAR(20) NOT NULL,
  `co_level` VARCHAR(20) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fb_slot` (`final_blueprint_id`, `slot_key`),
  KEY `idx_fb` (`final_blueprint_id`),
  KEY `idx_unit` (`unit_no`, `sub_unit`),
  KEY `idx_slot_number` (`slot_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.5 final_question_papers: generated papers (renamed from generated_papers)
CREATE TABLE IF NOT EXISTS `final_question_papers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `course_code` VARCHAR(100) NOT NULL,
  `course_title` VARCHAR(255) NULL,
  `semester` VARCHAR(50) NOT NULL,
  `academic_year` VARCHAR(50) NOT NULL,
  `exam_type` VARCHAR(120) NOT NULL,
  `programme_year` VARCHAR(30) NULL,
  `language` VARCHAR(20) NOT NULL DEFAULT 'EN',
  `set_code` VARCHAR(20) NOT NULL DEFAULT 'SET-A',
  `total_marks` INT NOT NULL DEFAULT 75,
  `paper_json` LONGTEXT NULL,
  `docx_file_path` VARCHAR(500) NULL,
  `generated_by` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_course` (`course_code`, `semester`, `academic_year`),
  KEY `idx_set` (`set_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.6 final_question_paper_slots: paper slot allocations with question references
CREATE TABLE IF NOT EXISTS `final_question_paper_slots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `paper_id` BIGINT UNSIGNED NOT NULL,
  `slot_id` BIGINT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `slot_key` VARCHAR(100) NOT NULL,
  `slot_number` INT NOT NULL,
  `section_type` VARCHAR(50) NOT NULL,
  `marks` INT NOT NULL,
  `k_level` VARCHAR(20) NOT NULL,
  `co_level` VARCHAR(20) NOT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_paper_slot` (`paper_id`, `slot_id`),
  UNIQUE KEY `uk_paper_question` (`paper_id`, `question_id`),
  KEY `idx_paper` (`paper_id`),
  KEY `idx_question` (`question_id`),
  KEY `idx_slot` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.7 dean_question_templates: ensure exists (from dean portal)
CREATE TABLE IF NOT EXISTS `dean_question_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_name` VARCHAR(150) NOT NULL,
  `total_marks` INT NOT NULL,
  `academic_year` VARCHAR(20) NOT NULL,
  `year_of_programme` VARCHAR(10) NOT NULL,
  `semester` INT NOT NULL,
  `class_sections` JSON NULL,
  `sections` JSON NOT NULL,
  `slots` JSON NOT NULL,
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_context` (`academic_year`, `year_of_programme`, `semester`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. QPS SETTINGS: Static account hashes and department scopes
-- ============================================================================
-- These are INSERT IGNORE / ON DUPLICATE KEY UPDATE - safe to re-run
INSERT INTO `qps_settings` (`setting_key`, `setting_value`, `updated_by`) VALUES
('static_hod_password_hash', '', 'SYSTEM'),
('static_dean_password_hash', '', 'SYSTEM'),
('static_coe_password_hash', '', 'SYSTEM'),
('static_hod_department_scope', 'all', 'SYSTEM'),
('static_dean_department_scope', 'all', 'SYSTEM'),
('coe_repeat_year_threshold', '1', 'SYSTEM'),
('default_academic_year', '2026-2027', 'SYSTEM'),
('docx_header_text', 'HOLY CROSS COLLEGE (AUTONOMOUS)', 'SYSTEM'),
('docx_college_name', 'Holy Cross College (Autonomous)', 'SYSTEM'),
('docx_affiliation', 'Affiliated to Bharathidasan University | Nationally Re-accredited with A++ Grade by NAAC', 'SYSTEM'),
('docx_location', 'Tiruchirappalli - 620 002, Tamil Nadu', 'SYSTEM')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- ============================================================================
-- 4. INDEXES FOR PERFORMANCE
-- ============================================================================
-- questions table indexes
SET @qps_idx = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND INDEX_NAME='idx_questions_course_context'),
    'SELECT 1',
    'ALTER TABLE `questions` ADD INDEX `idx_questions_course_context` (`course_code`, `semester`, `academic_year`)'
);
PREPARE qps_stmt FROM @qps_idx; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

SET @qps_idx = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND INDEX_NAME='idx_questions_used_in'),
    'SELECT 1',
    'ALTER TABLE `questions` ADD INDEX `idx_questions_used_in` (`used_in`)'
);
PREPARE qps_stmt FROM @qps_idx; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

-- qps_question_bank_draft_questions indexes
SET @qps_idx = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_question_bank_draft_questions' AND INDEX_NAME='idx_draft_status'),
    'SELECT 1',
    'ALTER TABLE `qps_question_bank_draft_questions` ADD INDEX `idx_draft_status` (`status`)'
);
PREPARE qps_stmt FROM @qps_idx; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

-- qps_submission_packages indexes
SET @qps_idx = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_submission_packages' AND INDEX_NAME='idx_pkg_qb_status'),
    'SELECT 1',
    'ALTER TABLE `qps_submission_packages` ADD INDEX `idx_pkg_qb_status` (`question_bank_status`)'
);
PREPARE qps_stmt FROM @qps_idx; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

SET @qps_idx = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_submission_packages' AND INDEX_NAME='idx_pkg_mb_status'),
    'SELECT 1',
    'ALTER TABLE `qps_submission_packages` ADD INDEX `idx_pkg_mb_status` (`master_blueprint_status`)'
);
PREPARE qps_stmt FROM @qps_idx; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

SET @qps_idx = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_submission_packages' AND INDEX_NAME='idx_pkg_fb_status'),
    'SELECT 1',
    'ALTER TABLE `qps_submission_packages` ADD INDEX `idx_pkg_fb_status` (`final_blueprint_status`)'
);
PREPARE qps_stmt FROM @qps_idx; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

-- ============================================================================
-- 5. CLEANUP
-- ============================================================================
DROP PROCEDURE IF EXISTS `qps_add_column_if_missing`;

-- ============================================================================
-- ROLLBACK NOTES:
-- This migration is ADDITIVE ONLY - no DROP TABLE, no DROP COLUMN, no data deletion.
-- To rollback CODE only: restore previous PHP files.
-- To rollback SCHEMA:
--   1. Export/backup data from new tables (course_submissions, corrections, master_blueprints,
--      final_blueprint_slots, final_question_papers, final_question_paper_slots)
--   2. DROP TABLE the new tables only
--   3. The ALTER TABLE column additions on existing tables are non-destructive
--      and do not need rollback unless you specifically want to remove columns.
-- NEVER drop existing tables: questions, question_banks, pr_x_xxxx_staf_prof_mast,
-- courses, departments, timetablefaculty, qps_question_bank_drafts,
-- qps_question_bank_draft_questions, qps_submission_packages, qps_audit_log, etc.
-- ============================================================================

-- Verification queries
SELECT 'course_submissions' as table_name, COUNT(*) as `row_count` FROM `course_submissions`
UNION ALL SELECT 'corrections', COUNT(*) FROM `corrections`
UNION ALL SELECT 'master_blueprints', COUNT(*) FROM `master_blueprints`
UNION ALL SELECT 'final_blueprint_slots', COUNT(*) FROM `final_blueprint_slots`
UNION ALL SELECT 'final_question_papers', COUNT(*) FROM `final_question_papers`
UNION ALL SELECT 'final_question_paper_slots', COUNT(*) FROM `final_question_paper_slots`
UNION ALL SELECT 'dean_question_templates', COUNT(*) FROM `dean_question_templates`;

SELECT setting_key, setting_value FROM `qps_settings`
WHERE setting_key IN (
  'static_hod_password_hash','static_dean_password_hash','static_coe_password_hash',
  'static_hod_department_scope','static_dean_department_scope',
  'coe_repeat_year_threshold','default_academic_year',
  'docx_header_text','docx_college_name','docx_affiliation','docx_location'
);