<?php
declare(strict_types=1);

define('PAGE_TITLE', 'My Files • COE File Manager');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/workflow.php';

qps_workflow_require_role(['COE']);
$pdo = getDBConnection();
qps_ensure_aux_schema($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS generated_paper_remarks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, paper_id BIGINT UNSIGNED NOT NULL, actor_staff_code VARCHAR(100) NOT NULL, actor_role VARCHAR(50) NULL, remarks TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_gpr_paper (paper_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$user = getCurrentUser() ?: [];

// Generated-paper recycle metadata. Keep paper JSON and usage history intact during retention.
qps_add_column_if_missing($pdo, 'generated_papers', 'deleted_at', 'DATETIME NULL');
qps_add_column_if_missing($pdo, 'generated_papers', 'deleted_by', 'VARCHAR(100) NULL');
qps_add_column_if_missing($pdo, 'generated_papers', 'restored_at', 'DATETIME NULL');
qps_add_column_if_missing($pdo, 'generated_papers', 'restored_by', 'VARCHAR(100) NULL');
qps_add_column_if_missing($pdo, 'generated_papers', 'updated_at', 'DATETIME NULL');

// Expired generated papers are purged only after the full five-day retention window.
try {
    $expired = $pdo->query("SELECT id FROM generated_papers WHERE deleted_at IS NOT NULL AND deleted_at <= (NOW() - INTERVAL 5 DAY)")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expired as $expiredPaperId) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM question_usage_history WHERE paper_id = ?')->execute([(int)$expiredPaperId]);
            $pdo->prepare('DELETE FROM generated_papers WHERE id = ? AND deleted_at IS NOT NULL AND deleted_at <= (NOW() - INTERVAL 5 DAY)')->execute([(int)$expiredPaperId]);
            if (function_exists('qps_audit')) qps_audit($pdo, 'QUESTION_PAPER_PERMANENTLY_PURGED', 'QUESTION_PAPER', (string)$expiredPaperId, ['retention_days' => 5]);
            $pdo->commit();
        } catch (Throwable $purgeError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
} catch (Throwable $schemaOrPurgeError) { /* Existing schema may be upgraded on first visit. */ }

$root = realpath(BASE_PATH . '/storage/uploads');
if (!$root) {
    @mkdir(BASE_PATH . '/storage/uploads', 0777, true);
    $root = realpath(BASE_PATH . '/storage/uploads');
}

$trashDir = BASE_PATH . '/storage/recycle_bin';
if (!is_dir($trashDir)) {
    @mkdir($trashDir, 0777, true);
}
$trashMetaFile = $trashDir . '/trash_meta.json';

// Helper: load trash items and auto-purge expired (> 5 days)
function fm_get_trash_items(string $trashDir, string $trashMetaFile): array {
    if (!file_exists($trashMetaFile)) {
        return [];
    }
    $raw = @file_get_contents($trashMetaFile);
    $items = json_decode((string)$raw, true);
    if (!is_array($items)) {
        return [];
    }

    $active = [];
    $changed = false;
    $now = time();

    foreach ($items as $it) {
        $expiresAt = (int)($it['expires_at'] ?? 0);
        $trashPath = $trashDir . DIRECTORY_SEPARATOR . ($it['trash_file_name'] ?? '');

        // Auto-purge only after the full 5-day retention period
        if ($expiresAt > 0 && $now > $expiresAt) {
            $changed = true;
            if (is_file($trashPath)) {
                @unlink($trashPath);
            } elseif (is_dir($trashPath)) {
                $subFiles = array_diff(scandir($trashPath) ?: [], ['.', '..']);
                foreach ($subFiles as $sf) {
                    @unlink($trashPath . DIRECTORY_SEPARATOR . $sf);
                }
                @rmdir($trashPath);
            }
            continue;
        }

        if (file_exists($trashPath)) {
            $active[] = $it;
        } else {
            $changed = true;
        }
    }

    if ($changed) {
        @file_put_contents($trashMetaFile, json_encode($active, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    return $active;
}

function fm_save_trash_items(string $trashMetaFile, array $items): void {
    @file_put_contents($trashMetaFile, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$trashItems = fm_get_trash_items($trashDir, $trashMetaFile);
$view = trim((string)($_GET['view'] ?? 'files'));
if (!in_array($view, ['files', 'trash', 'papers', 'papers_trash'], true)) $view = 'files';

$rel = trim((string)($_GET['path'] ?? ''), "/\\");
$rel = preg_replace('#[^a-zA-Z0-9_./\\ -]#', '', $rel);
$target = realpath($root . ($rel !== '' ? DIRECTORY_SEPARATOR . $rel : ''));
if (!$target || strpos($target, $root) !== 0 || !is_dir($target)) {
    $rel = '';
    $target = $root;
}

// CSRF token
if (empty($_SESSION['coe_fm_csrf'])) {
    $_SESSION['coe_fm_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['coe_fm_csrf'];

$msg = '';
$error = '';

// Generated question-paper actions (COE-only page; all mutations are POST + CSRF protected).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paper_action'])) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token expired. Please reload.');
        $paperId = filter_var($_POST['paper_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$paperId || $paperId < 1) throw new RuntimeException('Invalid generated paper ID.');
        $actor = (string)($user['staff_code'] ?? 'COE_OFFICE');
        $paperAction = (string)$_POST['paper_action'];
        if ($paperAction === 'paper_delete') {
            $st = $pdo->prepare("UPDATE generated_papers SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL");
            $st->execute([$actor, $paperId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('Paper was not found or is already in the recycle bin.');
            qps_audit($pdo, 'QUESTION_PAPER_DELETED', 'QUESTION_PAPER', (string)$paperId, ['deleted_by' => $actor, 'retention_days' => 5]);
            $msg = 'Generated question paper moved to Recycle Bin for 5 days.';
            $view = 'papers';
        } elseif ($paperAction === 'paper_remark_save') {
            $remark = trim((string)($_POST['paper_remarks'] ?? ''));
            if ($remark === '' || mb_strlen($remark) > 5000) throw new RuntimeException('Enter a remark up to 5,000 characters.');
            $check = $pdo->prepare('SELECT id FROM generated_papers WHERE id = ? AND deleted_at IS NULL');
            $check->execute([$paperId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Generated paper not found.');
            $ins = $pdo->prepare('INSERT INTO generated_paper_remarks (paper_id, actor_staff_code, actor_role, remarks) VALUES (?,?,?,?)');
            $ins->execute([$paperId, $actor, $user['role'] ?? 'COE', $remark]);
            qps_audit($pdo, 'QUESTION_PAPER_REMARK_ADDED', 'QUESTION_PAPER', (string)$paperId, ['actor' => $actor]);
            $msg = "Remark saved to this paper's history.";
            $view = 'papers';
        } elseif ($paperAction === 'paper_restore') {
            $st = $pdo->prepare("UPDATE generated_papers SET deleted_at = NULL, deleted_by = NULL, restored_at = NOW(), restored_by = ? WHERE id = ? AND deleted_at IS NOT NULL AND deleted_at > (NOW() - INTERVAL 5 DAY)");
            $st->execute([$actor, $paperId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('Restore period expired or paper was not found. Papers can only be restored within 5 days.');
            qps_audit($pdo, 'QUESTION_PAPER_RESTORED', 'QUESTION_PAPER', (string)$paperId, ['restored_by' => $actor]);
            $msg = 'Generated question paper restored successfully.';
            $view = 'papers_trash';
        } elseif ($paperAction === 'paper_purge') {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT id FROM generated_papers WHERE id = ? AND deleted_at IS NOT NULL AND deleted_at <= (NOW() - INTERVAL 5 DAY) FOR UPDATE");
            $st->execute([$paperId]);
            if (!$st->fetchColumn()) throw new RuntimeException('Paper is still within its 5-day recovery period and cannot be permanently deleted.');
            $pdo->prepare('DELETE FROM question_usage_history WHERE paper_id = ?')->execute([$paperId]);
            $pdo->prepare('DELETE FROM generated_papers WHERE id = ?')->execute([$paperId]);
            qps_audit($pdo, 'QUESTION_PAPER_PERMANENTLY_PURGED', 'QUESTION_PAPER', (string)$paperId, ['purged_by' => $actor]);
            $pdo->commit();
            $msg = 'Expired generated paper permanently removed.';
            $view = 'papers_trash';
        } else {
            throw new RuntimeException('Unsupported generated paper action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Handle File Upload to current directory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_file') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token expired. Please reload.');
        }
        if (empty($_FILES['file']['name'])) {
            throw new RuntimeException('No file selected for upload.');
        }

        $fileName = basename((string)$_FILES['file']['name']);
        $cleanFileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $fileName);
        $destPath = $target . DIRECTORY_SEPARATOR . $cleanFileName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
            $msg = "File \"{$cleanFileName}\" uploaded successfully.";
        } else {
            throw new RuntimeException('Failed to upload file to target directory.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Handle Move Item to Recycle Bin (4 Days Retention)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token expired. Please reload.');
        }
        $itemRel = trim((string)($_POST['item_path'] ?? ''), "/\\");
        $itemRel = preg_replace('#[^a-zA-Z0-9_./\\ -]#', '', $itemRel);
        $itemFull = realpath($root . DIRECTORY_SEPARATOR . $itemRel);

        if (!$itemFull || strpos($itemFull, $root) !== 0 || $itemFull === $root) {
            throw new RuntimeException('Invalid file path for deletion.');
        }

        $trashId = 'trash_' . time() . '_' . bin2hex(random_bytes(4));
        $baseName = basename($itemFull);
        $trashFileName = $trashId . '_' . $baseName;
        $destTrashPath = $trashDir . DIRECTORY_SEPARATOR . $trashFileName;

        $isDir = is_dir($itemFull);
        $fileSize = $isDir ? 0 : (int)@filesize($itemFull);
        $ext = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));

        if (@rename($itemFull, $destTrashPath)) {
            $trashItems[] = [
                'id' => $trashId,
                'original_rel_path' => $itemRel,
                'file_name' => $baseName,
                'trash_file_name' => $trashFileName,
                'deleted_at' => time(),
                'deleted_by' => $user['staff_code'] ?? 'COE_OFFICE',
                'expires_at' => time() + (5 * 86400), // Exactly 5 days
                'is_dir' => $isDir,
                'size' => $fileSize,
                'ext' => $ext
            ];
            fm_save_trash_items($trashMetaFile, $trashItems);
            $msg = "\"{$baseName}\" moved to Recycle Bin (Retained for 5 days).";
        } else {
            throw new RuntimeException('Failed to move item to Recycle Bin.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Handle Restore from Recycle Bin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_trash_item') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token expired. Please reload.');
        }
        $trashId = trim((string)($_POST['trash_id'] ?? ''));
        $foundIdx = -1;
        foreach ($trashItems as $idx => $ti) {
            if ($ti['id'] === $trashId) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === -1) {
            throw new RuntimeException('Recycle Bin item not found or expired.');
        }

        $itemToRestore = $trashItems[$foundIdx];
        $trashFilePath = $trashDir . DIRECTORY_SEPARATOR . $itemToRestore['trash_file_name'];
        $originalRel = trim((string)$itemToRestore['original_rel_path'], "/\\");
        $destOriginalPath = $root . DIRECTORY_SEPARATOR . $originalRel;

        if (!file_exists($trashFilePath)) {
            unset($trashItems[$foundIdx]);
            fm_save_trash_items($trashMetaFile, $trashItems);
            throw new RuntimeException('Underlying file was already purged from disk.');
        }

        $destDir = dirname($destOriginalPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0777, true);
        }

        $restored = false;
        if (is_dir($trashFilePath)) {
            if (is_dir($destOriginalPath)) {
                // Merge contents of directory
                $sub = array_diff(scandir($trashFilePath) ?: [], ['.', '..']);
                foreach ($sub as $sf) {
                    @rename($trashFilePath . DIRECTORY_SEPARATOR . $sf, $destOriginalPath . DIRECTORY_SEPARATOR . $sf);
                }
                @rmdir($trashFilePath);
                $restored = true;
            } else {
                $restored = @rename($trashFilePath, $destOriginalPath);
            }
        } else {
            if (file_exists($destOriginalPath)) {
                $pi = pathinfo($destOriginalPath);
                $destOriginalPath = $pi['dirname'] . DIRECTORY_SEPARATOR . $pi['filename'] . '_restored_' . time() . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
            }
            $restored = @rename($trashFilePath, $destOriginalPath);
            if (!$restored && @copy($trashFilePath, $destOriginalPath)) {
                @unlink($trashFilePath);
                $restored = true;
            }
        }

        if ($restored) {
            unset($trashItems[$foundIdx]);
            fm_save_trash_items($trashMetaFile, $trashItems);
            $msg = "Item \"{$itemToRestore['file_name']}\" restored successfully to original directory.";
        } else {
            throw new RuntimeException('Could not restore file to original path.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Handle Permanent Purge of Single Trash Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'purge_trash_item') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token expired.');
        }
        $trashId = trim((string)($_POST['trash_id'] ?? ''));
        $foundIdx = -1;
        foreach ($trashItems as $idx => $ti) {
            if ($ti['id'] === $trashId) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx !== -1) {
            $item = $trashItems[$foundIdx];
            if ((int)($item['expires_at'] ?? 0) > time()) {
                throw new RuntimeException('This item is still recoverable. Permanent deletion is allowed only after the 5-day retention window expires.');
            }
            $trashPath = $trashDir . DIRECTORY_SEPARATOR . $item['trash_file_name'];
            if (is_file($trashPath)) @unlink($trashPath);
            elseif (is_dir($trashPath)) {
                $sub = array_diff(scandir($trashPath) ?: [], ['.', '..']);
                foreach ($sub as $s) @unlink($trashPath . DIRECTORY_SEPARATOR . $s);
                @rmdir($trashPath);
            }
            unset($trashItems[$foundIdx]);
            fm_save_trash_items($trashMetaFile, $trashItems);
            $msg = "Item \"{$item['file_name']}\" permanently deleted.";
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Handle Empty Entire Recycle Bin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'empty_trash') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token expired.');
        }
        $remainingTrash = [];
        foreach ($trashItems as $ti) {
            if ((int)($ti['expires_at'] ?? 0) > time()) { $remainingTrash[] = $ti; continue; }
            $p = $trashDir . DIRECTORY_SEPARATOR . ($ti['trash_file_name'] ?? '');
            if (is_file($p)) @unlink($p);
            elseif (is_dir($p)) {
                $sub = array_diff(scandir($p) ?: [], ['.', '..']);
                foreach ($sub as $s) { $child = $p . DIRECTORY_SEPARATOR . $s; if (is_file($child)) @unlink($child); }
                @rmdir($p);
            }
        }
        $trashItems = $remainingTrash;
        fm_save_trash_items($trashMetaFile, $trashItems);
        $msg = $remainingTrash ? 'Expired items purged. Recoverable items remain in the Recycle Bin until their 5-day window ends.' : 'Expired recycle-bin items purged successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Handle Create New Folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_folder') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token expired.');
        }
        $folderName = trim((string)($_POST['folder_name'] ?? ''));
        $cleanFolderName = preg_replace('/[^a-zA-Z0-9_ -]/', '_', $folderName);
        if ($cleanFolderName === '') {
            throw new RuntimeException('Invalid folder name.');
        }
        $newDir = $target . DIRECTORY_SEPARATOR . $cleanFolderName;
        if (is_dir($newDir)) {
            throw new RuntimeException('A folder with that name already exists.');
        }
        @mkdir($newDir, 0777, true);
        $msg = "Folder \"{$cleanFolderName}\" created successfully.";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Safe download endpoint
if (isset($_GET['download'])) {
    $drel = trim((string)$_GET['download'], "/\\");
    $drel = preg_replace('#[^a-zA-Z0-9_./\\ -]#', '', $drel);
    $file = realpath($root . DIRECTORY_SEPARATOR . $drel);
    if (!$file || strpos($file, $root) !== 0 || !is_file($file)) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    readfile($file);
    exit;
}

// Scan items in target directory
$items = [];
$totalSizeBytes = 0;
foreach (scandir($target) ?: [] as $name) {
    if ($name === '.' || $name === '..' || $name === '.htaccess' || $name === 'web.config' || $name === '.gitkeep') continue;
    $full = $target . DIRECTORY_SEPARATOR . $name;
    $isDir = is_dir($full);
    $size = $isDir ? 0 : (int)@filesize($full);
    $totalSizeBytes += $size;
    $mtime = (int)@filemtime($full);
    $itemRel = ltrim(($rel !== '' ? $rel . '/' : '') . $name, '/');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $items[] = [
        'name' => $name,
        'dir' => $isDir,
        'size' => $size,
        'mtime' => $mtime,
        'rel' => $itemRel,
        'ext' => $ext
    ];
}

$generatedPapers = [];
$deletedPapers = [];
$paperRemarks = [];
$savedPaperSets = [];
$setDeptFilter = trim((string)($_GET['dept'] ?? ''));
$setCourseFilter = strtoupper(trim((string)($_GET['course'] ?? '')));
if ($view === 'papers') {
    try {
        $setSql = "SELECT s.set_label,s.set_key,s.created_at,f.paper_code,f.semester,f.academic_year,f.exam_type,
                          fb.course_title,fb.total_marks,p.dept_code
                   FROM qps_paper_sets s
                   JOIN qps_final_papers f ON f.id=s.final_paper_id
                   LEFT JOIN qps_final_blueprints fb ON fb.id=f.final_blueprint_id
                   LEFT JOIN qps_submission_packages p ON p.id=f.package_id
                   WHERE 1=1";
        $setParams=[];
        if($setDeptFilter!==''){$setSql.=" AND p.dept_code=?";$setParams[]=$setDeptFilter;}
        if($setCourseFilter!==''){$setSql.=" AND UPPER(f.paper_code) LIKE ?";$setParams[]='%'.$setCourseFilter.'%';}
        $setSql.=" ORDER BY s.created_at DESC LIMIT 500";
        $setSt=$pdo->prepare($setSql);$setSt->execute($setParams);$savedPaperSets=$setSt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) { $savedPaperSets=[]; }
}
if (in_array($view, ['papers', 'papers_trash'], true)) {
    if ($view === 'papers') {
        $st = $pdo->query("SELECT id, paper_code, course_title, semester, academic_year, set_name, total_marks, dept_code, created_at FROM generated_papers WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 500");
        $generatedPapers = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($generatedPapers) {
            $ids = array_map('intval', array_column($generatedPapers, 'id'));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $rm = $pdo->prepare("SELECT * FROM generated_paper_remarks WHERE paper_id IN ($marks) ORDER BY created_at DESC, id DESC");
            $rm->execute($ids);
            foreach ($rm->fetchAll(PDO::FETCH_ASSOC) as $r) $paperRemarks[(int)$r['paper_id']][] = $r;
        }
    } else {
        $st = $pdo->query("SELECT id, paper_code, course_title, semester, academic_year, set_name, total_marks, created_at, deleted_at, deleted_by FROM generated_papers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 500");
        $deletedPapers = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

function fm_format_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 1) . ' GB';
}

function fm_file_type_meta(string $ext, bool $isDir): array {
    if ($isDir) {
        return [
            'type' => 'folder',
            'label' => 'Folder',
            'color' => 'text-amber-400',
            'bg' => 'bg-amber-50',
            'border' => 'border-amber-200',
            'icon' => 'folder'
        ];
    }
    switch ($ext) {
        case 'docx':
        case 'doc':
        case 'odt':
            return ['type' => 'document', 'label' => 'Word Document', 'color' => 'text-blue-500', 'bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'icon' => 'file-text'];
        case 'pdf':
            return ['type' => 'pdf', 'label' => 'PDF Document', 'color' => 'text-rose-500', 'bg' => 'bg-rose-50', 'border' => 'border-rose-200', 'icon' => 'file-text'];
        case 'xlsx':
        case 'xls':
        case 'csv':
            return ['type' => 'spreadsheet', 'label' => 'Spreadsheet', 'color' => 'text-emerald-500', 'bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'icon' => 'table-2'];
        case 'mp4':
        case 'mkv':
        case 'avi':
        case 'mov':
        case 'webm':
            return ['type' => 'video', 'label' => 'Video File', 'color' => 'text-violet-500', 'bg' => 'bg-violet-50', 'border' => 'border-violet-200', 'icon' => 'play-square'];
        case 'zip':
        case 'rar':
        case 'tar':
        case 'gz':
        case '7z':
            return ['type' => 'archive', 'label' => 'Archive Zip', 'color' => 'text-amber-500', 'bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'icon' => 'archive'];
        case 'tsx':
        case 'ts':
        case 'js':
        case 'jsx':
        case 'php':
        case 'py':
        case 'json':
        case 'css':
        case 'html':
            return ['type' => 'code', 'label' => 'Code / Component', 'color' => 'text-indigo-500', 'bg' => 'bg-indigo-50', 'border' => 'border-indigo-200', 'icon' => 'code-2'];
        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'svg':
        case 'webp':
        case 'gif':
            return ['type' => 'image', 'label' => 'Image File', 'color' => 'text-sky-500', 'bg' => 'bg-sky-50', 'border' => 'border-sky-200', 'icon' => 'image'];
        case 'mp3':
        case 'wav':
        case 'ogg':
        case 'm4a':
        case 'flac':
            return ['type' => 'audio', 'label' => 'Audio File', 'color' => 'text-pink-500', 'bg' => 'bg-pink-50', 'border' => 'border-pink-200', 'icon' => 'music'];
        case 'txt':
        case 'md':
        case 'log':
            return ['type' => 'text', 'label' => 'Text File', 'color' => 'text-slate-500', 'bg' => 'bg-slate-50', 'border' => 'border-slate-200', 'icon' => 'file'];
        default:
            return ['type' => 'file', 'label' => 'File', 'color' => 'text-slate-400', 'bg' => 'bg-slate-50', 'border' => 'border-slate-200', 'icon' => 'file'];
    }
}

// Breadcrumb construction
$parts = $rel === '' ? [] : explode('/', $rel);
$crumb = [];
$cur = '';
foreach ($parts as $p) {
    $cur .= ($cur !== '' ? '/' : '') . $p;
    $crumb[] = ['name' => $p, 'path' => $cur];
}

$storagePercent = 42; // aesthetic status

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- TOP HEADER SECTION -->
  <section class="flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
        <?php echo ($view === 'trash' || $view === 'papers_trash') ? 'Recycle Bin' : (($view === 'papers') ? 'Generated Question Papers' : 'My Files'); ?>
      </h1>
      <div class="text-xs font-semibold text-slate-400 mt-1 flex items-center space-x-2">
        <?php if ($view === 'trash' || $view === 'papers_trash'): ?>
          <span class="text-rose-600 font-bold"><?php echo count($trashItems); ?> Deleted Items</span>
          <span>•</span>
          <span>Automatic 5-Day Recovery Window</span>
        <?php else: ?>
          <span id="statItemCount"><?php echo count($items); ?> Items</span>
          <span>•</span>
          <span id="statTotalSize"><?php echo fm_format_size($totalSizeBytes); ?> Total</span>
          <span>•</span>
          <span id="statPercent"><?php echo $storagePercent; ?>% Space</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right Controls: Search, Recycle Bin, Upload, Folder, Settings, Profile -->
    <div class="flex items-center space-x-2.5">
      <?php if (!in_array($view, ['trash', 'papers_trash'], true)): ?>
        <!-- Search Input Pill -->
        <div class="relative w-60 sm:w-68">
          <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3 pointer-events-none"></i>
          <input type="text" id="fmSearchInput" placeholder="Search files and folders..." oninput="filterFmItems()" class="w-full pl-10 pr-9 py-2 bg-white border border-slate-200/90 rounded-full text-xs font-bold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 shadow-sm transition">
        </div>

        <!-- Recycle Bin Link with Badge -->
        <a href="?view=trash" class="px-3.5 py-2 rounded-full bg-white hover:bg-slate-50 border border-slate-200/90 shadow-sm flex items-center space-x-1.5 text-slate-700 hover:text-rose-600 transition text-xs font-bold" title="Open Recycle Bin (5 Days Validity)">
          <i data-lucide="trash-2" class="w-4 h-4 text-rose-500"></i>
          <span>Recycle Bin</span>
          <?php if (!empty($trashItems)): ?>
            <span class="px-1.5 py-0.2 rounded-full bg-rose-100 text-rose-700 font-mono text-[10px] font-black"><?php echo count($trashItems); ?></span>
          <?php endif; ?>
        </a>

        <!-- Upload Button -->
        <button type="button" onclick="openFmUploadModal()" class="w-9 h-9 rounded-full bg-white hover:bg-slate-50 border border-slate-200/90 shadow-sm flex items-center justify-center text-slate-700 hover:text-indigo-600 transition" title="Upload File to Current Directory">
          <i data-lucide="upload-cloud" class="w-4 h-4"></i>
        </button>

        <!-- New Folder Button -->
        <button type="button" onclick="openFmNewFolderModal()" class="w-9 h-9 rounded-full bg-white hover:bg-slate-50 border border-slate-200/90 shadow-sm flex items-center justify-center text-slate-700 hover:text-indigo-600 transition" title="Create New Folder">
          <i data-lucide="folder-plus" class="w-4 h-4"></i>
        </button>
      <?php else: ?>
        <!-- Back to Files Button -->
        <a href="<?php echo ($view === 'papers_trash') ? '?view=papers' : '?'; ?>" class="px-4 py-2 rounded-full bg-white hover:bg-slate-50 border border-slate-200 shadow-sm flex items-center space-x-1.5 text-slate-700 hover:text-indigo-600 transition text-xs font-bold">
          <i data-lucide="arrow-left" class="w-4 h-4"></i>
          <span>Back to My Files</span>
        </a>

        <!-- Empty Recycle Bin Button -->
        <?php if ($view === 'trash' && !empty($trashItems)): ?>
          <form method="POST" onsubmit="return confirmEmptyTrashFm(event, this);">
            <input type="hidden" name="action" value="empty_trash">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <button type="submit" class="px-4 py-2 rounded-full bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 shadow-sm flex items-center space-x-1.5 transition text-xs font-bold">
              <i data-lucide="trash" class="w-4 h-4"></i>
              <span>Empty Recycle Bin</span>
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>

      <a href="?view=files" class="px-3 py-2 rounded-full border border-slate-200 bg-white text-xs font-bold hover:bg-slate-50">My Files</a>
      <a href="?view=papers" class="px-3 py-2 rounded-full border border-slate-200 bg-white text-xs font-bold hover:bg-slate-50">Generated Papers</a>
      <?php if ($view === 'papers' || $view === 'papers_trash'): ?><a href="?view=papers_trash" class="px-3 py-2 rounded-full border border-rose-200 bg-rose-50 text-rose-700 text-xs font-bold">Paper Bin</a><?php endif; ?>
      <!-- Profile Avatar -->
      <div class="w-9 h-9 rounded-full bg-gradient-to-tr from-amber-500 via-orange-500 to-rose-500 flex items-center justify-center text-white font-black text-xs shadow-sm ring-2 ring-white">
        <?php echo strtoupper(substr($user['name'] ?? 'C', 0, 1)); ?>
      </div>
    </div>
  </section>

  <!-- Notification Banner -->
  <?php if ($msg): ?>
    <div class="card-modern p-4 bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-bold flex items-center space-x-2">
      <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600 shrink-0"></i>
      <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="card-modern p-4 bg-rose-50 border-rose-200 text-rose-800 text-xs font-bold flex items-center space-x-2">
      <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 shrink-0"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
  <?php endif; ?>

  <?php if ($view === 'trash'): ?>
    <!-- RECYCLE BIN VIEW (5 Days Retention) -->
    <section class="space-y-4">
      <div class="bg-rose-50/70 border border-rose-200/80 rounded-2xl p-4 flex items-center justify-between text-xs text-rose-900">
        <div class="flex items-center space-x-2.5">
          <i data-lucide="info" class="w-5 h-5 text-rose-600 shrink-0"></i>
          <div>
            <strong>5-Day Expiration Policy:</strong> Deleted files are safely stored here for 5 days. COE and ERP staff can restore items anytime before they are permanently purged.
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <?php foreach ($trashItems as $t): 
          $remainingSec = max(0, (int)$t['expires_at'] - time());
          $remainingDays = floor($remainingSec / 86400);
          $remainingHours = floor(($remainingSec % 86400) / 3600);
          $meta = fm_file_type_meta($t['ext'] ?? '', $t['is_dir'] ?? false);
        ?>
          <div class="bg-white rounded-3xl border border-slate-200/80 p-5 shadow-sm hover:shadow-md transition flex flex-col justify-between space-y-4">
            <div>
              <div class="flex items-start justify-between">
                <div class="w-12 h-12 rounded-2xl <?php echo $meta['bg']; ?> <?php echo $meta['border']; ?> border flex items-center justify-center <?php echo $meta['color']; ?>">
                  <i data-lucide="<?php echo $meta['icon']; ?>" class="w-6 h-6"></i>
                </div>
                <span class="px-2.5 py-1 rounded-full text-[10px] font-mono font-black bg-rose-50 text-rose-700 border border-rose-200">
                  ⏳ <?php echo $remainingDays; ?>d <?php echo $remainingHours; ?>h left
                </span>
              </div>
              <h4 class="font-extrabold text-slate-900 text-sm mt-3 truncate" title="<?php echo htmlspecialchars($t['file_name']); ?>">
                <?php echo htmlspecialchars($t['file_name']); ?>
              </h4>
              <div class="text-[11px] text-slate-400 mt-1 truncate" title="Original: storage/uploads/<?php echo htmlspecialchars($t['original_rel_path']); ?>">
                Original: storage/uploads/<?php echo htmlspecialchars($t['original_rel_path']); ?>
              </div>
              <div class="text-[10px] text-slate-500 mt-2 font-mono">
                Deleted: <?php echo date('M d, Y h:i A', (int)$t['deleted_at']); ?>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-2 pt-3 border-t border-slate-100">
              <form method="POST" onsubmit="return confirmRestoreFm(event, this);" class="w-full">
                <input type="hidden" name="action" value="restore_trash_item">
                <input type="hidden" name="trash_id" value="<?php echo htmlspecialchars($t['id']); ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <button type="submit" class="w-full bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-full py-2 text-xs font-bold transition flex items-center justify-center space-x-1">
                  <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                  <span>Restore</span>
                </button>
              </form>

              <form method="POST" onsubmit="return confirmPermanentDeleteFm(event, this);" class="w-full">
                <input type="hidden" name="action" value="purge_trash_item">
                <input type="hidden" name="trash_id" value="<?php echo htmlspecialchars($t['id']); ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <button type="submit" class="w-full bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-full py-2 text-xs font-bold transition flex items-center justify-center space-x-1">
                  <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                  <span>Delete</span>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($trashItems)): ?>
          <div class="col-span-full py-16 text-center bg-white rounded-3xl border border-slate-200 p-8 space-y-2">
            <i data-lucide="trash" class="w-12 h-12 text-slate-300 mx-auto mb-2"></i>
            <h4 class="font-bold text-slate-700 text-sm">Recycle Bin is Empty</h4>
            <p class="text-xs text-slate-400">No deleted files or folders in the 5-day retention pool.</p>
          </div>
        <?php endif; ?>
      </div>
    </section>

  <?php elseif ($view === 'papers' || $view === 'papers_trash'): ?>
    <section class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
      <div class="p-5 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="font-extrabold text-slate-900">Generated examination papers</h2><p class="text-xs text-slate-500 mt-1">Google Drive-style paper repository · view, edit, download and recover</p></div>
        <div class="flex flex-wrap gap-2 items-center"><form method="get" class="flex flex-wrap gap-2"><input type="hidden" name="view" value="papers"><input name="dept" value="<?php echo htmlspecialchars($setDeptFilter); ?>" placeholder="Department code" class="border rounded-xl px-3 py-2 text-xs w-32"><input name="course" value="<?php echo htmlspecialchars($setCourseFilter); ?>" placeholder="Course code" class="border rounded-xl px-3 py-2 text-xs w-32"><button class="border rounded-xl px-3 py-2 text-xs font-bold">Filter</button></form><input id="paperSearch" oninput="filterGeneratedPapers()" placeholder="Search legacy papers..." class="border rounded-xl px-3 py-2 text-xs"><button type="button" onclick="setPaperLayout('grid')" class="px-3 py-2 border rounded-xl text-xs font-bold">Grid</button><button type="button" onclick="setPaperLayout('list')" class="px-3 py-2 border rounded-xl text-xs font-bold">List</button></div>
      </div>
      <?php $paperRows = ($view === 'papers') ? $generatedPapers : $deletedPapers; ?>
      <div id="paperManagerGrid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 p-4">
      <?php foreach ($paperRows as $gp): ?>
        <article class="paper-manager-item border border-slate-200 rounded-2xl p-4 hover:shadow-md hover:border-indigo-200 transition" data-paper="<?php echo htmlspecialchars(strtolower(($gp['paper_code'] ?? '') . ' ' . ($gp['course_title'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
          <div class="flex items-start justify-between gap-2"><div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center"><i data-lucide="file-text" class="w-5 h-5"></i></div><input type="checkbox" class="paper-select mt-2" value="<?php echo (int)$gp['id']; ?>" aria-label="Select paper"></div>
          <h3 class="font-bold text-sm text-slate-900 mt-3"><?php echo htmlspecialchars(($gp['paper_code'] ?? 'Paper') . ' · ' . ($gp['set_name'] ?? 'SET A')); ?></h3>
          <p class="text-xs text-slate-600 mt-1"><?php echo htmlspecialchars($gp['course_title'] ?? ''); ?></p>
          <p class="text-[11px] text-slate-400 mt-2"><?php echo htmlspecialchars(($gp['semester'] ?? '') . ' · ' . ($gp['academic_year'] ?? '') . ' · ' . ($gp['total_marks'] ?? '') . ' marks'); ?></p>
          <?php if ($view === 'papers_trash'): ?><p class="text-[11px] text-rose-600 font-bold mt-2"><?php $remain=max(0, 5*86400-(time()-strtotime($gp['deleted_at']))); echo (int)ceil($remain/86400); ?> day(s) remaining</p><?php endif; ?>
          <div class="flex flex-wrap gap-2 mt-4 pt-3 border-t border-slate-100">
          <?php if ($view === 'papers'): ?>
            <a class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold" href="<?php echo getBaseUrl(); ?>/modules/coe/view_paper.php?paper_id=<?php echo (int)$gp['id']; ?>">View</a>
            <a class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold" href="<?php echo getBaseUrl(); ?>/modules/coe/edit_generated_paper.php?paper_id=<?php echo (int)$gp['id']; ?>">Edit</a>
            <a class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 text-xs font-bold" href="<?php echo getBaseUrl(); ?>/api/export_docx.php?paper_id=<?php echo (int)$gp['id']; ?>">Download DOCX</a>
            <form method="post" onsubmit="return confirm('Move this paper to the Recycle Bin for 5 days?')"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="paper_id" value="<?php echo (int)$gp['id']; ?>"><button name="paper_action" value="paper_delete" class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs font-bold">Delete</button></form>
          <?php else: ?>
            <form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="paper_id" value="<?php echo (int)$gp['id']; ?>"><button name="paper_action" value="paper_restore" class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-xs font-bold">Restore</button></form>
            <?php if (strtotime($gp['deleted_at']) <= time() - 5*86400): ?><form method="post" onsubmit="return confirm('Permanently purge this expired paper?')"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="paper_id" value="<?php echo (int)$gp['id']; ?>"><button name="paper_action" value="paper_purge" class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs font-bold">Purge</button></form><?php endif; ?>
          <?php endif; ?>
          </div>
          <?php if ($view === 'papers'): ?>
            <details class="mt-3 border-t pt-3"><summary class="cursor-pointer text-xs font-bold text-slate-600">Remarks history (<?php echo count($paperRemarks[(int)$gp['id']] ?? []); ?>)</summary>
              <?php foreach (($paperRemarks[(int)$gp['id']] ?? []) as $pr): ?><div class="mt-2 text-[11px] bg-slate-50 rounded-lg p-2"><div class="font-bold text-slate-700"><?php echo htmlspecialchars($pr['actor_staff_code']); ?> · <?php echo htmlspecialchars($pr['created_at']); ?></div><div class="text-slate-600 whitespace-pre-wrap"><?php echo htmlspecialchars($pr['remarks']); ?></div></div><?php endforeach; ?>
              <form method="post" class="mt-2 space-y-2"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="paper_id" value="<?php echo (int)$gp['id']; ?>"><textarea name="paper_remarks" maxlength="5000" required rows="2" class="w-full border rounded-lg p-2 text-xs" placeholder="Add a remark for this paper..."></textarea><button name="paper_action" value="paper_remark_save" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-bold">Save Remark</button></form>
            </details>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$paperRows): ?><div class="col-span-full text-center py-16 text-sm text-slate-400">No generated papers in this view.</div><?php endif; ?>
      </div>
    </section>

    <?php if ($view === 'papers'): ?>
    <section class="bg-white border border-indigo-100 rounded-2xl overflow-hidden">
      <div class="p-5 border-b border-slate-100"><h2 class="font-extrabold text-slate-900">Saved Question Paper Sets</h2><p class="text-xs text-slate-500 mt-1">Canonical questions are stored once; each card references its saved set key and ordering.</p></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 p-4">
      <?php foreach($savedPaperSets as $set): ?>
        <article class="border rounded-2xl p-4 hover:shadow-md transition">
          <div class="flex items-center justify-between"><span class="rounded-full bg-indigo-50 text-indigo-700 px-3 py-1 text-xs font-black">SET <?=htmlspecialchars($set['set_label'])?></span><i data-lucide="file-check-2" class="w-5 h-5 text-indigo-500"></i></div>
          <h3 class="font-black text-lg mt-3"><?=htmlspecialchars($set['paper_code'])?></h3>
          <p class="text-xs text-slate-600"><?=htmlspecialchars($set['course_title']??'')?></p>
          <p class="text-[11px] text-slate-400 mt-2"><?=htmlspecialchars($set['semester'].' · '.$set['academic_year'].' · '.($set['total_marks']??'').' marks')?></p>
          <div class="flex flex-wrap gap-2 mt-4"><a class="px-3 py-2 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold" href="<?=htmlspecialchars(getBaseUrl())?>/modules/coe/view_paper.php?set_key=<?=rawurlencode($set['set_key'])?>">View Paper</a><a class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-xs font-bold" href="<?=htmlspecialchars(getBaseUrl())?>/api/export_docx.php?set_key=<?=rawurlencode($set['set_key'])?>">Download DOCX</a></div>
        </article>
      <?php endforeach; ?>
      <?php if(!$savedPaperSets): ?><div class="col-span-full text-center py-10 text-sm text-slate-400">No saved set-key papers match these filters.</div><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

  <?php else: ?>

    <!-- BREADCRUMBS & VIEW CONTROLS TOOLBAR -->
    <section class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
      
      <!-- Breadcrumb Path -->
      <div class="flex items-center space-x-1.5 text-xs text-slate-500 overflow-x-auto no-scrollbar py-1">
        <a href="?" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-900 transition flex items-center" title="Home Root">
          <i data-lucide="home" class="w-4 h-4"></i>
        </a>
        <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-300 shrink-0"></i>
        <a href="?" class="font-bold text-slate-600 hover:text-slate-900 hover:underline transition">Home</a>
        
        <?php foreach ($crumb as $index => $c): ?>
          <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-300 shrink-0"></i>
          <?php if ($index === count($crumb) - 1): ?>
            <span class="font-black text-indigo-600 bg-indigo-50 border border-indigo-200/60 px-2 py-0.5 rounded-lg"><?php echo htmlspecialchars($c['name']); ?></span>
          <?php else: ?>
            <a href="?path=<?php echo urlencode($c['path']); ?>" class="font-bold text-slate-600 hover:text-slate-900 hover:underline transition"><?php echo htmlspecialchars($c['name']); ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Right Controls: Sort & Grid View Toggle -->
      <div class="flex items-center space-x-2 shrink-0">
        <!-- Sort Dropdown -->
        <div class="relative inline-block text-left">
          <select id="fmSortSelect" onchange="sortFmItems(this.value)" class="appearance-none bg-white border border-slate-200/90 text-slate-700 font-bold text-xs rounded-xl pl-8 pr-7 py-1.5 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer">
            <option value="newest">Newest First</option>
            <option value="oldest">Oldest First</option>
            <option value="name_asc">Name (A-Z)</option>
            <option value="name_desc">Name (Z-A)</option>
            <option value="size_desc">Size (Largest)</option>
          </select>
          <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2.5 pointer-events-none"></i>
          <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-2.5 pointer-events-none"></i>
        </div>

        <!-- View Switcher -->
        <div class="inline-flex items-center bg-white border border-slate-200/90 rounded-xl p-0.5 shadow-sm text-xs">
          <button type="button" onclick="switchFmView('grid')" id="fmViewGridBtn" class="p-1.5 rounded-lg bg-indigo-50 text-indigo-600 shadow-xs transition" title="Grid View">
            <i data-lucide="grid" class="w-4 h-4"></i>
          </button>
          <button type="button" onclick="switchFmView('list')" id="fmViewListBtn" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 transition" title="List View">
            <i data-lucide="list" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

    </section>

    <!-- FILE CARDS GRID -->
    <section class="min-h-[420px]">
      
      <!-- Grid View -->
      <div id="fmGridViewContainer" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-4 gap-4">
        
        <?php if ($rel !== '' && count($parts) > 0): 
          $parent = implode('/', array_slice($parts, 0, -1));
        ?>
          <!-- Parent Directory Card -->
          <a href="?path=<?php echo urlencode($parent); ?>" class="rounded-[24px] bg-white border border-slate-200/80 hover:border-indigo-300 hover:shadow-md transition p-4 flex flex-col justify-between items-center text-center group min-h-[160px]">
            <div class="h-24 flex items-center justify-center">
              <div class="w-14 h-14 rounded-2xl bg-slate-100 group-hover:bg-indigo-50 text-slate-400 group-hover:text-indigo-600 flex items-center justify-center transition">
                <i data-lucide="corner-up-left" class="w-7 h-7"></i>
              </div>
            </div>
            <div class="w-full text-center">
              <span class="font-bold text-xs text-slate-700 truncate block">.. (Parent Directory)</span>
              <span class="text-[10px] text-slate-400">Back</span>
            </div>
          </a>
        <?php endif; ?>

        <?php foreach ($items as $idx => $it): 
          $meta = fm_file_type_meta($it['ext'], $it['dir']);
        ?>
          <?php if ($it['dir']): ?>
            <!-- Clickable Folder Card -->
            <a href="?path=<?php echo urlencode($it['rel']); ?>"
               class="fm-file-card rounded-[24px] bg-white border border-slate-200/80 hover:border-amber-400 hover:shadow-lg transition duration-200 p-4 flex flex-col justify-between group min-h-[165px] relative select-none"
               id="fm-card-<?php echo $idx; ?>"
               data-name="<?php echo htmlspecialchars(strtolower($it['name'])); ?>"
               data-size="<?php echo $it['size']; ?>"
               data-mtime="<?php echo $it['mtime']; ?>"
               data-dir="1">
              
              <!-- Center Large Folder Icon -->
              <div class="h-24 flex items-center justify-center relative">
                <div class="w-16 h-16 rounded-2xl bg-amber-50 border border-amber-200/60 flex items-center justify-center text-amber-500 group-hover:scale-110 transition shadow-sm">
                  <i data-lucide="folder" class="w-9 h-9 fill-amber-400 text-amber-500"></i>
                </div>
                
                <!-- Action Menu 3-dots Pill -->
                <button type="button" onclick="openFmItemMenu(event, '<?php echo addslashes($it['name']); ?>', '<?php echo $it['rel']; ?>', true)" class="absolute top-0 right-0 p-1.5 rounded-full text-slate-300 hover:text-slate-600 hover:bg-slate-100 opacity-0 group-hover:opacity-100 transition z-10" title="Folder Actions">
                  <i data-lucide="more-vertical" class="w-4 h-4"></i>
                </button>
              </div>

              <!-- Bottom Folder Info Box -->
              <div class="w-full text-center pt-2 border-t border-slate-100/80">
                <h4 class="font-black text-xs text-slate-800 truncate group-hover:text-amber-800 transition" title="<?php echo htmlspecialchars($it['name']); ?>">
                  <?php echo htmlspecialchars($it['name']); ?>
                </h4>
                <div class="text-[10px] text-slate-400 font-medium mt-0.5 flex items-center justify-center space-x-1">
                  <span>Folder</span>
                  <span>•</span>
                  <span><?php echo date('M d, Y', $it['mtime']); ?></span>
                </div>
              </div>
            </a>

          <?php else: ?>

            <!-- Clickable File Card -->
            <div class="fm-file-card rounded-[24px] bg-white border border-slate-200/80 hover:border-indigo-300 hover:shadow-lg transition duration-200 p-4 flex flex-col justify-between group min-h-[165px] relative cursor-pointer select-none"
                 id="fm-card-<?php echo $idx; ?>"
                 onclick="selectFmCard(event, 'fm-card-<?php echo $idx; ?>', '<?php echo addslashes($it['name']); ?>', '<?php echo $it['rel']; ?>', false)"
                 ondblclick="window.location.href='?download=<?php echo urlencode($it['rel']); ?>'"
                 data-name="<?php echo htmlspecialchars(strtolower($it['name'])); ?>"
                 data-size="<?php echo $it['size']; ?>"
                 data-mtime="<?php echo $it['mtime']; ?>"
                 data-dir="0">
              
              <!-- Center Large Icon Area -->
              <div class="h-24 flex items-center justify-center relative">
                <?php if (in_array($it['ext'], ['docx', 'doc'])): ?>
                  <div class="fm-card-icon w-16 h-16 rounded-2xl bg-blue-50/70 border border-blue-200/50 flex items-center justify-center text-blue-500 group-hover:scale-110 transition shadow-sm">
                    <i data-lucide="file-text" class="w-8 h-8"></i>
                  </div>
                <?php elseif ($it['ext'] === 'pdf'): ?>
                  <div class="fm-card-icon w-16 h-16 rounded-2xl bg-rose-50/70 border border-rose-200/50 flex items-center justify-center text-rose-500 group-hover:scale-110 transition shadow-sm">
                    <i data-lucide="file-text" class="w-8 h-8"></i>
                  </div>
                <?php elseif (in_array($it['ext'], ['xlsx', 'xls', 'csv'])): ?>
                  <div class="fm-card-icon w-16 h-16 rounded-2xl bg-emerald-50/70 border border-emerald-200/50 flex items-center justify-center text-emerald-500 group-hover:scale-110 transition shadow-sm">
                    <i data-lucide="table-2" class="w-8 h-8"></i>
                  </div>
                <?php elseif (in_array($it['ext'], ['zip', 'rar', 'gz'])): ?>
                  <div class="fm-card-icon w-16 h-16 rounded-2xl bg-amber-50/70 border border-amber-200/50 flex items-center justify-center text-amber-500 group-hover:scale-110 transition shadow-sm">
                    <i data-lucide="archive" class="w-8 h-8"></i>
                  </div>
                <?php elseif (in_array($it['ext'], ['png', 'jpg', 'jpeg', 'svg', 'webp'])): ?>
                  <div class="fm-card-icon w-16 h-16 rounded-2xl bg-sky-50/70 border border-sky-200/50 flex items-center justify-center text-sky-500 group-hover:scale-110 transition shadow-sm">
                    <i data-lucide="image" class="w-8 h-8"></i>
                  </div>
                <?php else: ?>
                  <div class="fm-card-icon w-16 h-16 rounded-2xl bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-500 group-hover:scale-110 transition shadow-sm">
                    <i data-lucide="file" class="w-8 h-8"></i>
                  </div>
                <?php endif; ?>

                <!-- Action Menu 3-dots Pill -->
                <button type="button" onclick="openFmItemMenu(event, '<?php echo addslashes($it['name']); ?>', '<?php echo $it['rel']; ?>', false)" class="absolute top-0 right-0 p-1.5 rounded-full text-slate-300 hover:text-slate-600 hover:bg-slate-100 opacity-0 group-hover:opacity-100 transition z-10" title="File Actions">
                  <i data-lucide="more-vertical" class="w-4 h-4"></i>
                </button>
              </div>

              <!-- Bottom File Info Box -->
              <div class="w-full text-center pt-2 border-t border-slate-100/80">
                <h4 class="font-black text-xs text-slate-800 truncate group-hover:text-indigo-600 transition" title="<?php echo htmlspecialchars($it['name']); ?>">
                  <?php echo htmlspecialchars($it['name']); ?>
                </h4>
                <div class="text-[10px] text-slate-400 font-medium mt-0.5 flex items-center justify-center space-x-1">
                  <span><?php echo fm_format_size($it['size']); ?></span>
                  <span>•</span>
                  <span><?php echo date('M d, Y', $it['mtime']); ?></span>
                </div>
              </div>

            </div>
          <?php endif; ?>
        <?php endforeach; ?>

      </div>

      <!-- List View (Hidden by default) -->
      <div id="fmListViewContainer" class="hidden bg-white rounded-3xl border border-slate-200/90 shadow-sm overflow-hidden">
        <table class="w-full text-left text-xs border-collapse">
          <thead class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px] border-b border-slate-200">
            <tr>
              <th class="py-3 px-4">Name</th>
              <th class="py-3 px-4">Size</th>
              <th class="py-3 px-4">Type</th>
              <th class="py-3 px-4">Last Modified</th>
              <th class="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 font-medium">
            <?php foreach ($items as $idx => $it): 
              $meta = fm_file_type_meta($it['ext'], $it['dir']);
            ?>
              <tr class="hover:bg-slate-50/80 transition cursor-pointer" onclick="<?php echo $it['dir'] ? "window.location.href='?path=" . urlencode($it['rel']) . "'" : "selectFmCard(event, 'fm-card-" . $idx . "', '" . addslashes($it['name']) . "', '" . $it['rel'] . "', false)"; ?>" ondblclick="<?php echo $it['dir'] ? "window.location.href='?path=" . urlencode($it['rel']) . "'" : "window.location.href='?download=" . urlencode($it['rel']) . "'"; ?>">
                <td class="py-3 px-4 flex items-center space-x-3">
                  <div class="w-8 h-8 rounded-xl <?php echo $meta['bg']; ?> <?php echo $meta['border']; ?> border flex items-center justify-center <?php echo $meta['color']; ?>">
                    <i data-lucide="<?php echo $meta['icon']; ?>" class="w-4 h-4"></i>
                  </div>
                  <span class="font-bold text-slate-800"><?php echo htmlspecialchars($it['name']); ?></span>
                </td>
                <td class="py-3 px-4 text-slate-500 font-mono"><?php echo $it['dir'] ? '—' : fm_format_size($it['size']); ?></td>
                <td class="py-3 px-4 text-slate-600"><?php echo $meta['label']; ?></td>
                <td class="py-3 px-4 text-slate-400 font-mono text-[11px]"><?php echo date('Y-m-d H:i', $it['mtime']); ?></td>
                <td class="py-3 px-4 text-right">
                  <button type="button" onclick="openFmItemMenu(event, '<?php echo addslashes($it['name']); ?>', '<?php echo $it['rel']; ?>', <?php echo $it['dir'] ? 'true' : 'false'; ?>)" class="p-1 rounded-full text-slate-400 hover:text-slate-700 hover:bg-slate-100">
                    <i data-lucide="more-horizontal" class="w-4 h-4"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Empty State -->
      <?php if (!$items): ?>
        <div class="py-20 text-center rounded-[32px] bg-white border border-slate-200/80 p-8 space-y-3">
          <div class="w-16 h-16 rounded-full bg-slate-100 text-slate-400 mx-auto flex items-center justify-center">
            <i data-lucide="folder-open" class="w-8 h-8"></i>
          </div>
          <h3 class="font-black text-slate-700 text-sm">This folder is empty</h3>
          <p class="text-xs text-slate-400 max-w-sm mx-auto">Upload question banks, generated examination papers, or official documents into this directory.</p>
          <button type="button" onclick="openFmUploadModal()" class="btn-orange-pill text-xs shadow-md mt-2">
            <i data-lucide="upload" class="w-3.5 h-3.5"></i>
            <span>Upload File Now</span>
          </button>
        </div>
      <?php endif; ?>

    </section>

  <?php endif; ?>

</main>

<!-- Context Menu Popover -->
<div id="fmItemDropdown" class="hidden fixed z-50 bg-white rounded-2xl shadow-2xl border border-slate-200 p-1.5 w-48 text-xs font-bold text-slate-700 space-y-0.5">
  <a id="fmMenuDownloadBtn" href="#" class="w-full flex items-center space-x-2 px-3 py-2 rounded-xl hover:bg-indigo-50 hover:text-indigo-600 transition">
    <i data-lucide="download" class="w-4 h-4 text-slate-400"></i>
    <span>Download File</span>
  </a>
  <button type="button" onclick="copyFmItemPath()" class="w-full flex items-center space-x-2 px-3 py-2 rounded-xl hover:bg-slate-50 transition text-left">
    <i data-lucide="copy" class="w-4 h-4 text-slate-400"></i>
    <span>Copy Relative Path</span>
  </button>
  <div class="h-px bg-slate-100 my-1"></div>
  <button type="button" onclick="confirmDeleteFmItem()" class="w-full flex items-center space-x-2 px-3 py-2 rounded-xl text-rose-600 hover:bg-rose-50 transition text-left">
    <i data-lucide="trash-2" class="w-4 h-4"></i>
    <span>Move to Recycle Bin</span>
  </button>
</div>

<!-- Modal: Upload File -->
<div id="fmUploadModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-[28px] max-w-md w-full p-6 shadow-2xl space-y-4 border border-stone-200">
    <div class="flex items-center justify-between border-b border-stone-100 pb-3">
      <div class="flex items-center space-x-2">
        <div class="w-8 h-8 rounded-xl bg-orange-100 text-orange-600 flex items-center justify-center font-black">
          <i data-lucide="upload-cloud" class="w-4 h-4"></i>
        </div>
        <div>
          <h3 class="font-black text-slate-900 text-sm">Upload File</h3>
          <p class="text-[10px] text-slate-400">Current: storage/uploads/<?php echo htmlspecialchars($rel ?: '(Root)'); ?></p>
        </div>
      </div>
      <button onclick="closeFmUploadModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-stone-100"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>

    <form method="POST" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="action" value="upload_file">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      
      <div class="border-2 border-dashed border-slate-200 hover:border-orange-400 rounded-2xl p-6 text-center cursor-pointer transition" onclick="document.getElementById('fmFileInput').click()">
        <i data-lucide="file-plus" class="w-10 h-10 text-slate-300 mx-auto mb-2"></i>
        <div class="text-xs font-bold text-slate-700" id="fmFileChosenLabel">Click to select file or drag here</div>
        <p class="text-[10px] text-slate-400 mt-1">Supports DOCX, PDF, XLSX, CSV, JSON, ZIP</p>
        <input type="file" id="fmFileInput" name="file" class="hidden" onchange="updateFmFileChosen(this)">
      </div>

      <div class="flex justify-end space-x-2 pt-2 border-t border-stone-100">
        <button type="button" onclick="closeFmUploadModal()" class="px-4 py-2 text-xs font-bold text-slate-700 bg-stone-100 hover:bg-stone-200 rounded-full">Cancel</button>
        <button type="submit" class="btn-orange-pill text-xs shadow-md">Upload Now</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: New Folder -->
<div id="fmNewFolderModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-[28px] max-w-sm w-full p-6 shadow-2xl space-y-4 border border-stone-200">
    <div class="flex items-center justify-between border-b border-stone-100 pb-3">
      <div class="flex items-center space-x-2">
        <div class="w-8 h-8 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center font-black">
          <i data-lucide="folder-plus" class="w-4 h-4"></i>
        </div>
        <h3 class="font-black text-slate-900 text-sm">Create New Folder</h3>
      </div>
      <button onclick="closeFmNewFolderModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-stone-100"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="create_folder">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Folder Name</label>
        <input type="text" name="folder_name" placeholder="e.g. BLUEPRINTS, 2026-2027" required class="w-full border border-stone-300 rounded-2xl p-2.5 font-bold text-xs bg-stone-50 focus:bg-white focus:ring-2 focus:ring-indigo-500">
      </div>

      <div class="flex justify-end space-x-2 pt-2 border-t border-stone-100">
        <button type="button" onclick="closeFmNewFolderModal()" class="px-4 py-2 text-xs font-bold text-slate-700 bg-stone-100 hover:bg-stone-200 rounded-full">Cancel</button>
        <button type="submit" class="px-5 py-2 text-xs font-black text-white bg-indigo-600 hover:bg-indigo-700 rounded-full shadow-md">Create</button>
      </div>
    </form>
  </div>
</div>

<!-- Hidden POST form for Delete -->
<form id="fmDeleteForm" method="POST" action="" class="hidden">
  <input type="hidden" name="action" value="delete_item">
  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
  <input type="hidden" name="item_path" id="fmDeleteItemPath" value="">
</form>

<script>
let currentSelectedFmItem = null;

function selectFmCard(e, cardId, name, rel, isDir) {
  e.stopPropagation();
  currentSelectedFmItem = { name, rel, isDir };
  document.querySelectorAll('.fm-file-card').forEach(c => {
    c.classList.remove('ring-2', 'ring-indigo-500', 'bg-indigo-50/20');
  });
  const el = document.getElementById(cardId);
  if (el) {
    el.classList.add('ring-2', 'ring-indigo-500', 'bg-indigo-50/20');
  }
}

function handleFmDblClick(encodedRel, isDir) {
  if (isDir) {
    window.location.href = '?path=' + encodedRel;
  } else {
    window.location.href = '?download=' + encodedRel;
  }
}

function openFmItemMenu(e, name, rel, isDir) {
  e.stopPropagation();
  e.preventDefault();
  currentSelectedFmItem = { name, rel, isDir };
  
  const dropdown = document.getElementById('fmItemDropdown');
  const dlBtn = document.getElementById('fmMenuDownloadBtn');
  
  if (isDir) {
    dlBtn.classList.add('hidden');
  } else {
    dlBtn.classList.remove('hidden');
    dlBtn.href = '?download=' + encodeURIComponent(rel);
  }

  const x = Math.min(window.innerWidth - 210, e.clientX);
  const y = Math.min(window.innerHeight - 150, e.clientY);
  dropdown.style.left = `${x}px`;
  dropdown.style.top = `${y}px`;
  dropdown.classList.remove('hidden');
}

document.addEventListener('click', () => {
  const dropdown = document.getElementById('fmItemDropdown');
  if (dropdown) dropdown.classList.add('hidden');
});

function copyFmItemPath() {
  if (!currentSelectedFmItem) return;
  const full = 'storage/uploads/' + currentSelectedFmItem.rel;
  navigator.clipboard.writeText(full).then(() => {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Copied path: ' + full,
        showConfirmButton: false,
        timer: 2000
      });
    }
  });
}

function confirmDeleteFmItem() {
  if (!currentSelectedFmItem) return;
  const name = currentSelectedFmItem.name;
  const rel = currentSelectedFmItem.rel;

  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Move to Recycle Bin?',
      text: `Are you sure you want to move "${name}" to the Recycle Bin? It will be safely retained for 4 days and can be restored anytime by COE or ERP staff.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Move to Trash',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#FF5B00',
      cancelButtonColor: '#64748B',
      customClass: {
        popup: 'rounded-[28px]',
        confirmButton: 'rounded-full px-5 py-2.5 font-bold',
        cancelButton: 'rounded-full px-5 py-2.5 font-bold'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        document.getElementById('fmDeleteItemPath').value = rel;
        document.getElementById('fmDeleteForm').submit();
      }
    });
  } else {
    if (confirm(`Move "${name}" to Recycle Bin?`)) {
      document.getElementById('fmDeleteItemPath').value = rel;
      document.getElementById('fmDeleteForm').submit();
    }
  }
}

function confirmRestoreFm(event, form) {
  event.preventDefault();
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Restore File?',
      text: 'Do you want to restore this file back to its original location in the file manager?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Restore',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#10B981',
      cancelButtonColor: '#64748B',
      customClass: {
        popup: 'rounded-[28px]',
        confirmButton: 'rounded-full px-5 py-2.5 font-bold',
        cancelButton: 'rounded-full px-5 py-2.5 font-bold'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  } else {
    if (confirm('Restore file to original location?')) {
      form.submit();
    }
  }
  return false;
}

function confirmPermanentDeleteFm(event, form) {
  event.preventDefault();
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Permanently Delete File?',
      text: 'Are you sure you want to delete this file forever? This action cannot be undone and the file will be removed from the server immediately.',
      icon: 'error',
      showCancelButton: true,
      confirmButtonText: 'Yes, Delete Forever',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#EF4444',
      cancelButtonColor: '#64748B',
      customClass: {
        popup: 'rounded-[28px]',
        confirmButton: 'rounded-full px-5 py-2.5 font-bold',
        cancelButton: 'rounded-full px-5 py-2.5 font-bold'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  } else {
    if (confirm('Permanently delete this file?')) {
      form.submit();
    }
  }
  return false;
}

function confirmEmptyTrashFm(event, form) {
  event.preventDefault();
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Empty Recycle Bin?',
      text: 'All files and folders currently in the Recycle Bin will be permanently purged. This cannot be undone.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Empty Trash',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#EF4444',
      cancelButtonColor: '#64748B',
      customClass: {
        popup: 'rounded-[28px]',
        confirmButton: 'rounded-full px-5 py-2.5 font-bold',
        cancelButton: 'rounded-full px-5 py-2.5 font-bold'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  } else {
    if (confirm('Empty Recycle Bin?')) {
      form.submit();
    }
  }
  return false;
}

function openFmUploadModal() {
  document.getElementById('fmUploadModal')?.classList.remove('hidden');
}
function closeFmUploadModal() {
  document.getElementById('fmUploadModal')?.classList.add('hidden');
}

function openFmNewFolderModal() {
  document.getElementById('fmNewFolderModal')?.classList.remove('hidden');
}
function closeFmNewFolderModal() {
  document.getElementById('fmNewFolderModal')?.classList.add('hidden');
}

function updateFmFileChosen(input) {
  const label = document.getElementById('fmFileChosenLabel');
  if (input.files && input.files[0]) {
    label.textContent = 'Selected: ' + input.files[0].name;
    label.classList.add('text-indigo-600', 'font-black');
  }
}

function filterFmItems() {
  const query = (document.getElementById('fmSearchInput')?.value || '').toLowerCase().trim();
  const cards = document.querySelectorAll('.fm-file-card');
  let matchCount = 0;

  cards.forEach(card => {
    const name = card.dataset.name || '';
    if (!query || name.includes(query)) {
      card.style.display = 'flex';
      matchCount++;
    } else {
      card.style.display = 'none';
    }
  });

  const countBadge = document.getElementById('statItemCount');
  if (countBadge && query) {
    countBadge.textContent = `${matchCount} Matching`;
  }
}

function switchFmView(viewMode) {
  const grid = document.getElementById('fmGridViewContainer');
  const list = document.getElementById('fmListViewContainer');
  const gridBtn = document.getElementById('fmViewGridBtn');
  const listBtn = document.getElementById('fmViewListBtn');

  if (viewMode === 'list') {
    grid?.classList.add('hidden');
    list?.classList.remove('hidden');
    gridBtn?.classList.remove('bg-indigo-50', 'text-indigo-600');
    gridBtn?.classList.add('text-slate-400');
    listBtn?.classList.add('bg-indigo-50', 'text-indigo-600');
    listBtn?.classList.remove('text-slate-400');
  } else {
    list?.classList.add('hidden');
    grid?.classList.remove('hidden');
    listBtn?.classList.remove('bg-indigo-50', 'text-indigo-600');
    listBtn?.classList.add('text-slate-400');
    gridBtn?.classList.add('bg-indigo-50', 'text-indigo-600');
    gridBtn?.classList.remove('text-slate-400');
  }
}

function sortFmItems(mode) {
  const container = document.getElementById('fmGridViewContainer');
  if (!container) return;
  const cards = Array.from(container.querySelectorAll('.fm-file-card'));
  
  cards.sort((a, b) => {
    const isDirA = Number(a.dataset.dir || 0);
    const isDirB = Number(b.dataset.dir || 0);
    if (isDirA !== isDirB) return isDirB - isDirA; // Folders always first

    if (mode === 'newest') return Number(b.dataset.mtime) - Number(a.dataset.mtime);
    if (mode === 'oldest') return Number(a.dataset.mtime) - Number(b.dataset.mtime);
    if (mode === 'size_desc') return Number(b.dataset.size) - Number(a.dataset.size);
    if (mode === 'name_asc') return (a.dataset.name || '').localeCompare(b.dataset.name || '');
    if (mode === 'name_desc') return (b.dataset.name || '').localeCompare(a.dataset.name || '');
    return 0;
  });

  cards.forEach(card => container.appendChild(card));
}

function setPaperLayout(layout) {
  const grid = document.getElementById('paperManagerGrid');
  if (!grid) return;
  grid.classList.toggle('grid', layout === 'grid');
  grid.classList.toggle('grid-cols-1', layout === 'list');
  grid.classList.toggle('sm:grid-cols-2', layout === 'grid');
  grid.classList.toggle('xl:grid-cols-3', layout === 'grid');
  grid.querySelectorAll('.paper-manager-item').forEach(el => {
    el.classList.toggle('flex', layout === 'list');
    el.classList.toggle('items-center', layout === 'list');
    el.classList.toggle('justify-between', layout === 'list');
  });
}
function filterGeneratedPapers(){const q=(document.getElementById('paperSearch')?.value||'').toLowerCase();document.querySelectorAll('.paper-manager-item').forEach(el=>{el.style.display=(el.dataset.paper||'').includes(q)?'':'none';});}
function setPaperLayout(layout){const g=document.getElementById('paperManagerGrid');if(!g)return;g.classList.toggle('grid-cols-1',layout==='list');g.classList.toggle('sm:grid-cols-2',layout==='grid');g.classList.toggle('xl:grid-cols-3',layout==='grid');g.querySelectorAll('.paper-manager-item').forEach(x=>{x.classList.toggle('flex',layout==='list');x.classList.toggle('items-center',layout==='list');x.classList.toggle('justify-between',layout==='list');});}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
