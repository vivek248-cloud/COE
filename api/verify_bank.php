<?php
/**
 * AJAX API: Review, Verification & Atomic Approval Flow (HOD -> COE -> Master Question Bank)
 * Holy Cross College (Autonomous) - Examination Management System
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
qps_ensure_question_draft_schema($pdo);

try {
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) $rawInput = @file_get_contents('php://stdin');
    $input = json_decode((string)$rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = trim((string)($input['action'] ?? ''));
    $draftId = (int)($input['draft_id'] ?? ($input['bank_id'] ?? 0));
    $bankId = (int)($input['bank_id'] ?? ($input['draft_id'] ?? 0));
    $paperCode = strtoupper(trim((string)($input['paper_code'] ?? ($input['course_code'] ?? ''))));
    $remarks = trim((string)($input['remarks'] ?? ''));
    $staffCode = $user['staff_code'] ?? 'USER';

    // ----------------------------------------------------
    // Resolution: Find target Draft or Master Question Bank
    // ----------------------------------------------------
    $draft = null;
    if ($draftId > 0) {
        $stD = $pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE id = ? LIMIT 1");
        $stD->execute([$draftId]);
        $draft = $stD->fetch(PDO::FETCH_ASSOC);
    }

    if (!$draft && $paperCode !== '') {
        $stD = $pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE UPPER(paper_code) = UPPER(?) ORDER BY id DESC LIMIT 1");
        $stD->execute([$paperCode]);
        $draft = $stD->fetch(PDO::FETCH_ASSOC);
        if ($draft) $draftId = (int)$draft['id'];
    }

    $existingBank = null;
    if ($bankId > 0) {
        $stB = $pdo->prepare("SELECT * FROM question_banks WHERE id = ? LIMIT 1");
        $stB->execute([$bankId]);
        $existingBank = $stB->fetch(PDO::FETCH_ASSOC);
    }

    if (!$existingBank && $paperCode !== '') {
        $stB = $pdo->prepare("SELECT * FROM question_banks WHERE UPPER(paper_code) = UPPER(?) ORDER BY id DESC LIMIT 1");
        $stB->execute([$paperCode]);
        $existingBank = $stB->fetch(PDO::FETCH_ASSOC);
        if ($existingBank) $bankId = (int)$existingBank['id'];
    }

    if (!$draft && !$existingBank && $draftId <= 0 && $bankId <= 0) {
        throw new RuntimeException('Invalid Question Bank or Draft ID provided.');
    }

    $effectivePaperCode = $paperCode ?: ($draft['paper_code'] ?? ($existingBank['paper_code'] ?? ''));

    // ----------------------------------------------------
    // ACTION 1: CHECK DUPLICATES
    // ----------------------------------------------------
    if ($action === 'check_duplicates') {
        $poolQuestions = [];
        if ($draftId > 0 && $draft) {
            $stQ = $pdo->prepare("SELECT id, q_number, unit_no, sub_unit, section_type as section, question_type, k_level, marks, question_text FROM qps_question_bank_draft_questions WHERE draft_id = ? ORDER BY id ASC");
            $stQ->execute([$draftId]);
            $poolQuestions = $stQ->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($bankId > 0 && $existingBank) {
            $stQ = $pdo->prepare("SELECT id, q_number, unit_no, sub_unit, section_type as section, question_type, k_level, marks, question_text FROM questions WHERE bank_id = ? ORDER BY id ASC");
            $stQ->execute([$bankId]);
            $poolQuestions = $stQ->fetchAll(PDO::FETCH_ASSOC);
        }

        $seen = [];
        $duplicates = [];
        foreach ($poolQuestions as $idx => $q) {
            $clean = mb_strtolower(preg_replace('/\s+/u', ' ', trim(strip_tags($q['question_text'] ?? ''))));
            if ($clean === '') continue;
            $hash = hash('sha256', $clean);

            if (isset($seen[$hash])) {
                $prev = $seen[$hash];
                $duplicates[] = [
                    'q_number' => $q['q_number'] ?? ($idx + 1),
                    'section' => $q['section'] ?? 'SECTION-A',
                    'k_level' => $q['k_level'] ?? 'K1',
                    'question_text' => $q['question_text'],
                    'matched_with' => "Matches Q#" . ($prev['q_number'] ?? 1) . " (Unit " . ($prev['unit_no'] ?? 1) . ")"
                ];
            } else {
                $seen[$hash] = $q;
            }
        }

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'duplicate_count' => count($duplicates),
            'duplicates' => $duplicates,
            'total_scanned' => count($poolQuestions)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------
    // ACTION 2: HOD VERIFY & FORWARD TO COE (or submit_to_coe)
    // ----------------------------------------------------
    if ($action === 'hod_verify_and_forward' || $action === 'submit_to_coe') {
        if (!isHOD() && !isCOE() && empty($user['is_super_admin'])) {
            throw new RuntimeException('Permission denied: Only Head of Department (HOD) or COE can forward question banks.');
        }

        $now = date('Y-m-d H:i:s');

        if ($draft && $draftId > 0) {
            // Check unresolved corrections
            $stUnresolved = $pdo->prepare("SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id = ? AND correction_status = 'NEEDS_CORRECTION'");
            $stUnresolved->execute([$draftId]);
            $unresolvedCount = (int)$stUnresolved->fetchColumn();

            if ($unresolvedCount > 0 && empty($input['force_forward'])) {
                throw new RuntimeException("There are {$unresolvedCount} questions marked for correction that have not been resolved yet.");
            }

            $stUp = $pdo->prepare("UPDATE qps_question_bank_drafts SET 
                status = 'SUBMITTED_TO_COE', 
                hod_reviewed_by = ?, 
                hod_reviewed_at = ?, 
                hod_remarks = ?, 
                updated_at = ? 
                WHERE id = ?");
            $stUp->execute([$staffCode, $now, $remarks, $now, $draftId]);

            qps_audit($pdo, 'HOD_FORWARDED_TO_COE', 'DRAFT_BANK', (string)$draftId, [
                'paper_code' => $effectivePaperCode,
                'hod_code' => $staffCode,
                'remarks' => $remarks
            ]);

            ob_end_clean();
            echo json_encode([
                'success' => true,
                'draft_id' => $draftId,
                'status' => 'SUBMITTED_TO_COE',
                'message' => 'Question Bank verified and successfully submitted to COE Office for final approval.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($existingBank && $bankId > 0) {
            $pdo->prepare("UPDATE question_banks SET status = 'APPROVED_BY_HOD', hod_status = 'approved', hod_reviewed_by = ?, hod_reviewed_at = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$staffCode, $now, $bankId]);

            qps_audit($pdo, 'HOD_FORWARDED_TO_COE', 'QUESTION_BANK', (string)$bankId, [
                'paper_code' => $effectivePaperCode,
                'hod_code' => $staffCode
            ]);

            ob_end_clean();
            echo json_encode([
                'success' => true,
                'bank_id' => $bankId,
                'status' => 'APPROVED_BY_HOD',
                'message' => 'Question Bank verified and successfully submitted to COE Office for final approval.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ----------------------------------------------------
    // ACTION 3: COE APPROVE & COMMIT TO MASTER QUESTION BANK (or approve_bank)
    // ----------------------------------------------------
    if ($action === 'coe_approve_and_commit' || $action === 'approve_bank') {
        if (!isCOE() && empty($user['is_super_admin'])) {
            throw new RuntimeException('Permission denied: Only Controller of Examinations (COE) Office can approve question banks.');
        }

        $now = date('Y-m-d H:i:s');

        // Case A: Draft to Master Question Bank promotion
        if ($draft && $draftId > 0) {
            $stQ = $pdo->prepare("SELECT * FROM qps_question_bank_draft_questions WHERE draft_id = ? ORDER BY id ASC");
            $stQ->execute([$draftId]);
            $draftQuestions = $stQ->fetchAll(PDO::FETCH_ASSOC);

            if (empty($draftQuestions)) {
                // If draft questions already committed or empty, mark draft as approved
                $pdo->prepare("UPDATE qps_question_bank_drafts SET status = 'APPROVED', coe_reviewed_by = ?, coe_reviewed_at = ?, coe_remarks = ?, updated_at = ? WHERE id = ?")
                    ->execute([$staffCode, $now, $remarks, $now, $draftId]);

                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'status' => 'APPROVED',
                    'message' => 'Question Bank approved successfully.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $stCourse = $pdo->prepare("SELECT coursetitle, dept_code, level, maxmark FROM courses WHERE UPPER(coursecode) = UPPER(?) LIMIT 1");
                $stCourse->execute([$effectivePaperCode]);
                $cRow = $stCourse->fetch(PDO::FETCH_ASSOC);

                $courseTitle = $cRow['coursetitle'] ?? $draft['course_title'] ?? $effectivePaperCode;
                $deptCode = $cRow['dept_code'] ?? $draft['dept_code'] ?? 'GEN';
                $semester = $draft['semester'] ?: ($input['semester'] ?? 'Semester 1');
                $academicYear = $draft['academic_year'] ?: (defined('DEFAULT_ACADEMIC_YEAR') ? DEFAULT_ACADEMIC_YEAR : '2026-2027');

                $stB = $pdo->prepare("SELECT id FROM question_banks WHERE UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ? LIMIT 1");
                $stB->execute([$effectivePaperCode, $semester, $academicYear]);
                $targetBankId = (int)$stB->fetchColumn();

                if ($targetBankId > 0) {
                    $pdo->prepare("UPDATE question_banks SET total_questions = total_questions + ?, status = 'APPROVED', verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ?")
                        ->execute([count($draftQuestions), $staffCode, $targetBankId]);
                } else {
                    $stInsB = $pdo->prepare("INSERT INTO question_banks 
                        (paper_code, course_title, dept_code, semester, academic_year, exam_type, total_questions, status, staff_code, verified_by, verified_at, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'APPROVED', ?, ?, NOW(), NOW(), NOW())");
                    $stInsB->execute([
                        $effectivePaperCode, $courseTitle, $deptCode, $semester, $academicYear,
                        $draft['exam_type'] ?? 'Odd Semester End Examination',
                        count($draftQuestions),
                        $draft['staff_code'],
                        $staffCode
                    ]);
                    $targetBankId = (int)$pdo->lastInsertId();
                }

                $stInsQ = $pdo->prepare("INSERT INTO questions 
                    (bank_id, course_code, paper_code, fixed_q_no, q_number, unit_no, sub_unit, section_type, question_type, question_text, marks, k_level, co_level, options_json, answer_key, match_column_a, match_column_b, assertion_text, reason_text, image_url, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

                $importedCount = 0;
                foreach ($draftQuestions as $idx => $dq) {
                    $fq = (int)($dq['fixed_q_no'] ?? ($dq['q_number'] ?? ($idx + 1)));
                    $stInsQ->execute([
                        $targetBankId,
                        $effectivePaperCode,
                        $effectivePaperCode,
                        $fq,
                        (int)($dq['q_number'] ?? ($idx + 1)),
                        (int)($dq['unit_no'] ?? 1),
                        $dq['sub_unit'] ?? '1.1',
                        $dq['section_type'] ?? 'SECTION-A',
                        $dq['question_type'] ?? 'MCQ',
                        $dq['question_text'],
                        (int)($dq['marks'] ?? 1),
                        $dq['k_level'] ?? 'K1',
                        $dq['co_level'] ?? 'CO1',
                        $dq['options_json'] ?? null,
                        $dq['answer_key'] ?? null,
                        $dq['match_column_a'] ?? null,
                        $dq['match_column_b'] ?? null,
                        $dq['assertion_text'] ?? null,
                        $dq['reason_text'] ?? null,
                        $dq['image_url'] ?? null
                    ]);
                    $importedCount++;
                }

                // Delete draft questions and update draft status
                $pdo->prepare("DELETE FROM qps_question_bank_draft_questions WHERE draft_id = ?")->execute([$draftId]);
                $pdo->prepare("UPDATE qps_question_bank_drafts SET status = 'APPROVED', coe_reviewed_by = ?, coe_reviewed_at = ?, coe_remarks = ?, total_questions = 0, updated_at = ? WHERE id = ?")
                    ->execute([$staffCode, $now, $remarks, $now, $draftId]);

                $pdo->commit();

                qps_audit($pdo, 'COE_APPROVED_QUESTION_BANK', 'QUESTION_BANK', (string)$targetBankId, [
                    'draft_id' => $draftId,
                    'paper_code' => $effectivePaperCode,
                    'imported_questions' => $importedCount,
                    'approved_by' => $staffCode
                ]);

                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'draft_id' => $draftId,
                    'bank_id' => $targetBankId,
                    'imported_count' => $importedCount,
                    'status' => 'APPROVED',
                    'message' => "Question Bank approved! {$importedCount} questions successfully committed to Master Question Bank."
                ], JSON_UNESCAPED_UNICODE);
                exit;

            } catch (Throwable $eTx) {
                $pdo->rollBack();
                throw $eTx;
            }
        }

        // Case B: Direct Master Question Bank approval
        if ($existingBank && $bankId > 0) {
            $pdo->prepare("UPDATE question_banks SET status = 'APPROVED', verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$staffCode, $bankId]);

            qps_audit($pdo, 'COE_APPROVED_QUESTION_BANK', 'QUESTION_BANK', (string)$bankId, [
                'paper_code' => $effectivePaperCode,
                'approved_by' => $staffCode
            ]);

            ob_end_clean();
            echo json_encode([
                'success' => true,
                'bank_id' => $bankId,
                'status' => 'APPROVED',
                'message' => "Question Bank #{$bankId} successfully approved for examination paper generation."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ----------------------------------------------------
    // ACTION 4: REJECT DRAFT
    // ----------------------------------------------------
    if ($action === 'reject_draft') {
        if (!isHOD() && !isCOE() && empty($user['is_super_admin'])) {
            throw new RuntimeException('Permission denied.');
        }

        $now = date('Y-m-d H:i:s');
        if ($draft && $draftId > 0) {
            $pdo->prepare("UPDATE qps_question_bank_drafts SET status = 'NEEDS_CORRECTION', review_comments = ?, updated_at = ? WHERE id = ?")
                ->execute([$remarks, $now, $draftId]);
        }

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'draft_id' => $draftId,
            'status' => 'NEEDS_CORRECTION',
            'message' => 'Question bank sent back for revision.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException("Unsupported action requested: {$action}");

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
