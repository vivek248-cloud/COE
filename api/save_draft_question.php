<?php
/**
 * AJAX API: Save, Update, Delete & Manage Draft Questions & Auto-sync Blueprint
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

    $action = trim((string)($input['action'] ?? 'save_question'));
    $paperCode = strtoupper(trim((string)($input['paper_code'] ?? ($input['course_code'] ?? ($input['course'] ?? '')))));
    $semester = trim((string)($input['semester'] ?? 'Semester 1'));
    $academicYear = trim((string)($input['academic_year'] ?? (defined('DEFAULT_ACADEMIC_YEAR') ? DEFAULT_ACADEMIC_YEAR : '2026-2027')));
    $staffCode = $user['staff_code'] ?? 'STAFF';

    // ----------------------------------------------------
    // 1. SAVE / UPDATE SINGLE DRAFT QUESTION
    // ----------------------------------------------------
    if ($action === 'save_question') {
        if ($paperCode === '') {
            throw new RuntimeException('Course / Paper Code is required.');
        }

        $questionId = (int)($input['question_id'] ?? 0);
        $unitNo = max(1, min(5, (int)($input['unit_no'] ?? 1)));
        $subUnit = trim((string)($input['sub_unit'] ?? "$unitNo.1"));
        $marks = (int)($input['marks'] ?? 1);
        if (!in_array($marks, [1, 5, 10], true)) {
            $marks = 1; // Strict 1M, 5M, 10M
        }

        // Section & Type Automation:
        // Marks == 1 -> Section A (MCQ, MATCH, AR, VSA)
        // Marks == 5 -> Section B (PARAGRAPH Either/Or)
        // Marks == 10 -> Section C (ESSAY) or Section D (COMPULSORY)
        $sectionType = 'SECTION-A';
        $questionType = trim((string)($input['question_type'] ?? 'MCQ'));
        if ($marks === 1) {
            $sectionType = 'SECTION-A';
            if (!in_array($questionType, ['MCQ', 'MATCH', 'ASSERTION_REASON', 'VSA'], true)) {
                $questionType = 'MCQ';
            }
        } elseif ($marks === 5) {
            $sectionType = 'SECTION-B';
            $questionType = 'PARAGRAPH';
        } elseif ($marks === 10) {
            $isComp = !empty($input['is_compulsory']) || trim((string)($input['section_type'] ?? '')) === 'SECTION-D';
            $sectionType = $isComp ? 'SECTION-D' : 'SECTION-C';
            $questionType = 'ESSAY';
        }

        $kLevel = strtoupper(trim((string)($input['k_level'] ?? 'K1')));
        if (!in_array($kLevel, ['K1','K2','K3','K4','K5','K6'], true)) $kLevel = 'K1';
        $coLevel = strtoupper(trim((string)($input['co_level'] ?? 'CO1')));
        $questionText = trim((string)($input['question_text'] ?? ''));
        if ($questionText === '') {
            throw new RuntimeException('Question text cannot be empty.');
        }

        $optionsJson = null;
        if (!empty($input['options']) && is_array($input['options'])) {
            $optionsJson = json_encode($input['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (!empty($input['options_json'])) {
            $optionsJson = (string)$input['options_json'];
        }

        $answerKey = trim((string)($input['answer_key'] ?? ''));
        $matchColA = !empty($input['match_column_a']) ? (is_array($input['match_column_a']) ? json_encode($input['match_column_a'], JSON_UNESCAPED_UNICODE) : (string)$input['match_column_a']) : null;
        $matchColB = !empty($input['match_column_b']) ? (is_array($input['match_column_b']) ? json_encode($input['match_column_b'], JSON_UNESCAPED_UNICODE) : (string)$input['match_column_b']) : null;
        $assertionText = trim((string)($input['assertion_text'] ?? ''));
        $reasonText = trim((string)($input['reason_text'] ?? ''));
        $imageUrl = trim((string)($input['image_url'] ?? ''));
        $language = strtoupper(trim((string)($input['language'] ?? 'EN')));
        if (!in_array($language, ['EN','TL','HI','FR'], true)) $language = 'EN';

        // Ensure Draft Container exists
        $stDraft = $pdo->prepare("SELECT id FROM qps_question_bank_drafts WHERE UPPER(paper_code) = UPPER(?) AND staff_code = ? AND semester = ? AND academic_year = ? LIMIT 1");
        $stDraft->execute([$paperCode, $staffCode, $semester, $academicYear]);
        $draftRow = $stDraft->fetch(PDO::FETCH_ASSOC);

        $draftId = 0;
        if ($draftRow) {
            $draftId = (int)$draftRow['id'];
        } else {
            // Find course title & dept
            $stC = $pdo->prepare("SELECT coursetitle, dept_code FROM courses WHERE UPPER(coursecode) = UPPER(?) LIMIT 1");
            $stC->execute([$paperCode]);
            $cRow = $stC->fetch(PDO::FETCH_ASSOC);
            $courseTitle = $cRow['coursetitle'] ?? $paperCode;
            $deptCode = $cRow['dept_code'] ?? ($user['dept_code'] ?? 'GEN');

            $stInsD = $pdo->prepare("INSERT INTO qps_question_bank_drafts (staff_code, dept_code, dept_name, paper_code, course_title, semester, academic_year, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'DRAFT', NOW(), NOW())");
            $stInsD->execute([$staffCode, $deptCode, $user['department'] ?? '', $paperCode, $courseTitle, $semester, $academicYear]);
            $draftId = (int)$pdo->lastInsertId();
        }

        if ($questionId > 0) {
            // Update existing question
            $stUp = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET 
                unit_no = ?, sub_unit = ?, section_type = ?, question_type = ?, question_text = ?, 
                marks = ?, k_level = ?, co_level = ?, options_json = ?, answer_key = ?, 
                match_column_a = ?, match_column_b = ?, assertion_text = ?, reason_text = ?, 
                image_url = ?, language = ?, correction_status = 'CORRECTED', updated_at = NOW() 
                WHERE id = ? AND (staff_code = ? OR ? = 1)");
            $stUp->execute([
                $unitNo, $subUnit, $sectionType, $questionType, $questionText,
                $marks, $kLevel, $coLevel, $optionsJson, $answerKey,
                $matchColA, $matchColB, $assertionText, $reasonText,
                $imageUrl, $language, $questionId, $staffCode, (isHOD() || isCOE()) ? 1 : 0
            ]);
            $savedQId = $questionId;

            // Fetch fixed_q_no
            $stFix = $pdo->prepare("SELECT fixed_q_no FROM qps_question_bank_draft_questions WHERE id = ?");
            $stFix->execute([$questionId]);
            $fixedQNo = (int)$stFix->fetchColumn();
        } else {
            // Normalize legacy gaps first, then assign the next sequential draft number.
            // fixed_q_no is deliberately preserved because it is the stable paper-wide identity.
            $pdo->beginTransaction();
            $stExisting = $pdo->prepare("SELECT id FROM qps_question_bank_draft_questions WHERE draft_id = ? ORDER BY id ASC FOR UPDATE");
            $stExisting->execute([$draftId]);
            $existingIds = $stExisting->fetchAll(PDO::FETCH_COLUMN);
            $stRenumber = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET q_number = ? WHERE id = ?");
            foreach ($existingIds as $index => $existingId) {
                $stRenumber->execute([$index + 1, (int)$existingId]);
            }
            $nextQNum = count($existingIds) + 1;

            // Calculate next fixed_q_no per course code
            $stF1 = $pdo->prepare("SELECT COALESCE(MAX(fixed_q_no), 0) FROM questions WHERE UPPER(course_code) = UPPER(?)");
            $stF1->execute([$paperCode]);
            $maxM = (int)$stF1->fetchColumn();

            $stF2 = $pdo->prepare("SELECT COALESCE(MAX(fixed_q_no), 0) FROM qps_question_bank_draft_questions WHERE UPPER(paper_code) = UPPER(?)");
            $stF2->execute([$paperCode]);
            $maxD = (int)$stF2->fetchColumn();

            $fixedQNo = max($maxM, $maxD) + 1;

            $stIns = $pdo->prepare("INSERT INTO qps_question_bank_draft_questions 
                (draft_id, fixed_q_no, staff_code, paper_code, semester, academic_year, q_number, unit_no, sub_unit, section_type, question_type, question_text, marks, k_level, co_level, options_json, answer_key, match_column_a, match_column_b, assertion_text, reason_text, image_url, language, correction_status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CLEAN', NOW(), NOW())");
            $stIns->execute([
                $draftId, $fixedQNo, $staffCode, $paperCode, $semester, $academicYear,
                $nextQNum, $unitNo, $subUnit, $sectionType, $questionType, $questionText,
                $marks, $kLevel, $coLevel, $optionsJson, $answerKey,
                $matchColA, $matchColB, $assertionText, $reasonText,
                $imageUrl, $language
            ]);
            $savedQId = (int)$pdo->lastInsertId();
        }

        if ($pdo->inTransaction()) $pdo->commit();

        // Update total questions count in draft container
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id = ?");
        $stCnt->execute([$draftId]);
        $totalQCount = (int)$stCnt->fetchColumn();
        $pdo->prepare("UPDATE qps_question_bank_drafts SET total_questions = ?, updated_at = NOW() WHERE id = ?")->execute([$totalQCount, $draftId]);

        // Audit Log
        qps_audit($pdo, $questionId > 0 ? 'DRAFT_QUESTION_UPDATED' : 'DRAFT_QUESTION_CREATED', 'DRAFT_QUESTION', (string)$savedQId, [
            'paper_code' => $paperCode,
            'unit' => $unitNo,
            'sub_unit' => $subUnit,
            'marks' => $marks,
            'k_level' => $kLevel,
            'type' => $questionType
        ]);

        $stNext = $pdo->prepare("SELECT COALESCE(MAX(q_number), 0) + 1 FROM qps_question_bank_draft_questions WHERE draft_id = ?");
        $stNext->execute([$draftId]);
        $nextQuestionNumber = (int)$stNext->fetchColumn();
        echo json_encode([
            'success' => true,
            'question_id' => $savedQId,
            'draft_id' => $draftId,
            'total_questions' => $totalQCount,
            'next_question_number' => $nextQuestionNumber,
            'message' => 'Question saved successfully to draft Question Bank.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------
    // 2. DELETE DRAFT QUESTION
    // ----------------------------------------------------
    if ($action === 'delete_question') {
        $questionId = (int)($input['question_id'] ?? 0);
        if ($questionId <= 0) {
            throw new RuntimeException('Invalid Question ID provided.');
        }

        $stQ = $pdo->prepare("SELECT draft_id, paper_code, q_number FROM qps_question_bank_draft_questions WHERE id = ? AND (staff_code = ? OR ? = 1)");
        $stQ->execute([$questionId, $staffCode, (isHOD() || isCOE()) ? 1 : 0]);
        $row = $stQ->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Question not found or permission denied.');
        }

        $draftId = (int)$row['draft_id'];
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM qps_question_bank_draft_questions WHERE id = ?")->execute([$questionId]);

        // Compact q_number values after deletion (1..N). Keep fixed_q_no unchanged.
        $stRemaining = $pdo->prepare("SELECT id FROM qps_question_bank_draft_questions WHERE draft_id = ? ORDER BY q_number ASC, id ASC FOR UPDATE");
        $stRemaining->execute([$draftId]);
        $remainingIds = $stRemaining->fetchAll(PDO::FETCH_COLUMN);
        $stRenumber = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET q_number = ? WHERE id = ?");
        foreach ($remainingIds as $index => $remainingId) {
            $stRenumber->execute([$index + 1, (int)$remainingId]);
        }
        $totalQCount = count($remainingIds);
        $pdo->prepare("UPDATE qps_question_bank_drafts SET total_questions = ?, updated_at = NOW() WHERE id = ?")->execute([$totalQCount, $draftId]);
        $pdo->commit();

        qps_audit($pdo, 'DRAFT_QUESTION_DELETED', 'DRAFT_QUESTION', (string)$questionId, [
            'paper_code' => $row['paper_code'],
            'q_number' => $row['q_number']
        ]);

        echo json_encode([
            'success' => true,
            'total_questions' => $totalQCount,
            'message' => 'Question deleted successfully.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------
    // 3. SUBMIT DRAFT QUESTION BANK TO HOD
    // ----------------------------------------------------
    if ($action === 'submit_to_hod') {
        $draftId = (int)($input['draft_id'] ?? 0);
        if ($draftId <= 0 && $paperCode !== '') {
            $stD = $pdo->prepare("SELECT id FROM qps_question_bank_drafts WHERE UPPER(paper_code) = UPPER(?) AND staff_code = ? ORDER BY id DESC LIMIT 1");
            $stD->execute([$paperCode, $staffCode]);
            $draftId = (int)$stD->fetchColumn();
        }

        if ($draftId <= 0) {
            throw new RuntimeException('Draft Question Bank not found.');
        }

        // Count questions
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id = ?");
        $stCnt->execute([$draftId]);
        $count = (int)$stCnt->fetchColumn();

        if ($count < 1) {
            throw new RuntimeException('Please enter at least one question before submitting to HOD.');
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE qps_question_bank_drafts SET status = 'SUBMITTED_TO_HOD', submitted_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$now, $now, $draftId]);

        qps_audit($pdo, 'DRAFT_SUBMITTED_TO_HOD', 'DRAFT_BANK', (string)$draftId, [
            'paper_code' => $paperCode,
            'total_questions' => $count
        ]);

        echo json_encode([
            'success' => true,
            'draft_id' => $draftId,
            'status' => 'SUBMITTED_TO_HOD',
            'message' => "Question Bank ({$count} questions) successfully submitted to Head of Department (HOD) for verification!"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unsupported action requested.');

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
