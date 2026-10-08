# V29 – COE Blueprint, History & File Manager Enhancements

## COE Final 30 Blueprint
- Question numbers are hidden from the COE selection table.
- Section D is independently selectable and validated.
- K-level rules are enforced per section.
- Every checkbox is checked against the immediately previous academic year for the same course + semester.
- Previous-year questions remain visible but selecting one triggers a top-right SweetAlert error and row shake/fall animation.
- Questions become eligible again after the previous-year window has passed (two academic years completed).
- Added **Auto Pick Valid 30**; it selects only valid, non-previous-year questions and respects A/B/C/D requirements.
- Fixed blueprint INSERT parameter alignment.

## Exam Type & Marks Administration
- COE staff can access only `admin/index.php?tab=settings` from the Admin area.
- Added CRUD for Exam Types.
- Added CRUD for marks dropdown values.
- Exam Type labels are now loaded dynamically through `hcc_exam_types()`.
- Default labels are concise: `Odd Semester End Examination`, `Even Semester End Examination`, etc.

## COE File Manager
- Added `modules/coe/file_manager.php`.
- Storage hierarchy: `storage/uploads/<COURSE>/sem_<N>/<YEAR>/DOCS`, `GENERATED PAPERS`, `BLUEPRINTS`.
- Existing uploaded documents are backfilled into `DOCS` when a year folder is opened.
- Uploaded source documents are copied into `DOCS` during commit.
- Generated paper JSON snapshots are stored in `GENERATED PAPERS`.
- Saved blueprint JSON snapshots are stored in `BLUEPRINTS`.
- Added secure download handling and breadcrumb navigation.

## Generation History
- Generated papers now write question usage records to `qps_question_usage`.
- COE previous-year validation also reads generated paper JSON (`db_id` / `question_id`) so generated papers are an authoritative historical source.

## Login / Sidebar
- COE sidebar now includes File Manager and COE Settings.
- COE user card shows department information in addition to name/staff code/role.

## Testing
Run:

```bat
php -l modules/coe/blueprint.php
php -l modules/coe/file_manager.php
php -l modules/admin/index.php
php -l includes/system.php
php -l includes/sidebar.php
php -l modules/teaching/upload.php
php -l api/commit_upload.php
php -l api/shuffle_generate.php
```
