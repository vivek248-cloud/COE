# COE database normalization and cleanup plan

## Immediate fix included
`api/commit_upload.php` now normalizes and persists `question_type` while building the final question record. It preserves the explicit type, maps common aliases (MC/MCQ, P/Paragraph, E/Essay, M/Match, AR/Assertion-Reason), and infers MCQ only when the source omitted type but has at least two structured options. The existing INSERT/UPDATE already writes `question_type` and `options_json`; the previous normalization stage had dropped `question_type` before those statements.

## Safe existing-data repair
1. Back up `hccweb`.
2. Run the SELECT previews in `database/repair_question_types_and_mcq.sql`.
3. Review that the matching rows really are MCQs.
4. Run the UPDATE statement and verify the final grouped counts.
The repair script only fills blank `question_type` values where `options_json` is valid JSON with at least two options. It does not overwrite non-empty question types.

## Recommended logical organization
Keep `questions` as the central question table, but always scope retrieval by `bank_id` and/or `course_code`. Avoid mixing subjects in a global unfiltered question list. The main lookup index should normally begin with `bank_id`, then the fields used for eligibility (`section_type`, `question_type`, `marks`, `unit_no`, `sub_unit`, `k_level`). Confirm existing indexes and query plans before adding a duplicate index.

Suggested logical entities:
- `courses`: one row per institutional course/paper code.
- `question_banks`: one uploaded version per course/paper bank, linked to course and staff metadata.
- `questions`: individual questions owned by `bank_id`, with normalized type, section, marks, unit/sub-unit, K-level and CO-level.
- `answer_keys`: optional normalized answer-key records linked to `question_id` and `bank_id`.
- `question_passages`, `question_passage_links`, `question_assertion_reason`: specialized structures for passage-based and assertion/reason question data.
- `qps_question_bank_drafts` and `qps_question_bank_draft_questions`: temporary review/staging data; purge only after successful COE approval/commit, not on failed approval.
- `generated_papers` and blueprint tables: preserve generated-paper history and blueprint assignments.

## Do not drop tables based only on names or row counts
The screenshot shows some tables with zero rows, but zero rows does not prove a table is unused. Some are staging tables or features waiting for data. Before removing any table, search all PHP/SQL code for references, inspect foreign keys/triggers/views, and take a backup. Run this read-only inventory query in phpMyAdmin:

```sql
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_ROWS DESC, TABLE_NAME;
```

Check foreign-key dependencies:

```sql
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY REFERENCED_TABLE_NAME, TABLE_NAME;
```

Check existing indexes before changing them:

```sql
SHOW INDEX FROM questions;
SHOW INDEX FROM question_banks;
SHOW INDEX FROM answer_keys;
```

## Suggested query pattern
Use a bank-scoped query instead of retrieving every question in `hccweb`:

```sql
SELECT id, bank_id, course_code, q_number, unit_no, sub_unit,
       section_type, question_type, marks, k_level, co_level,
       question_text, options_json, answer_key
FROM questions
WHERE bank_id = :bank_id
ORDER BY section_type, unit_no, sub_unit, q_number;
```

This makes the selected bank explicit and avoids accidentally mixing questions from different courses.
