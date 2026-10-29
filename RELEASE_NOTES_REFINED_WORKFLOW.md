# Refined Project Release Manifest

This ZIP is based on the uploaded project archive and includes these changes:
- College master-table connection defaults to the configured application database (`hccweb` by default), while preserving the existing staff authentication and timetable allocation tables.
- Legacy question upload page redirects to the structured question-entry form; old file import endpoints return HTTP 410.
- Staff navigation includes a Final Blueprint Builder and no longer exposes the import template link.
- Added relational final blueprint and question-paper history schemas plus a live per-course question-matrix snapshot table.
- Added a COE activity log page and best-effort activity events for login success, draft CRUD, review/approval, publication, and DOCX export.
- Development GET login shortcuts are disabled unless `QPS_ALLOW_DEV_SHORTCUTS=1` is explicitly set.
- Fixed HTML escaping in the question-entry JavaScript renderer.
- Added `WORKFLOW_REDESIGN_NOTES.md` and `database/migrate_workflow_blueprints_audit_history.sql`.

Validation performed:
- `php -l` passed for all 59 PHP files in this archive.
- Existing DOCX standard-template regression test passed.
- Live MariaDB integration, multi-user concurrency, browser interaction, and production deployment were not testable in this offline package build. Apply the additive migration in a backup/staging database first.
