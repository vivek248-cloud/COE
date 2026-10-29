<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$draftId = (int)($_GET['draft_id'] ?? 0);
$questionId = (int)($_GET['question_id'] ?? 0);
if ($draftId < 1 || $questionId < 1) {
    http_response_code(400);
    exit;
}

$pdo = getDBConnection();
qps_ensure_question_draft_schema($pdo);
$st = $pdo->prepare('SELECT d.staff_code,d.dept_code,q.image_path FROM qps_question_bank_drafts d JOIN qps_question_bank_draft_questions q ON q.draft_id=d.id WHERE d.id=? AND q.id=?');
$st->execute([$draftId, $questionId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit;
}

$user = getCurrentUser() ?: [];
$staff = trim((string)($user['staff_code'] ?? ''));
$allowed = strcasecmp($staff, (string)$row['staff_code']) === 0;
if (!$allowed && isCOE()) {
    $allowed = true;
} elseif (!$allowed && isHOD()) {
    $dept = trim((string)($user['dept_code'] ?? ''));
    $allowed = isERPStaff() || $dept === '' || strcasecmp($dept, (string)$row['dept_code']) === 0;
}
if (!$allowed) {
    http_response_code(403);
    exit;
}

$relative = (string)($row['image_path'] ?? '');
$prefix = 'storage/private/question-diagrams/' . $draftId . '/';
if ($relative === '' || strpos($relative, $prefix) !== 0) {
    http_response_code(404);
    exit;
}
$base = realpath(PRIVATE_STORAGE_DIR . '/question-diagrams/' . $draftId);
$file = realpath(BASE_PATH . '/' . $relative);
if (!$base || !$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
    http_response_code(415);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($file);
