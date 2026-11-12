# Question Paper System — Workflow Upgrade Implementation

## Scope and compatibility

This upgrade adds a centralized Staff → HOD → Dean → COE workflow to the existing PHP 8 / PDO / MySQL-MariaDB application. It keeps the existing framework, Tailwind classes, SweetAlert2 conventions, authentication session and current question-bank blueprint generator. The existing `modules/teaching/view_banks.php` is retained as a compatibility page because the COE dashboard and older inspection links still use its repository/inspection features; the new staff correction queue is `modules/teaching/corrections.php`.

The project ZIP contains the new and modified PHP files, SQL migration, deployment seed script, and this manual test plan. PHP syntax checks were run across all 83 PHP files in the package; no syntax errors were reported. The MySQL migration and runtime workflows could not be executed against the live database because no live database connection or live `SHOW COLUMNS` export was supplied.

## Explicit assumptions and limitations

1. The live database is MySQL 8 or MariaDB and uses the existing tables `courses`, `timetablefaculty`, `question_banks`, `questions`, `question_bank_blueprints`, `qps_question_bank_drafts`, `qps_question_bank_draft_questions`, and `qps_final_blueprints`.
2. `courses.level` or `courses.programme` contains the programme level expected by Dean rules (for example `1st UG`, `2nd UG`, `1st PG`). If the source contains only `UG`/`PG`, rule lookup intentionally fails rather than guessing.
3. `courses.papertype` / `coursetype1` / `coursetype` identifies the configured paper type (`OBE`, `NON_OBE`, `PGDCA`). The draft metadata is populated from these course fields when questions are saved.
4. `question_bank_blueprints.matrix_json` is the existing QBB source. The workflow package stores its row ID; the existing automatic QBB generator is not modified.
5. The SQL file in the ZIP (`database/hcc_database.sql`) is SQLite-style (`AUTOINCREMENT`, `TEXT`, `BEGIN TRANSACTION`), not a live MySQL structure export. The migration therefore adds the expected `generated_papers.set_name` and `generated_papers.paper_data_json` aliases if absent, but it cannot safely copy data from unknown live legacy column names. Check `SHOW COLUMNS FROM generated_papers` on the live database before production rollout.
6. The new reviewer interface supports question, QBB and final-slot remarks. Staff applies corrections through the existing question-entry workspace. The structured `correction_json` column is reserved for field-level correction proposals; the initial shared review screen currently captures free-text remarks. A dedicated inline editor for HOD/Dean to directly edit unit/sub-unit/marks/question text is a follow-on UI task.
7. The shared QBB review currently presents the stored matrix JSON in a readable preformatted block. A fully normalized QBB matrix table renderer needs the exact live `matrix_json` shape for every existing course type.
8. The new set-key generation and set-key DOCX/view paths are implemented, but they have not been runtime-tested against the installed `python-docx` environment or the live generated-paper table.
9. The legacy random shuffle endpoint now returns HTTP 410 so it cannot bypass saved final allotments. Use the new three-step wizard in `modules/coe/shuffle.php`.

## Deployment

1. Back up the live database and the project directory.
2. Confirm the existing draft, QBB, Dean registry and COE allotment migrations have been applied:
   - `database/migrate_qps_question_bank_drafts.sql`
   - `database/migrate_question_bank_blueprints.sql`
   - `database/migrate_qps_dean_users.sql`
   - `database/migrate_qps_dean_review_actions.sql`
   - `database/migrate_coe_final_question_allotment.sql`
3. Run `database/migrate_workflow_dean_coe_v1.sql` using phpMyAdmin Import or the MySQL CLI. The file uses a short-lived stored procedure to add missing columns idempotently.
4. Run the static account seed script from the project root:
   ```bash
   php database/seed_static_users.php
   ```
   Defaults: Dean `dean` / `dean@123`; COE `coe` / `coe@123`. Set `QPS_DEAN_PASSWORD` and `QPS_COE_PASSWORD` environment variables before seeding if you want non-default passwords. The script stores password hashes, never plaintext.
5. Log in through the Dean tab with `dean` / `dean@123`, and through the COE tab with `coe` / `coe@123`.
6. In Dean → Manage K-Level / CO-Level Rules, save rules for every required semester, academic year, programme level and course type.
7. In COE → Settings, save the institution header and paper types/sections. Configure total marks to match the actual exam patterns.
8. Verify `SHOW COLUMNS FROM generated_papers`, `SHOW COLUMNS FROM questions`, and `SHOW COLUMNS FROM question_usage_history` before opening the production portals.

## New files

- `database/migrate_workflow_dean_coe_v1.sql`
- `database/seed_static_users.php`
- `includes/question_rules.php`
- `includes/workflow.php`
- `includes/paper_rebuild.php`
- `api/question_rules.php`
- `api/workflow.php`
- `api/remarks.php`
- `api/coe_paper_sets.php`
- `modules/dean/question_rules.php`
- `modules/auth/change_static_password.php`
- `modules/dean/coe_status.php`
- `modules/review/index.php`
- `modules/review/view.php`
- `modules/teaching/corrections.php`
- `modules/teaching/draft.php`
- `modules/teaching/completed_question_banks.php`
- `modules/coe/settings.php`

## Modified files

- `includes/auth.php` — strict Dean capability; static Dean/COE role support.
- `includes/system.php` — audit IP trusts `REMOTE_ADDR` unless the peer is explicitly configured in `QPS_TRUSTED_PROXIES`.
- `includes/sidebar.php` — staff correction/draft/completed links and restricted HOD/Dean portal menus.
- `modules/auth/login.php` — static Dean and COE login; removes Dean fallback passwords; records failed login events.
- `modules/teaching/upload.php` — staff-only portal context, K/CO read-only rule-derived display, slot remark styling, CSRF, validated three-artifact submission.
- `modules/teaching/dashboard.php` — staff portal context and correction queue link.
- `api/save_final_blueprint_slots.php` — shared server-side rule validation and assigned-course authorization.
- `api/save_draft_question.php` — CSRF for question create/update/delete; direct submit-to-HOD bypass disabled; draft course metadata is synchronized from `courses`.
- `api/verify_bank.php` — HOD can no longer forward directly to COE; COE commit requires workflow approval.
- `api/coe_final_allotment.php` — previous-year usage refresh, dimmed selectable questions, no rejection solely for prior usage, duplicate slot assignment enforcement, usage metadata and workflow allotment transition.
- `modules/dean/actions.php` — Dean approval goes through the centralized workflow and shared validator; Dean remarks enter the central remark queue.
- `modules/coe/dashboard.php` — removes `SUBMITTED_TO_HOD` from the COE pending queue and links to COE package review.
- `modules/coe/audit_logs.php` — exact COE-role authorization for audit access.
- `modules/coe/shuffle.php` — three-step saved-allotment set generator; legacy random UI hidden.
- `api/shuffle_generate.php` — disables legacy random re-selection.
- `modules/coe/view_paper.php` — set-key paper rebuild and view; supports saved header lines.
- `api/export_docx.php` — exports set-key rebuilt papers to DOCX.
- `modules/coe/file_manager.php` — cards for canonical set-key papers with department/course filters and view/DOCX links.
- `docx_generator.py` — renders saved dynamic exam-header lines in set-key DOCX exports.

## Shared validator

`includes/question_rules.php` exports:

```php
qps_rules_for_course(PDO $pdo, array $course): array

qps_validate_slots(
    PDO $pdo,
    array $course,
    array $slots,
    string $mode
): array
```

The validator:
- Resolves the exact academic year first, otherwise the latest earlier year.
- Prefers exact course type, then wildcard `*`.
- Requires a rule for every question number.
- Recomputes K-Level and CO-Level on the server.
- Rejects posted K/CO values that differ from Dean rules.
- Checks duplicate slot numbers, unit, sub-unit, question type and marks.
- In submit mode, checks configured paper total marks, section/type/marks rules where configured, optional expected slot count, and a matching clean question-bank draft question.

## Manual acceptance test

### Authentication and roles
1. Run the migration and seed script.
2. Dean tab: `dean` / `dean@123` opens the Dean portal.
3. COE tab: `coe` / `coe@123` opens the COE portal.
4. A normal teaching session receives 403 on Dean review and Dean rule URLs.
5. An ERP account without explicit Dean authentication receives 403 on Dean URLs.
6. Dean fallback passwords `hcc123`, `staff@123`, and `12345` are rejected on the Dean tab.
7. Check `qps_audit_log` for `LOGIN_SUCCESS_*` and `LOGIN_FAILED` rows with IP addresses.

### Rules and final blueprint
1. Save rules for Semester 1 / 2026-2027 / 1st UG / OBE, Q1–Q30.
2. Open Teaching → Question Entry, select the course and Final Question Slot Allocation tab.
3. Confirm only Unit, Subunit, Question Type and Marks are editable.
4. Confirm K-Level and CO-Level display the Dean rule values.
5. Tamper with a posted K-Level or CO-Level in the browser request; `api/save_final_blueprint_slots.php` must reject it.
6. Try a course with no rule set; it must be blocked with a clear error.
7. Try duplicate question numbers and a wrong total; submit validation must reject them.

### Workflow and remarks
1. Enter a question bank, confirm automatic QBB generation still works, save final blueprint and submit.
2. Confirm a package is created with all three artifact IDs and status `SUBMITTED_TO_HOD`.
3. HOD reviews each artifact. Add a question or final-slot remark and send it back.
4. Confirm the staff correction page shows the open remark; edit the question in the existing upload workspace and resubmit.
5. HOD verifies and forwards to Dean.
6. Dean adds a remark; confirm it appears in HOD → Remarks from Deans.
7. HOD forwards Dean remarks to staff; staff corrects and resubmits; HOD re-verifies and forwards to Dean.
8. Dean approves; package becomes `SUBMITTED_TO_COE`.
9. COE reviews all three artifacts and approves; package becomes `COE_APPROVED`, and the existing COE bank commit path runs.
10. Confirm drafts are not cleared at Dean approval and are only marked approved at COE approval.

### Allotment, shuffle and export
1. Open final allotment for a `COE_APPROVED` course.
2. Confirm questions used in the immediately previous academic year and same semester are dimmed but selectable.
3. Toggle “Hide last year used”; confirm only `is_used=1` questions are hidden.
4. Assign a question to one slot; confirm it is hidden from other slots and the server rejects duplicate assignments.
5. Save allotment; verify `times_used`, `last_used_exam`, `last_used_semester`, `last_used_year`, and `question_usage_history`.
6. In COE → Shuffle, choose course, choose 1/2/3 sets, generate.
7. Confirm canonical questions are stored once in `qps_final_papers.questions_json`, with one `qps_paper_sets` row per set.
8. Confirm Match, Assertion–Reason and Section D compulsory slots remain in original order; MCQ/paragraph/essay slots shuffle only within matching groups.
9. Open each `set_key` in `view_paper.php` and export DOCX; compare the rendered order to the saved set.
10. Confirm old `generated_papers` rows still open and export.

### Compatibility
1. Confirm the existing automatic question-bank blueprint generator still works.
2. Confirm legacy generated papers open and export using the original path.
3. Confirm COE's legacy file-manager recycle/restore and paper remark features still work.
