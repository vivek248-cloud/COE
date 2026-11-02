-- COE Final Question Allotment
-- Additive migration. Does not alter existing question/blueprint records.
CREATE TABLE IF NOT EXISTS qps_coe_question_allotments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  final_blueprint_id BIGINT UNSIGNED NOT NULL,
  slot_key VARCHAR(100) NOT NULL,
  slot_number INT NOT NULL,
  section_type VARCHAR(40) NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  paper_code VARCHAR(100) NOT NULL,
  academic_year VARCHAR(30) NULL,
  semester VARCHAR(50) NULL,
  assigned_by VARCHAR(100) NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qps_allot_slot (final_blueprint_id, slot_key),
  UNIQUE KEY uq_qps_allot_question (final_blueprint_id, question_id),
  KEY idx_qps_allot_paper (paper_code, academic_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
