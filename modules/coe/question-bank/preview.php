<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/question_bank_helpers.php';

$upload = $_SESSION['qbank_upload'] ?? null;

if (!$upload || empty($upload['rows'])) {
    flash('error', 'No upload is waiting for preview.');
    redirect(url('modules/coe/question-bank/upload.php'));
}

$rows = $upload['rows'];
$metadata = $upload['metadata'];
$bankId = $upload['bank_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($rows as $i => &$row) {
        $row['unit'] = trim((string)($_POST['unit'][$i] ?? ''));
        $row['question_format'] = trim((string)($_POST['question_format'][$i] ?? ''));
        $row['bloom_level'] = strtoupper(trim((string)($_POST['bloom_level'][$i] ?? '')));
        $row['marks'] = trim((string)($_POST['marks'][$i] ?? ''));
        $row['question_no'] = (int)($_POST['question_no'][$i] ?? ($i + 1));
    }
    unset($row);

    $validation = qbank_validate_rows($pdo, $rows, $bankId);
    $errors = $validation['errors'];

    if (!$errors && isset($_POST['confirm_import'])) {
        try {
            $pdo->beginTransaction();

            if (!$bankId) {
                $stmt = $pdo->prepare('
                    INSERT INTO question_banks
                    (bank_code, title, university, department, course_code, course_name, semester, academic_year, source_file, status, total_questions)
                    VALUES
                    (:bank_code, :title, :university, :department, :course_code, :course_name, :semester, :academic_year, :source_file, :status, 0)
                ');
                $stmt->execute([
                    'bank_code' => $metadata['bank_code'],
                    'title' => $metadata['title'],
                    'university' => $metadata['university'],
                    'department' => $metadata['department'],
                    'course_code' => $metadata['course_code'],
                    'course_name' => $metadata['course_name'],
                    'semester' => $metadata['semester'],
                    'academic_year' => $metadata['academic_year'],
                    'source_file' => basename((string)$upload['path']),
                    'status' => $metadata['status'] ?? 'active',
                ]);
                $bankId = (int)$pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare('
                    UPDATE question_banks
                    SET source_file = :source_file
                    WHERE id = :id
                ');
                $stmt->execute([
                    'source_file' => basename((string)$upload['path']),
                    'id' => $bankId,
                ]);
            }

            $insert = $pdo->prepare('
                INSERT INTO questions
                (question_bank_id, question_no, question_text, unit, question_format, bloom_level, marks, normalized_text)
                VALUES
                (:question_bank_id, :question_no, :question_text, :unit, :question_format, :bloom_level, :marks, :normalized_text)
            ');

            foreach ($rows as $row) {
                $normalizedText = qbank_normalize_text((string)$row['question_text']);
                $insert->execute([
                    'question_bank_id' => $bankId,
                    'question_no' => (int)$row['question_no'],
                    'question_text' => trim((string)$row['question_text']),
                    'unit' => trim((string)$row['unit']),
                    'question_format' => trim((string)$row['question_format']),
                    'bloom_level' => strtoupper(trim((string)$row['bloom_level'])),
                    'marks' => (float)$row['marks'],
                    'normalized_text' => $normalizedText,
                ]);
            }

            $stmt = $pdo->prepare('
                UPDATE question_banks
                SET total_questions = (
                    SELECT COUNT(*) FROM questions WHERE question_bank_id = :question_bank_id
                )
                WHERE id = :question_bank_id_2
            ');
            $stmt->execute([
                'question_bank_id' => $bankId,
                'question_bank_id_2' => $bankId,
            ]);

            $pdo->commit();

            unset($_SESSION['qbank_upload']);
            flash('success', count($rows) . ' questions imported successfully.');
            redirect(url('modules/coe/question-bank/view.php?id=' . $bankId));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Import failed and no partial data was saved. ' . $e->getMessage();
        }
    }
} else {
    $validation = qbank_validate_rows($pdo, $rows, $bankId);
    $errors = $validation['errors'];
}

$pageTitle = 'Preview Question Bank';

require_once __DIR__ . '/../../../includes/layout/header.php';
require_once __DIR__ . '/../../../includes/layout/navbar.php';
require_once __DIR__ . '/../../../includes/layout/sidebar.php';
?>

<main class="app-main">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <div class="text-uppercase small text-secondary fw-semibold">Question Bank</div>
            <h1 class="h3 mb-1">Preview Import</h1>
            <p class="text-secondary mb-0">
                <?= count($rows) ?> questions detected from <?= e(strtoupper($upload['extension'])) ?>.
            </p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-2">Validation failed</div>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation failed',
                        html: <?= json_encode('<ul style="text-align:left">' . implode('', array_map(
                            static fn($e) => '<li>' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</li>',
                            $errors
                        )) . '</ul>') ?>,
                        confirmButtonText: 'Review'
                    });
                }
            });
        </script>
    <?php else: ?>
        <div class="alert alert-success">
            All questions passed the Phase 2 validation checks. Review the data and confirm import.
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <strong><?= e($metadata['title'] ?? 'Question Bank') ?></strong>
        </div>
        <div class="card-body p-0">
            <form method="post">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead>
                        <tr>
                            <th style="width:70px">#</th>
                            <th style="min-width:420px">Question</th>
                            <th style="width:120px">Unit</th>
                            <th style="width:180px">Format</th>
                            <th style="width:110px">Bloom</th>
                            <th style="width:100px">Marks</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $i => $row): ?>
                            <tr>
                                <td>
                                    <input class="form-control" type="number" min="1"
                                           name="question_no[<?= $i ?>]"
                                           value="<?= e($row['question_no'] ?? ($i + 1)) ?>">
                                </td>
                                <td>
                                    <textarea class="form-control" rows="3" readonly><?= e($row['question_text']) ?></textarea>
                                </td>
                                <td>
                                    <input class="form-control" name="unit[<?= $i ?>]"
                                           value="<?= e($row['unit'] ?? '') ?>" required
                                           placeholder="I / II / III">
                                </td>
                                <td>
                                    <select class="form-select" name="question_format[<?= $i ?>]" required>
                                        <option value="">Select</option>
                                        <?php foreach (qbank_allowed_formats() as $format): ?>
                                            <option value="<?= e($format) ?>"
                                                <?= ($row['question_format'] ?? '') === $format ? 'selected' : '' ?>>
                                                <?= e($format) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-select" name="bloom_level[<?= $i ?>]" required>
                                        <option value="">Select</option>
                                        <?php foreach (qbank_allowed_bloom() as $bloom): ?>
                                            <option value="<?= e($bloom) ?>"
                                                <?= strtoupper((string)($row['bloom_level'] ?? '')) === $bloom ? 'selected' : '' ?>>
                                                <?= e($bloom) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input class="form-control" type="number" min="0.01" step="0.01"
                                           name="marks[<?= $i ?>]"
                                           value="<?= e($row['marks'] ?? '') ?>" required>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 d-flex gap-2 justify-content-end">
                    <a class="btn btn-outline-secondary" href="<?= e(url('modules/coe/question-bank/upload.php' . ($bankId ? '?bank_id=' . (int)$bankId : ''))) ?>">
                        Cancel
                    </a>
                    <button class="btn btn-primary" name="confirm_import" value="1" <?= $errors ? 'disabled' : '' ?>>
                        <i class="bi bi-database-check me-1"></i> Confirm Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</main>

<?php require_once __DIR__ . '/../../../includes/layout/footer.php'; ?>
