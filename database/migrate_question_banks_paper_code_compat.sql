-- Safe additive compatibility migration for older hccweb question_banks tables.
-- Run once in phpMyAdmin against the application database.
SET @schema_name = DATABASE();
SET @has_paper_code = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'question_banks'
    AND COLUMN_NAME = 'paper_code'
);
SET @ddl = IF(@has_paper_code = 0,
  'ALTER TABLE `question_banks` ADD COLUMN `paper_code` VARCHAR(100) NULL AFTER `id`',
  'SELECT 1');
PREPARE qps_stmt FROM @ddl;
EXECUTE qps_stmt;
DEALLOCATE PREPARE qps_stmt;

SET @has_course_code = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'question_banks'
    AND COLUMN_NAME = 'course_code'
);
SET @has_papercode = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'question_banks'
    AND COLUMN_NAME = 'papercode'
);
SET @backfill_sql = IF(@has_course_code > 0,
  'UPDATE `question_banks` SET `paper_code` = `course_code` WHERE (`paper_code` IS NULL OR `paper_code` = '''') AND `course_code` IS NOT NULL',
  IF(@has_papercode > 0,
    'UPDATE `question_banks` SET `paper_code` = `papercode` WHERE (`paper_code` IS NULL OR `paper_code` = '''') AND `papercode` IS NOT NULL',
    'SELECT 1'));
PREPARE qps_stmt FROM @backfill_sql;
EXECUTE qps_stmt;
DEALLOCATE PREPARE qps_stmt;

-- Verify after migration:
-- SHOW COLUMNS FROM question_banks LIKE 'paper_code';
-- SELECT id, paper_code FROM question_banks ORDER BY id DESC LIMIT 20;
