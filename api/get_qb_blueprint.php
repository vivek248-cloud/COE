<?php
/**
 * AJAX API to fetch Question Bank Blueprint for a course
 * Holy Cross College (Autonomous) - Examination System
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireAuth();
$pdo = getDBConnection();

try {
    qps_ensure_aux_schema($pdo);
    $paperCode = strtoupper(trim((string)($_GET['paper_code'] ?? '')));

    if ($paperCode === '') {
        echo json_encode([
            'success' => true,
            'has_custom_blueprint' => false,
            'blueprint' => null,
            'default_pool_target' => 275,
            'message' => 'No paper code provided, using standard autonomous 275-question pool.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare("SELECT * FROM qps_question_bank_blueprints WHERE UPPER(paper_code) = ? AND is_published = 1 ORDER BY id DESC LIMIT 1");
    $st->execute([$paperCode]);
    $bp = $st->fetch(PDO::FETCH_ASSOC);

    if ($bp) {
        $subunits = json_decode($bp['subunits_config'] ?? '{}', true) ?: [];
        $matrix = json_decode($bp['matrix_config'] ?? '{}', true) ?: [];

        echo json_encode([
            'success' => true,
            'has_custom_blueprint' => true,
            'blueprint_id' => (int)$bp['id'],
            'paper_code' => $bp['paper_code'],
            'course_title' => $bp['course_title'],
            'total_expected_questions' => (int)$bp['total_expected_questions'],
            'subunits_config' => $subunits,
            'matrix_config' => $matrix,
            'published_by' => $bp['published_by'],
            'updated_at' => $bp['updated_at'],
            'message' => 'Active HOD Question Bank Blueprint loaded successfully.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Default Master 275-Pool specification if no custom HOD override
    $defaultSubunits = [];
    for ($u = 1; $u <= 5; $u++) {
        for ($s = 1; $s <= 5; $s++) {
            $defaultSubunits["{$u}.{$s}"] = 11; // 25 sub-units * 11 = 275
        }
    }

    echo json_encode([
        'success' => true,
        'has_custom_blueprint' => false,
        'paper_code' => $paperCode,
        'total_expected_questions' => 275,
        'subunits_config' => $defaultSubunits,
        'matrix_config' => [
            'k_levels' => ['K1' => 75, 'K2' => 75, 'K3' => 50, 'K4' => 50, 'K5' => 25],
            'sections' => ['Section A' => 150, 'Section B' => 75, 'Section C' => 50]
        ],
        'message' => 'Using Autonomous Standard 275-Question Pool Blueprint.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
