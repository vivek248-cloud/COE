# V34 — Fresh schema initialization and structured draft workflow

## Changes
- Added `qps_ensure_base_schema()` so the application creates the core `question_banks`, `questions`, `blueprints`, and `coe_users` tables before additive auxiliary migrations execute.
- Kept college-owned authentication and assignment tables (`pr_x_xxxx_staf_prof_mast`, `departments`, `courses`, `timetablefaculty`) untouched.
- Added a structured faculty question-entry screen at `modules/teaching/question-entry.php`.
- Added draft API actions for create/open, list, add/update/delete question, and submit to HOD.
- Added matching columns, MCQ options, answer key, K-level, CO-level, section, unit/subunit, compulsory flag, choice group, and private diagram upload support.
- Added a HOD/COE review queue at `modules/coe/review_drafts.php` with question-level verification/correction remarks and bank-level return/approval.
- HOD approval moves a verified bank to the COE queue. COE approval inserts the approved bank and normalized questions in a single transaction, records review history, marks the draft approved, and clears only the temporary draft-question rows.
- Added Section A/B/C/D pattern checks before faculty submission for the agreed 75-mark format.
- Updated dashboard shortcuts to open the new form workflow and review queue.

## Notes / verification
- PHP syntax lint passes for all project PHP files in the build environment.
- A live MariaDB integration test was not available in this build environment. Test first on a backup or local disposable database before replacing the current project.
- The existing college authentication and course assignment tables must already exist in the configured database. The core question-system tables are initialized automatically on the first request.
- Diagram files are stored under `storage/private/question-diagrams/`; configure web-server deny rules for the `storage` directory and serve diagrams through an authenticated controller before rendering them to end users.
