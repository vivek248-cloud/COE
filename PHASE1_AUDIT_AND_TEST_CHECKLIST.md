# COE Question Paper System — Phase 1 Audit & Delivery

## Audit summary

### Existing pages
- Teaching: `modules/teaching/dashboard.php`, `upload.php`, `draft.php`, `corrections.php`, `question_bank_blueprint.php`, `view_banks.php`, `completed_question_banks.php`.
- Dean: `modules/dean/dashboard.php`, `review.php`, `question_rules.php`, `actions.php`, `coe_status.php`.
- COE: `modules/coe/dashboard.php`, `review_bank.php`, `final_allotment.php`, `shuffle.php`, `view_paper.php`, `audit_logs.php`, `settings.php`, `staff_directory.php`.
- Shared review: `modules/review/index.php`, `submitted.php`, `view.php`.
- Authentication: `modules/auth/login.php`, `logout.php`, `change_static_password.php`.

### Existing tables and important columns

| Existing table | Relevant columns / purpose |
|---|---|
| `qps_question_bank_drafts` | `id`, `staff_code`, `dept_code`, `paper_code`, `course_title`, `semester`, `academic_year`, `exam_type`, `max_marks`, `total_marks`, `status`, review timestamps/remarks |
| `qps_question_bank_draft_questions` | `id`, `draft_id`, `staff_code`, `paper_code`, `semester`, `academic_year`, `q_number`, `unit_no`, `sub_unit`, `section_type`, `question_type`, `question_text`, `marks`, `k_level`, `co_level`, language, correction status |
| `questions` | Existing final question table; legacy SQL export includes `id`, `bank_id`, `q_number`, `unit_no`, `section_type`, `question_text`, `marks`, `k_level`, `co_level`, formula/image fields, `sub_unit`, `options_json`, `answer_key`, `language` |
| `question_paper_templates` | `id`, `template_name`, `total_marks`, `academic_year`, `year_of_programme`, `semester`, `class_sections`, `created_by`, `status`, timestamps |
| `question_paper_template_sections` | `id`, `template_id`, `section_name`, `sort_order` |
| `question_paper_template_slots` | `id`, `template_id`, `section_id`, `slot_label`, `marks`, `k_level`, `co_level`, `sort_order`, legacy `unit_id`, `question_id` |
| `qps_static_users` | `username`, `password_hash`, `display_name`, `role` (existing enum is DEAN/COE), active/change-password flags |
| `qps_dean_users` | Staff-code allow-list for legacy Dean login |
| `qps_submission_packages` | Course/context, item IDs, status, current owner, staff, department |
| `qps_workflow_events` | Package transition history, actor, role, from/to status, IP, note |
| `qps_remarks` | Package, target type/ref, reviewer role, destination role, text, OPEN/RESOLVED status |
| `qps_audit_log` | Staff, role, action, entity, details, IP, timestamp |
| `qps_settings` | Key/value configuration, update time and actor |

### What already matched
- Existing staff-master login and session identity.
- Dean and COE static-user support through `qps_static_users`.
- Dean template tables and separate section/slot rows.
- A package workflow helper (`includes/workflow.php`) with transaction-safe transitions.
- Existing review remarks/correction storage and workflow event history.
- Existing settings and audit tables.

### Gaps found and addressed in Phase 1
- No static HOD account in the login flow.
- Static login was partly database-backed and COE retained plaintext fallback credentials.
- Login page contained prefilled/demo credentials and GET quick-login shortcuts.
- No shared strict `requireRole()` guard for TEACHING/HOD/DEAN/COE.
- No login throttling table/helper.
- Existing audit events did not have dedicated `from_status` and `to_status` columns.
- Department scope was not represented as a central configurable setting.

## Phase 1 implementation

Changed files:
- `config/config.php` — bcrypt hash constants for HOD/DEAN/COE; login-throttle settings.
- `modules/auth/login.php` — config-backed static login, session ID regeneration, CSRF token checks, removes GET quick-login bypasses and demo passwords.
- `includes/auth.php` — normalized strict role guards, department-scope helpers, CSRF helpers and login-throttling helpers.
- `includes/workflow.php` — shared open-correction counter/guard and structured transition audit helper; existing workflow tables and transition model retained.
- `database/migrate_qps_phase1_security.sql` — idempotent creation of `qps_login_attempts`, additive audit columns/index and default settings.

Static passwords are represented in configuration only as bcrypt hashes. The usernames are `hod`, `dean`, and `coe`. Update hashes in `config/config.php` if the initial credentials are rotated.

## Proposed final table list

Reuse existing:
- `qps_question_bank_drafts`
- `qps_question_bank_draft_questions`
- `questions`
- `question_paper_templates`
- `question_paper_template_sections`
- `question_paper_template_slots`
- `qps_submission_packages`
- `qps_workflow_events`
- `qps_remarks`
- `qps_audit_log`
- `qps_settings`
- Existing staff master and timetable allocation tables

Add:
- `qps_login_attempts` (Phase 1)

Do not create duplicate `questions_draft`, `course_submissions`, `corrections`, or `audit_log` tables because the project already has equivalent tables under the `qps_*` names above. Additional Phase 2–8 tables will be introduced only when their phase is implemented.

## Installation
1. Back up the database and project.
2. Select the target MySQL/MariaDB database in phpMyAdmin.
3. Run `database/migrate_qps_phase1_security.sql`.
4. Replace the four PHP files above, preserving paths.
5. Log in using the new HOD / Office tab.

The initial HOD and Dean department scopes default to `all` to preserve current visibility. Change `static_hod_department_scope` and `static_dean_department_scope` in `qps_settings` to a JSON array such as `["SCI","COM"]` to restrict access.

## Rollback notes
- Back up first.
- Revert the four PHP files from the pre-Phase-1 backup.
- The migration is additive. To roll back the new throttle table, run `DROP TABLE qps_login_attempts;` only if you are certain no other release uses it.
- The audit columns/index and settings rows can safely remain; they do not delete or invalidate existing records. If removing them, first verify no later phase uses them. No existing data is dropped.

## Phase 1 test checklist
- [ ] Existing faculty/staff login still works with valid staff-master credentials.
- [ ] HOD static login creates a HOD session and opens the shared review queue.
- [ ] Dean static login opens the Dean portal.
- [ ] COE static login opens the COE portal.
- [ ] Wrong static password is rejected.
- [ ] Five failed attempts for the same username/IP trigger the configured lock window.
- [ ] Successful login regenerates the session ID and clears failed attempts.
- [ ] Missing/invalid login CSRF token is rejected.
- [ ] `requireRole(['TEACHING'])`, `requireRole(['HOD'])`, `requireRole(['DEAN'])`, and `requireRole(['COE'])` reject mismatched roles.
- [ ] HOD/Dean department scope reads `all` and JSON list values from `qps_settings`.
- [ ] Existing qps workflow transitions continue writing `qps_workflow_events`.
- [ ] Structured audit transition records contain actor, role, IP, from status and to status.
- [ ] Existing question, Dean template, submission and remark rows remain unchanged.
