# V28 – QBB Upload Gate & True Draft-to-Master Workflow

## Staff upload flow
1. Staff selects the course and uploads DOCX/XLSX/CSV/PDF/JSON.
2. The page reads the HOD Question Bank Blueprint state.
3. If **Use Question Bank Blueprint = ON**, the **Check Blueprint** button becomes mandatory.
4. The responsive match table shows Unit, Sub-Unit, Section, K, CO, Marks, Type, Required and Uploaded counts.
5. A shortage such as `Unit 5 / Sub-Unit 5.5: required 2, uploaded 1` blocks upload.
6. Only after validation passes does **Upload & Submit to HOD** unlock.
7. If QBB is OFF, the bank can be submitted without the QBB count gate.

## Database workflow
- Faculty uploads are stored in `qps_question_bank_drafts`.
- The draft stores the complete structured question JSON and the original uploaded source file.
- No `questions` rows are created during staff upload.
- Source documents are stored at:
  `storage/uploads/{course_code}/sem_{semester}/{academic_year}/`
- HOD verification promotes every question to `question_banks` + `questions` in one transaction.
- The draft row is deleted only after successful promotion.
- No question is intentionally skipped during promotion.

## Master question structure
`questions` now supports:
- `course_code`
- `section_type`
- `question_type`
- `unit_no`
- `sub_unit`
- `k_level`
- `co_level`
- `marks`
- `question_text`
- `question_json`
- `answer_key`
- `match_column_a`
- `match_column_b`
- `options_json`
- `image_url`
- formula fields and parser metadata

## HOD blueprint bootstrap
The HOD Question Bank Blueprint pool can read both already-approved master questions and submitted staff drafts for the selected course, allowing the department to build its first course blueprint before the new gated upload cycle is enabled.
