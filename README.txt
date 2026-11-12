PAGE 3 – MASTER BLUEPRINT PATCH
================================

Files included
--------------
1. modules/teaching/question_bank_blueprint.php
2. api/teaching_master_blueprint_docx.php

Installation
------------
1. Take a backup of your current two files.
2. Copy question_bank_blueprint.php to:
   Question-Paper-System-new/modules/teaching/question_bank_blueprint.php
3. Copy teaching_master_blueprint_docx.php to:
   Question-Paper-System-new/api/teaching_master_blueprint_docx.php
4. Confirm PHP's ZipArchive extension is enabled (required for DOCX download).
5. Sign in as Teaching Staff and open Page 3 from the sidebar.

Behavior
--------
- Without course_code, Page 3 lists allocated courses with active draft questions.
- The list is scoped to the configured default academic year and regulation.
- Selecting a course opens its live question-bank blueprint.
- Detail access is still checked against timetablefaculty (fid + papercode).
- The blueprint reads questions_draft; it does not save a stale matrix.
- DOCX export uses the same live draft source and includes college/course details,
  academic context, matrix totals and per-section counts.
- Legacy paper_code query parameter is accepted as an alias for course_code.
- Invalid item_type values are rejected.
- The incorrect $marks variable in section classification is corrected to $m.

DOCX library
------------
This patch uses a small native DOCX OOXML package writer with PHP ZipArchive.
It does NOT use PhpWord and does not add a Composer dependency.

Important compatibility note
----------------------------
The code expects questions_draft to contain the columns already described in
the project schema: staff_id, course_code, regulation, academic_year, exam_type,
paper_type, is_active, unit_number, sub_unit_number, marks, blooms_level,
co_code, question_type, question_text, updated_at, id.

This patch has PHP syntax validation only. It has not been executed against
your live hccweb database. Test with one allocated course and verify that the
default academic year/regulation values match the question rows.


PAGE 4 - FINAL QUESTION SLOT CREATION PATCH

Based on the latest supplied Question-Paper-System-new ZIP (2026-11-11 10:32 upload).

Files replaced:
- modules/teaching/final_slot_allocation.php
- api/teaching_slot_eligibility.php
- api/teaching_save_final_allotment.php

New migration:
- database/migrate_teaching_final_blueprints_v1.sql

Installation:
1. Back up the current three PHP files and the database.
2. Run database/migrate_teaching_final_blueprints_v1.sql against the QPS database (hccweb).
3. Copy the folders/files from this patch into the project root, overwriting the three listed PHP files.
4. Sign in as Teaching Staff and open Final Question Slot Creation.

Behavior:
- Course list is restricted to timetablefaculty allocations for the signed-in staff member.
- Only active, published Dean templates are listed. When template academic year, semester and programme year are populated, they must match the selected context.
- Dean template section and slot rows are read-only.
- Every slot supports multiple Unit/Sub-unit combinations.
- Eligibility counts are calculated from questions_draft using staff, course, academic year, language, marks, blooms_level and co_code. Only editable draft/correction question records are considered; questions with open corrections are excluded.
- Server validates all allocations again during save. A slot cannot be saved without at least one selected combination, and each selected combination must meet the COE minimum.
- Progress is shown and save is blocked until every slot has an allocation.
- Saved allocations are restored by staff/course/template/context.
- Template name, sections, slot labels, marks, K-Level and CO-Level are copied into a snapshot so later Dean template edits do not rewrite saved allocation data.
- Minimum eligible questions is stored in qps_settings under FINAL_SLOT_MIN_ELIGIBLE_QUESTIONS (default 1). The UI reads the server setting; it is not user-editable. COE can change it using the UPDATE statement in the SQL migration.

IMPORTANT COMPATIBILITY NOTE:
The existing HOD/Dean/COE pipeline in this ZIP reads legacy qps_final_blueprints / qps_submission_packages. This patch deliberately does not auto-create or activate a qps_final_blueprints record, because that could expose a staff draft to COE before the intended submission/review workflow. The new normalized final_blueprints records are staff-owned drafts. A separate workflow bridge is required when Page 6 submission is implemented or when HOD/Dean/COE consumers are migrated.

Validation performed:
- PHP syntax check passed for all three PHP files.
- JavaScript syntax check passed for the Page 4 script.
- Live database execution and end-to-end browser tests were not possible in this environment.

Test checklist:
- Staff A cannot see or save Staff B's course.
- Select a matching published template and verify all sections/slots load.
- Confirm a template with a mismatched academic context is filtered out.
- Select multiple Unit/Sub-unit combinations for one slot.
- Verify count warnings when a combination has fewer than the COE minimum.
- Attempt to save with one unallocated slot (must fail).
- Save all slots, reload the page and confirm allocations restore.
- Edit the Dean template after saving and confirm the saved snapshot still preserves the original slot metadata.
- Confirm HOD/Dean/COE pages continue to behave as before; this patch does not alter their legacy tables.
