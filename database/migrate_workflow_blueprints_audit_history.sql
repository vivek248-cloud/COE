-- QPS workflow hardening and relational final blueprint/history schema
-- Run with database hccweb selected. This migration is additive and does not drop college tables.
CREATE TABLE IF NOT EXISTS qps_activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  staff_code VARCHAR(80) NOT NULL DEFAULT '',
  staff_name VARCHAR(190) NOT NULL DEFAULT '',
  staff_mobile VARCHAR(40) NOT NULL DEFAULT '',
  role VARCHAR(40) NOT NULL DEFAULT '',
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NOT NULL DEFAULT '',
  entity_id VARCHAR(100) NOT NULL DEFAULT '',
  details_json LONGTEXT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_activity_created (created_at),
  KEY idx_activity_staff (staff_code, created_at),
  KEY idx_activity_action (action, created_at),
  KEY idx_activity_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_final_blueprints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  blueprint_name VARCHAR(190) NOT NULL,
  paper_code VARCHAR(80) NOT NULL,
  course_title VARCHAR(255) NOT NULL DEFAULT '',
  semester VARCHAR(30) NOT NULL,
  academic_year VARCHAR(30) NOT NULL,
  exam_type VARCHAR(80) NOT NULL,
  regulation VARCHAR(80) NULL,
  created_by VARCHAR(80) NOT NULL,
  status ENUM('DRAFT','FINALIZED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  total_marks INT NOT NULL DEFAULT 75,
  blueprint_json LONGTEXT NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_final_bp_course (paper_code, semester, academic_year, exam_type),
  KEY idx_final_bp_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_final_blueprint_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  blueprint_id BIGINT UNSIGNED NOT NULL,
  question_number VARCHAR(20) NOT NULL,
  section_type VARCHAR(20) NOT NULL,
  unit_no INT NOT NULL,
  sub_unit VARCHAR(30) NOT NULL,
  question_type VARCHAR(40) NOT NULL,
  k_level VARCHAR(10) NOT NULL,
  co_level VARCHAR(30) NOT NULL,
  marks INT NOT NULL,
  choice_group VARCHAR(30) NULL,
  question_id BIGINT UNSIGNED NULL,
  source_bank_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_final_bp_question_number (blueprint_id, question_number),
  KEY idx_final_bp_cell (blueprint_id, unit_no, sub_unit, question_type, k_level, co_level, marks),
  KEY idx_final_bp_question (question_id),
  CONSTRAINT fk_final_bp_items_blueprint FOREIGN KEY (blueprint_id) REFERENCES qps_final_blueprints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_question_paper_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  paper_code VARCHAR(80) NOT NULL,
  course_title VARCHAR(255) NOT NULL DEFAULT '',
  semester VARCHAR(30) NOT NULL,
  academic_year VARCHAR(30) NOT NULL,
  exam_type VARCHAR(80) NOT NULL,
  set_name VARCHAR(30) NOT NULL DEFAULT 'SET_A',
  final_blueprint_id BIGINT UNSIGNED NULL,
  generated_paper_id BIGINT UNSIGNED NULL,
  paper_snapshot_json LONGTEXT NOT NULL,
  content_hash CHAR(64) NULL,
  generated_by VARCHAR(80) NOT NULL,
  exported_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_paper_history_course (paper_code, semester, academic_year, exam_type),
  KEY idx_paper_history_hash (content_hash),
  KEY idx_paper_history_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_question_paper_history_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  history_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NULL,
  source_bank_id BIGINT UNSIGNED NULL,
  question_number VARCHAR(20) NOT NULL,
  question_text_snapshot LONGTEXT NOT NULL,
  section_type VARCHAR(20) NOT NULL,
  unit_no INT NULL,
  sub_unit VARCHAR(30) NULL,
  question_type VARCHAR(40) NULL,
  marks INT NOT NULL,
  k_level VARCHAR(10) NULL,
  co_level VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_paper_history_item_question (question_id),
  CONSTRAINT fk_paper_history_items_history FOREIGN KEY (history_id) REFERENCES qps_question_paper_history(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: These tables are additive. Do not drop legacy/overlapping tables until PHP references
-- and existing row counts have been reviewed. Question images remain in private storage.

CREATE TABLE IF NOT EXISTS qps_course_question_matrices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  paper_code VARCHAR(80) NOT NULL,
  semester VARCHAR(30) NOT NULL,
  academic_year VARCHAR(30) NOT NULL,
  exam_type VARCHAR(80) NOT NULL,
  department_code VARCHAR(30) NOT NULL DEFAULT '',
  total_questions INT NOT NULL DEFAULT 0,
  total_marks INT NOT NULL DEFAULT 0,
  matrix_json LONGTEXT NOT NULL,
  refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qps_course_matrix_context (paper_code, semester, academic_year, exam_type),
  KEY idx_qps_course_matrix_dept (department_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
