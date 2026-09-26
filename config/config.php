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
define('COE_DEFAULT_USERNAME', 'coe');
define('COE_DEFAULT_PASSWORD', 'coe@123');
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
