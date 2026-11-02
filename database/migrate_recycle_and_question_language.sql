-- COE portal: question language codes and 5-day recoverable deletion.
-- Run against the active application database (MySQL/MariaDB).
ALTER TABLE qps_question_bank_draft_questions
  MODIFY COLUMN language VARCHAR(2) NOT NULL DEFAULT 'EN';
UPDATE qps_question_bank_draft_questions
  SET language = CASE
    WHEN UPPER(language) IN ('TA','TAM','TAMIL','TL') THEN 'TL'
    WHEN UPPER(language) IN ('HI','HIN','HINDI') THEN 'HI'
    WHEN UPPER(language) IN ('FR','FRE','FRENCH') THEN 'FR'
    ELSE 'EN'
  END;

ALTER TABLE generated_papers
  ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN deleted_at DATETIME NULL,
  ADD COLUMN deleted_by VARCHAR(100) NULL,
  ADD COLUMN expires_at DATETIME NULL;

ALTER TABLE question_banks
  ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN deleted_at DATETIME NULL,
  ADD COLUMN deleted_by VARCHAR(100) NULL,
  ADD COLUMN expires_at DATETIME NULL;

-- Existing audit action values are intentionally not changed.
