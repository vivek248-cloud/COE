<?php
/**
 * Logout Handler
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

session_unset();
session_destroy();

header("Location: " . getBaseUrl() . "/modules/auth/login.php");
exit;
