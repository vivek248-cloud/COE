<?php
declare(strict_types=1);

/*
 * DEVELOPMENT ONLY.
 *
 * DELETE THIS FILE BEFORE PRODUCTION.
 */

require_once __DIR__ . '/../config/database.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim((string)($_POST['username'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');

    if ($username === '') {
        $error = 'Username is required.';
    } elseif (strlen($newPassword) < 10) {
        $error = 'Password must be at least 10 characters.';
    } else {

        $pdo = db();

        $check = $pdo->prepare(
            'SELECT id, username, role
             FROM users
             WHERE username = :username
             LIMIT 1'
        );

        $check->execute([
            ':username' => $username
        ]);

        $user = $check->fetch();

        if (!$user) {

            $error = 'User not found.';

        } else {

            /*
             * IMPORTANT:
             * Never store the plain password.
             *
             * password_hash() creates the secure password hash.
             */
            $passwordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $update = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $update->execute([
                ':password_hash' => $passwordHash,
                ':id' => (int)$user['id']
            ]);

            $message =
                'Password updated successfully for ' .
                $user['username'] .
                ' (' .
                $user['role'] .
                ').';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - Development</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            padding: 40px;
        }

        .box {
            max-width: 500px;
            margin: auto;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,.08);
        }

        input,
        button {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin-top: 8px;
            margin-bottom: 18px;
        }

        button {
            background: #222;
            color: #fff;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }

        .success {
            background: #e4f8e9;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .error {
            background: #ffe5e5;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .warning {
            background: #fff3cd;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>

<div class="box">

    <h1>Development Password Reset</h1>

    <div class="warning">
        <strong>Development only.</strong><br>
        Delete this file before production deployment.
    </div>

    <?php if ($message): ?>
        <div class="success">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <label>
            Username
        </label>

        <input
            type="text"
            name="username"
            placeholder="teacher01"
            required
        >

        <label>
            New Password
        </label>

        <input
            type="password"
            name="new_password"
            placeholder="Enter new password"
            minlength="10"
            required
        >

        <button type="submit">
            Update Password
        </button>

    </form>

</div>

</body>
</html>