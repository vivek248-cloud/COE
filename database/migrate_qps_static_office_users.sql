-- QPS: Move HOD / Dean / COE office credentials to qps_static_users.
-- MySQL 8 / MariaDB 10.4+. Additive and safe to re-run.
-- Run against the application's selected database (normally hccweb).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qps_static_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(150) NOT NULL,
  role ENUM('HOD','DEAN','COE') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qps_static_username (username),
  KEY idx_qps_static_role_active (role,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installations may have role ENUM('DEAN','COE'). Expand it to include HOD.
SET @db := DATABASE();
SET @sql := IF(
  EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=@db AND table_name='qps_static_users' AND column_name='role'
  )
  AND (
    SELECT column_type FROM information_schema.columns
    WHERE table_schema=@db AND table_name='qps_static_users' AND column_name='role'
    LIMIT 1
  ) NOT LIKE '%HOD%',
  'ALTER TABLE qps_static_users MODIFY role ENUM(''HOD'',''DEAN'',''COE'') NOT NULL',
  'SELECT 1'
);
PREPARE qps_stmt FROM @sql; EXECUTE qps_stmt; DEALLOCATE PREPARE qps_stmt;

-- Initial accounts. Passwords are bcrypt hashes, never stored as plaintext.
-- Initial login: hod / hod@123, dean / dean@123, coe / coe@123.
-- INSERT IGNORE preserves any existing database-managed accounts/passwords.
INSERT IGNORE INTO qps_static_users
(username,password_hash,display_name,role,is_active,must_change_password)
VALUES
('hod',  '$2y$12$UWnLVS6yYtIpV.vlQ1Kkg.EWc15sR05LTRwEhzUwbMxhwRnA.9Asy','HOD Office','HOD',1,0),
('dean', '$2y$12$PnKPFuVLNHmOVew/BJG8FucJS8cOjuTb24Pt6e4dD0aA3NZ1FQZbG','Dean Office','DEAN',1,0),
('coe',  '$2y$12$gnEd1RStU.qB8pJL1M4YYedr5ZwJv0mhW.QEmelnIXH7/Dzu9juxO','Controller of Examinations','COE',1,0);

-- Confirm accounts. Never select password_hash for display on a public page.
SELECT id,username,display_name,role,is_active,must_change_password
FROM qps_static_users
WHERE role IN ('HOD','DEAN','COE')
ORDER BY role,username;

-- Change username/display name directly in the database:
-- UPDATE qps_static_users SET username='HOD_STAFFCODE', display_name='Head of Department' WHERE role='HOD' AND username='hod';
--
-- Generate a new bcrypt hash in PHP:
-- php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT), PHP_EOL;"
-- Then update the password:
-- UPDATE qps_static_users SET password_hash='$2y$10$PASTE_GENERATED_HASH', must_change_password=0 WHERE role='HOD' AND username='HOD_STAFFCODE';
--
-- To disable an account:
-- UPDATE qps_static_users SET is_active=0 WHERE username='HOD_STAFFCODE';
