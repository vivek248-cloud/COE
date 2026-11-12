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
$pdo->exec("CREATE TABLE IF NOT EXISTS qps_review_remarks_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 draft_question_id BIGINT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NULL,
 paper_code VARCHAR(100) NULL,
 actor_staff_code VARCHAR(100) NOT NULL,
 actor_role VARCHAR(50) NULL,
 action_code VARCHAR(40) NOT NULL,
 remarks TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_qps_remark_question (draft_question_id, created_at),
 KEY idx_qps_remark_paper (paper_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try {
    $sessionCsrf = (string)($_SESSION['qps_review_csrf'] ?? '');
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) $rawInput = @file_get_contents('php://stdin');
    $input = json_decode((string)$rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }
    if ($sessionCsrf === '' || !hash_equals($sessionCsrf, (string)($input['csrf'] ?? ''))) {
        throw new RuntimeException('Security token expired. Refresh the page and try again.');
    }

    $action = trim((string)($input['action'] ?? 'mark_correction'));
    $questionId = (int)($input['question_id'] ?? 0);
    $remarks = trim((string)($input['remarks'] ?? ''));

    if ($questionId <= 0) {
        throw new RuntimeException('Invalid Question ID provided.');
    }
    $stQ = $pdo->prepare('SELECT id, draft_id, paper_code FROM qps_question_bank_draft_questions WHERE id = ? LIMIT 1');
    $stQ->execute([$questionId]);
    $questionRow = $stQ->fetch(PDO::FETCH_ASSOC);
    if (!$questionRow) throw new RuntimeException('Question not found. Refresh the page and try again.');

    if ($action === 'mark_correction') {
        if (!isHOD() && !isCOE()) {
            throw new RuntimeException('Only HOD or COE can request question corrections.');
        }

        if ($remarks === '') {
            $remarks = 'Please revise this question.';
        }

        $st = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET correction_status = 'NEEDS_CORRECTION', remarks = ?, updated_at = NOW() WHERE id = ?");
        $st->execute([$remarks, $questionId]);
        $history = $pdo->prepare("INSERT INTO qps_review_remarks_history (draft_question_id,draft_id,paper_code,actor_staff_code,actor_role,action_code,remarks) VALUES (?,?,?,?,?,'FLAGGED',?)");
        $history->execute([$questionId, $questionRow['draft_id'], $questionRow['paper_code'], $user['staff_code'] ?? 'HOD', $user['role'] ?? null, $remarks]);

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
        if (!isHOD() && !isCOE() && empty($user['is_super_admin'])) throw new RuntimeException('Only HOD or COE can resolve a correction.');
        $st = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET correction_status = 'VERIFIED', updated_at = NOW() WHERE id = ?");
        $st->execute([$questionId]);
        $history = $pdo->prepare("INSERT INTO qps_review_remarks_history (draft_question_id,draft_id,paper_code,actor_staff_code,actor_role,action_code,remarks) VALUES (?,?,?,?,?,'RESOLVED',?)");
        $history->execute([$questionId, $questionRow['draft_id'], $questionRow['paper_code'], $user['staff_code'] ?? 'STAFF', $user['role'] ?? null, $remarks]);

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
