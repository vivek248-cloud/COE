<?php
/**
 * Interactive Question Bank Entry & Draft Management
 * Holy Cross College (Autonomous) - Examination Management System
 * Dynamic Course Loading, Section Rule Automation (1M, 5M, 10M),
 * Real-Time Question Bank Blueprint Matrix, Diagram Attachments & HOD Review Flow
 */
define('PAGE_TITLE', 'Interactive Question Bank Entry');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/question_bank_blueprint.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();
qps_ensure_aux_schema($pdo);
qps_ensure_question_draft_schema($pdo);

$isCoe = isCOE();
$isHod = isHOD();

// Fetch assigned courses
$assignedCourses = [];
try {
    if ($isCoe) {
        $st = $pdo->query("SELECT c.coursecode as papercode, c.coursetitle, c.dept_code, c.level, c.maxmark, c.credit, c.type, d.name as dept_name FROM courses c LEFT JOIN departments d ON d.code = c.dept_code ORDER BY c.coursecode ASC");
        $assignedCourses = $st->fetchAll(PDO::FETCH_ASSOC);
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

        if (empty($assignedCourses)) {
            $userDept = $user['dept_code'] ?? '';
            $stF = $pdo->prepare("SELECT c.coursecode as papercode, c.coursetitle, c.dept_code, c.level, c.maxmark, c.credit, c.type, d.name as dept_name FROM courses c LEFT JOIN departments d ON d.code = c.dept_code WHERE UPPER(c.dept_code) = UPPER(?) OR ? = '' ORDER BY c.coursecode ASC LIMIT 50");
            $stF->execute([$userDept, $userDept]);
            $assignedCourses = $stF->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {}

$defaultCourse = !empty($assignedCourses) ? $assignedCourses[0]['papercode'] : '';
$selectedCourseCode = strtoupper(trim((string)($_GET['paper_code'] ?? ($_GET['course'] ?? $defaultCourse))));
$departments = array_unique(array_map(fn($c) => $c['dept_code'], $assignedCourses));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<style>

  .qbank-hero { position: relative; isolation: isolate; overflow: hidden; border-color: #e2e8f0; box-shadow: 0 12px 32px rgba(15, 23, 42, .06); }
  .qbank-hero::after { content: ''; position: absolute; z-index: -1; width: 18rem; height: 18rem; right: -7rem; top: -11rem; border-radius: 9999px; background: radial-gradient(circle, rgba(99,102,241,.12), transparent 68%); pointer-events: none; }
  .qbank-hero h1 { font-size: clamp(1.25rem, 2vw, 1.65rem); line-height: 1.2; }
  .qbank-hero .qbank-hero-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .625rem; }
  .qbank-hero .qbank-hero-actions a { min-height: 2.75rem; white-space: nowrap; }
  .qbank-overview { border-color: #e2e8f0; box-shadow: 0 12px 32px rgba(15, 23, 42, .05); }
  .qbank-course-grid { grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr) minmax(15rem, .9fr); }
  .qbank-course-grid > div { grid-column: auto / span 1 !important; }
  .course-select-wrap .qps-searchable-wrapper { display: block !important; width: 100% !important; }
  .course-select-wrap .qps-searchable-trigger { min-height: 3rem; padding-left: 2.75rem !important; padding-right: 2.75rem !important; border-color: #cbd5e1; background: #f8fafc; }
  .course-select-wrap .qps-searchable-trigger:hover { background: #fff; border-color: #a5b4fc; }
  .course-select-wrap .qps-searchable-trigger > span:first-child { min-width: 0; flex: 1 1 auto; line-height: 1.35; white-space: normal !important; overflow-wrap: anywhere; }
  #courseCodeBadge { flex: 0 0 auto; max-width: 8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #draftStatusBadge { min-height: 2.75rem; white-space: nowrap; }
  #btnSubmitHod { min-height: 2.75rem; white-space: nowrap; }
  .qbank-metrics { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .qbank-metrics > div { min-width: 0; min-height: 7rem; display: flex; flex-direction: column; justify-content: space-between; gap: .5rem; padding: 1rem; border-radius: 1rem; transition: transform .18s ease, box-shadow .18s ease; }
  .qbank-metrics > div:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(15, 23, 42, .07); }
  .qbank-metrics > div > span:first-child { display: block; min-height: 1.7rem; line-height: 1.3; overflow-wrap: anywhere; }
  .qbank-metrics > div > div { line-height: 1; }
  .qbank-metrics > div > span:last-child { line-height: 1.35; }
.qbank-tabs {
  position: sticky; top: 60px; z-index: 1000;
  display: flex; align-items: stretch; flex-wrap: wrap; gap: .5rem;
  padding: .35rem;
  border: 1px solid #e2e8f0;
  border-radius: 1rem;
  background: #020618;
}

/* The sliding liquid blob (created by the JS below) */
.qbank-glider {
  position: absolute; left: 0; top: 0; width: 0; height: 0;
  border-radius: .75rem;
  background: linear-gradient(160deg, #ffffff, #f1f5f9);
  box-shadow:
    inset 0 1px 1px rgba(255, 255, 255, .9),
    inset 0 -8px 14px rgba(148, 163, 184, .22),
    0 6px 18px rgba(255, 255, 255, .18);
  opacity: 0;
  pointer-events: none;
  z-index: 0;
  transition:
    left .5s cubic-bezier(.34, 1.45, .5, 1),
    top .5s cubic-bezier(.34, 1.45, .5, 1),
    width .5s cubic-bezier(.34, 1.45, .5, 1),
    height .5s cubic-bezier(.34, 1.45, .5, 1),
    opacity .2s ease;
}
.qbank-glider::before {               /* soft gloss on the top half */
  content: ""; position: absolute; inset: 0 0 50% 0; border-radius: inherit;
  background: linear-gradient(180deg, rgba(255, 255, 255, .9), rgba(255, 255, 255, 0));
}
.qbank-glider.no-anim { transition: none; }

.qbank-tabs > button {
  position: relative; z-index: 1;      /* sits above the blob */
  color: #fff !important;              /* default: white on the dark bar */
  background: transparent !important;
  border: 1px solid transparent !important;
  box-shadow: none !important;
  min-width: 0; min-height: 3rem; flex: 1 1 12rem;
  justify-content: center; text-align: center;
  white-space: normal; line-height: 1.25;
  border-radius: .75rem;
  padding: .65rem 1rem;
  transition: transform .3s cubic-bezier(.34, 1.56, .64, 1), color .25s ease;
}
.qbank-tabs > button > i { flex: 0 0 auto; }
.qbank-tabs > button.border-indigo-600 { font-weight: 600; }

/* Tab currently under the white blob: black text */
.qbank-tabs > button.qbank-under { color: #000 !important; }

.qbank-tabs > button:active { transform: scale(.96); }
.qbank-tabs > button:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }

@media (prefers-reduced-motion: reduce) {
  .qbank-glider { transition: opacity .2s ease; }
}

@media (prefers-reduced-motion: reduce) {
  .qbank-glider { transition: opacity .2s ease; }
}
  .qbank-page :focus-visible { outline: 3px solid rgba(99,102,241,.35); outline-offset: 2px; }
  @media (max-width: 1279px) {
    .qbank-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @media (max-width: 1023px) {
    .qbank-course-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .qbank-course-grid > :last-child { grid-column: 1 / -1 !important; justify-content: flex-start; }
  }
  @media (max-width: 639px) {
    .qbank-page { padding: 1rem; }
    .qbank-hero { padding: 1.125rem; }
    .qbank-hero .qbank-hero-actions { justify-content: stretch; }
    .qbank-hero .qbank-hero-actions a { flex: 1 1 100%; justify-content: center; }
    .qbank-course-grid { grid-template-columns: minmax(0, 1fr); }
    .qbank-course-grid > :last-child { grid-column: auto !important; justify-content: stretch; flex-wrap: wrap; }
    .qbank-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .625rem; }
    .qbank-metrics > div { min-height: 6.75rem; padding: .75rem; }
    .qbank-metrics > div > span:first-child { font-size: 10px; }
    .qbank-tabs > button { flex-basis: 100%; justify-content: flex-start; text-align: left; }
  }
  @media (prefers-reduced-motion: reduce) {
    .qbank-metrics > div { transition: none; }
  }
</style>

<div class="qbank-page mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
  
  <!-- Page Header -->
  <div class="qbank-hero flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
    <div class="flex items-center gap-4">
      <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-white shadow-lg shadow-indigo-200">
        <i data-lucide="edit-3" class="w-7 h-7"></i>
      </div>
      <div>
        <div class="flex items-center gap-2">
          <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Interactive Question Bank Entry</h1>
          <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Auto-Sync
          </span>
        </div>
        <p class="text-sm text-slate-500 mt-0.5">Structured question creation with real-time blueprint compilation and automated section mapping</p>
      </div>
    </div>
    
    <div class="qbank-hero-actions flex items-center gap-3">
      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-all">
        <i data-lucide="layout-grid" class="w-4 h-4 text-slate-600"></i> Blueprint Master
      </a>
      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/view_banks.php" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-xl transition-all">
        <i data-lucide="eye" class="w-4 h-4"></i> View All Banks
      </a>
    </div>
  </div>

  <!-- Course Selector & Live Status Header -->
  <div class="qbank-overview bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
    <div class="qbank-course-grid grid grid-cols-1 md:grid-cols-12 gap-5 items-center">
      <div class="md:col-span-5">
        <label for="courseSelect" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Select Allocated Course</label>
        <div class="relative course-select-wrap">
          <select id="courseSelect" class="w-full pl-10 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all appearance-none">
            <?php foreach ($assignedCourses as $c): ?>
              <option value="<?php echo htmlspecialchars($c['papercode']); ?>" <?php echo (strtoupper($c['papercode']) === $selectedCourseCode) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c['papercode'] . ' - ' . $c['coursetitle']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <i data-lucide="book-open" class="w-5 h-5 text-slate-400 absolute left-3.5 top-3.5 pointer-events-none"></i>
          <!-- <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 absolute right-3.5 top-4 pointer-events-none"></i> -->
        </div>
      </div>

      <div class="md:col-span-4 flex items-center gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200/60">
        <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-800 font-bold text-xs" id="courseCodeBadge">
          <?php echo htmlspecialchars($departments[0] ?? '---'); ?>
        </div>
        <div class="min-w-0 flex-1">
          <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Active Course</h4>
          <p class="text-sm font-semibold text-slate-800 truncate" id="courseTitleText">Loading Course Title...</p>
        </div>
      </div>

      <div class="md:col-span-3 flex justify-end gap-2">
        <div id="draftStatusBadge" class="px-4 py-2 rounded-xl text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-amber-500 animate-ping"></span> Status: <span id="statusLabel">DRAFT</span>
        </div>

      </div>
    </div>

    <!-- Language selection: course is selected above; language is chosen before entering questions -->
    <div class="mt-4 pt-4 border-t border-slate-100">
      <div class="max-w-md">
        <label for="fieldQuestionLanguage" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Question Language</label>
        <select id="fieldQuestionLanguage" onchange="applyQuestionLanguage()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
          <option value="EN">English</option><option value="TL">Tamil</option><option value="HI">Hindi</option><option value="FR">French</option>
        </select>
        <p class="text-[11px] text-slate-400 mt-1">Choose the course first, then select the language for the questions you enter.</p>
      </div>
    </div>

    <!-- Live Metric Cards -->
    <div class="qbank-metrics grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 mt-6 pt-6 border-t border-slate-100">
      <div class="bg-indigo-50/70 p-3.5 rounded-xl border border-indigo-100">
        <span class="text-[11px] font-bold text-indigo-600 uppercase">Total Questions</span>
        <div class="text-2xl font-black text-indigo-900 mt-1" id="statTotalQ">0</div>
        <span class="text-[10px] text-indigo-500 font-medium">In draft pool</span>
      </div>
      <div class="bg-blue-50/70 p-3.5 rounded-xl border border-blue-100">
        <span class="text-[11px] font-bold text-blue-600 uppercase">Sec A: 1M (MCQ)</span>
        <div class="text-2xl font-black text-blue-900 mt-1" id="statSecAMCQ">0</div>
        <span class="text-[10px] text-blue-500 font-medium">Target: 8+ Qs</span>
      </div>
      <div class="bg-cyan-50/70 p-3.5 rounded-xl border border-cyan-100">
        <span class="text-[11px] font-bold text-cyan-600 uppercase">Sec A: Match & AR</span>
        <div class="text-2xl font-black text-cyan-900 mt-1" id="statSecAMatchAR">0</div>
        <span class="text-[10px] text-cyan-500 font-medium">Q9 Match + Q10 AR</span>
      </div>
      <div class="bg-sky-50/70 p-3.5 rounded-xl border border-sky-100">
        <span class="text-[11px] font-bold text-sky-600 uppercase">Sec A: 1M (VSA)</span>
        <div class="text-2xl font-black text-sky-900 mt-1" id="statSecAVSA">0</div>
        <span class="text-[10px] text-sky-500 font-medium">Target: 10+ Qs (Q11-20)</span>
      </div>
      <div class="bg-amber-50/70 p-3.5 rounded-xl border border-amber-100">
        <span class="text-[11px] font-bold text-amber-600 uppercase">Sec B: 5M Pairs</span>
        <div class="text-2xl font-black text-amber-900 mt-1" id="statSecB">0</div>
        <span class="text-[10px] text-amber-500 font-medium">Target: 10+ Qs</span>
      </div>
      <div class="bg-emerald-50/70 p-3.5 rounded-xl border border-emerald-100">
        <span class="text-[11px] font-bold text-emerald-600 uppercase">Sec C/D: 10M</span>
        <div class="text-2xl font-black text-emerald-900 mt-1" id="statSecCD">0</div>
        <span class="text-[10px] text-emerald-500 font-medium">Sec C (4+) & D (1+)</span>
      </div>
      <div class="bg-rose-50/70 p-3.5 rounded-xl border border-rose-100" id="cardCorrections">
        <span class="text-[11px] font-bold text-rose-600 uppercase">Corrections</span>
        <div class="text-2xl font-black text-rose-700 mt-1" id="statCorrections">0</div>
        <span class="text-[10px] text-rose-500 font-medium">Needs revision</span>
      </div>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div class="qbank-tabs flex border-b border-slate-200">
    <button onclick="switchTab('entry')" id="tabBtn-entry" class="px-5 py-3 text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 flex items-center gap-2 transition-all">
      <i data-lucide="plus-circle" class="w-4 h-4"></i> Question Entry Form
    </button>
    <button onclick="switchTab('blueprint')" id="tabBtn-blueprint" class="px-5 py-3 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-2 transition-all">
      <i data-lucide="grid" class="w-4 h-4"></i> Live Blueprint Matrix
    </button>
    <button onclick="switchTab('slots')" id="tabBtn-slots" class="px-5 py-3 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-2 transition-all">
      <i data-lucide="check-square" class="w-4 h-4"></i> Final Question Slot Allocation
    </button>
    <button onclick="switchTab('drafts')" id="tabBtn-drafts" class="px-5 py-3 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-2 transition-all">
      <i data-lucide="layers" class="w-4 h-4"></i> Draft Questions Pool (<span id="draftCountBadge">0</span>)
    </button>
    <button onclick="switchTab('allquestions')" id="tabBtn-allquestions" class="px-5 py-3 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-2 transition-all">
      <i data-lucide="library" class="w-4 h-4"></i> All Questions (<span id="allQuestionsCountBadge">0</span>)
    </button>
  </div>

  <!-- TAB 1: INTERACTIVE QUESTION ENTRY FORM -->
  <div id="tabContent-entry" class="space-y-6">
    <form id="questionForm" onsubmit="handleSaveQuestion(event)" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 space-y-6">
      <input type="hidden" id="editQuestionId" value="0">

      <div class="flex items-center justify-between pb-4 border-b border-slate-100">
        <div class="flex items-center gap-2">
          <span class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 font-bold flex items-center justify-center text-sm" id="formModeIcon">1</span>
          <div><h3 class="text-base font-bold text-slate-800" id="formModeTitle">Add New Question</h3><p id="nextQuestionNumber" class="text-xs font-bold text-indigo-600 mt-1">Next question number: 1</p></div>
        </div>
        <button type="button" onclick="resetQuestionForm()" class="text-xs font-semibold text-slate-500 hover:text-slate-700 flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 transition-all">
          <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> Clear Form
        </button>
      </div>

      <!-- Correction Notice Banner (visible when editing a question that needs correction) -->
      <div id="correctionAlertBanner" class="hidden p-4 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 text-sm flex items-start gap-3">
        <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
        <div>
          <h5 class="font-bold">HOD Correction Remarks:</h5>
          <p id="correctionAlertText" class="mt-1 text-slate-700"></p>
        </div>
      </div>

      <!-- Row 1: Unit, Sub-Unit, Marks & Section Rule -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Unit <span class="text-rose-500">*</span></label>
          <select id="fieldUnit" onchange="updateSubunitOptions()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
            <option value="1">Unit I</option>
            <option value="2">Unit II</option>
            <option value="3">Unit III</option>
            <option value="4">Unit IV</option>
            <option value="5">Unit V</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Sub-Unit <span class="text-rose-500">*</span></label>
          <div class="flex items-center gap-2">
            <select id="fieldSubUnit" onchange="syncCustomSubUnit()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
              <option value="1.1">1.1</option>
              <option value="1.2">1.2</option>
              <option value="1.3">1.3</option>
              <option value="1.4">1.4</option>
              <option value="1.5">1.5</option>
              <option value="1.6">1.6</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Marks <span class="text-rose-500">*</span></label>
          <select id="fieldMarks" onchange="handleMarksChange()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-bold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
            <option value="1">1 Mark (Section A: 20 Marks)</option>
            <option value="5">5 Marks (Section B: 25 Marks)</option>
            <option value="10">10 Marks (Section C & D: 30 Marks)</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Question Type <span class="text-rose-500">*</span></label>
          <select id="fieldType" onchange="handleTypeChange()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
            <!-- Dynamically populated based on Marks -->
          </select>
        </div>
      </div>

      <!-- Row 2: Bloom's Taxonomy & Course Outcome -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Bloom's Taxonomy (K-Level) <span class="text-rose-500">*</span></label>
          <select id="fieldKLevel" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
            <option value="K1">K1 - Remember (Recall facts & basic concepts)</option>
            <option value="K2">K2 - Understand (Explain ideas or concepts)</option>
            <option value="K3">K3 - Apply (Use information in new situations)</option>
            <option value="K4">K4 - Analyze (Draw connections among ideas)</option>
            <option value="K5">K5 - Evaluate (Justify a stand or decision)</option>
            <option value="K6">K6 - Create (Produce new or original work)</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Course Outcome (CO) <span class="text-rose-500">*</span></label>
          <select id="fieldCOLevel" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
            <option value="CO1">CO1</option>
            <option value="CO2">CO2</option>
            <option value="CO3">CO3</option>
            <option value="CO4">CO4</option>
            <option value="CO5">CO5</option>
          </select>
        </div>

        <div id="sectionDCheckboxContainer" class="hidden flex items-center pt-6">
          <label class="inline-flex items-center gap-2.5 cursor-pointer">
            <input type="checkbox" id="fieldIsCompulsory" class="w-5 h-5 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
            <span class="text-xs font-bold text-indigo-900 uppercase">Compulsory Question (Section D / Q29)</span>
          </label>
        </div>
      </div>

      <!-- Question Textarea with Math LaTeX Helper -->
      <div>
        <div class="flex items-center justify-between mb-1.5">
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600">Question Text <span class="text-rose-500">*</span></label>
          <div class="flex items-center gap-1.5 text-xs text-slate-500">
            <span>Insert Math:</span>
            <button type="button" onclick="insertLatex('\\sqrt{x}')" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-mono text-xs">√x</button>
            <button type="button" onclick="insertLatex('\\frac{a}{b}')" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-mono text-xs">a/b</button>
            <button type="button" onclick="insertLatex('\\sum_{i=1}^{n}')" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-mono text-xs">∑</button>
            <button type="button" onclick="insertLatex('\\int_{a}^{b}')" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-mono text-xs">∫</button>
            <button type="button" onclick="insertLatex('\\theta')" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-mono text-xs">θ</button>
          </div>
        </div>
        <textarea id="fieldQuestionText" oninput="renderMathPreview()" rows="3" placeholder="Enter the complete question statement here. For formulas use LaTeX: $E=mc^2$ or $\frac{a}{b}$..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all font-sans text-sm"></textarea>
        <!-- Math Live Preview -->
        <div id="mathPreviewBox" class="mt-2 p-3 bg-slate-50 rounded-xl border border-slate-200/60 text-xs text-slate-600 hidden">
          <span class="font-bold text-slate-400 block mb-1">Live Math Preview:</span>
          <div id="mathPreviewContent" class="text-slate-900 text-sm"></div>
        </div>
      </div>

      <!-- SPECIFIC QUESTION TYPE SUB-CONTAINERS -->

      <!-- 1. MCQ OPTIONS (A, B, C, D) -->
      <div id="containerMCQ" class="space-y-3 bg-slate-50/70 p-4 rounded-xl border border-slate-200/80">
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600">Multiple Choice Options (A, B, C, D) & Answer Key</label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center text-xs flex-shrink-0">A</span>
            <input type="text" id="mcqOptA" placeholder="Option A text..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-800">
          </div>
          <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center text-xs flex-shrink-0">B</span>
            <input type="text" id="mcqOptB" placeholder="Option B text..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-800">
          </div>
          <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center text-xs flex-shrink-0">C</span>
            <input type="text" id="mcqOptC" placeholder="Option C text..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-800">
          </div>
          <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center text-xs flex-shrink-0">D</span>
            <input type="text" id="mcqOptD" placeholder="Option D text..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-800">
          </div>
        </div>
        <div class="flex items-center gap-3 pt-2">
          <span class="text-xs font-bold text-slate-600 uppercase">Correct Option:</span>
          <div class="flex items-center gap-4 text-sm font-semibold">
            <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="mcqCorrect" value="A" checked class="text-indigo-600"> Option A</label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="mcqCorrect" value="B" class="text-indigo-600"> Option B</label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="mcqCorrect" value="C" class="text-indigo-600"> Option C</label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="mcqCorrect" value="D" class="text-indigo-600"> Option D</label>
          </div>
        </div>
      </div>

      <!-- 2. MATCH THE FOLLOWING (Column A & Column B) -->
      <div id="containerMatch" class="hidden space-y-3 bg-slate-50/70 p-4 rounded-xl border border-slate-200/80">
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600">Match Following Pairs (Column A & Column B)</label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><span class="text-xs font-bold text-slate-500 uppercase">Column A</span><div id="matchColumnA" class="space-y-2 mt-2"></div><button type="button" onclick="addMatchRow()" class="mt-2 px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold">+ Add Row</button></div>
          <div><span class="text-xs font-bold text-slate-500 uppercase">Column B (includes one extra option)</span><div id="matchColumnB" class="space-y-2 mt-2"></div><button type="button" onclick="addMatchOption()" class="mt-2 px-3 py-2 rounded-lg bg-sky-50 text-sky-700 text-xs font-bold">+ Add Option</button></div>
        </div>
        <div class="mt-3"><span class="text-xs font-bold text-slate-500 uppercase">Assign Column B options to Column A</span><div id="matchAssignments" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2"></div><p class="text-xs text-indigo-700 mt-2">Answer key: <strong id="matchAnswerKeyPreview">—</strong></p></div>

      </div>

      <!-- 3. ASSERTION & REASON -->
      <div id="containerAR" class="hidden space-y-3 bg-slate-50/70 p-4 rounded-xl border border-slate-200/80">
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600">Assertion & Reason Details</label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Assertion (A):</label>
            <textarea id="arAssertionText" rows="2" placeholder="State Assertion (A)..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm"></textarea>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Reason (R):</label>
            <textarea id="arReasonText" rows="2" placeholder="State Reason (R)..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm"></textarea>
          </div>
        </div>
      </div>

      <!-- Row 4: Diagram / Image Attachment & Answer Key -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Diagram / Image Attachment (Optional)</label>
          <div class="flex items-center gap-3">
            <input type="file" id="diagramFileInput" accept="image/*" onchange="uploadDiagramFile(this)" class="block w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            <input type="hidden" id="fieldImageUrl" value="">
          </div>
          <div id="diagramPreviewContainer" class="hidden mt-2 relative inline-block">
            <img id="diagramPreviewImg" src="" alt="Diagram Preview" class="h-20 w-auto rounded-lg border border-slate-200 shadow-sm object-contain bg-white p-1">
            <button type="button" onclick="removeDiagram()" class="absolute -top-2 -right-2 w-5 h-5 bg-rose-600 text-white rounded-full flex items-center justify-center text-xs shadow-md">✕</button>
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Answer Key / Model Points (Optional)</label>
          <input type="text" id="fieldAnswerKey" placeholder="Correct answer or key valuation points..." class="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
        <button type="button" onclick="resetQuestionForm()" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-all">Cancel</button>
        <button type="submit" id="btnSaveQuestion" class="px-6 py-2.5 rounded-xl text-sm font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-200 flex items-center gap-2 transition-all">
          <i data-lucide="save" class="w-4 h-4"></i> Save Question to Bank Draft
        </button>
      </div>
    </form>
  </div>

  <!-- TAB 2: LIVE QUESTION BANK BLUEPRINT MATRIX -->
  <div id="tabContent-blueprint" class="hidden space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
      <div class="p-6 bg-slate-900 text-white flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <div class="flex items-center gap-2 text-indigo-400 text-xs font-bold uppercase tracking-wider">
            <i data-lucide="matrix" class="w-4 h-4"></i> Live Compiled Question Bank Blueprint
          </div>
          <h2 class="text-xl font-bold tracking-tight mt-0.5" id="blueprintHeaderTitle">Course Blueprint Matrix</h2>
          <p class="text-xs text-slate-400 mt-1">Populated simultaneously as questions are entered. Conforms to Holy Cross College 75-mark standard.</p>
        </div>
        <div class="flex items-center gap-3">
          <span class="px-3 py-1.5 rounded-lg bg-slate-800 text-emerald-400 text-xs font-mono font-bold border border-slate-700">
            Total Marks in Pool: <span id="blueprintTotalMarks">0</span> M
          </span>
          <button type="button" onclick="switchTab('slots')" class="px-4 py-2 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-500 text-white shadow-md flex items-center gap-1.5 transition">
            <i data-lucide="check-square" class="w-4 h-4"></i> Configure Exam Slots &rarr;
          </button>
        </div>
      </div>

      <!-- Blueprint Table Matching sample-blueprint.jpeg -->
      <div class="overflow-x-auto">
        <table class="w-full border-collapse text-center text-xs" id="blueprintMatrixTable">
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
          <tbody id="blueprintMatrixBody" class="divide-y divide-slate-200">
            <!-- Dynamic Sub-unit Rows (1.1 through 5.4) rendered via JS -->
          </tbody>
          <tfoot id="blueprintMatrixFoot" class="bg-slate-100 font-extrabold text-slate-900 border-t-2 border-slate-400">
            <!-- Totals Row rendered via JS -->
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB 3: FINAL QUESTION SLOT ALLOCATION (MASTER BLUEPRINT SPECIFICATION) -->
  <div id="tabContent-slots" class="hidden space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 space-y-6">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
        <div>
          <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 font-bold flex items-center justify-center text-sm">📋</span>
            <h3 class="text-base font-bold text-slate-800">Final Question Slot Allocation (Master Blueprint Specification)</h3>
          </div>
          <p class="text-xs text-slate-500 mt-1">Teaching Staff and HODs define the exact slot blueprint rules (Unit, Subunit, Question Type, Marks, K-Level, CO-Level for Q1–Q30). Actual questions are kept confidential and selected & validated by COE.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
          <button type="button" onclick="applyStandardSlotBlueprint()" class="px-4 py-2 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-slate-950 shadow-sm flex items-center gap-1.5 transition">
            <i data-lucide="sparkles" class="w-4 h-4"></i> Apply Standard OBE Pattern
          </button>
          <button type="button" onclick="saveFinalBlueprintSlots()" class="px-5 py-2 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-200 flex items-center gap-1.5 transition">
            <i data-lucide="save" class="w-4 h-4"></i> Save Master Blueprint Slots
          </button>
        </div>
      </div>

      <!-- Slot Allocation Summary Banner -->
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200/70">
          <span class="text-[11px] font-bold text-slate-500 uppercase">Pattern & Scheme</span>
          <div class="text-lg font-black text-slate-900 mt-0.5" id="slotPatternText">OBE (30 Slots • 75M)</div>
        </div>
        <div class="p-3.5 bg-emerald-50 rounded-xl border border-emerald-200/70">
          <span class="text-[11px] font-bold text-emerald-700 uppercase">Configured Slots</span>
          <div class="text-lg font-black text-emerald-900 mt-0.5" id="slotAllocatedCount">30 / 30 Configured</div>
        </div>
        <div class="p-3.5 bg-blue-50 rounded-xl border border-blue-200/70">
          <span class="text-[11px] font-bold text-blue-700 uppercase">Total Marks</span>
          <div class="text-lg font-black text-blue-900 mt-0.5" id="slotTotalMarks">75 Marks</div>
        </div>
        <div class="p-3.5 bg-indigo-50 rounded-xl border border-indigo-200/70">
          <span class="text-[11px] font-bold text-indigo-700 uppercase">Blueprint Status</span>
          <div class="text-lg font-black text-emerald-600 mt-0.5" id="slotValidationStatus">ACTIVE SPECIFICATION</div>
        </div>
      </div>

      <!-- Confidential Notice Banner for Teaching Staff -->
      <div class="bg-indigo-50/60 border border-indigo-200 rounded-xl p-3 flex items-center justify-between text-xs text-indigo-900">
        <div class="flex items-center space-x-2">
          <i data-lucide="shield" class="w-4 h-4 text-indigo-600 shrink-0"></i>
          <span><strong>Confidentiality Rule:</strong> Teaching staff configure slot parameters alone (Unit, Subunit, Type, Marks, K/CO levels). Question text is hidden to ensure impartial exam paper compilation.</span>
        </div>
        <span class="text-[11px] font-mono font-bold text-indigo-700 bg-white px-2.5 py-0.5 rounded-full border border-indigo-200">OBE Strict Template</span>
      </div>

      <!-- Entered Units Pool Information Banner -->
      <div id="enteredUnitsBanner" class="p-3.5 bg-indigo-50/80 border border-indigo-200 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-indigo-950">
        <div class="flex items-center space-x-2.5">
          <span class="w-6 h-6 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-bold text-xs shrink-0">🎯</span>
          <span id="enteredUnitsBannerText">Loading entered questions units...</span>
        </div>
        <span class="text-[11px] font-bold text-indigo-700 bg-white px-3 py-1 rounded-full border border-indigo-200 shadow-sm self-start sm:self-auto shrink-0" id="enteredUnitsBadge">Entered Units Only</span>
      </div>

      <!-- Interactive Question Slots Container Grouped by Section Colors -->
      <div id="slotsGridContainer" class="space-y-6">
        <!-- Rendered dynamically by renderSlotAllocationTable() -->
      </div>
    </div>
  </div>

  <!-- TAB 4: DRAFT QUESTIONS POOL & CORRECTIONS -->
  <div id="tabContent-drafts" class="hidden space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-100">
        <div>
          <h3 class="text-base font-bold text-slate-800">Draft Questions Pool</h3>
          <p class="text-xs text-slate-500">All questions stored in current draft before HOD / COE approval</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button id="btnSubmitHod" onclick="submitBankToHOD()" class="px-4 py-2.5 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-200 flex items-center gap-2 transition-all">
            <i data-lucide="send" class="w-4 h-4"></i> Submit to HOD
          </button>
          <select id="filterSection" onchange="renderQuestionsList()" class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold">
            <option value="ALL">All Sections</option>
            <option value="SECTION-A">Section A (1M)</option>
            <option value="SECTION-B">Section B (5M)</option>
            <option value="SECTION-C">Section C (10M)</option>
            <option value="SECTION-D">Section D (10M Compulsory)</option>
          </select>
          <select id="filterCorrection" onchange="renderQuestionsList()" class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold">
            <option value="ALL">All Statuses</option>
            <option value="NEEDS_CORRECTION">⚠️ Needs Correction</option>
            <option value="CLEAN">✅ Clean</option>
          </select>
        </div>
      </div>

      <!-- Questions Container -->
      <div id="draftQuestionsList" class="mt-4 space-y-3">
        <!-- Rendered via JS -->
      </div>

      <!-- Combined question preview: attached image and MATCH table -->
      <div id="questionImageModal" class="hidden fixed inset-0 z-[1010] items-center justify-center bg-slate-950/70 p-4" onclick="if(event.target===this)closeQuestionImagePreview()">
        <div class="bg-white w-full max-w-5xl max-h-[92vh] overflow-hidden rounded-2xl shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="questionImageTitle">
          <div class="flex items-center justify-between gap-3 p-4 border-b shrink-0">
            <div><h3 id="questionImageTitle" class="font-bold text-slate-800">Question Preview</h3><p class="text-xs text-slate-500">Question details, matching answers and attachment</p></div>
            <button type="button" onclick="closeQuestionImagePreview()" class="w-9 h-9 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-xl" aria-label="Close">×</button>
          </div>
          <div id="questionPreviewContent" class="p-4 overflow-y-auto space-y-4"></div>
          <div class="flex justify-end p-3 border-t shrink-0"><button type="button" onclick="closeQuestionImagePreview()" class="px-4 py-2 rounded-lg border text-sm font-semibold">Close</button></div>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 5: ALL QUESTIONS (DRAFT + COE APPROVED) -->
  <div id="tabContent-allquestions" class="hidden space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
      <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4 pb-4 border-b border-slate-100">
        <div><h3 class="text-base font-bold text-slate-800">All Questions</h3><p class="text-xs text-slate-500">Draft questions and questions from COE-approved banks for this course</p></div>
        <div class="flex flex-wrap gap-2">
          <input id="allQSearch" oninput="allQPage=1;renderAllQuestions()" type="search" placeholder="Search question..." class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs w-48">
          <select id="allQStatus" onchange="allQPage=1;renderAllQuestions()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs"><option value="ALL">All statuses</option><option value="DRAFT">Draft</option><option value="COE_APPROVED">COE Approved</option></select>
          <select id="allQSection" onchange="allQPage=1;renderAllQuestions()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs"><option value="ALL">All sections</option><option value="SECTION-A">Section A</option><option value="SECTION-B">Section B</option><option value="SECTION-C">Section C</option><option value="SECTION-D">Section D</option></select>
          <select id="allQType" onchange="allQPage=1;renderAllQuestions()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs"><option value="ALL">All types</option><option value="MCQ">MCQ</option><option value="MATCH">Match</option><option value="VSA">VSA</option><option value="AR">Assertion & Reason</option></select>
        </div>
      </div>
      <div id="allQuestionsList" class="mt-4"></div>
      <div id="allQuestionsPagination" class="mt-4 flex flex-wrap items-center justify-between gap-3"></div>
    </div>
  </div>

</div>

<script>
// Global HTML Escape Helper
function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>'"]/g, tag => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#39;',
    '"': '&quot;'
  }[tag] || tag));
}

// State Management
let currentCourseCode = '<?php echo htmlspecialchars($selectedCourseCode); ?>';
let currentDraft = null;
let currentQuestions = [];
let currentMatrix = [];
let coeApprovedQuestions = [];
let allQPage = 1;
const ALL_Q_PAGE_SIZE = 10;

document.addEventListener('DOMContentLoaded', () => {
  if (currentCourseCode) {
    loadCourseData(currentCourseCode);
  }

  document.getElementById('courseSelect').addEventListener('change', function() {
    currentCourseCode = this.value;
    loadCourseData(currentCourseCode);
  });

  handleMarksChange();
  updateSubunitOptions();
  lucide.createIcons();
});

function switchTab(tabId) {
  ['entry', 'blueprint', 'slots', 'drafts', 'allquestions'].forEach(t => {
    const el = document.getElementById(`tabContent-${t}`);
    if (el) el.classList.add('hidden');
    const btn = document.getElementById(`tabBtn-${t}`);
    if (btn) {
      btn.classList.remove('border-indigo-600', 'text-indigo-600');
      btn.classList.add('border-transparent', 'text-slate-500');
    }
  });

  const activeContent = document.getElementById(`tabContent-${tabId}`);
  if (activeContent) activeContent.classList.remove('hidden');
  const activeBtn = document.getElementById(`tabBtn-${tabId}`);
  if (activeBtn) {
    activeBtn.classList.remove('border-transparent', 'text-slate-500');
    activeBtn.classList.add('border-indigo-600', 'text-indigo-600');
  }

  if (tabId === 'blueprint') {
    renderBlueprintMatrix();
  } else if (tabId === 'slots') {
    renderSlotAllocationTable();
  } else if (tabId === 'drafts') {
    renderQuestionsList();
  } else if (tabId === 'allquestions') {
    loadAllQuestions();
  }
}

// ----------------------------------------------------
// FINAL QUESTION SLOT ALLOCATION & MASTER BLUEPRINT ENGINE
// ----------------------------------------------------
let currentSlotsData = [];
let activeSlotNumber = null;

// Extract unique entered units & sub-units strictly from the draft questions pool
function getEnteredUnitsAndSubunits() {
  const unitMap = {};
  
  if (Array.isArray(currentQuestions) && currentQuestions.length > 0) {
    currentQuestions.forEach(q => {
      const u = parseInt(q.unit_no, 10);
      const sub = String(q.sub_unit || `${u}.1`).trim();
      if (!isNaN(u) && u >= 1) {
        if (!unitMap[u]) unitMap[u] = new Set();
        unitMap[u].add(sub);
      }
    });
  }

  if (Array.isArray(currentMatrix) && currentMatrix.length > 0) {
    currentMatrix.forEach(row => {
      const u = parseInt(row.unit, 10);
      const sub = String(row.sub_unit || `${u}.1`).trim();
      if (!isNaN(u) && u >= 1) {
        if (!unitMap[u]) unitMap[u] = new Set();
        unitMap[u].add(sub);
      }
    });
  }

  const sortedUnits = Object.keys(unitMap).map(Number).sort((a,b) => a - b);
  
  if (sortedUnits.length === 0) {
    // If no questions entered yet in draft pool, provide standard fallback units 1-5
    return {
      isFallback: true,
      units: [1, 2, 3, 4, 5],
      subunitsByUnit: {
        1: ['1.1', '1.2', '1.3', '1.4', '1.5'],
        2: ['2.1', '2.2', '2.3', '2.4', '2.5'],
        3: ['3.1', '3.2', '3.3', '3.4', '3.5'],
        4: ['4.1', '4.2', '4.3', '4.4', '4.5'],
        5: ['5.1', '5.2', '5.3', '5.4', '5.5']
      }
    };
  }

  const subunitsByUnit = {};
  sortedUnits.forEach(u => {
    subunitsByUnit[u] = Array.from(unitMap[u]).sort((a,b) => {
      const na = parseFloat(a) || 0;
      const nb = parseFloat(b) || 0;
      return na - nb || a.localeCompare(b);
    });
  });

  return {
    isFallback: false,
    units: sortedUnits,
    subunitsByUnit: subunitsByUnit
  };
}

function getDefaultSlotTemplates(isOBE = true) {
  if (isOBE) {
    const slots = [];
    // Q1 - Q8: MCQ (1M)
    for (let i = 1; i <= 8; i++) {
      const u = ((i - 1) % 5) + 1;
      slots.push({ slot_number: i, section_type: 'SECTION-A', unit_no: u, sub_unit: `${u}.1`, question_type: 'MCQ', k_level: (i <= 4 ? 'K1' : 'K2'), co_level: `CO${u}`, marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    }
    // Q9: Match the Following (1M)
    slots.push({ slot_number: 9, section_type: 'SECTION-A', unit_no: 1, sub_unit: '1.1', question_type: 'MATCH', k_level: 'K1', co_level: 'CO1', marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    // Q10: Assertion & Reason (1M)
    slots.push({ slot_number: 10, section_type: 'SECTION-A', unit_no: 2, sub_unit: '2.1', question_type: 'ASSERTION_REASON', k_level: 'K2', co_level: 'CO2', marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    // Q11 - Q20: Very Short Answer (1M)
    for (let i = 11; i <= 20; i++) {
      const u = ((i - 11) % 5) + 1;
      slots.push({ slot_number: i, section_type: 'SECTION-A', unit_no: u, sub_unit: `${u}.2`, question_type: 'VSA', k_level: (i <= 15 ? 'K1' : 'K2'), co_level: `CO${u}`, marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    }
    // Q21 - Q25: Either / Or Pairs (5M each = 25M)
    for (let i = 21; i <= 25; i++) {
      const u = i - 20;
      slots.push({ slot_number: i, section_type: 'SECTION-B', unit_no: u, sub_unit: `${u}.1`, question_type: 'PARAGRAPH', k_level: (u <= 3 ? 'K3' : 'K4'), co_level: `CO${u}`, marks: 5, is_compulsory: 1, choice_group: `Q${i}_PAIR`, assigned_fixed_q_no: null });
    }
    // Q26 - Q29: Descriptive Essay (10M each - answer 2 = 20M)
    for (let i = 26; i <= 29; i++) {
      const u = i - 25;
      slots.push({ slot_number: i, section_type: 'SECTION-C', unit_no: u, sub_unit: `${u}.1`, question_type: 'ESSAY', k_level: (u <= 2 ? 'K5' : 'K6'), co_level: `CO${u}`, marks: 10, is_compulsory: 0, assigned_fixed_q_no: null });
    }
    // Q30: Compulsory Essay (10M = 10M)
    slots.push({ slot_number: 30, section_type: 'SECTION-D', unit_no: 5, sub_unit: '5.1', question_type: 'ESSAY', k_level: 'K6', co_level: 'CO5', marks: 10, is_compulsory: 1, assigned_fixed_q_no: null });
    return slots;
  } else {
    // NON-OBE Pattern: 16 Slots (50 Marks)
    const slots = [];
    for (let i = 1; i <= 4; i++) {
      slots.push({ slot_number: i, section_type: 'SECTION-A', unit_no: i, sub_unit: `${i}.1`, question_type: 'MCQ', k_level: 'K1', co_level: `CO${i}`, marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    }
    slots.push({ slot_number: 5, section_type: 'SECTION-A', unit_no: 1, sub_unit: '1.1', question_type: 'MATCH', k_level: 'K1', co_level: 'CO1', marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    slots.push({ slot_number: 6, section_type: 'SECTION-A', unit_no: 2, sub_unit: '2.1', question_type: 'ASSERTION_REASON', k_level: 'K2', co_level: 'CO2', marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    for (let i = 7; i <= 10; i++) {
      const u = i - 6;
      slots.push({ slot_number: i, section_type: 'SECTION-A', unit_no: u, sub_unit: `${u}.2`, question_type: 'VSA', k_level: 'K2', co_level: `CO${u}`, marks: 1, is_compulsory: 1, assigned_fixed_q_no: null });
    }
    for (let i = 11; i <= 14; i++) {
      const u = i - 10;
      slots.push({ slot_number: i, section_type: 'SECTION-B', unit_no: u, sub_unit: `${u}.1`, question_type: 'PARAGRAPH', k_level: 'K3', co_level: `CO${u}`, marks: 5, is_compulsory: 1, choice_group: `Q${i}_PAIR`, assigned_fixed_q_no: null });
    }
    for (let i = 15; i <= 16; i++) {
      const u = i - 14;
      slots.push({ slot_number: i, section_type: 'SECTION-C', unit_no: u, sub_unit: `${u}.1`, question_type: 'ESSAY', k_level: 'K5', co_level: `CO${u}`, marks: 10, is_compulsory: 0, assigned_fixed_q_no: null });
    }
    return slots;
  }
}

// Render the question-number navigator. Only the selected slot editor is rendered.
function renderSlotCirclesBar() {
  const bar = document.getElementById('slotQuickCirclesStrip');
  if (!bar) return;
  if (!currentSlotsData.length) currentSlotsData = getDefaultSlotTemplates(true);
  if (!currentSlotsData.some(s => Number(s.slot_number) === Number(activeSlotNumber))) {
    activeSlotNumber = currentSlotsData[0]?.slot_number ?? null;
  }
  const sectionColors = {
    'SECTION-A': 'bg-indigo-600 border-indigo-400 text-white',
    'SECTION-B': 'bg-amber-400 border-amber-300 text-slate-950',
    'SECTION-C': 'bg-emerald-600 border-emerald-400 text-white',
    'SECTION-D': 'bg-rose-600 border-rose-400 text-white'
  };
  bar.innerHTML = currentSlotsData.map(slot => {
    const n = Number(slot.slot_number);
    const active = n === Number(activeSlotNumber);
    return `<button type="button" onclick="selectBlueprintSlot(${n})" aria-pressed="${active}" title="Q${n} · ${escapeHtml(slot.section_type)} · ${Number(slot.marks || 1)} mark(s)" class="w-10 h-10 rounded-full border-2 font-black text-xs inline-flex items-center justify-center transition ${sectionColors[slot.section_type] || 'bg-slate-500 text-white border-slate-400'} ${active ? 'ring-4 ring-orange-300 scale-110 shadow-lg' : 'opacity-85 hover:opacity-100 hover:scale-105'}">${n}</button>`;
  }).join('');
}

function selectBlueprintSlot(slotNum) {
  activeSlotNumber = Number(slotNum);
  renderSlotAllocationTable();
}

function scrollToSlot(slotNum) { selectBlueprintSlot(slotNum); }

// Slot Unit Selection Change: updates unit and resets sub-unit to entered pool options
function updateSlotUnit(slotNum, newUnit) {
  const slot = currentSlotsData.find(s => parseInt(s.slot_number, 10) === parseInt(slotNum, 10));
  if (!slot) return;

  const u = parseInt(newUnit, 10) || 1;
  slot.unit_no = u;
  slot.co_level = `CO${Math.min(5, u)}`;

  const pool = getEnteredUnitsAndSubunits();
  const availableSubunits = pool.subunitsByUnit[u] || [`${u}.1`];
  if (!availableSubunits.includes(slot.sub_unit)) {
    slot.sub_unit = availableSubunits[0] || `${u}.1`;
  }

  renderSlotAllocationTable();
}

// Slot Subunit Selection Change
function updateSlotSubUnit(slotNum, newSubUnit) {
  const slot = currentSlotsData.find(s => parseInt(s.slot_number, 10) === parseInt(slotNum, 10));
  if (!slot) return;
  slot.sub_unit = newSubUnit;
  renderSlotAllocationTable();
}

// Slot Generic Field Update
function updateSlotField(slotNum, field, val) {
  const slot = currentSlotsData.find(s => parseInt(s.slot_number, 10) === parseInt(slotNum, 10));
  if (slot) {
    if (field === 'marks') {
      slot.marks = parseInt(val, 10) || 1;
    } else {
      slot[field] = val;
    }
    renderSlotAllocationTable();
  }
}

function applyStandardSlotBlueprint() {
  currentSlotsData = getDefaultSlotTemplates(true);
  renderSlotAllocationTable();
  Swal.fire({
    icon: 'success',
    title: 'Standard Pattern Applied',
    text: 'Official Holy Cross College OBE 30-slot distribution loaded.',
    timer: 1200,
    showConfirmButton: false
  });
}

function renderSlotAllocationTable() {
  const container = document.getElementById('slotsGridContainer');
  if (!container) return;
  if (!currentSlotsData.length) currentSlotsData = getDefaultSlotTemplates(true);
  const pool = getEnteredUnitsAndSubunits();
  if (!currentSlotsData.some(s => Number(s.slot_number) === Number(activeSlotNumber))) {
    activeSlotNumber = currentSlotsData[0]?.slot_number ?? null;
  }

  const totalSlots = currentSlotsData.length;
  const examTotalMarks = 75;
  const countEl = document.getElementById('slotAllocatedCount');
  const marksEl = document.getElementById('slotTotalMarks');
  const statusEl = document.getElementById('slotValidationStatus');
  if (countEl) countEl.innerText = `${totalSlots} / ${totalSlots} Configured`;
  if (marksEl) marksEl.innerText = `${examTotalMarks} Marks`;
  if (statusEl) { statusEl.innerText = 'ACTIVE SPECIFICATION'; statusEl.className = 'text-lg font-black text-emerald-600 mt-0.5'; }

  const bannerText = document.getElementById('enteredUnitsBannerText');
  const bannerBadge = document.getElementById('enteredUnitsBadge');
  if (bannerText && bannerBadge) {
    if (!pool.isFallback) {
      const unitsText = pool.units.map(u => `Unit ${['I','II','III','IV','V'][u-1] || u} (${currentQuestions.filter(q => Number(q.unit_no) === u).length} Qs)`).join(', ');
      bannerText.innerHTML = `<span><strong>Entered Question Units Active:</strong> ${unitsText}. Dropdowns contain units and sub-units found in the draft pool.</span>`;
      bannerBadge.innerText = `${pool.units.length} Units from Draft Pool`;
    } else {
      bannerText.innerHTML = '<span><strong>Draft Pool Notice:</strong> No questions entered yet. Standard units I–V are shown until questions are added.</span>';
      bannerBadge.innerText = 'Default Units I–V';
    }
  }

  renderSlotCirclesBar();
  const slot = currentSlotsData.find(s => Number(s.slot_number) === Number(activeSlotNumber));
  if (!slot) { container.innerHTML = '<p class="text-sm text-slate-500">No blueprint slots available.</p>'; return; }

  const units = pool.units.length ? pool.units : [1,2,3,4,5];
  const roman = u => ['I','II','III','IV','V'][Number(u)-1] || u;
  const unitOptions = units.map(u => `<option value="${u}" ${Number(slot.unit_no)===Number(u)?'selected':''}>Unit ${roman(u)} (${currentQuestions.filter(q=>Number(q.unit_no)===Number(u)).length} Qs)</option>`).join('');
  const chosenUnit = Number(slot.unit_no) || units[0] || 1;
  const subunits = pool.subunitsByUnit[chosenUnit] || [`${chosenUnit}.1`];
  if (!subunits.includes(String(slot.sub_unit))) slot.sub_unit = subunits[0];
  const subOptions = subunits.map(sub => `<option value="${escapeHtml(sub)}" ${String(slot.sub_unit)===String(sub)?'selected':''}>Sub-unit ${escapeHtml(sub)} (${currentQuestions.filter(q=>Number(q.unit_no)===chosenUnit && String(q.sub_unit).trim()===String(sub)).length} Qs)</option>`).join('');
  const matched = currentQuestions.filter(q=>Number(q.unit_no)===chosenUnit && String(q.sub_unit).trim()===String(slot.sub_unit).trim() && Number(q.marks)===Number(slot.marks)).length;
  const sectionStyles = {'SECTION-A':'border-indigo-200 bg-indigo-50 text-indigo-800','SECTION-B':'border-amber-200 bg-amber-50 text-amber-800','SECTION-C':'border-emerald-200 bg-emerald-50 text-emerald-800','SECTION-D':'border-rose-200 bg-rose-50 text-rose-800'};
  const secStyle = sectionStyles[slot.section_type] || 'border-slate-200 bg-slate-50 text-slate-800';

  container.innerHTML = `
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(210px,.65fr)_minmax(0,1.7fr)] gap-4 items-start">
      <aside class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-4">
        <h4 class="font-black text-slate-900">Question slots</h4><p class="text-xs text-slate-500 mt-1">Select a numbered circle to edit only that slot.</p>
        <div class="mt-4 space-y-3">${['SECTION-A','SECTION-B','SECTION-C','SECTION-D'].map(sec=>{
          const arr=currentSlotsData.filter(x=>x.section_type===sec); if(!arr.length)return '';
          return `<div><div class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-2">${sec.replace('SECTION-','Section ')} · ${arr.length}</div><div class="flex flex-wrap gap-2">${arr.map(x=>`<button type="button" onclick="selectBlueprintSlot(${Number(x.slot_number)})" class="w-9 h-9 rounded-full border-2 text-xs font-black ${sectionStyles[x.section_type]||''} ${Number(x.slot_number)===Number(activeSlotNumber)?'ring-4 ring-orange-300 scale-110':''}" aria-label="Edit question ${x.slot_number}">${Number(x.slot_number)}</button>`).join('')}</div></div>`;
        }).join('')}</div>
      </aside>
      <section class="rounded-2xl border-2 ${secStyle.split(' ')[0]} bg-white p-5 shadow-sm" id="slot-card-${Number(slot.slot_number)}">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4 mb-4">
          <div><div class="text-xs font-black uppercase tracking-wider text-slate-500">${escapeHtml(slot.section_type)}</div><h4 class="text-lg font-black text-slate-900">Question Slot #${Number(slot.slot_number)}</h4><p class="text-xs text-slate-500">Edit the requirements for this question slot.</p></div>
          <span class="rounded-full px-3 py-1 text-xs font-black ${secStyle}">${Number(slot.marks||1)} Mark(s) · ${matched} matching draft questions</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label class="text-xs font-bold text-slate-600">Unit<select onchange="updateSlotUnit(${Number(slot.slot_number)},this.value)" class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm">${unitOptions}</select></label>
          <label class="text-xs font-bold text-slate-600">Sub-unit<select onchange="updateSlotSubUnit(${Number(slot.slot_number)},this.value)" class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm">${subOptions}</select></label>
          <label class="text-xs font-bold text-slate-600 sm:col-span-2">Question Type<select onchange="updateSlotField(${Number(slot.slot_number)},'question_type',this.value)" class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm">
            ${[['MCQ','Multiple Choice (MCQ)'],['FILL_BLANKS','Fill in the Blanks'],['TRUE_FALSE','True / False'],['MATCH','Match the Following'],['ASSERTION_REASON','Assertion & Reason'],['VSA','Very Short Answer (VSA)'],['PARAGRAPH','Paragraph Answer (5M)'],['ESSAY','Descriptive Essay (10M)'],['CASE_STUDY','Compulsory Case/Essay (10M)']].map(([v,l])=>`<option value="${v}" ${slot.question_type===v?'selected':''}>${l}</option>`).join('')}
          </select></label>
          <label class="text-xs font-bold text-slate-600">K-Level<select onchange="updateSlotField(${Number(slot.slot_number)},'k_level',this.value)" class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm">${['K1','K2','K3','K4','K5','K6'].map(v=>`<option ${slot.k_level===v?'selected':''}>${v}</option>`).join('')}</select></label>
          <label class="text-xs font-bold text-slate-600">CO-Level<select onchange="updateSlotField(${Number(slot.slot_number)},'co_level',this.value)" class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm">${['CO1','CO2','CO3','CO4','CO5'].map(v=>`<option ${slot.co_level===v?'selected':''}>${v}</option>`).join('')}</select></label>
          <label class="text-xs font-bold text-slate-600">Marks<select onchange="updateSlotField(${Number(slot.slot_number)},'marks',this.value)" class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm">${[1,5,10].map(v=>`<option value="${v}" ${Number(slot.marks)===v?'selected':''}>${v} mark(s)</option>`).join('')}</select></label>
        </div>
        <div class="mt-4 rounded-xl bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600">${matched>0?`<strong class="text-emerald-700">${matched} matching question(s)</strong> currently exist in this unit, sub-unit and marks combination.`:'No exact matching questions are currently in the draft pool.'}</div>
      </section>
    </div>`;
  lucide.createIcons();
}

// Save Master Blueprint & Final Blueprint Slots
async function saveFinalBlueprintSlots() {
  if (!currentSlotsData.length) return;

  const payload = {
    action: 'save_slots',
    paper_code: currentCourseCode,
    course_type: 'OBE',
    slots: currentSlotsData
  };

  try {
    const res = await fetch('<?php echo getBaseUrl(); ?>/api/save_final_blueprint_slots.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success) {
      Swal.fire({
        icon: 'success',
        title: 'Final Blueprint Saved',
        text: data.message,
        confirmButtonColor: '#4f46e5'
      });
      loadCourseData(currentCourseCode);
    } else {
      throw new Error(data.message || 'Failed to save blueprint slots');
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Save Failed', text: err.message });
  }
}

// Update Subunit dropdown based on selected Unit
function updateSubunitOptions() {
  const u = document.getElementById('fieldUnit').value;
  const suSel = document.getElementById('fieldSubUnit');
  suSel.innerHTML = `
    <option value="${u}.1">${u}.1</option>
    <option value="${u}.2">${u}.2</option>
    <option value="${u}.3">${u}.3</option>
    <option value="${u}.4">${u}.4</option>
    <option value="${u}.5">${u}.5</option>
    <option value="${u}.6">${u}.6</option>
  `;
}

function syncCustomSubUnit() {}

// Dynamic Marks & Section & Question Type Automation
function handleMarksChange() {
  const m = parseInt(document.getElementById('fieldMarks').value, 10);
  const typeSel = document.getElementById('fieldType');
  const kSel = document.getElementById('fieldKLevel');
  const secDCont = document.getElementById('sectionDCheckboxContainer');

  typeSel.innerHTML = '';
  secDCont.classList.add('hidden');

  if (m === 1) {
    typeSel.innerHTML = `
      <option value="MCQ">Multiple Choice Question (MCQ - Q1-8)</option>
      <option value="MATCH">Match the Following (Q9)</option>
      <option value="ASSERTION_REASON">Assertion & Reason (Q10)</option>
      <option value="VSA">Very Short Answer (VSA - Q11-20)</option>
    `;
    kSel.value = 'K1';
  } else if (m === 5) {
    typeSel.innerHTML = `
      <option value="PARAGRAPH">Paragraph / Either-Or Pair Question</option>
    `;
    kSel.value = 'K3';
  } else if (m === 10) {
    typeSel.innerHTML = `
      <option value="ESSAY">Essay / Descriptive Question (Section C)</option>
    `;
    secDCont.classList.remove('hidden');
    kSel.value = 'K5';
  }

  handleTypeChange();
}

function handleTypeChange() {
  const type = document.getElementById('fieldType').value;
  document.getElementById('containerMCQ').classList.add('hidden');
  document.getElementById('containerMatch').classList.add('hidden');
  document.getElementById('containerAR').classList.add('hidden');

  if (type === 'MCQ') {
    document.getElementById('containerMCQ').classList.remove('hidden');
  } else if (type === 'MATCH') {
    document.getElementById('containerMatch').classList.remove('hidden');
  } else if (type === 'ASSERTION_REASON') {
    document.getElementById('containerAR').classList.remove('hidden');
  }
}

// Math LaTeX Formula Helpers
function insertLatex(code) {
  const ta = document.getElementById('fieldQuestionText');
  const start = ta.selectionStart;
  const end = ta.selectionEnd;
  const text = ta.value;
  ta.value = text.substring(0, start) + code + text.substring(end);
  ta.focus();
  ta.selectionEnd = start + code.length;
  renderMathPreview();
}

function applyQuestionLanguage() {
  const language = document.getElementById('fieldQuestionLanguage')?.value || 'EN';
  const textarea = document.getElementById('fieldQuestionText');
  const preview = document.getElementById('mathPreviewContent');
  const fonts = {
    TL: '"Noto Sans Tamil", "Latha", "Tamil MN", sans-serif',
    HI: '"Noto Sans Devanagari", "Mangal", sans-serif',
    EN: 'Arial, sans-serif',
    FR: 'Arial, sans-serif'
  };
  const langTags = {TL:'ta', HI:'hi', EN:'en', FR:'fr'};
  if (textarea) {
    textarea.lang = langTags[language] || 'en';
    textarea.style.fontFamily = fonts[language] || fonts.EN;
    textarea.style.fontSize = '15px';
  }
  if (preview) {
    preview.lang = langTags[language] || 'en';
    preview.style.fontFamily = fonts[language] || fonts.EN;
    preview.style.whiteSpace = 'pre-wrap';
  }
  renderMathPreview();
}

function renderMathPreview() {
  const text = document.getElementById('fieldQuestionText').value;
  const box = document.getElementById('mathPreviewBox');
  const content = document.getElementById('mathPreviewContent');

  if (!text.trim()) {
    box.classList.add('hidden');
    return;
  }

  box.classList.remove('hidden');
  content.innerText = text;
  const language = document.getElementById('fieldQuestionLanguage')?.value || 'EN';
  content.lang = ({TL:'ta', HI:'hi', EN:'en', FR:'fr'})[language] || 'en';
  content.style.whiteSpace = 'pre-wrap';
  content.style.fontFamily = language === 'TL' ? '"Noto Sans Tamil", "Latha", "Tamil MN", sans-serif' : (language === 'HI' ? '"Noto Sans Devanagari", "Mangal", sans-serif' : 'Arial, sans-serif');

  if (window.MathJax && window.MathJax.typesetPromise) {
    MathJax.typesetPromise([content]).catch(err => console.log(err));
  }
}

// Diagram Upload
async function uploadDiagramFile(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const formData = new FormData();
  formData.append('diagram_file', file);

  try {
    const res = await fetch('<?php echo getBaseUrl(); ?>/api/upload_diagram.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      document.getElementById('fieldImageUrl').value = data.url;
      document.getElementById('diagramPreviewImg').src = data.url;
      document.getElementById('diagramPreviewContainer').classList.remove('hidden');
      Swal.fire({ icon: 'success', title: 'Diagram Uploaded', text: 'Diagram attached successfully.', timer: 1500, showConfirmButton: false });
    } else {
      throw new Error(data.message || 'Upload failed');
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Upload Failed', text: err.message });
  }
}

function removeDiagram() {
  document.getElementById('fieldImageUrl').value = '';
  document.getElementById('diagramPreviewContainer').classList.add('hidden');
  document.getElementById('diagramFileInput').value = '';
}

// Fetch Course Data & Blueprint
async function loadCourseData(courseCode) {
  if (!courseCode) return;
  coeApprovedQuestions = [];
  if (document.getElementById('allQuestionsCountBadge')) document.getElementById('allQuestionsCountBadge').textContent = currentQuestions.length;
  try {
    // document.getElementById('courseCodeBadge').innerText = courseCode;
    const res = await fetch(`<?php echo getBaseUrl(); ?>/api/get_draft_questions.php?paper_code=${encodeURIComponent(courseCode)}`);
    const data = await res.json();

    if (data.success) {
      currentDraft = data.draft;
      currentQuestions = data.questions || [];
      if (document.getElementById('allQuestionsCountBadge')) document.getElementById('allQuestionsCountBadge').textContent = currentQuestions.length + coeApprovedQuestions.length;
      updateNextQuestionNumber();
      currentMatrix = data.blueprint_matrix || [];

      // Update Header Text & Status
      const sel = document.getElementById('courseSelect');
      const opt = sel.options[sel.selectedIndex];
      const title = opt ? opt.text.split(' - ')[1] || opt.text : courseCode;
      document.getElementById('courseTitleText').innerText = title;
      document.getElementById('blueprintHeaderTitle').innerText = `${courseCode} - ${title}`;

      const status = currentDraft ? currentDraft.status : 'DRAFT';
      document.getElementById('statusLabel').innerText = status;

      // Update Stats
      const sc = data.section_counts || {};
      document.getElementById('statTotalQ').innerText = sc.TOTAL_QUESTIONS || currentQuestions.length;
      document.getElementById('draftCountBadge').innerText = sc.TOTAL_QUESTIONS || currentQuestions.length;
      document.getElementById('statSecAMCQ').innerText = sc.A_MCQ || 0;
      document.getElementById('statSecAMatchAR').innerText = (sc.A_MATCH || 0) + (sc.A_AR || 0);
      document.getElementById('statSecAVSA').innerText = sc.A_VSA || 0;
      document.getElementById('statSecB').innerText = sc.B || 0;
      document.getElementById('statSecCD').innerText = (sc.C || 0) + (sc.D || 0);
      document.getElementById('statCorrections').innerText = sc.CORRECTIONS_COUNT || 0;
      document.getElementById('blueprintTotalMarks').innerText = sc.TOTAL_MARKS || 0;

      // Fetch saved slots if any
      try {
        const slotRes = await fetch(`<?php echo getBaseUrl(); ?>/api/save_final_blueprint_slots.php?action=load_slots&paper_code=${encodeURIComponent(courseCode)}`);
        const slotData = await slotRes.json();
        if (slotData.success && slotData.slots && slotData.slots.length) {
          currentSlotsData = slotData.slots;
        } else {
          currentSlotsData = getDefaultSlotTemplates(true);
        }
      } catch (e) {
        currentSlotsData = getDefaultSlotTemplates(true);
      }

      renderBlueprintMatrix();
      renderSlotAllocationTable();
      renderQuestionsList();
    }
  } catch (err) {
    console.error('Error loading course data:', err);
  }
}

// Keep numbering monotonic: deleting a middle question never renumbers the remaining questions.
function updateNextQuestionNumber() {
  const next = (Array.isArray(currentQuestions) ? currentQuestions.length : 0) + 1;
  const label = document.getElementById('nextQuestionNumber');
  if (label) label.innerText = `Next question number: ${next}`;
}

// Save or Update Question
let matchAItems = ['', '', '', ''];
let matchBItems = ['', '', '', '', ''];
let matchAssignments = ['', '', '', ''];
function renderMatchEditor(){
 const a=document.getElementById('matchColumnA'), b=document.getElementById('matchColumnB'), as=document.getElementById('matchAssignments');
 if(!a||!b||!as)return;
 a.innerHTML=matchAItems.map((v,i)=>`<div class="flex gap-2"><input class="match-a flex-1 min-w-0 px-3 py-2 border rounded-lg text-sm" value="${escapeHtml(v)}" placeholder="Row ${i+1}" oninput="matchAItems[${i}]=this.value"><button type="button" onclick="removeMatchRow(${i})" class="text-rose-600">×</button></div>`).join('');
 b.innerHTML=matchBItems.map((v,i)=>`<div class="flex gap-2"><input class="match-b flex-1 min-w-0 px-3 py-2 border rounded-lg text-sm" value="${escapeHtml(v)}" placeholder="Option ${i+1}" oninput="matchBItems[${i}]=this.value"><button type="button" onclick="removeMatchOption(${i})" class="text-rose-600">×</button></div>`).join('');
 as.innerHTML=matchAItems.map((v,i)=>{const usedByOthers=matchAssignments.map((value,row)=>row!==i?String(value):'').filter(Boolean);return `<label class="text-xs text-slate-600 flex items-center gap-2">Row ${i+1}<select class="border rounded-lg p-2 flex-1" onchange="setMatchAssignment(${i},this.value)"><option value="">Choose option</option>${matchBItems.map((x,j)=>{const optionNo=String(j+1);if(usedByOthers.includes(optionNo))return '';return `<option value="${optionNo}" ${String(matchAssignments[i]||'')===optionNo?'selected':''}>Option ${j+1}</option>`;}).join('')}</select></label>`;}).join('');
 document.getElementById('matchAnswerKeyPreview').textContent=getMatchAnswerKey()||'—';
}
function setMatchAssignment(row,value){matchAssignments[row]=value;renderMatchEditor();}
function addMatchRow(){matchAItems.push('');matchAssignments.push('');matchBItems.push('');renderMatchEditor();}
function addMatchOption(){matchBItems.push('');renderMatchEditor();}
function removeMatchRow(i){if(matchAItems.length<=1)return;matchAItems.splice(i,1);matchAssignments.splice(i,1);renderMatchEditor();}
function removeMatchOption(i){if(matchBItems.length<=matchAItems.length+1){Swal.fire('Keep one extra option','Column B must have at least one more option than Column A.','info');return;}matchBItems.splice(i,1);matchAssignments=matchAssignments.map(v=>v==i+1?'':(v>i+1?String(+v-1):v));renderMatchEditor();}
function getMatchColumnA(){return matchAItems.slice();}
function getMatchColumnB(){return matchBItems.slice();}
function getMatchAnswerKey(){return matchAssignments.join(',');}
function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
document.addEventListener('DOMContentLoaded',()=>{renderMatchEditor();applyQuestionLanguage();});

async function handleSaveQuestion(e) {
  e.preventDefault();
  const editId = parseInt(document.getElementById('editQuestionId').value, 10);
  const unitNo = parseInt(document.getElementById('fieldUnit').value, 10);
  const subUnit = document.getElementById('fieldSubUnit').value;
  const marks = parseInt(document.getElementById('fieldMarks').value, 10);
  const qType = document.getElementById('fieldType').value;
  const kLevel = document.getElementById('fieldKLevel').value;
  const coLevel = document.getElementById('fieldCOLevel').value;
  const qText = document.getElementById('fieldQuestionText').value.trim();
  const isCompulsory = document.getElementById('fieldIsCompulsory').checked;
  const answerKey = qType === 'MATCH' ? getMatchAnswerKey() : document.getElementById('fieldAnswerKey').value.trim();
  const language = document.getElementById('fieldQuestionLanguage').value;
  const imageUrl = document.getElementById('fieldImageUrl').value;

  if (!qText) {
    Swal.fire({ icon: 'warning', title: 'Missing Question Text', text: 'Please enter the question statement.' });
    return;
  }

  let options = null;
  if (qType === 'MCQ') {
    const correctOpt = document.querySelector('input[name="mcqCorrect"]:checked')?.value || 'A';
    options = {
      A: document.getElementById('mcqOptA').value.trim(),
      B: document.getElementById('mcqOptB').value.trim(),
      C: document.getElementById('mcqOptC').value.trim(),
      D: document.getElementById('mcqOptD').value.trim(),
      correct: correctOpt
    };
  }

  let matchColA = null, matchColB = null;
  if (qType === 'MATCH') {
    matchColA = getMatchColumnA();
    matchColB = getMatchColumnB();
    if (!matchColA.length || matchColA.some(v => !v) || matchColB.some(v => !v) || matchAssignments.some(v => !v) || new Set(matchAssignments).size !== matchAssignments.length) { Swal.fire({icon:'warning',title:'Complete matching pairs',text:'Enter every Column A/B item and assign an option to each row.'}); return; }
  }

  let assertionText = '', reasonText = '';
  if (qType === 'ASSERTION_REASON') {
    assertionText = document.getElementById('arAssertionText').value.trim();
    reasonText = document.getElementById('arReasonText').value.trim();
  }

  const payload = {
    action: 'save_question',
    question_id: editId,
    paper_code: currentCourseCode,
    unit_no: unitNo,
    sub_unit: subUnit,
    marks: marks,
    question_type: qType,
    k_level: kLevel,
    co_level: coLevel,
    question_text: qText,
    is_compulsory: isCompulsory ? 1 : 0,
    options: options,
    match_column_a: matchColA,
    match_column_b: matchColB,
    assertion_text: assertionText,
    reason_text: reasonText,
    answer_key: answerKey,
    language: language,
    image_url: imageUrl
  };

  try {
    const res = await fetch('<?php echo getBaseUrl(); ?>/api/save_draft_question.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success) {
      Swal.fire({
        icon: 'success',
        title: editId > 0 ? 'Question Updated' : 'Question Added to Draft',
        text: data.message,
        timer: 1500,
        showConfirmButton: false
      });
      resetQuestionForm();
      loadCourseData(currentCourseCode);
    } else {
      throw new Error(data.message || 'Failed to save question');
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Error Saving Question', text: err.message });
  }
}

// Reset Question Form. Dynamic MATCH fields are rendered from arrays, so do not
// address the old static matchA1/matchB1 elements (they no longer exist).
function resetQuestionForm() {
  const setValue = (id, value = '') => {
    const el = document.getElementById(id);
    if (el) el.value = value;
  };
  const setText = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.innerText = value;
  };
  const setHtml = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = value;
  };
  const setChecked = (id, value = false) => {
    const el = document.getElementById(id);
    if (el) el.checked = value;
  };

  setValue('editQuestionId', '0');
  setText('formModeTitle', 'Add New Question');
  updateNextQuestionNumber();
  setText('formModeIcon', '+');
  setHtml('btnSaveQuestion', '<i data-lucide="save" class="w-4 h-4"></i> Save Question to Bank Draft');
  setValue('fieldQuestionText');
  setValue('fieldAnswerKey');
  const correctionBanner = document.getElementById('correctionAlertBanner');
  if (correctionBanner) correctionBanner.classList.add('hidden');
  setChecked('fieldIsCompulsory', false);

  ['mcqOptA','mcqOptB','mcqOptC','mcqOptD','arAssertionText','arReasonText'].forEach(id => setValue(id));
  document.querySelectorAll('input[name="mcqCorrect"]').forEach(el => { el.checked = false; });

  // Restore the initial four A rows and five B options (one distractor).
  matchAItems = ['', '', '', ''];
  matchBItems = ['', '', '', '', ''];
  matchAssignments = ['', '', '', ''];
  renderMatchEditor();

  removeDiagram();
  renderMathPreview();
  if (window.lucide) lucide.createIcons();
}

// Edit Question - Load into form
function editQuestion(qId) {
  const q = currentQuestions.find(item => parseInt(item.id, 10) === parseInt(qId, 10));
  if (!q) return;

  switchTab('entry');

  document.getElementById('editQuestionId').value = q.id;
  document.getElementById('formModeTitle').innerText = `Edit Question #${q.q_number || q.id}`;
  document.getElementById('formModeIcon').innerText = '✏️';
  document.getElementById('btnSaveQuestion').innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Update Question in Draft';

  document.getElementById('fieldUnit').value = q.unit_no;
  updateSubunitOptions();
  document.getElementById('fieldSubUnit').value = q.sub_unit;
  document.getElementById('fieldMarks').value = q.marks;
  handleMarksChange();
  document.getElementById('fieldType').value = q.question_type;
  handleTypeChange();

  document.getElementById('fieldKLevel').value = q.k_level;
  document.getElementById('fieldCOLevel').value = q.co_level;
  document.getElementById('fieldQuestionText').value = q.question_text;
  document.getElementById('fieldQuestionLanguage').value = (q.language || 'EN').toUpperCase();
  if (q.question_type === 'MATCH') {
    try { matchAItems = Array.isArray(q.match_column_a) ? q.match_column_a : JSON.parse(q.match_column_a || '[]'); } catch(e) { matchAItems=['','','','']; }
    try { matchBItems = Array.isArray(q.match_column_b) ? q.match_column_b : JSON.parse(q.match_column_b || '[]'); } catch(e) { matchBItems=['','','','','']; }
    matchAssignments = String(q.answer_key || '').split(',').map(v=>v.trim());
    while(matchAssignments.length<matchAItems.length)matchAssignments.push('');
    renderMatchEditor();
  }
  document.getElementById('fieldAnswerKey').value = q.answer_key || '';

  if (q.section_type === 'SECTION-D') {
    document.getElementById('fieldIsCompulsory').checked = true;
  }

  // Load MCQ Options
  if (q.options) {
    document.getElementById('mcqOptA').value = q.options.A || '';
    document.getElementById('mcqOptB').value = q.options.B || '';
    document.getElementById('mcqOptC').value = q.options.C || '';
    document.getElementById('mcqOptD').value = q.options.D || '';
    if (q.options.correct) {
      const radio = document.querySelector(`input[name="mcqCorrect"][value="${q.options.correct}"]`);
      if (radio) radio.checked = true;
    }
  }

  // Load AR
  document.getElementById('arAssertionText').value = q.assertion_text || '';
  document.getElementById('arReasonText').value = q.reason_text || '';

  // Load Diagram
  if (q.image_url) {
    document.getElementById('fieldImageUrl').value = q.image_url;
    document.getElementById('diagramPreviewImg').src = q.image_url;
    document.getElementById('diagramPreviewContainer').classList.remove('hidden');
  } else {
    removeDiagram();
  }

  // Correction Alert Banner
  if (q.correction_status === 'NEEDS_CORRECTION' && q.remarks) {
    document.getElementById('correctionAlertText').innerText = q.remarks;
    document.getElementById('correctionAlertBanner').classList.remove('hidden');
  } else {
    document.getElementById('correctionAlertBanner').classList.add('hidden');
  }

  renderMathPreview();
  lucide.createIcons();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Delete Question
async function deleteQuestion(qId) {
  const result = await Swal.fire({
    title: 'Delete this question?',
    text: 'This will remove the question from the draft question bank.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Yes, Delete'
  });

  if (!result.isConfirmed) return;

  try {
    const res = await fetch('<?php echo getBaseUrl(); ?>/api/save_draft_question.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete_question', question_id: qId })
    });
    const data = await res.json();
    if (data.success) {
      Swal.fire({ icon: 'success', title: 'Deleted', text: 'Question removed from draft.', timer: 1200, showConfirmButton: false });
      loadCourseData(currentCourseCode);
    } else {
      throw new Error(data.message || 'Delete failed');
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Error', text: err.message });
  }
}

// Submit Question Bank to HOD
async function submitBankToHOD() {
  if (!currentQuestions.length) {
    Swal.fire({ icon: 'warning', title: 'Empty Bank Draft', text: 'Please enter at least one question before submitting to HOD.' });
    return;
  }

  const result = await Swal.fire({
    title: 'Submit Question Bank to HOD?',
    text: `You are submitting ${currentQuestions.length} questions for ${currentCourseCode} to the Head of Department for review and verification.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#4f46e5',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Yes, Submit to HOD'
  });

  if (!result.isConfirmed) return;

  try {
    const res = await fetch('<?php echo getBaseUrl(); ?>/api/save_draft_question.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'submit_to_hod', paper_code: currentCourseCode })
    });
    const data = await res.json();

    if (data.success) {
      Swal.fire({
        icon: 'success',
        title: 'Submitted to HOD!',
        text: data.message,
        confirmButtonColor: '#4f46e5'
      });
      loadCourseData(currentCourseCode);
    } else {
      throw new Error(data.message || 'Submission failed');
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Submission Failed', text: err.message });
  }
}

// Render Blueprint Matrix Table matching sample-blueprint.jpeg
function renderBlueprintMatrix() {
  const tbody = document.getElementById('blueprintMatrixBody');
  const tfoot = document.getElementById('blueprintMatrixFoot');
  if (!tbody) return;

  if (!currentMatrix || !currentMatrix.length) {
    tbody.innerHTML = `<tr><td colspan="16" class="p-8 text-center text-slate-400 font-medium">No entered questions yet. Enter questions in Tab 1 to dynamically compile the blueprint rows for your course.</td></tr>`;
    if (tfoot) tfoot.innerHTML = '';
    const badge = document.getElementById('blueprintTotalMarks');
    if (badge) badge.innerText = '0';
    return;
  }

  let grand = {
    mcq_k1: 0, mcq_k2: 0, mcq_tot: 0,
    vsa_k1: 0, vsa_k2: 0, vsa_tot: 0,
    sec_b_k3: 0, sec_b_k4: 0, sec_b_tot: 0,
    sec_c_k5: 0, sec_c_k6: 0, sec_c_tot: 0,
    sec_d_k6: 0, sec_d_tot: 0,
    total_q: 0, total_marks: 0
  };

  let rowsHtml = '';
  // Collect distinct units present in entered rows
  const presentUnits = [...new Set(currentMatrix.map(r => parseInt(r.unit, 10)))].sort((a,b) => a - b);

  presentUnits.forEach(u => {
    const unitRows = currentMatrix.filter(r => parseInt(r.unit, 10) === u);
    if (!unitRows.length) return;

    unitRows.forEach((row, idx) => {
      const mcqTot = (row.mcq_k1 || 0) + (row.mcq_k2 || 0);
      const vsaTot = (row.vsa_k1 || 0) + (row.vsa_k2 || 0);
      const bTot = (row.sec_b_k3 || 0) + (row.sec_b_k4 || 0);
      const cTot = (row.sec_c_k5 || 0) + (row.sec_c_k6 || 0);
      const dTot = row.sec_d_k6 || 0;
      const totQ = row.total_questions || 0;
      const totM = row.total_marks || 0;

      // Grand totals
      grand.mcq_k1 += row.mcq_k1 || 0; grand.mcq_k2 += row.mcq_k2 || 0; grand.mcq_tot += mcqTot;
      grand.vsa_k1 += row.vsa_k1 || 0; grand.vsa_k2 += row.vsa_k2 || 0; grand.vsa_tot += vsaTot;
      grand.sec_b_k3 += row.sec_b_k3 || 0; grand.sec_b_k4 += row.sec_b_k4 || 0; grand.sec_b_tot += bTot;
      grand.sec_c_k5 += row.sec_c_k5 || 0; grand.sec_c_k6 += row.sec_c_k6 || 0; grand.sec_c_tot += cTot;
      grand.sec_d_k6 += row.sec_d_k6 || 0; grand.sec_d_tot += dTot;
      grand.total_q += totQ; grand.total_marks += totM;

      const unitRoman = ['I', 'II', 'III', 'IV', 'V'][u - 1] || u;

      rowsHtml += `
        <tr class="hover:bg-slate-50 transition-colors ${idx === unitRows.length - 1 ? 'border-b-2 border-slate-300' : 'border-b border-slate-200'}">
          ${idx === 0 ? `<td rowspan="${unitRows.length}" class="p-2 border-r border-slate-300 font-bold bg-slate-100 text-slate-800 align-middle">Unit ${unitRoman}</td>` : ''}
          <td class="p-2 border-r border-slate-300 font-semibold text-slate-700 bg-slate-50/50">${row.sub_unit}</td>
          
          <!-- MC 1M -->
          <td class="p-2 border-r border-slate-200 font-mono ${row.mcq_k1 ? 'bg-blue-50 text-blue-800 font-bold' : 'text-slate-400'}">${row.mcq_k1 || 0}</td>
          <td class="p-2 border-r border-slate-200 font-mono ${row.mcq_k2 ? 'bg-blue-50 text-blue-800 font-bold' : 'text-slate-400'}">${row.mcq_k2 || 0}</td>
          <td class="p-2 border-r border-slate-300 font-mono bg-blue-100/50 font-bold text-blue-900">${mcqTot}</td>

          <!-- VSA 1M -->
          <td class="p-2 border-r border-slate-200 font-mono ${row.vsa_k1 ? 'bg-sky-50 text-sky-800 font-bold' : 'text-slate-400'}">${row.vsa_k1 || 0}</td>
          <td class="p-2 border-r border-slate-200 font-mono ${row.vsa_k2 ? 'bg-sky-50 text-sky-800 font-bold' : 'text-slate-400'}">${row.vsa_k2 || 0}</td>
          <td class="p-2 border-r border-slate-300 font-mono bg-sky-100/50 font-bold text-sky-900">${vsaTot}</td>

          <!-- Sec B 5M -->
          <td class="p-2 border-r border-slate-200 font-mono ${row.sec_b_k3 ? 'bg-amber-50 text-amber-800 font-bold' : 'text-slate-400'}">${row.sec_b_k3 || 0}</td>
          <td class="p-2 border-r border-slate-200 font-mono ${row.sec_b_k4 ? 'bg-amber-50 text-amber-800 font-bold' : 'text-slate-400'}">${row.sec_b_k4 || 0}</td>
          <td class="p-2 border-r border-slate-300 font-mono bg-amber-100/50 font-bold text-amber-900">${bTot}</td>

          <!-- Sec C 10M -->
          <td class="p-2 border-r border-slate-200 font-mono ${row.sec_c_k5 ? 'bg-emerald-50 text-emerald-800 font-bold' : 'text-slate-400'}">${row.sec_c_k5 || 0}</td>
          <td class="p-2 border-r border-slate-200 font-mono ${row.sec_c_k6 ? 'bg-emerald-50 text-emerald-800 font-bold' : 'text-slate-400'}">${row.sec_c_k6 || 0}</td>
          <td class="p-2 border-r border-slate-300 font-mono bg-emerald-100/50 font-bold text-emerald-900">${cTot}</td>

          <!-- Sec D 10M -->
          <td class="p-2 border-r border-slate-200 font-mono ${row.sec_d_k6 ? 'bg-purple-50 text-purple-800 font-bold' : 'text-slate-400'}">${row.sec_d_k6 || 0}</td>
          <td class="p-2 border-r border-slate-300 font-mono bg-purple-100/50 font-bold text-purple-900">${dTot}</td>

          <!-- Totals -->
          <td class="p-2 border-r border-slate-300 font-mono font-bold bg-slate-100 text-slate-800">${totQ}</td>
          <td class="p-2 font-mono font-extrabold bg-slate-100 text-indigo-900">${totM}M</td>
        </tr>
      `;
    });
  });

  tbody.innerHTML = rowsHtml;

  tfoot.innerHTML = `
    <tr>
      <td colspan="2" class="p-3 border-r border-slate-300 text-right uppercase tracking-wider font-extrabold text-slate-900 bg-slate-200">Total Available</td>
      <td class="p-2 border-r border-slate-300 font-mono text-blue-900 bg-blue-100">${grand.mcq_k1}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-blue-900 bg-blue-100">${grand.mcq_k2}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-blue-950 font-black bg-blue-200">${grand.mcq_tot}</td>

      <td class="p-2 border-r border-slate-300 font-mono text-sky-900 bg-sky-100">${grand.vsa_k1}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-sky-900 bg-sky-100">${grand.vsa_k2}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-sky-950 font-black bg-sky-200">${grand.vsa_tot}</td>

      <td class="p-2 border-r border-slate-300 font-mono text-amber-900 bg-amber-100">${grand.sec_b_k3}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-amber-900 bg-amber-100">${grand.sec_b_k4}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-amber-950 font-black bg-amber-200">${grand.sec_b_tot}</td>

      <td class="p-2 border-r border-slate-300 font-mono text-emerald-900 bg-emerald-100">${grand.sec_c_k5}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-emerald-900 bg-emerald-100">${grand.sec_c_k6}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-emerald-950 font-black bg-emerald-200">${grand.sec_c_tot}</td>

      <td class="p-2 border-r border-slate-300 font-mono text-purple-900 bg-purple-100">${grand.sec_d_k6}</td>
      <td class="p-2 border-r border-slate-300 font-mono text-purple-950 font-black bg-purple-200">${grand.sec_d_tot}</td>

      <td class="p-2 border-r border-slate-300 font-mono font-black text-slate-900 bg-slate-300 text-sm">${grand.total_q}</td>
      <td class="p-2 font-mono font-black text-indigo-950 bg-indigo-200 text-sm">${grand.total_marks}M</td>
    </tr>
  `;
}

// Draft pool table with a clear indicator and preview for questions with attached images.
function renderQuestionsList() {
  const container = document.getElementById('draftQuestionsList');
  if (!container) return;

  const filterSec = document.getElementById('filterSection')?.value || 'ALL';
  const filterCorr = document.getElementById('filterCorrection')?.value || 'ALL';
  const filtered = (Array.isArray(currentQuestions) ? currentQuestions : []).filter(q => {
    if (filterSec !== 'ALL' && q.section_type !== filterSec) return false;
    if (filterCorr === 'NEEDS_CORRECTION' && q.correction_status !== 'NEEDS_CORRECTION') return false;
    if (filterCorr === 'CLEAN' && q.correction_status === 'NEEDS_CORRECTION') return false;
    return true;
  });

  if (!filtered.length) {
    container.innerHTML = `<div class="p-8 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">
      <i data-lucide="inbox" class="w-10 h-10 text-slate-400 mx-auto mb-2"></i>
      <h4 class="text-sm font-bold text-slate-700">No Draft Questions Found</h4>
      <p class="text-xs text-slate-500 mt-1">Use the Question Entry Form tab to create your first question.</p></div>`;
    if (window.lucide) lucide.createIcons();
    return;
  }

  const rows = filtered.map((q, idx) => {
    const imageUrl = String(q.image_url || q.diagram_url || q.image_path || '').trim();
    const hasImage = imageUrl !== '';
    const safeQuestion = escapeHtml(q.question_text || '');
    const isMatch = String(q.question_type || '').toUpperCase() === 'MATCH';
    const canPreview = hasImage || isMatch;
    const questionCell = canPreview
      ? `<button type="button" onclick="openQuestionImagePreview(${Number(q.id)})" class="group text-left w-full rounded-lg border ${hasImage ? 'border-sky-200 bg-sky-50 hover:bg-sky-100 hover:border-sky-400' : 'border-indigo-200 bg-indigo-50 hover:bg-indigo-100 hover:border-indigo-400'} px-3 py-2 transition" title="Click to view question details${hasImage ? ' and attached image' : ''}">
           <span class="block text-slate-800 font-semibold group-hover:text-sky-900">${safeQuestion}</span>
           <span class="inline-flex flex-wrap items-center gap-1.5 mt-1 text-[11px] font-bold ${hasImage ? 'text-sky-700' : 'text-indigo-700'}">${hasImage ? '<i data-lucide="image" class="w-3.5 h-3.5"></i> Image attached' : ''}${hasImage && isMatch ? ' · ' : ''}${isMatch ? '<i data-lucide="list-checks" class="w-3.5 h-3.5"></i> Match details' : ''} · Click to view</span>
         </button>`
      : `<span class="text-slate-800">${safeQuestion}</span>`;
    return `<tr class="border-t border-slate-200 hover:bg-slate-50">
      <td class="p-3 whitespace-nowrap">${escapeHtml(q.q_number || idx + 1)}</td>
      <td class="p-2 min-w-[260px]">${questionCell}</td>
      <td class="p-3 text-center">${escapeHtml(q.question_type || '')}</td>
      <td class="p-3 text-center whitespace-nowrap">
        <button type="button" onclick="editQuestion(${Number(q.id)})" class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold">Edit</button>
        <button type="button" onclick="deleteQuestion(${Number(q.id)})" class="px-2.5 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs font-bold ml-1">Delete</button>
      </td>
    </tr>`;
  }).join('');

  container.innerHTML = `<div class="overflow-x-auto bg-white border border-slate-200 rounded-xl">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600"><tr>
        <th class="p-3 text-left">Q.No</th><th class="p-3 text-left">Question</th>
        <th class="p-3 text-center">Type</th><th class="p-3 text-center">Actions</th>
      </tr></thead><tbody>${rows}</tbody>
    </table></div>`;

  if (window.lucide) lucide.createIcons();
}

let allQuestionsRequestToken = 0;
async function loadAllQuestions() {
  const token = ++allQuestionsRequestToken;
  const container = document.getElementById('allQuestionsList');
  if (container) container.innerHTML = '<div class="p-6 text-center text-sm text-slate-500">Loading all questions…</div>';
  try {
    const response = await fetch(`<?php echo getBaseUrl(); ?>/api/get_staff_all_questions.php?paper_code=${encodeURIComponent(currentCourseCode)}`, { credentials: 'same-origin' });
    const data = await response.json();
    if (token !== allQuestionsRequestToken) return;
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load COE-approved questions.');
    coeApprovedQuestions = Array.isArray(data.questions) ? data.questions : [];
    document.getElementById('allQuestionsCountBadge').textContent = currentQuestions.length + coeApprovedQuestions.length;
    renderAllQuestions();
  } catch (error) {
    if (container) container.innerHTML = `<div class="p-5 rounded-xl bg-rose-50 text-rose-700 text-sm">${escapeHtml(error.message)}</div>`;
  }
}
function renderAllQuestions() {
  const container = document.getElementById('allQuestionsList');
  const pager = document.getElementById('allQuestionsPagination');
  if (!container || !pager) return;
  const drafts = (Array.isArray(currentQuestions) ? currentQuestions : []).map(q => ({...q, _sourceStatus:'DRAFT'}));
  const approved = (Array.isArray(coeApprovedQuestions) ? coeApprovedQuestions : []).map(q => ({...q, _sourceStatus:'COE_APPROVED'}));
  let rows = [...drafts, ...approved];
  const status = document.getElementById('allQStatus')?.value || 'ALL';
  const section = document.getElementById('allQSection')?.value || 'ALL';
  const type = document.getElementById('allQType')?.value || 'ALL';
  const search = (document.getElementById('allQSearch')?.value || '').trim().toLowerCase();
  rows = rows.filter(q => {
    const qSection = String(q.section_type || q.section || '').toUpperCase();
    const qType = String(q.question_type || '').toUpperCase();
    if (status !== 'ALL' && q._sourceStatus !== status) return false;
    if (section !== 'ALL' && qSection !== section) return false;
    if (type !== 'ALL' && qType !== type) return false;
    if (search && !String(q.question_text || '').toLowerCase().includes(search) && !String(q.q_number || '').toLowerCase().includes(search)) return false;
    return true;
  });
  const total = rows.length;
  const pages = Math.max(1, Math.ceil(total / ALL_Q_PAGE_SIZE));
  allQPage = Math.min(Math.max(1, allQPage), pages);
  const start = (allQPage - 1) * ALL_Q_PAGE_SIZE;
  const pageRows = rows.slice(start, start + ALL_Q_PAGE_SIZE);
  if (!total) {
    container.innerHTML = '<div class="p-8 text-center text-sm text-slate-500 border border-dashed rounded-xl">No questions match the selected filters.</div>';
  } else {
    container.innerHTML = `<div class="overflow-x-auto border border-slate-200 rounded-xl"><table class="w-full text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="p-3 text-left">Q.No</th><th class="p-3 text-left">Question</th><th class="p-3 text-center">Type</th><th class="p-3 text-center">Section</th><th class="p-3 text-center">Status</th></tr></thead><tbody>${pageRows.map((q,i)=>`<tr class="border-t border-slate-200 hover:bg-slate-50"><td class="p-3">${escapeHtml(q.q_number || start+i+1)}</td><td class="p-3 min-w-[280px]">${escapeHtml(q.question_text || '')}</td><td class="p-3 text-center">${escapeHtml(q.question_type || '—')}</td><td class="p-3 text-center">${escapeHtml(q.section_type || q.section || '—')}</td><td class="p-3 text-center"><span class="inline-flex px-2.5 py-1 rounded-full text-[11px] font-bold ${q._sourceStatus==='COE_APPROVED'?'bg-emerald-50 text-emerald-700':'bg-amber-50 text-amber-700'}">${q._sourceStatus==='COE_APPROVED'?'COE Approved':'Draft'}</span></td></tr>`).join('')}</tbody></table></div>`;
  }
  pager.innerHTML = `<div class="text-xs text-slate-500">Showing ${total ? start+1 : 0}–${Math.min(start+ALL_Q_PAGE_SIZE,total)} of ${total} questions</div><div class="flex items-center gap-2"><button type="button" ${allQPage<=1?'disabled':''} onclick="allQPage--;renderAllQuestions()" class="px-3 py-2 rounded-lg border text-xs font-semibold disabled:opacity-40">Previous</button><span class="text-xs font-semibold text-slate-600">Page ${allQPage} of ${pages}</span><button type="button" ${allQPage>=pages?'disabled':''} onclick="allQPage++;renderAllQuestions()" class="px-3 py-2 rounded-lg border text-xs font-semibold disabled:opacity-40">Next</button></div>`;
}

function parsePreviewArray(value) {
  if (Array.isArray(value)) return value;
  if (typeof value === 'string' && value.trim()) {
    try { const parsed = JSON.parse(value); return Array.isArray(parsed) ? parsed : []; } catch (e) { return []; }
  }
  return [];
}

function openQuestionImagePreview(questionId) {
  const q = (Array.isArray(currentQuestions) ? currentQuestions : []).find(item => String(item.id) === String(questionId));
  if (!q) return;
  const imageUrl = String(q.image_url || q.diagram_url || q.image_path || '').trim();
  const isMatch = String(q.question_type || '').toUpperCase() === 'MATCH';
  const modal = document.getElementById('questionImageModal');
  const content = document.getElementById('questionPreviewContent');
  const title = document.getElementById('questionImageTitle');
  if (!modal || !content) return;

  let html = `<div class="rounded-xl border border-slate-200 bg-white p-3"><div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Question ${escapeHtml(q.q_number || '')}</div><div class="text-sm text-slate-800 whitespace-pre-wrap">${escapeHtml(q.question_text || '')}</div></div>`;
  if (isMatch) {
    const colA = parsePreviewArray(q.match_column_a);
    const colB = parsePreviewArray(q.match_column_b);
    const assignments = String(q.answer_key || '').split(',').map(v => v.trim());

    // Keep the two source columns side by side; show the matching sequence once below.
    const columnARows = colA.map((item, index) =>
      `<div class="match-preview-row match-preview-a"><span class="match-preview-number">${index + 1}.</span><span>${escapeHtml(item)}</span></div>`
    ).join('');
    const columnBRows = colB.map((item, index) =>
      `<div class="match-preview-row match-preview-b"><span class="match-preview-number">${index + 1}.</span><span>${escapeHtml(item)}</span></div>`
    ).join('');
    const answerPairs = colA.map((item, index) => {
      const optionNo = assignments[index] || '';
      return optionNo ? `${index + 1} → ${optionNo}` : `${index + 1} → —`;
    }).join(', ');

    html += `<section class="match-preview-wrap">
      <div class="font-bold text-slate-800 mb-2">Match the Following</div>
      <div class="match-preview-columns">
        <div class="match-preview-column">
          <div class="match-preview-heading">Column A</div>
          ${columnARows || '<div class="p-3 text-slate-400 text-sm">No Column A items saved.</div>'}
        </div>
        <div class="match-preview-column">
          <div class="match-preview-heading">Column B</div>
          ${columnBRows || '<div class="p-3 text-slate-400 text-sm">No Column B options saved.</div>'}
        </div>
      </div>
      <div class="match-preview-key"><strong>Answer Key:</strong> <span>${escapeHtml(answerPairs || '—')}</span></div>
    </section>`;
  }
  if (imageUrl) html += `<section class="rounded-xl border border-sky-200 bg-sky-50 p-3"><div class="text-sm font-bold text-sky-800 mb-2">Attached Image / Diagram</div><div class="flex justify-center"><img src="${escapeHtml(imageUrl)}" alt="Question attachment" class="max-w-full max-h-[55vh] object-contain rounded-lg bg-white shadow-sm" /></div></section>`;
  content.innerHTML = html;
  if (title) title.textContent = `Question ${q.q_number || ''} · Preview`;
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  document.body.classList.add('overflow-hidden');
}

function closeQuestionImagePreview() {
  const modal = document.getElementById('questionImageModal');
  const content = document.getElementById('questionPreviewContent');
  if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
  if (content) content.innerHTML = '';
  document.body.classList.remove('overflow-hidden');
}

</script>
<style>
.match-preview-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.match-preview-column{min-width:0;overflow:hidden;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.match-preview-heading{padding:9px 11px;background:#f8fafc;color:#263449;font-size:13px;font-weight:700}
.match-preview-row{display:flex;gap:8px;align-items:flex-start;padding:8px 11px;border-top:1px solid #e2e8f0;font-size:13px;line-height:1.45;overflow-wrap:anywhere}
.match-preview-number{flex:0 0 18px;color:#64748b}
.match-preview-a>span:last-child{color:#6554d9;font-weight:600}
.match-preview-b>span:last-child{color:#15966b;font-weight:600}
.match-preview-key{margin-top:10px;color:#334155;font-size:12px}
.match-preview-key span{color:#6554d9;font-weight:700}
@media(max-width:640px){.match-preview-columns{grid-template-columns:1fr}}
</style>


<script>
(function () {
  function init() {
    document.querySelectorAll('.qbank-tabs').forEach(function (bar) {
      var glider = document.createElement('span');
      glider.className = 'qbank-glider';
      glider.setAttribute('aria-hidden', 'true');
      bar.insertBefore(glider, bar.firstChild);

      function activeBtn() {
        return bar.querySelector(':scope > button.border-indigo-600');
      }
      var lastActive = activeBtn();

      function markUnder(btn) {
        bar.querySelectorAll(':scope > button.qbank-under').forEach(function (b) {
          if (b !== btn) b.classList.remove('qbank-under');
        });
        if (btn) btn.classList.add('qbank-under');
      }

      function moveTo(btn, instant) {
        markUnder(btn);
        if (!btn) { glider.style.opacity = 0; return; }
        if (instant) glider.classList.add('no-anim');
        glider.style.left   = btn.offsetLeft + 'px';
        glider.style.top    = btn.offsetTop + 'px';
        glider.style.width  = btn.offsetWidth + 'px';
        glider.style.height = btn.offsetHeight + 'px';
        glider.style.opacity = 1;
        if (instant) {
          void glider.offsetWidth;
          requestAnimationFrame(function () { glider.classList.remove('no-anim'); });
        }
      }
      function rest(instant) { moveTo(activeBtn(), instant); }

      function btnFrom(e) {
        var b = e.target.closest && e.target.closest('button');
        return b && b.parentNode === bar ? b : null;
      }

      bar.addEventListener('mouseover', function (e) { var b = btnFrom(e); if (b) moveTo(b); });
      bar.addEventListener('mouseleave', function () { rest(); });
      bar.addEventListener('focusin',  function (e) { var b = btnFrom(e); if (b) moveTo(b); });
      bar.addEventListener('focusout', function () { rest(); });

      // Follow active-tab changes from your tab-switching code
      new MutationObserver(function () {
        var a = activeBtn();
        if (a !== lastActive) { lastActive = a; rest(); }
      }).observe(bar, { subtree: true, attributes: true, attributeFilter: ['class'] });

      if (window.ResizeObserver) new ResizeObserver(function () { rest(true); }).observe(bar);
      window.addEventListener('load', function () { rest(true); });

      rest(true);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
</script>


<?php require_once __DIR__ . '/../../includes/footer.php'; ?>