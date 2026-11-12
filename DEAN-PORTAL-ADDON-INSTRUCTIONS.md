# Dean Portal Add-on (existing project preserved)

This add-on was added without editing or replacing existing project files. It adds only:

- `modules/dean/dashboard.php`
- `modules/dean/review.php`
- `modules/dean/actions.php`
- `database/migrate_qps_dean_review_actions.sql`
- this instruction file

The uploaded project already contains `isDean()` / `requireDean()` in `includes/auth.php` and `database/migrate_qps_dean_users.sql`. Those existing files were left unchanged.

## Install on a test copy

1. Back up the current project folder.
2. Extract/copy the add-on files into the matching folders in `C:\xampp\htdocs\Question-Paper-System-new\`. Do not replace any existing file.
3. In phpMyAdmin select the restored test database (for example `hccweb_coe_test`).
4. Run `database/migrate_qps_dean_users.sql` if the `qps_dean_users` table has not already been created and seeded.
5. Run `database/migrate_qps_dean_review_actions.sql` in that same test database.
6. Log in using the existing staff login with one of the active Dean staff codes.
7. Open `http://localhost/Question-Paper-System-new/modules/dean/dashboard.php`.

## Workflow status contract

The dashboard intentionally displays only `qps_question_bank_drafts.status = 'SUBMITTED_TO_DEAN'`. The existing HOD submission page/process must set this status when the HOD forwards a course. This add-on does not edit the HOD or teaching pages.

After Dean approval, the add-on changes the draft status to `SUBMITTED_TO_COE`, which is already recognized by the existing COE dashboard query in this project.

## Correction verification

A Dean remark marks the question `NEEDS_CORRECTION` and records the question's `version_no`. The teaching staff must save a newer version before the Dean can verify and clear the correction. This prevents the Dean from clearing a remark without a staff edit.

## Navigation

To honor the requirement not to change existing files, no existing sidebar or navbar was edited. Open the Dean dashboard URL directly for now. A Dean-only sidebar shortcut can be added later as a separate, explicitly approved one-line change to `includes/sidebar.php`.

## Scope and limitations

This add-on implements question-bank review using the existing draft tables. It does not alter or claim to implement HOD routing, master/final blueprint review, COE blueprint approval, or final paper allocation. Those need integration with the corresponding existing workflow/table structures in a separate step.
