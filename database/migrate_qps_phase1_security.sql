-- QPS Phase 1: security, role scopes and structured audit.
-- MySQL 8 / MariaDB 10.4+. Additive and safe to re-run. Run against hccweb.
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

-- qps_audit_log already exists in the project; extend it without deleting legacy columns/data.
SET @db := DATABASE();
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=@db AND table_name='qps_audit_log')
  AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='qps_audit_log' AND column_name='from_status'),
  'ALTER TABLE qps_audit_log ADD COLUMN from_status VARCHAR(40) NULL AFTER ip_address',
  'SELECT 1'
);
PREPARE qps_stmt FROM @sql; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=@db AND table_name='qps_audit_log')
  AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='qps_audit_log' AND column_name='to_status'),
  'ALTER TABLE qps_audit_log ADD COLUMN to_status VARCHAR(40) NULL AFTER from_status',
  'SELECT 1'
);
PREPARE qps_stmt FROM @sql; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=@db AND table_name='qps_audit_log')
  AND NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='qps_audit_log' AND index_name='idx_qps_audit_status'),
  'ALTER TABLE qps_audit_log ADD KEY idx_qps_audit_status (entity_type,from_status,to_status,created_at)',
  'SELECT 1'
);
PREPARE qps_stmt FROM @sql; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

-- Static office roles are config-backed; scopes are configurable here.
INSERT INTO qps_settings (setting_key,setting_value,updated_by)
VALUES
 ('static_hod_department_scope','all','SYSTEM'),
 ('static_dean_department_scope','all','SYSTEM'),
 ('coe_repeat_year_threshold','1','SYSTEM'),
 ('default_academic_year','2026-2027','SYSTEM'),
 ('docx_header_text','HOLY CROSS COLLEGE (AUTONOMOUS)','SYSTEM')
ON DUPLICATE KEY UPDATE setting_value=setting_value;

-- Verification
SELECT setting_key, setting_value FROM qps_settings
WHERE setting_key IN ('static_hod_department_scope','static_dean_department_scope',
                      'coe_repeat_year_threshold','default_academic_year','docx_header_text');
SHOW TABLES LIKE 'qps_login_attempts';
