<?php
/**
 * AJAX API: HOD / COE Correction Remarks Management
 * Holy Cross College (Autonomous) - Examination Management System
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in first.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDBConnection();
$user = getCurrentUser();
qps_ensure_question_draft_schema($pdo);

try {
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) $rawInput = @file_get_contents('php://stdin');
    $input = json_decode((string)$rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = trim((string)($input['action'] ?? 'mark_correction'));
    $questionId = (int)($input['question_id'] ?? 0);
    $remarks = trim((string)($input['remarks'] ?? ''));

    if ($questionId <= 0) {
        throw new RuntimeException('Invalid Question ID provided.');
    }

    if ($action === 'mark_correction') {
        if (!isHOD() && !isCOE()) {
            throw new RuntimeException('Only HOD or COE can request question corrections.');
        }

        if ($remarks === '') {
            $remarks = 'Please revise this question.';
        }

        $st = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET correction_status = 'NEEDS_CORRECTION', remarks = ?, updated_at = NOW() WHERE id = ?");
        $st->execute([$remarks, $questionId]);

        qps_audit($pdo, 'QUESTION_CORRECTION_FLAGGED', 'DRAFT_QUESTION', (string)$questionId, [
            'remarks' => $remarks,
            'flagged_by' => $user['staff_code'] ?? 'HOD'
        ]);

        echo json_encode([
            'success' => true,
            'question_id' => $questionId,
            'status' => 'NEEDS_CORRECTION',
            'remarks' => $remarks,
            'message' => 'Correction remark added successfully.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'resolve_correction') {
        $st = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET correction_status = 'VERIFIED', updated_at = NOW() WHERE id = ?");
        $st->execute([$questionId]);

        qps_audit($pdo, 'QUESTION_CORRECTION_RESOLVED', 'DRAFT_QUESTION', (string)$questionId, [
            'verified_by' => $user['staff_code'] ?? 'STAFF'
        ]);

        echo json_encode([
            'success' => true,
            'question_id' => $questionId,
            'status' => 'VERIFIED',
            'message' => 'Question marked as verified.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unsupported action requested.');

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
