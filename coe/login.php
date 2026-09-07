<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . dashboard_url_for_role(current_role()));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, username, password_hash, role, is_active
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['is_active'] !== 1 ||
            !password_verify($password, $user['password_hash'])) {

            audit_log(
                $user ? (int)$user['id'] : null,
                'LOGIN_FAILED',
                'COE Staff login failed for username: ' . $username
            );

            $error = 'Invalid username or password.';
        } elseif ($user['role'] !== 'COE_STAFF') {
            audit_log(
                (int)$user['id'],
                'LOGIN_ROLE_DENIED',
                'Non-COE account attempted COE login.'
            );

            $error = 'This account is not authorized for the COE portal.';
        } else {
            login_user($user);

            audit_log(
                (int)$user['id'],
                'LOGIN_SUCCESS',
                'COE Staff login successful.'
            );

            header('Location: ' . BASE_URL . '/coe/dashboard.php');
            exit;
        }
    }
}

$title = 'COE Staff Login';
require __DIR__ . '/../includes/layout/header.php';
?>
<div class="card" style="max-width:460px;margin:auto">
    <h1>COE Staff Login</h1>
    <p>Authorized COE Staff access only.</p>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>

        <label for="username">COE User ID</label>
        <input id="username" name="username" type="text"
               maxlength="100" required autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password"
               required autocomplete="current-password">

        <button type="submit">Login</button>
    </form>

    <p>
        Teaching Staff?
        <a href="<?= BASE_URL ?>/teaching/login.php">Open Teaching Login</a>
    </p>
</div>
<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
