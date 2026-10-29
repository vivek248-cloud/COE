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

V34 STRUCTURED QUESTION ENTRY / FRESH DATABASE
------------------------------------------------
Question Paper System data database: hccweb (DB_NAME in config/config.php).
Existing college identity/course database: hccweb-2 by default (COLLEGE_DB_NAME environment variable).
The staff master, departments, courses and timetablefaculty tables are read from the college database; they are not recreated or modified by this project.

On the first application request, qps_ensure_base_schema() creates the core question-system tables before qps_ensure_aux_schema() runs additive migrations. Make sure the configured database user can CREATE/ALTER tables in hccweb and can SELECT from the college database.

New staff page: modules/teaching/question-entry.php
HOD / COE draft review queue: modules/coe/review_drafts.php
Draft API: api/draft_entry.php
Review API: api/review_drafts.php

Before testing, back up both databases. Test the full lifecycle with a disposable staff/course assignment first. The implementation passes PHP syntax lint, but needs a live MariaDB workflow test in your XAMPP environment.
