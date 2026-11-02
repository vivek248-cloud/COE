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

$selectedYear = trim((string)($_GET['academic_year'] ?? ($_POST['academic_year'] ?? (defined('DEFAULT_ACADEMIC_YEAR') ? DEFAULT_ACADEMIC_YEAR : '2026-2027'))));
$selectedSemester = trim((string)($_GET['semester'] ?? ($_POST['semester'] ?? 'Semester 1')));
$selectedExam = trim((string)($_GET['exam_type'] ?? ($_POST['exam_type'] ?? 'Odd Semester End Examination')));

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

// Fetch actual entered questions for this course
$enteredQuestions = [];
if ($selectedPaper !== '') {
    $stDraft = $pdo->prepare("SELECT id FROM qps_question_bank_drafts WHERE UPPER(paper_code) = UPPER(?) ORDER BY id DESC LIMIT 1");
    $stDraft->execute([$selectedPaper]);
    $dId = (int)$stDraft->fetchColumn();

    if ($dId > 0) {
        $stDQ = $pdo->prepare("SELECT * FROM qps_question_bank_draft_questions WHERE draft_id = ? ORDER BY unit_no ASC, sub_unit ASC, marks ASC, id ASC");
        $stDQ->execute([$dId]);
        $enteredQuestions = $stDQ->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($enteredQuestions)) {
        $stQ = $pdo->prepare("SELECT * FROM questions WHERE UPPER(course_code) = UPPER(?) OR UPPER(paper_code) = UPPER(?) OR bank_id IN (SELECT id FROM question_banks WHERE UPPER(paper_code) = UPPER(?)) ORDER BY unit_no ASC, sub_unit ASC, marks ASC, id ASC");
        $stQ->execute([$selectedPaper, $selectedPaper, $selectedPaper]);
        $enteredQuestions = $stQ->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Build live dynamic matrix for ONLY the unit & subunit rows the staff entered
$compiledMatrix = [];
$totalPoolQuestions = count($enteredQuestions);
$totalPoolMarks = 0;

if (!empty($enteredQuestions)) {
    foreach ($enteredQuestions as $qItem) {
        $uNo = (int)($qItem['unit_no'] ?? 1);
        $sUnit = trim((string)($qItem['sub_unit'] ?? "$uNo.1"));
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
        if ($uA !== $uB) {
            return $uA <=> $uB;
        }
        $suA = (int)($partA[1] ?? 0);
        $suB = (int)($partB[1] ?? 0);
        return $suA <=> $suB;
    });

    foreach ($enteredQuestions as $q) {
        $uNo = (int)($q['unit_no'] ?? 1);
        $sUnit = trim((string)($q['sub_unit'] ?? "$uNo.1"));
        if ($sUnit === '') $sUnit = "$uNo.1";
        $m = (int)($q['marks'] ?? 1);
        $k = strtoupper((string)($q['k_level'] ?? 'K1'));
        $type = strtoupper((string)($q['question_type'] ?? 'MCQ'));
        $sec = strtoupper((string)($q['section_type'] ?? 'SECTION-A'));

        $totalPoolMarks += $m;

        if (isset($compiledMatrix[$sUnit])) {
            $compiledMatrix[$sUnit]['total_questions']++;
            $compiledMatrix[$sUnit]['total_marks'] += $m;

            if ($sec === 'SECTION-A') {
                if ($type === 'VSA' || $type === 'SHORT') {
                    if ($k === 'K2') $compiledMatrix[$sUnit]['vsa_k2']++;
                    else $compiledMatrix[$sUnit]['vsa_k1']++;
                } else {
                    if ($k === 'K2') $compiledMatrix[$sUnit]['mcq_k2']++;
                    else $compiledMatrix[$sUnit]['mcq_k1']++;
                }
            } elseif ($sec === 'SECTION-B') {
                if ($k === 'K4' || $k === 'K5' || $k === 'K6') $compiledMatrix[$sUnit]['sec_b_k4']++;
                else $compiledMatrix[$sUnit]['sec_b_k3']++;
            } elseif ($sec === 'SECTION-C') {
                if ($k === 'K6') $compiledMatrix[$sUnit]['sec_c_k6']++;
                else $compiledMatrix[$sUnit]['sec_c_k5']++;
            } elseif ($sec === 'SECTION-D') {
                $compiledMatrix[$sUnit]['sec_d_k6']++;
            }
        }
    }
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

// Group matrix rows by unit and sort units strictly in ASCENDING order (Unit I, II, III, IV, V)
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
        <span>Live Compiled Question Bank Blueprint</span>
      </h2>
      <p class="text-xs text-slate-400 max-w-2xl">
        Dynamically compiled matrix of entered units and subunits matching the official examination scheme. Shows the actual distribution of MC, VSA, Section B, Section C, and Section D items.
      </p>
    </div>

    <!-- Quick Status Strip -->
    <div class="flex flex-wrap items-center gap-2.5 shrink-0">
      <a href="<?php echo $baseUrl; ?>/modules/teaching/upload.php?paper_code=<?php echo urlencode($selectedPaper); ?>" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs px-4 py-2.5 rounded-2xl shadow-lg flex items-center space-x-1.5 transition">
        <i data-lucide="edit-3" class="w-4 h-4"></i>
        <span>Question Entry & Slot Allocation &rarr;</span>
      </a>
      <div class="flex items-center gap-2 bg-slate-900/90 border border-slate-800 p-3 rounded-2xl">
        <div class="text-right">
          <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Pool Status</div>
          <div class="text-xs font-black <?php echo $totalPoolQuestions > 0 ? 'text-emerald-400' : 'text-amber-400'; ?>">
            <?php echo $totalPoolQuestions > 0 ? "{$totalPoolQuestions} Qs ({$totalPoolMarks}M)" : 'EMPTY POOL'; ?>
          </div>
        </div>
        <div class="w-2.5 h-2.5 rounded-full <?php echo $totalPoolQuestions > 0 ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'; ?>"></div>
      </div>
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
      <input type="text" name="academic_year" value="<?php echo qbb_h($selectedYear); ?>" class="w-full text-xs rounded-xl border border-slate-300 bg-slate-50 p-2.5 font-bold text-slate-900 focus:ring-2 focus:ring-orange-500 focus:outline-none" placeholder="e.g. 2026-2027">
    </div>

    <div>
      <label class="block text-[11px] font-bold text-slate-600 mb-1">Semester</label>
      <input type="text" name="semester" value="<?php echo qbb_h($selectedSemester); ?>" class="w-full text-xs rounded-xl border border-slate-300 bg-slate-50 p-2.5 font-bold text-slate-900 focus:ring-2 focus:ring-orange-500 focus:outline-none" placeholder="e.g. Semester 1">
    </div>

    <div class="flex items-center space-x-2">
      <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-4 rounded-xl transition flex items-center justify-center space-x-1.5 shadow-sm">
        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
        <span>Load Course Matrix</span>
      </button>
    </div>
  </form>

  <!-- Metric Badges Strip -->
  <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
    <div class="p-3.5 bg-white rounded-2xl border border-slate-200/80 shadow-sm">
      <span class="text-[11px] font-bold text-slate-500 uppercase">Selected Course</span>
      <div class="text-base font-black text-slate-900 mt-0.5 truncate"><?php echo qbb_h($selectedPaper); ?></div>
      <div class="text-[11px] text-slate-500 truncate"><?php echo qbb_h($activeCourse['coursetitle'] ?? 'Course Title'); ?></div>
    </div>
    <div class="p-3.5 bg-blue-50 rounded-2xl border border-blue-200 shadow-sm">
      <span class="text-[11px] font-bold text-blue-700 uppercase">Total Questions in Pool</span>
      <div class="text-lg font-black text-blue-900 mt-0.5"><?php echo $totalPoolQuestions; ?> Items</div>
    </div>
    <div class="p-3.5 bg-emerald-50 rounded-2xl border border-emerald-200 shadow-sm">
      <span class="text-[11px] font-bold text-emerald-700 uppercase">Total Marks in Pool</span>
      <div class="text-lg font-black text-emerald-900 mt-0.5"><?php echo $totalPoolMarks; ?> Marks</div>
    </div>
    <div class="p-3.5 bg-purple-50 rounded-2xl border border-purple-200 shadow-sm">
      <span class="text-[11px] font-bold text-purple-700 uppercase">Entered Subunits</span>
      <div class="text-lg font-black text-purple-900 mt-0.5"><?php echo count($compiledMatrix); ?> Subunits</div>
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
        <p class="text-xs font-bold text-slate-600 uppercase">Question Bank Blueprint (Live Entered Units • <?php echo qbb_h($selectedYear); ?>)</p>
      </div>
      
      <!-- Metadata Strip -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-2 pt-2 text-xs font-bold text-slate-800 border-t border-slate-200">
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">DEPARTMENT:</span> <span class="font-black text-slate-900"><?php echo qbb_h($activeCourse['dept_code'] ?? $deptCode ?: 'DEPARTMENT'); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">PAPER CODE:</span> <span class="font-black text-orange-600 font-mono"><?php echo qbb_h($selectedPaper); ?></span></div>
        <div><span class="text-slate-500 uppercase font-bold text-[10px] block">TITLE OF THE PAPER:</span> <span class="font-black text-slate-900"><?php echo qbb_h($activeCourse['coursetitle'] ?? 'COURSE TITLE'); ?></span></div>
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
              <th colspan="2" class="p-2 border-r border-slate-300 bg-purple-50/70 text-purple-900">Section D (10M)</th>
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
                    <p class="font-semibold text-xs">No questions entered yet for <strong><?php echo qbb_h($selectedPaper); ?></strong>.</p>
                    <p class="text-[11px] text-slate-400">Questions created by faculty will dynamically compile into this blueprint matrix.</p>
                    <a href="<?php echo $baseUrl; ?>/modules/teaching/upload.php?paper_code=<?php echo urlencode($selectedPaper); ?>" class="inline-flex items-center space-x-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-3.5 py-2 rounded-xl shadow transition">
                      <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                      <span>Enter Questions Now</span>
                    </a>
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
                    <?php echo qbb_h($row['sub_unit']); ?>
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

                  <!-- Sec D 10M -->
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
          <button type="button" onclick="window.print()" class="bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center space-x-1.5 shadow-sm">
            <i data-lucide="printer" class="w-4 h-4"></i>
            <span>Print Blueprint Matrix</span>
          </button>
        </div>

        <div class="flex items-center space-x-2">
          <a href="<?php echo $baseUrl; ?>/modules/teaching/upload.php?paper_code=<?php echo urlencode($selectedPaper); ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-5 py-2.5 rounded-xl transition flex items-center space-x-1.5 shadow-md shadow-indigo-200">
            <i data-lucide="edit-3" class="w-4 h-4"></i>
            <span>Edit Questions / Slots &rarr;</span>
          </a>
        </div>
      </div>

    </div>
  </div>

</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) {
    lucide.createIcons();
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
