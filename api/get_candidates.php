<?php
/**
 * API: Get Question Candidates for COE Swap Modal
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
qps_ensure_question_draft_schema($pdo);

try {
    $paperId = (int)($_GET['paper_id'] ?? 0);
    $secIdx = (int)($_GET['section_index'] ?? 0);
    $bankId = (int)($_GET['bank_id'] ?? 0);
    $unitNo = (int)($_GET['unit_no'] ?? 0);
    $marks = (int)($_GET['marks'] ?? 0);

    $paper = null;
    $paperCode = '';
    $paperYear = null;
    $paperSem = null;
    $usedQuestionHashes = [];
    $usedQuestionTexts = [];

    if ($paperId > 0) {
        $st = $pdo->prepare("SELECT * FROM generated_papers WHERE id = ?");
        $st->execute([$paperId]);
        $paper = $st->fetch(PDO::FETCH_ASSOC);
        if ($paper) {
            $paperCode = strtoupper(trim((string)($paper['paper_code'] ?? '')));
            $paperYear = trim((string)($paper['academic_year'] ?? ''));
            $paperSem = trim((string)($paper['semester'] ?? ''));
            $data = json_decode($paper['paper_data_json'] ?? '{}', true);
            if (!$bankId) $bankId = (int)($paper['bank_id'] ?? 0);

            // Record used questions in this paper
            if (!empty($data['sections']) && is_array($data['sections'])) {
                foreach ($data['sections'] as $s) {
                    if (!empty($s['questions']) && is_array($s['questions'])) {
                        foreach ($s['questions'] as $q) {
                            if (empty($q['is_or_divider'])) {
                                if (!empty($q['id'])) $usedQuestionHashes[(int)$q['id']] = true;
                                if (!empty($q['question_text'])) {
                                    $norm = mb_strtolower(trim(strip_tags($q['question_text'])));
                                    $usedQuestionTexts[hash('sha256', $norm)] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (!$bankId) {
        throw new RuntimeException('Question bank ID is required.');
    }

    if ($paperCode === '') {
        $stBp = $pdo->prepare("SELECT paper_code FROM question_banks WHERE id = ?");
        $stBp->execute([$bankId]);
        $paperCode = strtoupper(trim((string)$stBp->fetchColumn()));
    }

    // 1. Fetch from questions relational table
    $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
    $stQ->execute([$bankId]);
    $allBankQuestions = $stQ->fetchAll(PDO::FETCH_ASSOC);

    // 2. If relational table empty, fallback to questions_json in question_banks table
    if (empty($allBankQuestions)) {
        $stB = $pdo->prepare("SELECT questions_json FROM question_banks WHERE id = ?");
        $stB->execute([$bankId]);
        $jsonStr = $stB->fetchColumn();
        if ($jsonStr) {
            $decoded = json_decode($jsonStr, true);
            $allBankQuestions = $decoded['questions'] ?? (is_array($decoded) ? $decoded : []);
        }
    }

    // Ensure every question has an id/q_number and fixed_q_no
    foreach ($allBankQuestions as $idx => &$qItem) {
        if (empty($qItem['id'])) {
            $qItem['id'] = $qItem['q_number'] ?? ($idx + 1);
        }
        if (empty($qItem['q_number'])) {
            $qItem['q_number'] = $idx + 1;
        }
        if (empty($qItem['fixed_q_no'])) {
            $qItem['fixed_q_no'] = $qItem['q_number'] ?? ($idx + 1);
        }
    }
    unset($qItem);

    // Filter candidates & check prior usage history
    $candidates = [];
    foreach ($allBankQuestions as $q) {
        $qid = (int)($q['id'] ?? 0);
        $qText = mb_strtolower(trim(strip_tags($q['question_text'] ?? '')));
        $qHash = hash('sha256', $qText);

        // Skip if already in this paper set
        if ($qid > 0 && isset($usedQuestionHashes[$qid])) continue;
        if (isset($usedQuestionTexts[$qHash])) continue;

        // Unit match (if specified)
        $qUnit = (int)($q['unit_no'] ?? 1);
        if ($unitNo > 0 && $qUnit !== $unitNo) {
            continue;
        }

        // Check if question was used in previous academic years/semesters for this course
        $priorUsage = qps_check_question_prior_usage($pdo, $paperCode, $qid, $q['fixed_q_no'] ?? null, $paperYear, $paperSem);
        if ($priorUsage && !empty($priorUsage['is_used'])) {
            $q['is_prior_used'] = true;
            $q['prior_usage_info'] = $priorUsage;
            $q['prior_usage_msg'] = $priorUsage['message'] ?? ("used in the " . ($priorUsage['academic_year'] ?? '') . " semester (" . ($priorUsage['semester'] ?? '') . ")");
        } else {
            $q['is_prior_used'] = false;
            $q['prior_usage_info'] = null;
            $q['prior_usage_msg'] = '';
        }

        $candidates[] = $q;
    }

    // If unit filter was too strict and returned 0, fallback to all unused questions in the bank
    if (empty($candidates)) {
        foreach ($allBankQuestions as $q) {
            $qid = (int)($q['id'] ?? 0);
            $qText = mb_strtolower(trim(strip_tags($q['question_text'] ?? '')));
            $qHash = hash('sha256', $qText);
            if ($qid > 0 && isset($usedQuestionHashes[$qid])) continue;
            if (isset($usedQuestionTexts[$qHash])) continue;

            $priorUsage = qps_check_question_prior_usage($pdo, $paperCode, $qid, $q['fixed_q_no'] ?? null, $paperYear, $paperSem);
            if ($priorUsage && !empty($priorUsage['is_used'])) {
                $q['is_prior_used'] = true;
                $q['prior_usage_info'] = $priorUsage;
                $q['prior_usage_msg'] = $priorUsage['message'] ?? ("used in the " . ($priorUsage['academic_year'] ?? '') . " semester (" . ($priorUsage['semester'] ?? '') . ")");
            } else {
                $q['is_prior_used'] = false;
                $q['prior_usage_info'] = null;
                $q['prior_usage_msg'] = '';
            }

            $candidates[] = $q;
        }
    }

    echo json_encode([
        'success' => true,
        'count' => count($candidates),
        'candidates' => $candidates
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
