-- Repair existing imported rows for I25BI1CCT01 where section_type was incorrectly saved as SECTION-A.
-- BACK UP the database first. Review the SELECT before running the UPDATE.
START TRANSACTION;

SELECT q.id, q.q_number, q.section_type, q.question_type, q.marks, LEFT(q.question_text, 120) AS question_preview
FROM questions q
JOIN question_banks qb ON qb.id = q.bank_id
WHERE UPPER(qb.paper_code) = 'I25BI1CCT01'
ORDER BY q.q_number;

UPDATE questions q
JOIN question_banks qb ON qb.id = q.bank_id
SET
  q.marks = CASE
    WHEN UPPER(COALESCE(q.question_type, '')) IN ('PARAGRAPH','P','PA') AND COALESCE(q.marks, 0) IN (0,1) THEN 5
    WHEN UPPER(COALESCE(q.question_type, '')) IN ('ESSAY','E') AND COALESCE(q.marks, 0) IN (0,1) THEN 10
    ELSE q.marks
  END,
  q.section_type = CASE
    WHEN UPPER(COALESCE(q.question_type, '')) IN ('PARAGRAPH','P','PA') THEN 'SECTION-B'
    WHEN UPPER(COALESCE(q.question_type, '')) IN ('ESSAY','E') THEN 'SECTION-C'
    WHEN COALESCE(q.marks, 0) >= 8 THEN 'SECTION-C'
    WHEN COALESCE(q.marks, 0) >= 4 THEN 'SECTION-B'
    ELSE 'SECTION-A'
  END
WHERE UPPER(qb.paper_code) = 'I25BI1CCT01'
  AND (
    UPPER(COALESCE(q.section_type, '')) IN ('A','SECTION-A','SECTION A','')
    OR UPPER(COALESCE(q.question_type, '')) IN ('PARAGRAPH','P','PA','ESSAY','E')
  );

-- Verify counts before committing. If the results are unexpected, use ROLLBACK instead.
SELECT q.section_type, q.question_type, q.marks, COUNT(*) AS question_count
FROM questions q
JOIN question_banks qb ON qb.id = q.bank_id
WHERE UPPER(qb.paper_code) = 'I25BI1CCT01'
GROUP BY q.section_type, q.question_type, q.marks
ORDER BY q.section_type, q.question_type, q.marks;

COMMIT;
