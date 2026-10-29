-- QPS Upload / Admin v2 auxiliary migration for existing hccweb database.
-- ERP master tables are NOT copied or modified except for application columns on question_banks.
-- The application also creates these objects automatically when possible.

CREATE TABLE IF NOT EXISTS qps_bank_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_id INT NOT NULL,
  version_no INT NOT NULL DEFAULT 1,
  questions_json LONGTEXT NOT NULL,
  source_file_name VARCHAR(255) NULL,
  source_format VARCHAR(30) NULL,
  source_path VARCHAR(500) NULL,
  ocr_language VARCHAR(100) NULL,
  ocr_used TINYINT(1) NOT NULL DEFAULT 0,
  content_hash CHAR(64) NULL,
  created_by VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qps_bv_bank (bank_id),
  KEY idx_qps_bv_hash (content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_upload_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_id INT NULL,
  staff_code VARCHAR(100) NULL,
  action VARCHAR(50) NOT NULL,
  source_file_name VARCHAR(255) NULL,
  source_format VARCHAR(30) NULL,
  question_count INT NOT NULL DEFAULT 0,
  ocr_used TINYINT(1) NOT NULL DEFAULT 0,
  message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qps_uh_bank (bank_id),
  KEY idx_qps_uh_staff (staff_code,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value LONGTEXT NULL,
  updated_by VARCHAR(100) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(100) NULL,
  role VARCHAR(50) NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id VARCHAR(100) NULL,
  details LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qps_audit_actor (actor,created_at),
  KEY idx_qps_audit_entity (entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Run the following only if these columns do not already exist in question_banks.
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS root_bank_id INT NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS version_no INT NOT NULL DEFAULT 1;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS source_format VARCHAR(30) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS source_file_name VARCHAR(255) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS ocr_language VARCHAR(100) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS ocr_used TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS locked_at VARCHAR(50) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(100) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS reviewed_at VARCHAR(50) NULL;
ALTER TABLE question_banks ADD COLUMN IF NOT EXISTS blueprint_id INT NULL;
