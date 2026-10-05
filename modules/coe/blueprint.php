<?php
/**
 * Holy Cross College (Autonomous), Tiruchirappalli
 * OBE Master Blueprint Manager & Question Pool Matrix Designer
 * Strictly conforming to Tss.pdf & sample-blueprint.jpeg
 * - 275+ Question Master Syllabus Pool Matrix (sample-blueprint.jpeg) with dynamic sub-units & live totals
 * - 30-Question Final Exam Paper Matrix (Tss.pdf) with interactive Light Blue cells
 * - Course-driven units and sub-units, Role-based access for Teaching Staff, HOD, and COE
 */
define('PAGE_TITLE', 'OBE Master Blueprint Manager');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/pagination.php';
require_once __DIR__ . '/../../includes/question_bank_blueprint.php';

requireAuth();
$pdo = getDBConnection();
$currentUser = getCurrentUser();
$user = is_array($currentUser) ? $currentUser : ['staff_code' => 'COE_OFFICE', 'role' => 'COE_ADMIN', 'dept_code' => ''];
$isCoe = isCOE();
$isHod = isHOD();
$isStaff = !$isCoe && !$isHod;

qps_ensure_aux_schema($pdo);

$msg = '';
$error = '';

$examTypesList = function_exists('hcc_exam_types') ? hcc_exam_types() : [
    'Internal 1' => 'Internal 1 (CIA-I)',
    'Internal 2' => 'Internal 2 (CIA-II)',
    'Odd Semester End Examination' => 'Odd Semester End Examination (Nov/Dec)',
    'Even Semester End Examination' => 'Even Semester End Examination (Apr/May)',
    'Year' => 'Year End Examination'
];

// Handle Blueprint Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_blueprint'])) {
    $bpId = intval($_POST['blueprint_id'] ?? 0);
    $bpName = trim($_POST['name'] ?? '');
    $deptCode = trim($_POST['dept_code'] ?? '');
    $paperCode = trim($_POST['paper_code'] ?? '');
    $courseTitle = trim($_POST['course_title'] ?? '');
    $semester = trim($_POST['semester'] ?? 'Semester 1');
    $academicYear = trim($_POST['academic_year'] ?? DEFAULT_ACADEMIC_YEAR);
    $examType = trim($_POST['exam_type'] ?? 'Odd Semester End Examination');
    $totalMarks = intval($_POST['total_marks'] ?? 75);
    $durationHours = trim($_POST['duration_hours'] ?? '3 Hours');
    $instructions = trim($_POST['instructions'] ?? '');
    $matrixConfig = trim($_POST['matrix_config'] ?? '');
    $sectionsConfig = trim($_POST['sections_config'] ?? '');
    $now = date('Y-m-d H:i:s');

    if ($bpName === '') {
        $error = 'Blueprint Name is required.';
    } elseif ($paperCode === '') {
        $error = 'Attached Course / Subject is required. Select a real ERP course before saving the blueprint.';
    } else {
        try {
            $courseSt = $pdo->prepare("SELECT coursecode, coursetitle, dept_code, maxmark, qpattern, type, level FROM courses WHERE UPPER(coursecode)=UPPER(?) LIMIT 1");
            $courseSt->execute([$paperCode]);
            $courseRow = $courseSt->fetch(PDO::FETCH_ASSOC);
            if (!$courseRow) throw new RuntimeException('Selected course was not found in the ERP courses table.');
            $courseExam = hcc_course_exam_info($courseRow);
            $totalMarks = (int)$courseExam['exam_marks'];
            $courseTitle = $courseRow['coursetitle'];
            $deptCode = $courseRow['dept_code'] ?: $deptCode;

            $cfg = json_decode($matrixConfig, true);
            if (!is_array($cfg) || empty($cfg['matrix_30q']) || !is_array($cfg['matrix_30q'])) {
                throw new RuntimeException('Sync the HOD-published Question Bank Blueprint first. The COE page accepts only the 30-question course blueprint.');
            }
            $qbbStateSt = $pdo->prepare("SELECT id, qbb_enabled FROM question_bank_blueprints WHERE status='PUBLISHED' AND qbb_enabled=1 AND UPPER(paper_code)=UPPER(?) AND (semester=? OR semester IS NULL OR semester='') AND (academic_year=? OR academic_year IS NULL OR academic_year='') AND (exam_type=? OR exam_type IS NULL OR exam_type='') ORDER BY published_at DESC, id DESC LIMIT 1");
            $qbbStateSt->execute([$paperCode, $semester, $academicYear, $examType]);
            $activeQbb = $qbbStateSt->fetch(PDO::FETCH_ASSOC);
            if (!$activeQbb) {
                throw new RuntimeException('Use Question Bank Blueprint is OFF for ' . $paperCode . '. Ask the HOD to publish a Question Bank Blueprint and turn the Use QBB toggle ON.');
            }
            $qbbStats = qps_qbb_pool_stats($pdo, $paperCode, $semester, $academicYear, $examType);
            if (!$qbbStats['found']) throw new RuntimeException('No submitted/verified cumulative question pool is available for ' . $paperCode . '.');
            $selectedTotal = 0; $seenNumbers = []; $validationErrors = []; $selectedByUnit = []; $selectedBySub = [];
            foreach ($cfg['matrix_30q'] as $idx => $row) {
                $required = (int)($row['required_count'] ?? 0);
                $sub = (string)($row['sub_unit'] ?? ''); $unit = (string)($row['unit'] ?? '');
                $available = (int)($row['available_subunit'] ?? ($qbbStats['sub_units'][$sub] ?? 0));
                if ($required > $available) $validationErrors[] = 'Row '.($idx+1).' / '.$sub.': requires '.$required.' but the cumulative pool contains only '.$available.'.';
                $vals = preg_split('/\s*,\s*/', trim((string)($row['question_numbers'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                $selectedTotal += count($vals); $selectedByUnit[$unit] = ($selectedByUnit[$unit] ?? 0) + count($vals); $selectedBySub[$sub] = ($selectedBySub[$sub] ?? 0) + count($vals);
                foreach ($vals as $v) { if (isset($seenNumbers[$v])) $validationErrors[] = 'Question '.$v.' is selected more than once.'; $seenNumbers[$v]=true; }
                if (count($vals) > $required) $validationErrors[] = 'Row '.($idx+1).' / '.$sub.': '.$required.' required but '.count($vals).' question numbers were entered.';
            }
            foreach ($selectedByUnit as $unit => $cnt) { $available=(int)($qbbStats['units'][(int)$unit] ?? 0); if ($cnt > $available) $validationErrors[]='Unit '.$unit.': '.$cnt.' questions selected, but the cumulative pool contains only '.$available.'.'; }
            foreach ($selectedBySub as $sub => $cnt) { $available=(int)($qbbStats['sub_units'][$sub] ?? 0); if ($cnt > $available) $validationErrors[]='Sub-Unit '.$sub.': '.$cnt.' questions selected, but the cumulative pool contains only '.$available.'.'; }
            if ($selectedTotal !== 30) $validationErrors[] = 'The final blueprint must contain exactly 30 selected questions; currently '.$selectedTotal.'.';
            if ($validationErrors) throw new RuntimeException(implode(' ', array_values(array_unique($validationErrors))));
            // Unique Name Check (excluding current edit ID)
            $stChk = $pdo->prepare("SELECT id FROM blueprints WHERE UPPER(name) = ? AND id != ?");
            $stChk->execute([strtoupper($bpName), $bpId]);
            if ($stChk->fetch()) {
                throw new RuntimeException("A blueprint with the name '{$bpName}' already exists. Please choose a unique name.");
            }

            if ($bpId > 0) {
                $stUp = $pdo->prepare("UPDATE blueprints SET 
                    name = ?, dept_code = ?, paper_code = ?, course_title = ?,
                    semester = ?, academic_year = ?, exam_type = ?, total_marks = ?,
                    duration_hours = ?, sections_config = ?, instructions = ?,
                    matrix_config = ?, updated_at = ?
                    WHERE id = ?");
                $stUp->execute([
                    $bpName, $deptCode, $paperCode, $courseTitle,
                    $semester, $academicYear, $examType, $totalMarks,
                    $durationHours, $sectionsConfig, $instructions,
                    $matrixConfig, $now, $bpId
                ]);
                $msg = "Blueprint '{$bpName}' updated successfully.";
            } else {
                $stIns = $pdo->prepare("INSERT INTO blueprints (
                    name, dept_code, paper_code, course_title,
                    semester, academic_year, exam_type, total_marks,
                    duration_hours, sections_config, instructions,
                    created_by, created_at, updated_at, matrix_config
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stIns->execute([
                    $bpName, $deptCode, $paperCode, $courseTitle,
                    $semester, $academicYear, $examType, $totalMarks,
                    $durationHours, $sectionsConfig, $instructions,
                    $user['staff_code'] ?? 'STAFF', $now, $now, $matrixConfig
                ]);
                $bpId = (int)$pdo->lastInsertId();
                $msg = "Master Blueprint '{$bpName}' created successfully.";
            }

            qps_audit($pdo, 'BLUEPRINT_SAVE', 'BLUEPRINT', (string)$bpId, [
                'name' => $bpName,
                'paper_code' => $paperCode,
                'total_marks' => $totalMarks
            ]);

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_blueprint') {
    $delId = intval($_POST['blueprint_id'] ?? 0);
    if ($delId > 0) {
        try {
            $pdo->prepare("DELETE FROM blueprints WHERE id = ?")->execute([$delId]);
            $msg = "Blueprint #{$delId} deleted successfully.";
            qps_audit($pdo, 'BLUEPRINT_DELETE', 'BLUEPRINT', (string)$delId);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Fetch departments & courses for selection
$departments = [];
$courses = [];
try {
    $departments = $pdo->query("SELECT code, name FROM departments WHERE is_active = '1' OR is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $courses = $pdo->query("SELECT coursecode, coursetitle, dept_code, maxmark, type FROM courses ORDER BY coursecode ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Edit mode loading
$editId = intval($_GET['edit_id'] ?? 0);
$editingBp = null;
if ($editId > 0) {
    try {
        $stE = $pdo->prepare("SELECT * FROM blueprints WHERE id = ?");
        $stE->execute([$editId]);
        $editingBp = $stE->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Preselect course if passed via GET
$selectedCourseParam = trim($_GET['course_code'] ?? $_GET['course'] ?? ($editingBp['paper_code'] ?? ''));

// Fetch all blueprints for listing
$blueprintsList = [];
try {
    $stList = $pdo->query("SELECT * FROM blueprints ORDER BY id DESC");
    $allBlueprintRows = $stList->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allBlueprintRows as $bpRow) {
        $cfg = json_decode((string)($bpRow['matrix_config'] ?? ''), true);
        if (!is_array($cfg)) continue;
        $has30 = !empty($cfg['matrix_30q']) && is_array($cfg['matrix_30q']);
        $has275 = !empty($cfg['matrix_275q']) && is_array($cfg['matrix_275q']);
        $isNewQbbDriven = (($cfg['source'] ?? '') === 'HOD_PUBLISHED_QUESTION_BANK_BLUEPRINT');
        if ($isNewQbbDriven || ($has30 && !$has275)) $blueprintsList[] = $bpRow;
    }
} catch (Exception $e) {}

$totalBlueprints = count($blueprintsList);

// Show which courses have a recently uploaded question bank so the blueprint is
// always anchored to real uploaded units/sub-units rather than invented defaults.
$recentUploadByCourse = [];
try {
    $rRows = $pdo->query("SELECT paper_code, dept_code, total_questions, updated_at FROM question_banks WHERE paper_code IS NOT NULL AND paper_code <> '' ORDER BY updated_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rRows as $rr) {
        $code = strtoupper((string)$rr['paper_code']);
        if (!isset($recentUploadByCourse[$code])) $recentUploadByCourse[$code] = $rr;
    }
} catch (Throwable $e) {}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="flex min-h-[calc(100vh-64px)] bg-[#F8F9FA]">
  <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

  <main class="flex-1 p-4 md:p-8 overflow-y-auto space-y-6">

    <!-- Hero Header Banner -->
    <div class="bg-gradient-to-r from-[#1C1D21] via-slate-900 to-indigo-950 rounded-[28px] p-7 text-white shadow-xl border border-stone-800 relative overflow-hidden">
      <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div class="inline-flex items-center space-x-2 bg-white/10 px-3 py-1 rounded-full text-xs font-bold text-amber-300 backdrop-blur-md mb-2 border border-white/15">
            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
            <span>Holy Cross College (Autonomous) - OBE Question Blue Print</span>
          </div>
          <h1 class="text-2xl md:text-3xl font-black tracking-tight">
            Master Question Blueprint Designer
          </h1>
          <p class="text-slate-300 text-xs md:text-sm mt-1 max-w-2xl">
            Build the final 30-question paper blueprint from the HOD-published course Question Bank Blueprint. The COE page no longer exposes the 275-question master matrix.
          </p>
        </div>

        <div class="flex items-center gap-2.5">
          <a href="#saved-blueprints-section" class="bg-white/10 hover:bg-white/20 text-white border border-white/25 text-xs font-bold px-4 py-2.5 rounded-full backdrop-blur-md transition flex items-center space-x-2">
            <i data-lucide="list" class="w-4 h-4"></i>
            <span>Saved Blueprints (<?php echo $totalBlueprints; ?>)</span>
          </a>
          <button type="button" onclick="exportBlueprintJson()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-4 py-2.5 rounded-full shadow-lg transition flex items-center space-x-2">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Export JSON Spec</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($msg): ?>
      <div class="bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-2xl p-4 text-xs font-bold flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i>
          <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900"><i data-lucide="x" class="w-4 h-4"></i></button>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="bg-red-50 border border-red-300 text-red-900 rounded-2xl p-4 text-xs font-bold flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <i data-lucide="alert-triangle" class="w-4 h-4 text-red-600"></i>
          <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900"><i data-lucide="x" class="w-4 h-4"></i></button>
      </div>
    <?php endif; ?>

    <!-- Interactive Blueprint Designer Card -->
    <div class="bg-white rounded-[28px] shadow-sm border border-stone-200/80 p-6 space-y-6">
      
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-stone-100 pb-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 rounded-2xl bg-[#1C1D21] text-amber-300 flex items-center justify-center font-black text-sm shadow">
            <i data-lucide="layout-template" class="w-5 h-5"></i>
          </div>
          <div>
            <h3 class="font-extrabold text-slate-900 text-base">
              <?php echo $editingBp ? 'Edit Master Blueprint: ' . htmlspecialchars($editingBp['name']) : 'Master Question Blueprint Configuration'; ?>
            </h3>
            <p class="text-xs text-slate-500">Build the 30-question paper blueprint only from the HOD-published Question Bank Blueprint for the selected course.</p>
          </div>
        </div>

        <!-- Blueprint Mode Switcher Tabs -->
        <div class="flex items-center bg-stone-100 p-1 rounded-full border border-stone-200 text-xs font-bold">
          <button type="button" style="display:none" onclick="switchBlueprintView('275q')" id="btn-tab-275q" class="px-3.5 py-1.5 rounded-full bg-[#1C1D21] text-white shadow transition text-[11px] flex items-center space-x-1.5">
            <i data-lucide="database" class="w-3.5 h-3.5 text-amber-300"></i>
            <span>275Q Master Pool Matrix (sample-blueprint.jpeg)</span>
          </button>
          <button type="button" onclick="switchBlueprintView('30q')" id="btn-tab-30q" class="px-3.5 py-1.5 rounded-full text-slate-700 hover:text-slate-900 transition text-[11px] flex items-center space-x-1.5">
            <i data-lucide="file-check-2" class="w-3.5 h-3.5 text-sky-600"></i>
            <span>30Q Paper Matrix (Tss.pdf)</span>
          </button>
        </div>
      </div>

      <form method="POST" action="" id="blueprint-form" class="space-y-6" onsubmit="return validateBlueprintForm();">
        <input type="hidden" name="blueprint_id" value="<?php echo (int)($editingBp['id'] ?? 0); ?>">
        <input type="hidden" name="matrix_config" id="matrix_config_input" value="<?php echo htmlspecialchars($editingBp['matrix_config'] ?? ''); ?>">
        <input type="hidden" name="sections_config" id="sections_config_input" value="<?php echo htmlspecialchars($editingBp['sections_config'] ?? ''); ?>">

        <!-- Metadata Parameters Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
          
          <div>
            <label class="block font-bold text-slate-700 mb-1">Unique Blueprint Name *</label>
            <input type="text" name="name" id="bp-name-input" required value="<?php echo htmlspecialchars($editingBp['name'] ?? 'Course 30-Question Paper Blueprint'); ?>" placeholder="e.g. Economics 30Q Paper Blueprint" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-900 shadow-sm focus:ring-2 focus:ring-indigo-500">
          </div>

          <div>
            <label class="block font-bold text-slate-700 mb-1">Attached Course / Subject *</label>
            <select name="paper_code" id="bp-course-select" onchange="syncPublishedQbbBlueprint()" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm focus:ring-2 focus:ring-indigo-500">
              <option value="">-- Select Assigned Course --</option>
              <?php foreach ($courses as $c): ?>
                <?php 
                  $cVal = strtoupper($c['coursecode']);
                  $isSel = ($selectedCourseParam && strtoupper($selectedCourseParam) === $cVal) || (($editingBp['paper_code'] ?? '') === $cVal);
                ?>
                <option value="<?php echo htmlspecialchars($cVal); ?>" data-title="<?php echo htmlspecialchars($c['coursetitle']); ?>" data-dept="<?php echo htmlspecialchars($c['dept_code']); ?>" data-max="<?php echo htmlspecialchars((string)hcc_course_exam_info($c)['exam_marks']); ?>" <?php echo $isSel ? 'selected' : ''; ?>>
                  <?php $cExam = hcc_course_exam_info($c); echo htmlspecialchars($c['coursecode'] . ' - ' . $c['coursetitle'] . ' [' . $cExam['type_label'] . ']'); ?><?php if (isset($recentUploadByCourse[$cVal])): ?> • RECENT BANK: <?php echo (int)$recentUploadByCourse[$cVal]['total_questions']; ?>Q<?php else: ?> • No uploaded bank<?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="course_title" id="bp-course-title-input" value="<?php echo htmlspecialchars($editingBp['course_title'] ?? ''); ?>">
            <div id="recent-bank-hint" class="mt-1 text-[10px] font-bold text-indigo-700">Select a course to load only its uploaded Unit/Sub-Unit structure.</div>
          </div>

          <div>
            <label class="block font-bold text-slate-700 mb-1">Academic Department</label>
            <select name="dept_code" id="bp-dept-select" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm">
              <option value="">All Departments (General)</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?php echo htmlspecialchars($d['code']); ?>" <?php echo (($editingBp['dept_code'] ?? '') === $d['code']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($d['code'] . ' - ' . $d['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 mb-1">Semester *</label>
            <select name="semester" id="bp-semester" onchange="onCourseSelected(document.getElementById('bp-course-select')?.value || '')" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm">
              <?php for ($s=1;$s<=8;$s++): ?>
                <option value="Semester <?php echo $s; ?>" <?php echo (($editingBp['semester'] ?? '') === 'Semester '.$s) ? 'selected' : ''; ?>>Semester <?php echo $s; ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 mb-1">Academic Year *</label>
            <select name="academic_year" id="bp-academic-year" onchange="onCourseSelected(document.getElementById('bp-course-select')?.value || '')" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm">
              <?php foreach (['2026-2027','2025-2026','2024-2025','2023-2024'] as $ay): ?>
                <option value="<?php echo $ay; ?>" <?php echo (($editingBp['academic_year'] ?? DEFAULT_ACADEMIC_YEAR) === $ay) ? 'selected' : ''; ?>><?php echo $ay; ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 mb-1">Exam Type *</label>
            <select name="exam_type" id="bp-exam-type" onchange="onCourseSelected(document.getElementById('bp-course-select')?.value || '')" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm">
              <?php foreach ($examTypesList as $ek=>$ev): ?>
                <option value="<?php echo htmlspecialchars($ek); ?>" <?php echo (($editingBp['exam_type'] ?? 'Odd Semester End Examination') === $ek) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ev); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 mb-1">Target Total Marks *</label>
            <input type="number" name="total_marks" id="bp-marks-input" value="<?php echo htmlspecialchars($editingBp['total_marks'] ?? 75); ?>" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-black text-amber-950 shadow-sm focus:ring-2 focus:ring-indigo-500">
          </div>

        </div>

        <!-- ==================== VIEW 1: 275-QUESTION POOL MATRIX (sample-blueprint.jpeg) ==================== -->
        <div id="view-matrix-275q" hidden style="display:none!important" class="hidden space-y-4">
          
          <!-- Pool Control Bar -->
          <div class="bg-gradient-to-r from-indigo-50 via-slate-50 to-purple-50 border-2 border-indigo-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs shadow-xs">
            <div class="flex items-center space-x-3">
              <div class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center font-black text-xs shadow">
                <i data-lucide="layers" class="w-4 h-4"></i>
              </div>
              <div>
                <span class="font-black text-slate-900 text-sm">275-Question Pool Status:</span>
                <span id="pool-count-badge" class="ml-2 px-3 py-0.5 rounded-full font-mono font-black text-xs bg-emerald-100 text-emerald-950 border border-emerald-400">
                  275 / 275 Questions Configured (100% Balanced)
                </span>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button type="button" onclick="syncUploadedQuestionPool()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-3.5 py-1.5 rounded-full text-[11px] shadow-sm transition flex items-center space-x-1">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                <span>Sync Uploaded Question Pool</span>
              </button>
              <button type="button" onclick="autoFillSample275Q()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-3.5 py-1.5 rounded-full text-[11px] shadow-sm transition flex items-center space-x-1">
                <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                <span>Load Sample Matrix</span>
              </button>
              <button type="button" onclick="addNewUnitRow()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-3 py-1.5 rounded-full text-[11px] shadow-sm transition flex items-center space-x-1">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                <span>+ Add Unit / Topic</span>
              </button>
              <button type="button" onclick="clear275QMatrix()" class="bg-white hover:bg-stone-100 text-slate-700 border border-stone-300 font-bold px-3 py-1.5 rounded-full text-[11px] shadow-sm transition">
                Clear
              </button>
            </div>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <span class="w-3 h-3 rounded-full bg-indigo-600"></span>
              <h4 class="font-extrabold text-slate-900 text-xs uppercase tracking-wider">Master Question Blueprint Matrix (sample-blueprint.jpeg)</h4>
            </div>
            <p class="text-[11px] text-slate-500">Edit cognitive level counts per sub-unit. Row totals and column sums update in real time.</p>
          </div>

          <div class="overflow-x-auto custom-scrollbar-x rounded-2xl border border-stone-300 shadow-sm">
            <table class="w-full text-center border-collapse text-[10px]" id="matrix-275q-table">
              <thead class="bg-stone-900 text-white font-bold uppercase tracking-wider">
                <tr>
                  <th class="p-2 border-r border-stone-700 w-24">Unit</th>
                  <th class="p-2 border-r border-stone-700 w-20">Sub-Unit</th>
                  <th class="p-1.5 border-r border-stone-700" title="MC K1 Remember">MC K1(R)</th>
                  <th class="p-1.5 border-r border-stone-700" title="MC K1 Match Following">MC K1(MATCH)</th>
                  <th class="p-1.5 border-r border-stone-700" title="MC K2 Understand">MC K2(U)</th>
                  <th class="p-1.5 border-r border-stone-700" title="MC K2 Assertion-Reason">MC K2(ASSERT)</th>
                  <th class="p-1.5 border-r border-stone-700" title="MC K3 Apply">MC K3(AP)</th>
                  <th class="p-1.5 border-r border-stone-700" title="VSA K1 Remember">VSA K1(R)</th>
                  <th class="p-1.5 border-r border-stone-700" title="VSA K2 Understand">VSA K2(U)</th>
                  <th class="p-1.5 border-r border-stone-700" title="VSA K3 Apply">VSA K3(AP)</th>
                  <th class="p-1.5 border-r border-stone-700" title="Part B K1 Remember">Part B K1(R)</th>
                  <th class="p-1.5 border-r border-stone-700" title="Part B K2 Understand">Part B K2(U)</th>
                  <th class="p-1.5 border-r border-stone-700" title="Part B K4 Analyze">Part B K4(AN)</th>
                  <th class="p-1.5 border-r border-stone-700" title="Part C K1 Remember">Part C K1(R)</th>
                  <th class="p-1.5 border-r border-stone-700" title="Part C K3 Apply">Part C K3(AP)</th>
                  <th class="p-1.5 border-r border-stone-700" title="Part D K2 Understand">Part D K2(U)</th>
                  <th class="p-1.5 bg-stone-950 text-amber-300 font-bold w-14">TOTAL</th>
                  <th class="p-1.5 bg-stone-950 text-stone-300 w-16">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-stone-200 bg-white" id="matrix-275q-tbody">
                <!-- Rendered dynamically matching sample-blueprint.jpeg with inputs -->
              </tbody>
              <tfoot class="bg-stone-100 font-black text-slate-900 border-t-2 border-stone-400" id="matrix-275q-tfoot">
                <!-- Column totals summing to 275 -->
              </tfoot>
            </table>
          </div>
        </div>

        <!-- ==================== VIEW 2: 30-QUESTION PAPER MATRIX (Tss.pdf) ==================== -->
        <div id="view-matrix-30q" class="space-y-4">
          
          <div class="bg-gradient-to-r from-sky-50 via-indigo-50 to-blue-50 border-2 border-sky-300 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs shadow-xs">
            <div class="flex items-center space-x-3">
              <div class="w-8 h-8 rounded-full bg-sky-600 text-white flex items-center justify-center font-black text-xs">
                <i data-lucide="check" class="w-4 h-4"></i>
              </div>
              <div>
                <span class="font-black text-slate-900 text-sm">30-Question Blueprint Status:</span>
                <span id="picked-questions-count-badge" class="ml-2 px-3 py-0.5 rounded-full font-mono font-black text-xs bg-sky-200 text-sky-950 border border-sky-400">
                  30 / 30 Picked (Compulsory Ready)
                </span>
              </div>
            </div>

            <div class="flex items-center space-x-2">
              <button type="button" id="syncPublishedQbbBtn" onclick="syncPublishedQbbBlueprint()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-3.5 py-1.5 rounded-full text-[11px] shadow-sm transition flex items-center space-x-1">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                <span>Sync Published Question Bank Blueprint</span>
              </button>
              <button type="button" onclick="clearMatrixPicks()" class="bg-white hover:bg-stone-100 text-slate-700 border border-stone-300 font-bold px-3 py-1.5 rounded-full text-[11px] shadow-sm transition">
                Clear Picks
              </button>
            </div>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <span class="w-3 h-3 rounded-full bg-sky-500"></span>
              <h4 class="font-extrabold text-slate-900 text-xs uppercase tracking-wider">Holy Cross 30-Question Paper Blueprint Matrix (Tss.pdf)</h4>
            </div>
            <p class="text-[11px] text-slate-500">Rows are copied exactly from the HOD-published Question Bank Blueprint. Units/Sub-Units, K-Level, CO, Marks, Section and required counts are read from that blueprint; enter the final question number(s) in the last column.</p>
          </div>

          <div class="overflow-x-auto custom-scrollbar-x rounded-2xl border border-stone-300 shadow-sm">
            <table class="w-full text-center border-collapse text-[11px]" id="matrix-30q-table">
              <thead class="bg-stone-900 text-white font-black uppercase text-[10px] tracking-wider">
                <tr>
                  <th class="p-2 border-r border-stone-700 min-w-[85px]">Section</th>
                  <th class="p-2 border-r border-stone-700 min-w-[90px]">Unit</th>
                  <th class="p-2 border-r border-stone-700 min-w-[90px]">Sub-Unit</th>
                  <th class="p-2 border-r border-stone-700 min-w-[120px]">Question Type</th>
                  <th class="p-2 border-r border-stone-700 min-w-[70px]">K-Level</th>
                  <th class="p-2 border-r border-stone-700 min-w-[70px]">CO</th>
                  <th class="p-2 border-r border-stone-700 min-w-[70px]">Marks</th>
                  <th class="p-2 border-r border-stone-700 min-w-[80px]">Required</th>
                  <th class="p-2 border-r border-stone-700 min-w-[90px]">Available</th>
                  <th class="p-2 border-r border-stone-700 min-w-[260px]">Question Number(s)</th>
                  <th class="p-2 bg-stone-950 text-amber-300 min-w-[70px]">Picked</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-stone-200 bg-white" id="matrix-30q-tbody">
                <!-- Rendered dynamically -->
              </tbody>
            </table>
          </div>
        </div>

        <!-- Action Buttons Footer -->
        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-stone-200">
          <div class="flex items-center space-x-2">
            <a href="<?php echo getBaseUrl(); ?>/modules/coe/blueprint.php" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-700 bg-stone-100 hover:bg-stone-200 transition">
              Reset / Cancel
            </a>
          </div>

          <div class="flex items-center space-x-2">
            <button type="submit" name="save_blueprint" class="bg-[#F6C443] hover:bg-[#EAB326] text-slate-950 font-black px-7 py-3 rounded-full shadow-lg flex items-center space-x-2 transition text-xs">
              <i data-lucide="check-circle" class="w-4 h-4"></i>
              <span><?php echo $editingBp ? 'Update Blueprint Matrix' : 'Save & Lock Master Blueprint'; ?></span>
            </button>
          </div>
        </div>

      </form>
    </div>

    <!-- Repository of Configured Blueprints -->
    <div id="saved-blueprints-section" class="bg-white rounded-[28px] shadow-sm border border-stone-200/80 p-6 space-y-4">
      <div class="flex items-center justify-between border-b border-stone-100 pb-3">
        <div class="flex items-center space-x-3">
          <div class="w-8 h-8 rounded-full bg-stone-100 text-slate-900 flex items-center justify-center font-bold text-xs border border-stone-200">
            <i data-lucide="book-marked" class="w-4 h-4"></i>
          </div>
          <div>
            <h3 class="font-extrabold text-slate-900 text-sm">Configured Blueprints Repository (<?php echo $totalBlueprints; ?>)</h3>
            <p class="text-[11px] text-slate-500">Official active matrices available for paper generation and staff upload validation.</p>
          </div>
        </div>
      </div>

      <div class="overflow-x-auto custom-scrollbar-x text-xs">
        <table class="w-full text-left border-collapse min-w-[700px]">
          <thead class="bg-stone-50 text-slate-700 font-black uppercase text-[11px] border-b border-stone-200">
            <tr>
              <th class="py-3 px-3">ID</th>
              <th class="py-3 px-3">Blueprint Name</th>
              <th class="py-3 px-3">Course Code</th>
              <th class="py-3 px-3">Department</th>
              <th class="py-3 px-3 text-center">Marks</th>
              <th class="py-3 px-3 text-center">Duration</th>
              <th class="py-3 px-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100 font-medium">
            <?php if (empty($blueprintsList)): ?>
              <tr><td colspan="7" class="py-8 text-center text-slate-400">No blueprints configured yet.</td></tr>
            <?php else: ?>
              <?php foreach ($blueprintsList as $bp): ?>
                <tr class="hover:bg-stone-50/80 transition">
                  <td class="py-3 px-3 font-mono font-bold text-slate-500">#<?php echo $bp['id']; ?></td>
                  <td class="py-3 px-3 font-bold text-slate-900">
                    <a href="?edit_id=<?php echo $bp['id']; ?>" class="hover:text-indigo-600 transition">
                      <?php echo htmlspecialchars($bp['name']); ?>
                    </a>
                  </td>
                  <td class="py-3 px-3 font-mono font-bold text-indigo-700"><?php echo htmlspecialchars($bp['paper_code'] ?: 'Institutional'); ?></td>
                  <td class="py-3 px-3 text-slate-600"><?php echo htmlspecialchars($bp['dept_code'] ?: 'All Depts'); ?></td>
                  <td class="py-3 px-3 text-center font-bold text-amber-900"><?php echo htmlspecialchars($bp['total_marks']); ?>M</td>
                  <td class="py-3 px-3 text-center text-slate-600"><?php echo htmlspecialchars($bp['duration_hours'] ?: '3 Hours'); ?></td>
                  <td class="py-3 px-3 text-right">
                    <div class="flex items-center justify-end space-x-2">
                      <a href="?edit_id=<?php echo $bp['id']; ?>" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold px-2.5 py-1 rounded-full text-[11px]">Edit</a>
                      <?php if ($isCoe || $isHod): ?>
                        <form method="POST" action="" onsubmit="return confirm('Delete this blueprint permanently?');" class="inline">
                          <input type="hidden" name="action" value="delete_blueprint">
                          <input type="hidden" name="blueprint_id" value="<?php echo $bp['id']; ?>">
                          <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 font-bold px-2.5 py-1 rounded-full text-[11px]">Delete</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<script>
// Default 30-Question Matrix matching Holy Cross Tss.pdf
const default30QMatrix = [
  { unit: 'Unit I', sub: '1.1', mcq_k1: '1', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '11', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '21.a', essay: '', comp: '' },
  { unit: 'Unit I', sub: '1.2', mcq_k1: '', mcq_k2: '2', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '12', vsa_k3: '', vsa_k4: '', para: '21.b', essay: '', comp: '' },
  { unit: 'Unit I', sub: '1.3', mcq_k1: '', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '', essay: '26', comp: '' },

  { unit: 'Unit II', sub: '2.1', mcq_k1: '3', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '13', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '22.a', essay: '', comp: '' },
  { unit: 'Unit II', sub: '2.2', mcq_k1: '', mcq_k2: '4', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '14', vsa_k3: '', vsa_k4: '', para: '22.b', essay: '', comp: '' },
  { unit: 'Unit II', sub: '2.3', mcq_k1: '', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '', essay: '27', comp: '' },

  { unit: 'Unit III', sub: '3.1', mcq_k1: '5', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '15', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '23.a', essay: '', comp: '' },
  { unit: 'Unit III', sub: '3.2', mcq_k1: '', mcq_k2: '6', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '16', vsa_k3: '', vsa_k4: '', para: '23.b', essay: '', comp: '' },
  { unit: 'Unit III', sub: '3.3', mcq_k1: '', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '', essay: '28', comp: '' },

  { unit: 'Unit IV', sub: '4.1', mcq_k1: '7', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '17', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '24.a', essay: '', comp: '' },
  { unit: 'Unit IV', sub: '4.2', mcq_k1: '', mcq_k2: '8', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '18', vsa_k3: '', vsa_k4: '', para: '24.b', essay: '', comp: '' },
  { unit: 'Unit IV', sub: '4.3', mcq_k1: '', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '', essay: '', comp: '' },

  { unit: 'Unit V', sub: '5.1', mcq_k1: '9', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '19', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '25.a', essay: '', comp: '' },
  { unit: 'Unit V', sub: '5.2', mcq_k1: '', mcq_k2: '10', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '20', vsa_k3: '', vsa_k4: '', para: '25.b', essay: '', comp: '' },
  { unit: 'Unit V', sub: '5.3', mcq_k1: '', mcq_k2: '', mcq_k3: '', ar_k2: '', match_k1: '', vsa_k1: '', vsa_k2: '', vsa_k3: '', vsa_k4: '', para: '', essay: '', comp: '29' }
];

// Default 275-Question Pool Matrix matching sample-blueprint.jpeg (Allied Business Economics)
const default275QMatrix = [
  { id: '1', unit: 'I', sub: '1.1', mc_k1_r: 1, mc_k1_match: 1, mc_k2_u: 1, mc_k2_assert: 2, mc_k3_ap: 3, vsa_k1_r: 1, vsa_k2_u: 1, vsa_k3_ap: 6, b_k1_r: 1, b_k2_u: 1, b_k4_an: 2, c_k1_r: 1, c_k3_ap: 2, d_k2_u: 1 },
  { id: '2', unit: 'I', sub: '1.2', mc_k1_r: 5, mc_k1_match: 1, mc_k2_u: 4, mc_k2_assert: 1, mc_k3_ap: 1, vsa_k1_r: 4, vsa_k2_u: 2, vsa_k3_ap: 2, b_k1_r: 2, b_k2_u: 2, b_k4_an: 1, c_k1_r: 0, c_k3_ap: 1, d_k2_u: 1 },
  { id: '3', unit: 'I', sub: '1.3', mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 1, mc_k2_assert: 0, mc_k3_ap: 1, vsa_k1_r: 2, vsa_k2_u: 4, vsa_k3_ap: 2, b_k1_r: 1, b_k2_u: 1, b_k4_an: 0, c_k1_r: 1, c_k3_ap: 0, d_k2_u: 1 },

  { id: '4', unit: 'II', sub: '2.1', mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 2, mc_k2_assert: 1, mc_k3_ap: 3, vsa_k1_r: 4, vsa_k2_u: 3, vsa_k3_ap: 2, b_k1_r: 2, b_k2_u: 2, b_k4_an: 1, c_k1_r: 0, c_k3_ap: 1, d_k2_u: 1 },
  { id: '5', unit: 'II', sub: '2.2', mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 2, mc_k2_assert: 1, mc_k3_ap: 1, vsa_k1_r: 3, vsa_k2_u: 2, vsa_k3_ap: 5, b_k1_r: 1, b_k2_u: 1, b_k4_an: 0, c_k1_r: 1, c_k3_ap: 1, d_k2_u: 1 },
  { id: '6', unit: 'II', sub: '2.3', mc_k1_r: 4, mc_k1_match: 1, mc_k2_u: 2, mc_k2_assert: 2, mc_k3_ap: 3, vsa_k1_r: 2, vsa_k2_u: 3, vsa_k3_ap: 1, b_k1_r: 2, b_k2_u: 1, b_k4_an: 1, c_k1_r: 0, c_k3_ap: 1, d_k2_u: 1 },

  { id: '7', unit: 'III', sub: '3.1', mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 1, mc_k2_assert: 0, mc_k3_ap: 1, vsa_k1_r: 3, vsa_k2_u: 2, vsa_k3_ap: 3, b_k1_r: 1, b_k2_u: 2, b_k4_an: 1, c_k1_r: 1, c_k3_ap: 1, d_k2_u: 1 },
  { id: '8', unit: 'III', sub: '3.2', mc_k1_r: 5, mc_k1_match: 2, mc_k2_u: 4, mc_k2_assert: 1, mc_k3_ap: 2, vsa_k1_r: 3, vsa_k2_u: 2, vsa_k3_ap: 1, b_k1_r: 1, b_k2_u: 2, b_k4_an: 2, c_k1_r: 0, c_k3_ap: 1, d_k2_u: 1 },
  { id: '9', unit: 'III', sub: '3.3', mc_k1_r: 1, mc_k1_match: 0, mc_k2_u: 1, mc_k2_assert: 1, mc_k3_ap: 1, vsa_k1_r: 1, vsa_k2_u: 3, vsa_k3_ap: 2, b_k1_r: 3, b_k2_u: 1, b_k4_an: 0, c_k1_r: 1, c_k3_ap: 0, d_k2_u: 1 },

  { id: '10', unit: 'IV', sub: '4.1', mc_k1_r: 3, mc_k1_match: 1, mc_k2_u: 3, mc_k2_assert: 2, mc_k3_ap: 1, vsa_k1_r: 4, vsa_k2_u: 2, vsa_k3_ap: 4, b_k1_r: 2, b_k2_u: 1, b_k4_an: 2, c_k1_r: 1, c_k3_ap: 1, d_k2_u: 1 },
  { id: '11', unit: 'IV', sub: '4.2', mc_k1_r: 1, mc_k1_match: 1, mc_k2_u: 2, mc_k2_assert: 0, mc_k3_ap: 3, vsa_k1_r: 3, vsa_k2_u: 3, vsa_k3_ap: 2, b_k1_r: 1, b_k2_u: 1, b_k4_an: 0, c_k1_r: 0, c_k3_ap: 1, d_k2_u: 1 },
  { id: '12', unit: 'IV', sub: '4.3', mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 1, mc_k2_assert: 0, mc_k3_ap: 1, vsa_k1_r: 3, vsa_k2_u: 2, vsa_k3_ap: 1, b_k1_r: 1, b_k2_u: 3, b_k4_an: 0, c_k1_r: 0, c_k3_ap: 0, d_k2_u: 1 },

  { id: '13', unit: 'V', sub: '5.1', mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 3, mc_k2_assert: 1, mc_k3_ap: 1, vsa_k1_r: 2, vsa_k2_u: 2, vsa_k3_ap: 2, b_k1_r: 1, b_k2_u: 1, b_k4_an: 0, c_k1_r: 0, c_k3_ap: 0, d_k2_u: 1 },
  { id: '14', unit: 'V', sub: '5.2', mc_k1_r: 1, mc_k1_match: 0, mc_k2_u: 2, mc_k2_assert: 1, mc_k3_ap: 2, vsa_k1_r: 1, vsa_k2_u: 3, vsa_k3_ap: 1, b_k1_r: 1, b_k2_u: 1, b_k4_an: 1, c_k1_r: 1, c_k3_ap: 1, d_k2_u: 1 },
  { id: '15', unit: 'V', sub: '5.3', mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 1, mc_k2_assert: 0, mc_k3_ap: 1, vsa_k1_r: 2, vsa_k2_u: 2, vsa_k3_ap: 2, b_k1_r: 1, b_k2_u: 1, b_k4_an: 0, c_k1_r: 0, c_k3_ap: 0, d_k2_u: 1 }
];

let activeMatrixData30Q = [];
let syncedQbb = {found:false, blueprint_id:0, pool_total:0, units:{}, sub_units:{}};
let activeMatrixData275Q = <?php echo json_encode(!empty($editingBp['matrix_config']) ? 'SAVED' : 'EMPTY'); ?> === 'SAVED' ? JSON.parse(JSON.stringify(default275QMatrix)) : [];

// Load existing config if editing
<?php if (!empty($editingBp['matrix_config'])): ?>
try {
  const savedCfg = <?php echo json_encode(json_decode($editingBp['matrix_config'], true) ?: []); ?>;
  if (savedCfg.matrix_275q && Array.isArray(savedCfg.matrix_275q) && savedCfg.matrix_275q.length > 0) {
    activeMatrixData275Q = savedCfg.matrix_275q;
  }
  if (savedCfg.matrix_30q && Array.isArray(savedCfg.matrix_30q) && savedCfg.matrix_30q.length > 0) {
    activeMatrixData30Q = savedCfg.matrix_30q;
  }
} catch (e) {
  console.warn('Error parsing saved matrix config:', e);
}
<?php endif; ?>

const colKeys275 = ['mc_k1_r', 'mc_k1_match', 'mc_k2_u', 'mc_k2_assert', 'mc_k3_ap', 'vsa_k1_r', 'vsa_k2_u', 'vsa_k3_ap', 'b_k1_r', 'b_k2_u', 'b_k4_an', 'c_k1_r', 'c_k3_ap', 'd_k2_u'];

const hasSavedBlueprintMatrix = <?php echo json_encode(!empty($editingBp['matrix_config'])); ?>;
document.addEventListener('DOMContentLoaded', () => {
  render275QMatrix();
  render30QMatrix();
  updatePickedStats();
  const selected = document.getElementById('bp-course-select')?.value || '';
  if (selected && !hasSavedBlueprintMatrix) syncPublishedQbbBlueprint();
  if (typeof lucide !== 'undefined') lucide.createIcons();
});

function switchBlueprintView(view) {
  const v30 = document.getElementById('view-matrix-30q');
  const v275 = document.getElementById('view-matrix-275q');
  const b30 = document.getElementById('btn-tab-30q');
  const b275 = document.getElementById('btn-tab-275q');

  if (view === '30q') {
    v30.classList.remove('hidden');
    v275.classList.add('hidden');
    b30.className = 'px-3.5 py-1.5 rounded-full bg-[#1C1D21] text-white shadow transition text-[11px] flex items-center space-x-1.5';
    b275.className = 'px-3.5 py-1.5 rounded-full text-slate-700 hover:text-slate-900 transition text-[11px] flex items-center space-x-1.5';
  } else {
    v30.classList.add('hidden');
    v275.classList.remove('hidden');
    b275.className = 'px-3.5 py-1.5 rounded-full bg-[#1C1D21] text-white shadow transition text-[11px] flex items-center space-x-1.5';
    b30.className = 'px-3.5 py-1.5 rounded-full text-slate-700 hover:text-slate-900 transition text-[11px] flex items-center space-x-1.5';
  }
}

async function syncUploadedQuestionPool() {
  const select = document.getElementById('bp-course-select');
  const val = select?.value || '';
  if (!val) {
    Swal.fire('Course Required', 'Select a course before syncing the uploaded question pool.', 'warning');
    return;
  }
  const btn = Array.from(document.querySelectorAll('button')).find(b => (b.textContent || '').includes('Sync Uploaded Question Pool'));
  const original = btn ? btn.innerHTML : '';
  if (btn) { btn.disabled = true; btn.innerHTML = '<i data-lucide="loader-circle" class="w-3.5 h-3.5 animate-spin"></i><span>Syncing…</span>'; }
  try {
    await onCourseSelected(val);
    const badge = document.getElementById('pool-count-badge');
    const count = activeMatrixData275Q.reduce((sum, row) => sum + Object.keys(row).filter(k => k !== 'unit' && k !== 'sub').reduce((s,k) => s + (parseInt(row[k]) || 0), 0), 0);
    if (badge && activeMatrixData275Q.length) badge.textContent = `${activeMatrixData275Q.length} uploaded sub-units synced`;
    Swal.fire({toast:true,position:'top-end',icon: activeMatrixData275Q.length ? 'success' : 'warning',title: activeMatrixData275Q.length ? 'Question pool synced' : 'No verified question pool found',timer:1800,showConfirmButton:false});
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = original; if (window.lucide) lucide.createIcons(); }
  }
}

async function onCourseSelected(val) {
  const select = document.getElementById('bp-course-select');
  const opt = select.options[select.selectedIndex];
  if (!opt || !val) return;
  document.getElementById('bp-course-title-input').value = opt.dataset.title || '';
  if (opt.dataset.dept) document.getElementById('bp-dept-select').value = opt.dataset.dept;
  if (opt.dataset.max) document.getElementById('bp-marks-input').value = opt.dataset.max;

  const hint = document.getElementById('recent-bank-hint');
  if (hint) hint.textContent = 'Syncing cumulative uploaded question-bank Unit/Sub-Unit structure…';
  try {
    const semester = document.getElementById('bp-semester')?.value || '';
    const academicYear = document.getElementById('bp-academic-year')?.value || '';
    const examType = document.getElementById('bp-exam-type')?.value || '';
    const r = await fetch('<?php echo getBaseUrl(); ?>/api/get_bank_units.php?paper_code=' + encodeURIComponent(val) + '&semester=' + encodeURIComponent(semester) + '&academic_year=' + encodeURIComponent(academicYear) + '&exam_type=' + encodeURIComponent(examType));
    const data = await r.json();
    if (!data.found) {
      activeMatrixData275Q = [];
      if (hint) hint.textContent = 'No question bank uploaded yet for ' + val + '. Upload questions before creating a course-specific blueprint.';
    } else {
      const rows = (data.sub_units || []).sort((a,b)=>String(a.sub_unit).localeCompare(String(b.sub_unit),undefined,{numeric:true}));
      activeMatrixData275Q = rows.map(x => ({unit: 'Unit '+x.unit, sub: x.sub_unit, mc_k1_r:0,mc_k1_match:0,mc_k2_u:0,mc_k2_assert:0,mc_k3_ap:0,vsa_k1_r:0,vsa_k2_u:0,vsa_k3_ap:0,b_k1_r:0,b_k2_u:0,b_k4_an:0,c_k1_r:0,c_k3_ap:0,d_k2_u:0}));
      if (hint) hint.textContent = data.total_questions + ' cumulative questions found across ' + (data.bank_count || 1) + ' verified/submitted bank(s) for ' + val + '. Blueprint rows are based on the actual uploaded sub-units.';
    }
    render275QMatrix();
  } catch (e) {
    if (hint) hint.textContent = 'Could not load uploaded question-bank structure. Please check the course bank.';
  }
}

// -------------------------------------------------------------
// 275Q POOL MATRIX FUNCTIONS
// -------------------------------------------------------------
function render275QMatrix() {
  const tbody = document.getElementById('matrix-275q-tbody');
  const tfoot = document.getElementById('matrix-275q-tfoot');
  if (!tbody || !tfoot) return;

  tbody.innerHTML = '';
  const colTotals = {};
  colKeys275.forEach(k => colTotals[k] = 0);
  let grandTotal = 0;

  activeMatrixData275Q.forEach((row, rIdx) => {
    const tr = document.createElement('tr');
    tr.className = 'hover:bg-indigo-50/40 transition border-b border-stone-200';

    let rowSum = 0;

    let cells = `
      <td class="p-1 border-r border-stone-200 bg-stone-50 font-bold">
        <input type="text" value="${row.unit || 'I'}" onchange="update275Unit(${rIdx}, this.value)" class="w-14 text-center font-bold text-slate-800 bg-transparent border border-transparent hover:border-stone-300 rounded focus:bg-white focus:ring-1 focus:ring-indigo-500 text-[11px]">
      </td>
      <td class="p-1 border-r border-stone-200 bg-stone-50 font-mono font-bold">
        <input type="text" value="${row.sub || (rIdx+1)}" onchange="update275SubUnit(${rIdx}, this.value)" class="w-14 text-center font-mono font-bold text-indigo-700 bg-transparent border border-transparent hover:border-stone-300 rounded focus:bg-white focus:ring-1 focus:ring-indigo-500 text-[11px]">
      </td>
    `;

    colKeys275.forEach(k => {
      const val = parseInt(row[k]) || 0;
      rowSum += val;
      colTotals[k] += val;

      const cellBg = val > 0 ? 'bg-indigo-50/70 border-indigo-300 text-indigo-950 font-black' : 'bg-white border-stone-200 text-slate-400 font-medium';

      cells += `
        <td class="p-1 border-r border-stone-200">
          <input type="number" min="0" max="99" value="${val}" onchange="update275Cell(${rIdx}, '${k}', this.value)" class="w-11 h-7 text-center rounded-lg border ${cellBg} focus:ring-2 focus:ring-indigo-500 font-mono text-[11px] transition">
        </td>
      `;
    });

    grandTotal += rowSum;
    cells += `
      <td class="p-1.5 font-black font-mono text-amber-900 bg-amber-50/50 border-r border-stone-200 text-xs" id="row-275-total-${rIdx}">${rowSum}</td>
      <td class="p-1 text-center">
        <div class="flex items-center justify-center space-x-1">
          <button type="button" onclick="insertSubUnitRow(${rIdx})" title="Insert Sub-Unit below" class="w-6 h-6 rounded-md bg-stone-100 hover:bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs">+</button>
          <button type="button" onclick="removeSubUnitRow(${rIdx})" title="Remove Sub-Unit" class="w-6 h-6 rounded-md bg-stone-100 hover:bg-red-100 text-red-600 flex items-center justify-center font-bold text-xs">×</button>
        </div>
      </td>
    `;

    tr.innerHTML = cells;
    tbody.appendChild(tr);
  });

  // Footer Totals Row
  let footHtml = `
    <tr>
      <td colspan="2" class="p-2 font-black uppercase text-slate-900 border-r border-stone-300 text-right bg-stone-200">COLUMN TOTALS</td>
  `;

  colKeys275.forEach(k => {
    footHtml += `<td class="p-2 font-mono font-black text-indigo-950 border-r border-stone-300 bg-stone-100">${colTotals[k]}</td>`;
  });

  footHtml += `
      <td class="p-2 font-mono font-black text-amber-950 bg-amber-200 text-xs">${grandTotal}</td>
      <td class="p-1 bg-stone-100"></td>
    </tr>
  `;
  tfoot.innerHTML = footHtml;

  update275QStats(grandTotal);
}

function update275Cell(rIdx, key, val) {
  if (activeMatrixData275Q[rIdx]) {
    activeMatrixData275Q[rIdx][key] = Math.max(0, parseInt(val) || 0);
    render275QMatrix();
  }
}

function update275Unit(rIdx, val) {
  if (activeMatrixData275Q[rIdx]) {
    activeMatrixData275Q[rIdx].unit = val.trim();
    saveMatrixState();
  }
}

function update275SubUnit(rIdx, val) {
  if (activeMatrixData275Q[rIdx]) {
    activeMatrixData275Q[rIdx].sub = val.trim();
    saveMatrixState();
  }
}

function insertSubUnitRow(rIdx) {
  const current = activeMatrixData275Q[rIdx];
  const newSub = current ? `${current.unit}.${parseInt(current.sub.split('.')[1] || '1') + 1}` : '1.1';
  const newRow = {
    id: Date.now().toString(),
    unit: current ? current.unit : 'I',
    sub: newSub,
    mc_k1_r: 1, mc_k1_match: 1, mc_k2_u: 1, mc_k2_assert: 1, mc_k3_ap: 1,
    vsa_k1_r: 2, vsa_k2_u: 2, vsa_k3_ap: 2,
    b_k1_r: 1, b_k2_u: 1, b_k4_an: 1,
    c_k1_r: 1, c_k3_ap: 1,
    d_k2_u: 1
  };
  activeMatrixData275Q.splice(rIdx + 1, 0, newRow);
  render275QMatrix();
}

function removeSubUnitRow(rIdx) {
  if (activeMatrixData275Q.length <= 1) {
    Swal.fire('Minimum Row', 'At least one Unit/Sub-Unit row is required in the Blueprint.', 'warning');
    return;
  }
  activeMatrixData275Q.splice(rIdx, 1);
  render275QMatrix();
}

function addNewUnitRow() {
  const nextUnit = 'Unit VI';
  const newRow = {
    id: Date.now().toString(),
    unit: nextUnit,
    sub: '6.1',
    mc_k1_r: 2, mc_k1_match: 1, mc_k2_u: 2, mc_k2_assert: 1, mc_k3_ap: 2,
    vsa_k1_r: 2, vsa_k2_u: 2, vsa_k3_ap: 2,
    b_k1_r: 1, b_k2_u: 1, b_k4_an: 1,
    c_k1_r: 1, c_k3_ap: 1,
    d_k2_u: 1
  };
  activeMatrixData275Q.push(newRow);
  render275QMatrix();
}

function autoFillSample275Q() {
  activeMatrixData275Q = JSON.parse(JSON.stringify(default275QMatrix));
  render275QMatrix();
  Swal.fire({
    toast: true,
    position: 'top-end',
    icon: 'success',
    title: 'Loaded sample-blueprint.jpeg (275-Question Pool)',
    timer: 1500,
    showConfirmButton: false
  });
}

function clear275QMatrix() {
  activeMatrixData275Q.forEach(row => {
    colKeys275.forEach(k => row[k] = 0);
  });
  render275QMatrix();
}

function update275QStats(grandTotal) {
  if (grandTotal === undefined) {
    grandTotal = 0;
    activeMatrixData275Q.forEach(row => {
      colKeys275.forEach(k => grandTotal += (parseInt(row[k]) || 0));
    });
  }

  const badge = document.getElementById('pool-count-badge');
  if (badge) {
    if (grandTotal === 275) {
      badge.textContent = `275 / 275 Questions (100% Balanced Pool)`;
      badge.className = 'ml-2 px-3 py-0.5 rounded-full font-mono font-black text-xs bg-emerald-100 text-emerald-950 border border-emerald-400';
    } else {
      badge.textContent = `${grandTotal} Questions Configured (Custom Pool)`;
      badge.className = 'ml-2 px-3 py-0.5 rounded-full font-mono font-black text-xs bg-indigo-100 text-indigo-950 border border-indigo-400';
    }
  }

  saveMatrixState(grandTotal);
}

// -------------------------------------------------------------
// 30Q PAPER MATRIX FUNCTIONS
// -------------------------------------------------------------
function render30QMatrix() {
  const tbody=document.getElementById('matrix-30q-tbody'); if(!tbody)return;
  tbody.innerHTML='';
  if(!activeMatrixData30Q.length){
    tbody.innerHTML='<tr><td colspan="11" class="p-8 text-center text-slate-500 font-bold">Select a course and click <strong>Sync Published Question Bank Blueprint</strong>. Only an HOD-published Question Bank Blueprint can populate this matrix.</td></tr>';
    return;
  }
  activeMatrixData30Q.forEach((row,rIdx)=>{
    const required=Number(row.required_count||0), available=Number(row.available_subunit||0);
    const picked=String(row.question_numbers||'').split(',').map(x=>x.trim()).filter(Boolean);
    const shortage=required>available;
    const tooMany=picked.length>required;
    const tr=document.createElement('tr');
    tr.className='border-b border-stone-200 '+(shortage?'bg-rose-50':tooMany?'bg-amber-50':'hover:bg-stone-50');
    tr.innerHTML=`
      <td class="p-2 font-black ${shortage?'text-rose-700':''}">${esc30(row.section||'')}</td>
      <td class="p-2 font-bold">Unit ${esc30(row.unit||'')}</td>
      <td class="p-2 font-mono font-bold text-indigo-700">${esc30(row.sub_unit||'')}</td>
      <td class="p-2">${esc30(row.question_type||'')}</td>
      <td class="p-2 font-black">${esc30(row.k_level||'')}</td>
      <td class="p-2 font-black">${esc30(row.co_level||'')}</td>
      <td class="p-2 font-black">${Number(row.marks||0)}</td>
      <td class="p-2 font-black">${required}</td>
      <td class="p-2 font-black ${shortage?'text-rose-700':'text-emerald-700'}">${available}</td>
      <td class="p-1.5"><input type="text" value="${esc30(row.question_numbers||'')}" oninput="update30QCell(${rIdx},'question_numbers',this.value)" placeholder="e.g. 1, 11 or 21.a, 21.b" class="w-full min-w-[240px] h-9 px-3 text-xs rounded-xl border ${shortage||tooMany?'border-rose-400 bg-white':'border-stone-300 bg-white'} focus:ring-2 focus:ring-sky-500 font-mono"></td>
      <td class="p-2 font-black ${tooMany?'text-rose-700':'text-slate-900'}">${picked.length}/${required}</td>`;
    tbody.appendChild(tr);
  });
}

function esc30(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

async function syncPublishedQbbBlueprint(){
  const course=document.getElementById('bp-course-select')?.value||'';
  if(!course){Swal.fire('Course Required','Select a course before syncing the published Question Bank Blueprint.','warning');return false;}
  const btn=document.getElementById('syncPublishedQbbBtn'); const old=btn?.innerHTML||'';
  if(btn){btn.disabled=true;btn.innerHTML='<span>Syncing…</span>';}
  try{
    const semester=document.getElementById('bp-semester')?.value||'';
    const academicYear=document.getElementById('bp-academic-year')?.value||'';
    const examType=document.getElementById('bp-exam-type')?.value||'';
    const url='<?php echo getBaseUrl(); ?>/api/get_question_bank_blueprint.php?paper_code='+encodeURIComponent(course)+'&semester='+encodeURIComponent(semester)+'&academic_year='+encodeURIComponent(academicYear)+'&exam_type='+encodeURIComponent(examType);
    const res=await fetch(url); const data=await res.json();
    if(!data.found){activeMatrixData30Q=[];syncedQbb={found:false};render30QMatrix();updatePickedStats();Swal.fire('Blueprint Not Published','The HOD has not published a Question Bank Blueprint for '+course+' yet.','warning');return false;}
    syncedQbb=data;
    activeMatrixData30Q=(data.rows||[]).map(r=>({...r,question_numbers:''}));
    const shortage=(data.rows||[]).filter(r=>Number(r.required_count||0)>Number(r.available_subunit||0));
    render30QMatrix();updatePickedStats();
    const badge=document.getElementById('picked-questions-count-badge'); if(badge) badge.textContent=`0 / 30 Questions Picked • ${data.rows.length} blueprint rows`;
    if(shortage.length){
      Swal.fire({icon:'error',title:'Question Pool Shortage',html:'<div class="text-left text-xs">'+shortage.slice(0,8).map(r=>`<div><strong>${esc30(r.sub_unit)}</strong>: blueprint requires <strong>${r.required_count}</strong>, but cumulative pool contains only <strong>${r.available_subunit}</strong>.</div>`).join('')+'</div>'});
    }else{
      Swal.fire({toast:true,position:'top-end',icon:'success',title:'Published Question Bank Blueprint synced',text:`${data.rows.length} rows • ${data.pool_total} cumulative questions`,timer:2200,showConfirmButton:false});
    }
    return true;
  }catch(e){Swal.fire('Sync Failed',e.message||'Could not load the published HOD blueprint.','error');return false;}
  finally{if(btn){btn.disabled=false;btn.innerHTML=old;if(window.lucide)lucide.createIcons();}}
}

function update30QCell(rIdx,col,val){
  if(activeMatrixData30Q[rIdx]){activeMatrixData30Q[rIdx][col]=String(val||'').trim();render30QMatrix();updatePickedStats();}
}

function updatePickedStats(){
  let totalPicked=0; const seen={}; const unitSelected={}; const subSelected={}; let errors=[];
  activeMatrixData30Q.forEach((row,i)=>{
    const vals=String(row.question_numbers||'').split(',').map(x=>x.trim()).filter(Boolean);
    totalPicked+=vals.length;
    const u=String(row.unit||''); const su=String(row.sub_unit||'');
    unitSelected[u]=(unitSelected[u]||0)+vals.length; subSelected[su]=(subSelected[su]||0)+vals.length;
    if(vals.length>Number(row.required_count||0)) errors.push(`Row ${i+1} (${row.sub_unit}) allows ${row.required_count} but ${vals.length} were entered.`);
    vals.forEach(v=>{if(seen[v])errors.push(`Question ${v} is selected more than once.`);seen[v]=1;});
  });
  Object.entries(unitSelected).forEach(([u,c])=>{const a=Number(syncedQbb.units?.[u]||0);if(c>a)errors.push(`Unit ${u}: ${c} questions selected, but the cumulative pool contains only ${a}.`);});
  Object.entries(subSelected).forEach(([su,c])=>{const a=Number(syncedQbb.sub_units?.[su]||0);if(c>a)errors.push(`Sub-Unit ${su}: ${c} questions selected, but the cumulative pool contains only ${a}.`);});
  const badge=document.getElementById('picked-questions-count-badge');
  if(badge){badge.textContent=`${totalPicked} / 30 Questions Picked`+(errors.length?' • validation errors':'');badge.className='ml-2 px-3 py-0.5 rounded-full font-mono font-black text-xs '+(errors.length?'bg-rose-100 text-rose-950 border border-rose-400':totalPicked===30?'bg-emerald-100 text-emerald-950 border border-emerald-400':'bg-amber-100 text-amber-950 border border-amber-400');}
  saveMatrixState();
  return {totalPicked,errors};
}

function autoFillStandard30Q(){ return syncPublishedQbbBlueprint(); }

function clearMatrixPicks(){
  activeMatrixData30Q.forEach(row=>row.question_numbers='');
  render30QMatrix();updatePickedStats();
}

function saveMatrixState(grandTotal) {
  const matrixInput = document.getElementById('matrix_config_input');
  if (matrixInput) {
    matrixInput.value = JSON.stringify({
      matrix_30q: activeMatrixData30Q,
      source: 'HOD_PUBLISHED_QUESTION_BANK_BLUEPRINT',
      qbb_blueprint_id: syncedQbb.blueprint_id || 0,
      total_pool_questions: syncedQbb.pool_total || 0,
      updated_at: new Date().toISOString()
    });
  }
}

function validateBlueprintForm() {
  const name = document.getElementById('bp-name-input')?.value.trim();
  if (!name) {
    Swal.fire('Name Required', 'Please enter a Unique Blueprint Name.', 'warning');
    return false;
  }
  const result=updatePickedStats();
  if(!syncedQbb.found){ Swal.fire('Sync Required','Sync the published HOD Question Bank Blueprint before saving.','warning'); return false; }
  if(result.errors.length){ Swal.fire({icon:'error',title:'Blueprint Validation Failed',html:'<div class=\"text-left text-xs space-y-1\">'+result.errors.slice(0,10).map(esc30).join('<br>')+'</div>'}); return false; }
  if(result.totalPicked!==30){ Swal.fire('30 Questions Required',`The 30-question blueprint currently has ${result.totalPicked} picked questions. It must contain exactly 30.`,'warning'); return false; }
  for(const row of activeMatrixData30Q){ if(Number(row.required_count||0)>Number(row.available_subunit||0)){ Swal.fire('Question Pool Shortage',`${row.sub_unit} requires ${row.required_count}, but the cumulative pool contains only ${row.available_subunit}.`,'error'); return false; } }
  saveMatrixState();
  return true;
}

function exportBlueprintJson() {
  saveMatrixState();
  const name = document.getElementById('bp-name-input')?.value.trim() || 'HCC_Blueprint';
  const data = {
    institution: 'Holy Cross College (Autonomous), Tiruchirappalli',
    blueprint_name: name,
    course_code: document.getElementById('bp-course-select')?.value || '',
    course_title: document.getElementById('bp-course-title-input')?.value || '',
    dept_code: document.getElementById('bp-dept-select')?.value || '',
    total_marks: document.getElementById('bp-marks-input')?.value || 75,
    matrix_30q: activeMatrixData30Q,
    source: 'HOD_PUBLISHED_QUESTION_BANK_BLUEPRINT',
    qbb_blueprint_id: syncedQbb.blueprint_id || 0
  };

  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name.replace(/[^a-zA-Z0-9_-]/g, '_') + '_Blueprint.json';
  a.click();
}
document.addEventListener('DOMContentLoaded', () => {
  const course = document.getElementById('bp-course-select');
  if (course && course.value) {
    setTimeout(() => syncPublishedQbbBlueprint(), 150);
  }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
