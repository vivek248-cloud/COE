# V35 targeted UI and diagnostics update

Base: `Question-Paper-System-new(20261106-065044).zip`

## Changes
- Added a live total-question counter beside the next-question number in Teaching Staff question entry.
- MCQ saving now persists the selected correct option in both `options_json.correct` and `answer_key` (`A`, `B`, `C`, or `D`); all four option texts and a selected answer are required.
- Draft Questions Pool is grouped into Unit sections with per-unit question counts; existing edit/delete actions remain available.
- Added a Save Slots action beside the Total Marks summary; saved slot cards receive their section-specific background tint.
- Added a Delete rules action beside View rules on Dean rule cards. It confirms and deletes the individual rows in that selected rule set through the existing API.
- Renamed the Dean sidebar link to `Dean Submitted Courses` while retaining `modules/review/index.php`.
- Added COE `System Errors` page. PHP warnings/fatal errors and uncaught browser errors/unhandled promise rejections are captured in `storage/private/qps-error-log.jsonl`; the page provides severity/search filters and expandable details.

## Files changed
- `modules/teaching/upload.php`
- `modules/dean/question_rules.php`
- `includes/sidebar.php`
- `includes/footer.php`
- `config/config.php`
- `includes/error_logger.php` (new)
- `api/log_client_error.php` (new)
- `modules/coe/error_logs.php` (new)

## Database
No SQL migration is required. MCQ answer keys use existing `answer_key` and `options_json` fields. Error diagnostics are stored in a private JSONL file under `storage/private`.

## Verification performed
- PHP syntax check across every PHP file in the project: passed.
- Node syntax checks for inline JavaScript in modified Teaching Staff and global footer pages: passed.
- No live MySQL/browser workflow test was available in this environment.
