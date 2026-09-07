<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/question_bank_helpers.php';

$pageTitle = 'Add Question Bank';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'bank_code' => trim((string)($_POST['bank_code'] ?? '')),
        'title' => trim((string)($_POST['title'] ?? '')),
        'university' => trim((string)($_POST['university'] ?? '')),
        'department' => trim((string)($_POST['department'] ?? '')),
        'course_code' => trim((string)($_POST['course_code'] ?? '')),
        'course_name' => trim((string)($_POST['course_name'] ?? '')),
        'semester' => trim((string)($_POST['semester'] ?? '')),
        'academic_year' => trim((string)($_POST['academic_year'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'draft')),
    ];

    $errors = [];
    foreach (['bank_code', 'title', 'course_code', 'course_name', 'semester', 'academic_year'] as $field) {
        if ($data[$field] === '') {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO question_banks
                (bank_code, title, university, department, course_code, course_name, semester, academic_year, source_file, status, total_questions)
                VALUES
                (:bank_code, :title, :university, :department, :course_code, :course_name, :semester, :academic_year, NULL, :status, 0)
            ');
            $stmt->execute($data);

            flash('success', 'Question bank created successfully.');
            redirect(url('modules/coe/question-bank/'));
        } catch (PDOException $e) {
            $errors[] = 'Unable to create the question bank. Check whether the bank code already exists.';
        }
    }
}

require_once __DIR__ . '/../../../includes/layout/header.php';
require_once __DIR__ . '/../../../includes/layout/navbar.php';
require_once __DIR__ . '/../../../includes/layout/sidebar.php';
?>

<main class="app-main">
<div class="container-fluid py-4">
    <div class="mb-4">
        <div class="text-uppercase small text-secondary fw-semibold">COE Portal</div>
        <h1 class="h3">Add Question Bank</h1>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post" class="row g-3">
                <?php
                $fields = [
                    'bank_code' => 'Bank Code',
                    'title' => 'Title',
                    'university' => 'University',
                    'department' => 'Department',
                    'course_code' => 'Course Code',
                    'course_name' => 'Course Name',
                    'semester' => 'Semester',
                    'academic_year' => 'Academic Year',
                ];
                foreach ($fields as $name => $label):
                ?>
                    <div class="col-md-6">
                        <label class="form-label"><?= e($label) ?></label>
                        <input class="form-control" name="<?= e($name) ?>" required
                               value="<?= e($_POST[$name] ?? '') ?>">
                    </div>
                <?php endforeach; ?>

                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Create Bank</button>
                    <a class="btn btn-outline-secondary" href="<?= e(url('modules/coe/question-bank/')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</main>

<?php require_once __DIR__ . '/../../../includes/layout/footer.php'; ?>
