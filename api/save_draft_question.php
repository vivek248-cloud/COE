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

if (!isLoggedIn() || !in_array(strtoupper((string)getUserRole()), ['TEACHING', 'TEACHING_STAFF', 'FACULTY', 'TEACHING_FACULTY'], true)) {
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
    if (in_array($action, ['save_question','update_question','delete_question','submit_to_hod','save_context'], true)) {
        $csrf=(string)($_SESSION['qps_review_csrf']??'');
        if($csrf===''||!hash_equals($csrf,(string)($input['csrf']??''))) throw new RuntimeException('Security token expired. Refresh the page.');
    }
    $paperCode = strtoupper(trim((string)($input['paper_code'] ?? ($input['course_code'] ?? ($input['course'] ?? '')))));
    $semester = trim((string)($input['semester'] ?? 'Semester 1'));
    $academicYear = trim((string)($input['academic_year'] ?? (defined('DEFAULT_ACADEMIC_YEAR') ? DEFAULT_ACADEMIC_YEAR : '2026-2027')));
    $staffCode = $user['staff_code'] ?? 'STAFF';
    if ($paperCode !== '') { $assigned=$pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1'); $assigned->execute([$staffCode,$paperCode]); if(!$assigned->fetchColumn()) throw new RuntimeException('You are not allocated to this course.'); }

function qps_assert_owned_staff_draft_editable(PDO $pdo, int $draftId, string $staffCode): void {
    $st = $pdo->prepare("SELECT d.staff_code,d.status AS draft_status,p.status AS package_status,p.current_owner_role
        FROM qps_question_bank_drafts d
        LEFT JOIN qps_submission_packages p ON p.bank_draft_id=d.id
        WHERE d.id=? AND d.staff_code=? ORDER BY p.id DESC LIMIT 1");
    $st->execute([$draftId,$staffCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Draft header ownership could not be verified for this staff member.');
    if (!in_array(strtoupper((string)$row['draft_status']), ['DRAFT','RETURNED_TO_STAFF','STAFF_CORRECTION'], true)) {
        throw new RuntimeException('This draft is locked and cannot be edited by staff.');
    }
    $packageStatus = strtoupper((string)($row['package_status'] ?? ''));
    if ($packageStatus !== '' && !(in_array($packageStatus, ['HOD_REMARKS','STAFF_CORRECTION'], true) && strtoupper((string)$row['current_owner_role']) === 'STAFF')) {
        throw new RuntimeException('This submission is with another reviewer. Staff editing is available only for drafts or returned corrections.');
    }
}

    // ----------------------------------------------------
    // SAVE TEACHING ACADEMIC CONTEXT
    // ----------------------------------------------------
    if ($action === 'save_context') {
        if ($paperCode === '') throw new RuntimeException('Select an allocated course first.');
        $academicYear = trim((string)($input['academic_year'] ?? ''));
        $programmeYear = strtoupper(trim((string)($input['programme_year'] ?? '')));
        $semester = trim((string)($input['semester'] ?? ''));
        $language = strtoupper(trim((string)($input['language'] ?? 'EN')));
        if (!preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/', $academicYear)) throw new RuntimeException('Academic Year must use YYYY-YYYY format.');
        [$yearStart,$yearEnd] = array_map('intval',explode('-',$academicYear));
        if ($yearEnd !== $yearStart + 1) throw new RuntimeException('Academic Year must contain consecutive years.');
        if (!in_array($programmeYear,['I','II','III','IV'],true)) throw new RuntimeException('Year of Programme must be I, II, III or IV.');
        if (!preg_match('/^Semester ([1-8])$/',$semester)) throw new RuntimeException('Semester must be between 1 and 8.');
        if (!in_array($language,['EN','TL','HI','FR'],true)) throw new RuntimeException('Select a valid question language.');
        $stCourse=$pdo->prepare("SELECT c.coursetitle,c.dept_code,d.name AS dept_name,c.papertype,c.coursetype1,c.coursetype FROM courses c LEFT JOIN departments d ON d.code=c.dept_code WHERE UPPER(c.coursecode)=UPPER(?) LIMIT 1");
        $stCourse->execute([$paperCode]); $courseRow=$stCourse->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$courseRow) throw new RuntimeException('The selected course was not found.');
        $st=$pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND staff_code=? AND semester=? AND academic_year=? AND programme_year=? AND language=? ORDER BY id DESC LIMIT 1");
        $st->execute([$paperCode,$staffCode,$semester,$academicYear,$programmeYear,$language]);
        $draftRow=$st->fetch(PDO::FETCH_ASSOC);
        if ($draftRow) {
            $draftId=(int)$draftRow['id']; qps_assert_owned_staff_draft_editable($pdo,$draftId,(string)$staffCode);
        } else {
            $courseType=strtoupper(trim((string)($courseRow['papertype']??$courseRow['coursetype1']??$courseRow['coursetype']??'OBE')));
            $ins=$pdo->prepare("INSERT INTO qps_question_bank_drafts (staff_code,dept_code,dept_name,paper_code,course_title,course_type,degree_level,programme_year,programme_level,max_marks,total_marks,semester,academic_year,language,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,0,?,?,?,'DRAFT',NOW(),NOW())");
            $ins->execute([$staffCode,$courseRow['dept_code']??($user['dept_code']??''),$courseRow['dept_name']??'', $paperCode,$courseRow['coursetitle']??$paperCode,$courseType?:'OBE',$programmeYear,$programmeYear,$programmeYear,$semester,$academicYear,$language]);
            $draftId=(int)$pdo->lastInsertId();
        }
        $stDraft=$pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE id=? AND staff_code=? LIMIT 1");
        $stDraft->execute([$draftId,$staffCode]);
        echo json_encode(['success'=>true,'draft'=>$stDraft->fetch(PDO::FETCH_ASSOC),'message'=>'Independent question-bank context saved.'],JSON_UNESCAPED_UNICODE); exit;
    }

    // ----------------------------------------------------
    // 1. SAVE / UPDATE SINGLE DRAFT QUESTION
    // ----------------------------------------------------
    if ($action === 'save_question') {
        if ($paperCode === '') {
            throw new RuntimeException('Course / Paper Code is required.');
        }

        $questionId = (int)($input['question_id'] ?? 0);
        $unitNo = filter_var($input['unit_no'] ?? null, FILTER_VALIDATE_INT);
        if($unitNo===false || $unitNo<1 || $unitNo>5) throw new RuntimeException('Select a valid Unit (1 to 5).');
        $unitNo=(int)$unitNo;
        $subUnit = trim((string)($input['sub_unit'] ?? "$unitNo.1"));
        $marks = filter_var($input['marks'] ?? null, FILTER_VALIDATE_INT);
        if ($marks === false || $marks < 1 || $marks > 200) throw new RuntimeException('Question marks must be a whole number from 1 to 200.');
        $marks = (int)$marks;

        // Use section_type from frontend if provided, otherwise auto-determine based on marks
        $sectionType = strtoupper(trim((string)($input['section_type'] ?? '')));
        $validSections = ['SECTION-A', 'SECTION-B', 'SECTION-C', 'SECTION-D'];
        if (!in_array($sectionType, $validSections, true)) {
            // Fallback to auto-determination based on marks
            if ($marks === 1) {
                $sectionType = 'SECTION-A';
            } elseif ($marks === 5) {
                $sectionType = 'SECTION-B';
            } elseif ($marks === 10) {
                $isComp = !empty($input['is_compulsory']);
                $sectionType = $isComp ? 'SECTION-D' : 'SECTION-C';
            }
        }

        $questionType = strtoupper(trim((string)($input['question_type'] ?? 'MCQ')));
        $validQuestionTypes = ['MCQ','MATCH','ASSERTION_REASON','VSA','FILL_BLANKS','TRUE_FALSE','PARAGRAPH','CASE_STUDY','ESSAY','COMPULSORY'];
        if (!in_array($questionType,$validQuestionTypes,true)) throw new RuntimeException('Select a valid question type.');

        $kLevel = strtoupper(trim((string)($input['k_level'] ?? '')));
        if (!in_array($kLevel, ['K1','K2','K3','K4','K5','K6'], true)) throw new RuntimeException('Select a valid K-Level (K1-K6).');
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
        $programmeYear = strtoupper(trim((string)($input['programme_year'] ?? $input['programme_level'] ?? '')));
        $programmeLevel = $programmeYear;
        $language = strtoupper(trim((string)($input['language'] ?? 'EN')));
        if (!in_array($language, ['EN','TL','HI','FR'], true)) throw new RuntimeException('Select a valid question language.');
        if (!preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/', $academicYear)) throw new RuntimeException('Select a valid academic year before saving questions.');
        [$yearStart,$yearEnd]=array_map('intval',explode('-',$academicYear));
        if($yearEnd!==$yearStart+1) throw new RuntimeException('Academic Year must contain consecutive years.');
        if (!in_array($programmeYear, ['I','II','III','IV'], true)) throw new RuntimeException('Year of Programme must be I, II, III or IV.');
        if (!preg_match('/^Semester ([1-8])$/',$semester)) throw new RuntimeException('Select a valid semester before saving questions.');
        if (!in_array($kLevel,['K1','K2','K3','K4','K5','K6'],true)) throw new RuntimeException('Select a valid K-Level (K1-K6).');
        if (!preg_match('/^CO[1-6]$/',$coLevel)) throw new RuntimeException('Select a valid CO-Level (CO1-CO6).');
        if ($unitNo < 1 || $unitNo > 20) throw new RuntimeException('Unit number must be between 1 and 20.');
        if (!preg_match('/^'.preg_quote((string)$unitNo,'/').'\.[1-9][0-9]*$/',$subUnit)) throw new RuntimeException('Sub-unit must belong to the selected Unit (example: 1.1).');

        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        $stDraft = $pdo->prepare("SELECT id, status, programme_year FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND staff_code=? AND semester=? AND academic_year=? AND programme_year=? AND language=? ORDER BY id DESC LIMIT 1");
        $stDraft->execute([$paperCode,$staffCode,$semester,$academicYear,$programmeYear,$language]);
        $draftRow=$stDraft->fetch(PDO::FETCH_ASSOC);
        $draftId=0;
        if($draftRow){$draftId=(int)$draftRow['id'];}
        else {
            $stC=$pdo->prepare("SELECT coursetitle,dept_code,papertype,coursetype1,coursetype FROM courses WHERE UPPER(coursecode)=UPPER(?) LIMIT 1");
            $stC->execute([$paperCode]); $cRow=$stC->fetch(PDO::FETCH_ASSOC)?:[];
            if(!$cRow) throw new RuntimeException('The selected course was not found.');
            $courseType=strtoupper(trim((string)($cRow['papertype']??$cRow['coursetype1']??$cRow['coursetype']??'OBE')));
            $stInsD=$pdo->prepare("INSERT INTO qps_question_bank_drafts (staff_code,dept_code,dept_name,paper_code,course_title,course_type,degree_level,programme_year,programme_level,max_marks,total_marks,semester,academic_year,language,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,0,?,?,?,'DRAFT',NOW(),NOW())");
            $stInsD->execute([$staffCode,$cRow['dept_code']??($user['dept_code']??''),$user['department']??'',$paperCode,$cRow['coursetitle']??$paperCode,$courseType?:'OBE',$programmeYear,$programmeYear,$programmeYear,$semester,$academicYear,$language]);
            $draftId=(int)$pdo->lastInsertId();
        }

        if ($draftId > 0) {
            qps_assert_owned_staff_draft_editable($pdo, $draftId, $staffCode);
            $pdo->prepare("UPDATE qps_question_bank_drafts SET programme_year=?,programme_level=?,degree_level=?,max_marks=0,total_marks=0,language=?,updated_at=NOW() WHERE id=?")
                ->execute([$programmeYear,$programmeLevel,$programmeLevel,$language,$draftId]);
        }

        if ($questionId > 0) {
            // Update existing question
            // A staff update must target a question in that staff member's own draft header.
            // HOD/Dean review corrections use the review API; this endpoint is the staff workspace writer.
            $stOwner = $pdo->prepare("SELECT q.draft_id, d.staff_code, d.status
                FROM qps_question_bank_draft_questions q
                INNER JOIN qps_question_bank_drafts d ON d.id = q.draft_id
                WHERE q.id = ? AND q.draft_id = ? AND q.staff_code = ? AND d.staff_code = ?
                LIMIT 1");
            $stOwner->execute([$questionId, $draftId, $staffCode, $staffCode]);
            $ownerRow = $stOwner->fetch(PDO::FETCH_ASSOC);
            if (!$ownerRow) throw new RuntimeException('You can update only questions in your own course draft.');
            qps_assert_owned_staff_draft_editable($pdo, $draftId, $staffCode);
            $stUp = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET
                semester = ?, academic_year = ?, programme_year = ?, programme_level = ?, total_marks = 0,
                unit_no = ?, sub_unit = ?, section_type = ?, question_type = ?, question_text = ?,
                marks = ?, k_level = ?, co_level = ?, options_json = ?, answer_key = ?,
                match_column_a = ?, match_column_b = ?, assertion_text = ?, reason_text = ?,
                image_url = ?, language = ?, correction_status = 'CORRECTED', verification_status = 'VERIFICATION_PENDING', updated_at = NOW()
                WHERE id = ? AND draft_id = ? AND staff_code = ?");
            $stUp->execute([
                $semester, $academicYear, $programmeYear, $programmeLevel,
                $unitNo, $subUnit, $sectionType, $questionType, $questionText,
                $marks, $kLevel, $coLevel, $optionsJson, $answerKey,
                $matchColA, $matchColB, $assertionText, $reasonText,
                $imageUrl, $language, $questionId, $draftId, $staffCode
            ]);
            $savedQId = $questionId;

            // Fetch fixed_q_no from the same owned draft row.
            $stFix = $pdo->prepare("SELECT fixed_q_no FROM qps_question_bank_draft_questions WHERE id = ? AND draft_id = ? AND staff_code = ?");
            $stFix->execute([$questionId, $draftId, $staffCode]);
            $fixedQNo = (int)$stFix->fetchColumn();
        } else {
            // Normalize legacy gaps first, then assign the next sequential draft number.
            // fixed_q_no is deliberately preserved because it is the stable paper-wide identity.
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
                (draft_id, fixed_q_no, staff_code, paper_code, semester, academic_year, programme_year, programme_level, total_marks, q_number, unit_no, sub_unit, section_type, question_type, question_text, marks, k_level, co_level, options_json, answer_key, match_column_a, match_column_b, assertion_text, reason_text, image_url, language, correction_status, verification_status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CLEAN', 'VERIFICATION_PENDING', NOW(), NOW())");
            $stIns->execute([
                $draftId, $fixedQNo, $staffCode, $paperCode, $semester, $academicYear,
                $programmeYear, $programmeLevel,
                $nextQNum, $unitNo, $subUnit, $sectionType, $questionType, $questionText,
                $marks, $kLevel, $coLevel, $optionsJson, $answerKey,
                $matchColA, $matchColB, $assertionText, $reasonText,
                $imageUrl, $language
            ]);
            $savedQId = (int)$pdo->lastInsertId();
        }

        // Update total questions count in the same transaction

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
        if ($pdo->inTransaction()) $pdo->commit();

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

        $stQ = $pdo->prepare("SELECT q.draft_id, q.paper_code, q.q_number, d.status
            FROM qps_question_bank_draft_questions q
            INNER JOIN qps_question_bank_drafts d ON d.id = q.draft_id
            WHERE q.id = ? AND q.staff_code = ? AND d.staff_code = ? LIMIT 1");
        $stQ->execute([$questionId, $staffCode, $staffCode]);
        $row = $stQ->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Question not found or permission denied.');
        }

        $draftId = (int)$row['draft_id'];
        qps_assert_owned_staff_draft_editable($pdo, $draftId, $staffCode);
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM qps_question_bank_draft_questions WHERE id = ? AND draft_id = ? AND staff_code = ?")->execute([$questionId, $draftId, $staffCode]);

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
        throw new RuntimeException('Direct draft submission is disabled. Submit through the validated Staff workflow so the Question Bank, Question Bank Blueprint and Final Blueprint are checked together.');
    }

    throw new RuntimeException('Unsupported action requested.');

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    error_log('[QPS save_draft_question] '.$e->getMessage());
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save the question. Please check the form and try again.';
    echo json_encode(['success'=>false,'message'=>$message], JSON_UNESCAPED_UNICODE);
}
