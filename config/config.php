<?php
/**
 * System Configuration File
 * Holy Cross College (Autonomous), Tiruchirappalli
 */

// Set Indian Standard Time (IST / Asia/Kolkata UTC+5:30)
date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['qps_error_log_csrf'])) { $_SESSION['qps_error_log_csrf'] = bin2hex(random_bytes(32)); }

// Capture PHP warnings and fatal errors for the COE System Errors monitor.
require_once __DIR__ . '/../includes/error_logger.php';

// College and System Constants
define('APP_NAME', 'Holy Cross College Exam Paper & OBE Blueprint System');
define('COLLEGE_NAME', 'HOLY CROSS COLLEGE (AUTONOMOUS)');
define('COLLEGE_AFFILIATION', 'Affiliated to Bharathidasan University | Nationally Re-accredited with \'A++\' Grade by NAAC');
define('COLLEGE_LOCATION', 'TIRUCHIRAPPALLI - 620 002, TAMIL NADU');
define('DEFAULT_REGULATION', '2024 (OBE)');
define('DEFAULT_ACADEMIC_YEAR', '2026-2027');

// Database Configuration for College Server
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'hccweb');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Paths
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_DIR', BASE_PATH . '/assets/uploads');
define('QPS_LOGIN_MAX_ATTEMPTS', 5);
define('QPS_LOGIN_WINDOW_MINUTES', 15);
define('QPS_LOGIN_LOCK_MINUTES', 15);
define('MAX_UPLOAD_BYTES', 52428800); // 50 MB per source upload
define('MAX_UPLOAD_MB', 50);
// Question banks are question pools; there is intentionally no fixed question-count cap.
define('MAX_QUESTION_COUNT', 0);
define('PRIVATE_STORAGE_DIR', BASE_PATH . '/storage/private');

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0777, true);
}
if (!is_dir(PRIVATE_STORAGE_DIR)) {
    @mkdir(PRIVATE_STORAGE_DIR, 0777, true);
}

// ============================================================================
// STATIC ACCOUNT CONFIGURATION (Phase 1)
// HOD, Dean, COE static accounts - password hashes stored in qps_settings table
// Plaintext passwords are NEVER in code. Compare using password_verify() against bcrypt.
// Default passwords (change on first login): hod@123, dean@123, coe@123
// ============================================================================
define('STATIC_HOD_USERNAME', 'hod');
define('STATIC_DEAN_USERNAME', 'dean');
define('STATIC_COE_USERNAME', 'coe');

// Workflow status constants
define('QPS_ITEM_STATUS_DRAFT', 'draft');
define('QPS_ITEM_STATUS_SUBMITTED_TO_HOD', 'submitted_to_hod');
define('QPS_ITEM_STATUS_HOD_CORRECTIONS', 'hod_corrections');
define('QPS_ITEM_STATUS_SENT_TO_DEAN', 'sent_to_dean');
define('QPS_ITEM_STATUS_DEAN_CORRECTIONS', 'dean_corrections');
define('QPS_ITEM_STATUS_SENT_TO_COE', 'sent_to_coe');
define('QPS_ITEM_STATUS_APPROVED', 'approved');

// Item types
define('QPS_ITEM_QUESTION_BANK', 'question_bank');
define('QPS_ITEM_MASTER_BLUEPRINT', 'master_blueprint');
define('QPS_ITEM_FINAL_BLUEPRINT', 'final_blueprint');

// Correction target types
define('QPS_CORRECTION_TARGET_QUESTION', 'question');
define('QPS_CORRECTION_TARGET_BLUEPRINT_CELL', 'blueprint_cell');

// Correction statuses
define('QPS_CORRECTION_OPEN', 'open');
define('QPS_CORRECTION_RESOLVED', 'resolved');
define('QPS_CORRECTION_WITHDRAWN', 'withdrawn');

// Roles
define('QPS_ROLE_TEACHING', 'teaching');
define('QPS_ROLE_HOD', 'hod');
define('QPS_ROLE_DEAN', 'dean');
define('QPS_ROLE_COE', 'coe');
define('QPS_ROLE_ERP_ADMIN', 'erp_admin');
define('QPS_ROLE_SUPER_ADMIN', 'super_admin');
