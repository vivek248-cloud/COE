# Dean authorization setup

This project uses the existing `pr_x_xxxx_staf_prof_mast` table for staff authentication. Dean status is an additional authorization capability stored in `qps_dean_users`; it does not change the existing staff login or replace the user's teaching-staff/HOD role.

## Initial Dean staff codes

- TCS2013M09
- TCH2014G01
- TZO2018G01
- TEL2014G01
- TEL2007G03
- trs1997g01
- TCO2022G01

## Apply on a restored test database only

1. Select the restored test database in phpMyAdmin (not `hccweb`).
2. Open the SQL tab.
3. Run `migrate_qps_dean_users.sql`.
4. Review its final verification result. A requested code with no `matched_staff_master_code` was not found in the staff master and was intentionally not granted Dean access.

The migration is additive and idempotent. It creates only `qps_dean_users` and inserts/activates matching staff codes. It does not alter staff credentials or existing roles.

## PHP authorization helper

`includes/auth.php` now provides `isDean()` and `requireDean()`. `isDean()` checks the authenticated session's staff code against an active registry row and fails closed if the migration has not been run. The Dean portal pages and every Dean API endpoint must call `requireDean()` independently.
