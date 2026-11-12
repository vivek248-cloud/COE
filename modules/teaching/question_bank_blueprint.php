<?php
/**
 * Page 3: Question Bank Blueprint (Read-Only)
 * Holy Cross College (Autonomous) - COE Question Paper System
 * Live compiled blueprint matrix matching sample-blueprint.jpeg
 * Supports: question_bank, master_blueprint, final_blueprint item types
 */
declare(strict_types=1);
define('PAGE_TITLE', 'Question Bank Blueprint');
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

// Page 3 opens in course-list mode when no course is selected.
$requestedCourse = strtoupper(trim((string)($_GET['course_code'] ?? $_GET['paper_code'] ?? '')));
$allowedItemTypes = ['question_bank', 'master_blueprint', 'final_blueprint'];
$requestedItemType = (string)($_GET['item_type'] ?? 'question_bank');
if (!in_array($requestedItemType, $allowedItemTypes, true)) {
    http_response_code(400);
    exit('Invalid blueprint type.');
}
if ($requestedCourse === '' && empty($_GET['submission_id'])) {
    $staffCode = trim((string)($user['staff_code'] ?? ''));
    $listAcademicYear = qps_setting($pdo, 'default_academic_year', DEFAULT_ACADEMIC_YEAR);
    $listRegulation = qps_setting($pdo, 'default_regulation', DEFAULT_REGULATION);
    try {
        $listStmt = $pdo->prepare("
            SELECT
                c.coursecode AS course_code,
                c.coursetitle AS course_title,
                COUNT(qd.id) AS total_questions,
                MAX(qd.updated_at) AS last_updated
            FROM timetablefaculty tf
            INNER JOIN courses c
                ON UPPER(c.coursecode) = UPPER(tf.papercode)
            INNER JOIN questions_draft qd
                ON UPPER(qd.course_code) = UPPER(c.coursecode)
               AND qd.staff_id = ?
               AND qd.is_active = 1
               AND qd.academic_year = ?
               AND qd.regulation = ?
            WHERE UPPER(tf.fid) = UPPER(?)
            GROUP BY c.coursecode, c.coursetitle
            HAVING COUNT(qd.id) > 0
            ORDER BY c.coursecode
        ");
        $listStmt->execute([$staffCode, $listAcademicYear, $listRegulation, $staffCode]);
        $blueprintCourses = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[Teaching Master Blueprint] Course list: ' . $e->getMessage());
        $blueprintCourses = [];
        $listError = true;
    }

    require_once __DIR__ . '/../../includes/header.php';
    require_once __DIR__ . '/../../includes/navbar.php';
    require_once __DIR__ . '/../../includes/sidebar.php';
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ?>
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
          <p class="text-xs font-bold uppercase tracking-wider text-indigo-600">Teaching Staff / Page 3</p>
          <h1 class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">Master Blueprint</h1>
          <p class="text-sm text-slate-500 mt-1">Live compiled blueprint generated from your active draft questions.</p>
        </div>
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/dashboard.php"
           class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
          <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Courses
        </a>
      </div>
      <?php if (!empty($listError)): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">Unable to load blueprint courses. Please try again later.</div>
      <?php elseif (!$blueprintCourses): ?>
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
          <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100"><i data-lucide="book-open" class="w-6 h-6 text-slate-500"></i></div>
          <h2 class="font-bold text-slate-900">No blueprint courses yet</h2>
          <p class="mt-1 text-sm text-slate-500">Add active draft questions to one of your allocated courses to see its blueprint here.</p>
        </div>
      <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs text-slate-500">Courses with blueprint</p><p class="mt-1 text-2xl font-black text-slate-900"><?php echo count($blueprintCourses); ?></p></div>
          <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs text-slate-500">Total active questions</p><p class="mt-1 text-2xl font-black text-slate-900"><?php echo number_format(array_sum(array_map(static fn($r) => (int)$r['total_questions'], $blueprintCourses))); ?></p></div>
          <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs text-slate-500">Blueprint mode</p><p class="mt-1 text-lg font-bold text-emerald-700">Live · Read-only</p></div>
        </div>
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-bold text-slate-900">Your allocated courses</h2><p class="text-xs text-slate-500 mt-1">Select a course code to compile its blueprint.</p></div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3 text-left">Course Code</th><th class="px-5 py-3 text-left">Course Name</th><th class="px-5 py-3 text-right">Questions</th><th class="px-5 py-3 text-left">Last Updated</th><th class="px-5 py-3 text-right">Action</th></tr></thead>
              <tbody class="divide-y divide-slate-100">
              <?php foreach ($blueprintCourses as $courseRow): ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-5 py-4 font-bold text-indigo-700 whitespace-nowrap"><a class="hover:underline" href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo rawurlencode((string)$courseRow['course_code']); ?>&item_type=question_bank"><?php echo $e($courseRow['course_code']); ?></a></td>
                  <td class="px-5 py-4 text-slate-700"><?php echo $e($courseRow['course_title']); ?></td>
                  <td class="px-5 py-4 text-right font-bold tabular-nums"><?php echo number_format((int)$courseRow['total_questions']); ?></td>
                  <td class="px-5 py-4 text-slate-500 whitespace-nowrap"><?php echo $courseRow['last_updated'] ? $e(date('d M Y, h:i A', strtotime((string)$courseRow['last_updated']))) : '—'; ?></td>
                  <td class="px-5 py-4 text-right"><a class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700" href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo rawurlencode((string)$courseRow['course_code']); ?>&item_type=question_bank">View Blueprint <i data-lucide="arrow-up-right" class="w-3 h-3"></i></a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </main>
    <?php
    exit;
}

// Get parameters
$courseCode = $requestedCourse;

qps_ensure_aux_schema($pdo);

// Get parameters
$courseCode = strtoupper(trim((string)($_GET['course_code'] ?? '')));
$itemType = $_GET['item_type'] ?? 'question_bank'; // question_bank, master_blueprint, final_blueprint
$submissionId = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : null;

// Get academic context
$academicYear = qps_setting($pdo, 'default_academic_year', DEFAULT_ACADEMIC_YEAR);
$regulation = qps_setting($pdo, 'default_regulation', DEFAULT_REGULATION);

// Fetch exam type and paper type for this course
$examType = 'Odd Semester End Examination';
$paperType = 'Theory';
$courseTitle = '';
$deptCode = '';
try {
    $stmt = $pdo->prepare("
        SELECT qpattern, type as course_type, coursetitle, dept_code
        FROM courses
        WHERE UPPER(coursecode) = UPPER(?)
    ");
    $stmt->execute([$courseCode]);
    $course = $stmt->fetch();
    if ($course) {
        $paperType = hcc_paper_types()[$course['qpattern']] ?? $course['qpattern'];
        $examType = hcc_exam_types()[$course['course_type']] ?? 'Odd Semester End Examination';
        $courseTitle = $course['coursetitle'];
        $deptCode = $course['dept_code'];
    }
} catch (Throwable $e) {
    error_log('[Question Bank Blueprint] Error fetching course info: ' . $e->getMessage());
}

// If submission_id provided, get details from course_submissions
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
            $courseCode = $submission['course_code'];
            $academicYear = $submission['academic_year'];
            $examType = $submission['exam_type'];
            $paperType = $submission['paper_type'];
            $courseTitle = $submission['course_title'];
            $deptCode = $submission['dept_code'];
        }
    } catch (Throwable $e) {
        error_log('[Question Bank Blueprint] Error fetching submission: ' . $e->getMessage());
    }
}

// Validate access - faculty can only view their own courses
$hasAccess = false;
try {
    $stmt = $pdo->prepare("
        SELECT 1 FROM timetablefaculty
        WHERE UPPER(fid) = UPPER(?) AND UPPER(papercode) = UPPER(?)
    ");
    $stmt->execute([$user['staff_code'], $courseCode]);
    $hasAccess = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {}

if (!$courseCode || !$hasAccess) {
    http_response_code(403);
    exit('Access denied: You are not allocated to this course.');
}

// Fetch questions based on item type
$questions = [];
$status = 'draft';

if ($itemType === 'question_bank') {
    // Fetch from questions_draft table
    try {
        $stmt = $pdo->prepare("
            SELECT qd.*
            FROM questions_draft qd
            WHERE qd.staff_id = ?
              AND qd.course_code = ?
              AND qd.regulation = ?
              AND qd.academic_year = ?
              AND qd.exam_type = ?
              AND qd.paper_type = ?
              AND qd.is_active = 1
            ORDER BY qd.unit_number, qd.sub_unit_number, qd.question_number, qd.id
        ");
        $stmt->execute([
            $user['staff_code'],
            $courseCode,
            $regulation,
            $academicYear,
            $examType,
            $paperType
        ]);
        $questions = $stmt->fetchAll();
        $status = 'draft';
    } catch (Throwable $e) {
        error_log('[Question Bank Blueprint] Error fetching QB questions: ' . $e->getMessage());
    }
} elseif ($itemType === 'master_blueprint') {
    // Fetch from master_blueprints table (HOD approved)
    try {
        $stmt = $pdo->prepare("
            SELECT mb.*
            FROM master_blueprints mb
            JOIN course_submissions cs ON mb.submission_id = cs.id
            WHERE cs.course_code = ? AND cs.staff_code = ?
              AND cs.regulation = ? AND cs.academic_year = ?
              AND cs.exam_type = ? AND cs.paper_type = ?
            ORDER BY mb.unit_no, mb.sub_unit, mb.marks, mb.k_level, mb.co_level
        ");
        $stmt->execute([$courseCode, $user['staff_code'], $regulation, $academicYear, $examType, $paperType]);
        $questions = $stmt->fetchAll();
        $status = 'approved';
    } catch (Throwable $e) {
        error_log('[Question Bank Blueprint] Error fetching MB questions: ' . $e->getMessage());
    }
} elseif ($itemType === 'final_blueprint') {
    // Fetch from final_blueprint_slots table (COE approved)
    try {
        $stmt = $pdo->prepare("
            SELECT fbs.*
            FROM final_blueprint_slots fbs
            JOIN course_submissions cs ON fbs.master_blueprint_id = cs.master_blueprint_id
            WHERE cs.course_code = ? AND cs.staff_code = ?
              AND cs.regulation = ? AND cs.academic_year = ?
              AND cs.exam_type = ? AND cs.paper_type = ?
            ORDER BY fbs.unit_number, fbs.sub_unit_number, fbs.marks, fbs.blooms_level, fbs.co_code
        ");
        $stmt->execute([$courseCode, $user['staff_code'], $regulation, $academicYear, $examType, $paperType]);
        $questions = $stmt->fetchAll();
        $status = 'approved';
    } catch (Throwable $e) {
        error_log('[Question Bank Blueprint] Error fetching FB questions: ' . $e->getMessage());
    }
}

// Build compiled matrix for main blueprint table
$compiledMatrix = [];
$totalPoolQuestions = count($questions);
$totalPoolMarks = 0;

if (!empty($questions)) {
    foreach ($questions as $qItem) {
        if ($itemType === 'question_bank') {
            $uNo = (int)($qItem['unit_number'] ?? 1);
            $sUnit = trim((string)($qItem['sub_unit_number'] ?? "$uNo.1"));
            $marks = (int)($qItem['marks'] ?? 1);
            $k = strtoupper((string)($qItem['blooms_level'] ?? 'K1'));
            $type = strtoupper((string)($qItem['question_type'] ?? 'MCQ'));
        } else {
            $uNo = (int)($qItem['unit_no'] ?? $qItem['unit_number'] ?? 1);
            $sUnit = trim((string)($qItem['sub_unit'] ?? $qItem['sub_unit_number'] ?? "$uNo.1"));
            $marks = (int)($qItem['marks'] ?? 1);
            $k = strtoupper((string)($qItem['k_level'] ?? $qItem['blooms_level'] ?? 'K1'));
            $type = strtoupper((string)($qItem['question_type'] ?? 'MCQ'));
        }
        if ($sUnit === '') $sUnit = "$uNo.1";
        $suKey = $sUnit;

        if (!isset($compiledMatrix[$suKey])) {
            $compiledMatrix[$suKey] = [
                'unit' => $uNo,
                'sub_unit' => $sUnit,
                'mcq_k1' => 0, 'mcq_k2' => 0,
                'vsa_k1' => 0, 'vsa_k2' => 0,
                'sec_b_k3' => 0, 'sec_b_k4' => 0,
                'sec_c_k5' => 0, 'sec_c_k6' => 0,
                'sec_d_k6' => 0,
                'total_questions' => 0,
                'total_marks' => 0
            ];
        }
    }

    uksort($compiledMatrix, function($a, $b) {
        $partA = explode('.', (string)$a);
        $partB = explode('.', (string)$b);
        $uA = (int)($partA[0] ?? 0);
        $uB = (int)($partB[0] ?? 0);
        if ($uA !== $uB) return $uA <=> $uB;
        $suA = (int)($partA[1] ?? 0);
        $suB = (int)($partB[1] ?? 0);
        return $suA <=> $suB;
    });

    foreach ($questions as $q) {
        if ($itemType === 'question_bank') {
            $uNo = (int)($q['unit_number'] ?? 1);
            $sUnit = trim((string)($q['sub_unit_number'] ?? "$uNo.1"));
            $m = (int)($q['marks'] ?? 1);
            $k = strtoupper((string)($q['blooms_level'] ?? 'K1'));
            $type = strtoupper((string)($q['question_type'] ?? 'MCQ'));
        } else {
            $uNo = (int)($q['unit_no'] ?? $q['unit_number'] ?? 1);
            $sUnit = trim((string)($q['sub_unit'] ?? $q['sub_unit_number'] ?? "$uNo.1"));
            $m = (int)($q['marks'] ?? 1);
            $k = strtoupper((string)($q['k_level'] ?? $q['blooms_level'] ?? 'K1'));
            $type = strtoupper((string)($q['question_type'] ?? 'MCQ'));
        }
        if ($sUnit === '') $sUnit = "$uNo.1";

        $totalPoolMarks += $m;

        if (isset($compiledMatrix[$sUnit])) {
            $compiledMatrix[$sUnit]['total_questions']++;
            $compiledMatrix[$sUnit]['total_marks'] += $m;

            // Map question types to sections based on marks and type
            if ($m === 1) {
                // 1 Mark questions - MC or VSA
                if (in_array($type, ['MCQ'], true)) {
                    if ($k === 'K2') $compiledMatrix[$sUnit]['mcq_k2']++;
                    else $compiledMatrix[$sUnit]['mcq_k1']++;
                } else {
                    // VAS, Match, etc. treated as VSA
                    if ($k === 'K2') $compiledMatrix[$sUnit]['vsa_k2']++;
                    else $compiledMatrix[$sUnit]['vsa_k1']++;
                }
            } elseif ($m === 5) {
                // 5 Marks - Section B
                if ($k === 'K4' || $k === 'K5' || $k === 'K6') $compiledMatrix[$sUnit]['sec_b_k4']++;
                else $compiledMatrix[$sUnit]['sec_b_k3']++;
            } elseif ($m === 10) {
                // 10 Marks - Section C or D
                if ($k === 'K6') $compiledMatrix[$sUnit]['sec_c_k6']++;
                else $compiledMatrix[$sUnit]['sec_c_k5']++;
            } elseif ($m >= 15) {
                // 15+ Marks - Section D (Long Answer/Essay)
                $compiledMatrix[$sUnit]['sec_d_k6']++;
            }
        }
    }
}

// Detailed live matrix
$detailedMatrix = [];
foreach ($questions as $q) {
    if ($itemType === 'question_bank') {
        $unit = (int)($q['unit_number'] ?? 0);
        $sub = trim((string)($q['sub_unit_number'] ?? ''));
        $marks = (int)($q['marks'] ?? 0);
        $k = strtoupper(trim((string)($q['blooms_level'] ?? '')));
        $co = strtoupper(trim((string)($q['co_code'] ?? '')));
    } else {
        $unit = (int)($q['unit_no'] ?? $q['unit_number'] ?? 0);
        $sub = trim((string)($q['sub_unit'] ?? $q['sub_unit_number'] ?? ''));
        $marks = (int)($q['marks'] ?? 0);
        $k = strtoupper(trim((string)($q['k_level'] ?? $q['blooms_level'] ?? '')));
        $co = strtoupper(trim((string)($q['co_level'] ?? $q['co_code'] ?? '')));
    }
    $key = $unit.'|'.$sub.'|'.$marks.'|'.$k.'|'.$co;
    if (!isset($detailedMatrix[$key])) {
        $detailedMatrix[$key] = ['unit' => $unit, 'sub_unit' => $sub, 'marks' => $marks, 'k_level' => $k, 'co_level' => $co, 'count' => 0];
    }
    $detailedMatrix[$key]['count']++;
}
usort($detailedMatrix, fn($a, $b) => [$a['unit'], $a['sub_unit'], $a['marks'], $a['k_level'], $a['co_level']] <=> [$b['unit'], $b['sub_unit'], $b['marks'], $b['k_level'], $b['co_level']]);

// Live per-section counts, calculated from the same active question rows.
$sectionCounts = ['1 Mark' => 0, '5 Marks' => 0, '10 Marks' => 0, 'Above 10 Marks' => 0, 'Other' => 0];
foreach ($questions as $sectionQuestion) {
    $sectionMarks = (int)($sectionQuestion['marks'] ?? 0);
    if ($sectionMarks === 1) $sectionCounts['1 Mark']++;
    elseif ($sectionMarks === 5) $sectionCounts['5 Marks']++;
    elseif ($sectionMarks === 10) $sectionCounts['10 Marks']++;
    elseif ($sectionMarks > 10) $sectionCounts['Above 10 Marks']++;
    else $sectionCounts['Other']++;
}


// Compute Column Grand Totals
$grand = [
    'mcq_k1' => 0, 'mcq_k2' => 0, 'mcq_tot' => 0,
    'vsa_k1' => 0, 'vsa_k2' => 0, 'vsa_tot' => 0,
    'sec_b_k3' => 0, 'sec_b_k4' => 0, 'sec_b_tot' => 0,
    'sec_c_k5' => 0, 'sec_c_k6' => 0, 'sec_c_tot' => 0,
    'sec_d_k6' => 0, 'sec_d_tot' => 0,
    'total_q' => 0, 'total_marks' => 0
];

foreach ($compiledMatrix as $r) {
    $mcTot = $r['mcq_k1'] + $r['mcq_k2'];
    $vsaTot = $r['vsa_k1'] + $r['vsa_k2'];
    $bTot = $r['sec_b_k3'] + $r['sec_b_k4'];
    $cTot = $r['sec_c_k5'] + $r['sec_c_k6'];
    $dTot = $r['sec_d_k6'];

    $grand['mcq_k1'] += $r['mcq_k1']; $grand['mcq_k2'] += $r['mcq_k2']; $grand['mcq_tot'] += $mcTot;
    $grand['vsa_k1'] += $r['vsa_k1']; $grand['vsa_k2'] += $r['vsa_k2']; $grand['vsa_tot'] += $vsaTot;
    $grand['sec_b_k3'] += $r['sec_b_k3']; $grand['sec_b_k4'] += $r['sec_b_k4']; $grand['sec_b_tot'] += $bTot;
    $grand['sec_c_k5'] += $r['sec_c_k5']; $grand['sec_c_k6'] += $r['sec_c_k6']; $grand['sec_c_tot'] += $cTot;
    $grand['sec_d_k6'] += $dTot; $grand['sec_d_tot'] += $dTot;
    $grand['total_q'] += $r['total_questions']; $grand['total_marks'] += $r['total_marks'];
}

// Group matrix rows by unit
$presentUnits = [];
foreach ($compiledMatrix as $r) {
    $u = (int)$r['unit'];
    $presentUnits[$u][] = $r;
}
ksort($presentUnits, SORT_NUMERIC);

foreach ($presentUnits as $u => &$rowsInUnit) {
    usort($rowsInUnit, function($a, $b) {
        $sa = (string)($a['sub_unit'] ?? '');
        $sb = (string)($b['sub_unit'] ?? '');
        return version_compare($sa, $sb);
    });
}
unset($rowsInUnit);

// Item type labels
$itemTypeLabels = [
    'question_bank' => 'Question Bank',
    'master_blueprint' => 'Master Blueprint',
    'final_blueprint' => 'Final Blueprint'
];

$itemTypeIcons = [
    'question_bank' => 'database',
    'master_blueprint' => 'layout-template',
    'final_blueprint' => 'file-check'
];

$itemTypeColors = [
    'question_bank' => 'orange',
    'master_blueprint' => 'purple',
    'final_blueprint' => 'emerald'
];

$color = $itemTypeColors[$itemType] ?? 'orange';
$icon = $itemTypeIcons[$itemType] ?? 'database';
$label = $itemTypeLabels[$itemType] ?? 'Question Bank';

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
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($courseCode); ?>" class="hover:text-slate-700">Question Bank Draft</a>
        <i data-lucide="chevron-right" class="w-3 h-3"></i>
        <span class="text-slate-900 font-bold"><?php echo $label; ?> Blueprint</span>
      </nav>
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900 flex items-center gap-2">
        <i data-lucide="<?php echo $icon; ?>" class="w-6 h-6 text-<?php echo $color; ?>-500"></i>
        <?php echo htmlspecialchars($courseCode); ?> — <?php echo $label; ?> Blueprint
      </h1>
      <p class="text-sm text-slate-500 mt-1">
        <?php echo htmlspecialchars($courseTitle); ?> | <?php echo htmlspecialchars($examType); ?> / <?php echo htmlspecialchars($paperType); ?>
        | <?php echo htmlspecialchars($academicYear); ?> / <?php echo htmlspecialchars($regulation); ?>
      </p>
    </div>
    <div class="flex gap-2 shrink-0">
      <a href="<?php echo getBaseUrl(); ?>/api/teaching_master_blueprint_docx.php?course_code=<?php echo urlencode($courseCode); ?>&item_type=<?php echo urlencode($itemType); ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition flex items-center gap-1.5">
        <i data-lucide="file-down" class="w-4 h-4"></i>
        <span>Download DOCX</span>
      </a>
      <button type="button" onclick="window.print()" class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-bold transition flex items-center gap-1.5">
        <i data-lucide="printer" class="w-4 h-4"></i>
        <span>Print</span>
      </button>
      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/dashboard.php" class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-bold transition">
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
    <span class="bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-700 border border-<?php echo $color; ?>-200 px-3 py-1 rounded-full text-xs font-bold">
      <i data-lucide="<?php echo $icon; ?>" class="w-3 h-3 inline mr-1"></i>
      <?php echo $label; ?> (Read-Only)
    </span>
    <?php if ($submissionId): ?>
      <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-full text-xs font-mono">
        Submission ID: #<?php echo $submissionId; ?>
      </span>
    <?php endif; ?>
  </div>

  <!-- Metric Badges -->
  <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
    <div class="card-modern p-3.5">
      <span class="text-[11px] font-bold text-slate-500 uppercase">Course</span>
      <div class="text-base font-black text-slate-900 mt-0.5 truncate"><?php echo htmlspecialchars($courseCode); ?></div>
      <div class="text-[11px] text-slate-500 truncate"><?php echo htmlspecialchars($courseTitle); ?></div>
    </div>
    <div class="card-modern p-3.5 bg-blue-50 border-blue-200">
      <span class="text-[11px] font-bold text-blue-700 uppercase">Total Questions</span>
      <div class="text-lg font-black text-blue-900 mt-0.5"><?php echo $totalPoolQuestions; ?></div>
    </div>
    <div class="card-modern p-3.5 bg-emerald-50 border-emerald-200">
      <span class="text-[11px] font-bold text-emerald-700 uppercase">Total Marks</span>
      <div class="text-lg font-black text-emerald-900 mt-0.5"><?php echo $totalPoolMarks; ?> M</div>
    </div>
    <div class="card-modern p-3.5 bg-purple-50 border-purple-200">
      <span class="text-[11px] font-bold text-purple-700 uppercase">Subunits</span>
      <div class="text-lg font-black text-purple-900 mt-0.5"><?php echo count($compiledMatrix); ?></div>
    </div>
  </div>

  <!-- ========================================================================= -->
  <!-- MAIN INSTITUTIONAL BLUEPRINT CARD (Matches sample-blueprint.jpeg)        -->
  <!-- ========================================================================= -->
  <div class="bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden" id="blueprint-printable-area">

    <!-- Institutional Document Header -->
    <div class="p-6 border-b border-slate-200 bg-slate-50/50 space-y-2">
      <div class="text-center space-y-1">
        <h3 class="text-base sm:text-lg font-black uppercase text-slate-900 font-serif tracking-wider">HOLY CROSS COLLEGE (AUTONOMOUS), TIRUCHIRAPPALLI</h3>
        <p class="text-xs font-bold text-slate-600 uppercase"><?php echo $label; ?> Blueprint (Live Compiled • <?php echo htmlspecialchars($academicYear); ?>)</p>
      </div>

      <!-- Metadata Strip -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-2 pt-2 text-xs font-bold text-slate-800 border-t border-slate-200">
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">DEPARTMENT:</span> <span class="font-black text-slate-900"><?php echo htmlspecialchars($deptCode ?: 'DEPARTMENT'); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">PAPER CODE:</span> <span class="font-black text-orange-600 font-mono"><?php echo htmlspecialchars($courseCode); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">TITLE OF THE PAPER:</span> <span class="font-black text-slate-900"><?php echo htmlspecialchars($courseTitle); ?></span></div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs font-bold text-slate-800">
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">EXAM TYPE:</span> <span class="font-black text-slate-900"><?php echo htmlspecialchars($examType); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">PAPER TYPE:</span> <span class="font-black text-slate-900"><?php echo htmlspecialchars($paperType); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">REGULATION:</span> <span class="font-black text-slate-900"><?php echo htmlspecialchars($regulation); ?></span></div>
      </div>
    </div>

    <!-- Live Blueprint Matrix Table Matching sample-blueprint.jpeg -->
    <div class="p-4 sm:p-6 space-y-4">
      <div class="overflow-x-auto rounded-2xl border border-slate-300 shadow-sm">
        <table class="w-full text-xs text-center border-collapse bg-white" id="qbbTableMain">

          <!-- Double Header Rows -->
          <thead>
            <!-- Group Header Row -->
            <tr class="bg-slate-100 border-b border-slate-300 font-bold text-slate-700">
              <th rowspan="2" class="p-2 border-r border-slate-300 w-16">Unit</th>
              <th rowspan="2" class="p-2 border-r border-slate-300 w-16">Sub Unit</th>
              <th colspan="3" class="p-2 border-r border-slate-300 bg-blue-50/70 text-blue-900">MC (1M)</th>
              <th colspan="3" class="p-2 border-r border-slate-300 bg-sky-50/70 text-sky-900">V.S.A (1M)</th>
              <th colspan="3" class="p-2 border-r border-slate-300 bg-amber-50/70 text-amber-900">Section B (5M)</th>
              <th colspan="3" class="p-2 border-r border-slate-300 bg-emerald-50/70 text-emerald-900">Section C (10M)</th>
              <th colspan="2" class="p-2 border-r border-slate-300 bg-purple-50/70 text-purple-900">Section D (10M+)</th>
              <th rowspan="2" class="p-2 border-r border-slate-300 bg-slate-200 text-slate-800">Total Qs</th>
              <th rowspan="2" class="p-2 bg-slate-200 text-slate-800 font-extrabold">Total Marks</th>
            </tr>
            <!-- Sub Column Header Row -->
            <tr class="bg-slate-50 border-b border-slate-300 font-bold text-[11px] text-slate-600">
              <!-- MC -->
              <th class="p-1.5 border-r border-slate-300 bg-blue-50/40">K1</th>
              <th class="p-1.5 border-r border-slate-300 bg-blue-50/40">K2</th>
              <th class="p-1.5 border-r border-slate-300 bg-blue-100/60 font-extrabold text-blue-900">Tot</th>
              <!-- VSA -->
              <th class="p-1.5 border-r border-slate-300 bg-sky-50/40">K1</th>
              <th class="p-1.5 border-r border-slate-300 bg-sky-50/40">K2</th>
              <th class="p-1.5 border-r border-slate-300 bg-sky-100/60 font-extrabold text-sky-900">Tot</th>
              <!-- Sec B -->
              <th class="p-1.5 border-r border-slate-300 bg-amber-50/40">K3</th>
              <th class="p-1.5 border-r border-slate-300 bg-amber-50/40">K4</th>
              <th class="p-1.5 border-r border-slate-300 bg-amber-100/60 font-extrabold text-amber-900">Tot</th>
              <!-- Sec C -->
              <th class="p-1.5 border-r border-slate-300 bg-emerald-50/40">K5</th>
              <th class="p-1.5 border-r border-slate-300 bg-emerald-50/40">K6</th>
              <th class="p-1.5 border-r border-slate-300 bg-emerald-100/60 font-extrabold text-emerald-900">Tot</th>
              <!-- Sec D -->
              <th class="p-1.5 border-r border-slate-300 bg-purple-50/40">K6</th>
              <th class="p-1.5 border-r border-slate-300 bg-purple-100/60 font-extrabold text-purple-900">Tot</th>
            </tr>
          </thead>

          <!-- Table Body with Dynamically Entered Units & Subunits ONLY -->
          <tbody class="divide-y divide-slate-200" id="qbbMatrixTbody">
            <?php if (empty($compiledMatrix)): ?>
              <tr>
                <td colspan="16" class="p-8 text-center text-slate-500 bg-slate-50/50">
                  <div class="space-y-2">
                    <i data-lucide="inbox" class="w-8 h-8 text-slate-400 mx-auto"></i>
                    <p class="font-semibold text-xs">No questions found for <strong><?php echo htmlspecialchars($courseCode); ?></strong> in <?php echo $label; ?>.</p>
                    <p class="text-[11px] text-slate-400">
                      <?php if ($itemType === 'question_bank'): ?>
                        Questions created in the Question Bank Draft will dynamically compile into this blueprint matrix.
                      <?php elseif ($itemType === 'master_blueprint'): ?>
                        Master Blueprint will appear after Question Bank is approved by HOD/Dean.
                      <?php else: ?>
                        Final Blueprint will appear after Master Blueprint is approved by COE.
                      <?php endif; ?>
                    </p>
                    <?php if ($itemType === 'question_bank'): ?>
                      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($courseCode); ?>" class="inline-flex items-center space-x-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-3.5 py-2 rounded-xl shadow transition">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                        <span>Enter Questions</span>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php
              $romanUnits = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'];
              foreach ($presentUnits as $u => $rowsInUnit):
                $unitCount = count($rowsInUnit);
                foreach ($rowsInUnit as $idx => $row):
                  $mcTot = (int)($row['mcq_k1'] + $row['mcq_k2']);
                  $vsaTot = (int)($row['vsa_k1'] + $row['vsa_k2']);
                  $bTot = (int)($row['sec_b_k3'] + $row['sec_b_k4']);
                  $cTot = (int)($row['sec_c_k5'] + $row['sec_c_k6']);
                  $dTot = (int)$row['sec_d_k6'];
              ?>
                <tr class="hover:bg-slate-50 transition <?php echo $idx === $unitCount - 1 ? 'border-b-2 border-slate-300' : 'border-b border-slate-200'; ?>">

                  <!-- Unit Rowspan Column -->
                  <?php if ($idx === 0): ?>
                    <td rowspan="<?php echo $unitCount; ?>" class="p-2 border-r border-slate-300 font-bold bg-slate-100 text-slate-800 align-middle">
                      Unit <?php echo $romanUnits[$u] ?? $u; ?>
                    </td>
                  <?php endif; ?>

                  <!-- Subunit Column -->
                  <td class="p-2 border-r border-slate-300 font-semibold text-slate-700 bg-slate-50/50">
                    <?php echo htmlspecialchars($row['sub_unit']); ?>
                  </td>

                  <!-- MC 1M -->
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['mcq_k1'] ? 'bg-blue-50 text-blue-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['mcq_k1']; ?></td>
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['mcq_k2'] ? 'bg-blue-50 text-blue-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['mcq_k2']; ?></td>
                  <td class="p-2 border-r border-slate-300 font-mono bg-blue-100/50 font-bold text-blue-900"><?php echo $mcTot; ?></td>

                  <!-- VSA 1M -->
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['vsa_k1'] ? 'bg-sky-50 text-sky-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['vsa_k1']; ?></td>
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['vsa_k2'] ? 'bg-sky-50 text-sky-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['vsa_k2']; ?></td>
                  <td class="p-2 border-r border-slate-300 font-mono bg-sky-100/50 font-bold text-sky-900"><?php echo $vsaTot; ?></td>

                  <!-- Sec B 5M -->
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['sec_b_k3'] ? 'bg-amber-50 text-amber-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['sec_b_k3']; ?></td>
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['sec_b_k4'] ? 'bg-amber-50 text-amber-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['sec_b_k4']; ?></td>
                  <td class="p-2 border-r border-slate-300 font-mono bg-amber-100/50 font-bold text-amber-900"><?php echo $bTot; ?></td>

                  <!-- Sec C 10M -->
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['sec_c_k5'] ? 'bg-emerald-50 text-emerald-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['sec_c_k5']; ?></td>
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['sec_c_k6'] ? 'bg-emerald-50 text-emerald-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['sec_c_k6']; ?></td>
                  <td class="p-2 border-r border-slate-300 font-mono bg-emerald-100/50 font-bold text-emerald-900"><?php echo $cTot; ?></td>

                  <!-- Sec D 10M+ -->
                  <td class="p-2 border-r border-slate-200 font-mono <?php echo $row['sec_d_k6'] ? 'bg-purple-50 text-purple-800 font-bold' : 'text-slate-400'; ?>"><?php echo $row['sec_d_k6']; ?></td>
                  <td class="p-2 border-r border-slate-300 font-mono bg-purple-100/50 font-bold text-purple-900"><?php echo $dTot; ?></td>

                  <!-- Totals -->
                  <td class="p-2 border-r border-slate-300 font-mono font-bold bg-slate-100 text-slate-800"><?php echo $row['total_questions']; ?></td>
                  <td class="p-2 font-mono font-extrabold bg-slate-100 text-indigo-900"><?php echo $row['total_marks']; ?>M</td>
                </tr>
              <?php endforeach; endforeach; ?>
            <?php endif; ?>
          </tbody>

          <!-- Totals Footer Row -->
          <?php if (!empty($compiledMatrix)): ?>
            <tfoot class="bg-slate-100 font-extrabold text-slate-900 border-t-2 border-slate-400">
              <tr>
                <td colspan="2" class="p-3 border-r border-slate-300 text-right uppercase tracking-wider font-extrabold text-slate-900 bg-slate-200">
                  Total Available
                </td>
                <td class="p-2 border-r border-slate-300 font-mono text-blue-900 bg-blue-100"><?php echo $grand['mcq_k1']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-blue-900 bg-blue-100"><?php echo $grand['mcq_k2']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-blue-950 font-black bg-blue-200"><?php echo $grand['mcq_tot']; ?></td>

                <td class="p-2 border-r border-slate-300 font-mono text-sky-900 bg-sky-100"><?php echo $grand['vsa_k1']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-sky-900 bg-sky-100"><?php echo $grand['vsa_k2']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-sky-950 font-black bg-sky-200"><?php echo $grand['vsa_tot']; ?></td>

                <td class="p-2 border-r border-slate-300 font-mono text-amber-900 bg-amber-100"><?php echo $grand['sec_b_k3']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-amber-900 bg-amber-100"><?php echo $grand['sec_b_k4']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-amber-950 font-black bg-amber-200"><?php echo $grand['sec_b_tot']; ?></td>

                <td class="p-2 border-r border-slate-300 font-mono text-emerald-900 bg-emerald-100"><?php echo $grand['sec_c_k5']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-emerald-900 bg-emerald-100"><?php echo $grand['sec_c_k6']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-emerald-950 font-black bg-emerald-200"><?php echo $grand['sec_c_tot']; ?></td>

                <td class="p-2 border-r border-slate-300 font-mono text-purple-900 bg-purple-100"><?php echo $grand['sec_d_k6']; ?></td>
                <td class="p-2 border-r border-slate-300 font-mono text-purple-950 font-black bg-purple-200"><?php echo $grand['sec_d_tot']; ?></td>

                <td class="p-2 border-r border-slate-300 font-mono font-black text-slate-900 bg-slate-300 text-sm"><?php echo $grand['total_q']; ?></td>
                <td class="p-2 font-mono font-black text-indigo-950 bg-indigo-200 text-sm"><?php echo $grand['total_marks']; ?>M</td>
              </tr>
            </tfoot>
          <?php endif; ?>

        </table>
      </div>

      <!-- Action Buttons Toolbar -->
      <div class="flex flex-wrap items-center justify-between gap-3 pt-3">
        <div class="flex items-center space-x-2">
          <a href="<?php echo getBaseUrl(); ?>/api/teaching_master_blueprint_docx.php?course_code=<?php echo urlencode($courseCode); ?>&item_type=<?php echo urlencode($itemType); ?>" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 text-white px-4 py-2.5 text-xs font-bold">
            <i data-lucide="file-down" class="w-4 h-4"></i> Download DOCX
          </a>
          <button type="button" onclick="window.print()" class="bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center space-x-1.5 shadow-sm">
            <i data-lucide="printer" class="w-4 h-4"></i>
            <span>Print Blueprint Matrix</span>
          </button>
        </div>

        <div class="flex items-center space-x-2">
          <?php if ($itemType === 'question_bank'): ?>
            <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($courseCode); ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-5 py-2.5 rounded-xl transition flex items-center space-x-1.5 shadow-md shadow-indigo-200">
              <i data-lucide="edit-3" class="w-4 h-4"></i>
              <span>Edit Questions &rarr;</span>
            </a>
          <?php elseif ($itemType === 'master_blueprint'): ?>
            <span class="bg-slate-100 text-slate-500 text-xs font-bold px-5 py-2.5 rounded-xl cursor-not-allowed flex items-center space-x-1.5">
              <i data-lucide="lock" class="w-4 h-4"></i>
              <span>HOD/Dean Approved</span>
            </span>
          <?php else: ?>
            <span class="bg-slate-100 text-slate-500 text-xs font-bold px-5 py-2.5 rounded-xl cursor-not-allowed flex items-center space-x-1.5">
              <i data-lucide="lock" class="w-4 h-4"></i>
              <span>COE Approved</span>
            </span>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

  <!-- Live per-section question count -->
  <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
    <div>
      <h3 class="font-black text-slate-900">Per-Section Question Count</h3>
      <p class="text-xs text-slate-500">Calculated live from active draft questions.</p>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
      <?php foreach ($sectionCounts as $sectionName => $sectionCount): ?>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
          <p class="text-xs font-semibold text-slate-500"><?php echo htmlspecialchars($sectionName); ?></p>
          <p class="mt-1 text-xl font-black <?php echo $sectionCount === 0 ? 'text-rose-600' : 'text-slate-900'; ?>"><?php echo (int)$sectionCount; ?></p>
          <?php if ($sectionCount === 0): ?><p class="text-[10px] font-semibold text-rose-500">No coverage</p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Detailed Question Bank Matrix -->
  <section class="mt-6 bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h3 class="font-black text-slate-900">Detailed Question Bank Matrix</h3>
        <p class="text-xs text-slate-500">Live counts grouped by Unit, Sub-unit, individual marks, K-Level and CO-Level.</p>
      </div>
      <span class="text-xs rounded-full bg-indigo-50 text-indigo-700 px-3 py-1 font-bold"><?php echo count($detailedMatrix); ?> combinations</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[650px] text-sm">
        <thead class="bg-slate-900 text-white">
          <tr>
            <th class="p-3 text-left">Unit</th>
            <th class="p-3 text-left">Sub-unit</th>
            <th class="p-3 text-left">Marks</th>
            <th class="p-3 text-left">K-Level</th>
            <th class="p-3 text-left">CO-Level</th>
            <th class="p-3 text-right">Questions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php foreach($detailedMatrix as $r): ?>
            <tr>
              <td class="p-3"><?php echo (int)$r['unit']; ?></td>
              <td class="p-3"><?php echo htmlspecialchars($r['sub_unit']); ?></td>
              <td class="p-3"><?php echo (int)$r['marks']; ?></td>
              <td class="p-3"><?php echo htmlspecialchars($r['k_level']); ?></td>
              <td class="p-3"><?php echo htmlspecialchars($r['co_level']); ?></td>
              <td class="p-3 text-right font-black"><?php echo (int)$r['count']; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$detailedMatrix): ?>
            <tr>
              <td colspan="6" class="p-8 text-center text-slate-500">No questions found for this course in <?php echo $label; ?>.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) {
    lucide.createIcons();
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>