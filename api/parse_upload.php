<?php
/**
 * AJAX Handler for Question Bank Upload & Extraction
 * Auto Language Detection (English, Tamil, French)
 * Deep Duplicate Identification (Internal & DB cross-reference)
 * Answer Key Extraction
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/question_importer.php';
require_once __DIR__ . '/../includes/system.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();

function qps_text_signature(string $s): string {
    $s = strip_tags($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

try {
    qps_ensure_aux_schema($pdo);
    $maxUploadMb = MAX_UPLOAD_MB;
    $maxUploadBytes = $maxUploadMb * 1048576;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST method required.');
    if (empty($_FILES['question_file']) || $_FILES['question_file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please select a valid question-bank file (DOCX, PDF, CSV, XLSX, JSON).');
    }
    
    $file = $_FILES['question_file'];
    if ((int)$file['size'] > $maxUploadBytes) {
        throw new RuntimeException('Maximum upload size is ' . ($maxUploadBytes / 1048576) . ' MB.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['csv', 'json', 'xlsx', 'docx', 'pdf', 'txt', 'odt', 'zip', 'png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Unsupported file format. Allowed: ' . implode(', ', $allowed));
    }

    $ocrLang = preg_replace('/[^a-zA-Z0-9+_-]/', '', $_POST['ocr_language'] ?? 'eng') ?: 'eng';
    $forceOcr = !empty($_POST['force_ocr']);
    $paperCode = strtoupper(trim((string)($_POST['paper_code'] ?? '')));
    $semester = trim((string)($_POST['semester'] ?? 'Semester 1'));
    $examType = trim((string)($_POST['exam_type'] ?? 'Odd Semester End Examination'));

    // Load the exact course blueprint section marks so a DOCX without explicit
    // mark labels still receives the approved A/B/C/D marks for that course.
    $sectionMarks = ['A'=>1,'B'=>5,'C'=>10,'D'=>10];
    if ($paperCode !== '') {
        try {
            $bp = $pdo->prepare("SELECT sections_config FROM blueprints WHERE UPPER(paper_code)=? AND (semester=? OR semester IS NULL OR semester='') AND (exam_type=? OR exam_type IS NULL OR exam_type='') ORDER BY id DESC LIMIT 1");
            $bp->execute([$paperCode, $semester, $examType]);
            $cfg = $bp->fetchColumn();
            $arr = json_decode((string)$cfg, true);
            if (is_array($arr)) foreach ($arr as $sec) {
                $code = strtoupper(substr((string)($sec['code'] ?? ''),0,1));
                $m = (int)($sec['marks_each'] ?? 0);
                if (in_array($code,['A','B','C','D'],true) && $m > 0) $sectionMarks[$code]=$m;
            }
        } catch (Throwable $e) {}
    }

    $tmp = PRIVATE_STORAGE_DIR . '/tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
    $token = bin2hex(random_bytes(20));
    $tmpPath = $tmp . '/' . $token . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
        throw new RuntimeException('Could not store the upload in temporary storage.');
    }

    try {
        $result = qps_parse_file($tmpPath, $ext, $ocrLang, $forceOcr, $sectionMarks);
    } catch (Throwable $e) {
        @unlink($tmpPath);
        throw $e;
    }

    $questions = $result['questions'] ?? [];
    if (count($questions) < 1) {
        throw new RuntimeException('No numbered questions could be extracted from the uploaded file.');
    }

    // Auto-detect Language across extracted text sample with filename hints
    $combinedSample = '';
    foreach (array_slice($questions, 0, 30) as $q) {
        $combinedSample .= ' ' . ($q['question_text'] ?? '');
    }
    $fname = strtoupper($file['name']);
    if (strpos($fname, 'ENGLISH') !== false || strpos($fname, 'ENG_') !== false || strpos($fname, 'ENG-') !== false || strpos($fname, 'ENG.') !== false || strpos($fname, 'ENG ') !== false) {
        $detectedLang = 'en';
    } elseif (strpos($fname, 'TAMIL') !== false || strpos($fname, 'TAM_') !== false || strpos($fname, 'TAM-') !== false) {
        $detectedLang = 'ta';
    } elseif (strpos($fname, 'FRENCH') !== false || strpos($fname, 'FRA_') !== false || strpos($fname, 'FRA-') !== false) {
        $detectedLang = 'fr';
    } elseif (strpos($fname, 'HINDI') !== false || strpos($fname, 'HIN_') !== false || strpos($fname, 'HIN-') !== false) {
        $detectedLang = 'hi';
    } else {
        $detectedLang = qps_detect_language($combinedSample);
    }

    // Apply detected language to all questions if not specified
    foreach ($questions as &$q) {
        if (empty($q['language'])) $q['language'] = $detectedLang;
    }
    unset($q);

    // Fetch existing database questions for this paper code for cross-duplicate checking
    $dbExistingQuestions = [];
    if ($paperCode !== '') {
        try {
            $stEx = $pdo->prepare("SELECT q.id, q.q_number, q.unit_no, q.sub_unit, q.section_type, q.k_level, q.question_text, q.marks, qb.academic_year
                                   FROM questions q
                                   JOIN question_banks qb ON qb.id = q.bank_id
                                   WHERE UPPER(qb.paper_code) = ? ORDER BY q.id ASC");
            $stEx->execute([$paperCode]);
            $dbExistingQuestions = $stEx->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // Duplicate detection (Internal within upload + External in Database)
    $seenSignatures = [];
    $duplicates = [];
    $duplicateCount = 0;

    // Index DB questions by normalized signature
    $dbSignatures = [];
    foreach ($dbExistingQuestions as $dbq) {
        $sig = qps_text_signature($dbq['question_text']);
        if ($sig !== '') {
            $dbSignatures[$sig] = $dbq;
        }
    }

    foreach ($questions as $idx => &$q) {
        $sig = qps_text_signature($q['question_text']);
        $q['is_duplicate'] = false;
        $q['duplicate_info'] = null;
        $q['replace_action'] = 'append'; // 'append', 'replace', or 'skip'

        if ($sig === '') continue;

        // Check internal duplicate
        if (isset($seenSignatures[$sig])) {
            $q['is_duplicate'] = true;
            $q['duplicate_info'] = [
                'type' => 'internal',
                'matched_q_number' => $seenSignatures[$sig]['q_number'],
                'section' => $seenSignatures[$sig]['section_type'],
                'k_level' => $seenSignatures[$sig]['k_level'],
                'question_text' => $seenSignatures[$sig]['question_text'],
                'message' => 'Duplicates Question #' . $seenSignatures[$sig]['q_number'] . ' in this uploaded batch'
            ];
            $duplicates[] = [
                'q_number' => $q['q_number'],
                'section' => $q['section_type'],
                'k_level' => $q['k_level'],
                'question_text' => $q['question_text'],
                'matched_with' => 'Question #' . $seenSignatures[$sig]['q_number'] . ' (in current file)'
            ];
            $duplicateCount++;
        } elseif (isset($dbSignatures[$sig])) {
            // Check database duplicate
            $match = $dbSignatures[$sig];
            $q['is_duplicate'] = true;
            $q['duplicate_info'] = [
                'type' => 'database',
                'existing_id' => $match['id'],
                'matched_q_number' => $match['q_number'],
                'section' => $match['section_type'],
                'k_level' => $match['k_level'],
                'question_text' => $match['question_text'],
                'academic_year' => $match['academic_year'] ?? 'Previous Session',
                'message' => 'Matches existing DB Question #' . $match['q_number'] . ' (' . ($match['academic_year'] ?? 'Existing') . ')'
            ];
            $duplicates[] = [
                'q_number' => $q['q_number'],
                'section' => $q['section_type'],
                'k_level' => $q['k_level'],
                'question_text' => $q['question_text'],
                'matched_with' => 'Database Q#' . $match['q_number'] . ' (ID: ' . $match['id'] . ')'
            ];
            $duplicateCount++;
        } else {
            $seenSignatures[$sig] = [
                'q_number' => $q['q_number'],
                'section_type' => $q['section_type'],
                'k_level' => $q['k_level'],
                'question_text' => $q['question_text']
            ];
        }
    }
    unset($q);

    $_SESSION['qps_upload_token'] = [
        'token' => $token,
        'path' => $tmpPath,
        'name' => $file['name'],
        'ext' => $ext,
        'size' => (int)$file['size'],
        'ocr_language' => $ocrLang,
        'ocr_used' => !empty($result['ocr_used']),
        'detected_language' => $detectedLang,
        'created' => time()
    ];

    echo json_encode([
        'success' => true,
        'token' => $token,
        'detected_language' => $detectedLang,
        'language_label' => ($detectedLang === 'ta') ? 'Tamil (தமிழ்)' : (($detectedLang === 'fr') ? 'French (Français)' : (($detectedLang === 'hi') ? 'Hindi (हिन्दी)' : 'English')),
        'source' => [
            'name' => $file['name'],
            'format' => strtoupper($ext),
            'size' => $file['size'],
            'ocr_used' => !empty($result['ocr_used']),
            'ocr_language' => $ocrLang
        ],
        'questions' => $questions,
        'question_count' => count($questions),
        'duplicate_count' => $duplicateCount,
        'duplicates' => $duplicates,
        'has_duplicates' => $duplicateCount > 0,
        'existing_db_questions_count' => count($dbExistingQuestions),
        'message' => 'Successfully extracted ' . count($questions) . ' questions. ' . ($duplicateCount > 0 ? "({$duplicateCount} duplicates identified - replace or append options available)" : "(Zero duplicates found)")
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
