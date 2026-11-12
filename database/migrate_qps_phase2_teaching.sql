-- Phase 2: independent teaching question bank + draft verification status
-- MySQL 8 / MariaDB. Additive; run once or repeatedly. Never drops existing data.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DELIMITER $$
DROP PROCEDURE IF EXISTS qps_phase2_add_column$$
CREATE PROCEDURE qps_phase2_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table)
     AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column) THEN
    SET @q=CONCAT('ALTER TABLE `',REPLACE(p_table,'`','``'),'` ADD COLUMN `',REPLACE(p_column,'`','``'),'` ',p_definition);
    PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;
CALL qps_phase2_add_column('qps_question_bank_draft_questions','verification_status',"VARCHAR(30) NOT NULL DEFAULT 'VERIFICATION_PENDING'");
CALL qps_phase2_add_column('qps_question_bank_draft_questions','programme_year',"VARCHAR(30) NULL");
CALL qps_phase2_add_column('qps_question_bank_draft_questions','programme_level',"VARCHAR(30) NULL");
CALL qps_phase2_add_column('qps_question_bank_drafts','programme_year',"VARCHAR(30) NULL");
CALL qps_phase2_add_column('qps_question_bank_drafts','programme_level',"VARCHAR(30) NULL");
CALL qps_phase2_add_column('qps_remarks','resolver_note',"TEXT NULL");
DROP PROCEDURE IF EXISTS qps_phase2_add_column;

-- Add context indexes only when absent.
SET @idx_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_question_bank_draft_questions' AND INDEX_NAME='idx_qps_draft_context');
SET @sql=IF(@idx_exists=0,'CREATE INDEX idx_qps_draft_context ON qps_question_bank_draft_questions (staff_code,paper_code,academic_year,semester,programme_year,language)','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Normalize legacy programme_year labels to Roman years for the new independent question-entry context.
-- programme_level retains the original UG/PG label so legacy reports remain interpretable.
UPDATE qps_question_bank_drafts SET programme_level=COALESCE(NULLIF(programme_level,''),programme_year),
 programme_year=CASE UPPER(programme_year) WHEN '1ST UG' THEN 'I' WHEN '1ST PG' THEN 'I' WHEN '2ND UG' THEN 'II' WHEN '2ND PG' THEN 'II' WHEN '3RD UG' THEN 'III' WHEN '4TH UG' THEN 'IV' ELSE programme_year END
 WHERE UPPER(programme_year) IN ('1ST UG','1ST PG','2ND UG','2ND PG','3RD UG','4TH UG');
UPDATE qps_question_bank_draft_questions SET programme_level=COALESCE(NULLIF(programme_level,''),programme_year),
 programme_year=CASE UPPER(programme_year) WHEN '1ST UG' THEN 'I' WHEN '1ST PG' THEN 'I' WHEN '2ND UG' THEN 'II' WHEN '2ND PG' THEN 'II' WHEN '3RD UG' THEN 'III' WHEN '4TH UG' THEN 'IV' ELSE programme_year END
 WHERE UPPER(programme_year) IN ('1ST UG','1ST PG','2ND UG','2ND PG','3RD UG','4TH UG');

-- Existing rows remain visible. Their new verification_status uses VERIFICATION_PENDING by default.
-- Rollback note: do NOT drop verification_status or the indexes if any new questions have been entered;
-- if rollback is mandatory, export values first, then ALTER TABLE DROP COLUMN verification_status and
-- DROP INDEX idx_qps_draft_context manually. programme_year/programme_level are retained for compatibility.
