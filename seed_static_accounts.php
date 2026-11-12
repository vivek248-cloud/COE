<?php
/**
 * Static Account Password Seeding Script
 * Holy Cross College (Autonomous) - COE Question Paper System
 *
 * Generates bcrypt hash for static COE account
 * and stores it in qps_settings table.
 *
 * HOD/Dean use staff master table (pr_x_xxxx_staf_prof_mast) with:
 * - hod_status = 'Y' for HOD portal access
 * - dean_status = 1 for Dean portal access
 *
 * COE static account:
 * - Username: coe
 * - Password: coe@123 (bcrypt hashed in qps_settings)
 *
 * Run once during setup: php seed_static_accounts.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

echo "=== QPS Static Account Password Seeding ===\n\n";

$pdo = getDBConnection();

// Ensure qps_settings table exists (matches migration schema: setting_key, setting_value, updated_by)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `qps_settings` (
            `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `setting_value` TEXT NULL,
            `updated_by` VARCHAR(100) NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ qps_settings table ready\n";
} catch (Throwable $e) {
    echo "✗ Error creating qps_settings table: " . $e->getMessage() . "\n";
    exit(1);
}

// Helper to upsert setting
function upsertSetting(PDO $pdo, string $key, string $value): bool {
    try {
        $st = $pdo->prepare("
            INSERT INTO qps_settings (setting_key, setting_value, updated_by)
            VALUES (?, ?, 'SEED_SCRIPT')
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by)
        ");
        return $st->execute([$key, $value]);
    } catch (Throwable $e) {
        error_log("[seed_static] upsert $key: " . $e->getMessage());
        return false;
    }
}

// COE Static Account - only one that uses qps_settings for password
$defaultPasswords = [
    'static_coe_password_hash' => 'coe@123',
];

echo "Generating bcrypt hash for COE static account (cost factor: " . PASSWORD_BCRYPT_DEFAULT_COST . "):\n\n";

$allSuccess = true;

foreach ($defaultPasswords as $settingKey => $plainPassword) {
    // Generate bcrypt hash
    $hash = password_hash($plainPassword, PASSWORD_BCRYPT);

    if ($hash === false) {
        echo "✗ Failed to hash password for $settingKey\n";
        $allSuccess = false;
        continue;
    }

    // Verify the hash works
    $verify = password_verify($plainPassword, $hash);

    if (!$verify) {
        echo "✗ Hash verification failed for $settingKey\n";
        $allSuccess = false;
        continue;
    }

    if (upsertSetting($pdo, $settingKey, $hash)) {
        echo "✓ $settingKey: $hash\n";
    } else {
        echo "✗ Failed to store $settingKey\n";
        $allSuccess = false;
    }
}

// Seed other important settings
$otherSettings = [
    'coe_repeat_year_threshold' => '1',  // N years for repeat detection
    'default_academic_year' => DEFAULT_ACADEMIC_YEAR,
    'docx_header_text' => 'HOLY CROSS COLLEGE (AUTONOMOUS)',
    'docx_college_name' => 'Holy Cross College (Autonomous)',
    'docx_affiliation' => 'Affiliated to Bharathidasan University | NAAC A++',
    'docx_location' => 'Tiruchirappalli - 620 002, Tamil Nadu',
];

echo "\nSeeding additional settings:\n";

foreach ($otherSettings as $settingKey => $value) {
    if (upsertSetting($pdo, $settingKey, $value)) {
        echo "✓ $settingKey: $value\n";
    } else {
        echo "✗ Failed to store $settingKey\n";
        $allSuccess = false;
    }
}

echo "\n";
if ($allSuccess) {
    echo "=== Seeding completed successfully ===\n";
    echo "\nStatic COE account credentials:\n";
    echo "  Username: coe\n";
    echo "  Password: coe@123 (bcrypt hashed in qps_settings)\n";
    echo "\nHOD/Dean login: Uses staff master table (pr_x_xxxx_staf_prof_mast)\n";
    echo "  HOD:  hod_status = 'Y'\n";
    echo "  Dean: dean_status = 1\n";
    echo "\nPassword hash stored in qps_settings table.\n";
    echo "Login uses password_verify() - plaintext passwords never stored in code.\n";
} else {
    echo "=== Seeding completed with errors ===\n";
    exit(1);
}