<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_role('TEACHING_STAFF');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$db = db();

if ($userId <= 0) {
    header('Location: ' . BASE_URL . '/teaching/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'Teaching Staff Dashboard';
$activeMenu = 'dashboard';
$pageCss = [
    'teaching-dashboard.css',
];

/*
|--------------------------------------------------------------------------
| Load page content into buffer
|--------------------------------------------------------------------------
*/

ob_start();

/*
|--------------------------------------------------------------------------
| Load courses assigned to the logged-in teaching staff
|--------------------------------------------------------------------------
|
| IMPORTANT:
| We never trust a user-supplied user_id here.
| The user ID comes only from the authenticated session.
|
*/

$stmt = $db->prepare(
    "SELECT
        tsc.id AS assignment_id,
        tsc.can_submit,

        ay.id AS academic_year_id,
        ay.name AS academic_year,

        p.id AS program_id,
        p.code AS program_code,
        p.name AS program_name,

        sy.id AS study_year_id,
        sy.name AS study_year,

        s.id AS semester_id,
        s.name AS semester,

        c.id AS course_id,
        c.code AS course_code,
        c.name AS course_name

    FROM teaching_staff_courses tsc

    INNER JOIN academic_years ay
        ON ay.id = tsc.academic_year_id

    INNER JOIN courses c
        ON c.id = tsc.course_id

    INNER JOIN programs p
        ON p.id = c.program_id

    INNER JOIN study_years sy
        ON sy.id = c.study_year_id

    INNER JOIN semesters s
        ON s.id = c.semester_id

    WHERE tsc.user_id = :user_id
      AND tsc.is_active = 1
      AND ay.is_active = 1
      AND c.is_active = 1

    ORDER BY
        ay.start_year DESC,
        p.name,
        sy.id,
        s.id,
        c.code"
);

$stmt->execute([
    ':user_id' => $userId,
]);

$assignedCourses = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Question counts
|--------------------------------------------------------------------------
*/

$questionCountStmt = $db->prepare(
    "SELECT
        qb.id AS question_bank_id,
        qb.course_id,
        qb.academic_year_id,
        qb.exam_type_id,
        qb.total_questions,
        qb.status AS bank_status,

        COUNT(q.id) AS actual_question_count,

        SUM(
            CASE
                WHEN q.status = 'DRAFT' THEN 1
                ELSE 0
            END
        ) AS draft_count,

        SUM(
            CASE
                WHEN q.status = 'SUBMITTED' THEN 1
                ELSE 0
            END
        ) AS submitted_count,

        SUM(
            CASE
                WHEN q.status = 'UNDER_REVIEW' THEN 1
                ELSE 0
            END
        ) AS review_count,

        SUM(
            CASE
                WHEN q.status = 'APPROVED' THEN 1
                ELSE 0
            END
        ) AS approved_count

    FROM question_banks qb

    LEFT JOIN questions q
        ON q.question_bank_id = qb.id
       AND q.is_current_version = 1
       AND q.is_active = 1

    WHERE qb.course_id = :course_id
      AND qb.academic_year_id = :academic_year_id
      AND qb.created_by = :user_id

    GROUP BY
        qb.id,
        qb.course_id,
        qb.academic_year_id,
        qb.exam_type_id,
        qb.total_questions,
        qb.status"
);

$bankStats = [];

foreach ($assignedCourses as $course) {

    $questionCountStmt->execute([
        ':course_id' => (int) $course['course_id'],
        ':academic_year_id' => (int) $course['academic_year_id'],
        ':user_id' => $userId,
    ]);

    $banks = $questionCountStmt->fetchAll();

    foreach ($banks as $bank) {
        $bankStats[(int) $bank['question_bank_id']] = $bank;
    }
}

?>

<div class="container-fluid py-4">

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

        <div>

            <h1 class="h3 mb-1">
                Teaching Staff Dashboard
            </h1>

            <p class="text-muted mb-0">
                Manage your assigned courses and question submissions.
            </p>

        </div>

        <div class="text-end">

            <div class="fw-semibold">
                <?= e(
                    $_SESSION['full_name']
                    ?? $_SESSION['username']
                    ?? 'Teaching Staff'
                ) ?>
            </div>

            <div class="small text-muted">
                <?= e(
                    $_SESSION['username']
                    ?? ''
                ) ?>
            </div>

        </div>

    </div>


    <!-- =====================================================
         NO COURSE ASSIGNMENTS
    ====================================================== -->

    <?php if (!$assignedCourses): ?>

        <div class="alert alert-info">

            <h5 class="alert-heading">
                No courses assigned
            </h5>

            <p class="mb-0">
                You currently do not have any active course assignments
                for this account.
                Please contact the COE/administrator.
            </p>

        </div>

    <?php else: ?>


        <!-- =================================================
             ASSIGNED COURSE CARDS
        ================================================== -->

        <div class="row g-4">

            <?php foreach ($assignedCourses as $course): ?>

                <?php

                $courseId = (int) $course['course_id'];

                $academicYearId =
                    (int) $course['academic_year_id'];

                $matchingBanks = [];

                foreach ($bankStats as $bank) {

                    if (
                        (int) $bank['course_id'] === $courseId
                        &&
                        (int) $bank['academic_year_id']
                            === $academicYearId
                    ) {
                        $matchingBanks[] = $bank;
                    }

                }

                $totalQuestions = 0;
                $draftQuestions = 0;
                $submittedQuestions = 0;
                $reviewQuestions = 0;
                $approvedQuestions = 0;

                foreach ($matchingBanks as $bank) {

                    $totalQuestions +=
                        (int) $bank['actual_question_count'];

                    $draftQuestions +=
                        (int) $bank['draft_count'];

                    $submittedQuestions +=
                        (int) $bank['submitted_count'];

                    $reviewQuestions +=
                        (int) $bank['review_count'];

                    $approvedQuestions +=
                        (int) $bank['approved_count'];
                }

                ?>

                <div class="col-12 col-xl-6">

                    <div class="card h-100 shadow-sm">

                        <div class="card-body">

                            <!-- =================================
                                 COURSE HEADER
                            ================================== -->

                            <div class="d-flex justify-content-between align-items-start mb-3">

                                <div>

                                    <span class="badge text-bg-primary mb-2">
                                        <?= e(
                                            $course['academic_year']
                                        ) ?>
                                    </span>

                                    <h4 class="card-title mb-1">

                                        <?= e(
                                            $course['course_code']
                                        ) ?>

                                    </h4>

                                    <div class="fw-semibold">

                                        <?= e(
                                            $course['course_name']
                                        ) ?>

                                    </div>

                                </div>


                                <?php if (
                                    (int) $course['can_submit'] === 1
                                ): ?>

                                    <span class="badge text-bg-success">
                                        Submission Enabled
                                    </span>

                                <?php else: ?>

                                    <span class="badge text-bg-secondary">
                                        Submission Disabled
                                    </span>

                                <?php endif; ?>

                            </div>


                            <!-- =================================
                                 COURSE INFORMATION
                            ================================== -->

                            <div class="row g-2 mb-4">

                                <div class="col-6 col-md-3">

                                    <div class="border rounded p-2">

                                        <div class="small text-muted">
                                            Program
                                        </div>

                                        <div class="fw-semibold">
                                            <?= e(
                                                $course['program_code']
                                            ) ?>
                                        </div>

                                    </div>

                                </div>


                                <div class="col-6 col-md-3">

                                    <div class="border rounded p-2">

                                        <div class="small text-muted">
                                            Year
                                        </div>

                                        <div class="fw-semibold">
                                            <?= e(
                                                $course['study_year']
                                            ) ?>
                                        </div>

                                    </div>

                                </div>


                                <div class="col-6 col-md-3">

                                    <div class="border rounded p-2">

                                        <div class="small text-muted">
                                            Semester
                                        </div>

                                        <div class="fw-semibold">
                                            <?= e(
                                                $course['semester']
                                            ) ?>
                                        </div>

                                    </div>

                                </div>


                                <div class="col-6 col-md-3">

                                    <div class="border rounded p-2">

                                        <div class="small text-muted">
                                            Questions
                                        </div>

                                        <div class="fw-semibold">
                                            <?= $totalQuestions ?>
                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =================================
                                 QUESTION STATUS
                            ================================== -->

                            <div class="row text-center g-2 mb-4">

                                <div class="col">

                                    <div class="bg-light rounded p-2">

                                        <div class="fw-bold">
                                            <?= $draftQuestions ?>
                                        </div>

                                        <div class="small text-muted">
                                            Draft
                                        </div>

                                    </div>

                                </div>


                                <div class="col">

                                    <div class="bg-light rounded p-2">

                                        <div class="fw-bold">
                                            <?= $submittedQuestions ?>
                                        </div>

                                        <div class="small text-muted">
                                            Submitted
                                        </div>

                                    </div>

                                </div>


                                <div class="col">

                                    <div class="bg-light rounded p-2">

                                        <div class="fw-bold">
                                            <?= $reviewQuestions ?>
                                        </div>

                                        <div class="small text-muted">
                                            Review
                                        </div>

                                    </div>

                                </div>


                                <div class="col">

                                    <div class="bg-light rounded p-2">

                                        <div class="fw-bold">
                                            <?= $approvedQuestions ?>
                                        </div>

                                        <div class="small text-muted">
                                            Approved
                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =================================
                                 ACTION BUTTONS
                            ================================== -->

                            <div class="d-flex flex-wrap gap-2">

                                <?php if (
                                    (int) $course['can_submit'] === 1
                                ): ?>

                                    <a
                                        href="<?= BASE_URL ?>/teaching/question-bank.php?course_id=<?= $courseId ?>&academic_year_id=<?= $academicYearId ?>"
                                        class="btn btn-primary"
                                    >
                                        Open Question Bank
                                    </a>


                                    <a
                                        href="<?= BASE_URL ?>/teaching/question-add.php?course_id=<?= $courseId ?>&academic_year_id=<?= $academicYearId ?>"
                                        class="btn btn-outline-primary"
                                    >
                                        Add Question
                                    </a>

                                <?php else: ?>

                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        disabled
                                    >
                                        Submission Disabled
                                    </button>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?php

/*
|--------------------------------------------------------------------------
| Finish content buffer
|--------------------------------------------------------------------------
*/

$content = ob_get_clean();

/*
|--------------------------------------------------------------------------
| Render common application layout
|--------------------------------------------------------------------------
*/

require __DIR__ . '/../includes/layout/base.php';