<?php
declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/config.php';


/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'httponly' => true,
        'secure'   => false, // localhost HTTP
        'samesite' => 'Lax',
    ]);

    session_start();
}


/*
|--------------------------------------------------------------------------
| Core Includes
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/csrf.php';

require_once __DIR__ . '/auth.php';

require_once __DIR__ . '/audit.php';


/*
|--------------------------------------------------------------------------
| HTML Escape Helper
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {

    function e(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}