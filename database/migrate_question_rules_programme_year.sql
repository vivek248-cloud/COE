-- Ensure question_rules stores Year of Programme in its dedicated column.
-- Safe to run repeatedly on MySQL 8 / MariaDB.
SET @qps_has_programme_year := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'question_rules'
    AND COLUMN_NAME = 'programme_year'
);
SET @qps_sql := IF(
  @qps_has_programme_year = 0,
  'ALTER TABLE question_rules ADD COLUMN programme_year VARCHAR(30) NULL DEFAULT NULL AFTER academic_year',
  'SELECT 1'
);
PREPARE qps_stmt FROM @qps_sql;
EXECUTE qps_stmt;
DEALLOCATE PREPARE qps_stmt;

-- Existing Dean rules already have the selected value in programme_level.
-- Copy it once into programme_year where the dedicated value is blank.
UPDATE question_rules
SET programme_year = programme_level
WHERE (programme_year IS NULL OR TRIM(programme_year) = '')
  AND programme_level IS NOT NULL
  AND TRIM(programme_level) <> '';
