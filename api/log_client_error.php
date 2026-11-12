<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/auth.php';
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$csrf = (string)($_SESSION['qps_error_log_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['success'=>false]); exit; }
$message = trim((string)($input['message'] ?? 'Client-side error'));
qps_write_error_log('CLIENT', $message, (string)($input['source'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), (int)($input['line'] ?? 0), (string)($input['stack'] ?? ''));
echo json_encode(['success'=>true]);
