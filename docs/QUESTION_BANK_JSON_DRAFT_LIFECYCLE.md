# Question Bank JSON + Draft Lifecycle

## Upload and staging
- `api/commit_upload.php` resolves COE/HOD role flags explicitly and normalizes submit-action aliases (including `submitted_to_hod`) before routing regular faculty uploads to the draft-first workflow. It writes a versioned JSON snapshot into `qps_question_bank_drafts.questions_json`.
- It also inserts one structured row per question into `qps_question_bank_draft_questions`, including question number, unit/sub-unit, section, type, marks, K-level, CO-level, options JSON and answer key.
- The same JSON snapshot is archived under `storage/uploads/<paper-code>/<year>/UPLOADED QUESTION BANKS/`.

## HOD and COE review
- HOD verification promotes the full question set into `question_banks.questions_json` and inserts all normalized question rows into `questions`.
- The draft snapshot and its question rows remain linked to the promoted master bank while the bank is `SUBMITTED TO COE`.
- After COE approval succeeds, the existing approval workflow deletes the associated `qps_question_bank_drafts` and `qps_question_bank_draft_questions` rows. The master bank JSON and relational question rows remain.

## Blueprint validation
- When a published/enabled `question_bank_blueprints` record exists for the course/semester/year/exam, upload validation checks each configured blueprint grid cell against the uploaded questions by Unit, Sub-Unit, question type, K-level, marks and section.
- Upload is rejected with a cell-level diagnostic when the required number of eligible questions is not present.
- COE 30-Q matrix eligibility continues to use structured question-bank fields and excludes questions already assigned in the current matrix.

## Navigation
- Removed the login page Quick Switch Accounts panel and clarified the top-navigation comment. Normal sign-in and logout remain; no account-switching shortcut is exposed.

## Database migration
For an existing MySQL/MariaDB database, run `database/migrate_qps_question_bank_drafts.sql` once with the `hccweb` database selected. The schema helper also attempts to apply the `status` and `promoted_bank_id` compatibility changes automatically. Back up the database first.
