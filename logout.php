<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

logout_user(true);

header('Location: ' . BASE_URL . '/teaching/login.php');
exit;
