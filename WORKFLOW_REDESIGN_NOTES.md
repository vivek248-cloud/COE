# COE Question Workflow Redesign — Deployment Notes

## Existing authentication and ERP masters
- Staff authentication and course assignment continue to use the existing college master tables.
- `getCollegeDBConnection()` now defaults to `DB_NAME` (`hccweb`); set `COLLEGE_DB_NAME` only if the college master tables are actually in a separate database.
- Do not copy, rename, recreate, or drop `pr_x_xxxx_staf_prof_mast`, `departments`, `courses`, or `timetablefaculty`.

## Staff question entry
- `modules/teaching/question-entry.php` is the supported question entry route.
- The old `modules/teaching/upload.php` URL redirects to the structured entry form.
- Legacy file import endpoints now return HTTP 410.
- Question drafts remain editable while in DRAFT/returned statuses; HOD and COE reviews are recorded in review history.
- COE approval publishes question rows in a database transaction and clears only the draft question rows after successful inserts.

## Additive database migration
Run `database/migrate_workflow_blueprints_audit_history.sql` in phpMyAdmin with `hccweb` selected. It creates:
- `qps_activity_logs`: staff, role, action, timestamp, IP and user-agent log
- `qps_final_blueprints` and `qps_final_blueprint_items`: relational final blueprint header and item records
- `qps_question_paper_history` and `qps_question_paper_history_items`: immutable paper snapshots for reuse/duplicate checking

The activity logger also creates `qps_activity_logs` lazily on first event. The migration is idempotent and additive.

## Activity log page
COE/Super Admin/ERP users can open `modules/coe/logs.php`. Events are logged for login success, draft creation/opening, question add/update/delete, HOD submission, question reviews, bank return/approval, COE publication and DOCX export.

## Required production hardening before go-live
1. Replace local root/no-password DB credentials with a least-privilege MariaDB account.
2. Set a strong non-default COE password and remove development login shortcuts before exposing this app to a network.
3. Enable HTTPS and secure session cookies.
4. Verify actual staff-mobile column mapping before populating the log's `staff_mobile` field; the logger records mobile only if it is present in the authenticated session.
5. Test the 75-mark validation and the full HOD → COE → publish workflow using a staging copy of `hccweb`.
6. Do not delete legacy tables until PHP references and live row counts are audited.
