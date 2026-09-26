<?php
/**
 * Main Application Router & Entry Point
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    if (isCOE()) {
        header("Location: " . getBaseUrl() . "/modules/coe/dashboard.php");
    } else {
        header("Location: " . getBaseUrl() . "/modules/teaching/dashboard.php");
    }
} else {
    header("Location: " . getBaseUrl() . "/modules/auth/login.php");
}
exit;
