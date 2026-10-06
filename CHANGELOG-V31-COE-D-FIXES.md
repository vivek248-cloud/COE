# V31 — COE Final Blueprint, History Protection & Verification Hardening

## COE blueprint
- COE final 30-question blueprint no longer depends on a published HOD Question Bank Blueprint.
- The HOD Question Bank Blueprint remains only a staff-upload validation rule controlled by its ON/OFF toggle.
- Verified uploaded question banks are the direct COE source pool.
- Section D is shown explicitly as a compulsory 10-mark requirement, matching the supplied LANGUAGE-QB sample.
- Section D is treated as a fixed question for all generated sets.
- Sections A-C remain independently shuffled and never borrow questions across sections.

## Previous-year protection
- COE question picking blocks questions used in the previous academic year for the same semester.
- Historical usage is read through `question_usage_history` and `generated_papers`.

## Staff course security
- Teaching/HOD course dropdowns use only `timetablefaculty` allocations.
- The department-course fallback was removed.
- `api/commit_upload.php` now performs a server-side timetable allocation check.

## Verification / SQLSTATE[21S01]
- HOD draft promotion now builds INSERT statements from the live `question_banks` and `questions` schema columns.
- This prevents `1136 Column count doesn't match value count` when the live hccweb schema differs from an older installation.

## COE repository
- Added `modules/coe/view_question_banks.php` for browsing and inspecting verified question banks.
- Added a COE sidebar entry for the repository.
- COE staff can access only Admin > Settings; other admin tabs redirect away.
