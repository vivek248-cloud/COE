-- QPS workflow upgrade v1.0
-- MySQL 8 / MariaDB. Additive and safe to re-run.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_static_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(150) NOT NULL,
  role ENUM('DEAN','COE') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_qps_static_username (username),
  KEY idx_qps_static_role_active (role,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  semester VARCHAR(50) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  programme_level VARCHAR(30) NOT NULL,
  course_type VARCHAR(50) NOT NULL DEFAULT '*',
  question_number INT UNSIGNED NOT NULL,
  k_level ENUM('K1','K2','K3','K4','K5','K6') NOT NULL,
  co_level ENUM('CO1','CO2','CO3','CO4','CO5','CO6') NOT NULL,
  created_by VARCHAR(100) NULL,
  updated_by VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qps_question_rule (semester,academic_year,programme_level,course_type,question_number),
  KEY idx_qps_rule_lookup (semester,programme_level,course_type,academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_submission_packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  paper_code VARCHAR(100) NOT NULL,
  semester VARCHAR(50) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  exam_type VARCHAR(120) NOT NULL DEFAULT '',
  dept_code VARCHAR(50) NULL,
  bank_draft_id BIGINT UNSIGNED NULL,
  bank_id BIGINT UNSIGNED NULL,
  qbb_id BIGINT UNSIGNED NULL,
  final_blueprint_id BIGINT UNSIGNED NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
  round_no INT UNSIGNED NOT NULL DEFAULT 1,
  current_owner_role VARCHAR(20) NOT NULL DEFAULT 'STAFF',
  submitted_by VARCHAR(100) NULL,
  staff_code VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qps_package_exam (paper_code,semester,academic_year,exam_type),
  KEY idx_qps_package_queue (status,current_owner_role,dept_code),
  KEY idx_qps_package_staff (staff_code,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_workflow_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NOT NULL,
  actor_code VARCHAR(100) NULL,
  actor_name VARCHAR(255) NULL,
  actor_role VARCHAR(20) NOT NULL,
  ip_address VARCHAR(45) NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qps_event_package (package_id,created_at),
  KEY idx_qps_event_actor (actor_code,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_remarks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('QUESTION','QBB','FINAL_SLOT') NOT NULL,
  target_ref VARCHAR(100) NOT NULL,
  round_no INT UNSIGNED NOT NULL DEFAULT 1,
  author_code VARCHAR(100) NOT NULL,
  author_name VARCHAR(255) NULL,
  author_role VARCHAR(20) NOT NULL,
  to_role VARCHAR(20) NOT NULL,
  remark_text TEXT NOT NULL,
  correction_json LONGTEXT NULL,
  status ENUM('OPEN','RESOLVED','WITHDRAWN') NOT NULL DEFAULT 'OPEN',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_qps_remarks_open (package_id,status,to_role,round_no),
  KEY idx_qps_remarks_target (target_type,target_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_final_papers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NULL,
  final_blueprint_id BIGINT UNSIGNED NULL,
  paper_code VARCHAR(100) NOT NULL,
  semester VARCHAR(50) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  exam_type VARCHAR(120) NOT NULL DEFAULT '',
  questions_json LONGTEXT NOT NULL,
  header_json LONGTEXT NULL,
  paper_type_json LONGTEXT NULL,
  content_hash CHAR(64) NOT NULL,
  created_by VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_qps_final_paper_package (package_id),
  KEY idx_qps_final_paper_code (paper_code,semester,academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_paper_sets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  final_paper_id BIGINT UNSIGNED NOT NULL,
  set_label VARCHAR(10) NOT NULL,
  set_key VARCHAR(64) NOT NULL,
  ordering_json LONGTEXT NOT NULL,
  seed BIGINT UNSIGNED NOT NULL,
  created_by VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_qps_set_key (set_key),
  UNIQUE KEY uq_qps_paper_set_label (final_paper_id,set_label),
  KEY idx_qps_sets_paper (final_paper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_usage_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  paper_code VARCHAR(100) NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  paper_id BIGINT UNSIGNED NULL,
  unit_no INT NULL,
  sub_unit VARCHAR(50) NULL,
  k_level VARCHAR(10) NULL,
  academic_year VARCHAR(20) NULL,
  exam_session VARCHAR(80) NULL,
  picked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qps_usage_question (question_id,picked_at),
  KEY idx_qps_usage_paper (paper_code,academic_year,exam_session)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_exam_headers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_qps_header_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_exam_header_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  header_id BIGINT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL,
  line_text VARCHAR(500) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_qps_header_line (header_id,line_no),
  KEY idx_qps_header_lines (header_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Runtime column compatibility is performed by qps_ensure_workflow_schema()
-- using the project's existing qps_add_column_if_missing() helper. This avoids
-- assumptions about the live generated_papers schema.

DELIMITER $$
DROP PROCEDURE IF EXISTS qps_migrate_workflow_v1_columns$$
CREATE PROCEDURE qps_migrate_workflow_v1_columns()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='is_used') THEN
      ALTER TABLE questions ADD COLUMN is_used TINYINT(1) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='times_used') THEN
      ALTER TABLE questions ADD COLUMN times_used INT UNSIGNED NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='last_used_exam') THEN
      ALTER TABLE questions ADD COLUMN last_used_exam VARCHAR(120) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='last_used_semester') THEN
      ALTER TABLE questions ADD COLUMN last_used_semester VARCHAR(50) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='last_used_year') THEN
      ALTER TABLE questions ADD COLUMN last_used_year VARCHAR(20) NULL;
    END IF;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_papers') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_papers' AND COLUMN_NAME='set_name') THEN
      ALTER TABLE generated_papers ADD COLUMN set_name VARCHAR(20) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_papers' AND COLUMN_NAME='paper_data_json') THEN
      ALTER TABLE generated_papers ADD COLUMN paper_data_json LONGTEXT NULL;
    END IF;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_audit_log') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_audit_log' AND COLUMN_NAME='ip_address') THEN
      ALTER TABLE qps_audit_log ADD COLUMN ip_address VARCHAR(100) NULL;
    END IF;
  END IF;
END$$
CALL qps_migrate_workflow_v1_columns()$$
DROP PROCEDURE qps_migrate_workflow_v1_columns$$
DELIMITER ;
