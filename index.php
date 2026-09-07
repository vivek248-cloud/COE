<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . dashboard_url_for_role(current_role()));
    exit;
}

header('Location: ' . BASE_URL . '/teaching/login.php');
exit;
