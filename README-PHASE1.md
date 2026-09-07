# Question Paper System — Phase 1

## Environment
- PHP 8.x
- MariaDB/MySQL
- XAMPP on Windows for development
- Project URL:
  http://localhost/Question-Paper-System-new/

## Install
1. Copy this project into:
   C:\xampp\htdocs\Question-Paper-System-new
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin.
4. Import:
   database/schema_phase1.sql
5. Check `config/config.php` and `config/database.php`.
6. Open:
   http://localhost/Question-Paper-System-new/database/create_test_users.php
7. Test Teaching Staff:
   http://localhost/Question-Paper-System-new/teaching/login.php
   User: teacher01
   Password: ChangeMe_Teacher_2026!
8. Test COE:
   http://localhost/Question-Paper-System-new/coe/login.php
   User: coe01
   Password: ChangeMe_COE_2026!

## IMPORTANT
`database/create_test_users.php` is development-only. Delete it after testing.

## Phase 1 success criteria
- Separate Teaching and COE login portals work.
- Passwords are stored as hashes.
- Role is read from the database.
- Teaching account cannot login through COE portal.
- COE account cannot login through Teaching portal.
- Sessions protect dashboards.
- Logout destroys the session.
- CSRF token is required on login POST.
- Login/logout actions are audited.
- Direct unauthorized dashboard access returns 403.

## Next phase
Only after Phase 1 is tested and confirmed:
Phase 2 = Teaching Staff question submission portal.
