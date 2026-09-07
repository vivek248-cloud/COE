<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    }

    if (isset($_SESSION['last_activity']) &&
        (time() - (int)$_SESSION['last_activity']) > SESSION_TIMEOUT) {
        logout_user(false);
    }

    $_SESSION['last_activity'] = time();
}

function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function logout_user(bool $writeAudit = true): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if ($writeAudit && !empty($_SESSION['user_id'])) {
        audit_log(
            (int)$_SESSION['user_id'],
            'LOGOUT',
            'User logged out.'
        );
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'],
            $params['domain'] ?? '', (bool)$params['secure'],
            (bool)$params['httponly']);
    }

    session_destroy();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

function current_user_id(): ?int
{
    return is_logged_in() ? (int)$_SESSION['user_id'] : null;
}

function current_role(): ?string
{
    return is_logged_in() ? (string)$_SESSION['role'] : null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function require_role(string $role): void
{
    require_login();

    if (current_role() !== $role) {
        http_response_code(403);
        exit('403 Forbidden');
    }
}

function dashboard_url_for_role(string $role): string
{
    return match ($role) {
        'TEACHING_STAFF' => BASE_URL . '/teaching/dashboard.php',
        'COE_STAFF' => BASE_URL . '/coe/dashboard.php',
        default => BASE_URL . '/index.php',
    };
}
