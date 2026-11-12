# Dean Login – Quick Start

1. Open the normal portal login page: `modules/auth/login.php`.
2. Select the **Dean Login** tab beside Faculty and COE Office.
3. Enter the same Staff Code and password used in the Faculty portal.
4. The system checks `qps_dean_users.is_active = 1`, then validates the credentials against `pr_x_xxxx_staf_prof_mast`.
5. On success, the existing faculty identity and role are retained and a separate Dean-session capability is activated.

Run `database/migrate_qps_dean_users.sql` once against the intended test database before testing. The Dean dashboard is `modules/dean/dashboard.php`. No `qps_static_users` table or static Dean password is used.

**Important:** A normal Faculty login does not grant Dean dashboard access. The user must explicitly sign in through the Dean Login tab.
