<?php
/**
 * Official Question Paper DOCX Download Endpoint
 * Holy Cross College (Autonomous) QPS
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();
$pdo = getDBConnection();

$paperId = isset($_GET['paper_id']) ? intval($_GET['paper_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
if (!$paperId) {
    http_response_code(400);
    die('Question paper ID required.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM generated_papers WHERE id = ?");
    $stmt->execute([$paperId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        die('Generated paper not found.');
    }

    $paper = json_decode($row['paper_data_json'], true) ?: [];
    $paper['id'] = $row['id'];
    $paperCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $paper['paper_code'] ?? ($row['paper_code'] ?? 'HCC_PAPER'));
    $setName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $paper['set_name'] ?? ($row['set_name'] ?? 'SET_A'));

    // Create temporary files
    $tmpDir = sys_get_temp_dir() . '/hcc_docx_' . uniqid();
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

    $jsonFile = $tmpDir . '/paper_' . $paperId . '.json';
    $docxFile = $tmpDir . '/' . $paperCode . '_' . $setName . '.docx';

    file_put_contents($jsonFile, json_encode($paper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $cmd = 'python3 ' . escapeshellarg(__DIR__ . '/../docx_generator.py') . ' ' . escapeshellarg($jsonFile) . ' ' . escapeshellarg($docxFile);
    exec($cmd . ' 2>&1', $output, $retCode);

    if ($retCode !== 0 || !file_exists($docxFile)) {
        http_response_code(500);
        die('Error rendering DOCX: ' . implode("\n", $output));
    }

    $filename = $paperCode . '_' . $setName . '_HolyCrossCollege.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($docxFile));
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');

    readfile($docxFile);

    @unlink($jsonFile);
    @unlink($docxFile);
    @rmdir($tmpDir);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    die('Server error: ' . $e->getMessage());
}
