
<?php
/**
 * Authentication and Session Management
 * Holy Cross College (Autonomous)
 * Multi-Role: Teaching Faculty, HOD, Dean, COE,
 * ERP Department / Super Admin
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/system.php';


/* =========================================================
   BASIC AUTHENTICATION
========================================================= */

function isLoggedIn(): bool {
    return isset($_SESSION['user'])
        && !empty($_SESSION['user']['staff_code']);
}

function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function getUserRole(): string {
    return $_SESSION['user']['role'] ?? 'GUEST';
}




/**
 * Verify a staff member's password from STAFF_PASSWORD.
 * Supports bcrypt, Argon2, MD5, SHA1, SHA256 and legacy plaintext.
 *
 * Empty passwords are always rejected.
 */
function qps_verify_staff_master_password(
    string $submittedPassword,
    string $storedPassword
): bool {

    if ($submittedPassword === '' || $storedPassword === '') {
        return false;
    }

    // Modern PHP password hashes: bcrypt and Argon2.
    if (preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $storedPassword)) {
        return password_verify($submittedPassword, $storedPassword);
    }

    // Legacy MD5.
    if (preg_match('/^[a-f0-9]{32}$/i', $storedPassword)) {
        return hash_equals(
            strtolower($storedPassword),
            md5($submittedPassword)
        );
    }

    // Legacy SHA1.
    if (preg_match('/^[a-f0-9]{40}$/i', $storedPassword)) {
        return hash_equals(
            strtolower($storedPassword),
            sha1($submittedPassword)
        );
    }

    // Legacy SHA256.
    if (preg_match('/^[a-f0-9]{64}$/i', $storedPassword)) {
        return hash_equals(
            strtolower($storedPassword),
            hash('sha256', $submittedPassword)
        );
    }

    // Legacy plaintext comparison.
    return hash_equals($storedPassword, $submittedPassword);
}






/* =========================================================
   ERP STAFF / ADMIN CHECK
========================================================= */

/**
 * ERP staff has full access across:
 * COE, Admin, Master Repositories and Teaching.
 */
function isERPStaff(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    $role = strtoupper(getUserRole());

    if (in_array($role, [
        'SUPER_ADMIN',
        'ADMIN',
        'ERP_STAFF',
        'ERP_ADMIN',
        'SYSTEM_ADMIN'
    ], true)) {
        return true;
    }

    if (
        !empty($_SESSION['user']['is_super_admin']) ||
        !empty($_SESSION['user']['is_erp_staff'])
    ) {
        return true;
    }

    $user = getCurrentUser();

    $dept = strtoupper((string)(
        $user['department']
        ?? $user['dept_name']
        ?? $user['dept_code']
        ?? ''
    ));

    $staffCode = strtoupper((string)(
        $user['staff_code'] ?? ''
    ));

    $desig = strtoupper((string)(
        $user['designation'] ?? ''
    ));

    if (
        strpos($dept, 'ERP') !== false ||
        strpos($dept, 'RP') !== false ||
        strpos($dept, 'ADMIN') !== false ||
        strpos($dept, 'SYSTEM') !== false ||
        strpos($dept, 'COMPUTER') !== false ||
        strpos($dept, 'SOFTWARE') !== false ||
        strpos($staffCode, 'ADMIN') !== false ||
        strpos($staffCode, 'ERP') !== false ||
        strpos($desig, 'ERP') !== false ||
        strpos($desig, 'DEVELOPER') !== false ||
        strpos($desig, 'ADMIN') !== false ||
        strpos($desig, 'WEB ADMIN') !== false ||
        strpos($desig, 'DEV') !== false
    ) {
        return true;
    }

    return false;
}


function isSuperAdmin(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    $role = strtoupper(getUserRole());

    if (in_array($role, [
        'SUPER_ADMIN',
        'ADMIN',
        'ERP_ADMIN'
    ], true)) {
        return true;
    }

    if (!empty($_SESSION['user']['is_super_admin'])) {
        return true;
    }

    return isERPStaff();
}


/* =========================================================
   COE AUTHORIZATION
========================================================= */

/**
 * COE Office, Super Admin and ERP access.
 */
function isCOE(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    if (($_SESSION['qps_static_role'] ?? '') === 'COE') {
        return true;
    }

    $role = strtoupper(getUserRole());

    if (in_array($role, [
        'COE_ADMIN',
        'COE_STAFF',
        'SUPER_ADMIN',
        'ADMIN',
        'COE',
        'ERP_ADMIN',
        'ERP_STAFF'
    ], true)) {
        return true;
    }

    return isERPStaff();
}


function isCOEStaff(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    $role = strtoupper(getUserRole());

    return in_array($role, [
        'COE_STAFF',
        'COE_ADMIN',
        'COE',
        'ERP_ADMIN',
        'SUPER_ADMIN'
    ], true) || isERPStaff();
}


/* =========================================================
   DEAN AUTHORIZATION
========================================================= */

/**
 * Dean authorization is an additional capability.
 * A staff member must explicitly use the Dean login tab.
 */
function isDean(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    return strtoupper((string)getUserRole()) === 'DEAN'
        && !empty($_SESSION['qps_dean_authenticated']);
}


function requireDean(): void {

    requireAuth();

    if (!isDean()) {
        http_response_code(403);
        exit('Access denied. Please sign in through the Dean login.');
    }
}


/* =========================================================
   HOD AUTHORIZATION
========================================================= */

function isHOD(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    return strtoupper((string)getUserRole()) === 'HOD';
}


function requireHOD(): void {

    requireAuth();

    if (!isHOD()) {
        http_response_code(403);
        exit('Access denied. HOD access required.');
    }
}


/* =========================================================
   TEACHING STAFF AUTHORIZATION
========================================================= */

/**
 * Check whether the logged-in user is Teaching Staff.
 *
 * Supported session roles:
 * TEACHING_STAFF
 * FACULTY
 * TEACHING_FACULTY
 */
function isTeachingStaff(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    $role = strtoupper((string)getUserRole());

    return in_array($role, [
        'TEACHING_STAFF',
        'FACULTY',
        'TEACHING_FACULTY'
    ], true);
}


/**
 * Protect Teaching Staff pages.
 */
function requireTeachingStaff(): void {

    requireAuth();

    if (!isTeachingStaff()) {
        http_response_code(403);
        exit('Access denied. Teaching Staff access required.');
    }
}


/* =========================================================
   ERP MASTER PERMISSIONS
========================================================= */

/**
 * Super Admin and ERP staff have full CRUD/edit access
 * across ERP Master tables.
 */
function canEditERPMasters(): bool {

    if (!isLoggedIn()) {
        return false;
    }

    return isSuperAdmin() || isERPStaff();
}


/* =========================================================
   COMMON AUTHORIZATION
========================================================= */

function requireAuth(): void {

    qps_ensure_aux_schema(getDBConnection());

    if (!isLoggedIn()) {

        header(
            "Location: " .
            getBaseUrl() .
            "/modules/auth/login.php"
        );

        exit;
    }

    if (!empty($_SESSION['qps_static_must_change'])) {

        $script = basename(
            (string)($_SERVER['SCRIPT_NAME'] ?? '')
        );

        if (
            $script !== 'change_static_password.php' &&
            $script !== 'logout.php'
        ) {

            header(
                "Location: " .
                getBaseUrl() .
                "/modules/auth/change_static_password.php"
            );

            exit;
        }
    }
}


function requireCOE(): void {

    requireAuth();

    if (!isCOE() && !isERPStaff()) {

        header(
            "Location: " .
            getBaseUrl() .
            "/modules/teaching/dashboard.php?error=access_denied"
        );

        exit;
    }
}


function requireSuperAdmin(): void {

    requireAuth();

    if (!isSuperAdmin() && !isERPStaff()) {

        header(
            "Location: " .
            getBaseUrl() .
            "/modules/coe/dashboard.php?error=admin_access_denied"
        );

        exit;
    }
}


/* =========================================================
   BASE URL
========================================================= */

function getBaseUrl(): string {

    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    if (
        strpos(
            $script,
            '/Question-Paper-System-new'
        ) !== false
    ) {
        return '/Question-Paper-System-new';
    }

    return '';
}
