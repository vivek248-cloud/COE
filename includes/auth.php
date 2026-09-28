<?php
/**
 * Authentication and Session Management
 * Holy Cross College (Autonomous)
 * Multi-Role: Teaching Faculty, HOD, COE, ERP Department / Super Admin
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/system.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['staff_code']);
}

function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function getUserRole(): string {
    return $_SESSION['user']['role'] ?? 'GUEST';
}

/**
 * Check if the current user belongs to the ERP department or has administrative privileges.
 * ERP staff has full grand access across all pages (COE, Admin, Master Repositories, Teaching).
 */
function isERPStaff(): bool {
    if (!isLoggedIn()) return false;
    $role = strtoupper(getUserRole());
    if (in_array($role, ['SUPER_ADMIN', 'ADMIN', 'ERP_STAFF', 'ERP_ADMIN', 'SYSTEM_ADMIN'], true)) return true;
    if (!empty($_SESSION['user']['is_super_admin']) || !empty($_SESSION['user']['is_erp_staff'])) return true;
    
    $user = getCurrentUser();
    $dept = strtoupper((string)($user['department'] ?? ($user['dept_name'] ?? ($user['dept_code'] ?? ''))));
    $staffCode = strtoupper((string)($user['staff_code'] ?? ''));
    $desig = strtoupper((string)($user['designation'] ?? ''));
    
    // Check if staff department or code in pr_x_xxxx_staf_prof_mast is ERP, Admin, Computer, System, or Software
    if (strpos($dept, 'ERP') !== false || strpos($dept, 'RP') !== false || strpos($dept, 'ADMIN') !== false || strpos($dept, 'SYSTEM') !== false || strpos($dept, 'COMPUTER') !== false || strpos($dept, 'SOFTWARE') !== false || strpos($staffCode, 'ADMIN') !== false || strpos($staffCode, 'ERP') !== false || strpos($desig, 'ERP') !== false || strpos($desig, 'DEVELOPER') !== false || strpos($desig, 'ADMIN') !== false || strpos($desig, 'WEB ADMIN') !== false || strpos($desig, 'DEV') !== false) {
        return true;
    }
    
    return false;
}

function isSuperAdmin(): bool {
    if (!isLoggedIn()) return false;
    $role = strtoupper(getUserRole());
    if (in_array($role, ['SUPER_ADMIN', 'ADMIN', 'ERP_ADMIN'], true)) return true;
    if (!empty($_SESSION['user']['is_super_admin'])) return true;
    return isERPStaff();
}

/**
 * COE access check:
 * Grants access to COE Office, Super Admin, and ERP Department
 */
function isCOE(): bool {
    if (!isLoggedIn()) return false;
    $role = strtoupper(getUserRole());
    if (in_array($role, ['COE_ADMIN', 'COE_STAFF', 'SUPER_ADMIN', 'ADMIN', 'COE', 'ERP_ADMIN', 'ERP_STAFF'], true)) return true;
    return isERPStaff();
}

function isCOEStaff(): bool {
    if (!isLoggedIn()) return false;
    $role = strtoupper(getUserRole());
    return in_array($role, ['COE_STAFF', 'COE_ADMIN', 'COE', 'ERP_ADMIN', 'SUPER_ADMIN'], true) || isERPStaff();
}

function isHOD(): bool {
    if (!isLoggedIn()) return false;
    $role = strtoupper(getUserRole());
    if (in_array($role, ['HOD', 'HEAD_OF_DEPARTMENT', 'COE_ADMIN', 'SUPER_ADMIN', 'ADMIN', 'ERP_ADMIN'], true)) return true;
    if (!empty($_SESSION['user']['is_hod'])) return true;
    return isERPStaff();
}

/**
 * College ERP Rule:
 * Super Admin and ERP staff have full grand CRUD & edit access (NOT read-only)
 * across all ERP Master tables:
 * - Staff Master (pr_x_xxxx_staf_prof_mast)
 * - Departments (departments)
 * - Courses (courses)
 * - Faculty Timetable Allocations (timetablefaculty)
 * - Blueprints (blueprints)
 * - Question Banks (question_banks)
 */
function canEditERPMasters(): bool {
    if (!isLoggedIn()) return false;
    return isSuperAdmin() || isERPStaff() || isCOE();
}

function requireAuth(): void {
    qps_ensure_aux_schema(getDBConnection());
    if (!isLoggedIn()) {
        header("Location: " . getBaseUrl() . "/modules/auth/login.php");
        exit;
    }
}

function requireCOE(): void {
    requireAuth();
    if (!isCOE() && !isERPStaff()) {
        header("Location: " . getBaseUrl() . "/modules/teaching/dashboard.php?error=access_denied");
        exit;
    }
}

function requireSuperAdmin(): void {
    requireAuth();
    if (!isSuperAdmin() && !isERPStaff()) {
        header("Location: " . getBaseUrl() . "/modules/admin/index.php?error=superadmin_required");
        exit;
    }
}

function getBaseUrl(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($script, '/Question-Paper-System-new') !== false) {
        return '/Question-Paper-System-new';
    }
    return '';
}
