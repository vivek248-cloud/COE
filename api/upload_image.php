<?php
/**
 * Question Diagram / Figure Image Uploader
 * Holy Cross College (Autonomous) Examination Management System
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Only POST requests are allowed.');
    }

    $fileKey = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $fileKey = 'image';
    } elseif (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
        $fileKey = 'question_image';
    } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $fileKey = 'file';
    }

    if (!$fileKey) {
        throw new RuntimeException('No valid image file uploaded or upload error occurred.');
    }

    $file = $_FILES[$fileKey];
    $origName = $file['name'];
    $tmpName = $file['tmp_name'];
    $size = $file['size'];

    // Max 10MB per diagram
    if ($size > 10 * 1024 * 1024) {
        throw new RuntimeException('Image size exceeds maximum limit of 10 MB.');
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'bmp'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Unsupported image type. Allowed: PNG, JPG, JPEG, WEBP, GIF, SVG.');
    }

    // Target directory: assets/uploads/questions/
    $uploadSubdir = '/assets/uploads/questions';
    $targetDir = dirname(__DIR__) . $uploadSubdir;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    $uniqueName = 'qimg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $uniqueName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Failed to save uploaded image file.');
    }

    $baseUrl = getBaseUrl();
    $publicUrl = $baseUrl . $uploadSubdir . '/' . $uniqueName;

    echo json_encode([
        'success' => true,
        'image_url' => $publicUrl,
        'relative_path' => $uploadSubdir . '/' . $uniqueName,
        'file_name' => $origName,
        'message' => 'Question image uploaded successfully.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
