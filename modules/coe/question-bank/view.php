<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/question_bank_helpers.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    redirect(url('modules/coe/question-bank/'));
}

$stmt = $pdo->prepare('SELECT * FROM question_banks WHERE id = :id');
$stmt->execute(['id' => $id]);
$bank = $stmt->fetch();

if (!$bank) {
    flash('error', 'Question bank not found.');
    redirect(url('modules/coe/question-bank/'));
}

$stmt = $pdo->prepare('SELECT * FROM questions WHERE question_bank_id = :id ORDER BY question_no');
$stmt->execute(['id' => $id]);
$questions = $stmt->fetchAll();

$pageTitle = 'View Question Bank';

require_once __DIR__ . '/../../../includes/layout/header.php';
require_once __DIR__ . '/../../../includes/layout/navbar.php';
require_once __DIR__ . '/../../../includes/layout/sidebar.php';
?>

<main class="app-main">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <div class="text-uppercase small text-secondary fw-semibold">Question Bank</div>
            <h1 class="h3 mb-1"><?= e($bank['title']) ?></h1>
            <div class="text-secondary"><?= e($bank['bank_code']) ?> · <?= e($bank['course_code']) ?></div>
        </div>
        <a class="btn btn-primary" href="<?= e(url('modules/coe/question-bank/upload.php?bank_id=' . (int)$bank['id'])) ?>">
            Upload Questions
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>University</strong><br><?= e($bank['university']) ?></div>
                <div class="col-md-3"><strong>Department</strong><br><?= e($bank['department']) ?></div>
                <div class="col-md-3"><strong>Semester</strong><br><?= e($bank['semester']) ?></div>
                <div class="col-md-3"><strong>Academic Year</strong><br><?= e($bank['academic_year']) ?></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Question</th>
                    <th>Unit</th>
                    <th>Format</th>
                    <th>Bloom</th>
                    <th>Marks</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$questions): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-5">No questions imported yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($questions as $question): ?>
                    <tr>
                        <td><?= (int)$question['question_no'] ?></td>
                        <td style="min-width: 420px"><?= e($question['question_text']) ?></td>
                        <td><?= e($question['unit']) ?></td>
                        <td><?= e($question['question_format']) ?></td>
                        <td><?= e($question['bloom_level']) ?></td>
                        <td><?= e($question['marks']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>

<?php require_once __DIR__ . '/../../../includes/layout/footer.php'; ?>
