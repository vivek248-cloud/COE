-- Independent Question Entry + immutable per-staff Dean-template allocation snapshots
-- Safe to re-run on the existing hccweb MariaDB/MySQL database.

DELIMITER $$
DROP PROCEDURE IF EXISTS qps_add_column_if_missing_independent$$
CREATE PROCEDURE qps_add_column_if_missing_independent(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT
)
BEGIN
    DECLARE v_table INT DEFAULT 0;
    DECLARE v_column INT DEFAULT 0;
    SELECT COUNT(*) INTO v_table FROM information_schema.TABLES
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table;
    IF v_table > 0 THEN
      SELECT COUNT(*) INTO v_column FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column;
      IF v_column=0 THEN
        SET @qps_independent_sql=CONCAT('ALTER TABLE `',REPLACE(p_table,'`','``'),'` ADD COLUMN `',REPLACE(p_column,'`','``'),'` ',p_definition);
        PREPARE qps_independent_stmt FROM @qps_independent_sql;
        EXECUTE qps_independent_stmt;
        DEALLOCATE PREPARE qps_independent_stmt;
      END IF;
    END IF;
END$$
DELIMITER ;

-- These context fields already exist in the project's current migration/schema;
-- calls add them only on older installations where they are missing.
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','academic_year','VARCHAR(50) NULL');
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','semester','VARCHAR(50) NULL');
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','programme_year','VARCHAR(30) NULL');
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','unit_no','INT NOT NULL DEFAULT 1');
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','marks','INT NOT NULL DEFAULT 1');
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','k_level','VARCHAR(20) NOT NULL DEFAULT ''K1''');
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','co_level','VARCHAR(20) NOT NULL DEFAULT ''CO1''');
CALL qps_add_column_if_missing_independent('qps_question_bank_draft_questions','language','VARCHAR(20) NOT NULL DEFAULT ''EN''');

-- Course/subject and creator are already represented by paper_code + the draft header's
-- course_title, and by staff_code on each question. No duplicate subject/created_by columns
-- are added. The current question table has no template_id column, so none is altered.

CREATE TABLE IF NOT EXISTS qps_teaching_final_allotments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_code VARCHAR(64) NOT NULL,
  paper_code VARCHAR(64) NOT NULL,
  template_id BIGINT UNSIGNED NOT NULL,
  semester VARCHAR(32) NOT NULL,
  academic_year VARCHAR(32) NOT NULL,
  programme_year VARCHAR(32) NOT NULL,
  total_marks INT NOT NULL,
  language VARCHAR(8) NOT NULL DEFAULT 'EN',
  draft_id BIGINT UNSIGNED NOT NULL,
  slots_json LONGTEXT NOT NULL,
  template_snapshot LONGTEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_staff_context (staff_code,paper_code,academic_year,programme_year,semester,total_marks),
  KEY idx_template_context (template_id,staff_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL qps_add_column_if_missing_independent('qps_teaching_final_allotments','template_snapshot','LONGTEXT NULL');

DROP PROCEDURE IF EXISTS qps_add_column_if_missing_independent;

-- Rollback note:
-- This migration is additive and preserves all existing question/template rows.
-- To roll back code only, restore the previous PHP files. Keep qps_teaching_final_allotments
-- and template_snapshot if any staff allocations have been saved. Only drop that table
-- after exporting/backing up its rows and confirming those saved allocation snapshots
-- are no longer required. Do not drop the existing question context columns.
