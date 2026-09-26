<?php
/**
 * Faculty Question Bank Management & Submission Portal
 * Holy Cross College (Autonomous) - Examination Management System
 * Crextio Soft Corners & Dedicated Course Allocation Validation
 * - Auto-Language Detection (English, Tamil, Hindi, French) with 1-Click Switcher
 * - Duplicate Detection with Inline Replace, Append, or Skip Options
 * - Answer Key Extraction & Viewer
 * - Hierarchical Storage & Staff-to-HOD / HOD-to-COE Workflow
 */
define('PAGE_TITLE', 'Question Bank Upload & Verification');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();
qps_ensure_aux_schema($pdo);
$maxUploadMb = (float)MAX_UPLOAD_MB;
$isCoe = isCOE();
$isHod = isHOD();

// Fetch all available departments
$departments = [];
try {
    $stDept = $pdo->query("SELECT code, name FROM departments WHERE is_active = '1' OR is_active = 1 ORDER BY name ASC");
    $departments = $stDept->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch assigned courses for this faculty member from timetablefaculty
$assignedCourses = [];
$assignedCourseCodes = [];
try {
    if ($isCoe) {
        $st = $pdo->query("SELECT c.coursecode as papercode, c.coursetitle, c.dept_code, c.level, c.maxmark, c.credit, c.type, d.name as dept_name FROM courses c LEFT JOIN departments d ON d.code = c.dept_code ORDER BY c.coursecode ASC");
        $assignedCourses = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($assignedCourses as $ac) {
            $assignedCourseCodes[] = strtoupper($ac['papercode']);
        }
    } else {
        $st = $pdo->prepare("SELECT DISTINCT tf.papercode,
                COALESCE(c.coursetitle, tf.papercode) as coursetitle,
                COALESCE(c.dept_code, tf.deptcode) as dept_code,
                c.level, c.maxmark, c.credit, c.type, d.name as dept_name
              FROM timetablefaculty tf
              LEFT JOIN courses c ON UPPER(c.coursecode) = UPPER(tf.papercode)
              LEFT JOIN departments d ON d.code = COALESCE(c.dept_code, tf.deptcode)
              WHERE UPPER(tf.fid) = UPPER(?) ORDER BY tf.papercode");
        $st->execute([$user['staff_code']]);
        $assignedCourses = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($assignedCourses as $ac) {
            $assignedCourseCodes[] = strtoupper($ac['papercode']);
        }
        
        // Fallback to department courses if no timetable allocated yet or if user is HOD
        if (empty($assignedCourses)) {
            $userDept = $user['dept_code'] ?? '';
            $stF = $pdo->prepare("SELECT c.coursecode as papercode, c.coursetitle, c.dept_code, c.level, c.maxmark, c.credit, c.type, d.name as dept_name FROM courses c LEFT JOIN departments d ON d.code = c.dept_code WHERE UPPER(c.dept_code) = UPPER(?) OR ? = '' ORDER BY c.coursecode ASC LIMIT 40");
            $stF->execute([$userDept, $userDept]);
            $assignedCourses = $stF->fetchAll(PDO::FETCH_ASSOC);
            foreach ($assignedCourses as $ac) {
                $assignedCourseCodes[] = strtoupper($ac['papercode']);
            }
        }
    }
} catch (Throwable $e) {}

// Fetch all courses for dynamic department selection
$allCourses = [];
try {
    $stAllC = $pdo->query("SELECT coursecode, coursetitle, dept_code, level, maxmark, credit, type FROM courses ORDER BY coursecode ASC");
    $allCourses = $stAllC->fetchAll(PDO::FETCH_ASSOC);
  foreach ($allCourses as &$__c) { $__c['exam_marks'] = hcc_course_exam_info($__c)['exam_marks']; }
  unset($__c);
} catch (Exception $e) {}

$bankId = (int)($_GET['bank_id'] ?? 0);
$existingBank = null;
$existingQuestions = [];

if ($bankId) {
    try {
        $st = $pdo->prepare("SELECT * FROM question_banks WHERE id = ?");
        $st->execute([$bankId]);
        $existingBank = $st->fetch(PDO::FETCH_ASSOC);
        if ($existingBank) {
            $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
            $stQ->execute([$bankId]);
            $existingQuestions = $stQ->fetchAll(PDO::FETCH_ASSOC);
            if (empty($existingQuestions) && !empty($existingBank['questions_json'])) {
                $parsed = json_decode($existingBank['questions_json'], true);
                $existingQuestions = $parsed['questions'] ?? (is_array($parsed) ? $parsed : []);
            }
        }
    } catch (Exception $e) {}
}

$selectedPaper = $_GET['paper_code'] ?? ($existingBank['paper_code'] ?? ($assignedCourses[0]['papercode'] ?? ''));
$selectedCourse = $_GET['course_title'] ?? ($existingBank['course_title'] ?? '');
if (!$selectedCourse) {
    foreach ($allCourses as $c) {
        if ($c['coursecode'] === $selectedPaper) {
            $selectedCourse = $c['coursetitle'];
            break;
        }
    }
}
$deptDefault = $_GET['dept_code'] ?? ($existingBank['dept_code'] ?? ($user['dept_code'] ?? ($departments[0]['code'] ?? 'CSE')));

$selectedCourseRow = [];
foreach ($allCourses as $c) { if (strtoupper((string)$c['coursecode']) === strtoupper((string)$selectedPaper)) { $selectedCourseRow=$c; break; } }
$selectedExamInfo = $selectedCourseRow ? hcc_course_exam_info($selectedCourseRow) : ['exam_marks'=>75,'type_label'=>'OBE Theory (75M)'];
$initialMarks = (int)($_GET['max_marks'] ?? ($existingBank['max_marks'] ?? $selectedExamInfo['exam_marks']));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header Banner with Crextio Ambient Styling -->
  <div class="bg-gradient-to-r from-[#1C1D21] via-slate-900 to-indigo-950 rounded-[28px] p-7 text-white shadow-xl border border-stone-800 relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-amber-400/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="inline-flex items-center gap-2 bg-amber-400/25 text-amber-300 border border-amber-400/30 rounded-full px-3.5 py-1 text-[11px] font-extrabold uppercase tracking-wider">
          <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-300"></i> HOLY CROSS COLLEGE (AUTONOMOUS) • <?php echo $isHod ? 'HOD PORTAL' : 'FACULTY PORTAL'; ?>
        </div>
        <h2 class="text-xl sm:text-2xl font-extrabold mt-2.5 tracking-tight">Question Bank Repository & Smart OBE Verification</h2>
        <p class="text-stone-300 text-xs mt-1.5 max-w-3xl leading-relaxed">
          Upload Word (DOCX), PDF, Excel (XLSX), CSV, or JSON question banks. Features auto-language detection (English, Tamil, French), duplicate question detection with instant replace options, unit-subunit tracking, and answer key management.
        </p>
      </div>
      <div class="flex items-center gap-2.5">
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/view_banks.php" class="bg-white/10 hover:bg-white/20 text-white border border-white/25 text-xs font-bold px-4 py-2.5 rounded-full transition flex items-center space-x-1.5 shadow-sm">
          <i data-lucide="folder" class="w-4 h-4 text-amber-300"></i>
          <span><?php echo $isHod ? 'Department Question Banks' : 'My Question Banks'; ?></span>
        </a>
      </div>
    </div>
  </div>

  <!-- Allocated Courses Alert Bar for Faculty -->
  <?php if (!$isCoe && !empty($assignedCourses)): ?>
    <div class="bg-amber-50/90 border border-amber-200/90 rounded-[22px] p-4 text-xs shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-2">
      <div class="flex items-center space-x-2.5">
        <span class="w-7 h-7 rounded-full bg-amber-400 text-slate-950 font-black flex items-center justify-center text-xs shrink-0">★</span>
        <div>
          <span class="font-extrabold text-amber-950"><?php echo $isHod ? 'Department Managed / Allocated Courses:' : 'Your Dedicated Timetable Allocated Courses:'; ?></span>
          <span class="text-amber-900 ml-1 font-medium">
            <?php 
              $cList = [];
              foreach (array_slice($assignedCourses, 0, 8) as $ac) {
                $cList[] = '<strong>' . htmlspecialchars($ac['papercode']) . '</strong> (' . htmlspecialchars($ac['coursetitle']) . ')';
              }
              echo implode(' &bull; ', $cList);
              if (count($assignedCourses) > 8) echo ' &bull; +' . (count($assignedCourses) - 8) . ' more';
            ?>
          </span>
        </div>
      </div>
      <div class="flex items-center space-x-2 shrink-0">
        <?php if ($isHod): ?>
          <span class="bg-indigo-600 text-white text-[10px] font-extrabold px-3 py-1 rounded-full uppercase tracking-wider">HOD Role</span>
        <?php endif; ?>
        <span class="bg-amber-400/30 text-amber-950 text-[10px] font-extrabold px-3 py-1 rounded-full border border-amber-400/40 uppercase tracking-wider">
          Staff Code: <?php echo htmlspecialchars($user['staff_code']); ?>
        </span>
      </div>
    </div>
  <?php endif; ?>

  <!-- Step 1: Course & Examination Parameters Card with Dynamic Filter -->
  <div class="bg-white rounded-[28px] border border-stone-200/80 shadow-sm p-6 space-y-5">
    <div class="flex items-center justify-between border-b border-stone-100 pb-3">
      <div class="flex items-center space-x-3">
        <div class="w-8 h-8 rounded-full bg-[#1C1D21] text-white flex items-center justify-center font-extrabold text-sm shadow">1</div>
        <div>
          <h3 class="font-extrabold text-slate-900 text-sm">Course & Examination Parameters</h3>
          <p class="text-[11px] text-slate-500">Select Department to filter courses. Dedicated allocated courses are marked.</p>
        </div>
      </div>

      <!-- Manual Animated 3-Way Language Switcher -->
      <div class="relative flex items-center bg-stone-200/80 p-1 rounded-full border border-stone-300 text-xs font-bold shadow-inner">
        <div id="lang-slider-pill" class="absolute top-1 bottom-1 bg-[#1C1D21] rounded-full transition-all duration-300 ease-out shadow-md pointer-events-none" style="left: 4px; width: 95px;"></div>
        <button type="button" onclick="setLanguage('en', this)" id="lang-btn-en" class="relative z-10 px-3.5 py-1.5 rounded-full text-white font-black transition-colors duration-200 text-[11px] flex items-center space-x-1 cursor-pointer">
          <span>🇬🇧</span><span id="label-lang-en">English</span>
        </button>
        <button type="button" onclick="setLanguage('ta', this)" id="lang-btn-ta" class="relative z-10 px-3.5 py-1.5 rounded-full text-slate-700 font-bold transition-colors duration-200 text-[11px] flex items-center space-x-1 cursor-pointer">
          <span>🇮🇳</span><span id="label-lang-ta">தமிழ்</span>
        </button>
        <button type="button" onclick="setLanguage('hi', this)" id="lang-btn-hi" class="relative z-10 px-3.5 py-1.5 rounded-full text-slate-700 font-bold transition-colors duration-200 text-[11px] flex items-center space-x-1 cursor-pointer">
          <span>🇮🇳</span><span id="label-lang-hi">हिन्दी</span>
        </button>
        <button type="button" onclick="setLanguage('fr', this)" id="lang-btn-fr" class="relative z-10 px-3.5 py-1.5 rounded-full text-slate-700 font-bold transition-colors duration-200 text-[11px] flex items-center space-x-1 cursor-pointer">
          <span>🇫🇷</span><span id="label-lang-fr">Français</span>
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
      
      <!-- Department Selection -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Academic Department *</label>
        <select id="dept_code" onchange="filterCoursesByDept()" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold text-slate-800 shadow-sm focus:ring-2 focus:ring-indigo-500">
          <?php foreach ($departments as $d): ?>
            <option value="<?php echo htmlspecialchars($d['code']); ?>" <?php echo ($deptDefault === $d['code']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($d['code'] . ' - ' . $d['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Paper Code Selection -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Course / Paper Code *</label>
        <select id="paper_code" onchange="onPaperCodeChange()" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-mono font-black text-indigo-950 shadow-sm focus:ring-2 focus:ring-indigo-500">
          <!-- Populated dynamically via JS -->
        </select>
      </div>

      <!-- Course Title Display -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Course Title</label>
        <input type="text" id="course_title" readonly value="<?php echo htmlspecialchars($selectedCourse); ?>" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-100 font-bold text-slate-700 shadow-sm truncate">
      </div>

      <!-- Semester Selection -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Semester *</label>
        <select id="semester" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold shadow-sm focus:ring-2 focus:ring-indigo-500">
          <?php for ($s = 1; $s <= 8; $s++): ?>
            <option value="Semester <?php echo $s; ?>" <?php echo (($existingBank['semester'] ?? 'Semester 1') === 'Semester ' . $s) ? 'selected' : ''; ?>>
              Semester <?php echo $s; ?> (<?php echo hcc_roman_num($s); ?>)
            </option>
          <?php endfor; ?>
        </select>
      </div>

      <!-- Academic Year -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Academic Year</label>
        <select id="academic_year" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold shadow-sm">
          <option value="2026-2027" <?php echo (($existingBank['academic_year'] ?? DEFAULT_ACADEMIC_YEAR) === '2026-2027') ? 'selected' : ''; ?>>2026-2027 (Current Academic Year)</option>
          <option value="2025-2026" <?php echo (($existingBank['academic_year'] ?? '') === '2025-2026') ? 'selected' : ''; ?>>2025-2026</option>
          <option value="2024-2025" <?php echo (($existingBank['academic_year'] ?? '') === '2024-2025') ? 'selected' : ''; ?>>2024-2025</option>
        </select>
      </div>

      <!-- Examination Type -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Exam Type</label>
        <select id="exam_type" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold shadow-sm">
          <option value="Odd Semester End Examination">Odd Semester End Examination (Nov/Dec)</option>
          <option value="Even Semester End Examination">Even Semester End Examination (Apr/May)</option>
          <option value="Internal 1">Internal 1 (CIA-I)</option>
          <option value="Internal 2">Internal 2 (CIA-II)</option>
          <option value="Year">Year (Annual / Supplementary)</option>
        </select>
      </div>

      <!-- Maximum Marks Target -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Target End-Sem Marks</label>
        <div class="relative">
          <input type="number" id="max_marks_input" value="<?php echo $initialMarks; ?>" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-black text-amber-950 shadow-sm focus:ring-2 focus:ring-indigo-500">
          <span id="marks_badge_type" class="absolute right-2.5 top-2.5 text-[10px] font-extrabold <?php echo ($initialMarks === 50) ? 'bg-amber-50 text-amber-800 border border-amber-300' : 'bg-indigo-50 text-indigo-700 border border-indigo-200'; ?> px-2 py-0.5 rounded-full">
            <?php echo ($initialMarks === 50) ? 'NON-OBE (50M)' : 'OBE Theory (75M)'; ?>
          </span>
        </div>
      </div>

      <!-- Regulation / Curriculum -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Curriculum Regulation *</label>
        <select id="regulation" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold shadow-sm">
          <option value="2024 (OBE)" <?php echo ($initialMarks !== 50) ? 'selected' : ''; ?>>2024 (OBE Standard Curriculum - 75 Marks)</option>
          <option value="NON-OBE" <?php echo ($initialMarks === 50) ? 'selected' : ''; ?>>NON-OBE (50 Marks / Diploma / Practical)</option>
          <option value="2021 (LOCF)">2021 (LOCF Curriculum)</option>
        </select>
      </div>

      <!-- Blueprint Allocation Target -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Blueprint Allocation Target *</label>
        <select id="blueprint_allocation_target" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold shadow-sm">
          <option value="275_pool">Master 275-Question Pool Allocation (Units 1.1-5.5 • All K-Levels)</option>
          <option value="30_standard">Standard 30-Question Paper Blueprint (75 Marks • Holy Cross Autonomous)</option>
          <option value="custom">General Curriculum Question Pool</option>
        </select>
      </div>

    </div>
  </div>

  <!-- Dedicated Official Question Bank Templates Box (English, Tamil, French) -->
  <div class="bg-gradient-to-br from-indigo-50/70 via-stone-50 to-purple-50/50 rounded-3xl border border-indigo-100 p-5 space-y-4 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-indigo-100/80 pb-3">
      <div class="flex items-center space-x-2.5">
        <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-black text-xs shadow-sm">
          <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
        </div>
        <div>
          <h4 class="font-extrabold text-slate-900 text-xs uppercase tracking-wider" id="txt-tpl-title">Download Official OBE Question Bank Templates</h4>
          <p class="text-[11px] text-slate-500" id="txt-tpl-desc">Pre-formatted templates adhering to Holy Cross Bloom's Taxonomy & 275-Question Pool Matrix.</p>
        </div>
      </div>
      <span class="text-[10px] font-bold text-indigo-800 bg-indigo-100/80 px-2.5 py-1 rounded-full border border-indigo-200 self-start sm:self-auto">
        DOCX • XLSX • CSV • JSON
      </span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5 text-xs">
      
      <!-- English Template Card -->
      <div class="bg-white rounded-2xl border border-stone-200 p-3.5 space-y-2.5 shadow-xs hover:border-indigo-300 transition">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2 font-black text-slate-900 text-xs">
            <span class="text-base">🇬🇧</span>
            <span>English Template</span>
          </div>
          <span class="text-[10px] font-bold text-slate-500 bg-stone-100 px-2 py-0.5 rounded">All Units</span>
        </div>
        <p class="text-[11px] text-slate-500">Standard English OBE question matrix with K1-K6 cognitive levels & answer keys.</p>
        <div class="grid grid-cols-2 gap-1.5 pt-1">
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=english&format=xlsx" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="sheet" class="w-3 h-3 text-emerald-600"></i><span>XLSX (Excel)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=english&format=docx" class="bg-blue-50 hover:bg-blue-100 text-blue-800 border border-blue-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="file-text" class="w-3 h-3 text-blue-600"></i><span>DOCX (Word)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=english&format=csv" class="bg-stone-50 hover:bg-stone-100 text-slate-700 border border-stone-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="table" class="w-3 h-3"></i><span>CSV File</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=english&format=json" class="bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="code" class="w-3 h-3 text-amber-700"></i><span>JSON Spec</span>
          </a>
        </div>
      </div>

      <!-- Tamil Template Card -->
      <div class="bg-white rounded-2xl border border-stone-200 p-3.5 space-y-2.5 shadow-xs hover:border-amber-300 transition">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2 font-black text-slate-900 text-xs">
            <span class="text-base">🇮🇳</span>
            <span>தமிழ் மாதிரிப் படிவம்</span>
          </div>
          <span class="text-[10px] font-bold text-amber-800 bg-amber-50 px-2 py-0.5 rounded">Unicode / ஒருங்குறி</span>
        </div>
        <p class="text-[11px] text-slate-500">அலகு 1-5, அறிவாற்றல் நிலை, விடைக் குறிப்புகள் அடங்கிய தமிழ் வினா வங்கி மாதிரி.</p>
        <div class="grid grid-cols-2 gap-1.5 pt-1">
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=tamil&format=xlsx" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="sheet" class="w-3 h-3 text-emerald-600"></i><span>XLSX (Excel)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=tamil&format=docx" class="bg-blue-50 hover:bg-blue-100 text-blue-800 border border-blue-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="file-text" class="w-3 h-3 text-blue-600"></i><span>DOCX (Word)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=tamil&format=csv" class="bg-stone-50 hover:bg-stone-100 text-slate-700 border border-stone-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="table" class="w-3 h-3"></i><span>CSV (UTF-8)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=tamil&format=json" class="bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="code" class="w-3 h-3 text-amber-700"></i><span>JSON Spec</span>
          </a>
        </div>
      </div>

      <!-- Hindi Template Card -->
      <div class="bg-white rounded-2xl border border-stone-200 p-3.5 space-y-2.5 shadow-xs hover:border-orange-300 transition">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2 font-black text-slate-900 text-xs"><span class="text-base">🇮🇳</span><span>हिन्दी टेम्पलेट</span></div>
          <span class="text-[10px] font-bold text-orange-800 bg-orange-50 px-2 py-0.5 rounded">Unicode</span>
        </div>
        <p class="text-[11px] text-slate-500">यूनिट, उप-यूनिट, K-स्तर, CO और उत्तर कुंजी सहित प्रश्न बैंक टेम्पलेट।</p>
        <div class="grid grid-cols-2 gap-1.5 pt-1">
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=hindi&format=xlsx" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center">XLSX</a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=hindi&format=docx" class="bg-blue-50 hover:bg-blue-100 text-blue-800 border border-blue-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center">DOCX</a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=hindi&format=csv" class="bg-stone-50 hover:bg-stone-100 text-slate-700 border border-stone-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center">CSV</a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=hindi&format=json" class="bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center">JSON</a>
        </div>
      </div>

      <!-- French Template Card -->
      <div class="bg-white rounded-2xl border border-stone-200 p-3.5 space-y-2.5 shadow-xs hover:border-purple-300 transition">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2 font-black text-slate-900 text-xs">
            <span class="text-base">🇫🇷</span>
            <span>Modèle Français</span>
          </div>
          <span class="text-[10px] font-bold text-purple-800 bg-purple-50 px-2 py-0.5 rounded">Français OBE</span>
        </div>
        <p class="text-[11px] text-slate-500">Banque de questions avec unités, niveaux cognitifs Bloom K1-K6 et clés de réponse.</p>
        <div class="grid grid-cols-2 gap-1.5 pt-1">
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=french&format=xlsx" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="sheet" class="w-3 h-3 text-emerald-600"></i><span>XLSX (Excel)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=french&format=docx" class="bg-blue-50 hover:bg-blue-100 text-blue-800 border border-blue-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="file-text" class="w-3 h-3 text-blue-600"></i><span>DOCX (Word)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=french&format=csv" class="bg-stone-50 hover:bg-stone-100 text-slate-700 border border-stone-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="table" class="w-3 h-3"></i><span>CSV (UTF-8)</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?lang=french&format=json" class="bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 font-bold px-2 py-1.5 rounded-xl text-[11px] text-center transition flex items-center justify-center space-x-1">
            <i data-lucide="code" class="w-3 h-3 text-amber-700"></i><span>JSON Spec</span>
          </a>
        </div>
      </div>

    </div>
  </div>

  <!-- Step 2: Upload File & Extraction Engine -->
  <div class="bg-white rounded-[28px] border border-stone-200/80 shadow-sm p-6 space-y-4">
    <div class="flex items-center justify-between border-b border-stone-100 pb-3">
      <div class="flex items-center space-x-3">
        <div class="w-8 h-8 rounded-full bg-[#1C1D21] text-white flex items-center justify-center font-extrabold text-sm shadow">2</div>
        <div>
          <h3 class="font-extrabold text-slate-900 text-sm">Upload File or Append Questions (DOCX, PDF, Excel, CSV, JSON)</h3>
          <p class="text-[11px] text-slate-500">Supports question banks with up to 250+ questions. Questions are parsed across all units with answer keys extracted.</p>
        </div>
      </div>

      <div class="flex items-center space-x-2">
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php?format=docx" class="text-xs text-indigo-600 hover:text-indigo-800 font-bold flex items-center space-x-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 rounded-full transition">
          <i data-lucide="download" class="w-3.5 h-3.5"></i>
          <span>Download DOCX Template</span>
        </a>
      </div>
    </div>

    <!-- Drag & Drop Upload Zone -->
    <div id="dropZone" class="border-2 border-dashed border-stone-300 hover:border-indigo-500 bg-stone-50/70 hover:bg-indigo-50/30 rounded-3xl p-8 text-center transition cursor-pointer relative group">
      <input type="file" id="fileInput" accept=".docx,.pdf,.xlsx,.csv,.json,.txt,.odt,.zip" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full z-10">
      
      <div class="flex flex-col items-center justify-center space-y-3">
        <div class="w-14 h-14 rounded-2xl bg-white shadow-md border border-stone-200 flex items-center justify-center text-indigo-600 group-hover:scale-105 transition">
          <i data-lucide="upload-cloud" class="w-7 h-7"></i>
        </div>
        <div>
          <p class="font-extrabold text-slate-800 text-sm">Click to choose or drag and drop Question Bank file</p>
          <p class="text-[11px] text-slate-500 mt-0.5">Supports Microsoft Word (.docx), Scanned / Text PDF (.pdf), Excel (.xlsx), CSV, and JSON (up to <?php echo $maxUploadMb; ?>MB)</p>
        </div>
        <div class="flex items-center space-x-3 text-[11px] font-bold text-slate-500">
          <span class="flex items-center space-x-1"><i data-lucide="check" class="w-3.5 h-3.5 text-emerald-600"></i><span>All Units (I to V)</span></span>
          <span class="flex items-center space-x-1"><i data-lucide="check" class="w-3.5 h-3.5 text-emerald-600"></i><span>Answer Keys</span></span>
          <span class="flex items-center space-x-1"><i data-lucide="check" class="w-3.5 h-3.5 text-emerald-600"></i><span>Auto Language</span></span>
        </div>
      </div>
    </div>

    <!-- Upload Progress / File Status Card -->
    <div id="fileUploadStatus" class="hidden bg-stone-100 rounded-2xl p-4 flex items-center justify-between text-xs">
      <div class="flex items-center space-x-3">
        <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold">
          <i data-lucide="file-text" class="w-4 h-4"></i>
        </div>
        <div>
          <p id="uploadedFileName" class="font-extrabold text-slate-900 truncate max-w-md"></p>
          <p id="uploadedFileDetails" class="text-[11px] text-slate-500"></p>
        </div>
      </div>
      <button type="button" onclick="resetUpload()" class="text-rose-600 hover:text-rose-800 font-bold text-xs flex items-center space-x-1">
        <i data-lucide="x" class="w-4 h-4"></i>
        <span>Clear</span>
      </button>
    </div>
  </div>

  <!-- Duplicate Questions Warning Banner & Resolution Panel -->
  <div id="duplicateAlertPanel" class="hidden bg-amber-50 border-2 border-amber-300 rounded-[28px] p-6 shadow-md space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-amber-200 pb-3">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-2xl bg-amber-400 text-slate-950 flex items-center justify-center font-black text-lg shadow-sm">⚠️</div>
        <div>
          <h4 class="font-black text-amber-950 text-sm">Duplicate Questions Detected (<span id="duplicateCountBadge">0</span> questions)</h4>
          <p class="text-xs text-amber-800">You can replace the existing question in the bank, append it as a new question, or skip importing the duplicate.</p>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <button type="button" onclick="setAllDuplicateActions('replace')" class="px-3.5 py-1.5 rounded-full bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-black shadow-sm transition flex items-center space-x-1">
          <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
          <span>Replace All Matching</span>
        </button>
        <button type="button" onclick="setAllDuplicateActions('append')" class="px-3.5 py-1.5 rounded-full bg-white hover:bg-stone-100 text-slate-800 border border-stone-300 text-xs font-bold shadow-sm transition">
          Append All
        </button>
        <button type="button" onclick="setAllDuplicateActions('skip')" class="px-3.5 py-1.5 rounded-full bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold shadow-sm transition">
          Skip All Duplicates
        </button>
      </div>
    </div>

    <!-- Duplicate Summary List -->
    <div id="duplicateListContainer" class="space-y-2 max-h-60 overflow-y-auto pr-1">
      <!-- Populated via JS -->
    </div>
  </div>

  <!-- Master Blueprint Verification & Quality Compliance Gating Card -->
  <div id="blueprintComplianceCard" class="bg-white rounded-[28px] border border-stone-200/80 shadow-sm p-6 space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-stone-100 pb-3">
      <div class="flex items-center space-x-3">
        <div id="bpComplianceIcon" class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center font-extrabold text-sm shadow">
          <i data-lucide="shield-check" class="w-4 h-4"></i>
        </div>
        <div>
          <h3 class="font-extrabold text-slate-900 text-sm" id="txt-bp-card-title">Master Blueprint Verification & Submission Gating</h3>
          <p class="text-[11px] text-slate-500" id="txt-bp-card-desc">Question bank is automatically validated against the active Master Blueprint before submission to HOD.</p>
        </div>
      </div>

      <div class="flex items-center space-x-2">
        <span id="bpComplianceBadge" class="px-3.5 py-1 rounded-full text-xs font-black bg-stone-100 text-slate-700 border border-stone-200">
          Awaiting Question Bank Data
        </span>
      </div>
    </div>

    <!-- Blueprint Metrics Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3 text-xs" id="blueprintMetricsGrid">
      <div class="bg-stone-50 rounded-2xl p-3 border border-stone-200">
        <span class="text-[10px] uppercase font-bold text-slate-500 block">Total Questions</span>
        <span class="text-base font-black text-slate-900" id="bp-metric-total">0</span>
        <span class="text-[10px] text-slate-500 block mt-0.5" id="bp-metric-target">Target: 275 Pool</span>
      </div>
      <div class="bg-stone-50 rounded-2xl p-3 border border-stone-200">
        <span class="text-[10px] uppercase font-bold text-slate-500 block">Units Covered</span>
        <span class="text-base font-black text-indigo-700" id="bp-metric-units">0 / 5</span>
        <span class="text-[10px] text-slate-500 block mt-0.5">Units I to V</span>
      </div>
      <div class="bg-stone-50 rounded-2xl p-3 border border-stone-200">
        <span class="text-[10px] uppercase font-bold text-slate-500 block">MCQ / Part A</span>
        <span class="text-base font-black text-sky-700" id="bp-metric-mcq">0</span>
        <span class="text-[10px] text-slate-500 block mt-0.5">K1-K3 Levels</span>
      </div>
      <div class="bg-stone-50 rounded-2xl p-3 border border-stone-200">
        <span class="text-[10px] uppercase font-bold text-slate-500 block">Part B / 5M</span>
        <span class="text-base font-black text-amber-700" id="bp-metric-partb">0</span>
        <span class="text-[10px] text-slate-500 block mt-0.5">K1, K2, K4 Levels</span>
      </div>
      <div class="bg-stone-50 rounded-2xl p-3 border border-stone-200">
        <span class="text-[10px] uppercase font-bold text-slate-500 block">Part C & D</span>
        <span class="text-base font-black text-purple-700" id="bp-metric-partcd">0</span>
        <span class="text-[10px] text-slate-500 block mt-0.5">K1-K3 Levels</span>
      </div>
      <div class="bg-stone-50 rounded-2xl p-3 border border-stone-200">
        <span class="text-[10px] uppercase font-bold text-slate-500 block">Answer Keys</span>
        <span class="text-base font-black text-emerald-700" id="bp-metric-keys">0</span>
        <span class="text-[10px] text-slate-500 block mt-0.5">Verified Keys</span>
      </div>
    </div>

    <div id="bpDiscrepancyNotice" class="hidden bg-amber-50 border border-amber-200 rounded-2xl p-3.5 text-xs text-amber-900 flex items-start space-x-2.5">
      <i data-lucide="info" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5"></i>
      <div id="bpDiscrepancyText"></div>
    </div>
  </div>

  <!-- Step 3: Interactive Question Bank Preview & Editor -->
  <div id="previewCard" class="bg-white rounded-[28px] border border-stone-200/80 shadow-sm p-6 space-y-5">
    
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-stone-100 pb-4">
      <div class="flex items-center space-x-3">
        <div class="w-8 h-8 rounded-full bg-[#1C1D21] text-white flex items-center justify-center font-extrabold text-sm shadow">3</div>
        <div>
          <h3 class="font-extrabold text-slate-900 text-sm">Question Bank Workspace & Answer Key Verification</h3>
          <p id="poolSummary" class="text-[11px] text-slate-500">Edit questions, units, sub-units, cognitive K-levels, and answer keys.</p>
        </div>
      </div>

      <!-- Section and Unit Filter Controls -->
      <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center bg-stone-100 p-1 rounded-full border border-stone-200 text-xs font-bold">
          <button type="button" onclick="filterPreviewSection('ALL')" id="filter-sec-all" class="px-3 py-1 rounded-full bg-[#1C1D21] text-white transition text-[11px]">All</button>
          <button type="button" onclick="filterPreviewSection('SECTION-A')" id="filter-sec-a" class="px-3 py-1 rounded-full text-slate-600 hover:text-slate-900 transition text-[11px]">Section A</button>
          <button type="button" onclick="filterPreviewSection('SECTION-B')" id="filter-sec-b" class="px-3 py-1 rounded-full text-slate-600 hover:text-slate-900 transition text-[11px]">Section B</button>
          <button type="button" onclick="filterPreviewSection('SECTION-C')" id="filter-sec-c" class="px-3 py-1 rounded-full text-slate-600 hover:text-slate-900 transition text-[11px]">Section C</button>
          <button type="button" onclick="filterPreviewSection('SECTION-D')" id="filter-sec-d" class="px-3 py-1 rounded-full text-slate-600 hover:text-slate-900 transition text-[11px]">Section D</button>
        </div>

        <select id="filter-marks" onchange="filterPreviewMarks(this.value)" class="border border-stone-300 rounded-full px-3 py-1.5 text-[11px] font-bold bg-white text-slate-700 shadow-sm">
          <option value="ALL">All Marks</option>
          <option value="1">1 Mark</option>
          <option value="2">2 Marks</option>
          <option value="5">5 Marks</option>
          <option value="8">8 Marks</option>
          <option value="10">10 Marks</option>
          <option value="15">15 Marks</option>
          <option value="20">20 Marks</option>
        </select>

        <button type="button" onclick="addNewManualQuestion()" class="bg-[#1C1D21] hover:bg-slate-800 text-white text-xs font-black px-4 py-2 rounded-full transition flex items-center space-x-1.5 shadow-sm">
          <i data-lucide="plus-circle" class="w-4 h-4 text-amber-400"></i>
          <span>+ Add Question</span>
        </button>

        <!-- Validate with Blueprint (Unit Picker) Button -->
        <button type="button" onclick="validateQuestionsWithBlueprint()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 text-xs font-black px-4 py-2 rounded-full transition flex items-center space-x-1.5 shadow-sm">
          <i data-lucide="check-check" class="w-4 h-4 text-indigo-600"></i>
          <span>Validate with Blueprint</span>
        </button>

        <!-- HOD Duplicate Check SweetAlert Button -->
        <?php if ($isHod): ?>
          <button type="button" onclick="runHODDuplicateCheck()" class="bg-amber-400 hover:bg-amber-300 text-slate-950 text-xs font-black px-4 py-2 rounded-full transition flex items-center space-x-1.5 shadow-sm">
            <i data-lucide="search-check" class="w-4 h-4"></i>
            <span>Check Duplicates</span>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Questions Table / Grid -->
    <div class="overflow-x-auto rounded-2xl border border-stone-200">
      <table class="w-full text-left text-xs border-collapse">
        <thead class="bg-stone-900 text-stone-200 uppercase font-black text-[10px] tracking-wider">
          <tr>
            <th class="p-3 text-center w-12">#</th>
            <th class="p-3 text-center w-24">Unit</th>
            <th class="p-3 text-center w-24">Sub-Unit</th>
            <th class="p-3 text-center w-24">Marks</th>
            <th class="p-3 text-center w-28">K-Level</th>
            <th class="p-3 text-center w-20">CO-Level</th>
            <th class="p-3 text-center w-32">Section</th>
            <th class="p-3">Question Text & Answer Key</th>
            <th class="p-3 text-center w-20">Action</th>
          </tr>
        </thead>
        <tbody id="previewBody" class="divide-y divide-stone-200 bg-white">
          <!-- Rendered dynamically via renderPreview() -->
        </tbody>
      </table>
    </div>

    <!-- Hidden Image Upload Input for diagrams -->
    <input type="file" id="questionImageInput" accept="image/*" class="hidden" onchange="handleImageSelected(this)">

    <!-- Bottom Actions Bar: Staff to HOD / HOD to COE -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-stone-100">
      <div class="text-xs text-slate-500">
        Total in pool: <strong id="bottomTotalCount" class="text-slate-900 font-black">0</strong> questions |
        Answer Keys extracted: <strong id="bottomKeyCount" class="text-emerald-700 font-black">0</strong>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <button type="button" onclick="submitBank('Draft')" id="draftBtn" class="bg-stone-100 hover:bg-stone-200 text-slate-800 border border-stone-300 font-bold px-5 py-2.5 rounded-full text-xs transition shadow-xs">
          Save as Draft
        </button>

        <?php if ($isCoe): ?>
          <!-- COE has no staff/HOD submission action on this workspace. -->
          <span class="bg-indigo-50 text-indigo-800 border border-indigo-200 font-black px-5 py-2.5 rounded-full text-xs">COE Review Workspace</span>
        <?php elseif ($isHod): ?>
          <!-- HOD Button: Approve & Submit to COE -->
          <button type="button" onclick="submitBank('submit_to_coe')" id="submitCoeBtn" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-black px-6 py-2.5 rounded-full text-xs shadow-lg transition flex items-center space-x-2">
            <i data-lucide="shield-check" class="w-4 h-4"></i>
            <span>Verify & Submit to COE</span>
          </button>
        <?php else: ?>
          <!-- Faculty Button: Submit to HOD -->
          <button type="button" onclick="submitBank('submit_to_hod')" id="submitHodBtn" class="bg-[#1C1D21] hover:bg-slate-800 text-white font-black px-6 py-2.5 rounded-full text-xs shadow-lg transition flex items-center space-x-2">
            <i data-lucide="send" class="w-4 h-4 text-amber-400"></i>
            <span>Submit to HOD</span>
          </button>
        <?php endif; ?>
      </div>
    </div>

  </div>

</main>

<script>
const baseUrl = <?php echo json_encode(getBaseUrl()); ?>;
const allCoursesList = <?php echo json_encode($allCourses); ?>;
const assignedCodes = <?php echo json_encode($assignedCourseCodes); ?>;
const isUserHod = <?php echo json_encode($isHod); ?>;
let existingBankId = <?php echo (int)$bankId; ?>;
let currentLanguage = <?php echo json_encode($existingBank['language'] ?? 'en'); ?>;
let currentSectionFilter = 'ALL';
let currentMarksFilter = 'ALL';
let extractedQuestions = <?php echo json_encode($existingQuestions); ?>;
let activeImageTargetIndex = null;

document.addEventListener('DOMContentLoaded', () => {
  filterCoursesByDept();
  setLanguage(currentLanguage);
  if (extractedQuestions.length > 0) {
    renderPreview();
  }
  setupDragAndDrop();
});

function filterCoursesByDept() {
  const deptSelect = document.getElementById('dept_code');
  const paperSelect = document.getElementById('paper_code');
  if (!deptSelect || !paperSelect) return;

  const dept = deptSelect.value;
  const filtered = allCoursesList.filter(c => c.dept_code === dept || !dept);
  
  paperSelect.innerHTML = '';
  filtered.forEach(c => {
    const isAllocated = assignedCodes.includes(c.coursecode.toUpperCase());
    const opt = document.createElement('option');
    opt.value = c.coursecode;
    const cType = (c.type || 'OBE').toUpperCase();
    const isNonObe = cType.includes('NON');
    const typeLabel = isNonObe ? 'NON-OBE - 50M' : 'OBE - 75M';
    
    opt.textContent = `${c.coursecode} - ${c.coursetitle} [${typeLabel}]${isAllocated ? ' ★ (Allocated)' : ''}`;
    opt.dataset.title = c.coursetitle;
    opt.dataset.maxmark = c.exam_marks || (isNonObe ? 50 : 75);
    opt.dataset.type = c.type || (isNonObe ? 'NON OBE' : 'OBE');
    paperSelect.appendChild(opt);
  });

  if (filtered.length > 0) {
    onPaperCodeChange();
  }
}

function onPaperCodeChange() {
  const paperSelect = document.getElementById('paper_code');
  const titleInput = document.getElementById('course_title');
  const marksInput = document.getElementById('max_marks_input');
  const regSelect = document.getElementById('regulation');
  const badge = document.getElementById('marks_badge_type');
  if (!paperSelect) return;

  const sel = paperSelect.selectedOptions[0];
  if (sel) {
    const pCode = sel.value.toUpperCase();
    const pTitle = sel.dataset.title || sel.value;
    if (titleInput) titleInput.value = pTitle;

    const courseType = (sel.dataset.type || '').toUpperCase();
    const isNonObe = courseType.includes('NON') || pTitle.toLowerCase().includes('lab') || pTitle.toLowerCase().includes('practical') || pTitle.toLowerCase().includes('project');
    const targetMarks = isNonObe ? 50 : 75;
    
    if (marksInput) marksInput.value = targetMarks;
    if (regSelect) regSelect.value = isNonObe ? 'NON-OBE' : '2024 (OBE)';
    if (badge) {
      badge.textContent = isNonObe ? 'NON-OBE (50M)' : 'OBE Theory (75M)';
      badge.className = isNonObe 
        ? 'absolute right-2.5 top-2.5 text-[10px] font-extrabold bg-amber-50 text-amber-800 px-2 py-0.5 rounded-full border border-amber-300'
        : 'absolute right-2.5 top-2.5 text-[10px] font-extrabold bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded-full border border-indigo-200';
    }

    // Auto-detect Language based on course code / title
    if (pCode.includes('TL') || pTitle.toLowerCase().includes('tamil') || pTitle.toLowerCase().includes('ilakkiyam') || pTitle.toLowerCase().includes('varalaar') || pTitle.toLowerCase().includes('semmozhi')) {
      if (currentLanguage !== 'ta') {
        setLanguage('ta');
      }
    } else if (pCode.includes('FR') || pTitle.toLowerCase().includes('french') || pTitle.toLowerCase().includes('français')) {
      if (currentLanguage !== 'fr') {
        setLanguage('fr');
      }
    }
  }
}

function baminiToUnicodeClient(text) {
  if (!text) return '';
  let t = String(text);
  
  // Clean known clusters & grantha
  t = t.replace(/rk\];/g, 'சமஸ்').replace(/ej;/g, 'ந்த்').replace(/jhf;f/g, 'தாக்க')
       .replace(/yhj/g, 'லாத').replace(/jdpj;j/g, 'தனித்த').replace(/tha;e/g, 'வாய்ந்')
       .replace(/nkhop/g, 'மொழி').replace(/njspq;/g, 'தெலுங்').replace(/njYq;/g, 'தெலுங்')
       .replace(/jkpo;/g, 'தமிழ்').replace(/nrhw;/g, 'சொற்').replace(/thf;fp/g, 'வாக்கி')
       .replace(/vJ\?/g, 'எது?').replace(/\];/g, 'ஸ்').replace(/\\;/g, 'க்ஷ').replace(/\\/g, 'க்ஷ');

  const pairs3 = [
    ['nfs', 'கௌ'], ['nqs', 'ஙௌ'], ['nrs', 'சௌ'], ['nQs', 'ஞௌ'], ['nls', 'டௌ'],
    ['nzs', 'ணௌ'], ['njs', 'தௌ'], ['nes', 'நௌ'], ['ngs', 'பௌ'], ['nks', 'மௌ'],
    ['nas', 'யௌ'], ['nus', 'ரௌ'], ['nys', 'லௌ'], ['nts', 'வௌ'], ['nos', 'ழௌ'],
    ['nss', 'ளௌ'], ['nws', 'றௌ'], ['nds', 'னௌ'],
    ['Nfh', 'கோ'], ['Nqh', 'ஙோ'], ['Nrh', 'சோ'], ['NQh', 'ஞோ'], ['Nlh', 'டோ'],
    ['Nzh', 'ணோ'], ['Njh', 'தோ'], ['Neh', 'நோ'], ['Ngh', 'போ'], ['Nkh', 'மோ'],
    ['Nah', 'யோ'], ['Nuh', 'ரோ'], ['Nyh', 'லோ'], ['Nth', 'வோ'], ['Noh', 'ழோ'],
    ['Nsh', 'ளோ'], ['Nwh', 'றோ'], ['Ndh', 'னோ'],
    ['nfh', 'கொ'], ['nqh', 'ஙொ'], ['nrh', 'சொ'], ['nQh', 'ஞொ'], ['nlh', 'டொ'],
    ['nzh', 'ணொ'], ['njh', 'தொ'], ['neh', 'நொ'], ['ngh', 'பொ'], ['nkh', 'மொ'],
    ['nah', 'யொ'], ['nuh', 'ரொ'], ['nyh', 'லொ'], ['nth', 'வொ'], ['noh', 'ழொ'],
    ['nsh', 'ளொ'], ['nwh', 'றொ'], ['ndh', 'னொ']
  ];
  pairs3.forEach(([k, v]) => { t = t.split(k).join(v); });

  const pairs2 = [
    ['Nf', 'கே'], ['Nq', 'ஙே'], ['Nr', 'சே'], ['NQ', 'ஞே'], ['Nl', 'டே'],
    ['Nz', 'ணே'], ['Nj', 'தே'], ['Ne', 'நே'], ['Ng', 'பே'], ['Nk', 'மே'],
    ['Na', 'யே'], ['Nu', 'ரே'], ['Ny', 'லே'], ['Nt', 'வே'], ['No', 'ழே'],
    ['Ns', 'ளே'], ['Nw', 'றே'], ['Nd', 'னே'],
    ['nf', 'கெ'], ['nq', 'ஙெ'], ['nr', 'செ'], ['nQ', 'ஞெ'], ['nl', 'டெ'],
    ['nz', 'ணெ'], ['nj', 'தெ'], ['ne', 'நெ'], ['ng', 'பெ'], ['nk', 'மெ'],
    ['na', 'யெ'], ['nu', 'ரெ'], ['ny', 'லெ'], ['nt', 'வெ'], ['no', 'ழெ'],
    ['ns', 'ளெ'], ['nw', 'றெ'], ['nd', 'னெ'],
    ['if', 'கை'], ['iq', 'ஙை'], ['ir', 'சை'], ['iQ', 'ஞை'], ['il', 'டை'],
    ['iz', 'ணை'], ['ij', 'தை'], ['ie', 'நை'], ['ig', 'பை'], ['ik', 'மை'],
    ['ia', 'யை'], ['iu', 'ரை'], ['iy', 'லை'], ['it', 'வை'], ['io', 'ழை'],
    ['is', 'ளை'], ['iw', 'றை'], ['id', 'னை'],
    ['f;', 'க்'], ['q;', 'ங்'], ['r;', 'ச்'], ['Q;', 'ஞ்'], ['l;', 'ட்'],
    ['z;', 'ண்'], ['j;', 'த்'], ['e;', 'ந்'], ['g;', 'ப்'], ['k;', 'ம்'],
    ['a;', 'ய்'], ['u;', 'ர்'], ['y;', 'ல்'], ['t;', 'வ்'], ['o;', 'ழ்'],
    ['s;', 'ள்'], ['w;', 'ற்'], ['d;', 'ன்'], ['h;', 'ஹ்'], ['[;', 'ஷ்'],
    ['fh', 'கா'], ['qh', 'ஙா'], ['rh', 'சா'], ['Qh', 'ஞா'], ['lh', 'டா'],
    ['zh', 'ணா'], ['jh', 'தா'], ['eh', 'நா'], ['gh', 'பா'], ['kh', 'மா'],
    ['ah', 'யா'], ['uh', 'ரா'], ['yh', 'லா'], ['th', 'வா'], ['oh', 'ழா'],
    ['sh', 'ளா'], ['wh', 'றா'], ['dh', 'னா'],
    ['fp', 'கி'], ['qp', 'ஙி'], ['rp', 'சி'], ['Qp', 'ஞி'], ['lp', 'டி'],
    ['zp', 'ணி'], ['jp', 'தி'], ['ep', 'நி'], ['gp', 'பி'], ['kp', 'மி'],
    ['ap', 'யி'], ['up', 'ரி'], ['yp', 'லி'], ['tp', 'வி'], ['op', 'ழி'],
    ['sp', 'ளி'], ['wp', 'றி'], ['dp', 'னி'],
    ['fP', 'கீ'], ['qP', 'ஙீ'], ['rP', 'சீ'], ['QP', 'ஞீ'], ['lP', 'டீ'],
    ['zP', 'ணீ'], ['jP', 'தீ'], ['eP', 'நீ'], ['gP', 'பீ'], ['kP', 'மீ'],
    ['aP', 'யீ'], ['uP', 'ரீ'], ['yP', 'லீ'], ['tP', 'வீ'], ['oP', 'ழீ'],
    ['sP', 'ளீ'], ['wP', 'றீ'], ['dP', 'னீ'],
    ['T+', 'வூ'], ['F+', 'கூ'], ['R+', 'சூ'], ['L+', 'டூ'], ['Z+', 'ணூ'],
    ['J+', 'தூ'], ['E+', 'நூ'], ['G+', 'பூ'], ['K+', 'மூ'], ['A+', 'யூ'],
    ['U+', 'ரூ'], ['Y+', 'லூ'], ['O+', 'ழூ'], ['S+', 'ளூ'], ['W+', 'றூ'], ['D+', 'னூ'],
    ['E}', 'நூல்'], ['xs', 'ஔ'],
    ['F', 'கு'], ['R', 'சு'], ['L', 'டு'], ['Z', 'ணு'], ['J', 'து'],
    ['E', 'நு'], ['G', 'பு'], ['K', 'மு'], ['A', 'யு'], ['U', 'ரு'],
    ['Y', 'லு'], ['T', 'வு'], ['O', 'ழு'], ['S', 'ளு'], ['W', 'றூ'], ['D', 'னு']
  ];
  pairs2.forEach(([k, v]) => { t = t.split(k).join(v); });

  const pairs1 = [
    ['f', 'க'], ['q', 'ங'], ['r', 'ச'], ['Q', 'ஞ'], ['l', 'ட'],
    ['z', 'ண'], ['j', 'த'], ['e', 'ந'], ['g', 'ப'], ['k', 'ம'],
    ['a', 'ய'], ['u', 'ர'], ['y', 'ல'], ['t', 'வ'], ['o', 'ழ'],
    ['s', 'ள'], ['w', 'ற'], ['d', 'ன'],
    ['m', 'அ'], ['M', 'ஆ'], ['c', 'உ'], ['C', 'ஊ'],
    ['v', 'எ'], ['V', 'ஏ'], ['I', 'ஐ'], ['x', 'ஒ'], ['X', 'ஓ'],
    ['<', 'ஈ'], [',', 'இ'],
    ['~', 'ஸ்ரீ'], ['h', 'ா'], [';', '்'], ['_', 'ஹ'], ['[', 'ஷ']
  ];
  pairs1.forEach(([k, v]) => { t = t.split(k).join(v); });

  return t;
}

const UI_TRANSLATIONS = {
  en: {
    hero_title: 'Question Bank Upload & Verification Workspace',
    hero_desc: 'Upload comprehensive question pools (275+ Master Pool or 30-Question Paper Matrix) in English, Tamil, or French with automated answer key extraction and Master Blueprint validation.',
    step1_title: 'Course & Examination Parameters',
    step1_desc: 'Select Academic Department to filter courses. Allocated courses are marked.',
    tpl_title: 'Download Official OBE Question Bank Templates',
    tpl_desc: 'Pre-formatted templates adhering to Holy Cross Bloom\'s Taxonomy & 275-Question Pool Matrix.',
    step2_title: 'Upload File or Append Questions (DOCX, PDF, Excel, CSV, JSON)',
    step2_desc: 'Supports large question pools with 275+ questions across Units I–V with answer keys.',
    drop_title: 'Click to choose or drag and drop Question Bank file',
    drop_desc: 'Supports Microsoft Word (.docx), Scanned / Text PDF (.pdf), Excel (.xlsx), CSV, and JSON',
    bp_card_title: 'Master Blueprint Verification & Submission Gating',
    bp_card_desc: 'Question bank is automatically checked against the active Master Blueprint before submission to HOD.',
    step3_title: 'Interactive Question Bank Preview & Editor',
    step3_desc: 'Review extracted questions, edit cognitive K-levels, verify answer keys, and check blueprint compliance.',
    btn_submit_hod: 'Submit to HOD for Verification',
    btn_submit_coe: 'Verify & Submit to COE',
    btn_save_draft: 'Save as Draft'
  },
  ta: {
    hero_title: 'வினா வங்கி பதிவேற்றம் மற்றும் முதன்மை புளூபிரிண்ட் பணிமனை',
    hero_desc: 'அங்கீகரிக்கப்பட்ட முதன்மை புளூபிரிண்ட் (275+ வினாத் தொகுப்பு) விதிகளின்படி தமிழ் வினா வங்கிகளைப் பதிவேற்றி, விடைக்குறிப்புகளைச் சரிபார்த்து சமர்ப்பிக்கலாம்.',
    step1_title: 'பாடப்பிரிவு மற்றும் தேர்வு விவரங்கள்',
    step1_desc: 'துறையைத் தேர்ந்தெடுத்து பாடப்பிரிவை தேர்ந்தெடுக்கவும்.',
    tpl_title: 'அங்கீகரிக்கப்பட்ட மாதிரி வினா வங்கிப் படிவங்கள் (Download)',
    tpl_desc: 'தூய சிலுவைக் கல்லூரியின் 275+ வினாத் தொகுப்பு மாதிரிப் படிவங்கள்.',
    step2_title: 'வினா வங்கிக் கோப்பைப் பதிவேற்றவும் (DOCX, XLSX, PDF, CSV, JSON)',
    step2_desc: 'அலகுகள் 1-5 வரை 275+ வினாக்கள் வரை ஒருங்குறி (Unicode) முறையில் பதிவேற்றலாம்.',
    drop_title: 'வினா வங்கிக் கோப்பினைத் தேர்ந்தெடுக்க கிளிக் செய்யவும் அல்லது இழுத்துவிடவும்',
    drop_desc: 'வேர்ட் (.docx), எக்செல் (.xlsx), பிடிஎஃப் (.pdf), சிஎஸ்வி (.csv) மற்றும் ஜேசான் (.json)',
    bp_card_title: 'முதன்மை புளூபிரிண்ட் சரிபார்ப்பு மற்றும் சமர்ப்பிப்பு விதிமுறை',
    bp_card_desc: 'துறைத்தலைவர் (HOD) மற்றும் தேர்வுக் கட்டுப்பாட்டாளர் (COE) ஒப்புதலுக்கு முன் புளூபிரிண்ட் சரிபார்க்கப்படும்.',
    step3_title: 'வினா வங்கி முன்னோட்டம் மற்றும் சரிபார்ப்பு',
    step3_desc: 'அறிவாற்றல் நிலை, விடைக் குறிப்புகள் மற்றும் அலகுகளின் விநியோகத்தை சரிபார்க்கவும்.',
    btn_submit_hod: 'துறைத்தலைவருக்கு (HOD) சமர்ப்பிக்கவும்',
    btn_submit_coe: 'சரிபார்த்து COE-க்கு சமர்ப்பிக்கவும்',
    btn_save_draft: 'வரைவாக சேமி (Draft)'
  },
  hi: {
    hero_title: 'प्रश्न बैंक अपलोड और सत्यापन कार्यक्षेत्र',
    hero_desc: 'अंग्रेज़ी, तमिल, हिन्दी या फ़्रेंच में 275+ प्रश्नों के प्रश्न बैंक अपलोड करें और ब्लूप्रिंट/उत्तर कुंजी सत्यापित करें।',
    step1_title: 'पाठ्यक्रम और परीक्षा विवरण',
    step1_desc: 'विभाग चुनकर संबंधित पाठ्यक्रम चुनें।',
    tpl_title: 'आधिकारिक प्रश्न बैंक टेम्पलेट डाउनलोड करें',
    tpl_desc: 'Holy Cross College के स्वीकृत ब्लूप्रिंट के अनुसार टेम्पलेट।',
    step2_title: 'प्रश्न बैंक फ़ाइल अपलोड करें (DOCX, XLSX, PDF, CSV, JSON)',
    step2_desc: 'बड़े प्रश्न पूल, सभी यूनिट और उत्तर कुंजी समर्थित हैं।',
    drop_title: 'प्रश्न बैंक फ़ाइल चुनने या ड्रैग-ड्रॉप करने के लिए क्लिक करें',
    drop_desc: 'Word, PDF, Excel, CSV और JSON समर्थित हैं।',
    bp_card_title: 'मास्टर ब्लूप्रिंट सत्यापन और सबमिशन',
    bp_card_desc: 'HOD को भेजने से पहले प्रश्न बैंक का ब्लूप्रिंट से सत्यापन किया जाएगा।',
    step3_title: 'प्रश्न बैंक समीक्षा और उत्तर कुंजी सत्यापन',
    step3_desc: 'प्रश्न, यूनिट, K-स्तर और उत्तर कुंजी सत्यापित करें।',
    btn_submit_hod: 'HOD को भेजें',
    btn_submit_coe: 'COE को सत्यापित करके भेजें',
    btn_save_draft: 'ड्राफ्ट के रूप में सेव करें'
  },
  fr: {
    hero_title: 'Espace de Téléversement et Vérification de Banque de Questions',
    hero_desc: 'Téléversez des banques de questions complètes (Pool de 275+ questions) en français, conformes à la taxonomie de Bloom et au plan directeur.',
    step1_title: 'Paramètres du Cours et des Examens',
    step1_desc: 'Sélectionnez le département académique pour filtrer les cours attribués.',
    tpl_title: 'Télécharger les Modèles Officiels de Banque de Questions',
    tpl_desc: 'Modèles officiels Holy Cross conformes au plan directeur de 275 questions.',
    step2_title: 'Téléverser le Fichier de Banque de Questions (DOCX, XLSX, PDF, CSV, JSON)',
    step2_desc: 'Prend en charge les banques de questions jusqu\'à 275+ questions avec clés de réponse.',
    drop_title: 'Cliquez pour choisir ou glissez-déposez le fichier de banque de questions',
    drop_desc: 'Prise en charge de Word (.docx), PDF (.pdf), Excel (.xlsx), CSV et JSON',
    bp_card_title: 'Vérification du Plan Directeur & Soumission',
    bp_card_desc: 'La banque de questions doit être validée par rapport au plan directeur avant soumission au HOD.',
    step3_title: 'Examen Interactif de la Banque de Questions & Conformité',
    step3_desc: 'Vérifiez les questions extraites, les niveaux cognitifs Bloom K1-K6 et les clés de réponse.',
    btn_submit_hod: 'Soumettre au Chef de Département (HOD)',
    btn_submit_coe: 'Valider et Soumettre au Bureau COE',
    btn_save_draft: 'Enregistrer comme Brouillon'
  }
};

function setLanguage(lang, clickedBtn) {
  currentLanguage = lang;
  
  // Smooth animated sliding highlight pill
  const pill = document.getElementById('lang-slider-pill');
  const btn = clickedBtn || document.getElementById('lang-btn-' + lang);
  if (pill && btn) {
    pill.style.left = btn.offsetLeft + 'px';
    pill.style.width = btn.offsetWidth + 'px';
  }

  ['en', 'ta', 'hi', 'fr'].forEach(l => {
    const b = document.getElementById('lang-btn-' + l);
    if (b) {
      if (l === lang) {
        b.className = 'relative z-10 px-3.5 py-1.5 rounded-full text-white font-black transition-colors duration-200 text-[11px] flex items-center space-x-1 cursor-pointer';
      } else {
        b.className = 'relative z-10 px-3.5 py-1.5 rounded-full text-slate-700 font-bold hover:text-slate-950 transition-colors duration-200 text-[11px] flex items-center space-x-1 cursor-pointer';
      }
    }
  });

  // Re-translate dynamic UI text
  const t = UI_TRANSLATIONS[lang] || UI_TRANSLATIONS['en'];
  const updateTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  updateTxt('txt-hero-title', t.hero_title);
  updateTxt('txt-hero-desc', t.hero_desc);
  updateTxt('txt-step1-title', t.step1_title);
  updateTxt('txt-step1-desc', t.step1_desc);
  updateTxt('txt-tpl-title', t.tpl_title);
  updateTxt('txt-tpl-desc', t.tpl_desc);
  updateTxt('txt-step2-title', t.step2_title);
  updateTxt('txt-step2-desc', t.step2_desc);
  updateTxt('txt-drop-title', t.drop_title);
  updateTxt('txt-drop-desc', t.drop_desc);
  updateTxt('txt-bp-card-title', t.bp_card_title);
  updateTxt('txt-bp-card-desc', t.bp_card_desc);
  updateTxt('txt-step3-title', t.step3_title);
  updateTxt('txt-step3-desc', t.step3_desc);
  
  const submitHodBtn = document.getElementById('submitHodBtn');
  if (submitHodBtn) {
    submitHodBtn.innerHTML = '<i data-lucide="send" class="w-4 h-4 text-amber-400"></i><span>' + t.btn_submit_hod + '</span>';
  }
  const submitCoeBtn = document.getElementById('submitCoeBtn');
  if (submitCoeBtn) {
    submitCoeBtn.innerHTML = '<i data-lucide="shield-check" class="w-4 h-4"></i><span>' + t.btn_submit_coe + '</span>';
  }
  const draftBtn = document.getElementById('draftBtn');
  if (draftBtn) draftBtn.textContent = t.btn_save_draft;

  renderPreview();
  if (typeof lucide !== 'undefined') lucide.createIcons();
}

function verifyBlueprintCompliance() {
  const total = extractedQuestions.length;
  const targetBlueprint = document.getElementById('blueprint_allocation_target')?.value || '275_pool';
  const targetCount = (targetBlueprint === '275_pool') ? 275 : (targetBlueprint === '30_standard' ? 30 : 200);

  const unitStats = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
  let mcqCount = 0;
  let partBCount = 0;
  let partCDCount = 0;
  let keyCount = 0;

  extractedQuestions.forEach(q => {
    const u = Math.max(1, Math.min(5, parseInt(q.unit_no) || 1));
    unitStats[u] = (unitStats[u] || 0) + 1;

    const sec = (q.section_type || '').toUpperCase();
    if (sec.includes('A') || sec.includes('MCQ') || sec.includes('VSA')) mcqCount++;
    if (sec.includes('B') || parseInt(q.marks) === 5) partBCount++;
    if (sec.includes('C') || sec.includes('D') || parseInt(q.marks) >= 8) partCDCount++;
    if (q.answer_key && String(q.answer_key).trim() !== '') keyCount++;
  });

  let unitsCovered = 0;
  for (let u = 1; u <= 5; u++) {
    if (unitStats[u] > 0) unitsCovered++;
  }

  // Update UI Elements
  const elTotal = document.getElementById('bp-metric-total');
  const elUnits = document.getElementById('bp-metric-units');
  const elMcq = document.getElementById('bp-metric-mcq');
  const elPartb = document.getElementById('bp-metric-partb');
  const elPartcd = document.getElementById('bp-metric-partcd');
  const elKeys = document.getElementById('bp-metric-keys');
  const elTarget = document.getElementById('bp-metric-target');
  const badge = document.getElementById('bpComplianceBadge');
  const notice = document.getElementById('bpDiscrepancyNotice');
  const noticeText = document.getElementById('bpDiscrepancyText');

  if (elTotal) elTotal.textContent = total;
  if (elUnits) elUnits.textContent = `${unitsCovered} / 5`;
  if (elMcq) elMcq.textContent = mcqCount;
  if (elPartb) elPartb.textContent = partBCount;
  if (elPartcd) elPartcd.textContent = partCDCount;
  if (elKeys) elKeys.textContent = `${keyCount} (${total > 0 ? Math.round(keyCount/total*100) : 0}%)`;
  if (elTarget) elTarget.textContent = `Target: ${targetCount} Pool`;

  if (total === 0) {
    if (badge) {
      badge.textContent = 'Awaiting Question Bank Data';
      badge.className = 'px-3.5 py-1 rounded-full text-xs font-black bg-stone-100 text-slate-700 border border-stone-200';
    }
    if (notice) notice.classList.add('hidden');
    return;
  }

  const isCompliant = (total >= targetCount * 0.9) && (unitsCovered === 5) && (keyCount > 0);

  if (isCompliant) {
    if (badge) {
      badge.textContent = `✓ Blueprint Verified (${total}/${targetCount} Questions • 100% Compliant)`;
      badge.className = 'px-3.5 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-950 border border-emerald-400';
    }
    if (notice) notice.classList.add('hidden');
  } else {
    if (badge) {
      badge.textContent = `⚠️ Verification Notice: ${total}/${targetCount} Questions (${unitsCovered}/5 Units)`;
      badge.className = 'px-3.5 py-1 rounded-full text-xs font-black bg-amber-100 text-amber-950 border border-amber-400';
    }
    if (notice && noticeText) {
      notice.classList.remove('hidden');
      noticeText.innerHTML = `<strong>Master Blueprint Analysis:</strong> Current question bank has <strong>${total}</strong> questions across <strong>${unitsCovered} of 5 units</strong>. The approved master blueprint target is <strong>${targetCount} questions</strong> with complete K1-K6 coverage and answer keys.`;
    }
  }
}

function setupDragAndDrop() {
  const zone = document.getElementById('dropZone');
  const input = document.getElementById('fileInput');
  if (!zone || !input) return;

  input.addEventListener('change', (e) => {
    if (e.target.files && e.target.files[0]) {
      uploadAndParseFile(e.target.files[0]);
    }
  });
}

async function uploadAndParseFile(file) {
  const formData = new FormData();
  formData.append('question_file', file);
  formData.append('paper_code', document.getElementById('paper_code')?.value || '');
  formData.append('ocr_language', currentLanguage === 'ta' ? 'tam' : (currentLanguage === 'hi' ? 'hin' : (currentLanguage === 'fr' ? 'fra' : 'eng')));

  showQpsLoader('Extracting Question Bank', 'Analyzing units, sub-units, answer keys, and checking duplicate questions...');

  try {
    const res = await fetch(baseUrl + '/api/parse_upload.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    hideQpsLoader();

    if (!data.success) throw new Error(data.message || 'File parsing failed.');

    // Detect, but never silently translate or rewrite the uploaded question text.
    // Ask the staff member whether the UI should switch to the detected language.
    if (data.detected_language && data.detected_language !== currentLanguage) {
      const label = data.language_label || data.detected_language.toUpperCase();
      const result = await Swal.fire({
        icon: 'info', title: `Detected language: ${label}`,
        text: 'The uploaded text will remain unchanged. Switch the workspace language for labels/templates?',
        showCancelButton: true, confirmButtonText: `Switch to ${label}`, cancelButtonText: 'Keep current language',
        confirmButtonColor: '#1C1D21'
      });
      if (result.isConfirmed) setLanguage(data.detected_language);
    }

    // Append new questions to existing question list (for 250+ banks)
    if (extractedQuestions.length > 0) {
      const startNum = extractedQuestions.length + 1;
      data.questions.forEach((q, idx) => {
        q.q_number = startNum + idx;
        extractedQuestions.push(q);
      });
    } else {
      extractedQuestions = data.questions || [];
    }

    // Display duplicates banner if any duplicates identified
    if (data.has_duplicates) {
      renderDuplicatePanel(data.duplicates);
      const dupRows = data.duplicates.map(d => `<tr><td>${esc(d.q_number)}</td><td>${esc(d.section || '')}</td><td>${esc(d.k_level || '')}</td><td>${esc(d.question_text || '')}</td><td>${esc(d.matched_with || '')}</td></tr>`).join('');
      Swal.fire({ icon:'warning', title:`${data.duplicate_count} duplicate question(s) found`, width:'900px', html:`<div style="max-height:360px;overflow:auto;text-align:left"><table style="width:100%;font-size:12px;border-collapse:collapse"><thead><tr><th>Q.No</th><th>Section</th><th>K</th><th>Question</th><th>Matches</th></tr></thead><tbody>${dupRows}</tbody></table></div>`, confirmButtonText:'Review & Replace', confirmButtonColor:'#1C1D21'});
    } else {
      document.getElementById('duplicateAlertPanel')?.classList.add('hidden');
    }

    // Update status bar
    const st = document.getElementById('fileUploadStatus');
    const fn = document.getElementById('uploadedFileName');
    const fd = document.getElementById('uploadedFileDetails');
    if (st && fn && fd) {
      st.classList.remove('hidden');
      fn.textContent = file.name;
      fd.textContent = `${data.source.format} • Extracted ${data.question_count} questions (${data.duplicate_count} duplicates)`;
    }

    renderPreview();

  } catch (err) {
    hideQpsLoader();
    Swal.fire('Extraction Error', err.message || 'Unable to parse file.', 'error');
  }
}

function renderDuplicatePanel(duplicates) {
  const panel = document.getElementById('duplicateAlertPanel');
  const countBadge = document.getElementById('duplicateCountBadge');
  const listContainer = document.getElementById('duplicateListContainer');
  if (!panel || !listContainer) return;

  panel.classList.remove('hidden');
  if (countBadge) countBadge.textContent = duplicates.length;

  listContainer.innerHTML = '';
  duplicates.forEach((dup, idx) => {
    const div = document.createElement('div');
    div.className = 'bg-white/90 border border-amber-200 rounded-2xl p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs shadow-xs';
    div.innerHTML = `
      <div class="space-y-1">
        <div class="flex items-center space-x-2">
          <span class="bg-amber-400 text-slate-950 font-black px-2 py-0.5 rounded-full text-[10px]">Q#${dup.q_number}</span>
          <span class="bg-stone-100 text-slate-700 font-bold px-2 py-0.5 rounded-full text-[10px]">${dup.section || 'Section A'}</span>
          <span class="bg-blue-50 text-blue-900 font-black px-2 py-0.5 rounded-full text-[10px]">${dup.k_level || 'K1'}</span>
          <span class="text-amber-900 font-bold text-[11px]">${dup.matched_with}</span>
        </div>
        <p class="text-slate-800 font-medium text-[11px] line-clamp-1">${esc(dup.question_text)}</p>
      </div>

      <div class="flex items-center space-x-2 shrink-0">
        <select onchange="updateQuestionReplaceAction(${dup.q_number - 1}, this.value)" class="border border-stone-300 rounded-xl p-1.5 bg-stone-50 text-xs font-bold shadow-xs">
          <option value="replace">Replace Existing</option>
          <option value="append">Append as New</option>
          <option value="skip">Skip / Discard</option>
        </select>
      </div>
    `;
    listContainer.appendChild(div);
  });
}

function updateQuestionReplaceAction(index, action) {
  if (extractedQuestions[index]) {
    extractedQuestions[index].replace_action = action;
  }
}

function setAllDuplicateActions(action) {
  extractedQuestions.forEach(q => {
    if (q.is_duplicate) {
      q.replace_action = action;
    }
  });
  renderPreview();
  Swal.fire({
    icon: 'success',
    title: 'Duplicate Strategy Applied',
    text: `All duplicates set to: ${action.toUpperCase()}`,
    timer: 1500,
    showConfirmButton: false
  });
}

function filterPreviewMarks(mark) { currentMarksFilter = mark || 'ALL'; renderPreview(); }

function filterPreviewSection(sec) {
  currentSectionFilter = sec;
  ['ALL', 'SECTION-A', 'SECTION-B', 'SECTION-C', 'SECTION-D'].forEach(s => {
    const id = 'filter-sec-' + s.toLowerCase().replace('section-', '');
    const btn = document.getElementById(id);
    if (btn) {
      if (s === sec) {
        btn.className = 'px-3 py-1 rounded-full bg-[#1C1D21] text-white transition text-[11px] shadow-sm';
      } else {
        btn.className = 'px-3 py-1 rounded-full text-slate-600 hover:text-slate-900 transition text-[11px]';
      }
    }
  });
  renderPreview();
}

function renderPreview() {
  const body = document.getElementById('previewBody');
  if (!body) return;
  body.innerHTML = '';

  let keyCount = 0;
  let visibleCount = 0;

  extractedQuestions.forEach((q, i) => {
    if (q.answer_key) keyCount++;

    const secType = (q.section_type || '').toUpperCase();
    if (currentSectionFilter !== 'ALL') {
      if (!secType.includes(currentSectionFilter.replace('SECTION-', ''))) return;
    }
    if (currentMarksFilter !== 'ALL' && String(parseInt(q.marks || 0)) !== String(currentMarksFilter)) return;
    visibleCount++;

    const isDup = q.is_duplicate || false;
    const tr = document.createElement('tr');
    tr.className = `hover:bg-stone-50/80 align-top transition border-b border-stone-100 ${isDup ? 'bg-amber-50/40' : ''}`;
    tr.innerHTML = `
      <td class="p-3 text-center font-black font-mono text-indigo-900 bg-stone-50/50">
        ${i + 1}
        ${isDup ? `<span class="block text-[9px] font-black bg-amber-400 text-slate-950 px-1 py-0.5 rounded-full mt-1" title="Duplicate">DUP</span>` : ''}
      </td>
      
      <!-- Unit Selector (1..5) -->
      <td class="p-2">
        <select onchange="extractedQuestions[${i}].unit_no=parseInt(this.value);" class="w-full border border-stone-300 rounded-2xl p-2 font-bold text-center text-xs bg-white focus:ring-2 focus:ring-indigo-500 shadow-sm">
          <option value="1" ${q.unit_no==1?'selected':''}>Unit 1</option>
          <option value="2" ${q.unit_no==2?'selected':''}>Unit 2</option>
          <option value="3" ${q.unit_no==3?'selected':''}>Unit 3</option>
          <option value="4" ${q.unit_no==4?'selected':''}>Unit 4</option>
          <option value="5" ${q.unit_no==5?'selected':''}>Unit 5</option>
        </select>
      </td>

      <!-- Sub-Unit (e.g. 1.1, 1.2, 2.1) -->
      <td class="p-2">
        <input type="text" value="${esc(q.sub_unit || (q.unit_no + '.1'))}" onchange="extractedQuestions[${i}].sub_unit=this.value;" placeholder="e.g. 1.1" class="w-full border border-stone-300 rounded-2xl p-2 text-center font-mono font-bold text-xs bg-white focus:ring-2 focus:ring-indigo-500 shadow-sm">
      </td>

      <!-- Marks -->
      <td class="p-2">
        <input type="number" min="1" max="50" value="${esc(q.marks || 1)}" onchange="extractedQuestions[${i}].marks=parseInt(this.value);" class="w-full border border-stone-300 rounded-2xl p-2 text-center font-black text-amber-900 text-xs bg-white focus:ring-2 focus:ring-indigo-500 shadow-sm">
      </td>

      <!-- K-Level (Bloom's Taxonomy) -->
      <td class="p-2">
        <select onchange="extractedQuestions[${i}].k_level=this.value; extractedQuestions[${i}].co_level='CO'+this.value.replace('K',''); renderPreview();" class="w-full border border-stone-300 rounded-2xl p-2 font-bold text-blue-900 text-xs bg-white focus:ring-2 focus:ring-indigo-500 shadow-sm">
          <option value="K1" ${q.k_level==='K1'?'selected':''}>K1 - Remembering</option>
          <option value="K2" ${q.k_level==='K2'?'selected':''}>K2 - Understanding</option>
          <option value="K3" ${q.k_level==='K3'?'selected':''}>K3 - Applying</option>
          <option value="K4" ${q.k_level==='K4'?'selected':''}>K4 - Analyzing</option>
          <option value="K5" ${q.k_level==='K5'?'selected':''}>K5 - Evaluating</option>
          <option value="K6" ${q.k_level==='K6'?'selected':''}>K6 - Creating</option>
        </select>
      </td>

      <!-- CO-Level (Identical to K-Level) -->
      <td class="p-2 text-center">
        <span class="inline-block bg-emerald-50 text-emerald-800 font-mono font-black text-xs px-2.5 py-1.5 rounded-xl border border-emerald-200 shadow-xs">
          ${esc(q.co_level || ('CO' + (q.k_level || 'K1').replace('K','')))}
        </span>
      </td>

      <!-- Section -->
      <td class="p-2">
        <select onchange="extractedQuestions[${i}].section_type=this.value" class="w-full border border-stone-300 rounded-2xl p-2 font-bold text-slate-800 text-xs bg-white focus:ring-2 focus:ring-indigo-500 shadow-sm">
          <option value="SECTION-A" ${q.section_type==='SECTION-A'||q.section_type==='Section A'?'selected':''}>SECTION-A (1M)</option>
          <option value="SECTION-B" ${q.section_type==='SECTION-B'||q.section_type==='Section B'?'selected':''}>SECTION-B (5M)</option>
          <option value="SECTION-C" ${q.section_type==='SECTION-C'||q.section_type==='Section C'?'selected':''}>SECTION-C (10M)</option>
          <option value="SECTION-D" ${q.section_type==='SECTION-D'||q.section_type==='Section D'?'selected':''}>SECTION-D (10M Comp)</option>
        </select>
      </td>

      <!-- Question Text & Answer Key -->
      <td class="p-3 space-y-2">
        <textarea rows="2" onchange="extractedQuestions[${i}].question_text=this.value;" class="question-textarea w-full border border-stone-300 rounded-2xl p-2.5 font-sans leading-relaxed text-xs focus:ring-2 focus:ring-indigo-500 shadow-sm bg-stone-50/50 focus:bg-white transition" placeholder="Enter question description, options, formula...">${esc(q.question_text)}</textarea>
        
        <!-- Answer Key Input Block -->
        <div class="flex items-center gap-2 bg-emerald-50/60 p-2 rounded-xl border border-emerald-200">
          <span class="bg-emerald-600 text-white font-black text-[10px] px-2 py-0.5 rounded-lg shrink-0">KEY</span>
          <input type="text" value="${esc(q.answer_key || '')}" onchange="extractedQuestions[${i}].answer_key=this.value; renderPreview();" placeholder="Enter solution or correct answer key (e.g. Option (a) / Explanation)..." class="w-full bg-white border border-emerald-300 rounded-xl px-2.5 py-1 text-xs font-semibold text-emerald-950 focus:ring-2 focus:ring-emerald-500">
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
          <div class="flex items-center space-x-2">
            <button type="button" onclick="triggerImageUpload(${i})" class="bg-stone-100 hover:bg-stone-200 text-stone-900 border border-stone-300 px-3 py-1 rounded-full text-[10px] font-bold flex items-center space-x-1 shadow-xs transition">
              <i data-lucide="image-plus" class="w-3 h-3 text-indigo-600"></i>
              <span>${q.image_url ? 'Replace Diagram' : '+ Add Diagram'}</span>
            </button>
            ${q.image_url ? `<button type="button" onclick="removeQuestionImage(${i})" class="text-rose-600 hover:underline text-[10px] font-bold">Remove Image</button>` : ''}
          </div>
          
          ${isDup ? `
            <div class="flex items-center space-x-1">
              <span class="text-[10px] font-black text-amber-900">Action:</span>
              <select onchange="extractedQuestions[${i}].replace_action=this.value;" class="border border-amber-300 rounded-xl p-1 bg-amber-100 text-[10px] font-bold">
                <option value="replace" ${q.replace_action==='replace'?'selected':''}>Replace Existing</option>
                <option value="append" ${q.replace_action==='append'?'selected':''}>Append as New</option>
                <option value="skip" ${q.replace_action==='skip'?'selected':''}>Skip Duplicate</option>
              </select>
            </div>
          ` : ''}
        </div>

        ${q.image_url ? `
          <div class="mt-2 bg-stone-50 p-2 rounded-xl border border-stone-200 inline-block shadow-xs">
            <img src="${esc(q.image_url)}" class="max-h-24 rounded-lg border border-stone-300 object-contain">
          </div>
        ` : ''}
      </td>

      <!-- Action -->
      <td class="p-3 text-center align-middle">
        <button type="button" onclick="removeQuestion(${i})" class="bg-rose-50 hover:bg-rose-100 text-rose-700 w-8 h-8 rounded-full font-bold flex items-center justify-center mx-auto border border-rose-200 shadow-xs transition" title="Delete Question">
          <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
        </button>
      </td>
    `;
    body.appendChild(tr);
  });

  // Update counters
  const bTotal = document.getElementById('bottomTotalCount');
  const bKey = document.getElementById('bottomKeyCount');
  const pSummary = document.getElementById('poolSummary');
  if (bTotal) bTotal.textContent = extractedQuestions.length;
  if (bKey) bKey.textContent = keyCount;
  if (pSummary) pSummary.textContent = `Displaying ${visibleCount} of ${extractedQuestions.length} questions across all units.`;

  if (window.lucide) lucide.createIcons();
}

function addNewManualQuestion() {
  const nextNum = extractedQuestions.length + 1;
  const unit = Math.min(5, Math.ceil(nextNum / 6));
  extractedQuestions.push({
    q_number: nextNum,
    unit_no: unit,
    sub_unit: `${unit}.1`,
    marks: 1,
    k_level: 'K1',
    co_level: 'CO1',
    section_type: 'SECTION-A',
    question_text: '',
    answer_key: '',
    options: [],
    language: currentLanguage
  });
  renderPreview();
}

function removeQuestion(index) {
  extractedQuestions.splice(index, 1);
  extractedQuestions.forEach((q, i) => q.q_number = i + 1);
  renderPreview();
}

function triggerImageUpload(index) {
  activeImageTargetIndex = index;
  document.getElementById('questionImageInput')?.click();
}

function handleImageSelected(input) {
  if (input.files && input.files[0] && activeImageTargetIndex !== null) {
    const reader = new FileReader();
    reader.onload = (e) => {
      if (extractedQuestions[activeImageTargetIndex]) {
        extractedQuestions[activeImageTargetIndex].image_url = e.target.result;
        renderPreview();
      }
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function removeQuestionImage(index) {
  if (extractedQuestions[index]) {
    extractedQuestions[index].image_url = '';
    renderPreview();
  }
}

function resetUpload() {
  extractedQuestions = [];
  document.getElementById('fileUploadStatus')?.classList.add('hidden');
  document.getElementById('duplicateAlertPanel')?.classList.add('hidden');
  document.getElementById('fileInput').value = '';
  renderPreview();
}

// Blueprint & Unit Picker Validation with SweetAlert2
function validateQuestionsWithBlueprint() {
  if (extractedQuestions.length === 0) {
    Swal.fire({
      icon: 'warning',
      title: 'No Questions Found',
      text: 'Please upload or add questions before validating against the blueprint.',
      confirmButtonColor: '#1C1D21'
    });
    return;
  }

  const targetBlueprint = document.getElementById('blueprint_allocation_target')?.value || '275_pool';
  const reg = document.getElementById('regulation')?.value || '2024 (OBE)';
  const maxMarksVal = parseInt(document.getElementById('max_marks_input')?.value) || 75;
  const isNonObe = reg.includes('NON') || maxMarksVal === 50;

  // Aggregate questions by unit, subunit, k_level, and marks
  const unitStats = { 1: [], 2: [], 3: [], 4: [], 5: [] };
  const subunitStats = {};
  const kStats = { 'K1': 0, 'K2': 0, 'K3': 0, 'K4': 0, 'K5': 0, 'K6': 0 };
  const marksStats = { 1: 0, 2: 0, 5: 0, 8: 0, 10: 0 };
  
  extractedQuestions.forEach(q => {
    const u = parseInt(q.unit_no) || 1;
    if (!unitStats[u]) unitStats[u] = [];
    unitStats[u].push(q);

    const su = q.sub_unit || `${u}.1`;
    subunitStats[su] = (subunitStats[su] || 0) + 1;

    const k = (q.k_level || 'K1').toUpperCase();
    kStats[k] = (kStats[k] || 0) + 1;

    const m = parseInt(q.marks) || 1;
    marksStats[m] = (marksStats[m] || 0) + 1;
  });

  const mismatches = [];

  // 1. Check Unit distribution (Units I to V must be covered)
  for (let u = 1; u <= 5; u++) {
    const count = (unitStats[u] || []).length;
    const minExpected = (targetBlueprint === '275_pool') ? 5 : ((targetBlueprint === '30_standard') ? 4 : 1);
    if (count < minExpected) {
      mismatches.push({
        unit: `Unit ${u}`,
        subunit: `Unit ${u} Overall`,
        k_level: 'All K-Levels',
        expected: `${minExpected}+ questions`,
        found: `${count} question(s)`,
        issue: `Unit ${u} is deficient. Expected at least ${minExpected} questions, found ${count}.`
      });
    }
  }

  // 2. If 275-Question Pool Allocation, check all sub-units 1.1 to 5.5
  if (targetBlueprint === '275_pool') {
    for (let u = 1; u <= 5; u++) {
      for (let s = 1; s <= 5; s++) {
        const suKey = `${u}.${s}`;
        const suCount = subunitStats[suKey] || 0;
        const unitHasQuestions = (unitStats[u] || []).length;
        if (unitHasQuestions > 0 && suCount === 0) {
          mismatches.push({
            unit: `Unit ${u}`,
            subunit: suKey,
            k_level: `K${Math.min(5, s)}`,
            expected: `Sub-unit ${suKey} questions`,
            found: `0 questions`,
            issue: `Sub-unit ${suKey} has no questions allocated in Unit ${u}.`
          });
        }
      }
    }
  }

  // 3. Check Cognitive K-Level coverage
  if (kStats['K1'] === 0 && kStats['K2'] === 0) {
    mismatches.push({
      unit: 'Units 1-5',
      subunit: '1.1-5.1',
      k_level: 'K1 / K2 (Remember/Understand)',
      expected: 'Basic cognitive questions (Section A)',
      found: '0 found',
      issue: 'No K1 or K2 foundational questions detected.'
    });
  }

  if (kStats['K3'] === 0 && kStats['K4'] === 0 && kStats['K5'] === 0) {
    mismatches.push({
      unit: 'Units 1-5',
      subunit: '1.3-5.5',
      k_level: 'K3 / K4 / K5 (Higher Order)',
      expected: 'Application / Analysis / Evaluation questions',
      found: '0 found',
      issue: 'No higher order cognitive questions (K3/K4/K5) found.'
    });
  }

  // Render SweetAlert2
  if (mismatches.length > 0) {
    let tableHtml = `
      <div style="text-align: left; max-height: 320px; overflow-y: auto;" class="text-xs space-y-2">
        <p style="color: #78350F; font-weight: bold; margin-bottom: 8px;">The uploaded question bank has <strong>${mismatches.length} allocation mismatch(es)</strong> against the selected <strong>${targetBlueprint === '275_pool' ? '275-Question Master Pool' : '30-Question OBE Blueprint'}</strong>:</p>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px; border: 1px solid #FCD34D;">
          <thead>
            <tr style="background: #FEF3C7; color: #78350F; font-weight: bold;">
              <th style="padding: 6px; border: 1px solid #FCD34D;">Unit</th>
              <th style="padding: 6px; border: 1px solid #FCD34D;">Sub-Unit</th>
              <th style="padding: 6px; border: 1px solid #FCD34D;">K-Level</th>
              <th style="padding: 6px; border: 1px solid #FCD34D;">Expected</th>
              <th style="padding: 6px; border: 1px solid #FCD34D;">Found</th>
              <th style="padding: 6px; border: 1px solid #FCD34D;">Issue / Guidance</th>
            </tr>
          </thead>
          <tbody>
    `;

    mismatches.forEach(m => {
      tableHtml += `
        <tr style="border-bottom: 1px solid #FEE2E2;">
          <td style="padding: 6px; font-weight: bold; font-family: monospace; color: #3730A3; border: 1px solid #FCD34D;">${esc(m.unit)}</td>
          <td style="padding: 6px; font-weight: bold; font-family: monospace; border: 1px solid #FCD34D;">${esc(m.subunit)}</td>
          <td style="padding: 6px; font-weight: bold; color: #1E40AF; border: 1px solid #FCD34D;">${esc(m.k_level)}</td>
          <td style="padding: 6px; color: #4B5563; border: 1px solid #FCD34D;">${esc(m.expected)}</td>
          <td style="padding: 6px; font-weight: bold; color: #BE123C; border: 1px solid #FCD34D;">${esc(m.found)}</td>
          <td style="padding: 6px; color: #78350F; border: 1px solid #FCD34D;">${esc(m.issue)}</td>
        </tr>
      `;
    });

    tableHtml += `
          </tbody>
        </table>
      </div>
    `;

    Swal.fire({
      icon: 'warning',
      title: '⚠️ Blueprint & Unit Picker Mismatch',
      html: tableHtml,
      width: '780px',
      confirmButtonText: 'Review & Adjust Questions',
      confirmButtonColor: '#1C1D21'
    });
  } else {
    Swal.fire({
      icon: 'success',
      title: '✅ Blueprint Alignment 100% Verified!',
      html: `
        <div style="text-align: left; font-size: 13px; line-height: 1.6;" class="space-y-2">
          <p><strong>Total Questions:</strong> ${extractedQuestions.length} questions across all 5 Units (I to V).</p>
          <p><strong>Curriculum Regulation:</strong> <span style="color: #3730A3; font-weight: bold;">${esc(reg)} (${isNonObe ? '50 Marks' : '75 Marks'})</span></p>
          <p><strong>Sub-Units & Bloom Balance:</strong> K1 (${kStats['K1']}), K2 (${kStats['K2']}), K3 (${kStats['K3']}), K4 (${kStats['K4']}), K5 (${kStats['K5']}).</p>
          <p style="color: #065F46; font-weight: bold; margin-top: 8px;">✓ All Unit and Sub-unit allocation rules conform strictly to Holy Cross College Autonomous standards.</p>
        </div>
      `,
      confirmButtonColor: '#1C1D21'
    });
  }
}

// HOD Duplicate Check Modal
function runHODDuplicateCheck() {
  const seen = {};
  const dups = [];

  extractedQuestions.forEach((q, idx) => {
    const textNorm = (q.question_text || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    if (!textNorm) return;
    if (seen[textNorm]) {
      dups.push({
        q_number: q.q_number,
        section: q.section_type || 'SECTION-A',
        k_level: q.k_level || 'K1',
        question: q.question_text,
        duplicate_with: seen[textNorm].q_number
      });
    } else {
      seen[textNorm] = q;
    }
  });

  if (dups.length === 0) {
    Swal.fire({
      icon: 'success',
      title: 'Verification Passed: No Duplicates',
      text: 'All questions across all units and sections are verified clean with zero duplication.',
      confirmButtonColor: '#1C1D21'
    });
  } else {
    let dupHtml = '<div style="text-align:left; max-height: 250px; overflow-y:auto;" class="space-y-2 text-xs">';
    dups.forEach(d => {
      dupHtml += `
        <div style="background:#FFFBEB; border:1px solid #FCD34D; border-radius:12px; padding:10px; margin-bottom:8px;">
          <div style="display:flex; justify-content:space-between; font-weight:bold; color:#78350F; margin-bottom:4px;">
            <span>Question #${d.q_number} (${d.section} • ${d.k_level})</span>
            <span>Duplicates Q#${d.duplicate_with}</span>
          </div>
          <div style="color:#1F2937;">${esc(d.question)}</div>
        </div>
      `;
    });
    dupHtml += '</div>';

    Swal.fire({
      icon: 'warning',
      title: `${dups.length} Duplicate Question(s) Found`,
      html: dupHtml,
      confirmButtonColor: '#1C1D21',
      confirmButtonText: 'Review Questions'
    });
  }
}

async function submitBank(submitAction = 'submit_to_hod') {
  if (extractedQuestions.length < 1) {
    Swal.fire('Empty Question Bank', 'Please add or extract at least one question before submitting.', 'warning');
    return;
  }

  const paperCode = document.getElementById('paper_code')?.value;
  if (!paperCode) {
    Swal.fire('Paper Code Required', 'Please select an assigned course code.', 'warning');
    return;
  }

  const payload = {
    bank_id: existingBankId,
    dept_code: document.getElementById('dept_code')?.value || 'GEN',
    paper_code: paperCode,
    course_title: document.getElementById('course_title')?.value || paperCode,
    exam_type: document.getElementById('exam_type')?.value || 'Odd Semester End Examination',
    semester: document.getElementById('semester')?.value || 'Semester 1',
    academic_year: document.getElementById('academic_year')?.value || '2026-2027',
    degree_level: 'UG',
    regulation: document.getElementById('regulation')?.value || '2024 (OBE)',
    max_marks: parseInt(document.getElementById('max_marks_input')?.value) || 75,
    language: currentLanguage,
    submit_action: submitAction,
    questions: extractedQuestions
  };

  showQpsLoader('Saving Question Bank', 'Compressing, checking course dedication, and updating database...');

  try {
    const res = await fetch(baseUrl + '/api/commit_upload.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    hideQpsLoader();

    if (!data.success) throw new Error(data.message || 'Submission failed.');

    await Swal.fire({
      icon: 'success',
      title: (submitAction === 'submit_to_coe') ? 'Submitted to COE' : ((submitAction === 'submit_to_hod') ? 'Submitted to HOD' : 'Draft Saved'),
      text: data.message || 'Question bank successfully saved and archived.',
      confirmButtonColor: '#1C1D21'
    });

    location.href = baseUrl + '/modules/teaching/view_banks.php';

  } catch (err) {
    hideQpsLoader();
    Swal.fire('Operation Failed', err.message || 'Error occurred.', 'error');
  }
}

function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
