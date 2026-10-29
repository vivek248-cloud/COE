<?php
/**
 * AJAX API to Save & Publish Question Bank Blueprint by HOD
 * Holy Cross College (Autonomous) - Examination System
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();

if (!isHOD() && !isCOE() && !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Head of Department (HOD) or COE can configure Question Bank Blueprints.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    qps_ensure_aux_schema($pdo);
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) { $rawInput = @file_get_contents('php://stdin'); }
    $input = json_decode($rawInput, true);
    if (!is_array($input)) throw new RuntimeException('Invalid JSON payload.');

    $paperCode = strtoupper(trim((string)($input['paper_code'] ?? '')));
    $courseTitle = trim((string)($input['course_title'] ?? $paperCode));
    $deptCode = trim((string)($input['dept_code'] ?? ($user['dept_code'] ?? 'GEN')));
    $semester = trim((string)($input['semester'] ?? 'Semester 1'));
    $academicYear = trim((string)($input['academic_year'] ?? DEFAULT_ACADEMIC_YEAR));
    $totalExpected = max(1, (int)($input['total_expected_questions'] ?? 275));
    $subunitsConfig = $input['subunits_config'] ?? [];
    $matrixConfig = $input['matrix_config'] ?? [];

    if ($paperCode === '') throw new RuntimeException('Course / Paper Code is required.');

    $subunitsJson = json_encode($subunitsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $matrixJson = json_encode($matrixConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = date('Y-m-d H:i:s');
    $author = $user['staff_code'] ?? $user['name'] ?? 'HOD';

    // Check if an existing published blueprint exists for this course
    $stFind = $pdo->prepare("SELECT id FROM qps_question_bank_blueprints WHERE UPPER(paper_code) = ? ORDER BY id DESC LIMIT 1");
    $stFind->execute([$paperCode]);
    $existing = $stFind->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stUp = $pdo->prepare("UPDATE qps_question_bank_blueprints SET
            dept_code = ?, course_title = ?, semester = ?, academic_year = ?,
            total_expected_questions = ?, subunits_config = ?, matrix_config = ?,
            published_by = ?, is_published = 1, updated_at = ?
            WHERE id = ?");
        $stUp->execute([
            $deptCode, $courseTitle, $semester, $academicYear,
            $totalExpected, $subunitsJson, $matrixJson,
            $author, $now, (int)$existing['id']
        ]);
        $blueprintId = (int)$existing['id'];
    } else {
        $stIns = $pdo->prepare("INSERT INTO qps_question_bank_blueprints (
            dept_code, paper_code, course_title, semester, academic_year,
            total_expected_questions, subunits_config, matrix_config,
            created_by, published_by, is_published, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        $stIns->execute([
            $deptCode, $paperCode, $courseTitle, $semester, $academicYear,
            $totalExpected, $subunitsJson, $matrixJson,
            $author, $author, $now, $now
        ]);
        $blueprintId = (int)$pdo->lastInsertId();
    }

    qps_audit($pdo, 'SAVE_QB_BLUEPRINT', 'BLUEPRINT', (string)$blueprintId, [
        'paper_code' => $paperCode,
        'total_expected' => $totalExpected,
        'published_by' => $author
    ]);

    echo json_encode([
        'success' => true,
        'blueprint_id' => $blueprintId,
        'paper_code' => $paperCode,
        'total_expected_questions' => $totalExpected,
        'message' => "Question Bank Blueprint for {$paperCode} successfully published! Teaching staff will validate against this blueprint when 'Use Blueprint' is ON."
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
