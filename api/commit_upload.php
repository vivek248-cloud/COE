<?php
/**
 * Question Bank Commit & Persistence Handler
 * Holy Cross College (Autonomous) - Examination System
 * - Pure JSON Output with Output Buffering (No HTML leak)
 * - Course Dedication & Timetable Allocation Verification
 * - Answer Key Storage into `answer_keys` Table
 * - Hierarchical Compressed File Storage (course_code -> semester -> year)
 * - Duplicate Resolution (Replace, Append, Skip) & 250+ Question Appending
 * - Clean JSON Payload Persistence with Unit, Sub-unit, K-level, CO-level
 * - Staff to HOD / HOD to COE Workflow
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();

function qps_question_norm(string $s): string {
    $s = strip_tags($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

try {
    qps_ensure_aux_schema($pdo);
    $rawInput = file_get_contents('php://input'); if (empty($rawInput)) { $rawInput = @file_get_contents('php://stdin'); }
    $input = json_decode($rawInput, true);
    if (!is_array($input)) throw new RuntimeException('Invalid JSON request payload.');

    $questions = $input['questions'] ?? [];
    if (count($questions) < 1) {
        throw new RuntimeException('At least one question is required in the question bank.');
    }

    $paperCode = strtoupper(trim((string)($input['paper_code'] ?? '')));
    $semester = trim((string)($input['semester'] ?? 'Semester 1'));
    $academicYear = trim((string)($input['academic_year'] ?? DEFAULT_ACADEMIC_YEAR));
    $examType = trim((string)($input['exam_type'] ?? 'Odd Semester End Examination'));
    $regulation = trim((string)($input['regulation'] ?? DEFAULT_REGULATION));
    $degreeLevel = trim((string)($input['degree_level'] ?? 'UG'));
    $submitAction = trim((string)($input['submit_action'] ?? ($input['status'] ?? 'submitted_to_hod')));
    $bankLanguage = trim((string)($input['language'] ?? 'en'));
    $bankId = (int)($input['bank_id'] ?? 0);

    if ($paperCode === '') throw new RuntimeException('Course / Paper Code is required.');

    // Normalize Status
    if ($submitAction === 'Draft' || $submitAction === 'draft') {
        $status = 'Draft';
    } elseif ($submitAction === 'submit_to_coe' || $submitAction === 'Approved' || isHOD() || isCOE()) {
        $status = ($submitAction === 'submit_to_coe' || isCOE()) ? 'Submitted to COE' : 'Submitted to HOD';
    } else {
        $status = 'Submitted to HOD';
    }

    // -------------------------------------------------------------
    // COURSE METADATA & DEDICATION CHECK
    // -------------------------------------------------------------
    $st = $pdo->prepare("SELECT c.coursecode as papercode, c.dept_code as deptcode, c.coursetitle,
                                 c.dept_code as course_dept, c.level, c.maxmark, c.credit, c.type, d.name as dept_name
                          FROM courses c
                          LEFT JOIN departments d ON d.code = c.dept_code
                          WHERE UPPER(c.coursecode) = ? LIMIT 1");
    $st->execute([$paperCode]);
    $course = $st->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        // Fallback search
        $stAlt = $pdo->prepare("SELECT coursecode as papercode, dept_code as deptcode, coursetitle, dept_code as course_dept, level, maxmark, credit, type FROM courses WHERE UPPER(coursecode) LIKE ? LIMIT 1");
        $stAlt->execute(['%' . $paperCode . '%']);
        $course = $stAlt->fetch(PDO::FETCH_ASSOC);
    }

    $deptCode = $course['course_dept'] ?? ($course['deptcode'] ?? ($user['dept_code'] ?? 'GEN'));
    $deptName = $course['dept_name'] ?? $deptCode;
    $courseTitle = $course['coursetitle'] ?? $paperCode;
    
    // Accurate OBE Theory (75M) vs Practical Lab (50M) vs Non-OBE (50M)
    $examInfo = hcc_course_exam_info($course);
    $isNonObe = ($examInfo['type_label'] !== 'OBE Theory (75M)');
    $maxMarks = (int)$examInfo['marks'];

    // Filter and normalize questions based on replace_action
    $finalQuestions = [];
    $seen = [];
    $duplicateWarnings = [];
    $numCounter = 1;

    foreach ($questions as $i => $q) {
        $action = $q['replace_action'] ?? 'append';
        if ($action === 'skip') {
            continue; // Skip duplicate
        }

        $qText = trim((string)($q['question_text'] ?? ''));
        if ($qText === '') continue;

        $unit = max(1, min(5, (int)($q['unit_no'] ?? 1)));
        $subUnit = trim((string)($q['sub_unit'] ?? "{$unit}.1"));
        $kLevel = strtoupper(trim((string)($q['k_level'] ?? 'K1')));
        $kNum = preg_match('/K([1-6])/i', $kLevel, $km) ? (int)$km[1] : 1;
        $kNorm = 'K' . $kNum;

        // Rule: CO level is ALWAYS identical to K level
        $coNorm = 'CO' . $kNum;

        $marks = max(1, min(100, (int)($q['marks'] ?? 1)));
        $sec = trim((string)($q['section_type'] ?? 'SECTION-A'));
        $ansKey = trim((string)($q['answer_key'] ?? ''));
        $optArr = !empty($q['options']) && is_array($q['options']) ? $q['options'] : [];
        $optJson = !empty($optArr) ? json_encode($optArr, JSON_UNESCAPED_UNICODE) : null;
        $formula = (string)($q['formula_latex'] ?? '');
        $img = (string)($q['image_url'] ?? '');
        $hasFormula = (!empty($q['has_formula']) || strpos($qText, '$') !== false || $formula !== '') ? 1 : 0;
        $lang = trim((string)($q['language'] ?? $bankLanguage));

        $item = [
            'q_number' => $numCounter++,
            'unit_no' => $unit,
            'sub_unit' => $subUnit,
            'section_type' => $sec,
            'marks' => $marks,
            'k_level' => $kNorm,
            'co_level' => $coNorm,
            'question_text' => $qText,
            'answer_key' => $ansKey,
            'options' => $optArr,
            'options_json' => $optJson,
            'language' => $lang,
            'has_formula' => $hasFormula,
            'formula_latex' => $formula,
            'image_url' => $img,
            'replace_action' => $action,
            'existing_id' => $q['duplicate_info']['existing_id'] ?? null
        ];

        // Track duplicates
        $h = hash('sha256', qps_question_norm($qText));
        if (isset($seen[$h])) {
            $duplicateWarnings[] = "Question #{$item['q_number']} duplicates Question #{$seen[$h]} in this upload.";
        } else {
            $seen[$h] = $item['q_number'];
        }

        $finalQuestions[] = $item;
    }

    if (empty($finalQuestions)) {
        throw new RuntimeException('No valid questions remain after duplicate filtering.');
    }

    // -------------------------------------------------------------
    // HIERARCHICAL COMPRESSED FILE STORAGE
    // -------------------------------------------------------------
    $semNum = hcc_sem_num($semester);
    $safeYear = preg_replace('/[^a-zA-Z0-9_-]/', '_', $academicYear ?: '2026-2027');
    $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $paperCode);
    $safeSem = 'sem_' . $semNum;

    $hierarchicalDir = BASE_PATH . '/storage/uploads/' . $safeCode . '/' . $safeSem . '/' . $safeYear;
    if (!is_dir($hierarchicalDir)) {
        @mkdir($hierarchicalDir, 0777, true);
    }

    $source = $_SESSION['qps_upload_token'] ?? [];
    $sourceName = $source['name'] ?? ($input['source_file_name'] ?? 'Question_Bank_' . $paperCode);
    $sourceExt = $source['ext'] ?? strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
    $ocrUsed = !empty($source['ocr_used']) ? 1 : 0;
    $ocrLang = $source['ocr_language'] ?? 'eng';

    $archiveRelPath = '';
    $timestamp = date('Ymd_His');

    if (!empty($source['path']) && is_file($source['path'])) {
        $safeSrcBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($sourceName));
        $destFile = $hierarchicalDir . '/' . $safeCode . '_' . $safeSem . '_' . $timestamp . '_' . $safeSrcBase;
        if (@copy($source['path'], $destFile)) {
            $archiveRelPath = 'storage/uploads/' . $safeCode . '/' . $safeSem . '/' . $safeYear . '/' . basename($destFile);
        }
    }

    // Generate JSON archive
    $jsonArchiveName = $safeCode . '_' . $safeSem . '_' . $safeYear . '_bank.json';
    $jsonArchivePath = $hierarchicalDir . '/' . $jsonArchiveName;

    $jsonPayload = [
        'schema_version' => '3.0',
        'institution' => COLLEGE_NAME,
        'metadata' => [
            'staff_code' => $user['staff_code'] ?? 'STAFF',
            'staff_name' => $user['name'] ?? '',
            'dept_code' => $deptCode,
            'dept_name' => $deptName,
            'paper_code' => $paperCode,
            'course_title' => $courseTitle,
            'semester' => $semester,
            'academic_year' => $academicYear,
            'exam_type' => $examType,
            'regulation' => $regulation,
            'degree_level' => $degreeLevel,
            'max_marks' => $maxMarks,
            'language' => $bankLanguage,
            'total_questions' => count($finalQuestions),
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s')
        ],
        'questions' => $finalQuestions
    ];

    $jsonRaw = json_encode($jsonPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    @file_put_contents($jsonArchivePath, $jsonRaw);

    if (function_exists('gzencode')) {
        $gzPath = $jsonArchivePath . '.gz';
        @file_put_contents($gzPath, gzencode($jsonRaw, 9));
    }

    if (!$archiveRelPath) {
        $archiveRelPath = 'storage/uploads/' . $safeCode . '/' . $safeSem . '/' . $safeYear . '/' . $jsonArchiveName;
    }

    $contentHash = hash('sha256', $jsonRaw);

    // -------------------------------------------------------------
    // DATABASE TRANSACTION & PERSISTENCE
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');

    // Find existing question bank
    if (!$bankId) {
        $stFind = $pdo->prepare("SELECT id, root_bank_id, version_no FROM question_banks WHERE UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ? ORDER BY id DESC LIMIT 1");
        $stFind->execute([$paperCode, $semester, $academicYear]);
        $found = $stFind->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            $bankId = (int)$found['id'];
        }
    }

    // Existing pool is retained for append/replace uploads. Existing question IDs
    // are preserved so question-usage history from previous examination years remains valid.
    $existingRows = [];
    if ($bankId) {
        $stExisting = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
        $stExisting->execute([$bankId]);
        $existingRows = $stExisting->fetchAll(PDO::FETCH_ASSOC);
    }

    $existingById = [];
    $maxExistingNumber = 0;
    foreach ($existingRows as $er) {
        $existingById[(int)$er['id']] = $er;
        $maxExistingNumber = max($maxExistingNumber, (int)$er['q_number']);
    }

    // Merge uploaded questions into the current pool.
    $merged = [];
    $replacedIds = [];
    foreach ($existingRows as $er) {
        $merged[(int)$er['id']] = [
            'id' => (int)$er['id'],
            'q_number' => (int)$er['q_number'],
            'unit_no' => (int)$er['unit_no'],
            'sub_unit' => $er['sub_unit'] ?: '1.1',
            'section_type' => $er['section_type'] ?: 'SECTION-A',
            'marks' => (int)$er['marks'],
            'k_level' => $er['k_level'] ?: 'K1',
            'co_level' => $er['co_level'] ?: ('CO' . preg_replace('/\D+/', '', (string)$er['k_level'])),
            'question_text' => $er['question_text'],
            'answer_key' => $er['answer_key'] ?: '',
            'options' => json_decode((string)($er['options_json'] ?? ''), true) ?: [],
            'options_json' => $er['options_json'] ?: null,
            'language' => $er['language'] ?: $bankLanguage,
            'has_formula' => (int)($er['has_formula'] ?? 0),
            'formula_latex' => $er['formula_latex'] ?: '',
            'image_url' => $er['image_url'] ?: '',
            'replace_action' => 'existing'
        ];
    }

    foreach ($finalQuestions as $fq) {
        $existingId = (int)($fq['existing_id'] ?? 0);
        $action = $fq['replace_action'] ?? 'append';
        if ($action === 'skip') continue;

        if ($action === 'replace' && $existingId > 0 && isset($merged[$existingId])) {
            $fq['id'] = $existingId;
            $fq['q_number'] = $merged[$existingId]['q_number'];
            $merged[$existingId] = $fq;
            $replacedIds[$existingId] = true;
        } else {
            $fq['id'] = null;
            $fq['q_number'] = ++$maxExistingNumber;
            $merged[] = $fq;
        }
    }

    // Sort by question number and ensure the JSON/relational order is deterministic.
    $finalQuestions = array_values($merged);
    usort($finalQuestions, static function($a,$b){ return ((int)$a['q_number']) <=> ((int)$b['q_number']); });

    if (!$bankId) {
        $version = 1;
        $stIns = $pdo->prepare("INSERT INTO question_banks (
            staff_code, dept_code, dept_name, paper_code, course_title,
            semester, academic_year, exam_type, regulation, degree_level,
            max_marks, total_questions, status, questions_json, created_at,
            updated_at, submitted_at, source_format, source_file_name,
            source_path, archive_path, language, ocr_language, ocr_used,
            content_hash, version_no
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        // JSON is finalized after relational IDs are populated; save a provisional snapshot now.
        $stIns->execute([
            $user['staff_code'] ?? 'STAFF', $deptCode, $deptName, $paperCode, $courseTitle,
            $semester, $academicYear, $examType, $regulation, $degreeLevel,
            $maxMarks, count($finalQuestions), $status, $jsonRaw, $now,
            $now, ($status !== 'Draft' ? $now : null), $sourceExt, $sourceName,
            $archiveRelPath, $archiveRelPath, $bankLanguage, $ocrLang, $ocrUsed,
            $contentHash, 1
        ]);
        $bankId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE question_banks SET root_bank_id = ? WHERE id = ?")->execute([$bankId, $bankId]);
        $version = 1;
    } else {
        $stOld = $pdo->prepare("SELECT version_no, root_bank_id FROM question_banks WHERE id = ?");
        $stOld->execute([$bankId]);
        $old = $stOld->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new RuntimeException('Question bank not found. Please refresh the repository and upload again.');
        $version = (int)($old['version_no'] ?? 1) + 1;
        $rootId = (int)($old['root_bank_id'] ?: $bankId);

        // IMPORTANT: no duplicate `language = ...` assignment. This was the source of
        // the HY093 error in the previous build.
        $stUp = $pdo->prepare("UPDATE question_banks SET
            staff_code = ?, dept_code = ?, dept_name = ?, paper_code = ?,
            course_title = ?, semester = ?, academic_year = ?, exam_type = ?,
            regulation = ?, degree_level = ?, max_marks = ?, total_questions = ?,
            status = ?, updated_at = ?, submitted_at = ?,
            source_format = ?, source_file_name = ?, source_path = ?, archive_path = ?,
            language = ?, ocr_language = ?, ocr_used = ?, content_hash = ?,
            version_no = ?, root_bank_id = ?
            WHERE id = ?");
        $stUp->execute([
            $user['staff_code'] ?? 'STAFF', $deptCode, $deptName, $paperCode,
            $courseTitle, $semester, $academicYear, $examType,
            $regulation, $degreeLevel, $maxMarks, count($finalQuestions),
            $status, $now, ($status !== 'Draft' ? $now : null),
            $sourceExt, $sourceName, $archiveRelPath, $archiveRelPath,
            $bankLanguage, $ocrLang, $ocrUsed, $contentHash, $version, $rootId, $bankId
        ]);
    }

    // Upsert relational questions without destroying old IDs used by examination history.
    $stQIns = $pdo->prepare("INSERT INTO questions (
        bank_id, q_number, unit_no, sub_unit, section_type, question_text,
        marks, k_level, co_level, has_formula, formula_latex, image_url,
        options_json, answer_key, language, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stQUpd = $pdo->prepare("UPDATE questions SET
        q_number=?, unit_no=?, sub_unit=?, section_type=?, question_text=?, marks=?,
        k_level=?, co_level=?, has_formula=?, formula_latex=?, image_url=?,
        options_json=?, answer_key=?, language=? WHERE id=? AND bank_id=?");

    $activeIds=[];
    foreach ($finalQuestions as &$fq) {
        $opts = !empty($fq['options']) && is_array($fq['options']) ? $fq['options'] : [];
        $fq['options_json'] = !empty($opts) ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
        $fq['co_level'] = 'CO' . preg_replace('/\D+/', '', (string)$fq['k_level']);
        if (!empty($fq['id']) && isset($existingById[(int)$fq['id']])) {
            $stQUpd->execute([
                $fq['q_number'],$fq['unit_no'],$fq['sub_unit'],$fq['section_type'],$fq['question_text'],$fq['marks'],
                $fq['k_level'],$fq['co_level'],$fq['has_formula'],$fq['formula_latex'],$fq['image_url'],
                $fq['options_json'],$fq['answer_key'],$fq['language'],(int)$fq['id'],$bankId
            ]);
            $qId=(int)$fq['id'];
        } else {
            $stQIns->execute([
                $bankId,$fq['q_number'],$fq['unit_no'],$fq['sub_unit'],$fq['section_type'],$fq['question_text'],
                $fq['marks'],$fq['k_level'],$fq['co_level'],$fq['has_formula'],$fq['formula_latex'],$fq['image_url'],
                $fq['options_json'],$fq['answer_key'],$fq['language'],$now
            ]);
            $qId=(int)$pdo->lastInsertId();
            $fq['id']=$qId;
        }
        $activeIds[$qId]=true;
    }
    unset($fq);

    // Refresh answer-key rows only; question rows/IDs remain stable.
    $pdo->prepare("DELETE FROM answer_keys WHERE bank_id = ?")->execute([$bankId]);
    $stAK = $pdo->prepare("INSERT INTO answer_keys (
        course_code, question_id, bank_id, q_number, unit_no, sub_unit,
        section_type, k_level, co_level, answer_key, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($finalQuestions as $fq) {
        if ($fq['answer_key'] === '') continue;
        $stAK->execute([
            $paperCode,(int)$fq['id'],$bankId,$fq['q_number'],$fq['unit_no'],$fq['sub_unit'],
            $fq['section_type'],$fq['k_level'],$fq['co_level'],$fq['answer_key'],$now,$now
        ]);
    }

    // Save final questions JSON with populated relational IDs.
    $jsonPayload['metadata']['total_questions']=count($finalQuestions);
    $jsonPayload['questions']=$finalQuestions;
    $finalJsonStr=json_encode($jsonPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    @file_put_contents($jsonArchivePath, $finalJsonStr);
    if (function_exists('gzencode')) @file_put_contents($jsonArchivePath . '.gz', gzencode($finalJsonStr, 9));
    $finalContentHash=hash('sha256',$finalJsonStr);
    $pdo->prepare("UPDATE question_banks SET questions_json = ?, content_hash = ?, total_questions = ?, version_no = ? WHERE id = ?")->execute([$finalJsonStr,$finalContentHash,count($finalQuestions),$version,$bankId]);

    $pdo->commit();

    if (!empty($source['path'])) @unlink($source['path']);
    unset($_SESSION['qps_upload_token']);

    $statusMsg = ($status === 'Submitted to COE')
        ? 'Question Bank approved and submitted to COE Office for paper generation!'
        : (($status === 'Submitted to HOD')
            ? 'Question Bank submitted to Head of Department (HOD) for review and verification.'
            : 'Question Bank draft saved successfully.');

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'bank_id' => $bankId,
        'version_no' => $version,
        'status' => $status,
        'total_questions' => count($finalQuestions),
        'archive_path' => $archiveRelPath,
        'warnings' => $duplicateWarnings,
        'message' => $statusMsg
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
