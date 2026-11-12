<?php
/**
 * Page 6: Submit Course - Consolidated Review
 * Holy Cross College (Autonomous) - COE Question Paper System
 * Shows all 3 components (Question Bank, Master Blueprint, Final Blueprint) with validation before HOD submit
 */
declare(strict_types=1);
define('PAGE_TITLE', 'Submit Course');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/workflow.php';

requireTeachingStaff();
$user = getCurrentUser();
$pdo = getDBConnection();

// Ensure schema
qps_ensure_question_draft_schema($pdo);

// CSRF
if (empty($_SESSION['qps_submit_course_csrf'])) {
    $_SESSION['qps_submit_course_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['qps_submit_course_csrf'];

// Get current academic context
$defaultAcademicYear = qps_setting($pdo, 'default_academic_year', DEFAULT_ACADEMIC_YEAR);
$defaultRegulation = qps_setting($pdo, 'default_regulation', DEFAULT_REGULATION);

// Get filter params
$filterCourse = strtoupper(trim((string)($_GET['course_code'] ?? '')));
$filterSemester = trim((string)($_GET['semester'] ?? ''));
$filterYear = trim((string)($_GET['academic_year'] ?? $defaultAcademicYear));

// Fetch allocated courses for dropdown
$allocatedCourses = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT tf.papercode AS course_code,
               COALESCE(c.coursetitle, tf.papercode) AS course_title,
               c.dept_code, c.semester, c.qpattern, c.type as course_type
        FROM timetablefaculty tf
        LEFT JOIN courses c ON UPPER(tf.papercode) = UPPER(c.coursecode)
        WHERE UPPER(tf.fid) = UPPER(?)
        ORDER BY tf.papercode
    ");
    $stmt->execute([$user['staff_code']]);
    $allocatedCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[Submit Course] Course load failed: ' . $e->getMessage());
}

// Component data
$questionBank = null;
$masterBlueprint = null;
$finalBlueprint = null;
$submissionPackage = null;
$validationResults = [];
$overallStatus = 'incomplete'; // incomplete | ready | submitted | error

if ($filterCourse && $filterSemester && $filterYear) {
    try {
        // Verify allocation
        $check = $pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1');
        $check->execute([$user['staff_code'], $filterCourse]);
        if (!$check->fetchColumn()) {
            $validationResults[] = ['component' => 'Access', 'status' => 'error', 'message' => 'Not allocated to this course.'];
        } else {
            // Get course info
            $examType = 'Odd Semester End Examination';
            $paperType = 'Theory';
            $deptCode = null;
            $stmt = $pdo->prepare("SELECT qpattern, type as course_type, dept_code FROM courses WHERE UPPER(coursecode)=UPPER(?)");
            $stmt->execute([$filterCourse]);
            $course = $stmt->fetch();
            if ($course) {
                $paperType = hcc_paper_types()[$course['qpattern']] ?? $course['qpattern'];
                $examType = hcc_exam_types()[$course['course_type']] ?? 'Odd Semester End Examination';
                $deptCode = $course['dept_code'];
            }

            // ---- COMPONENT 1: QUESTION BANK DRAFT ----
            $stmt = $pdo->prepare("
                SELECT * FROM qps_question_bank_drafts
                WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$user['staff_code'], $filterCourse, $filterSemester, $filterYear]);
            $draft = $stmt->fetch();

            if ($draft) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt, SUM(marks) as marks_sum
                    FROM qps_question_bank_draft_questions
                    WHERE draft_id = ? AND staff_code = ?
                ");
                $stmt->execute([$draft['id'], $user['staff_code']]);
                $qbTotals = $stmt->fetch();
                $qbQuestionCount = (int)($qbTotals['cnt'] ?? 0);
                $qbTotalMarks = (int)($qbTotals['marks_sum'] ?? 0);

                $questionBank = [
                    'id' => (int)$draft['id'],
                    'status' => $draft['status'],
                    'question_count' => $qbQuestionCount,
                    'total_marks' => $qbTotalMarks,
                    'version' => (int)$draft['version_no'],
                    'updated_at' => $draft['updated_at'],
                    'max_marks' => (int)$draft['max_marks'],
                    'regulation' => $draft['regulation'],
                ];

                // Validation for QB
                if ($qbQuestionCount === 0) {
                    $validationResults[] = ['component' => 'Question Bank', 'status' => 'error', 'message' => 'No questions added.'];
                } elseif ($qbTotalMarks < 75) {
                    $validationResults[] = ['component' => 'Question Bank', 'status' => 'warning', 'message' => "Total marks ($qbTotalMarks) less than standard 75 marks."];
                } else {
                    $validationResults[] = ['component' => 'Question Bank', 'status' => 'success', 'message' => "$qbQuestionCount questions, $qbTotalMarks marks."];
                }

                // Check blueprint sync
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM qps_question_bank_draft_questions
                    WHERE draft_id = ? AND correction_status = 'CLEAN'
                ");
                $stmt->execute([$draft['id']]);
                $cleanCount = (int)$stmt->fetchColumn();
                if ($cleanCount < $qbQuestionCount) {
                    $validationResults[] = ['component' => 'Question Bank', 'status' => 'warning', 'message' => "$cleanCount/$qbQuestionCount questions are clean (no HOD corrections pending)."];
                }
            } else {
                $validationResults[] = ['component' => 'Question Bank', 'status' => 'error', 'message' => 'No Question Bank draft found.'];
            }

            // ---- COMPONENT 2: MASTER BLUEPRINT ----
            $stmt = $pdo->prepare("
                SELECT * FROM qps_master_blueprints
                WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$user['staff_code'], $filterCourse, $filterSemester, $filterYear]);
            $mb = $stmt->fetch();

            if ($mb) {
                $matrix = json_decode($mb['matrix_config'] ?? '{}', true);
                $totalQuestions = 0;
                $totalMarks = 0;
                if (is_array($matrix)) {
                    foreach ($matrix as $unitData) {
                        foreach ($unitData as $subUnitData) {
                            if (is_array($subUnitData)) {
                                foreach ($subUnitData as $cell) {
                                    if (is_array($cell)) {
                                        $totalQuestions += (int)($cell['count'] ?? 0);
                                        $totalMarks += (int)($cell['count'] ?? 0) * (int)($cell['marks'] ?? 0);
                                    }
                                }
                            }
                        }
                    }
                }

                $masterBlueprint = [
                    'id' => (int)$mb['id'],
                    'status' => $mb['status'],
                    'version' => (int)$mb['version_no'],
                    'updated_at' => $mb['updated_at'],
                    'total_questions' => $totalQuestions,
                    'total_marks' => $totalMarks,
                    'regulation' => $mb['regulation'],
                ];

                // Validation for MB
                if ($totalQuestions === 0) {
                    $validationResults[] = ['component' => 'Master Blueprint', 'status' => 'error', 'message' => 'Blueprint matrix is empty.'];
                } elseif ($totalMarks < 75) {
                    $validationResults[] = ['component' => 'Master Blueprint', 'status' => 'warning', 'message' => "Blueprint totals $totalMarks marks (standard 75)."];
                } else {
                    $validationResults[] = ['component' => 'Master Blueprint', 'status' => 'success', 'message' => "$totalQuestions questions, $totalMarks marks."];
                }

                // Cross-check with QB
                if ($questionBank && $questionBank['question_count'] !== $totalQuestions) {
                    $validationResults[] = ['component' => 'Master Blueprint', 'status' => 'warning', 'message' => "Question count mismatch: QB has {$questionBank['question_count']}, Blueprint has $totalQuestions."];
                }
                if ($questionBank && $questionBank['total_marks'] !== $totalMarks) {
                    $validationResults[] = ['component' => 'Master Blueprint', 'status' => 'warning', 'message' => "Marks mismatch: QB has {$questionBank['total_marks']}M, Blueprint has ${totalMarks}M."];
                }
            } else {
                $validationResults[] = ['component' => 'Master Blueprint', 'status' => 'error', 'message' => 'No Master Blueprint created.'];
            }

            // ---- COMPONENT 3: FINAL BLUEPRINT ----
            $stmt = $pdo->prepare("
                SELECT * FROM qps_final_blueprints
                WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$user['staff_code'], $filterCourse, $filterSemester, $filterYear]);
            $fb = $stmt->fetch();

            if ($fb) {
                $matrix = json_decode($fb['matrix_config'] ?? '{}', true);
                $totalQuestions = 0;
                $totalMarks = 0;
                $setCount = 0;
                if (is_array($matrix)) {
                    foreach ($matrix as $unitData) {
                        foreach ($unitData as $subUnitData) {
                            if (is_array($subUnitData)) {
                                foreach ($subUnitData as $cell) {
                                    if (is_array($cell)) {
                                        $totalQuestions += (int)($cell['count'] ?? 0);
                                        $totalMarks += (int)($cell['count'] ?? 0) * (int)($cell['marks'] ?? 0);
                                    }
                                }
                            }
                        }
                    }
                }

                // Get set count from question_paper_sets
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM question_paper_sets WHERE final_blueprint_id = ?
                ");
                $stmt->execute([$fb['id']]);
                $setCount = (int)$stmt->fetchColumn();

                $finalBlueprint = [
                    'id' => (int)$fb['id'],
                    'status' => $fb['status'],
                    'version' => (int)$fb['version_no'],
                    'updated_at' => $fb['updated_at'],
                    'total_questions' => $totalQuestions,
                    'total_marks' => $totalMarks,
                    'set_count' => $setCount,
                    'regulation' => $fb['regulation'],
                ];

                // Validation for FB
                if ($setCount === 0) {
                    $validationResults[] = ['component' => 'Final Blueprint', 'status' => 'error', 'message' => 'No question paper sets generated (A/B/C).'];
                } elseif ($totalQuestions === 0) {
                    $validationResults[] = ['component' => 'Final Blueprint', 'status' => 'error', 'message' => 'Final blueprint matrix is empty.'];
                } else {
                    $validationResults[] = ['component' => 'Final Blueprint', 'status' => 'success', 'message' => "$totalQuestions questions, $totalMarks marks, $setCount sets."];
                }

                // Cross-check with Master Blueprint
                if ($masterBlueprint && $masterBlueprint['total_questions'] !== $totalQuestions) {
                    $validationResults[] = ['component' => 'Final Blueprint', 'status' => 'warning', 'message' => "Question count mismatch: MB has {$masterBlueprint['total_questions']}, FB has $totalQuestions."];
                }
                if ($masterBlueprint && $masterBlueprint['total_marks'] !== $totalMarks) {
                    $validationResults[] = ['component' => 'Final Blueprint', 'status' => 'warning', 'message' => "Marks mismatch: MB has {$masterBlueprint['total_marks']}M, FB has ${totalMarks}M."];
                }
            } else {
                $validationResults[] = ['component' => 'Final Blueprint', 'status' => 'error', 'message' => 'No Final Blueprint created.'];
            }

            // ---- SUBMISSION PACKAGE ----
            $stmt = $pdo->prepare("
                SELECT * FROM qps_submission_packages
                WHERE UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ? AND dept_code = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$filterCourse, $filterSemester, $filterYear, $deptCode]);
            $pkg = $stmt->fetch();

            if ($pkg) {
                $submissionPackage = [
                    'id' => (int)$pkg['id'],
                    'status' => $pkg['status'],
                    'question_bank_status' => $pkg['question_bank_status'],
                    'master_blueprint_status' => $pkg['master_blueprint_status'],
                    'final_blueprint_status' => $pkg['final_blueprint_status'],
                    'created_at' => $pkg['created_at'],
                    'submitted_at' => $pkg['submitted_at'],
                ];

                // Check if already submitted
                if (in_array($pkg['status'], ['SUBMITTED_TO_HOD', 'HOD_APPROVED', 'HOD_REJECTED', 'DEAN_APPROVED', 'COE_APPROVED'])) {
                    $overallStatus = 'submitted';
                }
            } else {
                $overallStatus = 'ready';
            }

            // Determine overall readiness
            $hasErrors = false;
            $hasWarnings = false;
            foreach ($validationResults as $vr) {
                if ($vr['status'] === 'error') $hasErrors = true;
                if ($vr['status'] === 'warning') $hasWarnings = true;
            }

            if ($overallStatus !== 'submitted') {
                if ($hasErrors) $overallStatus = 'incomplete';
                elseif ($hasWarnings) $overallStatus = 'ready_warnings';
                else $overallStatus = 'ready';
            }

        }
    } catch (Throwable $e) {
        error_log('[Submit Course] Load failed: ' . $e->getMessage());
        $validationResults[] = ['component' => 'System', 'status' => 'error', 'message' => 'Failed to load course data: ' . $e->getMessage()];
        $overallStatus = 'error';
    }
}

// Handle POST: Submit to HOD
$message = null;
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!isset($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
        $message = 'Invalid CSRF token';
        $messageType = 'error';
    } elseif ($action === 'submit_to_hod') {
        $courseCode = strtoupper(trim((string)$_POST['course_code'] ?? ''));
        $semester = trim((string)$_POST['semester'] ?? '');
        $academicYear = trim((string)$_POST['academic_year'] ?? $defaultAcademicYear);

        try {
            if (!$courseCode || !$semester) throw new RuntimeException('Missing course context.');

            // Verify allocation
            $check = $pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1');
            $check->execute([$user['staff_code'], $courseCode]);
            if (!$check->fetchColumn()) throw new RuntimeException('Not allocated to this course.');

            // Re-run validations to ensure readiness
            $errors = [];

            // Check QB
            $stmt = $pdo->prepare("
                SELECT qd.id, qd.max_marks
                FROM qps_question_bank_drafts qd
                WHERE qd.staff_code = ? AND UPPER(qd.paper_code) = UPPER(?) AND qd.semester = ? AND qd.academic_year = ?
                ORDER BY qd.id DESC LIMIT 1
            ");
            $stmt->execute([$user['staff_code'], $courseCode, $semester, $academicYear]);
            $draft = $stmt->fetch();
            if (!$draft) $errors[] = 'Question Bank draft not found.';

            $stmt = $pdo->prepare("
                SELECT COUNT(*) as cnt, SUM(marks) as marks_sum
                FROM qps_question_bank_draft_questions
                WHERE draft_id = ? AND staff_code = ?
            ");
            $stmt->execute([$draft['id'], $user['staff_code']]);
            $qbTotals = $stmt->fetch();
            if (($qbTotals['cnt'] ?? 0) === 0) $errors[] = 'Question Bank has no questions.';

            // Check MB
            $stmt = $pdo->prepare("
                SELECT id FROM qps_master_blueprints
                WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$user['staff_code'], $courseCode, $semester, $academicYear]);
            $mb = $stmt->fetch();
            if (!$mb) $errors[] = 'Master Blueprint not found.';

            // Check FB
            $stmt = $pdo->prepare("
                SELECT id FROM qps_final_blueprints
                WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$user['staff_code'], $courseCode, $semester, $academicYear]);
            $fb = $stmt->fetch();
            if (!$fb) $errors[] = 'Final Blueprint not found.';

            // Check sets
            if ($fb) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM question_paper_sets WHERE final_blueprint_id = ?");
                $stmt->execute([$fb['id']]);
                if ((int)$stmt->fetchColumn() === 0) $errors[] = 'No question paper sets generated for Final Blueprint.';
            }

            if (!empty($errors)) {
                throw new RuntimeException('Validation failed: ' . implode('; ', $errors));
            }

            // Get course info for package
            $examType = 'Odd Semester End Examination';
            $paperType = 'Theory';
            $deptCode = null;
            $stmt = $pdo->prepare("SELECT qpattern, type as course_type, dept_code FROM courses WHERE UPPER(coursecode)=UPPER(?)");
            $stmt->execute([$courseCode]);
            $course = $stmt->fetch();
            if ($course) {
                $paperType = hcc_paper_types()[$course['qpattern']] ?? $course['qpattern'];
                $examType = hcc_exam_types()[$course['course_type']] ?? 'Odd Semester End Examination';
                $deptCode = $course['dept_code'];
            }

            // Create/update submission package
            $packageId = qps_create_or_update_package($pdo, [
                'paper_code' => $courseCode,
                'semester' => $semester,
                'academic_year' => $academicYear,
                'exam_type' => $examType,
                'dept_code' => $deptCode,
                'bank_draft_id' => (int)$draft['id'],
                'bank_id' => null,
                'qbb_id' => (int)$mb['id'],
                'final_blueprint_id' => (int)$fb['id'],
            ]);

            // Update draft status
            $pdo->prepare("UPDATE qps_question_bank_drafts SET status = 'SUBMITTED_TO_HOD', submitted_at = NOW() WHERE id = ?")->execute([$draft['id']]);
            $pdo->prepare("UPDATE qps_master_blueprints SET status = 'SUBMITTED_TO_HOD' WHERE id = ?")->execute([$mb['id']]);
            $pdo->prepare("UPDATE qps_final_blueprints SET status = 'SUBMITTED_TO_HOD' WHERE id = ?")->execute([$fb['id']]);

            // Transition
            qps_transition($pdo, $packageId, 'STAFF_SUBMIT', 'All components submitted for HOD review');

            // Redirect to prevent resubmission
            header("Location: " . getBaseUrl() . "/modules/teaching/submit_course.php?course_code=" . urlencode($courseCode) . "&semester=" . urlencode($semester) . "&academic_year=" . urlencode($academicYear) . "&submitted=1");
            exit;

        } catch (Throwable $e) {
            $message = 'Submission failed: ' . $e->getMessage();
            $messageType = 'error';
            error_log('[Submit Course] Submit error: ' . $e->getMessage());
        }
    }
}

// Success redirect
if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
    $message = 'All components submitted to HOD for review successfully!';
    $messageType = 'success';
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
      <nav class="flex items-center gap-2 text-xs text-slate-500 mb-2">
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/dashboard.php" class="hover:text-slate-700">Dashboard</a>
        <i data-lucide="chevron-right" class="w-3 h-3"></i>
        <span class="text-slate-900 font-bold">Submit Course</span>
      </nav>
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900">Submit Course to HOD</h1>
      <p class="text-sm text-slate-500 mt-1">Consolidated review of Question Bank, Master Blueprint & Final Blueprint</p>
    </div>
    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/dashboard.php" class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-bold transition shrink-0">
      <i data-lucide="arrow-left" class="w-4 h-4 inline mr-1"></i>
      Back to Courses
    </a>
  </div>

  <!-- Messages -->
  <?php if (!empty($message)): ?>
    <div class="rounded-lg px-4 py-3 text-sm font-medium
      <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' :
             ($messageType === 'error' ? 'bg-rose-50 text-rose-800 border border-rose-200' : 'bg-blue-50 text-blue-800 border border-blue-200'); ?>">
      <i data-lucide="<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info'); ?>" class="w-4 h-4 inline mr-1"></i>
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <!-- ========================================================================= -->
  <!-- CONTEXT SELECTOR (Top)                                                    -->
  <!-- ========================================================================= -->
  <div class="card-modern p-5 space-y-4">
    <h3 class="font-black text-slate-900 flex items-center gap-2">
      <i data-lucide="folder-search" class="w-5 h-5 text-indigo-600"></i>
      Select Course Context
    </h3>
    <form method="GET" id="contextForm" class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Academic Year *</label>
        <input type="text" name="academic_year" value="<?php echo htmlspecialchars($filterYear); ?>"
               placeholder="YYYY-YYYY" maxlength="9" pattern="(19|20)\d{2}-(19|20)\d{2}"
               class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
        <p class="text-[10px] text-slate-500 mt-1">Format: 2026-2027</p>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Semester *</label>
        <select name="semester" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
          <option value="">Select Semester</option>
          <?php for ($i = 1; $i <= 8; $i++): ?>
            <option value="Semester <?php echo $i; ?>" <?php echo $filterSemester === "Semester $i" ? 'selected' : ''; ?>>
              Semester <?php echo $i; ?>
            </option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Course *</label>
        <select name="course_code" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
          <option value="">Select Course</option>
          <?php foreach ($allocatedCourses as $c): ?>
            <option value="<?php echo htmlspecialchars($c['course_code']); ?>" <?php echo $filterCourse === $c['course_code'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['course_code'] . ' — ' . $c['course_title']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end">
        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg px-5 py-2.5 font-bold text-sm transition flex items-center justify-center gap-2">
          <i data-lucide="search" class="w-4 h-4"></i>
          <span>Load Review</span>
        </button>
      </div>
    </form>
  </div>

  <?php if ($filterCourse && $filterSemester && $filterYear): ?>
    <!-- Course Header -->
    <div class="card-modern p-4">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <span class="bg-slate-100 text-slate-800 text-[11px] font-bold px-2.5 py-0.5 rounded-full font-mono">
            <?php echo htmlspecialchars($filterCourse); ?>
          </span>
          <?php
            $courseTitle = '';
            foreach ($allocatedCourses as $ac) { if ($ac['course_code'] === $filterCourse) { $courseTitle = $ac['course_title']; break; } }
          ?>
          <h2 class="font-bold text-slate-900 text-lg mt-1"><?php echo htmlspecialchars($courseTitle ?: $filterCourse); ?></h2>
          <div class="flex flex-wrap items-center gap-2 mt-2 text-sm text-slate-500">
            <span class="bg-slate-100 px-2 py-0.5 rounded font-bold"><?php echo htmlspecialchars($filterSemester); ?></span>
            <span class="bg-slate-100 px-2 py-0.5 rounded font-bold"><?php echo htmlspecialchars($filterYear); ?></span>
          </div>
        </div>
        <div class="text-right">
          <?php
            $statusColors = [
                'incomplete' => 'bg-rose-50 text-rose-700 border border-rose-200',
                'ready_warnings' => 'bg-amber-50 text-amber-700 border border-amber-200',
                'ready' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                'submitted' => 'bg-blue-50 text-blue-700 border border-blue-200',
                'error' => 'bg-rose-50 text-rose-700 border border-rose-200',
            ];
            $statusLabels = [
                'incomplete' => 'INCOMPLETE',
                'ready_warnings' => 'READY (with warnings)',
                'ready' => 'READY TO SUBMIT',
                'submitted' => 'SUBMITTED TO HOD',
                'error' => 'ERROR',
            ];
            $statusClass = $statusColors[$overallStatus] ?? $statusColors['incomplete'];
          ?>
          <span class="px-4 py-2 rounded-lg font-bold text-sm border <?php echo $statusClass; ?>">
            <i data-lucide="<?php echo $overallStatus === 'submitted' ? 'check-circle' : ($overallStatus === 'ready' ? 'shield-check' : ($overallStatus === 'ready_warnings' ? 'alert-triangle' : 'x-circle')); ?>" class="w-4 h-4 inline mr-1"></i>
            <?php echo $statusLabels[$overallStatus] ?? 'UNKNOWN'; ?>
          </span>
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- VALIDATION SUMMARY                                                         -->
    <!-- ========================================================================= -->
    <?php if (!empty($validationResults)): ?>
      <div class="card-modern p-4">
        <h3 class="font-black text-slate-900 mb-3 flex items-center gap-2">
          <i data-lucide="clipboard-check" class="w-5 h-5 text-indigo-600"></i>
          Validation Summary
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <?php
            $componentGroups = [];
            foreach ($validationResults as $vr) {
                $componentGroups[$vr['component']][] = $vr;
            }
            foreach ($componentGroups as $component => $results):
                $hasError = false; $hasWarning = false; $hasSuccess = false;
                foreach ($results as $r) {
                    if ($r['status'] === 'error') $hasError = true;
                    elseif ($r['status'] === 'warning') $hasWarning = true;
                    elseif ($r['status'] === 'success') $hasSuccess = true;
                }
                $cardClass = $hasError ? 'border-l-4 border-l-rose-500 bg-rose-50' : ($hasWarning ? 'border-l-4 border-l-amber-500 bg-amber-50' : 'border-l-4 border-l-emerald-500 bg-emerald-50');
          ?>
            <div class="p-4 rounded-lg border <?php echo $cardClass; ?>">
              <div class="font-bold text-slate-900 text-sm mb-2"><?php echo htmlspecialchars($component); ?></div>
              <div class="space-y-1.5 text-sm">
                <?php foreach ($results as $r): ?>
                  <div class="flex items-start gap-2">
                    <i data-lucide="<?php echo $r['status'] === 'success' ? 'check-circle' : ($r['status'] === 'warning' ? 'alert-triangle' : 'x-circle'); ?>"
                       class="w-4 h-4 mt-0.5 flex-shrink-0 <?php echo $r['status'] === 'success' ? 'text-emerald-500' : ($r['status'] === 'warning' ? 'text-amber-500' : 'text-rose-500'); ?>"></i>
                    <span class="text-slate-700"><?php echo htmlspecialchars($r['message']); ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- THREE COMPONENT CARDS                                                      -->
    <!-- ========================================================================= -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

      <!-- COMPONENT 1: QUESTION BANK -->
      <div class="card-modern p-5 space-y-4 <?php echo $questionBank ? '' : 'border-l-4 border-l-rose-500 bg-rose-50'; ?>">
        <div class="flex items-center justify-between">
          <h3 class="font-black text-slate-900 flex items-center gap-2">
            <i data-lucide="database" class="w-5 h-5 text-indigo-600"></i>
            Question Bank
          </h3>
          <?php if ($questionBank): ?>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold font-mono
              <?php echo $questionBank['status'] === 'DRAFT' ? 'bg-amber-50 text-amber-700 border border-amber-200' :
                     ($questionBank['status'] === 'SUBMITTED_TO_HOD' ? 'bg-blue-50 text-blue-700 border border-blue-200' :
                     'bg-emerald-50 text-emerald-700 border border-emerald-200'); ?>">
              <?php echo htmlspecialchars($questionBank['status']); ?>
            </span>
          <?php else: ?>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold font-mono bg-rose-50 text-rose-700 border border-rose-200">MISSING</span>
          <?php endif; ?>
        </div>

        <?php if ($questionBank): ?>
          <div class="grid grid-cols-3 gap-3 text-center">
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-slate-900"><?php echo $questionBank['question_count']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Questions</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-emerald-600"><?php echo $questionBank['total_marks']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Total Marks</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-purple-600">v<?php echo $questionBank['version']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Version</div>
            </div>
          </div>

          <div class="space-y-2 text-sm text-slate-600 border-t border-slate-100 pt-3">
            <div class="flex justify-between"><span>Max Marks</span><span class="font-bold"><?php echo $questionBank['max_marks']; ?></span></div>
            <div class="flex justify-between"><span>Regulation</span><span class="font-bold"><?php echo htmlspecialchars($questionBank['regulation']); ?></span></div>
            <div class="flex justify-between"><span>Last Updated</span><span class="font-bold"><?php echo $questionBank['updated_at'] ? date('d M Y, H:i', strtotime($questionBank['updated_at'])) : '—'; ?></span></div>
          </div>

          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($filterCourse); ?>&semester=<?php echo urlencode($filterSemester); ?>&academic_year=<?php echo urlencode($filterYear); ?>"
             class="btn-orange-pill text-sm w-full text-center">
            <i data-lucide="edit-2" class="w-4 h-4 inline mr-1"></i>
            Manage Questions
          </a>
        <?php else: ?>
          <div class="p-4 text-center text-rose-600 bg-rose-50 rounded-lg">
            <i data-lucide="alert-triangle" class="w-8 h-8 mx-auto mb-2"></i>
            <p class="font-medium">No Question Bank draft found</p>
            <p class="text-sm mt-1">Create a draft in Question Bank Entry first</p>
            <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($filterCourse); ?>&semester=<?php echo urlencode($filterSemester); ?>&academic_year=<?php echo urlencode($filterYear); ?>"
               class="btn-orange-pill text-sm mt-3 inline-block">Create Question Bank</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- COMPONENT 2: MASTER BLUEPRINT -->
      <div class="card-modern p-5 space-y-4 <?php echo $masterBlueprint ? '' : 'border-l-4 border-l-rose-500 bg-rose-50'; ?>">
        <div class="flex items-center justify-between">
          <h3 class="font-black text-slate-900 flex items-center gap-2">
            <i data-lucide="layout-template" class="w-5 h-5 text-purple-600"></i>
            Master Blueprint
          </h3>
          <?php if ($masterBlueprint): ?>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold font-mono
              <?php echo $masterBlueprint['status'] === 'DRAFT' ? 'bg-amber-50 text-amber-700 border border-amber-200' :
                     ($masterBlueprint['status'] === 'SUBMITTED_TO_HOD' ? 'bg-blue-50 text-blue-700 border border-blue-200' :
                     'bg-emerald-50 text-emerald-700 border border-emerald-200'); ?>">
              <?php echo htmlspecialchars($masterBlueprint['status']); ?>
            </span>
          <?php else: ?>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold font-mono bg-rose-50 text-rose-700 border border-rose-200">MISSING</span>
          <?php endif; ?>
        </div>

        <?php if ($masterBlueprint): ?>
          <div class="grid grid-cols-3 gap-3 text-center">
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-slate-900"><?php echo $masterBlueprint['total_questions']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Questions</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-emerald-600"><?php echo $masterBlueprint['total_marks']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Total Marks</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-purple-600">v<?php echo $masterBlueprint['version']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Version</div>
            </div>
          </div>

          <div class="space-y-2 text-sm text-slate-600 border-t border-slate-100 pt-3">
            <div class="flex justify-between"><span>Regulation</span><span class="font-bold"><?php echo htmlspecialchars($masterBlueprint['regulation']); ?></span></div>
            <div class="flex justify-between"><span>Last Updated</span><span class="font-bold"><?php echo $masterBlueprint['updated_at'] ? date('d M Y, H:i', strtotime($masterBlueprint['updated_at'])) : '—'; ?></span></div>
          </div>

          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo urlencode($filterCourse); ?>&semester=<?php echo urlencode($filterSemester); ?>&academic_year=<?php echo urlencode($filterYear); ?>"
             class="btn-orange-pill text-sm w-full text-center">
            <i data-lucide="edit-2" class="w-4 h-4 inline mr-1"></i>
            Configure Blueprint
          </a>
        <?php else: ?>
          <div class="p-4 text-center text-rose-600 bg-rose-50 rounded-lg">
            <i data-lucide="alert-triangle" class="w-8 h-8 mx-auto mb-2"></i>
            <p class="font-medium">No Master Blueprint found</p>
            <p class="text-sm mt-1">Create blueprint in Course Blueprint first</p>
            <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo urlencode($filterCourse); ?>&semester=<?php echo urlencode($filterSemester); ?>&academic_year=<?php echo urlencode($filterYear); ?>"
               class="btn-orange-pill text-sm mt-3 inline-block">Create Blueprint</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- COMPONENT 3: FINAL BLUEPRINT -->
      <div class="card-modern p-5 space-y-4 <?php echo $finalBlueprint ? '' : 'border-l-4 border-l-rose-500 bg-rose-50'; ?>">
        <div class="flex items-center justify-between">
          <h3 class="font-black text-slate-900 flex items-center gap-2">
            <i data-lucide="list-checks" class="w-5 h-5 text-emerald-600"></i>
            Final Blueprint
          </h3>
          <?php if ($finalBlueprint): ?>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold font-mono
              <?php echo $finalBlueprint['status'] === 'DRAFT' ? 'bg-amber-50 text-amber-700 border border-amber-200' :
                     ($finalBlueprint['status'] === 'SUBMITTED_TO_HOD' ? 'bg-blue-50 text-blue-700 border border-blue-200' :
                     'bg-emerald-50 text-emerald-700 border border-emerald-200'); ?>">
              <?php echo htmlspecialchars($finalBlueprint['status']); ?>
            </span>
          <?php else: ?>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold font-mono bg-rose-50 text-rose-700 border border-rose-200">MISSING</span>
          <?php endif; ?>
        </div>

        <?php if ($finalBlueprint): ?>
          <div class="grid grid-cols-3 gap-3 text-center">
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-slate-900"><?php echo $finalBlueprint['total_questions']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Questions</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-emerald-600"><?php echo $finalBlueprint['total_marks']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Total Marks</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-lg">
              <div class="text-2xl font-black text-blue-600"><?php echo $finalBlueprint['set_count']; ?></div>
              <div class="text-[10px] text-slate-500 uppercase">Sets (A/B/C)</div>
            </div>
          </div>

          <div class="space-y-2 text-sm text-slate-600 border-t border-slate-100 pt-3">
            <div class="flex justify-between"><span>Regulation</span><span class="font-bold"><?php echo htmlspecialchars($finalBlueprint['regulation']); ?></span></div>
            <div class="flex justify-between"><span>Last Updated</span><span class="font-bold"><?php echo $finalBlueprint['updated_at'] ? date('d M Y, H:i', strtotime($finalBlueprint['updated_at'])) : '—'; ?></span></div>
            <div class="flex justify-between"><span>Version</span><span class="font-bold">v<?php echo $finalBlueprint['version']; ?></span></div>
          </div>

          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/final_slot_allocation.php?course_code=<?php echo urlencode($filterCourse); ?>&semester=<?php echo urlencode($filterSemester); ?>&academic_year=<?php echo urlencode($filterYear); ?>"
             class="btn-orange-pill text-sm w-full text-center">
            <i data-lucide="edit-2" class="w-4 h-4 inline mr-1"></i>
            Configure Final Slots
          </a>
        <?php else: ?>
          <div class="p-4 text-center text-rose-600 bg-rose-50 rounded-lg">
            <i data-lucide="alert-triangle" class="w-8 h-8 mx-auto mb-2"></i>
            <p class="font-medium">No Final Blueprint found</p>
            <p class="text-sm mt-1">Create final slots in Final Slot Allocation first</p>
            <a href="<?php echo getBaseUrl(); ?>/modules/teaching/final_slot_allocation.php?course_code=<?php echo urlencode($filterCourse); ?>&semester=<?php echo urlencode($filterSemester); ?>&academic_year=<?php echo urlencode($filterYear); ?>"
               class="btn-orange-pill text-sm mt-3 inline-block">Create Final Blueprint</a>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- ========================================================================= -->
    <!-- SUBMISSION PACKAGE STATUS                                                  -->
    <!-- ========================================================================= -->
    <?php if ($submissionPackage): ?>
      <div class="card-modern p-5 space-y-4 bg-blue-50 border-blue-200">
        <h3 class="font-black text-blue-900 flex items-center gap-2">
          <i data-lucide="package" class="w-5 h-5"></i>
          Submission Package Status
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="p-3 bg-white rounded-lg border border-blue-100">
            <div class="text-[10px] text-blue-500 font-bold uppercase">Overall Status</div>
            <div class="font-black text-blue-800 mt-1"><?php echo htmlspecialchars($submissionPackage['status']); ?></div>
          </div>
          <div class="p-3 bg-white rounded-lg border border-blue-100">
            <div class="text-[10px] text-blue-500 font-bold uppercase">Question Bank</div>
            <div class="font-bold text-blue-800 mt-1 capitalize"><?php echo strtolower(str_replace('_', ' ', $submissionPackage['question_bank_status'])); ?></div>
          </div>
          <div class="p-3 bg-white rounded-lg border border-blue-100">
            <div class="text-[10px] text-blue-500 font-bold uppercase">Master Blueprint</div>
            <div class="font-bold text-blue-800 mt-1 capitalize"><?php echo strtolower(str_replace('_', ' ', $submissionPackage['master_blueprint_status'])); ?></div>
          </div>
          <div class="p-3 bg-white rounded-lg border border-blue-100">
            <div class="text-[10px] text-blue-500 font-bold uppercase">Final Blueprint</div>
            <div class="font-bold text-blue-800 mt-1 capitalize"><?php echo strtolower(str_replace('_', ' ', $submissionPackage['final_blueprint_status'])); ?></div>
          </div>
        </div>
        <div class="text-sm text-blue-700 border-t border-blue-200 pt-3">
          <div class="flex justify-between">
            <span>Package ID</span>
            <span class="font-bold font-mono">#<?php echo $submissionPackage['id']; ?></span>
          </div>
          <div class="flex justify-between mt-1">
            <span>Created</span>
            <span class="font-bold"><?php echo $submissionPackage['created_at'] ? date('d M Y, H:i', strtotime($submissionPackage['created_at'])) : '—'; ?></span>
          </div>
          <?php if ($submissionPackage['submitted_at']): ?>
            <div class="flex justify-between mt-1">
              <span>Submitted to HOD</span>
              <span class="font-bold"><?php echo date('d M Y, H:i', strtotime($submissionPackage['submitted_at'])); ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- SUBMIT BUTTON                                                              -->
    <!-- ========================================================================= -->
    <div class="card-modern p-5 space-y-4">
      <h3 class="font-black text-slate-900 flex items-center gap-2">
        <i data-lucide="send" class="w-5 h-5 text-blue-600"></i>
        Submit to HOD for Review
      </h3>

      <?php if ($overallStatus === 'submitted'): ?>
        <div class="p-5 text-center bg-emerald-50 border border-emerald-200 rounded-xl">
          <i data-lucide="check-circle-2" class="w-12 h-12 mx-auto text-emerald-500 mb-3"></i>
          <h4 class="font-black text-emerald-800 text-lg">Already Submitted to HOD</h4>
          <p class="text-emerald-700 mt-2">This course has been submitted and is under HOD review.</p>
          <p class="text-sm text-emerald-600 mt-1">Check <a href="<?php echo getBaseUrl(); ?>/modules/teaching/corrections.php" class="underline hover:text-emerald-800">Corrections</a> for status updates.</p>
        </div>

      <?php elseif ($overallStatus === 'ready'): ?>
        <form method="POST" onsubmit="return confirm('Submit ALL components (Question Bank, Master Blueprint, Final Blueprint) to HOD for review? This action cannot be undone.')">
          <input type="hidden" name="action" value="submit_to_hod">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($filterCourse); ?>">
          <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filterSemester); ?>">
          <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filterYear); ?>">

          <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
            <p class="font-medium text-emerald-800">All validations passed. Ready to submit.</p>
            <p class="text-sm text-emerald-700 mt-1">Clicking submit will package all three components and send them to HOD for review.</p>
          </div>

          <div class="flex justify-end">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg text-base font-bold shadow transition flex items-center gap-2">
              <i data-lucide="send" class="w-5 h-5"></i>
              <span>Submit to HOD</span>
            </button>
          </div>
        </form>

      <?php elseif ($overallStatus === 'ready_warnings'): ?>
        <form method="POST" onsubmit="return confirm('Submit with warnings? Some components have mismatches or issues. Review the validation summary above.')">
          <input type="hidden" name="action" value="submit_to_hod">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($filterCourse); ?>">
          <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filterSemester); ?>">
          <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filterYear); ?>">

          <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl">
            <p class="font-medium text-amber-800 flex items-center gap-2">
              <i data-lucide="alert-triangle" class="w-4 h-4"></i>
              Ready to submit with warnings
            </p>
            <p class="text-sm text-amber-700 mt-1">Some components have warnings (e.g., mark mismatches). You can still submit, but HOD may return for corrections.</p>
          </div>

          <div class="flex justify-end">
            <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white px-8 py-3 rounded-lg text-base font-bold shadow transition flex items-center gap-2">
              <i data-lucide="alert-triangle" class="w-5 h-5"></i>
              <span>Submit with Warnings</span>
            </button>
          </div>
        </form>

      <?php else: ?>
        <div class="p-5 text-center bg-rose-50 border border-rose-200 rounded-xl">
          <i data-lucide="x-circle" class="w-12 h-12 mx-auto text-rose-500 mb-3"></i>
          <h4 class="font-black text-rose-800 text-lg">Cannot Submit</h4>
          <p class="text-rose-700 mt-2">Please resolve the validation errors above before submitting.</p>
          <p class="text-sm text-rose-600 mt-1">All three components must be complete with no errors.</p>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- No context selected -->
    <div class="card-modern p-10 text-center">
      <i data-lucide="folder-search" class="w-16 h-16 mx-auto text-slate-300 mb-4"></i>
      <h3 class="font-bold text-slate-700 text-lg">Select a Course to Review</h3>
      <p class="text-slate-500 mt-2">Use the form above to select Academic Year, Semester, and Course to load the consolidated review.</p>
    </div>
  <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>