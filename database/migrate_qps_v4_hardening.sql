-- COE Question Paper System v4 hardening migration
-- Target: existing MariaDB/MySQL application database.
-- Purpose: make the relational model queryable, preserve import provenance,
-- keep immutable JSON snapshots, and support deterministic duplicate/history checks.
-- Safe principle: additive only; no ERP master tables are replaced.

CREATE TABLE IF NOT EXISTS qps_bank_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bank_id INT NOT NULL,
  version_no INT NOT NULL DEFAULT 1,
  schema_version VARCHAR(20) NOT NULL DEFAULT '4.0',
  questions_json LONGTEXT NOT NULL,
  metadata_json LONGTEXT NULL,
  source_file_name VARCHAR(255) NULL,
  source_format VARCHAR(30) NULL,
  source_path VARCHAR(500) NULL,
  ocr_language VARCHAR(100) NULL,
  ocr_used TINYINT(1) NOT NULL DEFAULT 0,
  question_count INT UNSIGNED NOT NULL DEFAULT 0,
  content_hash CHAR(64) NULL,
  created_by VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qps_bv_bank_version (bank_id,version_no),
  KEY idx_qps_bv_hash (content_hash),
  KEY idx_qps_bv_created (bank_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bank_id INT NULL,
  token_hash CHAR(64) NULL,
  source_file_name VARCHAR(255) NOT NULL,
  source_format VARCHAR(30) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  parser_version VARCHAR(80) NOT NULL,
  schema_version VARCHAR(20) NOT NULL DEFAULT '4.0',
  detected_language VARCHAR(20) NULL,
  ocr_used TINYINT(1) NOT NULL DEFAULT 0,
  total_questions INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_questions INT UNSIGNED NOT NULL DEFAULT 0,
  warning_count INT UNSIGNED NOT NULL DEFAULT 0,
  low_confidence_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('UPLOADED','VALIDATED','COMMITTED','FAILED','REVIEW_REQUIRED') NOT NULL DEFAULT 'UPLOADED',
  diagnostics_json LONGTEXT NULL,
  created_by VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  KEY idx_qps_import_bank (bank_id,created_at),
  KEY idx_qps_import_staff (created_by,created_at),
  KEY idx_qps_import_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_import_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_id BIGINT UNSIGNED NOT NULL,
  row_no INT UNSIGNED NOT NULL,
  source_q_number INT NULL,
  question_hash CHAR(64) NULL,
  validation_status ENUM('VALID','REVIEW_REQUIRED','INVALID','DUPLICATE') NOT NULL DEFAULT 'VALID',
  duplicate_type VARCHAR(40) NULL,
  matched_question_id INT NULL,
  diagnostics_json LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_qps_import_row(import_id,row_no),
  KEY idx_qps_ir_hash(question_hash),
  KEY idx_qps_ir_match(matched_question_id),
  CONSTRAINT fk_qps_ir_import FOREIGN KEY(import_id) REFERENCES qps_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_question_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  bank_id INT NOT NULL,
  version_no INT NOT NULL DEFAULT 1,
  action ENUM('CREATE','UPDATE','REPLACE','ARCHIVE','RESTORE') NOT NULL,
  snapshot_json LONGTEXT NOT NULL,
  changed_by VARCHAR(100) NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qps_qh_question(question_id,changed_at),
  KEY idx_qps_qh_bank(bank_id,changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_question_usage (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  bank_id INT NULL,
  generated_paper_id INT NULL,
  paper_code VARCHAR(120) NULL,
  academic_year VARCHAR(30) NULL,
  semester VARCHAR(50) NULL,
  exam_type VARCHAR(100) NULL,
  usage_role VARCHAR(30) NOT NULL DEFAULT 'GENERATED',
  used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  used_by VARCHAR(100) NULL,
  KEY idx_qps_qu_question(question_id,used_at),
  KEY idx_qps_qu_context(paper_code,academic_year,semester),
  KEY idx_qps_qu_year(academic_year,used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(100) NULL,
  role VARCHAR(50) NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id VARCHAR(100) NULL,
  details LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  request_id CHAR(32) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qps_audit_actor (actor,created_at),
  KEY idx_qps_audit_entity (entity_type,entity_id,created_at),
  KEY idx_qps_audit_action (action,created_at),
  KEY idx_qps_audit_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS root_bank_id INT NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS version_no INT NOT NULL DEFAULT 1;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS schema_version VARCHAR(20) NOT NULL DEFAULT '4.0';
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS source_format VARCHAR(30) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS source_file_name VARCHAR(255) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS source_path VARCHAR(500) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS ocr_language VARCHAR(100) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS ocr_used TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS current_version_id BIGINT UNSIGNED NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS last_import_id BIGINT UNSIGNED NULL;

ALTER TABLE questions ADD COLUMN IF NOT EXISTS source_question_no INT NULL;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS import_schema VARCHAR(30) NOT NULL DEFAULT 'legacy';
ALTER TABLE questions ADD COLUMN IF NOT EXISTS parser_version VARCHAR(80) NULL;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS parser_confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS validation_status VARCHAR(30) NOT NULL DEFAULT 'VALID';
ALTER TABLE questions ADD COLUMN IF NOT EXISTS normalized_text LONGTEXT NULL;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS question_hash CHAR(64) NULL;

CREATE INDEX idx_qps_q_bank_filter ON questions(bank_id,unit_no,sub_unit,section_type,k_level,co_level,marks);
CREATE INDEX idx_qps_q_hash ON questions(question_hash);
CREATE INDEX idx_qps_q_validation ON questions(bank_id,validation_status);
CREATE INDEX idx_qps_q_source_no ON questions(bank_id,source_question_no);
CREATE INDEX idx_qps_q_parser ON questions(import_schema,parser_version);

CREATE INDEX idx_qps_qb_context ON question_banks(paper_code,semester,academic_year,status);
CREATE INDEX idx_qps_qb_staff_context ON question_banks(staff_code,paper_code,academic_year);
CREATE INDEX idx_qps_qb_hash ON question_banks(content_hash);

-- JSON remains a durable snapshot/archive. Relational columns remain the query source.
-- Do not delete questions_json or qps_bank_versions after applying this migration.
