<?php
/**
 * AJAX API for HOD Verification & Approval
 * Submits Question Bank to COE or checks duplicates
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();

try {
    qps_ensure_aux_schema($pdo);
    $rawInput = file_get_contents('php://input'); if (empty($rawInput)) { $rawInput = @file_get_contents('php://stdin'); } $input = json_decode($rawInput, true);
    if (!is_array($input)) throw new RuntimeException('Invalid JSON payload.');

    $bankId = (int)($input['bank_id'] ?? 0);
    $action = trim((string)($input['action'] ?? 'check_duplicates'));

    if (!$bankId) throw new RuntimeException('Bank ID is required.');

    $st = $pdo->prepare("SELECT * FROM question_banks WHERE id = ?");
    $st->execute([$bankId]);
    $bank = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bank) throw new RuntimeException('Question bank not found.');

    // Fetch questions
    $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
    $stQ->execute([$bankId]);
    $questions = $stQ->fetchAll(PDO::FETCH_ASSOC);

    if ($action === 'check_duplicates') {
        $seen = [];
        $duplicates = [];

        foreach ($questions as $q) {
            $textNorm = strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', strip_tags($q['question_text'])));
            $textNorm = trim(preg_replace('/\s+/', ' ', $textNorm));
            if ($textNorm === '') continue;

            if (isset($seen[$textNorm])) {
                $duplicates[] = [
                    'q_number' => $q['q_number'],
                    'section' => $q['section_type'],
                    'k_level' => $q['k_level'],
                    'question_text' => $q['question_text'],
                    'matched_with' => 'Question #' . $seen[$textNorm]['q_number']
                ];
            } else {
                $seen[$textNorm] = $q;
            }
        }

        echo json_encode([
            'success' => true,
            'bank_id' => $bankId,
            'duplicate_count' => count($duplicates),
            'duplicates' => $duplicates,
            'message' => count($duplicates) > 0 ? (count($duplicates) . ' duplicate questions found.') : 'Verification passed: Zero duplicate questions detected.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'submit_to_coe') {
        if (!isHOD() && !isCOE()) {
            throw new RuntimeException('Only the Head of Department (HOD) or COE can verify and submit question banks to COE.');
        }

        $now = date('Y-m-d H:i:s');
        $stUp = $pdo->prepare("UPDATE question_banks SET status = 'Submitted to COE', hod_reviewed_by = ?, hod_reviewed_at = ?, hod_status = 'approved', updated_at = ? WHERE id = ?");
        $stUp->execute([$user['staff_code'], $now, $now, $bankId]);

        qps_audit($pdo, 'HOD_SUBMIT_TO_COE', 'QUESTION_BANK', (string)$bankId, [
            'paper_code' => $bank['paper_code'],
            'reviewed_by' => $user['staff_code'],
            'timestamp' => $now
        ]);

        echo json_encode([
            'success' => true,
            'bank_id' => $bankId,
            'status' => 'Submitted to COE',
            'message' => "Question Bank #{$bankId} ({$bank['paper_code']}) successfully verified and submitted to the COE Office for paper generation!"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unsupported action requested.');

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
