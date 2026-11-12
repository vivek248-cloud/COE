-- Teaching Staff Page 4: independent normalized final blueprint storage.
-- MySQL 8 / MariaDB 10.4. Additive and re-runnable; does not alter Dean templates.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS final_blueprints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_code VARCHAR(64) NOT NULL,
  course_code VARCHAR(100) NOT NULL,
  template_id BIGINT UNSIGNED NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  programme_year VARCHAR(10) NOT NULL,
  total_marks INT NOT NULL,
  language VARCHAR(20) NOT NULL DEFAULT 'EN',
  template_name_snapshot VARCHAR(255) NOT NULL,
  template_snapshot LONGTEXT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_course_template_context (staff_code,course_code,template_id,academic_year,semester,programme_year,language),
  KEY idx_final_blueprint_course (course_code,academic_year,semester),
  KEY idx_final_blueprint_staff (staff_code,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installations may already have final_blueprint_slots from Phase 1.
-- Keep its original columns and add the slot snapshot/allocation columns only.
ALTER TABLE final_blueprint_slots
  ADD COLUMN IF NOT EXISTS template_slot_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS section_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS section_title_snapshot VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS slot_label_snapshot VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS marks_snapshot INT NULL,
  ADD COLUMN IF NOT EXISTS k_level_snapshot VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS co_level_snapshot VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS allocations_json LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS eligible_count INT NOT NULL DEFAULT 0;

INSERT INTO qps_settings (setting_key,setting_value,updated_by)
VALUES ('FINAL_SLOT_MIN_ELIGIBLE_QUESTIONS','1','SYSTEM')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

-- COE can change the minimum by updating qps_settings.setting_value:
-- UPDATE qps_settings SET setting_value='2',updated_by='COE' WHERE setting_key='FINAL_SLOT_MIN_ELIGIBLE_QUESTIONS';
