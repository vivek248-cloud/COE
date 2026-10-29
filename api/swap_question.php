<?php
/**
 * API: Execute Question Swap in Generated Paper
 * Holy Cross College (Autonomous) - Examination Management System
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

if (!isCOE()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = getDBConnection();

try {
    $rawInput = file_get_contents('php://input'); if (empty($rawInput)) { $rawInput = @file_get_contents('php://stdin'); } $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        throw new RuntimeException('Invalid JSON request payload.');
    }

    $paperId = (int)($input['paper_id'] ?? 0);
    $secIdx = (int)($input['section_index'] ?? 0);
    $qIdx = (int)($input['question_index'] ?? 0);
    $newId = $input['new_question_id'] ?? 0;

    if ($paperId <= 0) {
        throw new RuntimeException('Paper ID is required.');
    }

    $st = $pdo->prepare("SELECT * FROM generated_papers WHERE id = ?");
    $st->execute([$paperId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Generated examination paper not found.');
    }

    $data = json_decode($row['paper_data_json'] ?? '{}', true);
    if (empty($data['sections']) || !isset($data['sections'][$secIdx])) {
        throw new RuntimeException('Target section does not exist in paper.');
    }

    $targetSection = &$data['sections'][$secIdx];
    if (empty($targetSection['questions']) || !isset($targetSection['questions'][$qIdx])) {
        throw new RuntimeException('Target question slot does not exist.');
    }

    $oldQ = $targetSection['questions'][$qIdx];
    if (!empty($oldQ['is_or_divider'])) {
        throw new RuntimeException('Cannot swap an (OR) divider row.');
    }

    $bankId = (int)($row['bank_id'] ?? 0);

    // Fetch candidate pool
    $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
    $stQ->execute([$bankId]);
    $bankPool = $stQ->fetchAll(PDO::FETCH_ASSOC);

    if (empty($bankPool)) {
        $stB = $pdo->prepare("SELECT questions_json FROM question_banks WHERE id = ?");
        $stB->execute([$bankId]);
        $jsonStr = $stB->fetchColumn();
        if ($jsonStr) {
            $dec = json_decode($jsonStr, true);
            $bankPool = $dec['questions'] ?? (is_array($dec) ? $dec : []);
        }
    }

    // Find the replacement question by id, q_number, or index
    $newQ = null;
    foreach ($bankPool as $idx => $cand) {
        $candId = (int)($cand['id'] ?? ($cand['q_number'] ?? ($idx + 1)));
        if ($candId === (int)$newId || (string)($cand['q_number'] ?? '') === (string)$newId) {
            $newQ = $cand;
            break;
        }
    }

    if (!$newQ) {
        throw new RuntimeException('Selected replacement question was not found in the question bank.');
    }

    // Preserve formatting, numbering, and marks
    $newQ['display_qno'] = $oldQ['display_qno'] ?? ($oldQ['q_number'] ?? ($qIdx + 1));
    $newQ['marks'] = $oldQ['marks'] ?? ($newQ['marks'] ?? 2);
    
    // Enrich with trace
    $romanMap = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V'];
    $u = (int)($newQ['unit_no'] ?? ($oldQ['unit_no'] ?? 1));
    $newQ['unit_no'] = $u;
    $newQ['unit_roman'] = $romanMap[$u] ?? (string)$u;
    $newQ['subunit_no'] = $newQ['subunit_no'] ?? ($u . '.1');
    $newQ['blueprint_trace'] = "Unit {$newQ['unit_roman']} (Subunit {$newQ['subunit_no']}) • {$newQ['k_level']} • Manual COE Swap";

    // Replace in section
    $targetSection['questions'][$qIdx] = $newQ;
    unset($targetSection);

    // Save back to generated_papers
    $updatedJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $upSt = $pdo->prepare("UPDATE generated_papers SET paper_data_json = ? WHERE id = ?");
    $upSt->execute([$updatedJson, $paperId]);

    qps_audit($pdo, 'PAPER_QUESTION_SWAP', 'GENERATED_PAPER', (string)$paperId, [
        'old_q' => mb_substr(strip_tags($oldQ['question_text'] ?? ''), 0, 50),
        'new_q' => mb_substr(strip_tags($newQ['question_text'] ?? ''), 0, 50),
        'qno' => $newQ['display_qno']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Question successfully swapped and saved!',
        'paper_data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
