<?php
/**
 * Export Question Bank to JSON Script
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireCOE();
$pdo = getDBConnection();

$bankId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $pdo->prepare("SELECT * FROM question_banks WHERE id = ?");
$stmt->execute([$bankId]);
$bank = $stmt->fetch();

if (!$bank) {
    die("Question Bank not found.");
}

$snapshot = json_decode($bank['questions_json'] ?? '', true);
if (is_array($snapshot) && isset($snapshot['questions'])) {
    $bank['question_bank_json'] = $snapshot;
} else {
    $stmtQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
    $stmtQ->execute([$bankId]);
    $bank['question_bank_json'] = ['questions'=>$stmtQ->fetchAll()];
}
unset($bank['questions_json']);

header('Content-Type: application/json; charset=utf-8');
header("Content-Disposition: attachment; filename=HCC_QuestionBank_{$bank['paper_code']}_{$bankId}.json");

echo json_encode($bank, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
