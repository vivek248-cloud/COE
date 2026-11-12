# COE Question Paper System — Phase 1 audit and implementation

## Audit scope
Reviewed the supplied project archive: `modules/teaching/*`, `modules/dean/*`, `modules/coe/*`, `modules/review/*`, `api/*`, `includes/*`, `config/*`, and the SQL/migration files under `database/`.

## Existing implementation found

- **Teaching pages:** dashboard, upload, draft, corrections, completed banks, bank listing, question-bank blueprint, final slot allocation, template download and JSON export.
- **Dean pages:** dashboard, review, question rules, COE status and actions.
- **HOD/review pages:** `modules/review/index.php`, `submitted.php`, and `view.php`. There is no `modules/hod/` directory; the existing review module is the HOD workflow surface.
- **COE pages:** dashboard, bank review, final allotment, shuffle, file manager, settings, staff directory, audit logs, generated-paper preview/edit and error logs.
- **Existing APIs:** upload/parse/commit, draft question save/read, bank and blueprint APIs, teaching slot allocation/eligibility, Dean templates, HOD correction, remarks, workflow, COE candidates/allotment/paper sets, exports and generated-paper management.
- **Existing shared code:** `includes/auth.php` already defines strict `requireRole()`, `requireTeachingStaff()`, `requireHOD()`, `requireDeanRole()`, `requireCOERole()`, CSRF helpers, login throttling, and department-scope helpers. `includes/workflow.php` already has centralized transition, correction-count and audit-transition helpers.
- **Static logins:** `config/config.php` already stores bcrypt hashes for `hod`, `dean`, and `coe`; `modules/auth/login.php` uses `password_verify()`, CSRF, throttling and `session_regenerate_id(true)` for the config-backed static login path. No plaintext static passwords are needed in page code.
- **Settings:** `qps_settings` already exists in prior migrations and the runtime `qps_setting()` helper reads it. HOD and Dean scopes are represented by `static_hod_department_scope` and `static_dean_department_scope`; `all` or a JSON list is supported, with deny-by-default if a scope is missing.
- **Audit:** `qps_audit_log` is already created by earlier migrations, and `qps_audit()` supports both newer and legacy layouts. The existing workflow transition writer expects `staff_code`, `staff_name`, `from_status`, and `to_status` columns.

## Gaps / compatibility risks

1. The project has multiple generations of `qps_audit_log` schema. Some migrations create the legacy `actor` layout; others create `staff_code`/`staff_name`. The Phase 1 transition writer inserts into the latter layout, so an installation that only ran the legacy migration can fail to record transitions.
2. The existing `modules/auth/login.php` still contains separate legacy Dean/COE login branches backed by `qps_static_users` / `qps_dean_users`. The new config-backed `static` login branch exists too. These overlapping paths should be consolidated in a later controlled auth change, not removed blindly during this additive Phase 1.
3. Role guard coverage is incomplete across the project: several COE pages and numerous AJAX endpoints include `auth.php` but do not call a strict role guard near the entry point. `requireAuth()` alone is not sufficient for the requested per-role isolation. Existing mixed-purpose endpoints must be classified by action before adding guards so a legitimate caller is not broken.
4. HOD is served from `modules/review/*`; there is no dedicated `modules/hod/` module folder. The static HOD redirect points to the existing review module.
5. The supplied `database/hcc_database.sql` is a mixed/SQLite-oriented export in places (for example `INTEGER PRIMARY KEY AUTOINCREMENT`) and should not be treated as a production MySQL migration. Use the additive MySQL/MariaDB migration sequence instead.

## Proposed table plan

Reuse existing tables where possible; do not create parallel replacements:

- Existing identity/course sources: `pr_x_xxxx_staf_prof_mast`, `departments`, `courses`, `timetablefaculty`.
- Existing question sources: `question_banks`, `questions`, `qps_question_bank_drafts`, `qps_question_bank_draft_questions`.
- Existing templates/rules: `question_paper_templates`, `question_paper_template_sections`, `question_paper_template_slots`, `question_rules`.
- Existing workflow: `qps_submission_packages`, `qps_workflow_events`, `qps_remarks`, `qps_dean_review_actions`.
- Existing allocation/paper structures: `qps_teaching_final_allotments`, `qps_coe_question_allotments`, `qps_final_papers`, `qps_paper_sets`, `question_usage_history`, `qps_question_usage`.
- Existing security/audit: `qps_settings`, `qps_static_users`, `qps_login_attempts`, `qps_audit_log`.
- Later phases should add only missing entities such as item-granular `course_submissions`, generalized `corrections`, and normalized `final_question_papers` / `final_question_paper_slots` if the existing package/remarks/final-paper structures cannot represent the requested workflow. Do not add them in Phase 1 before confirming the later feature mapping.

## Phase 1 change in this package

Added `database/migrate_qps_phase1_security_v2.sql`. It is additive and re-runnable. It creates `qps_settings` and `qps_login_attempts` if absent, normalizes the audit columns by adding only missing columns, adds missing indexes, and seeds default settings without overwriting configured values. This specifically aligns the existing `qps_audit_transition()` insert contract with installations that have the older audit table layout.

The existing `config/config.php`, `includes/auth.php`, `includes/system.php`, `includes/workflow.php`, and static login path already contain most Phase 1 primitives, so they are retained rather than rewritten. The remaining role-guard coverage gap is explicitly recorded above and should be closed as a dedicated endpoint-by-endpoint security pass before Phase 2 is considered production-ready.

## Apply migration

1. Back up the target database.
2. Select the intended database (for the supplied project configuration, normally `hccweb`).
3. Run `database/migrate_qps_phase1_security_v2.sql` in phpMyAdmin or the MySQL/MariaDB CLI.
4. Verify the settings rows and audit columns shown at the end of the migration.

## Rollback note

No destructive rollback is provided: the migration only adds tables, columns and indexes, and inserts defaults. To roll back manually, first export any new audit/settings/login-attempt data you need to retain, then remove only the newly added indexes/columns/tables after checking that no application version depends on them. Never drop the pre-existing `qps_audit_log` or `qps_settings` tables.

## Phase 1 test checklist

- [ ] Run migration twice; second run completes without duplicate-column/index errors.
- [ ] Verify `qps_settings` retains manually changed HOD/Dean scopes after rerunning migration.
- [ ] Verify the three config-backed static account hashes still authenticate with the configured credentials; no plaintext password is stored in PHP page files.
- [ ] Verify successful static login regenerates the session ID.
- [ ] Verify five failed attempts for the same username/IP trigger throttling and a successful login clears the attempts.
- [ ] Verify CSRF failure blocks login POST.
- [ ] Verify `requireRole(['HOD'])`, `requireRole(['DEAN'])`, and `requireRole(['COE'])` reject other roles.
- [ ] Set HOD/Dean scopes to a JSON department list and verify `qps_require_department()` denies an out-of-scope department; verify `all` allows all.
- [ ] Trigger `qps_audit_transition()` and confirm actor, role, action, entity, from/to status, timestamp and IP are persisted.
- [ ] Complete the remaining endpoint-by-endpoint guard audit before treating the whole application as secure.
