-- Create questions_draft table for new Teaching Staff portal
-- Holy Cross College (Autonomous) - COE Question Paper System
-- Run on hccweb database

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- questions_draft: Faculty question drafts with full workflow support
CREATE TABLE IF NOT EXISTS `questions_draft` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` VARCHAR(100) NOT NULL,
  `course_code` VARCHAR(100) NOT NULL,
  `regulation` VARCHAR(50) NOT NULL,
  `academic_year` VARCHAR(50) NOT NULL,
  `exam_type` VARCHAR(120) NOT NULL,
  `paper_type` VARCHAR(50) NOT NULL,
  `unit_number` INT NOT NULL,
  `sub_unit_number` VARCHAR(50) NULL,
  `question_number` INT NOT NULL,
  `question_text` TEXT NOT NULL,
  `marks` INT NOT NULL,
  `blooms_level` VARCHAR(20) NOT NULL,
  `co_code` VARCHAR(20) NOT NULL,
  `question_type` VARCHAR(50) NOT NULL DEFAULT 'MCQ',
  `difficulty_level` VARCHAR(20) NULL,
  `estimated_time` INT NULL,
  `answer_key` TEXT NULL,
  `rubric` TEXT NULL,
  `options_json` LONGTEXT NULL,
  `image_url` VARCHAR(700) NULL,
  `has_formula` TINYINT(1) NOT NULL DEFAULT 0,
  `formula_latex` TEXT NULL,
  `language` VARCHAR(20) NOT NULL DEFAULT 'EN',
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `verified_at` DATETIME NULL,
  `verified_by` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_draft_question` (`staff_id`, `course_code`, `regulation`, `academic_year`, `exam_type`, `paper_type`, `unit_number`, `sub_unit_number`, `question_number`),
  KEY `idx_staff_course` (`staff_id`, `course_code`),
  KEY `idx_course_context` (`course_code`, `regulation`, `academic_year`, `exam_type`, `paper_type`),
  KEY `idx_status` (`status`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification
SELECT 'questions_draft' as table_name, COUNT(*) as row_count FROM `questions_draft`;
SHOW CREATE TABLE `questions_draft`;