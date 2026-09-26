<?php
/**
 * API: Get Question Bank Units & Breakdown for Course
 * Holy Cross College (Autonomous) - OBE Blueprint Management
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$paperCode = trim($_GET['paper_code'] ?? ($_POST['paper_code'] ?? ''));
$semester = trim($_GET['semester'] ?? ($_POST['semester'] ?? ''));
$academicYear = trim($_GET['academic_year'] ?? ($_POST['academic_year'] ?? ''));
$examType = trim($_GET['exam_type'] ?? ($_POST['exam_type'] ?? ''));

if (empty($paperCode)) {
    echo json_encode(['success' => false, 'error' => 'Course code is required.']);
    exit;
}

$pdo = getDBConnection();

try {
    // 1. Fetch latest question bank for this paper_code
    $sql = "SELECT id, paper_code, course_title, dept_code, semester, academic_year, exam_type, status, total_questions, questions_json, source_path, updated_at FROM question_banks WHERE UPPER(paper_code)=UPPER(?)";
    $params = [$paperCode];
    if ($semester !== '') { $sql .= ' AND semester = ?'; $params[] = $semester; }
    if ($academicYear !== '') { $sql .= ' AND academic_year = ?'; $params[] = $academicYear; }
    if ($examType !== '') { $sql .= ' AND exam_type = ?'; $params[] = $examType; }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $bank = $st->fetch(PDO::FETCH_ASSOC);

    if (!$bank) {
        // Fallback: search courses table to confirm course exists
        $cSt = $pdo->prepare("SELECT coursecode, coursetitle, dept_code, maxmark FROM courses WHERE coursecode = ? LIMIT 1");
        $cSt->execute([$paperCode]);
        $course = $cSt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'found' => false,
            'paper_code' => $paperCode,
            'course' => $course ?: null,
            'message' => 'No uploaded question bank found for ' . $paperCode . '. Upload the question bank before creating the course-specific blueprint.',
            'units' => [],
            'sub_units' => []
        ]);
        exit;
    }

    $questions = json_decode($bank['questions_json'] ?? '[]', true);
    if (!is_array($questions)) $questions = [];

    // Aggregate by unit
    $unitMap = [];
    $subUnitMap = [];
    foreach ($questions as $q) {
        $uRaw = trim((string)($q['unit'] ?? $q['unit_no'] ?? '1'));
        $u = preg_replace('/[^0-9]/', '', $uRaw);
        if ($u === '') $u = $uRaw ?: '1';

        if (!isset($unitMap[$u])) {
            $unitMap[$u] = [
                'unit' => $u,
                'count' => 0,
                'k_levels' => [],
                'marks_breakdown' => [],
                'sample_questions' => []
            ];
        }

        $unitMap[$u]['count']++;
        $su = trim((string)($q['sub_unit'] ?? ''));
        if ($su !== '') {
            if (!isset($subUnitMap[$su])) $subUnitMap[$su] = ['sub_unit'=>$su,'unit'=>$u,'count'=>0,'k_levels'=>[],'marks_breakdown'=>[]];
            $subUnitMap[$su]['count']++;
            $sk = strtoupper(trim((string)($q['k_level'] ?? 'K1')));
            if (!in_array($sk,$subUnitMap[$su]['k_levels'],true)) $subUnitMap[$su]['k_levels'][]=$sk;
            $sm = (int)($q['marks'] ?? 0);
            if ($sm>0) $subUnitMap[$su]['marks_breakdown'][(string)$sm]=($subUnitMap[$su]['marks_breakdown'][(string)$sm]??0)+1;
        }

        $k = strtoupper(trim((string)($q['k_level'] ?? $q['bloom_level'] ?? 'K1')));
        if ($k && !in_array($k, $unitMap[$u]['k_levels'])) {
            $unitMap[$u]['k_levels'][] = $k;
        }

        $m = (int)($q['marks'] ?? 2);
        $unitMap[$u]['marks_breakdown'][(string)$m] = ($unitMap[$u]['marks_breakdown'][(string)$m] ?? 0) + 1;

        if (count($unitMap[$u]['sample_questions']) < 2) {
            $unitMap[$u]['sample_questions'][] = mb_substr(strip_tags((string)($q['question_text'] ?? '')), 0, 80);
        }
    }

    // Sort units naturally
    uksort($unitMap, 'strnatcmp');

    // Sort K-levels inside each unit
    foreach ($unitMap as &$uData) {
        sort($uData['k_levels']);
    }
    unset($uData);

    foreach ($subUnitMap as &$suData) sort($suData['k_levels']);
    unset($suData);

    echo json_encode([
        'success' => true,
        'found' => true,
        'bank_id' => $bank['id'],
        'paper_code' => $bank['paper_code'],
        'course_title' => $bank['course_title'],
        'academic_year' => $bank['academic_year'],
        'exam_type' => $bank['exam_type'],
        'status' => $bank['status'],
        'total_questions' => count($questions),
        'source_path' => $bank['source_path'] ?? '',
        'units' => array_values($unitMap),
        'sub_units' => array_values($subUnitMap)
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to analyze question bank: ' . $e->getMessage()
    ]);
}
