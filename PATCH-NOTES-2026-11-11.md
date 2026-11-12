# Teaching Portal Schema Compatibility Patch — 2026-11-11

Updated against the live `hccweb` schema supplied by the project owner.

## Files changed

- `api/teaching_save_final_allotment.php`
  - Removed misleading `CREATE TABLE IF NOT EXISTS` schema setup for already-existing tables.
  - Added explicit required-column checks with a clear migration error.
  - Reads the Dean template slot `question_type`.
  - Writes required `paper_code`, `unit_no`, `sub_unit`, and `question_type` values to `final_blueprint_slots`.
  - Preserves all selected Unit/Sub-unit combinations in `allocations_json`; legacy scalar columns store the first selected combination.
  - Validates selected template academic year, semester, and programme year.
  - Restricts workflow lock lookup to the selected semester.
  - Keeps saved template/slot snapshots and saved-allocation loading.
- `modules/teaching/dashboard.php`
  - Removed references to non-existent `course_submissions.version` and `course_submissions.regulation`.
  - Uses the newest submission row per course and does not fabricate a version number.
- `modules/teaching/question_bank_draft.php`
  - Uses `corrections.text` as `correction_remark`.
  - Uses `corrections.resolver_note` when resolving a correction.
  - Restricts correction resolution to questions owned by the current staff/course context.
  - Removes nonexistent `course_submissions.regulation`, `paper_type`, and `version` fields from the submission INSERT.
  - Keeps question-level `questions_draft.regulation` and `questions_draft.paper_type` filters intact.

## Validation

PHP syntax checks passed for all changed PHP files:

- `api/teaching_save_final_allotment.php`
- `api/teaching_dean_templates.php` (unchanged, checked)
- `modules/teaching/dashboard.php`
- `modules/teaching/question_bank_draft.php`
- `modules/teaching/final_slot_allocation.php` (unchanged, checked)

No live database/browser workflow test was run from this environment. The application must still be tested in the owner's XAMPP installation.

## Important scope note

Page 4 continues to save Unit/Sub-unit eligibility allocations, not individually selected question IDs. The existing downstream HOD/Dean/COE integration through legacy QPS blueprint tables has not been redesigned in this patch.
