<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$username = 'teacher02';
$password = 'Teacher@123';
$fullName = 'Second Teaching Staff';
$role = 'TEACHING_STAFF';

$pdo = db();

$stmt = $pdo->prepare("
    INSERT INTO users
    (
        username,
        password_hash,
        full_name,
        role,
        is_active
    )
    VALUES
    (
        :username,
        :password_hash,
        :full_name,
        :role,
        1
    )
");

$stmt->execute([
    ':username' => $username,
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ':full_name' => $fullName,
    ':role' => $role,
]);

echo '<h2>User created successfully</h2>';
echo '<p>Username: ' . htmlspecialchars($username) . '</p>';
echo '<p>Password: ' . htmlspecialchars($password) . '</p>';