<?php
/**
 * Legacy route retained for old bookmarks only.
 * Manual file-based question-bank upload is retired; staff use the structured form.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
if (!isLoggedIn()) {
    header('Location: ' . getBaseUrl() . '/modules/auth/login.php');
    exit;
}
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = getBaseUrl() . '/modules/teaching/question-entry.php';
if ($query !== '') $target .= '?' . $query;
header('Location: ' . $target, true, 302);
exit;
