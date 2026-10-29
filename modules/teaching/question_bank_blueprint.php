<?php
declare(strict_types=1);

define('PAGE_TITLE', 'Question Bank Blueprint');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/question_bank_blueprint.php';

requireAuth();

$pdo = getDBConnection();
$user = getCurrentUser();
qps_qbb_ensure_schema($pdo);
qps_ensure_question_draft_schema($pdo);

$baseUrl = getBaseUrl();
$ctx = qps_qbb_hod_context($pdo, $user);
$isCoe = isCOE();
$isHod = !empty($ctx['is_hod']) || isHOD();
$staffCode = (string)$ctx['staff_code'];
$deptCode = strtoupper((string)($ctx['dept_code'] ?: ($user['dept_code'] ?? '')));
$flashSuccess = '';
$flashError = '';

function qbb_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function qbb_redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// Fetch Courses available to this user (Department courses if HOD/COE, or allocated courses if Staff)
$availableCourses = [];
try {
    if ($isHod || $isCoe) {
        if ($deptCode !== '' && !$isCoe) {
            $stC = $pdo->prepare("SELECT coursecode, coursetitle, dept_code, level, maxmark FROM courses WHERE UPPER(dept_code) = UPPER(?) ORDER BY coursecode ASC");
            $stC->execute([$deptCode]);
            $availableCourses = $stC->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stC = $pdo->query("SELECT coursecode, coursetitle, dept_code, level, maxmark FROM courses ORDER BY coursecode ASC");
            $availableCourses = $stC->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Teaching staff: courses assigned to them
        $stC = $pdo->prepare("
            SELECT DISTINCT c.coursecode, c.coursetitle, c.dept_code, c.level, c.maxmark
            FROM timetablefaculty tf
            INNER JOIN courses c ON UPPER(c.coursecode) = UPPER(tf.papercode)
            WHERE UPPER(tf.fid) = UPPER(?)
            ORDER BY c.coursecode ASC
        ");
        $stC->execute([$staffCode]);
        $availableCourses = $stC->fetchAll(PDO::FETCH_ASSOC);

        if (empty($availableCourses) && $deptCode !== '') {
            $stC = $pdo->prepare("SELECT coursecode, coursetitle, dept_code, level, maxmark FROM courses WHERE UPPER(dept_code) = UPPER(?) ORDER BY coursecode ASC");
            $stC->execute([$deptCode]);
            $availableCourses = $stC->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {}

// Selected Course & Parameters
$selectedPaper = strtoupper(trim((string)($_GET['paper_code'] ?? ($_POST['paper_code'] ?? ''))));
if ($selectedPaper === '' && !empty($availableCourses)) {
    $selectedPaper = strtoupper((string)$availableCourses[0]['coursecode']);
}

$selectedYear = trim((string)($_GET['academic_year'] ?? ($_POST['academic_year'] ?? '2021-2022')));
$selectedSemester = trim((string)($_GET['semester'] ?? ($_POST['semester'] ?? 'Semester 1')));
$selectedExam = trim((string)($_GET['exam_type'] ?? ($_POST['exam_type'] ?? 'Autonomous End Semester Examination')));

$activeCourse = null;
foreach ($availableCourses as $c) {
    if (strtoupper($c['coursecode']) === $selectedPaper) {
        $activeCourse = $c;
        break;
    }
}
if (!$activeCourse && $selectedPaper !== '') {
    $activeCourse = qps_qbb_course($pdo, $deptCode, $selectedPaper);
}

// Handle Form Submissions (Save Draft, Publish, Toggle Enable)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (empty($_SESSION['qbb_csrf']) || !hash_equals((string)$_SESSION['qbb_csrf'], $csrf)) {
            throw new RuntimeException('Security token expired. Please refresh the page and try again.');
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'save') {
            $paperCode = strtoupper(trim((string)($_POST['paper_code'] ?? '')));
            if ($paperCode === '') throw new RuntimeException('Please select a course before saving the blueprint.');

            $course = qps_qbb_course($pdo, $deptCode, $paperCode);
            $courseTitle = trim((string)($course['coursetitle'] ?? $paperCode));
            $semester = trim((string)($_POST['semester'] ?? $selectedSemester));
            $academicYear = trim((string)($_POST['academic_year'] ?? $selectedYear));
            $examType = trim((string)($_POST['exam_type'] ?? $selectedExam));

            $matrixInput = json_decode((string)($_POST['matrix_json'] ?? '{}'), true);
            if (!is_array($matrixInput)) {
                $matrixInput = [];
            }
            $subunits = qps_qbb_get_course_subunits($pdo, $paperCode);
            $normalized = qps_qbb_normalize_grid($matrixInput, $subunits);
            $grandTotal = (int)($normalized['totals']['grand_total'] ?? 275);
            $matrixJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $targetDept = $course['dept_code'] ?? ($deptCode ?: 'COE');

            $pdo->beginTransaction();

            if ($id > 0) {
                $st = $pdo->prepare(
                    "UPDATE question_bank_blueprints
                     SET paper_code=?, course_title=?, semester=?, academic_year=?, exam_type=?,
                         total_questions=?, matrix_json=?, status='DRAFT',
                         published_by=NULL, published_at=NULL, updated_at=CURRENT_TIMESTAMP
                     WHERE id=?"
                );
                $st->execute([$paperCode, $courseTitle, $semester, $academicYear, $examType, $grandTotal, $matrixJson, $id]);
                $savedId = $id;
            } else {
                $st = $pdo->prepare(
                    "INSERT INTO question_bank_blueprints
                     (department_code, paper_code, course_title, semester, academic_year, exam_type,
                      total_questions, matrix_json, status, qbb_enabled, created_by, created_by_name)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', 0, ?, ?)"
                );
                $st->execute([
                    $targetDept, $paperCode, $courseTitle, $semester, $academicYear, $examType,
                    $grandTotal, $matrixJson, $staffCode, $user['name'] ?? $staffCode
                ]);
                $savedId = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
            qbb_redirect($baseUrl . "/modules/teaching/question_bank_blueprint.php?paper_code=" . urlencode($paperCode) . "&saved=1");
        }

        if ($action === 'publish') {
            if (!$isHod && !$isCoe) {
                throw new RuntimeException('Only the Head of Department (HOD) or COE can officially publish and lock a Question Bank Blueprint.');
            }

            $paperCode = strtoupper(trim((string)($_POST['paper_code'] ?? '')));
            if ($paperCode === '') throw new RuntimeException('Please select a course before publishing the blueprint.');

            $course = qps_qbb_course($pdo, $deptCode, $paperCode);
            $courseTitle = trim((string)($course['coursetitle'] ?? $paperCode));
            $semester = trim((string)($_POST['semester'] ?? $selectedSemester));
            $academicYear = trim((string)($_POST['academic_year'] ?? $selectedYear));
            $examType = trim((string)($_POST['exam_type'] ?? $selectedExam));

            $matrixInput = json_decode((string)($_POST['matrix_json'] ?? '{}'), true);
            if (!is_array($matrixInput)) {
                $matrixInput = [];
            }
            $subunits = qps_qbb_get_course_subunits($pdo, $paperCode);
            $normalized = qps_qbb_normalize_grid($matrixInput, $subunits);
            $grandTotal = (int)($normalized['totals']['grand_total'] ?? 275);
            $matrixJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $targetDept = $course['dept_code'] ?? ($deptCode ?: 'COE');

            $pdo->beginTransaction();

            if ($id <= 0) {
                // Check if a record already exists for this course and year
                $stFind = $pdo->prepare("SELECT id FROM question_bank_blueprints WHERE UPPER(paper_code)=UPPER(?) AND academic_year=? LIMIT 1");
                $stFind->execute([$paperCode, $academicYear]);
                $foundId = (int)$stFind->fetchColumn();
                if ($foundId > 0) {
                    $id = $foundId;
                }
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    "UPDATE question_bank_blueprints
                     SET paper_code=?, course_title=?, semester=?, academic_year=?, exam_type=?,
                         total_questions=?, matrix_json=?, status='PUBLISHED', qbb_enabled=1,
                         published_by=?, published_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                     WHERE id=?"
                );
                $st->execute([$paperCode, $courseTitle, $semester, $academicYear, $examType, $grandTotal, $matrixJson, $staffCode, $id]);
            } else {
                $st = $pdo->prepare(
                    "INSERT INTO question_bank_blueprints
                     (department_code, paper_code, course_title, semester, academic_year, exam_type,
                      total_questions, matrix_json, status, qbb_enabled, created_by, created_by_name, published_by, published_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PUBLISHED', 1, ?, ?, ?, CURRENT_TIMESTAMP)"
                );
                $st->execute([
                    $targetDept, $paperCode, $courseTitle, $semester, $academicYear, $examType,
                    $grandTotal, $matrixJson, $staffCode, $user['name'] ?? $staffCode, $staffCode
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            // Archive any prior published blueprint for this same course & year
            $stArch = $pdo->prepare("UPDATE question_bank_blueprints SET status = 'ARCHIVED' WHERE id != ? AND UPPER(paper_code) = UPPER(?) AND academic_year = ? AND status = 'PUBLISHED'");
            $stArch->execute([$id, $paperCode, $academicYear]);

            $pdo->commit();
            qbb_redirect($baseUrl . "/modules/teaching/question_bank_blueprint.php?paper_code=" . urlencode($paperCode) . "&published=1");
        }

        if ($action === 'toggle_use') {
            if ($id <= 0) throw new RuntimeException('Blueprint ID is required.');
            $enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;
            $st = $pdo->prepare("UPDATE question_bank_blueprints SET qbb_enabled = ? WHERE id = ?");
            $st->execute([$enabled, $id]);
            qbb_redirect($baseUrl . "/modules/teaching/question_bank_blueprint.php?paper_code=" . urlencode($selectedPaper) . "&toggled=1");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flashError = $e->getMessage();
    }
}

// Generate CSRF Token
if (empty($_SESSION['qbb_csrf'])) {
    $_SESSION['qbb_csrf'] = bin2hex(random_bytes(32));
}

// Load Existing Blueprint for Selected Course
$existingBlueprint = null;
try {
    if ($selectedPaper !== '') {
        $stBp = $pdo->prepare("SELECT * FROM question_bank_blueprints WHERE UPPER(paper_code) = UPPER(?) ORDER BY (status = 'PUBLISHED') DESC, id DESC LIMIT 1");
        $stBp->execute([$selectedPaper]);
        $existingBlueprint = $stBp->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Dynamic Matrix Construction: Loads Units, Subunits & Cell Counts without Fail and Mismatches
$colDefs = qps_qbb_column_definitions();
$initialMatrix = qps_qbb_build_grid_for_course($pdo, $selectedPaper, $selectedYear, $selectedSemester, $selectedExam);

// Question Pool Stats for Selected Course
$poolStats = ['found' => false, 'total' => 0, 'units' => [], 'sub_units' => [], 'grid_counts' => []];
if ($selectedPaper !== '') {
    $poolStats = qps_qbb_pool_stats($pdo, $selectedPaper, $selectedSemester, $selectedYear, $selectedExam, true);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="flex-1 max-w-[1440px] w-full mx-auto px-3 sm:px-6 py-6 space-y-6">
  
  <!-- Alert Messages -->
  <?php if (isset($_GET['saved'])): ?>
    <div class="rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 p-4 flex items-center justify-between shadow-sm animate-fade-in">
      <div class="flex items-center space-x-3">
        <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0"></i>
        <div class="text-xs font-bold">Question Bank Blueprint matrix draft saved successfully for <strong><?php echo qbb_h($selectedPaper); ?></strong>.</div>
      </div>
      <button type="button" onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['published'])): ?>
    <div class="rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-900 p-4 flex items-center justify-between shadow-sm animate-fade-in">
      <div class="flex items-center space-x-3">
        <i data-lucide="shield-check" class="w-5 h-5 text-indigo-600 shrink-0"></i>
        <div class="text-xs font-bold">Blueprint officially <strong>Published & Activated</strong>! Faculty question bank uploads will strictly conform to this target distribution.</div>
      </div>
      <button type="button" onclick="this.parentElement.remove()" class="text-indigo-700 hover:text-indigo-900"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
  <?php endif; ?>

  <?php if ($flashError): ?>
    <div class="rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 p-4 flex items-center justify-between shadow-sm">
      <div class="flex items-center space-x-3">
        <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-600 shrink-0"></i>
        <div class="text-xs font-bold"><?php echo qbb_h($flashError); ?></div>
      </div>
      <button type="button" onclick="this.parentElement.remove()" class="text-rose-700 hover:text-rose-900"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
  <?php endif; ?>

  <!-- Top Hero & Context Bar -->
  <div class="bg-gradient-to-r from-slate-900 via-slate-950 to-slate-900 text-white rounded-3xl p-5 sm:p-6 shadow-xl border border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div class="space-y-1">
      <div class="flex items-center space-x-2">
        <span class="bg-orange-500/20 text-orange-400 text-[10px] font-black uppercase px-2.5 py-0.5 rounded-full border border-orange-500/30">Official OBE Format</span>
        <span class="text-slate-400 text-xs">•</span>
        <span class="text-slate-300 text-xs font-semibold">Holy Cross College (Autonomous)</span>
      </div>
      <h2 class="text-lg sm:text-xl font-black text-white font-serif tracking-tight flex items-center space-x-2">
        <i data-lucide="layout-template" class="w-6 h-6 text-orange-400 shrink-0"></i>
        <span>Course Question Bank Blueprint (QBB)</span>
      </h2>
      <p class="text-xs text-slate-400 max-w-2xl">
        Fill and manage the exact question distribution matrix (Units 1.1 - 5.5, 14 OBE categories, 275 items). Staff and HODs define the blueprint to validate incoming question banks and power automated question selection.
      </p>
    </div>

    <!-- Quick Status Strip -->
    <div class="flex items-center gap-2 bg-slate-900/90 border border-slate-800 p-3 rounded-2xl shrink-0">
      <div class="text-right">
        <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Blueprint Status</div>
        <div class="text-xs font-black <?php echo ($existingBlueprint && $existingBlueprint['status'] === 'PUBLISHED') ? 'text-emerald-400' : 'text-amber-400'; ?>">
          <?php echo $existingBlueprint ? qbb_h($existingBlueprint['status']) : 'LOADED COURSE MATRIX'; ?>
        </div>
      </div>
      <div class="w-2.5 h-2.5 rounded-full <?php echo ($existingBlueprint && $existingBlueprint['status'] === 'PUBLISHED') ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'; ?>"></div>
    </div>
  </div>

  <!-- Course Selector & Parameters Filter Bar -->
  <form method="GET" action="" class="bg-white rounded-3xl p-4 sm:p-5 shadow-sm border border-slate-200/80 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 items-end">
    <div>
      <label class="block text-[11px] font-bold text-slate-600 mb-1">Select Course / Paper Code</label>
      <select name="paper_code" class="w-full text-xs rounded-xl border border-slate-300 bg-slate-50 p-2.5 font-bold text-slate-900 focus:ring-2 focus:ring-orange-500 focus:outline-none" onchange="this.form.submit()">
        <?php foreach ($availableCourses as $c): ?>
          <option value="<?php echo qbb_h($c['coursecode']); ?>" <?php echo strtoupper($c['coursecode']) === $selectedPaper ? 'selected' : ''; ?>>
            <?php echo qbb_h($c['coursecode']); ?> - <?php echo qbb_h($c['coursetitle'] ?? ''); ?> (<?php echo qbb_h($c['dept_code'] ?? ''); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-[11px] font-bold text-slate-600 mb-1">Academic Year</label>
      <input type="text" name="academic_year" value="<?php echo qbb_h($selectedYear); ?>" class="w-full text-xs rounded-xl border border-slate-300 bg-slate-50 p-2.5 font-bold text-slate-900 focus:ring-2 focus:ring-orange-500 focus:outline-none" placeholder="e.g. 2021-2022">
    </div>

    <div>
      <label class="block text-[11px] font-bold text-slate-600 mb-1">Semester</label>
      <input type="text" name="semester" value="<?php echo qbb_h($selectedSemester); ?>" class="w-full text-xs rounded-xl border border-slate-300 bg-slate-50 p-2.5 font-bold text-slate-900 focus:ring-2 focus:ring-orange-500 focus:outline-none" placeholder="e.g. Semester 1">
    </div>

    <div class="flex items-center space-x-2">
      <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-4 rounded-xl transition flex items-center justify-center space-x-1.5 shadow-sm">
        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
        <span>Load Course & Matrix</span>
      </button>
    </div>
  </form>

  <!-- ========================================================================= -->
  <!-- MAIN INSTITUTIONAL BLUEPRINT CARD (Matches sample-blueprint.jpeg)        -->
  <!-- ========================================================================= -->
  <div class="bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden" id="blueprint-printable-area">
    
    <!-- Institutional Document Header (Exact Format from Image) -->
    <div class="p-6 border-b border-slate-200 bg-slate-50/50 space-y-2">
      <div class="text-center space-y-1">
        <div class="flex items-center justify-center space-x-2 mb-1">
          <img src="https://hcctrichy.ac.in/img/logo.png" alt="Holy Cross College Logo" class="w-10 h-10 object-contain rounded-xl bg-white p-1 shadow-sm border border-slate-200">
        </div>
        <h3 class="text-base sm:text-lg font-black uppercase text-slate-900 font-serif tracking-wider">QUESTION BLUE PRINT (<?php echo qbb_h($selectedYear); ?>)</h3>
      </div>
      
      <!-- Metadata Strip -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-2 pt-2 text-xs font-bold text-slate-800 border-t border-slate-200">
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">DEPARTMENT:</span> <span class="font-black text-slate-900"><?php echo qbb_h($activeCourse['dept_code'] ?? $deptCode ?: 'ECONOMICS'); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">CODE:</span> <span class="font-black text-orange-600 font-mono"><?php echo qbb_h($selectedPaper); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">TITLE OF THE PAPER:</span> <span class="font-black text-slate-900"><?php echo qbb_h($activeCourse['coursetitle'] ?? 'BUSINESS ECONOMICS'); ?></span></div>
      </div>
    </div>

    <!-- Interactive Blueprint Table Form -->
    <form method="POST" action="" id="qbbMatrixForm" class="p-4 sm:p-6 space-y-4">
      <input type="hidden" name="csrf" value="<?php echo qbb_h($_SESSION['qbb_csrf']); ?>">
      <input type="hidden" name="action" value="save" id="qbbFormAction">
      <input type="hidden" name="id" value="<?php echo (int)($existingBlueprint['id'] ?? 0); ?>">
      <input type="hidden" name="paper_code" value="<?php echo qbb_h($selectedPaper); ?>">
      <input type="hidden" name="academic_year" value="<?php echo qbb_h($selectedYear); ?>">
      <input type="hidden" name="semester" value="<?php echo qbb_h($selectedSemester); ?>">
      <input type="hidden" name="exam_type" value="<?php echo qbb_h($selectedExam); ?>">
      <input type="hidden" name="matrix_json" id="qbbMatrixJsonHidden" value="">

      <!-- Table Toolbar -->
      <div class="flex flex-wrap items-center justify-between gap-2.5 pb-2">
        <div class="flex items-center space-x-2">
          <span class="text-xs font-bold text-slate-600">Question Pool Target:</span>
          <span class="text-xs font-black bg-orange-100 text-orange-900 border border-orange-300 px-3 py-1 rounded-full font-mono" id="liveGrandTotalBadge">
            <?php echo (int)($initialMatrix['totals']['grand_total'] ?? 275); ?> Questions
          </span>
        </div>

        <div class="flex items-center space-x-2">
          <?php if (!empty($poolStats['found']) && $poolStats['total'] > 0): ?>
            <button type="button" onclick="autoFillFromQuestionBankPool()" class="text-xs font-bold text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-300 px-3 py-1.5 rounded-xl transition flex items-center space-x-1.5 shadow-sm">
              <i data-lucide="database" class="w-3.5 h-3.5 text-emerald-600"></i>
              <span>Auto-Fill from Question Bank (<?php echo (int)$poolStats['total']; ?> Qs)</span>
            </button>
          <?php endif; ?>
          <button type="button" onclick="loadHccStandardPreset()" class="text-xs font-bold text-orange-700 bg-orange-50 hover:bg-orange-100 border border-orange-200 px-3 py-1.5 rounded-xl transition flex items-center space-x-1.5 shadow-sm">
            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
            <span>Standard 275-Q Preset (Image Format)</span>
          </button>
          <button type="button" onclick="clearAllInputs()" class="text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-xl transition">
            Clear Grid
          </button>
        </div>
      </div>

      <!-- Exact Multi-level Table matching sample-blueprint.jpeg -->
      <div class="overflow-x-auto rounded-2xl border-2 border-slate-900 shadow-md">
        <table class="w-full text-xs text-center border-collapse bg-white select-none" id="qbbTableMain">
          
          <!-- Double Header Rows -->
          <thead>
            <!-- Header Row 1 -->
            <tr class="bg-slate-950 text-white font-black uppercase text-[11px] border-b border-slate-800">
              <th rowspan="2" class="border-r border-slate-800 px-3 py-2 w-14">Unit<br><span class="text-[9px] text-slate-400 font-mono font-normal">(3-5)</span></th>
              <th rowspan="2" class="border-r border-slate-800 px-3 py-2 w-14">sub<br>unit</th>
              <th colspan="5" class="border-r border-slate-800 bg-amber-950/80 text-amber-200 py-1.5">MC</th>
              <th colspan="3" class="border-r border-slate-800 bg-sky-950/80 text-sky-200 py-1.5">V.S.A</th>
              <th colspan="3" class="border-r border-slate-800 bg-purple-950/80 text-purple-200 py-1.5">PART B</th>
              <th colspan="2" class="border-r border-slate-800 bg-emerald-950/80 text-emerald-200 py-1.5">PART C</th>
              <th colspan="1" class="border-r border-slate-800 bg-rose-950/80 text-rose-200 py-1.5">PART D</th>
              <th rowspan="2" class="bg-slate-900 text-white px-3 py-2 font-black w-16">TOTAL</th>
            </tr>
            
            <!-- Header Row 2: Sub-columns -->
            <tr class="bg-slate-900 text-white font-bold text-[10px] border-b-2 border-slate-950">
              <!-- MC sub-columns -->
              <th class="border-r border-slate-700 p-1 text-amber-300">K1(R)</th>
              <th class="border-r border-slate-700 p-1 text-amber-300">K1 (R)<br><span class="text-[8px] font-mono">(MATCH)</span></th>
              <th class="border-r border-slate-700 p-1 text-amber-300">K2<br>(U)</th>
              <th class="border-r border-slate-700 p-1 text-amber-300">K2 (U)<br><span class="text-[8px] font-mono">(ASSERTION)</span></th>
              <th class="border-r border-slate-700 p-1 text-amber-300">K3<br>(AP)</th>

              <!-- VSA sub-columns -->
              <th class="border-r border-slate-700 p-1 text-sky-300">K1(R)</th>
              <th class="border-r border-slate-700 p-1 text-sky-300">K2<br>(U)</th>
              <th class="border-r border-slate-700 p-1 text-sky-300">K3<br>(AP)</th>

              <!-- PART B sub-columns -->
              <th class="border-r border-slate-700 p-1 text-purple-300">K1(R)</th>
              <th class="border-r border-slate-700 p-1 text-purple-300">K2<br>(U)</th>
              <th class="border-r border-slate-700 p-1 text-purple-300">K4(AN)</th>

              <!-- PART C sub-columns -->
              <th class="border-r border-slate-700 p-1 text-emerald-300">K1(R)</th>
              <th class="border-r border-slate-700 p-1 text-emerald-300">K3<br>(AP)</th>

              <!-- PART D sub-columns -->
              <th class="border-r border-slate-700 p-1 text-rose-300">K2<br>(U)</th>
            </tr>
          </thead>

          <!-- Table Body with Dynamic Sub-units grouped by Unit I to V -->
          <tbody class="divide-y divide-slate-200" id="qbbMatrixTbody">
            <?php
            $romanUnits = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'];
            $gridRows = $initialMatrix['grid'] ?? [];
            
            // Group rows by unit for correct rowspan display
            $unitGroups = [];
            foreach ($gridRows as $r) {
                $u = (int)($r['unit'] ?? 1);
                $unitGroups[$u][] = $r;
            }

            foreach ($unitGroups as $u => $rowsInUnit):
                $unitCount = count($rowsInUnit);
                foreach ($rowsInUnit as $idx => $row):
                    $subUnit = $row['sub_unit'] ?? ($u . '.' . ($idx + 1));
                    $rowSum = $row['total'] ?? 0;
            ?>
              <tr class="hover:bg-amber-50/30 transition text-slate-800" data-unit="<?php echo $u; ?>" data-subunit="<?php echo qbb_h($subUnit); ?>">
                
                <!-- Unit Column (Rowspan spanning all subunits of this unit) -->
                <?php if ($idx === 0): ?>
                  <td rowspan="<?php echo $unitCount; ?>" class="border-r-2 border-slate-800 font-serif font-black text-sm bg-slate-100 text-slate-900 border-b-2">
                    <?php echo $romanUnits[$u] ?? $u; ?>
                  </td>
                <?php endif; ?>

                <!-- Sub-unit label column -->
                <td class="border-r-2 border-slate-800 font-mono font-bold text-xs bg-slate-50 text-slate-900 py-1.5 px-2">
                  <?php echo qbb_h($subUnit); ?>
                </td>

                <!-- 14 Interactive Number Inputs -->
                <?php foreach (array_keys($colDefs) as $ck): 
                  $cellVal = (int)($row[$ck] ?? 0);
                ?>
                  <td class="border-r border-slate-300 p-0.5">
                    <input type="number" min="0" max="99" 
                      name="grid[<?php echo qbb_h($subUnit); ?>][<?php echo $ck; ?>]" 
                      value="<?php echo $cellVal > 0 ? $cellVal : ''; ?>" 
                      placeholder="-"
                      data-col="<?php echo $ck; ?>"
                      data-subunit="<?php echo qbb_h($subUnit); ?>"
                      oninput="recalculateQbbTotals()" 
                      class="w-full h-8 text-center text-xs font-bold text-slate-900 bg-transparent hover:bg-white focus:bg-white focus:ring-2 focus:ring-orange-500 focus:outline-none rounded transition"
                    >
                  </td>
                <?php endforeach; ?>

                <!-- Row Total -->
                <td class="font-black font-mono text-xs bg-slate-100 text-slate-900 row-total px-2 py-1.5">
                  <?php echo $rowSum; ?>
                </td>
              </tr>
            <?php endforeach; endforeach; ?>
          </tbody>

          <!-- Bottom Summary Row (Total Column Totals) -->
          <tfoot>
            <tr class="bg-slate-950 text-white font-black border-t-2 border-slate-950 text-xs" id="qbbTableTotalsRow">
              <td colspan="2" class="border-r-2 border-slate-800 px-3 py-2.5 uppercase tracking-wider text-center font-serif text-sm">
                Total
              </td>

              <!-- Column Totals for 14 columns -->
              <?php foreach (array_keys($colDefs) as $ck): 
                $colTotal = (int)($initialMatrix['totals'][$ck] ?? 0);
              ?>
                <td class="border-r border-slate-800 py-2.5 px-1 font-mono font-bold col-total text-amber-300" data-col-total="<?php echo $ck; ?>">
                  <?php echo $colTotal; ?>
                </td>
              <?php endforeach; ?>

              <!-- Grand Total -->
              <td class="bg-orange-600 text-white font-mono font-black text-sm py-2.5 px-2" id="qbbGrandTotalCell">
                <?php echo (int)($initialMatrix['totals']['grand_total'] ?? 275); ?>
              </td>
            </tr>
          </tfoot>

        </table>
      </div>

      <!-- Action Buttons -->
      <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-200">
        <div class="flex items-center space-x-2">
          <button type="button" onclick="window.print()" class="bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center space-x-1.5">
            <i data-lucide="printer" class="w-4 h-4"></i>
            <span>Print Blueprint</span>
          </button>
        </div>

        <div class="flex items-center space-x-3">
          <!-- Save Draft Button (HOD & Staff) -->
          <button type="button" onclick="submitQbbForm('save')" class="bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold px-5 py-2.5 rounded-xl transition flex items-center space-x-2 shadow">
            <i data-lucide="save" class="w-4 h-4 text-orange-400"></i>
            <span>Save Blueprint Draft</span>
          </button>

          <!-- Publish & Lock Button (HOD / COE) -->
          <?php if ($isHod || $isCoe): ?>
            <button type="button" onclick="submitQbbForm('publish')" class="bg-gradient-to-r from-orange-500 to-amber-500 hover:from-orange-600 hover:to-amber-600 text-white text-xs font-black px-5 py-2.5 rounded-xl transition flex items-center space-x-2 shadow-lg shadow-orange-500/30">
              <i data-lucide="shield-check" class="w-4 h-4"></i>
              <span>Publish & Activate for Course</span>
            </button>
          <?php endif; ?>
        </div>
      </div>

    </form>
  </div>


  <!-- ========================================================================= -->
  <!-- QUESTION BANK CUMULATIVE POOL VERIFICATION (Target vs Uploaded Pool)     -->
  <!-- ========================================================================= -->
  <div class="bg-white rounded-3xl p-5 sm:p-6 shadow-sm border border-slate-200 space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
      <div>
        <h3 class="text-sm font-black text-slate-900 uppercase tracking-wide flex items-center space-x-2">
          <i data-lucide="check-square" class="w-4 h-4 text-emerald-600"></i>
          <span>Question Bank Upload Compliance Status</span>
        </h3>
        <p class="text-xs text-slate-500">Live comparison between target blueprint requirements and questions uploaded by faculty.</p>
      </div>

      <div class="flex items-center space-x-2">
        <span class="text-xs font-bold text-slate-600">Total in Uploaded Pool:</span>
        <span class="text-xs font-black bg-emerald-100 text-emerald-900 border border-emerald-300 px-3 py-1 rounded-full font-mono">
          <?php echo (int)($poolStats['total'] ?? 0); ?> Questions Available
        </span>
      </div>
    </div>

    <!-- Unit Breakdown Pill Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
      <?php for ($u = 1; $u <= 5; $u++): 
        $avail = (int)($poolStats['units'][$u] ?? 0);
      ?>
        <div class="p-3.5 rounded-2xl border <?php echo $avail >= 40 ? 'bg-emerald-50/60 border-emerald-200' : ($avail > 0 ? 'bg-amber-50/60 border-amber-200' : 'bg-slate-50 border-slate-200'); ?>">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs font-black font-serif text-slate-900">Unit <?php echo $romanUnits[$u] ?? $u; ?></span>
            <span class="text-[10px] font-mono font-black <?php echo $avail >= 40 ? 'text-emerald-700' : ($avail > 0 ? 'text-amber-700' : 'text-slate-400'); ?>">
              <?php echo $avail; ?> in pool
            </span>
          </div>
          <div class="text-[10px] text-slate-500">
            <?php echo $avail >= 40 ? '✓ Pool satisfied' : ($avail > 0 ? '● In progress' : 'No questions uploaded'); ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>

    <div class="flex items-center justify-between pt-2 text-xs text-slate-500">
      <span>When staff upload a question bank (Word/Excel/PDF), the system verifies against this blueprint.</span>
      <a href="<?php echo $baseUrl; ?>/modules/teaching/question-entry.php?paper_code=<?php echo urlencode($selectedPaper); ?>" class="text-orange-600 hover:text-orange-700 font-bold flex items-center space-x-1">
        <span>Go to Question Upload Workspace</span>
        <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
      </a>
    </div>
  </div>

</main>

<!-- Client-side Interactive Logic matching sample-blueprint.jpeg -->
<script>
const HCC_DEFAULT_PRESET = <?php echo json_encode(qps_qbb_default_hcc_grid()); ?>;
const POOL_GRID_COUNTS = <?php echo json_encode($poolStats['grid_counts'] ?? []); ?>;

function recalculateQbbTotals() {
  const tbody = document.getElementById('qbbMatrixTbody');
  if (!tbody) return;

  const rows = tbody.querySelectorAll('tr[data-subunit]');
  const colTotals = {};
  let grandTotal = 0;

  rows.forEach(r => {
    let rowSum = 0;
    const inputs = r.querySelectorAll('input[data-col]');
    inputs.forEach(inp => {
      const colKey = inp.getAttribute('data-col');
      const val = parseInt(inp.value, 10) || 0;
      rowSum += val;
      colTotals[colKey] = (colTotals[colKey] || 0) + val;
    });

    const rowTotalEl = r.querySelector('.row-total');
    if (rowTotalEl) {
      rowTotalEl.textContent = rowSum;
    }
    grandTotal += rowSum;
  });

  // Update Column Totals in footer
  Object.keys(colTotals).forEach(ck => {
    const el = document.querySelector(`td[data-col-total="${ck}"]`);
    if (el) el.textContent = colTotals[ck];
  });

  // Update Grand Total
  const grandEl = document.getElementById('qbbGrandTotalCell');
  if (grandEl) grandEl.textContent = grandTotal;

  const badgeEl = document.getElementById('liveGrandTotalBadge');
  if (badgeEl) badgeEl.textContent = `${grandTotal} Questions`;
}

function autoFillFromQuestionBankPool() {
  const tbody = document.getElementById('qbbMatrixTbody');
  if (!tbody) return;

  const inputs = tbody.querySelectorAll('input[data-col][data-subunit]');
  inputs.forEach(inp => {
    const su = inp.getAttribute('data-subunit');
    const ck = inp.getAttribute('data-col');
    const cellKey = `${su}_${ck}`;
    const count = POOL_GRID_COUNTS[cellKey] || 0;
    inp.value = count > 0 ? count : '';
  });

  recalculateQbbTotals();
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      icon: 'success',
      title: 'Auto-Filled from Question Bank',
      text: 'Loaded live question counts directly from the uploaded Question Bank repository for this course.',
      confirmButtonColor: '#FF5B00',
      customClass: { popup: 'rounded-[28px]' }
    });
  }
}

function loadHccStandardPreset() {
  const tbody = document.getElementById('qbbMatrixTbody');
  if (!tbody) return;

  HCC_DEFAULT_PRESET.forEach(p => {
    const sub = p.sub_unit;
    const r = tbody.querySelector(`tr[data-subunit="${sub}"]`);
    if (r) {
      Object.keys(p).forEach(k => {
        if (k !== 'unit' && k !== 'sub_unit' && k !== 'total') {
          const inp = r.querySelector(`input[data-col="${k}"]`);
          if (inp) {
            const v = p[k];
            inp.value = (v > 0) ? v : '';
          }
        }
      });
    }
  });

  recalculateQbbTotals();
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      icon: 'success',
      title: 'Standard Blueprint Preset Loaded',
      text: 'Loaded exact 275-Question OBE distribution from Holy Cross College blueprint specification.',
      confirmButtonColor: '#FF5B00',
      customClass: { popup: 'rounded-[28px]' }
    });
  }
}

function clearAllInputs() {
  const inputs = document.querySelectorAll('#qbbMatrixTbody input[type="number"]');
  inputs.forEach(inp => { inp.value = ''; });
  recalculateQbbTotals();
}

function submitQbbForm(actionType) {
  const form = document.getElementById('qbbMatrixForm');
  const actionHidden = document.getElementById('qbbFormAction');
  const matrixHidden = document.getElementById('qbbMatrixJsonHidden');
  if (!form || !actionHidden || !matrixHidden) return;

  actionHidden.value = actionType;

  // Build grid object
  const gridRows = [];
  const rows = document.querySelectorAll('#qbbMatrixTbody tr[data-subunit]');
  rows.forEach(r => {
    const unit = parseInt(r.getAttribute('data-unit'), 10) || 1;
    const sub = r.getAttribute('data-subunit');
    const rowObj = { unit: unit, sub_unit: sub };
    let rSum = 0;

    r.querySelectorAll('input[data-col]').forEach(inp => {
      const ck = inp.getAttribute('data-col');
      const val = parseInt(inp.value, 10) || 0;
      rowObj[ck] = val;
      rSum += val;
    });
    rowObj['total'] = rSum;
    gridRows.push(rowObj);
  });

  const matrixPayload = { grid: gridRows };
  matrixHidden.value = JSON.stringify(matrixPayload);

  if (actionType === 'publish') {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: 'Publish & Lock Blueprint?',
        text: 'Publishing will set this blueprint as the official course standard for faculty question uploads and automated COE paper generation.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Publish Blueprint',
        confirmButtonColor: '#FF5B00',
        cancelButtonColor: '#475569',
        customClass: { popup: 'rounded-[28px]' }
      }).then(res => {
        if (res.isConfirmed) form.submit();
      });
      return;
    }
  }

  form.submit();
}

document.addEventListener('DOMContentLoaded', recalculateQbbTotals);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
