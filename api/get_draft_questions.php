<?php
/**
 * AJAX API: Get Draft Questions & Live Blueprint Matrix
 * Holy Cross College (Autonomous) - Examination Management System
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';
require_once __DIR__ . '/../includes/question_language_converter.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in first.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDBConnection();
$user = getCurrentUser();
qps_ensure_question_draft_schema($pdo);

try {
    $paperCode = strtoupper(trim((string)($_GET['paper_code'] ?? ($_GET['course'] ?? ($_GET['course_code'] ?? '')))));
    if ($paperCode === '') {
        throw new RuntimeException('Course / Paper Code is required.');
    }

    $staffCode = $user['staff_code'] ?? 'STAFF';
    $isStaffRole = (!isHOD() && !isCOE());

    // Fetch Draft Container
    if ($isStaffRole) {
        $stD = $pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE UPPER(paper_code) = UPPER(?) AND staff_code = ? ORDER BY id DESC LIMIT 1");
        $stD->execute([$paperCode, $staffCode]);
    } else {
        $stD = $pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE UPPER(paper_code) = UPPER(?) ORDER BY id DESC LIMIT 1");
        $stD->execute([$paperCode]);
    }
    $draft = $stD->fetch(PDO::FETCH_ASSOC);

    $draftId = $draft ? (int)$draft['id'] : 0;
    $questions = [];

    if ($draftId > 0) {
        $stQ = $pdo->prepare("SELECT * FROM qps_question_bank_draft_questions WHERE draft_id = ? ORDER BY unit_no ASC, sub_unit ASC, marks ASC, id ASC");
        $stQ->execute([$draftId]);
        $questions = $stQ->fetchAll(PDO::FETCH_ASSOC);
    $targetLanguage = strtoupper(trim((string)($_GET['language'] ?? '')));
    if (in_array($targetLanguage, ['EN','TL','HI','FR'], true)) {
        foreach ($questions as &$questionRow) {
            $converted = qps_convert_question_text((string)$questionRow['question_text'], strtoupper((string)($questionRow['language'] ?? 'EN')), $targetLanguage);
            $questionRow['display_question_text'] = $converted['text'];
            $questionRow['translation'] = $converted;
        }
        unset($questionRow);
    }

    }

    // Build Live Blueprint Matrix ONLY for units/subunits entered by staff
    $matrix = [];
    if (!empty($questions)) {
        foreach ($questions as $qItem) {
            $uNo = (int)($qItem['unit_no'] ?? 1);
            $sUnit = trim((string)($qItem['sub_unit'] ?? "$uNo.1"));
            if ($sUnit === '') $sUnit = "$uNo.1";
            $suKey = $sUnit;
            if (!isset($matrix[$suKey])) {
                $matrix[$suKey] = [
                    'unit' => $uNo,
                    'sub_unit' => $sUnit,
                    'mcq_k1' => 0, 'mcq_k2' => 0,
                    'vsa_k1' => 0, 'vsa_k2' => 0,
                    'sec_b_k3' => 0, 'sec_b_k4' => 0,
                    'sec_c_k5' => 0, 'sec_c_k6' => 0,
                    'sec_d_k6' => 0,
                    'total_questions' => 0,
                    'total_marks' => 0
                ];
            }
        }
        uksort($matrix, function($a, $b) {
            return version_compare((string)$a, (string)$b);
        });
    }

    $sectionCounts = [
        'A_MCQ' => 0,
        'A_MATCH' => 0,
        'A_AR' => 0,
        'A_VSA' => 0,
        'B' => 0,
        'C' => 0,
        'D' => 0,
        'TOTAL_QUESTIONS' => count($questions),
        'TOTAL_MARKS' => 0,
        'CORRECTIONS_COUNT' => 0
    ];

    foreach ($questions as &$q) {
        $uNo = (int)($q['unit_no'] ?? 1);
        $sUnit = (string)($q['sub_unit'] ?? "$uNo.1");
        $m = (int)($q['marks'] ?? 1);
        $k = strtoupper((string)($q['k_level'] ?? 'K1'));
        $type = strtoupper((string)($q['question_type'] ?? 'MCQ'));
        $sec = strtoupper((string)($q['section_type'] ?? 'SECTION-A'));
        $cStatus = (string)($q['correction_status'] ?? 'CLEAN');

        if ($cStatus === 'NEEDS_CORRECTION') {
            $sectionCounts['CORRECTIONS_COUNT']++;
        }

        $sectionCounts['TOTAL_MARKS'] += $m;

        // Parse options_json
        if (!empty($q['options_json'])) {
            $q['options'] = json_decode($q['options_json'], true);
        }

        // Section counts
        if ($sec === 'SECTION-A') {
            if ($type === 'MATCH') $sectionCounts['A_MATCH']++;
            elseif ($type === 'ASSERTION_REASON' || $type === 'AR') $sectionCounts['A_AR']++;
            elseif ($type === 'VSA' || $type === 'SHORT') $sectionCounts['A_VSA']++;
            else $sectionCounts['A_MCQ']++;
        } elseif ($sec === 'SECTION-B') {
            $sectionCounts['B']++;
        } elseif ($sec === 'SECTION-C') {
            $sectionCounts['C']++;
        } elseif ($sec === 'SECTION-D') {
            $sectionCounts['D']++;
        }

        // Populate Matrix
        if (!isset($matrix[$sUnit])) {
            $matrix[$sUnit] = [
                'unit' => $uNo,
                'sub_unit' => $sUnit,
                'mcq_k1' => 0, 'mcq_k2' => 0,
                'vsa_k1' => 0, 'vsa_k2' => 0,
                'sec_b_k3' => 0, 'sec_b_k4' => 0,
                'sec_c_k5' => 0, 'sec_c_k6' => 0,
                'sec_d_k6' => 0,
                'total_questions' => 0,
                'total_marks' => 0
            ];
        }

        $matrix[$sUnit]['total_questions']++;
        $matrix[$sUnit]['total_marks'] += $m;

        if ($sec === 'SECTION-A') {
            if ($type === 'VSA' || $type === 'SHORT') {
                if ($k === 'K2') $matrix[$sUnit]['vsa_k2']++;
                else $matrix[$sUnit]['vsa_k1']++;
            } else {
                if ($k === 'K2') $matrix[$sUnit]['mcq_k2']++;
                else $matrix[$sUnit]['mcq_k1']++;
            }
        } elseif ($sec === 'SECTION-B') {
            if ($k === 'K4' || $k === 'K5' || $k === 'K6') $matrix[$sUnit]['sec_b_k4']++;
            else $matrix[$sUnit]['sec_b_k3']++;
        } elseif ($sec === 'SECTION-C') {
            if ($k === 'K6') $matrix[$sUnit]['sec_c_k6']++;
            else $matrix[$sUnit]['sec_c_k5']++;
        } elseif ($sec === 'SECTION-D') {
            $matrix[$sUnit]['sec_d_k6']++;
        }
    }
    unset($q);

    echo json_encode([
        'success' => true,
        'draft' => $draft,
        'questions' => $questions,
        'section_counts' => $sectionCounts,
        'blueprint_matrix' => array_values($matrix)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
