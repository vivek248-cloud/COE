# V30 — COE Direct Assignment and Section D Eligibility Fix

## Changes
- Fixed effective-marks validation so the matrix-cell validator uses the same normalized 1M/5M/10M values as the eligible-question list.
- Fixed direct assignment from the Eligible Questions inspector: Section D slots (Q29/Q30) now target `COMP_K5` rather than the Section C `ESSAY_K14` column.
- Added use of `getSlotForBall()` for Q30, because the legacy canonical slot array ends at Q29.
- Added K5 filtering for Section D compulsory questions so the suggestion list respects the Section D K-level requirement.
- Direct assignment now validates Unit, Sub-Unit, K-Level, type, effective marks, section, previous-year duplicate status, and question text before changing the matrix.
- Mismatch messages now show the specific reason(s), rather than only a generic mismatch message.
- Existing slot assignments are moved safely without silently overwriting a different question in the target cell.

## Test status
- `php -l modules/coe/blueprint.php`: passed.
- Local XAMPP/database interaction: not run in this environment; verify with the current local question bank.
