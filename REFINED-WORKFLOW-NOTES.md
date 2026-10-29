# COE Question Paper System — Workflow Refinement Notes

## Preserved integration
- The college-owned `hccweb-2` master tables remain external source-of-truth tables: `pr_x_xxxx_staf_prof_mast`, `departments`, `courses`, and `timetablefaculty`.
- Do not drop, rename, recreate, or alter those college tables. Authentication continues through the existing staff/ERP integration.
- The old teaching upload URL is retained only as a compatibility redirect to the structured question-entry form; it does not accept file-based question-bank uploads.

## Current workflow in this build
1. Teaching staff select a course assigned through `timetablefaculty`, then create/edit questions in a structured form. The draft API persists each question immediately and supports MCQ options, matching columns, marks, section, unit/sub-unit, K-level, CO-level, choice groups, and private diagrams.
2. The course question matrix is recalculated after question create/update/delete. It aggregates published/master questions and eligible draft questions by course, semester, academic year, exam type, unit/sub-unit, type, marks, K-level, and CO-level.
3. Staff submit the draft to HOD. HOD can verify questions or return specific questions with correction remarks. COE review follows the HOD stage.
4. COE approval promotes the accepted bank/questions into the master tables in a transaction and clears the temporary draft-question rows for that approved draft.
5. Final blueprint items store question number, section, unit/sub-unit, type, K-level, CO-level, marks, selected question and source bank. Paper history snapshots preserve prior paper content for repeat-question checks.
6. Activity logging records staff code/name/mobile where present in the session, role, action, entity, timestamp, IP and user-agent; COE has the log page.

## Refinement in this package
- Removed temporary `error_log()` diagnostics from `includes/auth.php`.
- Changed `requireAuth()` to redirect anonymous users before invoking additive schema checks, avoiding schema/DDL work for unauthenticated requests and reducing avoidable database contention.
- Existing college-owned tables are not modified by this change.

## Safe rollout
1. Stop editing the live database during backup.
2. Back up both the QPS database and the project directory.
3. Extract this project into a separate test folder first; configure `config/config.php` and `config/db.php` for the intended local database.
4. Confirm the college connection points to `hccweb-2` and the four ERP tables above are readable.
5. Sign in as a test teaching staff account; verify only assigned courses appear. Create/edit/delete a test question and confirm the matrix counts update.
6. Test HOD correction/approval and COE correction/approval using disposable test records.
7. Test final blueprint selection, Word export, paper history, and COE audit-log page.
8. Only after all checks pass, deploy during a maintenance window. Never use destructive schema reset scripts on the college database.

## Important testing boundary
PHP syntax lint was run for all PHP files in this package. A live MariaDB/college ERP integration test was not possible in this environment. Database column names and permissions should be verified against a backup/test copy of the actual `hccweb-2` schema before production rollout.
