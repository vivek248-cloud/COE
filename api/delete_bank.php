<?php
/**
 * API Endpoint for Cascade Deleting Question Banks
 * Holy Cross College (Autonomous) - Examination System
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

if (!isLoggedIn()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in first.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDBConnection();
$user = getCurrentUser();
qps_ensure_recycle_schema($pdo);

try {
    $rawInput = file_get_contents('php://input'); if (empty($rawInput)) { $rawInput = @file_get_contents('php://stdin'); }
    $input = json_decode($rawInput, true);
    $bankId = 0;

    if (is_array($input) && isset($input['bank_id'])) {
        $bankId = (int)$input['bank_id'];
    } elseif (isset($_POST['bank_id'])) {
        $bankId = (int)$_POST['bank_id'];
    } elseif (isset($_GET['bank_id'])) {
        $bankId = (int)$_GET['bank_id'];
    }

    if ($bankId <= 0) {
        throw new RuntimeException('Invalid Question Bank ID provided.');
    }

    // Check bank existence
    $st = $pdo->prepare("SELECT id, paper_code, course_title, staff_code, dept_code, is_deleted FROM question_banks WHERE id = ?");
    $st->execute([$bankId]);
    $bank = $st->fetch(PDO::FETCH_ASSOC);

    if (!$bank) {
        throw new RuntimeException("Question Bank #{$bankId} does not exist or was already deleted.");
    }

    // Permission check
    $isOwner = ($user['staff_code'] ?? '') === ($bank['staff_code'] ?? '');
    $isDeptHod = isHOD() && (strtoupper($user['dept_code'] ?? '') === strtoupper($bank['dept_code'] ?? ''));
    if (!isCOE() && !isSuperAdmin() && !$isOwner && !$isDeptHod) {
        throw new RuntimeException('Permission denied: You do not have permission to delete this question bank.');
    }

    if ((int)($bank['is_deleted'] ?? 0) === 1) {
        throw new RuntimeException('This question bank is already in the recycle bin.');
    }
    // Soft delete: preserve bank, questions, related papers and usage history.
    $now = date('Y-m-d H:i:s');
    $stDel = $pdo->prepare("UPDATE question_banks SET is_deleted = 1, deleted_at = ?, deleted_by = ?, expires_at = ? WHERE id = ?");
    $stDel->execute([$now, $user['staff_code'] ?? 'COE_OFFICE', date('Y-m-d H:i:s', time() + 5 * 86400), $bankId]);

    // Audit log
    qps_audit($pdo, 'QUESTION_BANK_ITEM_DELETED', 'QUESTION_BANK', (string)$bankId, [
        'paper_code' => $bank['paper_code'],
        'course_title' => $bank['course_title'],
        'deleted_by' => $user['staff_code'] ?? 'ADMIN'
    ]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'bank_id' => $bankId,
        'message' => "Question Bank #{$bankId} ({$bank['paper_code']}) moved to the recycle bin. It can be restored within 5 days."
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
