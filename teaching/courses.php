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

$pageTitle = 'My Courses';
$activeMenu = 'courses';
$pageCss = ['teaching-courses.css'];

ob_start();

/*
|--------------------------------------------------------------------------
| Load courses assigned to the logged-in teaching staff
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
        c.name AS course_name,
        c.credits

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
      AND p.is_active = 1

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
| Question statistics for each assigned course
|--------------------------------------------------------------------------
*/

$questionCountStmt = $db->prepare(
    "SELECT
        qb.id AS question_bank_id,
        qb.course_id,
        qb.academic_year_id,

        COUNT(q.id) AS question_count,

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
        qb.academic_year_id"
);

$courseStats = [];

foreach ($assignedCourses as $course) {

    $questionCountStmt->execute([
        ':course_id' => (int) $course['course_id'],
        ':academic_year_id' => (int) $course['academic_year_id'],
        ':user_id' => $userId,
    ]);

    $stats = [
        'question_count' => 0,
        'draft_count' => 0,
        'submitted_count' => 0,
        'review_count' => 0,
        'approved_count' => 0,
    ];

    foreach ($questionCountStmt->fetchAll() as $bank) {
        $stats['question_count'] += (int) $bank['question_count'];
        $stats['draft_count'] += (int) $bank['draft_count'];
        $stats['submitted_count'] += (int) $bank['submitted_count'];
        $stats['review_count'] += (int) $bank['review_count'];
        $stats['approved_count'] += (int) $bank['approved_count'];
    }

    $courseKey =
        (int) $course['course_id']
        . ':'
        . (int) $course['academic_year_id'];

    $courseStats[$courseKey] = $stats;
}

?>

<div class="teaching-courses-page">

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="teaching-courses-header">

        <div class="teaching-courses-heading">

            <span class="teaching-page-kicker">
                TEACHING
            </span>

            <h1>
                My Courses
            </h1>

            <p>
                View your assigned courses and manage question submissions.
            </p>

        </div>

        <div class="teaching-courses-summary">

            <div class="teaching-courses-summary-value">
                <?= count($assignedCourses) ?>
            </div>

            <div class="teaching-courses-summary-label">
                Assigned Courses
            </div>

        </div>

    </div>


    <!-- =====================================================
         EMPTY STATE
    ====================================================== -->

    <?php if (!$assignedCourses): ?>

        <div class="teaching-courses-empty">

            <div class="teaching-courses-empty-icon">
                ▦
            </div>

            <h2>
                No courses assigned
            </h2>

            <p>
                You currently do not have any active course assignments.
                Please contact the COE or administrator.
            </p>

        </div>

    <?php else: ?>


        <!-- =================================================
             COURSE GRID
        ================================================== -->

        <div class="teaching-courses-grid">

            <?php foreach ($assignedCourses as $course): ?>

                <?php

                $courseId = (int) $course['course_id'];
                $academicYearId = (int) $course['academic_year_id'];

                $courseKey =
                    $courseId . ':' . $academicYearId;

                $stats = $courseStats[$courseKey] ?? [
                    'question_count' => 0,
                    'draft_count' => 0,
                    'submitted_count' => 0,
                    'review_count' => 0,
                    'approved_count' => 0,
                ];

                ?>

                <article class="teaching-course-item">

                    <!-- =========================================
                         CARD TOP
                    ========================================== -->

                    <div class="teaching-course-item-top">

                        <div>

                            <span class="teaching-course-item-code">
                                <?= e($course['course_code']) ?>
                            </span>

                            <h2 class="teaching-course-item-title">
                                <?= e($course['course_name']) ?>
                            </h2>

                        </div>


                        <?php if ((int) $course['can_submit'] === 1): ?>

                            <span class="teaching-course-enabled">
                                <span></span>
                                Submission Enabled
                            </span>

                        <?php else: ?>

                            <span class="teaching-course-disabled">
                                Submission Disabled
                            </span>

                        <?php endif; ?>

                    </div>


                    <!-- =========================================
                         ACADEMIC INFORMATION
                    ========================================== -->

                    <div class="teaching-course-academic">

                        <div class="teaching-course-academic-item">

                            <span>
                                Academic Year
                            </span>

                            <strong>
                                <?= e($course['academic_year']) ?>
                            </strong>

                        </div>


                        <div class="teaching-course-academic-item">

                            <span>
                                Program
                            </span>

                            <strong>
                                <?= e($course['program_code']) ?>
                            </strong>

                        </div>


                        <div class="teaching-course-academic-item">

                            <span>
                                Year
                            </span>

                            <strong>
                                <?= e($course['study_year']) ?>
                            </strong>

                        </div>


                        <div class="teaching-course-academic-item">

                            <span>
                                Semester
                            </span>

                            <strong>
                                <?= e($course['semester']) ?>
                            </strong>

                        </div>


                        <?php if (
                            $course['credits'] !== null
                            && $course['credits'] !== ''
                        ): ?>

                            <div class="teaching-course-academic-item">

                                <span>
                                    Credits
                                </span>

                                <strong>
                                    <?= e($course['credits']) ?>
                                </strong>

                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- =========================================
                         QUESTION SUMMARY
                    ========================================== -->

                    <div class="teaching-course-question-header">

                        <div>

                            <h3>
                                Question Bank
                            </h3>

                            <p>
                                Current question status
                            </p>

                        </div>

                        <div class="teaching-course-total">

                            <strong>
                                <?= $stats['question_count'] ?>
                            </strong>

                            <span>
                                Questions
                            </span>

                        </div>

                    </div>


                    <div class="teaching-course-status">

                        <div class="teaching-course-status-item">

                            <strong>
                                <?= $stats['draft_count'] ?>
                            </strong>

                            <span>
                                Draft
                            </span>

                        </div>


                        <div class="teaching-course-status-item">

                            <strong>
                                <?= $stats['submitted_count'] ?>
                            </strong>

                            <span>
                                Submitted
                            </span>

                        </div>


                        <div class="teaching-course-status-item">

                            <strong>
                                <?= $stats['review_count'] ?>
                            </strong>

                            <span>
                                Review
                            </span>

                        </div>


                        <div class="teaching-course-status-item teaching-course-status-approved">

                            <strong>
                                <?= $stats['approved_count'] ?>
                            </strong>

                            <span>
                                Approved
                            </span>

                        </div>

                    </div>


                    <!-- =========================================
                         ACTIONS
                    ========================================== -->

                    <div class="teaching-course-actions">

                        <?php if ((int) $course['can_submit'] === 1): ?>

                            <a
                                href="<?= BASE_URL ?>/teaching/question-bank.php?course_id=<?= $courseId ?>&academic_year_id=<?= $academicYearId ?>"
                                class="teaching-course-primary-action"
                            >
                                <span>Open Question Bank</span>
                                <span>→</span>
                            </a>

                            <a
                                href="<?= BASE_URL ?>/teaching/question-add.php?course_id=<?= $courseId ?>&academic_year_id=<?= $academicYearId ?>"
                                class="teaching-course-secondary-action"
                            >
                                + Add Question
                            </a>

                        <?php else: ?>

                            <span class="teaching-course-action-disabled">
                                Submission is currently disabled
                            </span>

                        <?php endif; ?>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?php

$content = ob_get_clean();

require __DIR__ . '/../includes/layout/base.php';
