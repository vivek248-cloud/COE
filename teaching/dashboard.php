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
| Load courses assigned to logged-in teaching staff
|--------------------------------------------------------------------------
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
| Question bank statistics
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

/*
|--------------------------------------------------------------------------
| Dashboard totals
|--------------------------------------------------------------------------
*/

$totalCourses = count($assignedCourses);
$totalBanks = count($bankStats);

$totalQuestions = 0;
$totalDraft = 0;
$totalSubmitted = 0;
$totalReview = 0;
$totalApproved = 0;

foreach ($bankStats as $bank) {

    $totalQuestions += (int) $bank['actual_question_count'];

    $totalDraft += (int) $bank['draft_count'];

    $totalSubmitted += (int) $bank['submitted_count'];

    $totalReview += (int) $bank['review_count'];

    $totalApproved += (int) $bank['approved_count'];
}

/*
|--------------------------------------------------------------------------
| User display
|--------------------------------------------------------------------------
*/

$displayName = $_SESSION['full_name']
    ?? $_SESSION['username']
    ?? 'Teaching Staff';

$username = $_SESSION['username'] ?? '';

/*
|--------------------------------------------------------------------------
| Dashboard UI
|--------------------------------------------------------------------------
*/

?>

<div class="container-fluid">

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="teaching-dashboard-header">

        <div class="teaching-dashboard-heading">

            <div class="teaching-welcome">
                Welcome back, <?= e($displayName) ?>
            </div>

            <h1>
                Teaching Staff Dashboard
            </h1>

            <p>
                Manage your assigned courses and question submissions.
            </p>

        </div>

    </div>


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="teaching-stats">

        <!-- Courses -->

        <div class="teaching-stat-card">

            <div class="teaching-stat-icon">
                ▦
            </div>

            <div class="teaching-stat-content">

                <span class="teaching-stat-label">
                    Assigned Courses
                </span>

                <span class="teaching-stat-value">
                    <?= $totalCourses ?>
                </span>

            </div>

        </div>


        <!-- Question Banks -->

        <div class="teaching-stat-card">

            <div class="teaching-stat-icon">
                ▤
            </div>

            <div class="teaching-stat-content">

                <span class="teaching-stat-label">
                    Question Banks
                </span>

                <span class="teaching-stat-value">
                    <?= $totalBanks ?>
                </span>

            </div>

        </div>


        <!-- Questions -->

        <div class="teaching-stat-card">

            <div class="teaching-stat-icon">
                #
            </div>

            <div class="teaching-stat-content">

                <span class="teaching-stat-label">
                    Total Questions
                </span>

                <span class="teaching-stat-value">
                    <?= $totalQuestions ?>
                </span>

            </div>

        </div>


        <!-- Approved -->

        <div class="teaching-stat-card">

            <div class="teaching-stat-icon">
                ✓
            </div>

            <div class="teaching-stat-content">

                <span class="teaching-stat-label">
                    Approved Questions
                </span>

                <span class="teaching-stat-value">
                    <?= $totalApproved ?>
                </span>

            </div>

        </div>

    </div>


    <!-- =====================================================
         ASSIGNED COURSES
    ====================================================== -->

    <div class="teaching-section-header">

        <div>

            <h2 class="teaching-section-title">
                My Assigned Courses
            </h2>

            <p class="teaching-section-subtitle">
                Courses currently assigned to your account.
            </p>

        </div>

    </div>


    <!-- =====================================================
         NO COURSES
    ====================================================== -->

    <?php if (!$assignedCourses): ?>

        <div class="teaching-empty-state">

            <div class="teaching-empty-state-icon">
                ▦
            </div>

            <h3>
                No courses assigned
            </h3>

            <p>
                You currently do not have any active course assignments.
                Please contact the COE or administrator.
            </p>

        </div>

    <?php else: ?>


        <!-- =================================================
             COURSE GRID
        ================================================== -->

        <div class="teaching-course-grid">

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

                /*
                |--------------------------------------------------------------------------
                | Course question statistics
                |--------------------------------------------------------------------------
                */

                $courseQuestions = 0;
                $courseDraft = 0;
                $courseSubmitted = 0;
                $courseReview = 0;
                $courseApproved = 0;

                foreach ($matchingBanks as $bank) {

                    $courseQuestions +=
                        (int) $bank['actual_question_count'];

                    $courseDraft +=
                        (int) $bank['draft_count'];

                    $courseSubmitted +=
                        (int) $bank['submitted_count'];

                    $courseReview +=
                        (int) $bank['review_count'];

                    $courseApproved +=
                        (int) $bank['approved_count'];
                }

                ?>

                <!-- =================================================
                     COURSE CARD
                ================================================== -->

                <article class="teaching-course-card">


                    <!-- =============================================
                         COURSE HEADER
                    ============================================== -->

                    <div class="teaching-course-header">

                        <div class="teaching-course-heading">

                            <span class="teaching-course-code">
                                <?= e($course['course_code']) ?>
                            </span>

                            <h3 class="teaching-course-name">
                                <?= e($course['course_name']) ?>
                            </h3>

                            <p class="teaching-course-bank-title">
                                <?= e($course['program_name']) ?>
                            </p>

                        </div>


                        <?php if (
                            (int) $course['can_submit'] === 1
                        ): ?>

                            <span class="teaching-submission-status">
                                Submission Enabled
                            </span>

                        <?php else: ?>

                            <span
                                class="teaching-submission-status"
                                style="
                                    background:#f1f5f9;
                                    color:#64748b;
                                "
                            >
                                Submission Disabled
                            </span>

                        <?php endif; ?>

                    </div>


                    <!-- =============================================
                         COURSE INFORMATION
                    ============================================== -->

                    <div class="teaching-course-details">

                        <!-- Program -->

                        <div class="teaching-course-detail">

                            <span class="teaching-course-detail-label">
                                Program
                            </span>

                            <span class="teaching-course-detail-value">
                                <?= e($course['program_code']) ?>
                            </span>

                        </div>


                        <!-- Year -->

                        <div class="teaching-course-detail">

                            <span class="teaching-course-detail-label">
                                Year
                            </span>

                            <span class="teaching-course-detail-value">
                                <?= e($course['study_year']) ?>
                            </span>

                        </div>


                        <!-- Semester -->

                        <div class="teaching-course-detail">

                            <span class="teaching-course-detail-label">
                                Semester
                            </span>

                            <span class="teaching-course-detail-value">
                                <?= e($course['semester']) ?>
                            </span>

                        </div>


                        <!-- Academic Year -->

                        <div class="teaching-course-detail">

                            <span class="teaching-course-detail-label">
                                Academic Year
                            </span>

                            <span class="teaching-course-detail-value">
                                <?= e($course['academic_year']) ?>
                            </span>

                        </div>


                        <!-- Questions -->

                        <div class="teaching-course-detail">

                            <span class="teaching-course-detail-label">
                                Questions
                            </span>

                            <span class="teaching-course-detail-value">
                                <?= $courseQuestions ?>
                            </span>

                        </div>


                        <!-- Approved -->

                        <div class="teaching-course-detail">

                            <span class="teaching-course-detail-label">
                                Approved
                            </span>

                            <span class="teaching-course-detail-value">
                                <?= $courseApproved ?>
                            </span>

                        </div>

                    </div>


                    <!-- =============================================
                         QUESTION STATUS
                    ============================================== -->

                    <div
                        class="teaching-course-status-grid"
                        style="
                            display:grid;
                            grid-template-columns:repeat(4,minmax(0,1fr));
                            gap:8px;
                            padding:18px 24px 0;
                        "
                    >

                        <div
                            style="
                                padding:10px;
                                text-align:center;
                                background:#f8fafc;
                                border-radius:9px;
                            "
                        >

                            <div
                                style="
                                    font-size:17px;
                                    font-weight:750;
                                    color:#111827;
                                "
                            >
                                <?= $courseDraft ?>
                            </div>

                            <div
                                style="
                                    margin-top:2px;
                                    font-size:10px;
                                    color:#94a3b8;
                                "
                            >
                                Draft
                            </div>

                        </div>


                        <div
                            style="
                                padding:10px;
                                text-align:center;
                                background:#f8fafc;
                                border-radius:9px;
                            "
                        >

                            <div
                                style="
                                    font-size:17px;
                                    font-weight:750;
                                    color:#111827;
                                "
                            >
                                <?= $courseSubmitted ?>
                            </div>

                            <div
                                style="
                                    margin-top:2px;
                                    font-size:10px;
                                    color:#94a3b8;
                                "
                            >
                                Submitted
                            </div>

                        </div>


                        <div
                            style="
                                padding:10px;
                                text-align:center;
                                background:#f8fafc;
                                border-radius:9px;
                            "
                        >

                            <div
                                style="
                                    font-size:17px;
                                    font-weight:750;
                                    color:#111827;
                                "
                            >
                                <?= $courseReview ?>
                            </div>

                            <div
                                style="
                                    margin-top:2px;
                                    font-size:10px;
                                    color:#94a3b8;
                                "
                            >
                                Review
                            </div>

                        </div>


                        <div
                            style="
                                padding:10px;
                                text-align:center;
                                background:#f0fdf4;
                                border-radius:9px;
                            "
                        >

                            <div
                                style="
                                    font-size:17px;
                                    font-weight:750;
                                    color:#16a34a;
                                "
                            >
                                <?= $courseApproved ?>
                            </div>

                            <div
                                style="
                                    margin-top:2px;
                                    font-size:10px;
                                    color:#64748b;
                                "
                            >
                                Approved
                            </div>

                        </div>

                    </div>


                    <!-- =============================================
                         COURSE FOOTER
                    ============================================== -->

                    <div class="teaching-course-footer">

                        <div class="teaching-question-count">

                            <div class="teaching-question-count-icon">
                                ?
                            </div>

                            <div class="teaching-question-count-text">

                                <span class="teaching-question-count-label">
                                    Total Questions
                                </span>

                                <span class="teaching-question-count-value">
                                    <?= $courseQuestions ?>
                                </span>

                            </div>

                        </div>


                        <div>

                            <?php if (
                                (int) $course['can_submit'] === 1
                            ): ?>

                                <a
                                    href="<?= BASE_URL ?>/teaching/question-bank.php?course_id=<?= $courseId ?>&academic_year_id=<?= $academicYearId ?>"
                                    class="teaching-course-action"
                                >
                                    Open Question Bank
                                    <span>→</span>
                                </a>

                            <?php else: ?>

                                <button
                                    type="button"
                                    class="teaching-course-action"
                                    disabled
                                    style="
                                        background:#94a3b8;
                                        cursor:not-allowed;
                                    "
                                >
                                    Submission Disabled
                                </button>

                            <?php endif; ?>

                        </div>

                    </div>

                </article>

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