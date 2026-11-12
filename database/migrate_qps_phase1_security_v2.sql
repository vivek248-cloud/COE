-- COE Question Paper System - Phase 1 security/audit alignment
-- MySQL 8.0+ / MariaDB 10.4+. Additive and re-runnable. Select the target DB first.
-- Does not drop tables, columns, indexes, or existing records.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qps_login_lookup (username, ip_address, attempted_at),
  KEY idx_qps_login_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qps_audit_log is present in some installations with either the legacy
-- actor/role schema or the newer staff_code/staff_name schema. Normalize by
-- adding the missing columns; never replace the table.
CREATE TABLE IF NOT EXISTS qps_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_code VARCHAR(100) NULL,
  staff_name VARCHAR(255) NULL,
  staff_mobile VARCHAR(50) NULL,
  role VARCHAR(50) NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NULL,
  entity_id VARCHAR(100) NULL,
  details LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NULL,
  actor VARCHAR(100) NULL,
  user_agent VARCHAR(500) NULL,
  request_id CHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qps_audit_actor (staff_code, created_at),
  KEY idx_qps_audit_entity (entity_type, entity_id, created_at),
  KEY idx_qps_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS qps_phase1_add_column_if_missing$$
CREATE PROCEDURE qps_phase1_add_column_if_missing(
  IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT
)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @qps_ddl = CONCAT('ALTER TABLE `', REPLACE(p_table,'`','``'), '` ADD COLUMN `',
                          REPLACE(p_column,'`','``'), '` ', p_definition);
    PREPARE qps_stmt FROM @qps_ddl;
    EXECUTE qps_stmt;
    DEALLOCATE PREPARE qps_stmt;
  END IF;
END$$
DELIMITER ;

CALL qps_phase1_add_column_if_missing('qps_audit_log','staff_code','VARCHAR(100) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','staff_name','VARCHAR(255) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','staff_mobile','VARCHAR(50) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','role','VARCHAR(50) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','action','VARCHAR(100) NOT NULL DEFAULT ''LEGACY''');
CALL qps_phase1_add_column_if_missing('qps_audit_log','entity_type','VARCHAR(100) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','entity_id','VARCHAR(100) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','details','LONGTEXT NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','ip_address','VARCHAR(45) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','from_status','VARCHAR(40) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','to_status','VARCHAR(40) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','actor','VARCHAR(100) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','user_agent','VARCHAR(500) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','request_id','CHAR(32) NULL');
CALL qps_phase1_add_column_if_missing('qps_audit_log','created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

-- Add indexes only when absent.
SET @db = DATABASE();
SET @ddl = IF(NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='qps_audit_log' AND INDEX_NAME='idx_qps_audit_status'),
  'ALTER TABLE qps_audit_log ADD KEY idx_qps_audit_status (entity_type,from_status,to_status,created_at)', 'SELECT 1');
PREPARE qps_stmt FROM @ddl; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;
SET @ddl = IF(NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='qps_login_attempts' AND INDEX_NAME='idx_qps_login_lookup'),
  'ALTER TABLE qps_login_attempts ADD KEY idx_qps_login_lookup (username,ip_address,attempted_at)', 'SELECT 1');
PREPARE qps_stmt FROM @ddl; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

-- Preserve existing administrator choices. HOD/Dean default to all only for
-- a fresh install; existing scope values are never overwritten.
INSERT INTO qps_settings (setting_key, setting_value, updated_by) VALUES
 ('static_hod_department_scope','all','SYSTEM'),
 ('static_dean_department_scope','all','SYSTEM'),
 ('coe_repeat_year_threshold','1','SYSTEM'),
 ('default_academic_year','2026-2027','SYSTEM'),
 ('docx_header_text','HOLY CROSS COLLEGE (AUTONOMOUS)','SYSTEM')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

DROP PROCEDURE IF EXISTS qps_phase1_add_column_if_missing;

-- Verification
SELECT setting_key, setting_value FROM qps_settings
WHERE setting_key IN ('static_hod_department_scope','static_dean_department_scope',
                      'coe_repeat_year_threshold','default_academic_year','docx_header_text')
ORDER BY setting_key;
SHOW TABLES LIKE 'qps_login_attempts';
SHOW COLUMNS FROM qps_audit_log;
