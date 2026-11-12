-- Question Paper Templates Schema
-- Holy Cross College (Autonomous) - Examination System
-- Creates 3 tables for reusable question paper blueprints

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Template Header
CREATE TABLE IF NOT EXISTS `question_paper_templates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL COMMENT 'Template name, e.g., "UG Semester 1 - 75 Marks"',
    `description` TEXT NULL COMMENT 'Optional description',
    `paper_type` ENUM('mid','end','model') NOT NULL DEFAULT 'end' COMMENT 'Exam type: mid/end/model',
    `total_marks` TINYINT UNSIGNED NOT NULL COMMENT 'Total marks for the paper (1-200)',
    `duration_minutes` SMALLINT UNSIGNED NULL COMMENT 'Exam duration in minutes',
    `instructions` TEXT NULL COMMENT 'General instructions printed on question paper',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete flag',
    `created_by` VARCHAR(64) NOT NULL COMMENT 'Staff code of creator',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name_paper_type` (`name`, `paper_type`),
    KEY `idx_active` (`is_active`),
    KEY `idx_created_by` (`created_by`),
    CONSTRAINT `chk_total_marks` CHECK (`total_marks` BETWEEN 1 AND 200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Question paper template headers (blueprints)';

-- 2. Template Sections (Section A, B, C, D within a template)
CREATE TABLE IF NOT EXISTS `question_paper_template_sections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_id` BIGINT UNSIGNED NOT NULL,
    `section_type` CHAR(1) NOT NULL COMMENT 'A, B, C, or D',
    `section_title` VARCHAR(100) NULL COMMENT 'Optional custom title, e.g., "Short Answer Questions"',
    `instruction` TEXT NULL COMMENT 'Section-specific instruction printed on paper',
    `display_order` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Order within template',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_template_section` (`template_id`, `section_type`),
    KEY `idx_template` (`template_id`),
    CONSTRAINT `fk_qpts_template` FOREIGN KEY (`template_id`) REFERENCES `question_paper_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `chk_section_type` CHECK (`section_type` IN ('A','B','C','D'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sections within a question paper template';

-- 3. Template Slots (Question slots within sections)
CREATE TABLE IF NOT EXISTS `question_paper_template_slots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `section_id` BIGINT UNSIGNED NOT NULL,
    `slot_label` VARCHAR(50) NOT NULL COMMENT 'Label shown on paper, e.g., "Q1", "Q2(a)"',
    `marks` TINYINT UNSIGNED NOT NULL COMMENT 'Marks for this slot (1-20)',
    `co` VARCHAR(20) NULL COMMENT 'Course Outcome code, e.g., CO1, CO2',
    `k_level` VARCHAR(10) NULL COMMENT 'Knowledge level: K1-K6 (Bloom)',
    `question_type` ENUM('theory','problem','case','derivation','diagram','short_answer','long_answer','mcq') NULL COMMENT 'Type of question expected',
    `is_compulsory` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'If 0, student can choose alternative',
    `alternative_group` VARCHAR(50) NULL COMMENT 'Group ID for OR-choice questions',
    `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Order within section',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_section` (`section_id`),
    KEY `idx_display_order` (`section_id`, `display_order`),
    CONSTRAINT `fk_qptsl_section` FOREIGN KEY (`section_id`) REFERENCES `question_paper_template_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `chk_marks` CHECK (`marks` BETWEEN 1 AND 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Individual question slots within template sections';

SET FOREIGN_KEY_CHECKS = 1;

-- Index for quick lookup of slots by template
CREATE INDEX `idx_slots_template` ON `question_paper_template_slots` (`section_id`);

-- View for easy template summary (optional helper)
CREATE OR REPLACE VIEW `v_question_paper_templates` AS
SELECT
    t.id,
    t.name,
    t.description,
    t.paper_type,
    t.total_marks,
    t.duration_minutes,
    t.is_active,
    t.created_by,
    t.created_at,
    t.updated_at,
    COALESCE(SUM(s.marks), 0) AS slots_total_marks,
    COUNT(DISTINCT sec.id) AS section_count,
    COUNT(s.id) AS slot_count
FROM question_paper_templates t
LEFT JOIN question_paper_template_sections sec ON sec.template_id = t.id AND sec.is_active = 1
LEFT JOIN question_paper_template_slots s ON s.section_id = sec.id AND s.is_active = 1
GROUP BY t.id, t.name, t.description, t.paper_type, t.total_marks, t.duration_minutes, t.is_active, t.created_by, t.created_at, t.updated_at;