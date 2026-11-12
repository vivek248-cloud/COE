<?php
/**
 * Page 2: Question Bank Entry
 * Holy Cross College (Autonomous) - COE Question Paper System
 * Universal form + course code cards with inline question management
 * Uses qps_question_bank_drafts / qps_question_bank_draft_questions tables
 */
declare(strict_types=1);
define('PAGE_TITLE', 'Question Bank Entry');
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
if (empty($_SESSION['qps_draft_csrf'])) {
    $_SESSION['qps_draft_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['qps_draft_csrf'];

// Get current academic context
$defaultAcademicYear = qps_setting($pdo, 'default_academic_year', DEFAULT_ACADEMIC_YEAR);
$defaultRegulation = qps_setting($pdo, 'default_regulation', DEFAULT_REGULATION);

// Handle success redirect from submit_to_hod
if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
    $message = 'Question Bank submitted to HOD for review successfully!';
    $messageType = 'success';
}

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
    error_log('[QB Entry] Course load failed: ' . $e->getMessage());
}

// Handle POST actions
$message = null;
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!isset($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
        $message = 'Invalid CSRF token';
        $messageType = 'error';
    } else {
        try {
            if ($action === 'submit_to_hod') {
                // Create submission package and transition to HOD
                $courseCode = strtoupper(trim((string)$_POST['course_code'] ?? ''));
                $semester = trim((string)$_POST['semester'] ?? '');
                $academicYear = trim((string)$_POST['academic_year'] ?? $defaultAcademicYear);
                $regulation = $defaultRegulation;

                if (!$courseCode || !$semester || !preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/', $academicYear)) {
                    throw new RuntimeException('Invalid course/semester/year context.');
                }

                // Verify allocation
                $check = $pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1');
                $check->execute([$user['staff_code'], $courseCode]);
                if (!$check->fetchColumn()) throw new RuntimeException('Not allocated to this course.');

                // Get course info
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

                // Get or create draft header
                $stmt = $pdo->prepare("
                    SELECT id FROM qps_question_bank_drafts
                    WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$user['staff_code'], $courseCode, $semester, $academicYear]);
                $draft = $stmt->fetch();

                if (!$draft) {
                    throw new RuntimeException('No draft found. Add questions first.');
                }

                // Verify questions exist
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM qps_question_bank_draft_questions
                    WHERE draft_id = ? AND staff_code = ?
                ");
                $stmt->execute([$draft['id'], $user['staff_code']]);
                $questionCount = (int)$stmt->fetchColumn();
                if ($questionCount === 0) throw new RuntimeException('No questions in draft. Add questions first.');

                // Create/update submission package
                $packageId = qps_create_or_update_package($pdo, [
                    'paper_code' => $courseCode,
                    'semester' => $semester,
                    'academic_year' => $academicYear,
                    'exam_type' => $examType,
                    'dept_code' => $deptCode,
                    'bank_draft_id' => (int)$draft['id'],
                    'bank_id' => null,
                    'qbb_id' => null,
                    'final_blueprint_id' => null,
                ]);

                // Update draft status
                $pdo->prepare("UPDATE qps_question_bank_drafts SET status = 'SUBMITTED_TO_HOD', submitted_at = NOW() WHERE id = ?")->execute([$draft['id']]);

                // Transition to HOD
                $result = qps_transition($pdo, $packageId, 'STAFF_SUBMIT', 'Question bank submitted for HOD review');

                $message = 'Question Bank submitted to HOD for review successfully!';
                $messageType = 'success';

                // Redirect to same page to prevent resubmission
                header("Location: " . getBaseUrl() . "/modules/teaching/question_bank_draft.php?course_code=" . urlencode($courseCode) . "&semester=" . urlencode($semester) . "&academic_year=" . urlencode($academicYear) . "&submitted=1");
                exit;

            } elseif ($action === 'save_question') {
                $questionId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
                $courseCode = strtoupper(trim((string)$_POST['course_code'] ?? ''));
                $semester = trim((string)$_POST['semester'] ?? '');
                $academicYear = trim((string)$_POST['academic_year'] ?? $defaultAcademicYear);
                $regulation = $defaultRegulation;

                if (!$courseCode || !$semester || !preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/', $academicYear)) {
                    throw new RuntimeException('Invalid course/semester/year context.');
                }

                // Verify allocation
                $check = $pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1');
                $check->execute([$user['staff_code'], $courseCode]);
                if (!$check->fetchColumn()) throw new RuntimeException('Not allocated to this course.');

                // Get course info for exam/paper type
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

                // Handle question type specific fields
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
                        $optVal = trim((string)($_POST["option_$i"] ?? ''));
                        if ($optVal !== '') $options[] = ['text' => $optVal];
                    }
                    $correctOption = isset($_POST['correct_option']) ? (int)$_POST['correct_option'] : 0;
                    $optionsJson = json_encode(['options' => $options, 'correct' => $correctOption]);
                } elseif ($questionType === 'Match') {
                    $colA = []; $colB = []; $answerKey = [];
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
                    $assertionText = trim((string)$_POST['assertion_text'] ?? '');
                    $reasonText = trim((string)$_POST['reason_text'] ?? '');
                    $correctOption = isset($_POST['ar_correct']) ? (int)$_POST['ar_correct'] : 0;
                    $optionsJson = json_encode(['correct' => $correctOption]);
                }

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

                // Get or create draft header
                $stmt = $pdo->prepare("
                    SELECT id FROM qps_question_bank_drafts
                    WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$user['staff_code'], $courseCode, $semester, $academicYear]);
                $draft = $stmt->fetch();

                if ($draft) {
                    $draftId = (int)$draft['id'];
                } else {
                    // Create new draft header
                    $stmt = $pdo->prepare("
                        INSERT INTO qps_question_bank_drafts (
                            staff_code, paper_code, semester, academic_year, regulation,
                            exam_type, course_type, dept_code, max_marks,
                            total_questions, total_marks, status, version_no,
                            created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'DRAFT', 1, NOW(), NOW())
                    ");
                    $stmt->execute([$user['staff_code'], $courseCode, $semester, $academicYear, $regulation, $examType, $paperType, $deptCode, 75]);
                    $draftId = (int)$pdo->lastInsertId();
                }

                // Determine section type based on marks
                $marks = (int)$_POST['marks'];
                $sectionType = 'SECTION-A';
                if ($marks >= 15) $sectionType = 'SECTION-D';
                elseif ($marks === 10) $sectionType = 'SECTION-C';
                elseif ($marks === 5) $sectionType = 'SECTION-B';

                $data = [
                    'draft_id' => $draftId,
                    'staff_code' => $user['staff_code'],
                    'paper_code' => $courseCode,
                    'semester' => $semester,
                    'academic_year' => $academicYear,
                    'unit_no' => (int)$_POST['unit_number'],
                    'sub_unit' => trim((string)$_POST['sub_unit_number']),
                    'question_type' => $questionType,
                    'question_text' => trim((string)$_POST['question_text']),
                    'marks' => $marks,
                    'k_level' => trim((string)$_POST['blooms_level']),
                    'co_level' => trim((string)$_POST['co_code']),
                    'options_json' => $optionsJson,
                    'match_column_a' => $matchColumnA,
                    'match_column_b' => $matchColumnB,
                    'answer_key' => trim((string)($_POST['answer_key'] ?? '')),
                    'assertion_text' => $assertionText,
                    'reason_text' => $reasonText,
                    'image_url' => $imageUrl,
                    'has_formula' => $hasFormula,
                    'formula_latex' => $formulaLatex,
                    'section_type' => $sectionType,
                    'language' => 'EN',
                    'correction_status' => 'CLEAN',
                ];

                if ($questionId > 0) {
                    $owned = $pdo->prepare("SELECT id FROM qps_question_bank_draft_questions WHERE id = ? AND staff_code = ? AND draft_id = ? LIMIT 1");
                    $owned->execute([$questionId, $user['staff_code'], $draftId]);
                    if (!$owned->fetchColumn()) throw new RuntimeException('Question not found or no permission.');
                    $placeholders = []; $params = [];
                    foreach ($data as $k => $v) { $placeholders[] = "$k = ?"; $params[] = $v; }
                    $params[] = $questionId;
                    $pdo->prepare("UPDATE qps_question_bank_draft_questions SET " . implode(', ', $placeholders) . ", updated_at = NOW() WHERE id = ?")->execute($params);
                    $message = 'Question updated successfully';
                } else {
                    // Get next question number within unit
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(MAX(q_number), 0) + 1 as next_num
                        FROM qps_question_bank_draft_questions
                        WHERE draft_id = ? AND unit_no = ?
                    ");
                    $stmt->execute([$draftId, $data['unit_no']]);
                    $data['q_number'] = (int)$stmt->fetchColumn();

                    $stmt = $pdo->prepare("
                        INSERT INTO qps_question_bank_draft_questions (" . implode(', ', array_keys($data)) . ", created_at, updated_at)
                        VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ", NOW(), NOW())
                    ");
                    $stmt->execute(array_values($data));
                    $message = 'Question added successfully';
                }

                // Update draft totals
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt, SUM(marks) as marks_sum
                    FROM qps_question_bank_draft_questions
                    WHERE draft_id = ?
                ");
                $stmt->execute([$draftId]);
                $totals = $stmt->fetch();
                $pdo->prepare("
                    UPDATE qps_question_bank_drafts
                    SET total_questions = ?, total_marks = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$totals['cnt'] ?? 0, $totals['marks_sum'] ?? 0, $draftId]);

                $messageType = 'success';

            } elseif ($action === 'delete_question') {
                $questionId = (int)$_POST['question_id'];
                $courseCode = strtoupper(trim((string)$_POST['course_code'] ?? ''));
                $semester = trim((string)$_POST['semester'] ?? '');
                $academicYear = trim((string)$_POST['academic_year'] ?? $defaultAcademicYear);

                // Get draft id
                $stmt = $pdo->prepare("
                    SELECT id FROM qps_question_bank_drafts
                    WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$user['staff_code'], $courseCode, $semester, $academicYear]);
                $draft = $stmt->fetch();

                if (!$draft) throw new RuntimeException('Draft not found.');

                $stmt = $pdo->prepare("DELETE FROM qps_question_bank_draft_questions WHERE id = ? AND staff_code = ? AND draft_id = ?");
                $stmt->execute([$questionId, $user['staff_code'], $draft['id']]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Question not found or no permission.');

                // Update draft totals
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt, SUM(marks) as marks_sum
                    FROM qps_question_bank_draft_questions
                    WHERE draft_id = ?
                ");
                $stmt->execute([$draft['id']]);
                $totals = $stmt->fetch();
                $pdo->prepare("
                    UPDATE qps_question_bank_drafts
                    SET total_questions = ?, total_marks = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$totals['cnt'] ?? 0, $totals['marks_sum'] ?? 0, $draft['id']]);

                $message = 'Question deleted';
                $messageType = 'success';
            }
        } catch (Throwable $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
            error_log('[QB Entry] POST error: ' . $e->getMessage());
        }
    }
}

// Get filter params
$filterCourse = strtoupper(trim((string)($_GET['course_code'] ?? '')));
$filterSemester = trim((string)($_GET['semester'] ?? ''));
$filterYear = trim((string)($_GET['academic_year'] ?? $defaultAcademicYear));
$filterUnit = trim((string)($_GET['unit'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 20;

// Build course cards (grouped drafts from qps_question_bank_drafts)
$courseCards = [];
try {
    $where = ['qd.staff_code = ?'];
    $params = [$user['staff_code']];
    if ($filterCourse) { $where[] = 'UPPER(qd.paper_code) = UPPER(?)'; $params[] = $filterCourse; }
    if ($filterSemester) { $where[] = 'qd.semester = ?'; $params[] = $filterSemester; }
    if ($filterYear) { $where[] = 'qd.academic_year = ?'; $params[] = $filterYear; }

    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT qd.paper_code as course_code, qd.academic_year, qd.semester, qd.exam_type, qd.course_type as paper_type, qd.regulation,
               qd.total_questions, qd.total_marks,
               MAX(qd.updated_at) as last_updated,
               qd.id as draft_id, qd.status
        FROM qps_question_bank_drafts qd
        WHERE {$whereSql}
        GROUP BY qd.paper_code, qd.academic_year, qd.semester, qd.exam_type, qd.course_type, qd.regulation, qd.id, qd.status, qd.total_questions, qd.total_marks
        ORDER BY qd.paper_code, qd.academic_year DESC, qd.semester
    ");
    $stmt->execute($params);
    $courseCards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[QB Entry] Course cards load failed: ' . $e->getMessage());
}

// If a specific course is selected, load its questions
$questions = [];
$questionsTotal = 0;
$totalPages = 1;
$expandedCourseKey = null;
$currentDraft = null;
$currentDraftId = 0;

if ($filterCourse && $filterSemester && $filterYear) {
    $expandedCourseKey = $filterCourse . '|' . $filterSemester . '|' . $filterYear;

    try {
        // Get the draft header
        $stmt = $pdo->prepare("
            SELECT * FROM qps_question_bank_drafts
            WHERE staff_code = ? AND UPPER(paper_code) = UPPER(?) AND semester = ? AND academic_year = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$user['staff_code'], $filterCourse, $filterSemester, $filterYear]);
        $currentDraft = $stmt->fetch();
        $currentDraftId = $currentDraft ? (int)$currentDraft['id'] : 0;

        if ($currentDraftId > 0) {
            $where = ['draft_id = ?', 'staff_code = ?'];
            $params = [$currentDraftId, $user['staff_code']];

            if ($filterUnit !== '' && ctype_digit($filterUnit)) {
                $where[] = 'unit_no = ?'; $params[] = (int)$filterUnit;
            }
            if ($searchTerm !== '') {
                $where[] = '(question_text LIKE ? OR CAST(q_number AS CHAR) LIKE ? OR sub_unit LIKE ?)';
                $like = '%' . $searchTerm . '%';
                array_push($params, $like, $like, $like);
            }

            $whereSql = implode(' AND ', $where);
            $count = $pdo->prepare("SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE {$whereSql}");
            $count->execute($params);
            $questionsTotal = (int)$count->fetchColumn();
            $totalPages = max(1, (int)ceil($questionsTotal / $pageSize));
            $safePage = min($page, $totalPages);
            $offset = ($safePage - 1) * $pageSize;

            $stmt = $pdo->prepare("
                SELECT * FROM qps_question_bank_draft_questions
                WHERE {$whereSql}
                ORDER BY unit_no, sub_unit, q_number, id
                LIMIT {$pageSize} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('[QB Entry] Questions load failed: ' . $e->getMessage());
    }
}

// Get units for filter
$units = [];
if ($currentDraftId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT unit_no FROM qps_question_bank_draft_questions
            WHERE draft_id = ? ORDER BY unit_no
        ");
        $stmt->execute([$currentDraftId]);
        $units = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
}

// CO codes
$coCodes = [];
if ($filterCourse) {
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT co_code FROM course_outcomes WHERE UPPER(course_code) = UPPER(?)");
        $stmt->execute([$filterCourse]);
        $coCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
}
if (empty($coCodes)) {
    $coCodes = ['CO1','CO2','CO3','CO4','CO5'];
}

// Compute live blueprint matrix for the expanded course
$blueprintMatrix = [];
if (!empty($questions)) {
    foreach ($questions as $q) {
        $uNo = (int)($q['unit_no'] ?? 1);
        $sUnit = trim((string)($q['sub_unit'] ?? "$uNo.1"));
        if ($sUnit === '') $sUnit = "$uNo.1";
        $marks = (int)($q['marks'] ?? 1);
        $k = strtoupper((string)($q['k_level'] ?? 'K1'));
        $type = strtoupper((string)($q['question_type'] ?? 'MCQ'));
        $sec = strtoupper((string)($q['section_type'] ?? 'SECTION-A'));

        if (!isset($blueprintMatrix[$sUnit])) {
            $blueprintMatrix[$sUnit] = [
                'unit' => $uNo, 'sub_unit' => $sUnit,
                'mcq_k1' => 0, 'mcq_k2' => 0,
                'vsa_k1' => 0, 'vsa_k2' => 0,
                'sec_b_k3' => 0, 'sec_b_k4' => 0,
                'sec_c_k5' => 0, 'sec_c_k6' => 0,
                'sec_d_k6' => 0,
                'total_questions' => 0, 'total_marks' => 0
            ];
        }
        $blueprintMatrix[$sUnit]['total_questions']++;
        $blueprintMatrix[$sUnit]['total_marks'] += $marks;

        if ($sec === 'SECTION-A') {
            if ($type === 'VSA' || $type === 'SHORT') {
                if ($k === 'K2') $blueprintMatrix[$sUnit]['vsa_k2']++; else $blueprintMatrix[$sUnit]['vsa_k1']++;
            } else {
                if ($k === 'K2') $blueprintMatrix[$sUnit]['mcq_k2']++; else $blueprintMatrix[$sUnit]['mcq_k1']++;
            }
        } elseif ($sec === 'SECTION-B') {
            if ($k === 'K4' || $k === 'K5' || $k === 'K6') $blueprintMatrix[$sUnit]['sec_b_k4']++; else $blueprintMatrix[$sUnit]['sec_b_k3']++;
        } elseif ($sec === 'SECTION-C') {
            if ($k === 'K6') $blueprintMatrix[$sUnit]['sec_c_k6']++; else $blueprintMatrix[$sUnit]['sec_c_k5']++;
        } elseif ($sec === 'SECTION-D') {
            $blueprintMatrix[$sUnit]['sec_d_k6']++;
        }
    }
    uksort($blueprintMatrix, fn($a,$b) => version_compare($a,$b));
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
        <span class="text-slate-900 font-bold">Question Bank Entry</span>
      </nav>
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900">Question Bank Entry</h1>
      <p class="text-sm text-slate-500 mt-1">Universal form + course draft cards with live blueprint matrix</p>
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
  <!-- UNIVERSAL FORM (Top)                                                     -->
  <!-- ========================================================================= -->
  <div class="card-modern p-5 space-y-4">
    <h3 class="font-black text-slate-900 flex items-center gap-2">
      <i data-lucide="file-plus-2" class="w-5 h-5 text-indigo-600"></i>
      Create / Select Question Bank Draft Context
    </h3>
    <form method="GET" id="contextForm" class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Academic Year *</label>
        <input type="text" name="academic_year" id="academic_year" value="<?php echo htmlspecialchars($filterYear); ?>"
               placeholder="YYYY-YYYY" maxlength="9" pattern="(19|20)\d{2}-(19|20)\d{2}"
               class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
        <p class="text-[10px] text-slate-500 mt-1">Format: 2026-2027</p>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Semester *</label>
        <select name="semester" id="semester" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
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
        <select name="course_code" id="course_code" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
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
          <span>Load Draft</span>
        </button>
      </div>
    </form>
    <p class="text-xs text-slate-500">Select Academic Year, Semester, and Course to load or create a Question Bank Draft. The draft will be created on first question save.</p>
  </div>

  <!-- ========================================================================= -->
  <!-- COURSE CODE CARDS (Draft List)                                           -->
  <!-- ========================================================================= -->
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="font-black text-slate-900 flex items-center gap-2">
        <i data-lucide="folder-open" class="w-5 h-5 text-orange-600"></i>
        Question Bank Drafts (<?php echo count($courseCards); ?>)
      </h3>
      <?php if (count($courseCards) > 0): ?>
        <span class="text-xs text-slate-500 bg-slate-100 px-3 py-1 rounded-full font-bold">
          Click a card to expand questions →
        </span>
      <?php endif; ?>
    </div>

    <?php if (empty($courseCards)): ?>
      <div class="card-modern p-10 text-center text-slate-500">
        <i data-lucide="inbox" class="w-12 h-12 mx-auto text-slate-300 mb-3"></i>
        <p class="font-medium text-slate-700">No Question Bank Drafts found</p>
        <p class="text-sm mt-1">Use the form above to select a course context, then add your first question to create a draft.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="courseCardsGrid">
        <?php foreach ($courseCards as $card):
          $courseCode = $card['course_code'];
          $semester = $card['semester'];
          $academicYear = $card['academic_year'];
          $examType = $card['exam_type'];
          $paperType = $card['paper_type'];
          $regulation = $card['regulation'];
          $totalQ = (int)$card['total_questions'];
          $totalM = (int)$card['total_marks'];
          $lastUpdated = $card['last_updated'];
          $draftId = (int)$card['draft_id'];
          $draftStatus = $card['status'];
          $cardKey = $courseCode . '|' . $semester . '|' . $academicYear;
          $isExpanded = ($cardKey === $expandedCourseKey);
        ?>
          <div class="card-modern p-5 space-y-4 transition-all duration-200 <?php echo $isExpanded ? 'ring-2 ring-indigo-500 shadow-lg' : ''; ?>"
               data-course-key="<?php echo htmlspecialchars($cardKey); ?>">
            <!-- Card Header -->
            <div class="flex items-start justify-between">
              <div class="flex-1 min-w-0">
                <span class="bg-slate-100 text-slate-800 text-[11px] font-bold px-2.5 py-0.5 rounded-full font-mono">
                  <?php echo htmlspecialchars($courseCode); ?>
                </span>
                <?php
                  $courseTitle = '';
                  foreach ($allocatedCourses as $ac) { if ($ac['course_code'] === $courseCode) { $courseTitle = $ac['course_title']; break; } }
                ?>
                <h4 class="font-bold text-slate-900 text-sm mt-1 truncate"><?php echo htmlspecialchars($courseTitle ?: $courseCode); ?></h4>
                <div class="flex flex-wrap items-center gap-2 mt-2 text-xs text-slate-500">
                  <span class="bg-slate-100 px-2 py-0.5 rounded font-bold"><?php echo htmlspecialchars($semester); ?></span>
                  <span class="bg-slate-100 px-2 py-0.5 rounded font-bold"><?php echo htmlspecialchars($academicYear); ?></span>
                  <span class="bg-slate-100 px-2 py-0.5 rounded"><?php echo htmlspecialchars($examType); ?></span>
                  <span class="bg-slate-100 px-2 py-0.5 rounded"><?php echo htmlspecialchars($paperType); ?></span>
                  <span class="px-2 py-0.5 rounded text-[10px] font-bold
                    <?php
                      $statusClass = 'bg-slate-100 text-slate-600';
                      if ($draftStatus === 'DRAFT') $statusClass = 'bg-amber-50 text-amber-700 border border-amber-200';
                      elseif ($draftStatus === 'SUBMITTED_TO_HOD') $statusClass = 'bg-blue-50 text-blue-700 border border-blue-200';
                      elseif ($draftStatus === 'HOD_APPROVED') $statusClass = 'bg-emerald-50 text-emerald-700 border border-emerald-200';
                      echo $statusClass;
                    ?>">
                    <?php echo htmlspecialchars($draftStatus); ?>
                  </span>
                </div>
              </div>
              <div class="text-right text-xs text-slate-500 shrink-0">
                <div class="font-bold text-slate-700"><?php echo $totalQ; ?> Questions</div>
                <div><?php echo $totalM; ?> Total Marks</div>
                <div class="mt-1"><?php echo $lastUpdated ? date('d M Y, H:i', strtotime($lastUpdated)) : '—'; ?></div>
              </div>
            </div>

            <!-- Stats Row -->
            <div class="flex items-center gap-4 pt-2 border-t border-slate-100">
              <div class="flex-1 text-center p-2 bg-slate-50 rounded-lg">
                <div class="text-lg font-black text-indigo-600"><?php echo $totalQ; ?></div>
                <div class="text-[10px] text-slate-500">Questions</div>
              </div>
              <div class="flex-1 text-center p-2 bg-slate-50 rounded-lg">
                <div class="text-lg font-black text-emerald-600"><?php echo $totalM; ?></div>
                <div class="text-[10px] text-slate-500">Marks</div>
              </div>
              <div class="flex-1 text-center p-2 bg-slate-50 rounded-lg">
                <div class="text-lg font-black text-purple-600"><?php echo count(array_unique(array_column(
                  array_filter($questions, fn($q) =>
                    $q['paper_code'] === $courseCode &&
                    $q['semester'] === $semester &&
                    $q['academic_year'] === $academicYear
                  ), 'unit_no'
                ))); ?></div>
                <div class="text-[10px] text-slate-500">Units</div>
              </div>
            </div>

            <!-- Expand/Collapse Trigger -->
            <button type="button"
                    onclick="toggleCourseCard('<?php echo htmlspecialchars($cardKey, ENT_QUOTES); ?>')"
                    class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg text-sm font-bold transition"
                    aria-expanded="<?php echo $isExpanded ? 'true' : 'false'; ?>">
              <i data-lucide="<?php echo $isExpanded ? 'chevron-up' : 'chevron-down'; ?>" class="w-4 h-4"></i>
              <span><?php echo $isExpanded ? 'Collapse Questions' : 'Expand Questions'; ?></span>
              <span class="text-xs bg-indigo-100 px-2 py-0.5 rounded-full"><?php echo $totalQ; ?></span>
            </button>

            <!-- Expanded Questions Table (Hidden by default) -->
            <div id="questions_<?php echo htmlspecialchars(str_replace(['|',' '], '_', $cardKey)); ?>"
                 class="hidden mt-4 space-y-3"
                 data-expanded="<?php echo $isExpanded ? 'true' : 'false'; ?>">
              <?php if ($isExpanded): ?>
                <!-- Filters for this course -->
                <form method="GET" class="p-3 bg-slate-50 rounded-lg border border-slate-200 grid grid-cols-1 sm:grid-cols-4 gap-2">
                  <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                  <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                  <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academicYear); ?>">
                  <input type="search" name="q" value="<?php echo htmlspecialchars($searchTerm); ?>"
                         placeholder="Search question..." class="col-span-1 sm:col-span-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                  <select name="unit" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    <option value="">All Units</option>
                    <?php foreach ($units as $u): ?>
                      <option value="<?php echo $u; ?>" <?php echo $filterUnit == $u ? 'selected' : ''; ?>>Unit <?php echo $u; ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg px-3 py-2 text-sm font-bold">Filter</button>
                </form>

                <!-- Live Blueprint Matrix Summary -->
                <?php if (!empty($blueprintMatrix)): ?>
                  <div class="card-modern p-3 bg-emerald-50 border-emerald-200">
                    <div class="font-bold text-emerald-900 text-sm mb-2 flex items-center gap-2">
                      <i data-lucide="layout-template" class="w-4 h-4"></i>
                      Live Blueprint Matrix (auto-synced)
                    </div>
                    <div class="overflow-x-auto">
                      <table class="w-full text-xs text-center">
                        <thead class="bg-emerald-100 text-emerald-900">
                          <tr>
                            <th class="p-1.5 border">Sub-unit</th>
                            <th class="p-1.5 border" colspan="2">1 Mark</th>
                            <th class="p-1.5 border" colspan="2">5 Marks</th>
                            <th class="p-1.5 border" colspan="2">10 Marks</th>
                            <th class="p-1.5 border">10M+</th>
                            <th class="p-1.5 border">Total Q</th>
                            <th class="p-1.5 border">Total M</th>
                          </tr>
                          <tr class="bg-emerald-50 text-[10px]">
                            <th class="p-1 border"></th>
                            <th class="p-1 border">MCQ</th><th class="p-1 border">VSA</th>
                            <th class="p-1 border">K3</th><th class="p-1 border">K4</th>
                            <th class="p-1 border">K5</th><th class="p-1 border">K6</th>
                            <th class="p-1 border">K6</th>
                            <th class="p-1 border"></th><th class="p-1 border"></th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-emerald-200">
                          <?php foreach ($blueprintMatrix as $su => $row):
                            $mcq = $row['mcq_k1'] + $row['mcq_k2'];
                            $vsa = $row['vsa_k1'] + $row['vsa_k2'];
                            $b5 = $row['sec_b_k3'] + $row['sec_b_k4'];
                            $c10 = $row['sec_c_k5'] + $row['sec_c_k6'];
                          ?>
                            <tr class="hover:bg-emerald-50">
                              <td class="p-1.5 border font-mono text-emerald-800"><?php echo htmlspecialchars($su); ?></td>
                              <td class="p-1.5 border font-bold"><?php echo $mcq; ?></td>
                              <td class="p-1.5 border font-bold"><?php echo $vsa; ?></td>
                              <td class="p-1.5 border font-bold"><?php echo $row['sec_b_k3']; ?></td>
                              <td class="p-1.5 border font-bold"><?php echo $row['sec_b_k4']; ?></td>
                              <td class="p-1.5 border font-bold"><?php echo $row['sec_c_k5']; ?></td>
                              <td class="p-1.5 border font-bold"><?php echo $row['sec_c_k6']; ?></td>
                              <td class="p-1.5 border font-bold"><?php echo $row['sec_d_k6']; ?></td>
                              <td class="p-1.5 border font-black bg-emerald-100"><?php echo $row['total_questions']; ?></td>
                              <td class="p-1.5 border font-black bg-emerald-100"><?php echo $row['total_marks']; ?>M</td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                <?php endif; ?>

                <!-- Questions Table -->
                <div class="card-modern overflow-hidden">
                  <div class="p-4 border-b border-slate-200 flex items-center justify-between">
                    <h4 class="font-bold text-slate-900">Draft Questions (<?php echo $questionsTotal; ?>)</h4>
                    <button type="button" onclick="openQuestionModal(null, '<?php echo htmlspecialchars($courseCode); ?>', '<?php echo htmlspecialchars($semester); ?>', '<?php echo htmlspecialchars($academicYear); ?>')"
                            class="btn-orange-pill text-sm">
                      <i data-lucide="plus-circle" class="w-4 h-4"></i>
                      <span>Add Question</span>
                    </button>
                  </div>

                  <?php if (empty($questions)): ?>
                    <div class="p-10 text-center text-slate-500">
                      <i data-lucide="database" class="w-12 h-12 mx-auto text-slate-300 mb-3"></i>
                      <p class="font-medium">No questions in this draft yet</p>
                      <p class="text-xs mt-1">Click "Add Question" to start building your question bank</p>
                    </div>
                  <?php else: ?>
                    <div class="overflow-x-auto">
                      <table class="w-full min-w-[1100px] text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                          <tr>
                            <th class="p-3 text-left w-16">No.</th>
                            <th class="p-3 text-left w-28">Unit / Sub-unit</th>
                            <th class="p-3 text-left w-16">Marks</th>
                            <th class="p-3 text-left w-24">Type</th>
                            <th class="p-3 text-left w-20">K-Level</th>
                            <th class="p-3 text-left w-20">CO</th>
                            <th class="p-3 text-left">Question</th>
                            <th class="p-3 text-right w-44">Actions</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                          <?php foreach ($questions as $q): ?>
                            <tr class="hover:bg-slate-50">
                              <td class="p-3 font-bold text-slate-900">Q<?php echo (int)$q['q_number']; ?></td>
                              <td class="p-3">
                                <div>Unit <?php echo (int)$q['unit_no']; ?></div>
                                <?php if (!empty($q['sub_unit'])): ?>
                                  <span class="text-xs text-slate-500"><?php echo htmlspecialchars($q['sub_unit']); ?></span>
                                <?php endif; ?>
                              </td>
                              <td class="p-3 font-bold text-slate-700"><?php echo (int)$q['marks']; ?></td>
                              <td class="p-3"><span class="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($q['question_type']); ?></span></td>
                              <td class="p-3"><span class="bg-purple-50 text-purple-700 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($q['k_level']); ?></span></td>
                              <td class="p-3"><span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($q['co_level']); ?></span></td>
                              <td class="p-3 max-w-lg">
                                <div class="line-clamp-2 text-slate-800"><?php echo htmlspecialchars($q['question_text']); ?></div>
                                <?php if (!empty($q['image_url'])): ?>
                                  <img src="<?php echo getBaseUrl(); ?>/<?php echo htmlspecialchars($q['image_url']); ?>" alt="Question Image" class="max-h-24 rounded border mt-1">
                                <?php endif; ?>
                                <?php if (!empty($q['has_formula']) && !empty($q['formula_latex'])): ?>
                                  <div class="mt-1 text-xs text-blue-700 bg-blue-50 p-1 rounded font-mono"><?php echo htmlspecialchars($q['formula_latex']); ?></div>
                                <?php endif; ?>
                              </td>
                              <td class="p-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                  <button type="button" onclick='viewQuestion(<?php echo json_encode($q, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                          class="bg-slate-100 text-slate-700 hover:bg-slate-200 px-3 py-1.5 rounded text-xs font-bold">View</button>
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
                                    <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academicYear); ?>">
                                    <button type="submit" class="bg-rose-50 text-rose-700 hover:bg-rose-100 px-3 py-1.5 rounded text-xs font-bold transition">
                                      <i data-lucide="trash-2" class="w-3.5 h-3.5 inline mr-1"></i>
                                      Delete
                                    </button>
                                  </form>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                      <div class="p-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span class="text-slate-500">Showing <?php echo $questionsTotal ? (($page - 1) * $pageSize + 1) : 0; ?>–<?php echo min($page * $pageSize, $questionsTotal); ?> of <?php echo $questionsTotal; ?></span>
                        <div class="flex items-center gap-2">
                          <?php
                            $pgParams = $_GET; unset($pgParams['page']);
                            $pgBase = '?' . http_build_query($pgParams);
                          ?>
                          <a class="px-3 py-1.5 rounded border <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : ''; ?>" href="<?php echo htmlspecialchars($pgBase . '&page=' . max(1, $page - 1)); ?>">Previous</a>
                          <span class="font-bold text-slate-700">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
                          <a class="px-3 py-1.5 rounded border <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : ''; ?>" href="<?php echo htmlspecialchars($pgBase . '&page=' . min($totalPages, $page + 1)); ?>">Next</a>
                        </div>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>

                <!-- Submit to HOD Button (only when expanded and draft status is DRAFT) -->
                <?php if ($questionsTotal > 0 && $draftStatus === 'DRAFT'): ?>
                  <div class="pt-3 border-t border-slate-200 flex justify-end">
                    <form method="POST" action="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($courseCode); ?>&semester=<?php echo urlencode($semester); ?>&academic_year=<?php echo urlencode($academicYear); ?>"
                          onsubmit="return confirm('Submit this Question Bank to HOD for review?')">
                      <input type="hidden" name="action" value="submit_to_hod">
                      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                      <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                      <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                      <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academicYear); ?>">
                      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow transition flex items-center gap-2">
                        <i data-lucide="send" class="w-4 h-4"></i>
                        Submit to HOD
                      </button>
                    </form>
                  </div>
                <?php elseif ($draftStatus !== 'DRAFT'): ?>
                  <div class="pt-3 border-t border-slate-200 text-center text-sm text-slate-500">
                    Draft status: <strong><?php echo htmlspecialchars($draftStatus); ?></strong> — Cannot submit again.
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</main>

<!-- View Question Modal -->
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
    <form method="POST" id="questionForm" class="p-5 space-y-4" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_question">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="question_id" id="questionId" value="0">
      <input type="hidden" name="course_code" id="modalCourseCode" value="">
      <input type="hidden" name="semester" id="modalSemester" value="">
      <input type="hidden" name="academic_year" id="modalAcademicYear" value="">

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
            <p class="text-xs text-slate-500 mt-2"><i data-lucide="info" class="w-3 h-3 inline mr-1"></i> Column A has 4 items, Column B can have 5 items. Enter the Column B index (1-5) that matches each Column A item.</p>
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
                  <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="ar_correct" value="0" class="w-4 h-4 text-indigo-600" checked><span class="text-sm"><strong>A.</strong> Both A and R are true and R is the correct explanation of A</span></label>
                  <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="ar_correct" value="1" class="w-4 h-4 text-indigo-600"><span class="text-sm"><strong>B.</strong> Both A and R are true but R is NOT the correct explanation of A</span></label>
                  <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="ar_correct" value="2" class="w-4 h-4 text-indigo-600"><span class="text-sm"><strong>C.</strong> A is true but R is false</span></label>
                  <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="ar_correct" value="3" class="w-4 h-4 text-indigo-600"><span class="text-sm"><strong>D.</strong> A is false but R is true</span></label>
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
            <p class="text-xs text-slate-500 mt-1">Upload diagram, graph, or image (PNG, JPG, GIF)</p>
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
// Course card toggle
function toggleCourseCard(cardKey) {
  const content = document.getElementById('questions_' + cardKey.replace(/[| ]/g, '_'));
  const button = content?.previousElementSibling;
  const icon = button?.querySelector('i[data-lucide]');
  const span = button?.querySelector('span');

  if (!content) return;

  if (content.classList.contains('hidden')) {
    // Expand: navigate to URL with course context so server renders questions
    const [courseCode, semester, academicYear] = cardKey.split('|');
    const url = new URL(window.location.href);
    url.searchParams.set('course_code', courseCode);
    url.searchParams.set('semester', semester);
    url.searchParams.set('academic_year', academicYear);
    window.location.href = url.toString();
  } else {
    content.classList.add('hidden');
    content.dataset.expanded = 'false';
    if (icon) { icon.setAttribute('data-lucide', 'chevron-down'); }
    if (span) span.textContent = 'Expand Questions';
    lucide.createIcons();
  }
}

// Auto-expand if URL has course context
document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  if (params.get('course_code') && params.get('semester') && params.get('academic_year')) {
    const cardKey = params.get('course_code') + '|' + params.get('semester') + '|' + params.get('academic_year');
    const content = document.getElementById('questions_' + cardKey.replace(/[| ]/g, '_'));
    if (content) {
      content.classList.remove('hidden');
      content.dataset.expanded = 'true';
      const button = content.previousElementSibling;
      const icon = button?.querySelector('i[data-lucide]');
      const span = button?.querySelector('span');
      if (icon) { icon.setAttribute('data-lucide', 'chevron-up'); }
      if (span) span.textContent = 'Collapse Questions';
    }
  }
  lucide.createIcons();
});

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
      el.querySelectorAll('[required]').forEach(input => { input.required = true; input.disabled = false; });
    } else {
      el.classList.add('hidden');
      el.querySelectorAll('[required]').forEach(input => { input.required = false; input.disabled = true; });
    }
  });
  lucide.createIcons();
}

// Match table functions
let matchRowCounter = 4;
function addMatchRow() {
  const tbody = document.getElementById('matchBody');
  const hiddenRow = tbody.querySelector('tr[data-index="4"]');
  if (hiddenRow && hiddenRow.style.display === 'none') {
    hiddenRow.style.display = '';
    document.getElementById('matchCount').value = '5';
    return;
  }
  if (matchRowCounter >= 8) { alert('Maximum 8 rows allowed'); return; }
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
  if (index === 4) { row.style.display = 'none'; }
  else { row.remove(); }
  const rows = document.querySelectorAll('#matchBody tr:not([style*="display: none"])');
  rows.forEach((r, i) => {
    r.dataset.index = i;
    r.querySelector('td').textContent = i + 1;
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

// Formula preview
document.getElementById('formulaLatex').addEventListener('input', function(e) {
  const val = e.target.value;
  const preview = document.getElementById('formulaPreview');
  if (val.trim()) { preview.textContent = val; preview.classList.remove('hidden'); }
  else { preview.classList.add('hidden'); }
});

// Read-only preview
function viewQuestion(question) {
  const modal = document.getElementById('viewQuestionModal');
  document.getElementById('viewQuestionTitle').textContent = `Question Q${question.q_number || ''}`;
  const meta = document.getElementById('viewQuestionMeta');
  meta.replaceChildren();
  [['Unit', question.unit_no || '—'],['Sub-unit', question.sub_unit || '—'],['Marks', question.marks || '—'],['Type', question.question_type || '—'],['K-Level', question.k_level || '—'],['CO', question.co_level || '—'],['Status', question.correction_status || 'draft']].forEach(([label, value]) => {
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
  [['Answer key', question.answer_key],['Formula', question.formula_latex]].forEach(([label, value]) => {
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
function openQuestionModal(question = null, courseCode = '', semester = '', academicYear = '') {
  const modal = document.getElementById('questionModal');
  const form = document.getElementById('questionForm');
  const title = document.getElementById('modalTitle');

  form.reset();
  document.getElementById('questionId').value = '0';
  document.getElementById('imagePreview').classList.add('hidden');
  document.getElementById('formulaPreview').classList.add('hidden');

  // Reset match table
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
    } else { row.style.display = 'none'; }
  });
  document.getElementById('matchCount').value = '4';
  matchRowCounter = 3;

  document.querySelector('input[name="correct_option"][value="0"]').checked = true;
  document.querySelector('input[name="ar_correct"][value="0"]').checked = true;

  if (question) {
    title.textContent = 'Edit Question';
    document.getElementById('questionId').value = question.id;
    document.getElementById('unitNumber').value = question.unit_no;
    document.getElementById('subUnitNumber').value = question.sub_unit || '';
    document.getElementById('marks').value = question.marks;
    document.getElementById('bloomsLevel').value = question.k_level;
    document.getElementById('coCode').value = question.co_level;
    document.getElementById('questionType').value = question.question_type;
    document.getElementById('questionText').value = question.question_text;
    onQuestionTypeChange();

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
    if (question.image_url) {
      document.getElementById('previewImg').src = '<?php echo getBaseUrl(); ?>/' + question.image_url;
      document.getElementById('imagePreview').classList.remove('hidden');
    }
    if (question.has_formula && question.formula_latex) {
      document.getElementById('formulaLatex').value = question.formula_latex;
      document.getElementById('formulaPreview').textContent = question.formula_latex;
      document.getElementById('formulaPreview').classList.remove('hidden');
    }
  } else {
    title.textContent = 'Add Question';
    onQuestionTypeChange();
  }

  // Set context hidden fields
  document.getElementById('modalCourseCode').value = courseCode;
  document.getElementById('modalSemester').value = semester;
  document.getElementById('modalAcademicYear').value = academicYear;

  modal.classList.remove('hidden');
  modal.classList.add('flex');
  lucide.createIcons();
}
function closeQuestionModal() {
  const modal = document.getElementById('questionModal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

// Close on backdrop click
document.getElementById('questionModal').addEventListener('click', function(e) { if (e.target === this) closeQuestionModal(); });
document.getElementById('viewQuestionModal').addEventListener('click', function(e) { if (e.target === this) closeViewQuestion(); });

// Keyboard shortcuts
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeQuestionModal(); closeViewQuestion(); } });

lucide.createIcons();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>