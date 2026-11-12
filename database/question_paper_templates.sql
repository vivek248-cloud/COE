-- Reusable Dean-authored question paper templates.
-- Run once against the project's configured MySQL/MariaDB database.
CREATE TABLE IF NOT EXISTS question_paper_templates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 template_name VARCHAR(150) NOT NULL,
 total_marks INT NOT NULL,
 academic_year VARCHAR(9) NOT NULL,
 year_of_programme VARCHAR(4) NOT NULL,
 semester TINYINT UNSIGNED NOT NULL,
 class_sections JSON NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 status ENUM('draft','published') NOT NULL DEFAULT 'draft',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_qpt_dean_year_name (created_by, academic_year, template_name),
 KEY idx_qpt_owner_updated (created_by, updated_at),
 CONSTRAINT chk_qpt_total_marks CHECK (total_marks BETWEEN 1 AND 200),
 CONSTRAINT chk_qpt_semester CHECK (semester BETWEEN 1 AND 8)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_paper_template_sections (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 template_id BIGINT UNSIGNED NOT NULL,
 section_name VARCHAR(80) NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 PRIMARY KEY (id),
 UNIQUE KEY uq_qpts_template_section (template_id, section_name),
 KEY idx_qpts_template_order (template_id, sort_order),
 CONSTRAINT fk_qpts_template FOREIGN KEY (template_id) REFERENCES question_paper_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_paper_template_slots (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 template_id BIGINT UNSIGNED NOT NULL,
 section_id BIGINT UNSIGNED NOT NULL,
 slot_label VARCHAR(40) NOT NULL,
 marks INT NOT NULL,
 k_level ENUM('K1','K2','K3','K4','K5','K6') NOT NULL,
 co_level ENUM('CO1','CO2','CO3','CO4','CO5','CO6') NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 unit_id BIGINT UNSIGNED NULL,
 question_id BIGINT UNSIGNED NULL,
 PRIMARY KEY (id),
 KEY idx_qpt_slots_template_order (template_id, sort_order),
 KEY idx_qpt_slots_section_order (section_id, sort_order),
 KEY idx_qpt_slots_assignment (unit_id, question_id),
 CONSTRAINT chk_qpt_slot_marks CHECK (marks BETWEEN 1 AND 200),
 CONSTRAINT fk_qpt_slot_template FOREIGN KEY (template_id) REFERENCES question_paper_templates(id) ON DELETE CASCADE,
 CONSTRAINT fk_qpt_slot_section FOREIGN KEY (section_id) REFERENCES question_paper_template_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff-side read contract:
-- SELECT t.*, s.id AS section_id, s.section_name, s.sort_order AS section_order,
--        sl.id AS slot_id, sl.slot_label, sl.marks, sl.k_level, sl.co_level,
--        sl.unit_id, sl.question_id, sl.sort_order AS slot_order
-- FROM question_paper_templates t
-- JOIN question_paper_template_sections s ON s.template_id=t.id
-- JOIN question_paper_template_slots sl ON sl.template_id=t.id AND sl.section_id=s.id
-- WHERE t.id=? AND t.status='published'
-- ORDER BY s.sort_order, sl.sort_order;
