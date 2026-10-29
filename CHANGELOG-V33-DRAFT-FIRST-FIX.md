# V33 — Draft-First Upload Routing Fix

- Defined `$isCoe` and `$isHod` in `api/commit_upload.php` from the authenticated role helpers.
- Normalized `submit_action` values so `submitted_to_hod`, `submit_to_hod`, and common aliases consistently enter the HOD draft queue.
- Preserved the existing structured JSON snapshot and one-row-per-question draft persistence.
- Removed the Quick Switch Accounts panel from the login page; ordinary sign-in and logout remain.
- No database schema changes are required for this fix.
