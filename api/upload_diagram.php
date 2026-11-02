<?php
/**
 * AJAX API: Upload Diagram / Image for Question Entry
 * Holy Cross College (Autonomous) - Examination Management System
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (empty($_FILES['diagram_file']) || $_FILES['diagram_file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No file uploaded or upload error occurred.');
    }

    $file = $_FILES['diagram_file'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExts, true)) {
        throw new RuntimeException('Invalid image format. Allowed: JPG, PNG, GIF, SVG, WEBP.');
    }

    $targetDir = __DIR__ . '/../uploads/diagrams';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    $filename = 'diagram_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Failed to save uploaded image.');
    }

    $publicUrl = getBaseUrl() . '/uploads/diagrams/' . $filename;

    echo json_encode([
        'success' => true,
        'url' => $publicUrl,
        'filename' => $filename,
        'message' => 'Diagram uploaded successfully.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
