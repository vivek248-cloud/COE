<?php
/**
 * Page 2: Question Bank Draft
 * Holy Cross College (Autonomous) - COE Question Paper System
 * Faculty creates/edits question bank draft for a course
 * Supports: new draft, continue existing, resolve corrections
 */
declare(strict_types=1);
define('PAGE_TITLE', 'Question Bank Draft');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/workflow.php';

requireTeachingStaff();
$user = getCurrentUser();
$pdo = getDBConnection();

// Ensure schema
qps_ensure_aux_schema($pdo);

// CSRF
if (empty($_SESSION['qps_draft_csrf'])) {
    $_SESSION['qps_draft_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['qps_draft_csrf'];

// Get parameters (from GET, with POST fallback for form submissions)
$courseCode = strtoupper(trim((string)($_GET['course_code'] ?? $_POST['course_code'] ?? '')));
$submissionId = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : (isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : null);
$correctMode = (isset($_GET['correct']) && $_GET['correct'] === '1') || (isset($_POST['correct']) && $_POST['correct'] === '1');

// Validate course_code is provided
if (!$courseCode) {
    $message = 'Error: Course code is required. Please access this page from the Dashboard with a valid course.';
    $messageType = 'error';
    $canEdit = false;
}

// Get academic context
$academicYear = qps_setting($pdo, 'default_academic_year', DEFAULT_ACADEMIC_YEAR);
$regulation = qps_setting($pdo, 'default_regulation', DEFAULT_REGULATION);

// Fetch exam type and paper type for this course
$examType = 'Odd Semester End Examination';
$paperType = 'Theory';
try {
    $stmt = $pdo->prepare("
        SELECT qpattern, type as course_type
        FROM courses
        WHERE UPPER(coursecode) = UPPER(?)
    ");
    $stmt->execute([$courseCode]);
    $course = $stmt->fetch();
    if ($course) {
        $paperType = hcc_paper_types()[$course['qpattern']] ?? $course['qpattern'];
        $examType = hcc_exam_types()[$course['course_type']] ?? 'Odd Semester End Examination';
    }
} catch (Throwable $e) {
    error_log('[Question Bank Draft] Error fetching course info: ' . $e->getMessage());
}

if ($courseCode) {
    $courseAccess = $pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid) = UPPER(?) AND UPPER(papercode) = UPPER(?) LIMIT 1');
    $courseAccess->execute([$user['staff_code'], $courseCode]);
    if (!$courseAccess->fetchColumn()) {
        http_response_code(403);
        exit('You are not allocated to this course.');
    }
}

// Determine submission context
$submission = null;
$questions = [];
$canEdit = true;
$status = 'draft';

if ($submissionId) {
    try {
        $stmt = $pdo->prepare("
            SELECT cs.*, cs.question_bank_draft_id as draft_id
            FROM course_submissions cs
            WHERE cs.id = ? AND cs.staff_code = ?
        ");
        $stmt->execute([$submissionId, $user['staff_code']]);
        $submission = $stmt->fetch();

        if ($submission) {
            $status = $submission['question_bank_status'] ?? 'draft';
            $courseCode = $submission['course_code'];
            $canEdit = in_array($status, ['draft', 'hod_corrections', 'dean_corrections'], true);
        } else {
            $canEdit = false;
            $message = 'Submission not found or you do not have access to it.';
            $messageType = 'error';
        }
    } catch (Throwable $e) {
        error_log('[Question Bank Draft] Error fetching submission: ' . $e->getMessage());
    }
} elseif ($courseCode) {
    try {
        $stmt = $pdo->prepare("
            SELECT cs.*, cs.question_bank_draft_id as draft_id
            FROM course_submissions cs
            WHERE UPPER(cs.course_code) = UPPER(?) AND UPPER(cs.staff_code) = UPPER(?)
              AND cs.academic_year = ?
            ORDER BY cs.updated_at DESC, cs.id DESC LIMIT 1
        ");
        $stmt->execute([$courseCode, $user['staff_code'], $academicYear]);
        $submission = $stmt->fetch();

        if ($submission) {
            $status = $submission['question_bank_status'] ?? 'draft';
            $submissionId = $submission['id'];
            $canEdit = in_array($status, ['draft', 'hod_corrections', 'dean_corrections'], true);
        }
    } catch (Throwable $e) {
        error_log('[Question Bank Draft] Error checking existing submission: ' . $e->getMessage());
    }
}

// Page 5 filters and pagination.
$filterUnit = trim((string)($_GET['unit'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterCorrections = trim((string)($_GET['has_corrections'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 20;
$questionsTotal = 0;
$totalPages = 1;

// Load the latest OPEN correction per question without multiplying question rows.
$loadDraftQuestions = function () use (
    $pdo, $user, $courseCode, $regulation, $academicYear, $examType, $paperType,
    $filterUnit, $filterStatus, $filterCorrections, $searchTerm, &$page, $pageSize,
    &$questionsTotal, &$totalPages
): array {
    if (!$courseCode) return [];

    $where = [
        'qd.staff_id = ?',
        'UPPER(qd.course_code) = UPPER(?)',
        'qd.regulation = ?',
        'qd.academic_year = ?',
        'qd.exam_type = ?',
        'qd.paper_type = ?',
        'qd.is_active = 1'
    ];
    $params = [
        $user['staff_code'], $courseCode, $regulation, $academicYear, $examType, $paperType
    ];

    if ($filterUnit !== '' && ctype_digit($filterUnit)) {
        $where[] = 'qd.unit_number = ?';
        $params[] = (int)$filterUnit;
    }
    if ($filterStatus === 'verified') {
        $where[] = "(qd.verified_at IS NOT NULL OR LOWER(COALESCE(qd.status,'')) IN ('verified','approved','coe_approved'))";
    } elseif ($filterStatus === 'verification_pending') {
        $where[] = "(qd.verified_at IS NULL AND LOWER(COALESCE(qd.status,'')) NOT IN ('verified','approved','coe_approved'))";
    } elseif ($filterStatus !== '') {
        $where[] = 'qd.status = ?';
        $params[] = $filterStatus;
    }
    if ($filterCorrections === '1') {
        $where[] = "EXISTS (SELECT 1 FROM corrections cx WHERE cx.target_type='question' AND cx.target_id=qd.id AND cx.status='open')";
    } elseif ($filterCorrections === '0') {
        $where[] = "NOT EXISTS (SELECT 1 FROM corrections cx WHERE cx.target_type='question' AND cx.target_id=qd.id AND cx.status='open')";
    }
    if ($searchTerm !== '') {
        $where[] = '(qd.question_text LIKE ? OR CAST(qd.question_number AS CHAR) LIKE ? OR qd.sub_unit_number LIKE ?)';
        $like = '%' . $searchTerm . '%';
        array_push($params, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM questions_draft qd WHERE {$whereSql}");
    $count->execute($params);
    $questionsTotal = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($questionsTotal / $pageSize));
    $safePage = min($page, $totalPages);
    $page = $safePage;
    $offset = ($safePage - 1) * $pageSize;

    $sql = "
        SELECT qd.*,
               corr.id AS correction_id,
               corr.text AS correction_remark,
               corr.raised_by_role AS correction_role,
               corr.created_at AS correction_created_at,
               corr.status AS correction_status
        FROM questions_draft qd
        LEFT JOIN corrections corr
          ON corr.id = (
              SELECT c2.id
              FROM corrections c2
              WHERE c2.target_type = 'question'
                AND c2.target_id = qd.id
                AND c2.status = 'open'
              ORDER BY c2.created_at DESC, c2.id DESC
              LIMIT 1
          )
        WHERE {$whereSql}
        ORDER BY qd.unit_number, qd.sub_unit_number, qd.question_number, qd.id
        LIMIT {$pageSize} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $questions = $loadDraftQuestions();
} catch (Throwable $e) {
    error_log('[Question Bank Draft] Error fetching questions: ' . $e->getMessage());
    $questions = [];
}

// Handle POST actions
$message = null;
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Verify CSRF
    if (!isset($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
        $message = 'Invalid CSRF token';
        $messageType = 'error';
    } else {
        try {
            if ($action === 'save_question') {
                if (!$canEdit) {
                    throw new RuntimeException('This Question Bank is locked and cannot be edited.');
                }
                $questionId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;

                // Handle options based on question type
                $questionType = trim((string)$_POST['question_type']);
                $optionsJson = null;
                $matchColumnA = null;
                $matchColumnB = null;
                $matchAnswerKey = null;
                $assertionText = null;
                $reasonText = null;
                $hasFormula = 0;
                $formulaLatex = null;
                $imageUrl = null;

                if ($questionType === 'MCQ') {
                    $options = [];
                    for ($i = 0; $i < 4; $i++) {
                        $optKey = "option_$i";
                        $optVal = trim((string)($_POST[$optKey] ?? ''));
                        if ($optVal !== '') $options[] = ['text' => $optVal];
                    }
                    $correctOption = isset($_POST['correct_option']) ? (int)$_POST['correct_option'] : 0;
                    $optionsJson = json_encode(['options' => $options, 'correct' => $correctOption]);
                } elseif ($questionType === 'Match') {
                    $colA = [];
                    $colB = [];
                    $answerKey = [];
                    $matchCount = (int)($_POST['match_count'] ?? 4);
                    for ($i = 0; $i < $matchCount; $i++) {
                        $a = trim((string)($_POST["match_a_$i"] ?? ''));
                        $b = trim((string)($_POST["match_b_$i"] ?? ''));
                        $ans = trim((string)($_POST["match_ans_$i"] ?? ''));
                        if ($a !== '') $colA[] = $a;
                        if ($b !== '') $colB[] = $b;
                        if ($ans !== '') $answerKey[] = $ans;
                    }
                    $matchColumnA = json_encode($colA);
                    $matchColumnB = json_encode($colB);
                    $matchAnswerKey = json_encode($answerKey);
                } elseif ($questionType === 'Assertion and Reasoning') {
                    $assertionText = trim((string)($_POST['assertion_text'] ?? ''));
                    $reasonText = trim((string)($_POST['reason_text'] ?? ''));
                    $correctOption = isset($_POST['ar_correct']) ? (int)$_POST['ar_correct'] : 0;
                    // AR options: A=Both true & R explains A, B=Both true but R doesn't explain A, C=A true R false, D=A false R true
                    $optionsJson = json_encode(['correct' => $correctOption]);
                } elseif (in_array($questionType, ['VAS', 'Paragraph', 'Essay'], true)) {
                    // No options needed, answer key is the model answer
                }

                // Handle image/formula upload
                if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = UPLOAD_DIR . '/questions/' . $courseCode . '/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
                    $ext = pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION);
                    $fileName = 'q_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest = $uploadDir . $fileName;
                    if (move_uploaded_file($_FILES['question_image']['tmp_name'], $dest)) {
                        $imageUrl = 'assets/uploads/questions/' . $courseCode . '/' . $fileName;
                    }
                }

                if (!empty($_POST['formula_latex'])) {
                    $hasFormula = 1;
                    $formulaLatex = trim((string)$_POST['formula_latex']);
                }

                $data = [
                    'staff_id' => $user['staff_code'],
                    'course_code' => $courseCode,
                    'regulation' => $regulation,
                    'academic_year' => $academicYear,
                    'exam_type' => $examType,
                    'paper_type' => $paperType,
                    'unit_number' => (int)$_POST['unit_number'],
                    'sub_unit_number' => trim((string)$_POST['sub_unit_number']),
                    'question_text' => trim((string)$_POST['question_text']),
                    'marks' => (int)$_POST['marks'],
                    'blooms_level' => trim((string)$_POST['blooms_level']),
                    'co_code' => trim((string)$_POST['co_code']),
                    'question_type' => $questionType,
                    'options_json' => $optionsJson,
                    'match_column_a' => $matchColumnA,
                    'match_column_b' => $matchColumnB,
                    'match_answer_key' => $matchAnswerKey,
                    'assertion_text' => $assertionText,
                    'reason_text' => $reasonText,
                    'answer_key' => trim((string)($_POST['answer_key'] ?? '')),
                    'image_url' => $imageUrl,
                    'has_formula' => $hasFormula,
                    'formula_latex' => $formulaLatex,
                    'language' => 'EN',
                    'is_active' => 1,
                ];

                if ($questionId > 0) {
                    $owned = $pdo->prepare("SELECT id FROM questions_draft WHERE id = ? AND staff_id = ? AND UPPER(course_code) = UPPER(?) AND is_active = 1 LIMIT 1");
                    $owned->execute([$questionId, $user['staff_code'], $courseCode]);
                    if (!$owned->fetchColumn()) {
                        throw new RuntimeException('Question not found or you do not have permission to edit it.');
                    }
                    $placeholders = [];
                    $params = [];
                    foreach ($data as $k => $v) {
                        $placeholders[] = "$k = ?";
                        $params[] = $v;
                    }
                    $params[] = $questionId;
                    $params[] = $user['staff_code'];

                    $stmt = $pdo->prepare("
                        UPDATE questions_draft SET " . implode(', ', $placeholders) . "
                        WHERE id = ? AND staff_id = ?
                    ");
                    $stmt->execute($params);
                    $message = 'Question updated successfully';
                } else {
                    // Insert new - auto-assign question_number
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(MAX(question_number), 0) + 1 as next_num
                        FROM questions_draft
                        WHERE staff_id = ? AND course_code = ? AND regulation = ?
                          AND academic_year = ? AND exam_type = ? AND paper_type = ?
                          AND unit_number = ?
                    ");
                    $stmt->execute([$user['staff_code'], $courseCode, $regulation, $academicYear, $examType, $paperType, $data['unit_number']]);
                    $nextNum = (int)$stmt->fetchColumn();
                    $data['question_number'] = $nextNum;

                    $stmt = $pdo->prepare("
                        INSERT INTO questions_draft (" . implode(', ', array_keys($data)) . ")
                        VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ")
                    ");
                    $stmt->execute(array_values($data));
                    $message = 'Question added successfully';
                }
                $messageType = 'success';

            } elseif ($action === 'delete_question') {
                if (!$canEdit) {
                    throw new RuntimeException('This Question Bank is locked and questions cannot be deleted.');
                }
                $questionId = (int)$_POST['question_id'];
                $stmt = $pdo->prepare("
                    UPDATE questions_draft SET is_active = 0 WHERE id = ? AND staff_id = ?
                ");
                $stmt->execute([$questionId, $user['staff_code']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Question not found or you do not have permission to delete it.');
                }
                $message = 'Question deleted';
                $messageType = 'success';

            } elseif ($action === 'resolve_correction') {
                if (!$canEdit) {
                    throw new RuntimeException('This Question Bank is locked. Corrections cannot be resolved from this page.');
                }
                $correctionId = (int)$_POST['correction_id'];
                $resolutionNote = trim((string)$_POST['resolution_note']);

                $stmt = $pdo->prepare("
                    UPDATE corrections
                    SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), resolver_note = ?
                    WHERE id = ? AND target_type = 'question'
                      AND target_id IN (
                          SELECT id FROM questions_draft
                          WHERE staff_id = ? AND course_code = ? AND regulation = ?
                            AND academic_year = ? AND exam_type = ? AND paper_type = ?
                      )
                ");
                $stmt->execute([$user['staff_code'], $resolutionNote, $correctionId, $user['staff_code'], $courseCode, $regulation, $academicYear, $examType, $paperType]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('The correction is no longer open or does not belong to this question bank.');
                }

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM corrections
                    WHERE target_type = 'question' AND status = 'open'
                    AND target_id IN (
                        SELECT id FROM questions_draft
                        WHERE staff_id = ? AND course_code = ? AND regulation = ?
                          AND academic_year = ? AND exam_type = ? AND paper_type = ?
                    )
                ");
                $stmt->execute([$user['staff_code'], $courseCode, $regulation, $academicYear, $examType, $paperType]);
                $openCount = (int)$stmt->fetchColumn();

                if ($openCount === 0 && $submissionId) {
                    qps_transition($pdo, $submissionId, 'question_bank', 'hod_corrections', 'submitted_to_hod', $user['staff_code'], 'teaching', 'All corrections resolved by faculty');
                }

                $message = 'Correction marked as resolved';
                $messageType = 'success';
            } elseif ($action === 'submit_to_hod') {
                if (!$submissionId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO course_submissions (
                            course_code, course_title, dept_code, dept_name, semester,
                            academic_year, exam_type, programme_year, language,
                            staff_code, total_marks, question_bank_status,
                            question_bank_draft_id, submitted_at, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted_to_hod', NULL, NOW(), NOW(), NOW())
                    ");
                    $stmtCourse = $pdo->prepare("
                        SELECT c.*, d.name as dept_name
                        FROM courses c
                        LEFT JOIN departments d ON UPPER(c.dept_code) = UPPER(d.code)
                        WHERE UPPER(c.coursecode) = UPPER(?)
                    ");
                    $stmtCourse->execute([$courseCode]);
                    $courseInfo = $stmtCourse->fetch();

                    $stmt->execute([
                        $courseCode,
                        $courseInfo['coursetitle'] ?? $courseCode,
                        $courseInfo['dept_code'] ?? '',
                        $courseInfo['dept_name'] ?? '',
                        $courseInfo['semester'] ?? '1',
                        $academicYear,
                        $examType,
                        $courseInfo['programme_year'] ?? '1',
                        $courseInfo['language'] ?? 'EN',
                        $user['staff_code'],
                        $courseInfo['maxmark'] ?? 75
                    ]);
                    $submissionId = (int)$pdo->lastInsertId();
                    $message = 'Question Bank submitted to HOD';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE course_submissions
                        SET question_bank_status = 'submitted_to_hod', submitted_at = NOW(), updated_at = NOW()
                        WHERE id = ? AND staff_code = ?
                    ");
                    $stmt->execute([$submissionId, $user['staff_code']]);
                    $message = 'Question Bank re-submitted to HOD';
                }

                qps_audit($pdo, 'course_submission', $submissionId, 'submit_to_hod',
                    $user['staff_code'], 'teaching',
                    ['status' => 'submitted_to_hod', 'item_type' => 'question_bank']);

                $status = 'submitted_to_hod';
                $canEdit = false;
                $messageType = 'success';
            }
        } catch (Throwable $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
            error_log('[Question Bank Draft] POST error: ' . $e->getMessage());
        }
    }

    // Refresh the current filtered page after a POST action.
    if ($courseCode) {
        try {
            $questions = $loadDraftQuestions();
        } catch (Throwable $e) {
            error_log('[Question Bank Draft] Error refreshing questions: ' . $e->getMessage());
        }
    }
}

// Get units for this course
$units = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT unit_number FROM questions_draft
        WHERE staff_id = ? AND course_code = ? AND regulation = ?
          AND academic_year = ? AND exam_type = ? AND paper_type = ? AND is_active = 1
        UNION
        SELECT DISTINCT unit_no FROM syllabus
        WHERE UPPER(course_code) = UPPER(?)
        ORDER BY unit_number
    ");
    $stmt->execute([$user['staff_code'], $courseCode, $regulation, $academicYear, $examType, $paperType, $courseCode]);
    $units = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// CO codes for this course
$coCodes = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT co_code FROM course_outcomes WHERE UPPER(course_code) = UPPER(?)
    ");
    $stmt->execute([$courseCode]);
    $coCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$availableCourses = [];
if (!$courseCode) {
    try {
        $courseStmt = $pdo->prepare("
            SELECT DISTINCT tf.papercode AS course_code,
                   COALESCE(c.coursetitle, tf.papercode) AS course_title
            FROM timetablefaculty tf
            LEFT JOIN courses c ON UPPER(tf.papercode) = UPPER(c.coursecode)
            WHERE UPPER(tf.fid) = UPPER(?)
            ORDER BY tf.papercode
        ");
        $courseStmt->execute([$user['staff_code']]);
        $availableCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[Question Bank Draft] Course selector load failed: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
if (!$courseCode):
?>
<main class="max-w-3xl mx-auto px-4 py-10">
  <div class="card-modern p-6">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center"><i data-lucide="book-open" class="w-5 h-5"></i></div>
      <div><h1 class="text-xl font-black text-slate-900">Draft Questions</h1><p class="text-sm text-slate-500">Select one of your allocated courses to continue.</p></div>
    </div>
    <?php if (!$availableCourses): ?>
      <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">No allocated courses were found for your staff account.</div>
    <?php else: ?>
      <form method="GET" class="flex flex-col sm:flex-row gap-3">
        <select name="course_code" required class="flex-1 rounded-lg border border-slate-200 px-3 py-3">
          <option value="">Choose course</option>
          <?php foreach ($availableCourses as $availableCourse): ?>
            <option value="<?php echo htmlspecialchars($availableCourse['course_code']); ?>"><?php echo htmlspecialchars($availableCourse['course_code'] . ' — ' . $availableCourse['course_title']); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg px-5 py-3 font-bold">Open Draft Questions</button>
      </form>
    <?php endif; ?>
    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/dashboard.php" class="inline-block mt-5 text-sm text-indigo-600 font-semibold">← Back to Dashboard</a>
  </div>
</main>
<?php
require_once __DIR__ . '/../../includes/footer.php';
exit;
endif;
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
      <nav class="flex items-center gap-2 text-xs text-slate-500 mb-2">
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/dashboard.php" class="hover:text-slate-700">Dashboard</a>
        <i data-lucide="chevron-right" class="w-3 h-3"></i>
        <span class="text-slate-900 font-bold">Question Bank Draft</span>
      </nav>
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900">
        <?php echo htmlspecialchars($courseCode); ?> — Question Bank Draft
      </h1>
      <p class="text-sm text-slate-500 mt-1">
        <?php echo htmlspecialchars($examType); ?> / <?php echo htmlspecialchars($paperType); ?>
        | <?php echo htmlspecialchars($academicYear); ?> / <?php echo htmlspecialchars($regulation); ?>
      </p>
    </div>
    <div class="flex gap-2">
      <?php if ($canEdit): ?>
        <button type="button" onclick="openQuestionModal()"
                class="btn-orange-pill text-sm shadow-md">
          <i data-lucide="plus-circle" class="w-4 h-4"></i>
          <span>Add Question</span>
        </button>
      <?php endif; ?>
      <?php if ($canEdit && count($questions) > 0 && $status === 'draft'): ?>
        <form method="POST" class="inline">
          <input type="hidden" name="action" value="submit_to_hod">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <button type="submit"
                  onclick="return confirm('Submit this Question Bank to HOD for review?')"
                  class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition">
            <i data-lucide="send" class="w-4 h-4 inline mr-1"></i>
            Submit to HOD
          </button>
        </form>
      <?php endif; ?>
      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/dashboard.php"
         class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-bold transition">
        <i data-lucide="arrow-left" class="w-4 h-4 inline mr-1"></i>
        Back to Courses
      </a>
    </div>
  </div>

  <!-- Status Badge -->
  <div class="flex items-center gap-3 flex-wrap">
    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border
      <?php
        $sc = [
          'draft' => 'bg-slate-50 text-slate-600 border-slate-200',
          'submitted_to_hod' => 'bg-blue-50 text-blue-700 border-blue-200',
          'hod_corrections' => 'bg-rose-50 text-rose-700 border-rose-200',
          'sent_to_dean' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
          'dean_corrections' => 'bg-rose-50 text-rose-700 border-rose-200',
          'sent_to_coe' => 'bg-purple-50 text-purple-700 border-purple-200',
          'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        ];
        echo $sc[$status] ?? $sc['draft'];
      ?>">
      <i data-lucide="<?php echo $status === 'approved' ? 'check-circle' : ($status === 'draft' ? 'file-text' : 'clock'); ?>" class="w-3 h-3"></i>
      Status: <?php echo ucwords(str_replace('_', ' ', $status)); ?>
    </span>
    <?php if ($status === 'hod_corrections' || $status === 'dean_corrections'): ?>
      <span class="bg-rose-50 text-rose-700 border border-rose-200 px-3 py-1 rounded-full text-xs font-bold">
        <i data-lucide="alert-circle" class="w-3 h-3 inline mr-1"></i>
        Corrections Pending — Resolve below
      </span>
    <?php elseif ($status === 'submitted_to_hod'): ?>
      <span class="bg-blue-50 text-blue-700 border border-blue-200 px-3 py-1 rounded-full text-xs font-bold">
        <i data-lucide="clock" class="w-3 h-3 inline mr-1"></i>
        Submitted to HOD — Awaiting review
      </span>
    <?php elseif ($status === 'approved'): ?>
      <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-3 py-1 rounded-full text-xs font-bold">
        <i data-lucide="check-circle" class="w-3 h-3 inline mr-1"></i>
        Approved — <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo urlencode($courseCode); ?>&item_type=question_bank" class="underline hover:text-emerald-900">View Blueprint</a>
      </span>
    <?php endif; ?>
    <?php if ($submissionId): ?>
      <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-full text-xs font-mono">
        Submission ID: #<?php echo $submissionId; ?>
      </span>
    <?php endif; ?>
  </div>

  <!-- Messages -->
  <?php if ($message): ?>
    <div class="rounded-lg px-4 py-3 text-sm font-medium
      <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' :
             ($messageType === 'error' ? 'bg-rose-50 text-rose-800 border border-rose-200' : 'bg-blue-50 text-blue-800 border border-blue-200'); ?>">
      <i data-lucide="<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info'); ?>" class="w-4 h-4 inline mr-1"></i>
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <!-- Questions Table -->
  <div class="card-modern overflow-hidden">
    <div class="p-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
      <h2 class="font-black text-slate-900">Draft Questions (<?php echo (int)$questionsTotal; ?>)</h2>
      <div class="flex items-center gap-2 text-xs text-slate-500">
        <span>Units: <?php echo count($units); ?></span>
        <span class="mx-1">|</span>
        <span>COs: <?php echo count($coCodes); ?></span>
      </div>
    </div>

    <form method="GET" class="p-4 bg-slate-50 border-b border-slate-200 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
      <?php if ($submissionId): ?><input type="hidden" name="submission_id" value="<?php echo (int)$submissionId; ?>"><?php endif; ?>
      <input type="search" name="q" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search question / number / sub-unit" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
      <select name="unit" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
        <option value="">All units</option>
        <?php foreach ($units as $unitOption): ?>
          <option value="<?php echo (int)$unitOption; ?>" <?php echo $filterUnit === (string)$unitOption ? 'selected' : ''; ?>>Unit <?php echo (int)$unitOption; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
        <option value="">All statuses</option>
        <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
        <option value="verification_pending" <?php echo $filterStatus === 'verification_pending' ? 'selected' : ''; ?>>Verification pending</option>
        <option value="verified" <?php echo $filterStatus === 'verified' ? 'selected' : ''; ?>>Verified</option>
      </select>
      <select name="has_corrections" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
        <option value="">All correction states</option>
        <option value="1" <?php echo $filterCorrections === '1' ? 'selected' : ''; ?>>Has open corrections</option>
        <option value="0" <?php echo $filterCorrections === '0' ? 'selected' : ''; ?>>Clear</option>
      </select>
      <div class="flex gap-2">
        <button class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg px-3 py-2 text-sm font-bold">Filter</button>
        <a href="?course_code=<?php echo urlencode($courseCode); ?>" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-semibold">Reset</a>
      </div>
    </form>

    <div class="overflow-x-auto">
      <table class="w-full min-w-[1250px] text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="p-3 text-left w-16">No.</th>
            <th class="p-3 text-left w-28">Unit / Sub-unit</th>
            <th class="p-3 text-left w-16">Marks</th>
            <th class="p-3 text-left w-24">Type</th>
            <th class="p-3 text-left w-20">K-Level</th>
            <th class="p-3 text-left w-20">CO</th>
            <th class="p-3 text-left w-24">Section</th>
            <th class="p-3 text-left">Question</th>
            <th class="p-3 text-left w-36">Verification</th>
            <th class="p-3 text-left w-56">Corrections</th>
            <th class="p-3 text-right w-44">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (empty($questions)): ?>
            <tr>
              <td colspan="11" class="p-10 text-center text-slate-500">
                <i data-lucide="database" class="w-12 h-12 mx-auto text-slate-300 mb-3"></i>
                <p class="font-medium">No questions in this draft yet</p>
                <p class="text-xs mt-1">Click "Add Question" to start building your question bank</p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($questions as $q):
              $hasCorrection = !empty($q['correction_id']);
              $correctionStatus = $q['correction_status'] ?? '';
              // Decode options for display
              $typeLabel = $q['question_type'];
              $optionsDisplay = '';
              if ($q['question_type'] === 'MCQ' && $q['options_json']) {
                  $opts = json_decode($q['options_json'], true);
                  if ($opts && isset($opts['options'])) {
                      $optionsDisplay = '<div class="text-[10px] text-slate-500 mt-1">';
                      foreach ($opts['options'] as $idx => $opt) {
                          $prefix = ($idx === ($opts['correct'] ?? -1)) ? '✓ ' : '';
                          $optionsDisplay .= "$prefix{$opt['text']}<br>";
                      }
                      $optionsDisplay .= '</div>';
                  }
              } elseif ($q['question_type'] === 'Match' && !empty($q['match_column_a'])) {
                  $colA = json_decode((string)($q['match_column_a'] ?? '[]'), true) ?? [];
                  $colB = json_decode((string)($q['match_column_b'] ?? '[]'), true) ?? [];
                  $ansKey = json_decode((string)($q['match_answer_key'] ?? '[]'), true) ?? [];
                  $optionsDisplay = '<div class="text-[10px] text-slate-500 mt-1">';
                  foreach ($colA as $idx => $a) {
                      $b = $colB[$idx] ?? '';
                      $ans = $ansKey[$idx] ?? '';
                      $optionsDisplay .= "{$a} → {$b} (Ans: {$ans})<br>";
                  }
                  $optionsDisplay .= '</div>';
              } elseif ($q['question_type'] === 'Assertion and Reasoning') {
                  if (!empty($q['assertion_text'])) $optionsDisplay .= '<div class="text-[10px] text-slate-500 mt-1"><strong>Assertion:</strong> ' . htmlspecialchars($q['assertion_text']) . '</div>';
                  if (!empty($q['reason_text'])) $optionsDisplay .= '<div class="text-[10px] text-slate-500 mt-1"><strong>Reason:</strong> ' . htmlspecialchars($q['reason_text']) . '</div>';
                  if ($q['options_json']) {
                      $ar = json_decode($q['options_json'], true);
                      $labels = ['A'=>'Both true & R explains A', 'B'=>'Both true but R doesn\'t explain A', 'C'=>'A true, R false', 'D'=>'A false, R true'];
                      $correct = $ar['correct'] ?? 0;
                      $optionsDisplay .= '<div class="text-[10px] text-slate-500 mt-1">Correct: ' . ($labels[$correct] ?? $correct) . '</div>';
                  }
              }
            ?>
              <tr class="hover:bg-slate-50">
                <td class="p-3 font-bold text-slate-900">Q<?php echo (int)$q['question_number']; ?></td>
                <td class="p-3">
                  <div>Unit <?php echo (int)$q['unit_number']; ?></div>
                  <?php if (!empty($q['sub_unit_number'])): ?>
                    <span class="text-xs text-slate-500"><?php echo htmlspecialchars($q['sub_unit_number']); ?></span>
                  <?php endif; ?>
                </td>
                <td class="p-3 font-bold text-slate-700"><?php echo (int)$q['marks']; ?></td>
                <td class="p-3"><span class="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($q['question_type']); ?></span></td>
                <td class="p-3"><span class="bg-purple-50 text-purple-700 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($q['blooms_level']); ?></span></td>
                <td class="p-3"><span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($q['co_code']); ?></span></td>
                <td class="p-3 text-xs text-slate-600"><?php echo htmlspecialchars((string)($q['section_type'] ?? $q['section'] ?? '—')); ?></td>
                <td class="p-3 max-w-lg">
                  <div class="line-clamp-2 text-slate-800"><?php echo htmlspecialchars($q['question_text']); ?></div>
                  <?php if (!empty($q['image_url'])): ?>
                    <div class="mt-1">
                      <img src="<?php echo getBaseUrl(); ?>/<?php echo htmlspecialchars($q['image_url']); ?>" alt="Question Image" class="max-h-24 rounded border">
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($q['has_formula']) && !empty($q['formula_latex'])): ?>
                    <div class="mt-1 text-xs text-blue-700 bg-blue-50 p-1 rounded font-mono">
                      <?php echo htmlspecialchars($q['formula_latex']); ?>
                    </div>
                  <?php endif; ?>
                  <?php echo $optionsDisplay; ?>
                  <?php if (!empty($q['answer_key'])): ?>
                    <div class="text-xs text-slate-500 mt-1 line-clamp-1">
                      <strong>Answer:</strong> <?php echo htmlspecialchars($q['answer_key']); ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="p-3">
                  <?php $verified = !empty($q['verified_at']) || in_array(strtolower((string)($q['status'] ?? '')), ['verified','approved','coe_approved'], true); ?>
                  <?php if ($verified): ?>
                    <span class="inline-flex rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-1 text-[10px] font-bold">Verified</span>
                  <?php else: ?>
                    <span class="inline-flex rounded-full bg-amber-50 text-amber-700 border border-amber-200 px-2 py-1 text-[10px] font-bold">Verification pending</span>
                  <?php endif; ?>
                </td>
                <td class="p-3">
                  <?php if ($hasCorrection): ?>
                    <div class="bg-rose-50 border border-rose-200 rounded-lg p-2">
                      <div class="text-xs text-rose-800 font-bold mb-1">
                        <i data-lucide="alert-circle" class="w-3 h-3 inline mr-1"></i>
                        Open correction · <?php echo htmlspecialchars((string)($q['correction_role'] ?? 'Reviewer')); ?>
                      </div>
                      <div class="text-[11px] text-rose-700 mb-1"><?php echo htmlspecialchars($q['correction_remark']); ?></div>
                      <div class="text-[10px] text-rose-500 mb-2"><?php echo !empty($q['correction_created_at']) ? date('d M Y, h:i A', strtotime($q['correction_created_at'])) : ''; ?></div>
                      <?php if ($canEdit): ?>
                      <form method="POST" class="inline">
                        <input type="hidden" name="action" value="resolve_correction">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="correction_id" value="<?php echo (int)$q['correction_id']; ?>">
                        <textarea name="resolution_note" rows="2" class="w-full text-[11px] p-1.5 border border-rose-200 rounded mb-1" placeholder="Describe correction made..."></textarea>
                        <button type="submit" class="w-full bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-2 py-1 rounded text-[11px] font-bold transition">
                          Mark Resolved
                        </button>
                      </form>
                      <?php else: ?>
                        <span class="text-[10px] text-rose-500 font-semibold">Locked — cannot resolve</span>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-emerald-700 text-xs font-medium">
                      <i data-lucide="check-circle" class="w-3 h-3 inline mr-1"></i>
                      Clear
                    </span>
                  <?php endif; ?>
                </td>
                <td class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button type="button" onclick='viewQuestion(<?php echo json_encode($q, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="bg-slate-100 text-slate-700 hover:bg-slate-200 px-3 py-1.5 rounded text-xs font-bold">View</button>
                    <?php if ($canEdit): ?>
                      <button type="button"
                              onclick='openQuestionModal(<?php echo json_encode($q, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                              class="bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-3 py-1.5 rounded text-xs font-bold transition">
                        <i data-lucide="edit" class="w-3.5 h-3.5 inline mr-1"></i>
                        Edit
                      </button>
                      <form method="POST" class="inline" onsubmit="return confirm('Delete this question?')">
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="question_id" value="<?php echo (int)$q['id']; ?>">
                        <button type="submit" class="bg-rose-50 text-rose-700 hover:bg-rose-100 px-3 py-1.5 rounded text-xs font-bold transition">
                          <i data-lucide="trash-2" class="w-3.5 h-3.5 inline mr-1"></i>
                          Delete
                        </button>
                      </form>
                    <?php else: ?>
                      <span class="bg-slate-100 text-slate-400 px-3 py-1.5 rounded text-xs font-bold cursor-not-allowed">
                        <i data-lucide="lock" class="w-3 h-3 inline mr-1"></i>
                        Locked
                      </span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-3 text-sm">
      <span class="text-slate-500">Showing <?php echo $questionsTotal ? (($page - 1) * $pageSize + 1) : 0; ?>–<?php echo min($page * $pageSize, $questionsTotal); ?> of <?php echo (int)$questionsTotal; ?> questions</span>
      <div class="flex items-center gap-2">
        <?php
          $paginationParams = $_GET;
          unset($paginationParams['page']);
          $paginationBase = '?' . http_build_query($paginationParams);
        ?>
        <a class="px-3 py-1.5 rounded border <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-slate-50'; ?>" href="<?php echo htmlspecialchars($paginationBase . '&page=' . max(1, $page - 1)); ?>">Previous</a>
        <span class="font-bold text-slate-700">Page <?php echo min($page, $totalPages); ?> / <?php echo $totalPages; ?></span>
        <a class="px-3 py-1.5 rounded border <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-slate-50'; ?>" href="<?php echo htmlspecialchars($paginationBase . '&page=' . min($totalPages, $page + 1)); ?>">Next</a>
      </div>
    </div>
  </div>

  <!-- Quick Stats -->
  <?php if (!empty($questions)): ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="card-modern p-4 text-center">
        <div class="text-2xl font-black text-orange-600"><?php echo (int)$questionsTotal; ?></div>
        <div class="text-xs text-slate-500">Total Questions</div>
      </div>
      <div class="card-modern p-4 text-center">
        <div class="text-2xl font-black text-purple-600">
          <?php echo array_sum(array_column($questions, 'marks')); ?>
        </div>
        <div class="text-xs text-slate-500">Total Marks</div>
      </div>
      <div class="card-modern p-4 text-center">
        <div class="text-2xl font-black text-emerald-600">
          <?php echo count(array_unique(array_column($questions, 'unit_number'))); ?>
        </div>
        <div class="text-xs text-slate-500">Units Covered</div>
      </div>
      <div class="card-modern p-4 text-center">
        <div class="text-2xl font-black text-blue-600">
          <?php echo count(array_unique(array_column($questions, 'co_code'))); ?>
        </div>
        <div class="text-xs text-slate-500">COs Covered</div>
      </div>
    </div>
  <?php endif; ?>

</main>

<!-- Read-only question preview modal -->
<div id="viewQuestionModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
    <div class="p-5 border-b flex items-center justify-between">
      <h3 class="text-lg font-black text-slate-900" id="viewQuestionTitle">Question Preview</h3>
      <button type="button" onclick="closeViewQuestion()" class="text-slate-500 hover:text-slate-800">✕</button>
    </div>
    <div class="p-5 space-y-4">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm" id="viewQuestionMeta"></div>
      <div class="rounded-xl bg-slate-50 border p-4">
        <div class="text-xs uppercase font-bold text-slate-500 mb-2">Question</div>
        <div id="viewQuestionText" class="whitespace-pre-wrap text-slate-800"></div>
      </div>
      <div id="viewQuestionExtra" class="text-sm text-slate-600 space-y-2"></div>
    </div>
  </div>
</div>

<!-- Add/Edit Question Modal -->
<div id="questionModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto">
    <div class="p-5 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white">
      <h3 class="text-lg font-black text-slate-900" id="modalTitle">Add Question</h3>
      <button type="button" onclick="closeQuestionModal()" class="text-slate-400 hover:text-slate-600 p-1">
        <i data-lucide="x" class="w-5 h-5"></i>
      </button>
    </div>
    <?php
// Build form action URL preserving current query parameters
$formAction = getBaseUrl() . '/modules/teaching/question_bank_draft.php?' . http_build_query(array_filter([
    'course_code' => $courseCode,
    'submission_id' => $submissionId,
    'correct' => $correctMode ? '1' : null,
]));
?>
<form method="POST" id="questionForm" class="p-5 space-y-4" enctype="multipart/form-data" action="<?php echo htmlspecialchars($formAction); ?>">
      <input type="hidden" name="action" value="save_question">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="question_id" id="questionId" value="0">
      <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">

      <!-- Basic Fields -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Unit Number *</label>
          <select name="unit_number" id="unitNumber" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <option value="<?php echo $i; ?>">Unit <?php echo $i; ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Sub-Unit</label>
          <input type="text" name="sub_unit_number" id="subUnitNumber" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" placeholder="e.g., 1.1, 1.2">
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Marks *</label>
          <select name="marks" id="marks" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500">
            <option value="1">1 Mark</option>
            <option value="2">2 Marks</option>
            <option value="5">5 Marks</option>
            <option value="10">10 Marks</option>
            <option value="15">15 Marks</option>
            <option value="20">20 Marks</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Bloom's Level (K-Level) *</label>
          <select name="blooms_level" id="bloomsLevel" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500">
            <option value="K1">K1 - Remember</option>
            <option value="K2">K2 - Understand</option>
            <option value="K3">K3 - Apply</option>
            <option value="K4">K4 - Analyze</option>
            <option value="K5">K5 - Evaluate</option>
            <option value="K6">K6 - Create</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">CO Code *</label>
          <select name="co_code" id="coCode" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500">
            <?php foreach ($coCodes as $co): ?>
              <option value="<?php echo htmlspecialchars($co); ?>"><?php echo htmlspecialchars($co); ?></option>
            <?php endforeach; ?>
            <?php if (empty($coCodes)): ?>
              <option value="CO1">CO1</option>
              <option value="CO2">CO2</option>
              <option value="CO3">CO3</option>
              <option value="CO4">CO4</option>
              <option value="CO5">CO5</option>
            <?php endif; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Question Type *</label>
          <select name="question_type" id="questionType" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" onchange="onQuestionTypeChange()">
            <option value="MCQ">MCQ (Multiple Choice)</option>
            <option value="Match">Match the Following</option>
            <option value="Assertion and Reasoning">Assertion and Reasoning</option>
            <option value="VAS">VAS (Very Short Answer)</option>
            <option value="Paragraph">Paragraph</option>
            <option value="Essay">Essay</option>
          </select>
        </div>
      </div>

      <!-- Question Text -->
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Question Text *</label>
        <textarea name="question_text" id="questionText" rows="4" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" placeholder="Enter the complete question text..."></textarea>
      </div>

      <!-- Dynamic Content Area based on Question Type -->
      <div id="dynamicContent" class="space-y-4">
        <!-- MCQ Content -->
        <div id="mcqContent" class="hidden">
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 class="font-bold text-blue-800 mb-3 flex items-center gap-2">
              <i data-lucide="list" class="w-4 h-4"></i>
              MCQ Options (4 options, select correct answer)
            </h4>
            <div class="space-y-2" id="mcqOptions">
              <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="flex items-center gap-2">
                  <input type="radio" name="correct_option" value="<?php echo $i; ?>" id="mcq_correct_<?php echo $i; ?>" class="w-4 h-4 text-orange-600" <?php echo $i === 0 ? 'checked' : ''; ?>>
                  <input type="text" name="option_<?php echo $i; ?>" placeholder="Option <?php echo chr(65 + $i); ?> (A/B/C/D)" class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500">
                  <label for="mcq_correct_<?php echo $i; ?>" class="text-xs text-slate-500">Correct</label>
                </div>
              <?php endfor; ?>
            </div>
          </div>
        </div>

        <!-- Match Content -->
        <div id="matchContent" class="hidden">
          <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-3">
              <h4 class="font-bold text-purple-800 flex items-center gap-2">
                <i data-lucide="link" class="w-4 h-4"></i>
                Match the Following
              </h4>
              <button type="button" onclick="addMatchRow()" class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded hover:bg-purple-200 transition">
                <i data-lucide="plus" class="w-3 h-3 inline mr-1"></i> Add Row
              </button>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm" id="matchTable">
                <thead class="bg-purple-100 text-purple-800">
                  <tr>
                    <th class="p-2 text-center w-8">#</th>
                    <th class="p-2">Column A (Question)</th>
                    <th class="p-2">Column B (Answer)</th>
                    <th class="p-2 text-center w-24">Correct Match (Index)</th>
                    <th class="p-2 text-center w-10"></th>
                  </tr>
                </thead>
                <tbody id="matchBody">
                  <?php for ($i = 0; $i < 4; $i++): ?>
                    <tr class="match-row" data-index="<?php echo $i; ?>">
                      <td class="p-2 text-center text-slate-500"><?php echo $i + 1; ?></td>
                      <td class="p-2"><input type="text" name="match_a_<?php echo $i; ?>" placeholder="Column A item" class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
                      <td class="p-2"><input type="text" name="match_b_<?php echo $i; ?>" placeholder="Column B item" class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
                      <td class="p-2 text-center"><input type="number" name="match_ans_<?php echo $i; ?>" min="1" max="5" placeholder="1-5" class="w-20 border border-slate-200 rounded px-2 py-1 text-sm text-center focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
                      <td class="p-2 text-center"><button type="button" onclick="removeMatchRow(this)" class="text-rose-500 hover:text-rose-700" title="Remove"><i data-lucide="trash-2" class="w-4 h-4"></i></button></td>
                    </tr>
                  <?php endfor; ?>
                  <!-- 5th row for Column B extra -->
                  <tr class="match-row" data-index="4" style="display: none;">
                    <td class="p-2 text-center text-slate-500">5</td>
                    <td class="p-2"><input type="text" name="match_a_4" placeholder="Column A item (optional)" class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
                    <td class="p-2"><input type="text" name="match_b_4" placeholder="Column B item" class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
                    <td class="p-2 text-center"><input type="number" name="match_ans_4" min="1" max="5" placeholder="1-5" class="w-20 border border-slate-200 rounded px-2 py-1 text-sm text-center focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
                    <td class="p-2 text-center"><button type="button" onclick="removeMatchRow(this)" class="text-rose-500 hover:text-rose-700" title="Remove"><i data-lucide="trash-2" class="w-4 h-4"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <input type="hidden" name="match_count" id="matchCount" value="4">
            <p class="text-xs text-slate-500 mt-2">
              <i data-lucide="info" class="w-3 h-3 inline mr-1"></i>
              Enter Column A items (questions) and Column B items (answers). In "Correct Match", enter the Column B index (1-5) that matches each Column A item. Column B can have 5 items, Column A has 4 items.
            </p>
          </div>
        </div>

        <!-- Assertion and Reasoning Content -->
        <div id="arContent" class="hidden">
          <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
            <h4 class="font-bold text-indigo-800 mb-3 flex items-center gap-2">
              <i data-lucide="message-square" class="w-4 h-4"></i>
              Assertion and Reasoning
            </h4>
            <div class="space-y-3">
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Assertion (A) *</label>
                <textarea name="assertion_text" id="assertionText" rows="2" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" placeholder="Enter the assertion statement..."></textarea>
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Reason (R) *</label>
                <textarea name="reason_text" id="reasonText" rows="2" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" placeholder="Enter the reason statement..."></textarea>
              </div>
              <div class="bg-white border border-slate-200 rounded-lg p-3">
                <label class="block text-xs font-bold text-slate-700 mb-2">Select Correct Option *</label>
                <div class="space-y-1">
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="ar_correct" value="0" class="w-4 h-4 text-indigo-600" checked>
                    <span class="text-sm"><strong>A.</strong> Both A and R are true and R is the correct explanation of A</span>
                  </label>
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="ar_correct" value="1" class="w-4 h-4 text-indigo-600">
                    <span class="text-sm"><strong>B.</strong> Both A and R are true but R is NOT the correct explanation of A</span>
                  </label>
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="ar_correct" value="2" class="w-4 h-4 text-indigo-600">
                    <span class="text-sm"><strong>C.</strong> A is true but R is false</span>
                  </label>
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="ar_correct" value="3" class="w-4 h-4 text-indigo-600">
                    <span class="text-sm"><strong>D.</strong> A is false but R is true</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- VAS / Paragraph / Essay Content -->
        <div id="textContent" class="hidden">
          <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
            <h4 class="font-bold text-emerald-800 mb-3 flex items-center gap-2">
              <i data-lucide="file-text" class="w-4 h-4"></i>
              Answer Key / Model Answer
            </h4>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Model Answer / Key Points *</label>
              <textarea name="answer_key" id="answerKey" rows="5" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500" placeholder="Enter the expected answer, key points, or marking scheme..."></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Image & Formula Upload Section -->
      <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 space-y-4">
        <h4 class="font-bold text-slate-800 flex items-center gap-2">
          <i data-lucide="image" class="w-4 h-4"></i>
          Image & Formula Upload
        </h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Question Image / Diagram</label>
            <input type="file" name="question_image" id="questionImage" accept="image/*" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-orange-50 file:text-orange-700 hover:file:bg-orange-100">
            <p class="text-xs text-slate-500 mt-1">Upload diagram, graph, or image for the question (PNG, JPG, GIF)</p>
            <div id="imagePreview" class="hidden mt-2">
              <img id="previewImg" src="" alt="Preview" class="max-h-32 rounded border">
              <button type="button" onclick="clearImage()" class="text-xs text-rose-600 hover:text-rose-800 mt-1 inline-block">Remove Image</button>
            </div>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Formula (LaTeX)</label>
            <textarea name="formula_latex" id="formulaLatex" rows="2" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500" placeholder="Enter LaTeX formula (e.g., \\int_0^1 x^2 dx = \\frac{1}{3})"></textarea>
            <p class="text-xs text-slate-500 mt-1">Optional: Enter mathematical formula in LaTeX format</p>
            <div id="formulaPreview" class="hidden mt-2 p-2 bg-white border border-slate-200 rounded text-sm" style="font-family: 'KaTeX_Main', serif;"></div>
          </div>
        </div>
      </div>

      <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
        <button type="button" onclick="closeQuestionModal()" class="px-4 py-2 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm font-bold transition">Cancel</button>
        <button type="submit" class="btn-orange-pill text-sm shadow-sm">
          <i data-lucide="save" class="w-4 h-4 inline mr-1"></i>
          Save Question
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Question type change handler
function onQuestionTypeChange() {
  const type = document.getElementById('questionType').value;
  const sections = ['mcqContent', 'matchContent', 'arContent', 'textContent'];

  sections.forEach(id => {
    const el = document.getElementById(id);
    const isVisible = (
      (type === 'MCQ' && id === 'mcqContent') ||
      (type === 'Match' && id === 'matchContent') ||
      (type === 'Assertion and Reasoning' && id === 'arContent') ||
      (['VAS', 'Paragraph', 'Essay'].includes(type) && id === 'textContent')
    );

    if (isVisible) {
      el.classList.remove('hidden');
      // Enable required fields in visible section
      el.querySelectorAll('[required]').forEach(input => {
        input.required = true;
        input.disabled = false;
      });
    } else {
      el.classList.add('hidden');
      // Disable required fields in hidden sections to prevent validation errors
      el.querySelectorAll('[required]').forEach(input => {
        input.required = false;
        input.disabled = true;
      });
    }
  });
  lucide.createIcons();
}

// Match table functions
let matchRowCounter = 4; // 0-3 exist, 4 is hidden 5th row

function addMatchRow() {
  const tbody = document.getElementById('matchBody');
  const hiddenRow = tbody.querySelector('tr[data-index="4"]');

  if (hiddenRow && hiddenRow.style.display === 'none') {
    hiddenRow.style.display = '';
    document.getElementById('matchCount').value = '5';
    return;
  }

  if (matchRowCounter >= 8) {
    alert('Maximum 8 rows allowed');
    return;
  }

  matchRowCounter++;
  const row = document.createElement('tr');
  row.className = 'match-row';
  row.dataset.index = matchRowCounter;
  row.innerHTML = `
    <td class="p-2 text-center text-slate-500">${matchRowCounter + 1}</td>
    <td class="p-2"><input type="text" name="match_a_${matchRowCounter}" placeholder="Column A item" class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
    <td class="p-2"><input type="text" name="match_b_${matchRowCounter}" placeholder="Column B item" class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
    <td class="p-2 text-center"><input type="number" name="match_ans_${matchRowCounter}" min="1" max="8" placeholder="1-8" class="w-20 border border-slate-200 rounded px-2 py-1 text-sm text-center focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></td>
    <td class="p-2 text-center"><button type="button" onclick="removeMatchRow(this)" class="text-rose-500 hover:text-rose-700" title="Remove"><i data-lucide="trash-2" class="w-4 h-4"></i></button></td>
  `;
  tbody.appendChild(row);
  document.getElementById('matchCount').value = tbody.querySelectorAll('tr:not([style*="display: none"])').length;
  lucide.createIcons();
}

function removeMatchRow(btn) {
  const row = btn.closest('tr');
  const index = parseInt(row.dataset.index);

  if (index === 4) {
    // Hide the 5th row instead of removing
    row.style.display = 'none';
  } else {
    row.remove();
  }

  // Renumber visible rows
  const rows = document.querySelectorAll('#matchBody tr:not([style*="display: none"])');
  rows.forEach((r, i) => {
    r.dataset.index = i;
    r.querySelector('td').textContent = i + 1;
    // Update input names
    const inputs = r.querySelectorAll('input');
    inputs.forEach(input => {
      const name = input.name;
      if (name.startsWith('match_a_')) input.name = `match_a_${i}`;
      else if (name.startsWith('match_b_')) input.name = `match_b_${i}`;
      else if (name.startsWith('match_ans_')) input.name = `match_ans_${i}`;
    });
  });

  document.getElementById('matchCount').value = rows.length;
  matchRowCounter = rows.length - 1;
}

// Image preview
document.getElementById('questionImage').addEventListener('change', function(e) {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('previewImg').src = e.target.result;
      document.getElementById('imagePreview').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
  }
});

function clearImage() {
  document.getElementById('questionImage').value = '';
  document.getElementById('previewImg').src = '';
  document.getElementById('imagePreview').classList.add('hidden');
}

// Formula preview (simple text display, could integrate KaTeX if needed)
document.getElementById('formulaLatex').addEventListener('input', function(e) {
  const val = e.target.value;
  const preview = document.getElementById('formulaPreview');
  if (val.trim()) {
    preview.textContent = val;
    preview.classList.remove('hidden');
  } else {
    preview.classList.add('hidden');
  }
});

// Read-only preview. Text is inserted with textContent to avoid HTML injection.
function viewQuestion(question) {
  const modal = document.getElementById('viewQuestionModal');
  document.getElementById('viewQuestionTitle').textContent = `Question Q${question.question_number || ''}`;
  const meta = document.getElementById('viewQuestionMeta');
  meta.replaceChildren();
  [
    ['Unit', question.unit_number || '—'],
    ['Sub-unit', question.sub_unit_number || '—'],
    ['Marks', question.marks || '—'],
    ['Type', question.question_type || '—'],
    ['K-Level', question.blooms_level || '—'],
    ['CO', question.co_code || '—'],
    ['Status', question.status || 'draft'],
    ['Section', question.section_type || question.section || '—']
  ].forEach(([label, value]) => {
    const item = document.createElement('div');
    item.className = 'rounded-lg border bg-white p-3';
    const small = document.createElement('div');
    small.className = 'text-[10px] uppercase text-slate-400 font-bold';
    small.textContent = label;
    const strong = document.createElement('div');
    strong.className = 'font-bold text-slate-800 mt-1';
    strong.textContent = value;
    item.append(small, strong);
    meta.append(item);
  });
  document.getElementById('viewQuestionText').textContent = question.question_text || '';
  const extra = document.getElementById('viewQuestionExtra');
  extra.replaceChildren();
  [
    ['Answer key', question.answer_key],
    ['Formula', question.formula_latex],
    ['Correction', question.correction_remark]
  ].forEach(([label, value]) => {
    if (!value) return;
    const block = document.createElement('div');
    const strong = document.createElement('strong');
    strong.textContent = label + ': ';
    block.append(strong, document.createTextNode(value));
    extra.append(block);
  });
  modal.classList.remove('hidden');
  modal.classList.add('flex');
}
function closeViewQuestion() {
  const modal = document.getElementById('viewQuestionModal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

// Modal functions
function openQuestionModal(question = null) {
  if (question && question.correction_id) {
    const reviewer = question.correction_role || 'reviewer';
    const note = question.correction_remark || 'An open correction exists for this question.';
    if (!confirm(`This question has an open correction from ${reviewer}:\\n\\n${note}\\n\\nContinue editing? After saving, resolve the correction with a reply note.`)) return;
  }
  const modal = document.getElementById('questionModal');
  const form = document.getElementById('questionForm');
  const title = document.getElementById('modalTitle');

  form.reset();
  document.getElementById('questionId').value = '0';
  document.getElementById('imagePreview').classList.add('hidden');
  document.getElementById('formulaPreview').classList.add('hidden');

  // Reset match table to default 4 rows
  const matchBody = document.getElementById('matchBody');
  const rows = matchBody.querySelectorAll('tr');
  rows.forEach((row, i) => {
    if (i < 4) {
      row.style.display = '';
      row.dataset.index = i;
      row.querySelector('td').textContent = i + 1;
      const inputs = row.querySelectorAll('input');
      inputs.forEach(input => {
        const name = input.name;
        if (name.startsWith('match_a_')) input.name = `match_a_${i}`;
        else if (name.startsWith('match_b_')) input.name = `match_b_${i}`;
        else if (name.startsWith('match_ans_')) input.name = `match_ans_${i}`;
        input.value = '';
      });
    } else {
      row.style.display = 'none';
    }
  });
  document.getElementById('matchCount').value = '4';
  matchRowCounter = 3;

  // Reset MCQ correct option to first
  document.querySelector('input[name="correct_option"][value="0"]').checked = true;

  // Reset AR correct option to first
  document.querySelector('input[name="ar_correct"][value="0"]').checked = true;

  if (question) {
    title.textContent = 'Edit Question';
    document.getElementById('questionId').value = question.id;
    document.getElementById('unitNumber').value = question.unit_number;
    document.getElementById('subUnitNumber').value = question.sub_unit_number || '';
    document.getElementById('marks').value = question.marks;
    document.getElementById('bloomsLevel').value = question.blooms_level;
    document.getElementById('coCode').value = question.co_code;
    document.getElementById('questionType').value = question.question_type;
    document.getElementById('questionText').value = question.question_text;

    // Trigger type change to show correct dynamic content
    onQuestionTypeChange();

    // Populate type-specific fields
    if (question.question_type === 'MCQ' && question.options_json) {
      const opts = JSON.parse(question.options_json);
      if (opts.options) {
        opts.options.forEach((opt, i) => {
          const input = document.querySelector(`input[name="option_${i}"]`);
          if (input) input.value = opt.text;
        });
        if (typeof opts.correct === 'number') {
          document.querySelector(`input[name="correct_option"][value="${opts.correct}"]`).checked = true;
        }
      }
    } else if (question.question_type === 'Match') {
      const colA = JSON.parse(question.match_column_a || '[]');
      const colB = JSON.parse(question.match_column_b || '[]');
      const ansKey = JSON.parse(question.match_answer_key || '[]');
      colA.forEach((a, i) => {
        const inputA = document.querySelector(`input[name="match_a_${i}"]`);
        const inputB = document.querySelector(`input[name="match_b_${i}"]`);
        const inputAns = document.querySelector(`input[name="match_ans_${i}"]`);
        if (inputA) inputA.value = a;
        if (inputB) inputB.value = colB[i] || '';
        if (inputAns) inputAns.value = ansKey[i] || '';
      });
      document.getElementById('matchCount').value = colA.length;
    } else if (question.question_type === 'Assertion and Reasoning') {
      document.getElementById('assertionText').value = question.assertion_text || '';
      document.getElementById('reasonText').value = question.reason_text || '';
      if (question.options_json) {
        const ar = JSON.parse(question.options_json);
        if (typeof ar.correct === 'number') {
          document.querySelector(`input[name="ar_correct"][value="${ar.correct}"]`).checked = true;
        }
      }
    } else if (['VAS', 'Paragraph', 'Essay'].includes(question.question_type)) {
      document.getElementById('answerKey').value = question.answer_key || '';
    }

    // Image preview
    if (question.image_url) {
      document.getElementById('previewImg').src = '<?php echo getBaseUrl(); ?>/' + question.image_url;
      document.getElementById('imagePreview').classList.remove('hidden');
    }

    // Formula preview
    if (question.has_formula && question.formula_latex) {
      document.getElementById('formulaLatex').value = question.formula_latex;
      document.getElementById('formulaPreview').textContent = question.formula_latex;
      document.getElementById('formulaPreview').classList.remove('hidden');
    }
  } else {
    title.textContent = 'Add Question';
    onQuestionTypeChange(); // Show default (MCQ)
  }

  modal.classList.remove('hidden');
  modal.classList.add('flex');
  lucide.createIcons();
}

function closeQuestionModal() {
  const modal = document.getElementById('questionModal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

// Close modal on backdrop click
document.getElementById('questionModal').addEventListener('click', function(e) {
  if (e.target === this) closeQuestionModal();
});
document.getElementById('viewQuestionModal').addEventListener('click', function(e) {
  if (e.target === this) closeViewQuestion();
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') { closeQuestionModal(); closeViewQuestion(); }
});

lucide.createIcons();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>