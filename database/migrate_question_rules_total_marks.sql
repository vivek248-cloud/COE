-- Dean rule key migration: marks-based rule sets, safe to execute repeatedly.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DELIMITER $$
DROP PROCEDURE IF EXISTS qps_migrate_question_rules_total_marks$$
CREATE PROCEDURE qps_migrate_question_rules_total_marks()
BEGIN
  DECLARE v_json LONGTEXT DEFAULT NULL;
  DECLARE v_i INT DEFAULT 0;
  DECLARE v_len INT DEFAULT 0;
  DECLARE v_code VARCHAR(50) DEFAULT '';
  DECLARE v_marks INT DEFAULT 0;
  DECLARE v_keys LONGTEXT DEFAULT NULL;
  DECLARE v_key VARCHAR(50) DEFAULT '';
  DECLARE v_json_type VARCHAR(20) DEFAULT '';
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND COLUMN_NAME='total_marks') THEN
    ALTER TABLE question_rules ADD COLUMN total_marks SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER programme_level;
  END IF;
  -- Backfill legacy rows only when their course_type exactly matches one
  -- configured code in paper_types_json and that code has a numeric total_marks.
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_settings') THEN
    SET v_json=(SELECT setting_value FROM qps_settings WHERE setting_key='paper_types_json' LIMIT 1);
    IF v_json IS NOT NULL AND JSON_VALID(v_json) THEN
      SET v_json_type=JSON_TYPE(v_json); SET v_i=0;
      IF v_json_type='OBJECT' THEN SET v_keys=JSON_KEYS(v_json); SET v_len=JSON_LENGTH(v_keys); ELSE SET v_len=JSON_LENGTH(v_json); END IF;
      WHILE v_i < v_len DO
        IF v_json_type='OBJECT' THEN
          SET v_key=JSON_UNQUOTE(JSON_EXTRACT(v_keys,CONCAT('$[',v_i,']')));
          SET v_code=UPPER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_json,CONCAT('$.',v_key,'.code'))),v_key)));
          SET v_marks=CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_json,CONCAT('$.',v_key,'.total_marks'))),'0') AS UNSIGNED);
        ELSE
          SET v_code=UPPER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_json,CONCAT('$[',v_i,'].code'))),'')));
          SET v_marks=CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_json,CONCAT('$[',v_i,'].total_marks'))),'0') AS UNSIGNED);
        END IF;
        IF v_code<>'' AND v_marks>0 THEN
          UPDATE question_rules SET total_marks=v_marks WHERE total_marks=0 AND UPPER(course_type)=v_code;
        END IF;
        SET v_i=v_i+1;
      END WHILE;
    END IF;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='uq_qps_question_rule') THEN
    ALTER TABLE question_rules DROP INDEX uq_qps_question_rule;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='idx_qps_rule_lookup') THEN
    ALTER TABLE question_rules DROP INDEX idx_qps_rule_lookup;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='uq_qps_question_rule') THEN
    ALTER TABLE question_rules ADD UNIQUE KEY uq_qps_question_rule (semester,academic_year,programme_level,total_marks,question_number);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='idx_qps_rule_lookup') THEN
    ALTER TABLE question_rules ADD KEY idx_qps_rule_lookup (semester,programme_level,total_marks,academic_year);
  END IF;
END$$
CALL qps_migrate_question_rules_total_marks()$$
DROP PROCEDURE qps_migrate_question_rules_total_marks$$
DELIMITER ;

-- Existing rows remain at total_marks=0 unless their paper-type code can be
-- safely resolved from the configured paper_types_json. The current live
-- question_rules table was verified empty before this migration was authored.
SELECT id, semester, academic_year, programme_level, course_type, question_number
FROM question_rules WHERE total_marks=0 ORDER BY id;
