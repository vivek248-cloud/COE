# COE K-Level 30-Question Blueprint Update

This is a **drop-in patch** for the current `vivek248-cloud/COE` project.

## File

Replace:

`modules/coe/blueprint.php`

with the supplied file.

## What this update changes

- COE blueprint works directly from the verified uploaded question pool.
- It does not require the HOD Question Bank Blueprint.
- Table is separated into:
  - Section A — 20 × 1 mark
  - Section B — 5 × 5 mark
  - Section C — 4 × 10 mark
  - Section D — 1 × 10 mark compulsory
- Exactly 30 questions are required before save.
- K-level validation:
  - Section A: K1/K2
  - Section B: K1/K2/K3
  - Section C: K2/K3/K4/K5
  - Section D: K3/K4/K5/K6
- Selected questions are highlighted.
- Previous academic year + same semester + same course/paper questions are locked.
- Unit and K-level filters are included.
- Server-side validation repeats the section/marks/K-level checks, so browser manipulation cannot bypass the rules.

## Historical rule

For an academic year such as `2026-2027`, the previous year is treated as `2025-2026`.

The previous-year check uses:

- course/paper code
- same semester
- previous academic year
- question usage/history

It checks both `qps_question_usage` and `qps_question_history` when those tables are available.

## Important

This ZIP is intentionally a **drop-in patch**, not a rebuilt copy of the entire repository. Your local working repository may contain recovered files that are newer than GitHub `main`.

Before replacing the file, make a backup:

`modules/coe/blueprint.php`

After testing locally:

```bash
git status
git diff -- modules/coe/blueprint.php
git add modules/coe/blueprint.php
git commit -m "Update COE 30-question K-level blueprint"
git push origin main
```

If your local branch is not `main`, push the branch you want.
