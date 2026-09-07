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
        'id' => $id,
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
                UPDATE question_banks SET
                    bank_code = :bank_code,
                    title = :title,
                    university = :university,
                    department = :department,
                    course_code = :course_code,
                    course_name = :course_name,
                    semester = :semester,
                    academic_year = :academic_year,
                    status = :status
                WHERE id = :id
            ');
            $stmt->execute($data);
            flash('success', 'Question bank updated successfully.');
            redirect(url('modules/coe/question-bank/view.php?id=' . $id));
        } catch (PDOException $e) {
            $errors[] = 'Unable to update the question bank. Check whether the bank code already exists.';
        }
    }

    $bank = array_merge($bank, $data);
}

$pageTitle = 'Edit Question Bank';

require_once __DIR__ . '/../../../includes/layout/header.php';
require_once __DIR__ . '/../../../includes/layout/navbar.php';
require_once __DIR__ . '/../../../includes/layout/sidebar.php';
?>

<main class="app-main">
<div class="container-fluid py-4">
    <div class="mb-4">
        <div class="text-uppercase small text-secondary fw-semibold">Question Bank</div>
        <h1 class="h3">Edit Question Bank</h1>
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
                               value="<?= e($bank[$name] ?? '') ?>">
                    </div>
                <?php endforeach; ?>

                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach (['draft','active','archived'] as $value): ?>
                            <option value="<?= e($value) ?>" <?= ($bank['status'] ?? '') === $value ? 'selected' : '' ?>>
                                <?= e(ucfirst($value)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Save Changes</button>
                    <a class="btn btn-outline-secondary" href="<?= e(url('modules/coe/question-bank/view.php?id=' . $id)) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</main>

<?php require_once __DIR__ . '/../../../includes/layout/footer.php'; ?>
