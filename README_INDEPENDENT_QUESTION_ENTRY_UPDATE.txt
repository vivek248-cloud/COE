# Focused Teaching Question Entry + Final Slot Allocation Update

## Files changed

- `modules/teaching/upload.php`
- `modules/teaching/final_slot_allocation.php`
- `api/save_draft_question.php`
- `api/get_draft_questions.php`
- `api/upload_diagram.php`
- `api/get_staff_all_questions.php`
- `api/teaching_dean_templates.php`
- `api/teaching_slot_eligibility.php`
- `api/teaching_save_final_allotment.php`
- `database/migrate_independent_question_entry_and_slot_allocations.sql`

No unrelated portal files were changed.

## Inspection summary

### Question Entry before the change

`upload.php` had a shared academic context that loaded Dean template options, showed a Dean template selector, loaded its sections/slots, called the Dean rule resolver, and attempted to save a final blueprint from the Question Entry page. This coupled question entry to the published template and its paper Total Marks.

### Current question table

The active question store is `qps_question_bank_draft_questions`. Its current schema includes:

`id, draft_id, fixed_q_no, staff_code, paper_code, semester, academic_year, q_number, unit_no, sub_unit, section_type, question_type, question_text, marks, k_level, co_level, options_json, answer_key, match_column_a, match_column_b, assertion_text, reason_text, image_url, has_formula, formula_latex, language, correction_status, remarks, version_no, created_at, updated_at`, plus compatibility/context columns added by the project's schema helpers/migrations: `programme_year, programme_level, total_marks, hod_approved_at, coe_approved_at, coe_returned_at, master_question_id`.

There is no `template_id` column in this question table. The course/subject is already represented by `paper_code` and the draft header's `course_title`; the question creator is represented by `staff_code`.

### Final Slot Allocation before the change

The page loaded published Dean templates through `api/teaching_dean_templates.php`, then loaded eligible units from `api/teaching_slot_eligibility.php`. The picker showed units/sub-units but did not require a concrete question ID per slot. The previous save API stored only unit/sub-unit choices in a JSON draft.

## What changed

### Question Entry

- Removed the Dean-template selector, template-loading requests, final-slot UI and template_id handling from `upload.php`.
- Academic Year is entered independently in `YYYY-YYYY` format and checked for consecutive years.
- Year of Programme is I, II, III or IV; Semester is 1-8.
- The existing allocated Course selector is the subject/course source.
- Unit/Sub-unit and Section remain part of each question because the existing question bank already stores them.
- Individual question marks accept any integer from 1 to 200.
- Question Type is selected by Teaching Staff and is not inferred from Dean templates or validated against marks.
- K-Level and CO-Level are validated server-side.
- The context is saved against a staff/course/context-specific draft and summarized with a Change context control.
- Existing question list/edit/delete and diagram upload behavior is retained. Legacy programme labels are also recognized when opening older drafts.

### Final Question Slot Allocation

- Staff selects an allocated course and then a published Dean template.
- Template selection pre-fills Academic Year, Year of Programme, Semester and Total Marks; the fields remain editable/confirmable.
- Actual published sections and slots are displayed.
- Every slot requires a selected question, not merely a Unit/Sub-unit.
- Server-side picker filtering is strict on individual question marks, K-Level, CO-Level, Academic Year, Year of Programme, Semester, Language and course; optional Unit Scope is supported.
- Already-selected question IDs are excluded from other slot pickers.
- The save API rechecks ownership, context, marks, K-Level, CO-Level, duplicate question IDs, complete slot count and template total marks in the backend.
- A live `X of N slots assigned` counter is shown.

### Allocation storage decision

New assignments are stored in `qps_teaching_final_allotments`, a separate table keyed by staff, course, template and academic context. `slots_json` stores the selected question IDs and the slot requirements; `template_snapshot` stores the template name, section/slot structure and template update timestamp at save time. This avoids overwriting the Dean's reusable `question_paper_template_slots.unit_id` / `question_id` fields when multiple staff use the same template, and preserves the saved template version when the Dean later edits the template.

The existing `question_paper_template_slots.unit_id` and `question_id` fields are left untouched for legacy assignments.

## Migration

Run `database/migrate_independent_question_entry_and_slot_allocations.sql` in phpMyAdmin with the application's database selected. It is additive and idempotent. It adds missing context columns only when absent and creates/extends the separate allocation table.

No `template_id` alteration is needed because the inspected question table does not contain a template ID column. No duplicate `subject` or `created_by` columns are added because existing `paper_code`/`course_title` and `staff_code` fields already represent those values.

Rollback: restore the previous PHP files if needed. Keep `qps_teaching_final_allotments` and its snapshots if any allocations have been saved. Drop the new table only after exporting its rows and confirming the snapshots are no longer needed. Do not drop existing question context columns.

## Static verification performed

- `php -l` passed for every changed PHP file.
- `node --check` passed for extracted inline JavaScript in both Teaching pages.
- Database integration tests were not run in this environment because no live hccweb database connection was available.

## Test checklist for XAMPP/phpMyAdmin

1. [ ] Open Question Entry when no Dean template exists/published. It should still save questions.
2. [ ] Save individual questions with 30, 50 and 75 marks (and other integer marks up to 200); no paper Total Marks selector should be required.
3. [ ] Verify questions store academic year, Roman programme year, semester, paper code, unit, marks, K-Level, CO-Level, language and staff_code.
4. [ ] Open an existing legacy draft using `1st UG`/`2nd UG` etc.; confirm the questions remain visible and context can be normalized to I/II/III/IV.
5. [ ] Select a Dean template in Final Slot Allocation; verify sections, slots, marks, K-Level and CO-Level match the published template.
6. [ ] Verify the question picker only lists exact marks + K-Level + CO-Level + academic year + programme year + semester + language + course matches.
7. [ ] Assign the same question to two slots; the picker should exclude it and the backend must reject a forged duplicate submission.
8. [ ] Submit a question with mismatched marks; backend save must reject it.
9. [ ] Leave one slot empty; save must be blocked.
10. [ ] Verify slot marks sum equals template Total Marks; a mismatch must be rejected.
11. [ ] Save an allocation, then edit the Dean template. Reopen the allocation page and verify it displays the saved earlier template snapshot read-only; verify `template_snapshot` and `slots_json` remain unchanged in `qps_teaching_final_allotments`.
12. [ ] Sign in as HOD, Dean or COE and attempt to open either Teaching page/API; access must be denied.
13. [ ] Confirm the existing `question_paper_template_slots.unit_id` / `question_id` values are unchanged.
