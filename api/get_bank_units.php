<?php
/**
 * API: Get the cumulative, verified Question Bank pool for Blueprint Sync.
 *
 * Important: a course may have several uploads/versions. Blueprint Sync must
 * not use only the newest question_banks row. It aggregates all non-draft,
 * HOD/COE-cleared banks for the selected course/context and reads the
 * relational questions table as the authoritative cumulative pool.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

$paperCode = strtoupper(trim((string)($_GET['paper_code'] ?? $_POST['paper_code'] ?? '')));
$semester = trim((string)($_GET['semester'] ?? $_POST['semester'] ?? ''));
$academicYear = trim((string)($_GET['academic_year'] ?? $_POST['academic_year'] ?? ''));
$examType = trim((string)($_GET['exam_type'] ?? $_POST['exam_type'] ?? ''));

if ($paperCode === '') {
    echo json_encode(['success'=>false,'error'=>'Course code is required.']);
    exit;
}

$pdo = getDBConnection();

try {
    // Include every submitted/approved bank for this course. Draft banks are
    // intentionally excluded so a half-uploaded bank cannot enter a blueprint.
    $sql = "SELECT id, paper_code, course_title, dept_code, semester, academic_year,
                   exam_type, status, hod_status, total_questions, updated_at
            FROM question_banks
            WHERE UPPER(paper_code)=UPPER(?)
              AND (UPPER(COALESCE(status,'')) IN ('SUBMITTED TO COE','APPROVED','HOD APPROVED','VERIFIED','COE VERIFIED','PUBLISHED')
                   OR UPPER(COALESCE(hod_status,''))='APPROVED')";
    $params = [$paperCode];
    if ($semester !== '') { $sql .= ' AND (semester = ? OR semester IS NULL OR semester = \'\')'; $params[] = $semester; }
    if ($academicYear !== '') { $sql .= ' AND (academic_year = ? OR academic_year IS NULL OR academic_year = \'\')'; $params[] = $academicYear; }
    if ($examType !== '') { $sql .= ' AND (exam_type = ? OR exam_type IS NULL OR exam_type = \'\')'; $params[] = $examType; }
    $sql .= ' ORDER BY id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $banks = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$banks) {
        $cSt = $pdo->prepare("SELECT coursecode, coursetitle, dept_code, maxmark FROM courses WHERE UPPER(coursecode)=UPPER(?) LIMIT 1");
        $cSt->execute([$paperCode]);
        $course = $cSt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success'=>true,
            'found'=>false,
            'paper_code'=>$paperCode,
            'course'=>$course ?: null,
            'bank_count'=>0,
            'total_questions'=>0,
            'units'=>[],
            'sub_units'=>[],
            'message'=>'No submitted/verified question bank found for '.$paperCode.'.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $bankIds = array_values(array_unique(array_map(static fn($b)=>(int)$b['id'], $banks)));
    $ph = implode(',', array_fill(0, count($bankIds), '?'));

    // Relational questions are authoritative. If an older installation has a
    // bank JSON snapshot but no relational rows, fall back to that snapshot.
    $qst = $pdo->prepare("SELECT q.* FROM questions q WHERE q.bank_id IN ($ph) ORDER BY q.bank_id ASC, q.q_number ASC, q.id ASC");
    $qst->execute($bankIds);
    $questions = $qst->fetchAll(PDO::FETCH_ASSOC);

    if (!$questions) {
        foreach ($banks as $bank) {
            $arr = json_decode((string)($bank['questions_json'] ?? ''), true);
            if (!is_array($arr)) continue;
            $arr = $arr['questions'] ?? $arr;
            if (!is_array($arr)) continue;
            foreach ($arr as $q) {
                if (is_array($q)) {
                    $q['bank_id'] = (int)$bank['id'];
                    $questions[] = $q;
                }
            }
        }
    }

    // De-duplicate the cumulative pool using the complete logical question
    // payload. Same stem + different MCQ options is not a duplicate.
    $seen = [];
    $pool = [];
    foreach ($questions as $q) {
        $text = trim(strip_tags((string)($q['question_text'] ?? '')));
        if ($text === '') continue;
        $payload = [
            $text,
            (string)($q['options_json'] ?? ''),
            (string)($q['answer_key'] ?? ''),
            (string)($q['assertion'] ?? ''),
            (string)($q['reason'] ?? ''),
            (string)($q['sub_questions'] ?? '')
        ];
        $sig = mb_strtolower(preg_replace('/\s+/u', ' ', implode(' ', $payload)), 'UTF-8');
        $hash = hash('sha256', $sig);
        if (isset($seen[$hash])) continue;
        $seen[$hash] = true;
        $pool[] = $q;
    }

    $unitMap = [];
    $subUnitMap = [];
    foreach ($pool as $q) {
        $uRaw = trim((string)($q['unit_no'] ?? $q['unit'] ?? ''));
        if (preg_match('/([1-5])/', $uRaw, $um)) $u = $um[1]; else $u = '1';
        $su = trim((string)($q['sub_unit'] ?? $q['subunit'] ?? ''));
        if (!preg_match('/^[1-5]\.[0-9]+$/', $su)) $su = $u . '.1';
        $k = strtoupper(trim((string)($q['k_level'] ?? 'K1')));
        $marks = (int)($q['marks'] ?? 0);
        $section = strtoupper(trim((string)($q['section_type'] ?? '')));

        if (!isset($unitMap[$u])) {
            $unitMap[$u] = ['unit'=>$u,'count'=>0,'k_levels'=>[],'marks_breakdown'=>[],'question_types'=>[]];
        }
        $unitMap[$u]['count']++;
        if ($k && !in_array($k,$unitMap[$u]['k_levels'],true)) $unitMap[$u]['k_levels'][]=$k;
        if ($marks>0) $unitMap[$u]['marks_breakdown'][(string)$marks]=($unitMap[$u]['marks_breakdown'][(string)$marks]??0)+1;
        if ($section && !in_array($section,$unitMap[$u]['question_types'],true)) $unitMap[$u]['question_types'][]=$section;

        if (!isset($subUnitMap[$su])) $subUnitMap[$su]=['sub_unit'=>$su,'unit'=>$u,'count'=>0,'k_levels'=>[],'marks_breakdown'=>[],'question_types'=>[]];
        $subUnitMap[$su]['count']++;
        if ($k && !in_array($k,$subUnitMap[$su]['k_levels'],true)) $subUnitMap[$su]['k_levels'][]=$k;
        if ($marks>0) $subUnitMap[$su]['marks_breakdown'][(string)$marks]=($subUnitMap[$su]['marks_breakdown'][(string)$marks]??0)+1;
        if ($section && !in_array($section,$subUnitMap[$su]['question_types'],true)) $subUnitMap[$su]['question_types'][]=$section;
    }

    uksort($unitMap, 'strnatcmp');
    uksort($subUnitMap, 'strnatcmp');
    foreach ($unitMap as &$v) { sort($v['k_levels']); sort($v['question_types']); }
    unset($v);
    foreach ($subUnitMap as &$v) { sort($v['k_levels']); sort($v['question_types']); }
    unset($v);

    $first = $banks[0];
    echo json_encode([
        'success'=>true,
        'found'=>true,
        'paper_code'=>$paperCode,
        'course_title'=>$first['course_title'] ?? $paperCode,
        'dept_code'=>$first['dept_code'] ?? '',
        'semester'=>$semester ?: ($first['semester'] ?? ''),
        'academic_year'=>$academicYear ?: ($first['academic_year'] ?? ''),
        'exam_type'=>$examType ?: ($first['exam_type'] ?? ''),
        'bank_count'=>count($banks),
        'bank_ids'=>$bankIds,
        'total_questions'=>count($pool),
        'source_banks'=>array_map(static function($b){
            return ['id'=>(int)$b['id'],'status'=>$b['status'],'hod_status'=>$b['hod_status'] ?? null,'total_questions'=>(int)$b['total_questions']];
        }, $banks),
        'units'=>array_values($unitMap),
        'sub_units'=>array_values($subUnitMap)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Failed to analyze cumulative question bank: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
