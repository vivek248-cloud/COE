<?php
declare(strict_types=1);

/*
 * DEVELOPMENT ONLY.
 * Run once from the browser or CLI after importing schema_phase1.sql.
 * DELETE THIS FILE BEFORE PRODUCTION DEPLOYMENT.
 */

require_once __DIR__ . '/../config/database.php';

$users = [
    [
        'username' => 'teacher01',
        'password' => 'ChangeMe_Teacher_2026!',
        'role' => 'TEACHING_STAFF',
        'full_name' => 'Test Teaching Staff',
    ],
    [
        'username' => 'coe01',
        'password' => 'ChangeMe_COE_2026!',
        'role' => 'COE_STAFF',
        'full_name' => 'Test COE Staff',
    ],
];

$stmt = db()->prepare(
    'INSERT INTO users
     (username, password_hash, role, full_name, is_active)
     VALUES (:username, :password_hash, :role, :full_name, 1)
     ON DUPLICATE KEY UPDATE
       password_hash = VALUES(password_hash),
       role = VALUES(role),
       full_name = VALUES(full_name),
       is_active = 1'
);

foreach ($users as $user) {
    $stmt->execute([
        ':username' => $user['username'],
        ':password_hash' => password_hash($user['password'], PASSWORD_DEFAULT),
        ':role' => $user['role'],
        ':full_name' => $user['full_name'],
    ]);

    echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8')
        . ' created/updated. Password: '
        . htmlspecialchars($user['password'], ENT_QUOTES, 'UTF-8')
        . '<br>';
}

echo '<strong>DELETE database/create_test_users.php before production.</strong>';
