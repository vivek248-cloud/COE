-- COE Question Type / MCQ repair for hccweb
-- BACK UP hccweb before running this file.
-- Run the SELECT preview first. The UPDATE only fills blank question_type values
-- for rows with at least two structured MCQ options; it does not overwrite known types.

SELECT id, bank_id, course_code, q_number, section_type, marks, question_type, options_json
FROM questions
WHERE (question_type IS NULL OR TRIM(question_type) = '')
  AND options_json IS NOT NULL
  AND TRIM(options_json) <> ''
ORDER BY bank_id, q_number;

-- Preview only those rows whose options_json is valid JSON and contains at least
-- two choices. MySQL 8 / MariaDB versions with JSON_VALID support this expression.
SELECT id, bank_id, course_code, q_number, question_type, options_json
FROM questions
WHERE (question_type IS NULL OR TRIM(question_type) = '')
  AND options_json IS NOT NULL
  AND JSON_VALID(options_json)
  AND JSON_LENGTH(options_json) >= 2
ORDER BY bank_id, q_number;

-- Apply after reviewing the preview:
UPDATE questions
SET question_type = 'MCQ'
WHERE (question_type IS NULL OR TRIM(question_type) = '')
  AND options_json IS NOT NULL
  AND JSON_VALID(options_json)
  AND JSON_LENGTH(options_json) >= 2;

-- Verify counts after update.
SELECT question_type, section_type, marks, COUNT(*) AS question_count
FROM questions
GROUP BY question_type, section_type, marks
ORDER BY question_type, section_type, marks;
