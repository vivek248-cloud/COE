<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Please sign in again.']);
    exit;
}
$paperCode = strtoupper(trim((string)($_GET['paper_code'] ?? '')));
if ($paperCode === '') {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Course code is required.']);
    exit;
}
$pdo = getDBConnection();
try {
    // COE users can view all courses. Teaching staff/HOD users must be allocated this course.
    if (!function_exists('isCOE') || !isCOE()) {
        $user = function_exists('getCurrentUser') ? getCurrentUser() : [];
        $staffCode = strtoupper(trim((string)($user['staff_code'] ?? '')));
        if ($staffCode === '') {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'Course access could not be verified.']);
            exit;
        }
        $access = $pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=? AND UPPER(papercode)=? LIMIT 1');
        $access->execute([$staffCode, $paperCode]);
        if (!$access->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'You are not allocated to this course.']);
            exit;
        }
    }

    $sql = "SELECT q.*, qb.status AS bank_status, qb.hod_status AS bank_hod_status
            FROM questions q
            INNER JOIN question_banks qb ON qb.id=q.bank_id
            WHERE UPPER(qb.paper_code)=?
              AND (UPPER(COALESCE(qb.status,'')) IN ('APPROVED','COE APPROVED','VERIFIED','COE VERIFIED','PUBLISHED')
                   OR UPPER(COALESCE(qb.hod_status,'')) IN ('COE APPROVED','APPROVED'))
            ORDER BY q.section_type, q.unit_no, q.q_number, q.id";
    $st = $pdo->prepare($sql);
    $st->execute([$paperCode]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $questions = [];
    foreach ($rows as $q) {
        $questions[] = [
            'id' => (int)($q['id'] ?? 0),
            'q_number' => (string)($q['q_number'] ?? ''),
            'question_text' => (string)($q['question_text'] ?? ''),
            'question_type' => (string)($q['question_type'] ?? '—'),
            'section_type' => (string)($q['section_type'] ?? ''),
            'marks' => (int)($q['marks'] ?? 0),
            'unit_no' => (string)($q['unit_no'] ?? ''),
            'language' => (string)($q['language'] ?? 'EN'),
            'status' => 'COE_APPROVED',
            'bank_status' => (string)($q['bank_status'] ?? '')
        ];
    }
    echo json_encode(['success'=>true,'questions'=>$questions], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Could not load COE-approved questions.']);
}
