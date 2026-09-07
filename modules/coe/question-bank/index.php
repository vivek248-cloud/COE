<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/question_bank_helpers.php';

$pageTitle = 'Question Bank';

$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$sql = '
    SELECT
        qb.*,
        COUNT(q.id) AS actual_questions
    FROM question_banks qb
    LEFT JOIN questions q
        ON q.question_bank_id = qb.id
';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        qb.bank_code LIKE :search
        OR qb.title LIKE :search
        OR qb.course_code LIKE :search
        OR qb.course_name LIKE :search
    )';

    $params['search'] = '%' . $search . '%';
}

if ($status !== '') {
    $where[] = 'qb.status = :status';
    $params['status'] = $status;
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= '
    GROUP BY qb.id
    ORDER BY qb.id DESC
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$banks = $stmt->fetchAll();

require_once __DIR__ . '/../../../includes/layout/header.php';
require_once __DIR__ . '/../../../includes/layout/navbar.php';
require_once __DIR__ . '/../../../includes/layout/sidebar.php';
?>

<main class="app-main">
    <div class="container-fluid py-4">

        <!-- PAGE HEADER -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

            <div>
                <div class="text-uppercase small text-secondary fw-semibold">
                    COE Portal
                </div>

                <h1 class="h3 mb-1">
                    Question Bank
                </h1>

                <p class="text-secondary mb-0">
                    Manage question banks and import questions.
                </p>
            </div>

            <div class="d-flex gap-2">



                <a
                    class="btn btn-primary"
                    href="<?= e(url('modules/coe/question-bank/create.php')) ?>"
                >
                    <i class="bi bi-plus-lg me-1"></i>
                    Add Question Bank
                </a>

            </div>

        </div>


        <!-- FLASH SUCCESS -->
        <?php if ($message = flash('success')): ?>

            <div class="alert alert-success alert-dismissible fade show" role="alert">

                <?= e($message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>


        <!-- FLASH ERROR -->
        <?php if ($message = flash('error')): ?>

            <div class="alert alert-danger alert-dismissible fade show" role="alert">

                <?= e($message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>


        <!-- SEARCH / FILTER -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body">

                <form method="get" class="row g-2">

                    <div class="col-md-7">

                        <input
                            type="search"
                            class="form-control"
                            name="q"
                            value="<?= e($search) ?>"
                            placeholder="Search bank code, title, course code or course name"
                        >

                    </div>


                    <div class="col-md-3">

                        <select
                            class="form-select"
                            name="status"
                        >

                            <option value="">
                                All statuses
                            </option>

                            <option
                                value="active"
                                <?= $status === 'active' ? 'selected' : '' ?>
                            >
                                Active
                            </option>

                            <option
                                value="draft"
                                <?= $status === 'draft' ? 'selected' : '' ?>
                            >
                                Draft
                            </option>

                            <option
                                value="archived"
                                <?= $status === 'archived' ? 'selected' : '' ?>
                            >
                                Archived
                            </option>

                        </select>

                    </div>


                    <div class="col-md-2 d-grid">

                        <button
                            type="submit"
                            class="btn btn-dark"
                        >
                            <i class="bi bi-search me-1"></i>
                            Search
                        </button>

                    </div>

                </form>

            </div>

        </div>


        <!-- QUESTION BANK TABLE -->
        <div class="card border-0 shadow-sm">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead>

                    <tr>

                        <th>
                            Bank
                        </th>

                        <th>
                            Course
                        </th>

                        <th>
                            Semester
                        </th>

                        <th>
                            Academic Year
                        </th>

                        <th>
                            Questions
                        </th>

                        <th>
                            Status
                        </th>

                        <th class="text-end">
                            Actions
                        </th>

                    </tr>

                    </thead>


                    <tbody>

                    <?php if (!$banks): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center text-secondary py-5"
                            >

                                <div class="mb-2">
                                    <i class="bi bi-database-x fs-2"></i>
                                </div>

                                No question banks found.

                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach ($banks as $bank): ?>

                        <tr>

                            <!-- BANK -->
                            <td>

                                <div class="fw-semibold">
                                    <?= e($bank['bank_code']) ?>
                                </div>

                                <div class="small text-secondary">
                                    <?= e($bank['title']) ?>
                                </div>

                            </td>


                            <!-- COURSE -->
                            <td>

                                <div class="fw-semibold">
                                    <?= e($bank['course_code']) ?>
                                </div>

                                <div class="small text-secondary">
                                    <?= e($bank['course_name']) ?>
                                </div>

                            </td>


                            <!-- SEMESTER -->
                            <td>
                                <?= e($bank['semester']) ?>
                            </td>


                            <!-- ACADEMIC YEAR -->
                            <td>
                                <?= e($bank['academic_year']) ?>
                            </td>


                            <!-- QUESTION COUNT -->
                            <td>

                                <span class="badge text-bg-light border">

                                    <i class="bi bi-list-ol me-1"></i>

                                    <?= (int)$bank['actual_questions'] ?>

                                </span>

                            </td>


                            <!-- STATUS -->
                            <td>

                                <?php

                                $statusClass = match ($bank['status']) {

                                    'active' => 'success',

                                    'draft' => 'warning',

                                    'archived' => 'secondary',

                                    default => 'dark',

                                };

                                ?>

                                <span class="badge text-bg-<?= $statusClass ?>">

                                    <?= e(ucfirst($bank['status'])) ?>

                                </span>

                            </td>


                            <!-- ACTIONS -->
                            <td class="text-end">

                                <div class="btn-group" role="group">

                                    <a
                                        class="btn btn-sm btn-outline-success"
                                        href="<?= e(
                                            url(
                                                'modules/coe/question-bank/upload.php?bank_id='
                                                . (int)$bank['id']
                                            )
                                        ) ?>"
                                        title="Upload Questions"
                                    >
                                        <i class="bi bi-upload"></i>
                                    </a>
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="<?= e(
                                            url(
                                                'modules/coe/question-bank/view.php?id='
                                                . (int)$bank['id']
                                            )
                                        ) ?>"
                                        title="View"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>


                                    <a
                                        class="btn btn-sm btn-outline-secondary"
                                        href="<?= e(
                                            url(
                                                'modules/coe/question-bank/edit.php?id='
                                                . (int)$bank['id']
                                            )
                                        ) ?>"
                                        title="Edit"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </a>


                                    <a
                                        class="btn btn-sm btn-outline-danger"
                                        href="<?= e(
                                            url(
                                                'modules/coe/question-bank/delete.php?id='
                                                . (int)$bank['id']
                                            )
                                        ) ?>"
                                        data-confirm="Delete this question bank? All its questions will also be deleted."
                                        title="Delete"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </a>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>
</main>


<?php
require_once __DIR__ . '/../../../includes/layout/footer.php';
?>