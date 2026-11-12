-- Question-context alignment migration for hccweb
-- Run once in phpMyAdmin with the database hccweb selected. Safe to run again.
-- Existing extra columns on question_rules are intentionally preserved.

DELIMITER $$
DROP PROCEDURE IF EXISTS qps_context_add_column$$
CREATE PROCEDURE qps_context_add_column(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_exists
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column;
        IF v_exists = 0 THEN
            SET @qps_context_sql = CONCAT('ALTER TABLE `', REPLACE(p_table,'`','``'), '` ADD COLUMN `', REPLACE(p_column,'`','``'), '` ', p_definition);
            PREPARE qps_context_stmt FROM @qps_context_sql;
            EXECUTE qps_context_stmt;
            DEALLOCATE PREPARE qps_context_stmt;
        END IF;
    END IF;
END$$
DELIMITER ;

-- Dean rules context fields.
CALL qps_context_add_column('question_rules', 'programme_year', 'VARCHAR(30) NULL AFTER academic_year');
CALL qps_context_add_column('question_rules', 'total_marks', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER programme_level');
CALL qps_context_add_column('question_rules', 'course_type', 'VARCHAR(50) NOT NULL DEFAULT ''*'' AFTER programme_year');

-- Draft header context.
CALL qps_context_add_column('qps_question_bank_drafts', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_context_add_column('qps_question_bank_drafts', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_context_add_column('qps_question_bank_drafts', 'total_marks', 'INT NOT NULL DEFAULT 0');
CALL qps_context_add_column('qps_question_bank_drafts', 'language', "VARCHAR(20) NOT NULL DEFAULT 'EN'");
CALL qps_context_add_column('qps_question_bank_drafts', 'semester', 'VARCHAR(50) NULL');
CALL qps_context_add_column('qps_question_bank_drafts', 'academic_year', 'VARCHAR(50) NULL');

-- Every draft question retains the exact same context as its draft header.
CALL qps_context_add_column('qps_question_bank_draft_questions', 'semester', 'VARCHAR(50) NULL');
CALL qps_context_add_column('qps_question_bank_draft_questions', 'academic_year', 'VARCHAR(50) NULL');
CALL qps_context_add_column('qps_question_bank_draft_questions', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_context_add_column('qps_question_bank_draft_questions', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_context_add_column('qps_question_bank_draft_questions', 'total_marks', 'INT NOT NULL DEFAULT 0');
CALL qps_context_add_column('qps_question_bank_draft_questions', 'language', "VARCHAR(20) NOT NULL DEFAULT 'EN'");

-- Final blueprint context.
CALL qps_context_add_column('qps_final_blueprints', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_context_add_column('qps_final_blueprints', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_context_add_column('qps_final_blueprints', 'language', "VARCHAR(20) NOT NULL DEFAULT 'EN'");

-- Approved master-bank and master-question context.
CALL qps_context_add_column('question_banks', 'semester', 'VARCHAR(50) NULL');
CALL qps_context_add_column('question_banks', 'academic_year', 'VARCHAR(50) NULL');
CALL qps_context_add_column('question_banks', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_context_add_column('question_banks', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_context_add_column('question_banks', 'total_marks', 'INT NOT NULL DEFAULT 0');
CALL qps_context_add_column('question_banks', 'language', "VARCHAR(20) NOT NULL DEFAULT 'EN'");
CALL qps_context_add_column('questions', 'semester', 'VARCHAR(50) NULL');
CALL qps_context_add_column('questions', 'academic_year', 'VARCHAR(50) NULL');
CALL qps_context_add_column('questions', 'programme_year', 'VARCHAR(30) NULL');
CALL qps_context_add_column('questions', 'programme_level', 'VARCHAR(30) NULL');
CALL qps_context_add_column('questions', 'total_marks', 'INT NOT NULL DEFAULT 0');
CALL qps_context_add_column('questions', 'language', "VARCHAR(20) NOT NULL DEFAULT 'EN'");

DROP PROCEDURE IF EXISTS qps_context_add_column;

-- Backfill legacy Dean rules. Do not infer a programme year from generic UG/PG labels.
UPDATE question_rules
   SET programme_year = programme_level
 WHERE (programme_year IS NULL OR TRIM(programme_year) = '')
   AND programme_level IN ('1st UG','2nd UG','3rd UG','1st PG','2nd PG');
UPDATE question_rules
   SET programme_level = programme_year
 WHERE (programme_level IS NULL OR TRIM(programme_level) = '')
   AND programme_year IN ('1st UG','2nd UG','3rd UG','1st PG','2nd PG');
UPDATE question_rules SET course_type='*';

-- Copy header context to existing draft questions where the per-question values are blank.
UPDATE qps_question_bank_draft_questions q
JOIN qps_question_bank_drafts d ON d.id = q.draft_id
   SET q.semester = d.semester,
       q.academic_year = d.academic_year,
       q.programme_year = COALESCE(NULLIF(d.programme_year,''), d.programme_level),
       q.programme_level = COALESCE(NULLIF(d.programme_year,''), d.programme_level),
       q.total_marks = COALESCE(NULLIF(d.total_marks,0),d.max_marks,0),
       q.language = COALESCE(NULLIF(d.language,''), q.language, 'EN');

-- Populate legacy bank headers from the latest matching staff draft when the old bank lacks context.
UPDATE question_banks b
JOIN qps_question_bank_drafts d
  ON d.id = (
       SELECT MAX(d2.id)
         FROM qps_question_bank_drafts d2
        WHERE UPPER(d2.paper_code)=UPPER(b.paper_code)
          AND d2.semester=b.semester
          AND d2.academic_year=b.academic_year
     )
   SET b.programme_year = COALESCE(NULLIF(d.programme_year,''), d.programme_level),
       b.programme_level = COALESCE(NULLIF(d.programme_year,''), d.programme_level),
       b.total_marks = COALESCE(NULLIF(d.total_marks,0),d.max_marks,0),
       b.language = COALESCE(NULLIF(d.language,''),'EN');

-- Copy the retained bank context to questions already promoted to the master bank.
UPDATE questions q
JOIN question_banks b ON b.id = q.bank_id
   SET q.semester = COALESCE(NULLIF(q.semester,''), b.semester),
       q.academic_year = COALESCE(NULLIF(q.academic_year,''), b.academic_year),
       q.programme_year = COALESCE(NULLIF(q.programme_year,''), b.programme_year, b.programme_level),
       q.programme_level = COALESCE(NULLIF(q.programme_level,''), b.programme_year, b.programme_level),
       q.total_marks = CASE WHEN q.total_marks IS NULL OR q.total_marks=0 THEN COALESCE(b.total_marks,0) ELSE q.total_marks END,
       q.language = COALESCE(NULLIF(q.language,''), b.language, 'EN');

-- Preserve programme_year on saved final blueprints using the matching retained draft.
UPDATE qps_final_blueprints f
JOIN qps_question_bank_drafts d
  ON UPPER(d.paper_code)=UPPER(f.paper_code)
 AND d.semester=f.semester
 AND d.academic_year=f.academic_year
   SET f.programme_year = COALESCE(NULLIF(f.programme_year,''), d.programme_year, d.programme_level),
       f.programme_level = COALESCE(NULLIF(f.programme_level,''), d.programme_year, d.programme_level),
       f.language = COALESCE(NULLIF(d.language,''), 'EN')
 WHERE f.programme_year IS NULL OR TRIM(f.programme_year)='';

-- Move question_rules uniqueness from the compatibility programme_level to programme_year.
-- The existing sample data (Q1..Q30/16) is already unique by this context.
DELIMITER $$
DROP PROCEDURE IF EXISTS qps_context_rebuild_rule_indexes$$
CREATE PROCEDURE qps_context_rebuild_rule_indexes()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='uq_qps_question_rule') THEN
        ALTER TABLE question_rules DROP INDEX uq_qps_question_rule;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='idx_qps_rule_lookup') THEN
        ALTER TABLE question_rules DROP INDEX idx_qps_rule_lookup;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='uq_qps_question_rule') THEN
        ALTER TABLE question_rules ADD UNIQUE KEY uq_qps_question_rule (semester, academic_year, programme_year, total_marks, question_number);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_rules' AND INDEX_NAME='idx_qps_rule_lookup') THEN
        ALTER TABLE question_rules ADD KEY idx_qps_rule_lookup (semester, programme_year, total_marks, academic_year);
    END IF;
END$$
DELIMITER ;
CALL qps_context_rebuild_rule_indexes();
DROP PROCEDURE IF EXISTS qps_context_rebuild_rule_indexes;

-- Verification query. Expected rows should show programme_year populated.
SELECT id, semester, academic_year, programme_year, programme_level, course_type,
       question_number, k_level, co_level, total_marks
  FROM question_rules
 ORDER BY academic_year DESC, total_marks DESC, programme_year, question_number;
