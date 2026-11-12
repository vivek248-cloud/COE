# Phase 2 — Teaching Staff Portal

## Audit summary

- Teaching course assignments are sourced from `timetablefaculty` joined to `courses` and `departments`.
- Question-entry data is stored in `qps_question_bank_drafts` and `qps_question_bank_draft_questions`; the latter already stores unit, sub-unit, section, question type, individual marks, K-Level, CO-Level, language and staff/course ownership.
- `modules/teaching/upload.php` previously loaded `api/teaching_dean_templates.php`, Dean academic-year/programme/marks/semester options and `api/question_rules.php`, and exposed a final-slot tab. Those template/rule calls and slot UI were removed from Question Entry.
- `modules/teaching/question_bank_blueprint.php` already compiled a legacy summary matrix from draft questions. It now reads only the logged-in staff member's allocated course and owned draft, and adds an exact Unit/Sub-unit/Marks/K-Level/CO-Level count matrix plus DOCX export.
- The project has a Python DOCX generator for generated exam papers, but no DOCX extraction control in the existing `upload.php` question-entry form. Existing DOCX parsing/extraction files and APIs were left untouched.

## Changed files

- `modules/teaching/dashboard.php`
- `modules/teaching/upload.php`
- `modules/teaching/draft.php`
- `modules/teaching/question_bank_blueprint.php`
- `api/save_draft_question.php`
- `api/get_draft_questions.php`
- `api/get_staff_all_questions.php`
- `api/upload_diagram.php`
- `api/remarks.php`
- `api/teaching_master_blueprint_docx.php` (new)
- `database/migrate_qps_phase2_teaching.sql` (new)

## Data model decision

Reused the existing draft tables. Added `verification_status` to `qps_question_bank_draft_questions`, defaulting to `VERIFICATION_PENDING`, and `resolver_note` to `qps_remarks`. Existing `programme_year` values such as `1st UG` are normalized to Roman year values (`I`–`IV`) while the prior label is preserved in `programme_level`.

No final `questions` table writes are made in Phase 2. Teaching questions remain in the draft question bank until the later COE approval phase.

## Installation

1. Back up the project and database.
2. Run `database/migrate_qps_phase2_teaching.sql` in the target MySQL/MariaDB database after the Phase 1 and base workflow migrations.
3. Extract this patch into `C:\xampp\htdocs\Question-Paper-System-new`, preserving the folder structure and replacing only the listed files.
4. Ensure PHP's `zip` extension (`ZipArchive`) is enabled for the Master Blueprint DOCX download.
5. Sign in with a Teaching Staff account and test the checklist below.

## Test checklist

- [ ] Teaching dashboard shows only courses allocated through `timetablefaculty`.
- [ ] Course cards show course code/title/department, known semester context and three status chips.
- [ ] A course with no Dean template can still open Question Entry and save context.
- [ ] Academic Year accepts consecutive `YYYY-YYYY` only.
- [ ] Year of Programme accepts I, II, III, IV.
- [ ] Semester accepts 1 through 8.
- [ ] Language selection persists with the draft context.
- [ ] Question marks accept any whole number from 1 through 200 (including 30, 50 and 75).
- [ ] Question Type remains selected by Teaching Staff and is not coerced based on marks.
- [ ] K-Level K1–K6 and CO-Level CO1–CO6 are validated server-side.
- [ ] New questions are saved with their own context and `VERIFICATION_PENDING` status.
- [ ] Changing context creates/loads the matching staff-owned draft without using a template or overall Total Marks.
- [ ] Existing draft questions remain visible after the migration.
- [ ] Staff can edit and delete only questions in their own editable drafts.
- [ ] Draft page shows question rows, verification status, open correction text, Edit and Delete actions.
- [ ] Staff can resolve an open question correction with an optional reply note.
- [ ] Master Blueprint recomputes from current draft questions after add/edit/delete.
- [ ] Detailed matrix counts by Unit, Sub-unit, Marks, K-Level and CO-Level.
- [ ] DOCX download opens as a valid Word document.
- [ ] Non-Teaching role and unallocated course access are rejected by pages/APIs.
- [ ] Diagram upload rejects invalid CSRF and non-Teaching roles.

## Known phase boundary

The Submit Course page and full three-artifact workflow submission are Phase 3. The existing direct HOD submission action is not exposed from the draft list in this phase. Final slot creation remains a Phase 3 feature. HOD/Dean review transitions and COE approval remain in their later phases.

## Verification performed

- PHP syntax checks passed for all ten changed PHP files.
- `node --check` passed for the inline JavaScript extracted from `modules/teaching/upload.php`.
- SQL migration and DOCX download still require testing against the user's actual XAMPP database and PHP extensions.
