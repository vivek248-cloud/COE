<?php
/**
 * PAGE 5 - DRAFT QUESTIONS
 * Holy Cross College (Autonomous) - Examination Management System
 * View, filter, edit and manage draft questions with corrections tracking
 */
declare(strict_types=1);
define('PAGE_TITLE', 'Draft Questions');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
requireTeachingStaff();

$pdo = getDBConnection();
$user = getCurrentUser();
if (empty($_SESSION['qps_review_csrf'])) $_SESSION['qps_review_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['qps_review_csrf'];

// Fetch assigned courses for the dropdown
$assignedCourses = [];
try {
    $st = $pdo->prepare("SELECT DISTINCT tf.papercode,
            COALESCE(c.coursetitle, tf.papercode) as coursetitle,
            COALESCE(c.dept_code, tf.deptcode) as dept_code,
            c.level, c.programme, c.maxmark, c.credit, c.type, c.papertype, c.coursetype1, c.coursetype, d.name as dept_name
          FROM timetablefaculty tf
          LEFT JOIN courses c ON UPPER(c.coursecode) = UPPER(tf.papercode)
          LEFT JOIN departments d ON d.code = COALESCE(c.dept_code, tf.deptcode)
          WHERE UPPER(tf.fid) = UPPER(?) ORDER BY tf.papercode");
    $st->execute([$user['staff_code']]);
    $assignedCourses = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$defaultCourse = !empty($assignedCourses) ? $assignedCourses[0]['papercode'] : '';
$selectedCourseCode = strtoupper(trim((string)($_GET['paper_code'] ?? ($_GET['course'] ?? $defaultCourse))));
$assignedCourseCodes = array_map(static fn($c) => strtoupper((string)$c['papercode']), $assignedCourses);
if ($selectedCourseCode !== '' && !in_array($selectedCourseCode, $assignedCourseCodes, true)) {
    http_response_code(403);
    exit('You are not allocated to this course.');
}

require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/navbar.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-7xl mx-auto px-4 py-6 space-y-6">
  <!-- Page Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm">
    <div>
      <h1 class="text-2xl font-black text-slate-900">Draft Questions</h1>
      <p class="text-sm text-slate-500 mt-1">Manage your draft question bank — review corrections, edit or delete questions before submission.</p>
    </div>
    <a href="draft_questions.php<?= $selectedCourseCode ? '?paper_code='.rawurlencode($selectedCourseCode) : '' ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold">
      <i data-lucide="plus-circle" class="w-4 h-4"></i> Add Questions
    </a>
  </div>

  <!-- Course Selector -->
  <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4">
    <label for="courseSelect" class="block text-xs font-bold uppercase tracking-wider text-slate-500">Select Course</label>
    <div class="relative">
      <select id="courseSelect" onchange="changeCourse(this.value)" class="w-full sm:w-[320px] pl-10 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all appearance-none">
        <?php foreach ($assignedCourses as $c): ?>
          <option value="<?= htmlspecialchars($c['papercode'], ENT_QUOTES) ?>" <?= (strtoupper($c['papercode']) === $selectedCourseCode) ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['papercode'] . ' - ' . $c['coursetitle']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <i data-lucide="book-open" class="w-5 h-5 text-slate-400 absolute left-3.5 top-3.5 pointer-events-none"></i>
    </div>
    <?php if ($selectedCourseCode): ?>
      <div id="courseInfo" class="flex flex-wrap items-center gap-3 text-sm">
        <span class="px-3 py-1.5 rounded-full bg-indigo-50 text-indigo-700 font-bold border border-indigo-100"><?= htmlspecialchars($selectedCourseCode) ?></span>
        <span class="text-slate-600" id="courseTitleText"></span>
        <span class="px-3 py-1.5 rounded-full bg-slate-100 text-slate-700 font-semibold" id="draftStatusBadge">Loading...</span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Filters Bar -->
  <div id="filtersBar" class="<?= $selectedCourseCode ? '' : 'hidden' ?> bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
      <div class="flex flex-wrap items-center gap-3">
        <label for="filterUnit" class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Unit:</label>
        <select id="filterUnit" onchange="applyFilters()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="ALL">All Units</option>
          <option value="1">Unit I</option>
          <option value="2">Unit II</option>
          <option value="3">Unit III</option>
          <option value="4">Unit IV</option>
          <option value="5">Unit V</option>
        </select>

        <label for="filterSection" class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Section:</label>
        <select id="filterSection" onchange="applyFilters()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="ALL">All Sections</option>
          <option value="SECTION-A">Section A (1M)</option>
          <option value="SECTION-B">Section B (5M)</option>
          <option value="SECTION-C">Section C (10M)</option>
          <option value="SECTION-D">Section D (10M Compulsory)</option>
        </select>

        <label for="filterStatus" class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Status:</label>
        <select id="filterStatus" onchange="applyFilters()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="ALL">All Statuses</option>
          <option value="VERIFIED">Verified</option>
          <option value="VERIFICATION_PENDING">Verification Pending</option>
        </select>

        <label for="filterCorrection" class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Corrections:</label>
        <select id="filterCorrection" onchange="applyFilters()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="ALL">All</option>
          <option value="HAS_CORRECTION">Has Corrections</option>
          <option value="CLEAR">Clear</option>
        </select>

        <label for="filterSearch" class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Search:</label>
        <input type="search" id="filterSearch" oninput="debounceSearch()" placeholder="Search question text..." class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm w-64 focus:ring-2 focus:ring-indigo-500 focus:bg-white">
      </div>

      <div class="flex items-center gap-3">
        <select id="perPageSelect" onchange="applyFilters()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="10">10 per page</option>
          <option value="25" selected>25 per page</option>
          <option value="50">50 per page</option>
          <option value="100">100 per page</option>
        </select>
        <span id="resultsInfo" class="text-xs text-slate-500 hidden"></span>
      </div>
    </div>
  </div>

  <!-- Questions Table -->
  <div id="questionsContainer" class="<?= $selectedCourseCode ? '' : 'hidden' ?>">
    <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[1200px] text-sm" id="questionsTable">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="p-3 text-left sticky left-0 bg-slate-50 z-10">No</th>
              <th class="p-3 text-left">Unit / Sub-unit</th>
              <th class="p-3 text-left max-w-md">Question</th>
              <th class="p-3 text-center">Marks</th>
              <th class="p-3 text-center">Type</th>
              <th class="p-3 text-center">K</th>
              <th class="p-3 text-center">CO</th>
              <th class="p-3 text-center">Section</th>
              <th class="p-3 text-center">Status</th>
              <th class="p-3 text-left">Corrections</th>
              <th class="p-3 text-right whitespace-nowrap">Actions</th>
            </tr>
          </thead>
          <tbody id="questionsBody" class="divide-y divide-slate-200">
            <!-- Rows rendered via JS -->
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div id="pagination" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 border-t border-slate-200 bg-slate-50/50 hidden">
        <div class="text-xs text-slate-500" id="paginationInfo"></div>
        <div class="flex items-center gap-2" id="paginationControls"></div>
      </div>
    </div>

    <!-- Empty State -->
    <div id="emptyState" class="hidden text-center py-12">
      <i data-lucide="inbox" class="w-12 h-12 text-slate-400 mx-auto mb-3"></i>
      <h3 class="text-lg font-bold text-slate-700">No Draft Questions Found</h3>
      <p class="text-sm text-slate-500 mt-1">Use the <a href="draft_questions.php?paper_code=<?= rawurlencode($selectedCourseCode) ?>" class="text-indigo-600 hover:underline font-semibold">Question Entry Form</a> to create your first question.</p>
    </div>
  </div>

  <!-- No Course Selected State -->
  <div id="noCourseState" class="<?= $selectedCourseCode ? 'hidden' : '' ?> bg-white border border-slate-200/80 rounded-2xl p-12 text-center">
    <i data-lucide="book-open" class="w-16 h-16 text-slate-300 mx-auto mb-4"></i>
    <h2 class="text-xl font-bold text-slate-700">Select a Course to View Draft Questions</h2>
    <p class="text-slate-500 mt-2">Choose a course from the dropdown above to see its draft question bank.</p>
  </div>
</main>

<!-- View Question Modal -->
<div id="viewModal" class="hidden fixed inset-0 z-[1010] items-center justify-center bg-slate-950/70 p-4" onclick="if(event.target===this)closeViewModal()">
  <div class="bg-white w-full max-w-4xl max-h-[90vh] overflow-hidden rounded-2xl shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
    <div class="flex items-center justify-between gap-3 p-4 border-b shrink-0">
      <div>
        <h3 id="viewModalTitle" class="font-bold text-slate-800">Question Preview</h3>
        <p class="text-xs text-slate-500">Question details, options, matching pairs and attachments</p>
      </div>
      <button type="button" onclick="closeViewModal()" class="w-9 h-9 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-xl" aria-label="Close">×</button>
    </div>
    <div id="viewModalContent" class="p-4 overflow-y-auto space-y-4"></div>
    <div class="flex justify-end p-3 border-t shrink-0">
      <button type="button" onclick="closeViewModal()" class="px-4 py-2 rounded-lg border text-sm font-semibold">Close</button>
      <button type="button" onclick="editFromViewModal()" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold ml-2">Edit Question</button>
    </div>
  </div>
</div>

<!-- Edit Question Modal -->
<div id="editModal" class="hidden fixed inset-0 z-[1020] items-center justify-center bg-slate-950/70 p-4" onclick="if(event.target===this)closeEditModal()">
  <div class="bg-white w-full max-w-4xl max-h-[90vh] overflow-hidden rounded-2xl shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="flex items-center justify-between gap-3 p-4 border-b shrink-0">
      <div>
        <h3 id="editModalTitle" class="font-bold text-slate-800">Edit Question</h3>
        <p class="text-xs text-slate-500" id="editModalSubtitle"></p>
      </div>
      <button type="button" onclick="closeEditModal()" class="w-9 h-9 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-xl" aria-label="Close">×</button>
    </div>
    <div id="editModalContent" class="p-4 overflow-y-auto space-y-4"></div>
    <div class="flex justify-end p-3 border-t shrink-0">
      <button type="button" onclick="closeEditModal()" class="px-4 py-2 rounded-lg border text-sm font-semibold">Cancel</button>
      <button type="button" onclick="saveEditQuestion()" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold ml-2">Save Changes</button>
    </div>
  </div>
</div>

<!-- Resolve Correction Modal -->
<div id="resolveModal" class="hidden fixed inset-0 z-[1030] items-center justify-center bg-slate-950/70 p-4" onclick="if(event.target===this)closeResolveModal()">
  <div class="bg-white w-full max-w-md max-h-[90vh] overflow-hidden rounded-2xl shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="resolveModalTitle">
    <div class="flex items-center justify-between gap-3 p-4 border-b">
      <h3 id="resolveModalTitle" class="font-bold text-slate-800">Resolve Correction</h3>
      <button type="button" onclick="closeResolveModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-xl" aria-label="Close">×</button>
    </div>
    <div class="p-4 space-y-4">
      <div id="resolveCorrectionInfo" class="p-3 bg-rose-50 border border-rose-200 rounded-lg text-sm text-rose-800"></div>
      <div>
        <label for="resolveNote" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Reply Note (Optional)</label>
        <textarea id="resolveNote" rows="3" placeholder="Describe the correction made..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-800 focus:ring-2 focus:ring-indigo-500 focus:bg-white"></textarea>
      </div>
    </div>
    <div class="flex justify-end gap-2 p-4 border-t">
      <button type="button" onclick="closeResolveModal()" class="px-4 py-2 rounded-lg border text-sm font-semibold">Cancel</button>
      <button type="button" onclick="confirmResolveCorrection()" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold">Mark Resolved</button>
    </div>
  </div>
</div>

<!-- Delete Confirmation (uses SweetAlert) -->

<script>
// Global HTML Escape Helper
function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>'"]/g, tag => ({
    '&': '&', '<': '<', '>': '>', "'": ''', '"': '"'
  }[tag] || tag));
}

// State Management
let currentCourseCode = '<?= htmlspecialchars($selectedCourseCode, ENT_QUOTES) ?>';
let currentDraft = null;
let currentQuestions = [];
let filteredQuestions = [];
let currentPage = 1;
let perPage = 25;
let searchDebounceTimer = null;
let editingQuestionId = null;
let resolvingCorrectionId = null;
let currentViewQuestion = null;

// Course title mapping
const courseTitles = {};
<?php foreach ($assignedCourses as $c): ?>
courseTitles['<?= strtoupper($c['papercode']) ?>'] = '<?= htmlspecialchars($c['coursetitle'], ENT_QUOTES) ?>';
<?php endforeach; ?>

document.addEventListener('DOMContentLoaded', async () => {
  lucide.createIcons();
  if (currentCourseCode) {
    await loadCourseData(currentCourseCode);
    document.getElementById('courseTitleText').textContent = courseTitles[currentCourseCode] || currentCourseCode;
  }
});

function changeCourse(courseCode) {
  if (!courseCode) return;
  currentCourseCode = courseCode;
  currentPage = 1;
  window.history.pushState({}, '', `?paper_code=${encodeURIComponent(courseCode)}`);
  document.getElementById('courseTitleText').textContent = courseTitles[courseCode] || courseCode;
  loadCourseData(courseCode);
  document.getElementById('filtersBar').classList.remove('hidden');
  document.getElementById('questionsContainer').classList.remove('hidden');
  document.getElementById('noCourseState').classList.add('hidden');
}

async function loadCourseData(courseCode) {
  if (!courseCode) return;
  try {
    const res = await fetch(`<?= getBaseUrl() ?>/api/get_draft_questions.php?paper_code=${encodeURIComponent(courseCode)}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Unable to load question bank.');

    currentDraft = data.draft || null;
    currentQuestions = data.questions || [];

    // Update draft status badge
    const statusBadge = document.getElementById('draftStatusBadge');
    if (statusBadge && currentDraft) {
      const status = currentDraft.status || 'DRAFT';
      const statusClass = status === 'DRAFT' ? 'bg-amber-50 text-amber-700 border-amber-200' :
                          status === 'RETURNED_TO_STAFF' ? 'bg-rose-50 text-rose-700 border-rose-200' :
                          status === 'SUBMITTED_TO_HOD' ? 'bg-blue-50 text-blue-700 border-blue-200' :
                          status === 'HOD_APPROVED' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' :
                          status === 'COE_APPROVED' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' :
                          'bg-slate-50 text-slate-700 border-slate-200';
      statusBadge.className = `px-3 py-1.5 rounded-full font-semibold border ${statusClass}`;
      statusBadge.textContent = status.replace(/_/g, ' ');
    }

    applyFilters();
  } catch (error) {
    console.error(error);
    Swal.fire({icon: 'error', title: 'Course Load Failed', text: error.message});
  }
}

function applyFilters() {
  const filterUnit = document.getElementById('filterUnit')?.value || 'ALL';
  const filterSection = document.getElementById('filterSection')?.value || 'ALL';
  const filterStatus = document.getElementById('filterStatus')?.value || 'ALL';
  const filterCorrection = document.getElementById('filterCorrection')?.value || 'ALL';
  const filterSearch = (document.getElementById('filterSearch')?.value || '').trim().toLowerCase();
  perPage = parseInt(document.getElementById('perPageSelect')?.value || '25', 10);

  filteredQuestions = (Array.isArray(currentQuestions) ? currentQuestions : []).filter(q => {
    if (filterUnit !== 'ALL' && Number(q.unit_no) !== Number(filterUnit)) return false;
    if (filterSection !== 'ALL' && q.section_type !== filterSection) return false;
    const vStatus = String(q.verification_status || 'VERIFICATION_PENDING').toUpperCase();
    if (filterStatus !== 'ALL' && vStatus !== filterStatus) return false;
    if (filterCorrection === 'HAS_CORRECTION' && q.correction_status !== 'NEEDS_CORRECTION') return false;
    if (filterCorrection === 'CLEAR' && q.correction_status === 'NEEDS_CORRECTION') return false;
    if (filterSearch) {
      const searchText = String(q.question_text || '').toLowerCase();
      const searchNum = String(q.q_number || '').toLowerCase();
      if (!searchText.includes(filterSearch) && !searchNum.includes(filterSearch)) return false;
    }
    return true;
  });

  // Sort by unit, sub_unit, q_number
  filteredQuestions.sort((a, b) => {
    const unitA = Number(a.unit_no || 1);
    const unitB = Number(b.unit_no || 1);
    if (unitA !== unitB) return unitA - unitB;
    const subA = String(a.sub_unit || '').localeCompare(String(b.sub_unit || ''), undefined, {numeric: true});
    if (subA !== 0) return subA;
    return Number(a.q_number || 0) - Number(b.q_number || 0);
  });

  currentPage = 1;
  renderQuestionsTable();
  renderPagination();
}

function debounceSearch() {
  clearTimeout(searchDebounceTimer);
  searchDebounceTimer = setTimeout(applyFilters, 300);
}

function renderQuestionsTable() {
  const tbody = document.getElementById('questionsBody');
  const emptyState = document.getElementById('emptyState');
  const pagination = document.getElementById('pagination');
  const resultsInfo = document.getElementById('resultsInfo');

  if (!filteredQuestions.length) {
    tbody.innerHTML = '';
    emptyState.classList.remove('hidden');
    pagination.classList.add('hidden');
    if (resultsInfo) {
      resultsInfo.textContent = `Showing 0 of ${currentQuestions.length} questions`;
      resultsInfo.classList.remove('hidden');
    }
    return;
  }

  emptyState.classList.add('hidden');
  pagination.classList.remove('hidden');

  const start = (currentPage - 1) * perPage;
  const end = Math.min(start + perPage, filteredQuestions.length);
  const pageQuestions = filteredQuestions.slice(start, end);

  if (resultsInfo) {
    resultsInfo.textContent = `Showing ${start + 1}–${end} of ${filteredQuestions.length} questions (${currentQuestions.length} total)`;
    resultsInfo.classList.remove('hidden');
  }

  const sectionMeta = {
    'SECTION-A': {label: 'A', cls: 'bg-indigo-50 text-indigo-800 border-indigo-100'},
    'SECTION-B': {label: 'B', cls: 'bg-amber-50 text-amber-800 border-amber-100'},
    'SECTION-C': {label: 'C', cls: 'bg-emerald-50 text-emerald-800 border-emerald-100'},
    'SECTION-D': {label: 'D', cls: 'bg-rose-50 text-rose-800 border-rose-100'}
  };

  const typeMeta = {
    'MCQ': {label: 'MCQ', cls: 'bg-blue-50 text-blue-800'},
    'MATCH': {label: 'Match', cls: 'bg-purple-50 text-purple-800'},
    'VSA': {label: 'VSA', cls: 'bg-sky-50 text-sky-800'},
    'ASSERTION_REASON': {label: 'A/R', cls: 'bg-orange-50 text-orange-800'},
    'FILL_BLANKS': {label: 'Fill', cls: 'bg-teal-50 text-teal-800'},
    'TRUE_FALSE': {label: 'T/F', cls: 'bg-indigo-50 text-indigo-800'},
    'PARAGRAPH': {label: 'Para', cls: 'bg-amber-50 text-amber-800'},
    'CASE_STUDY': {label: 'Case', cls: 'bg-emerald-50 text-emerald-800'},
    'ESSAY': {label: 'Essay', cls: 'bg-rose-50 text-rose-800'},
    'COMPULSORY': {label: 'Comp', cls: 'bg-rose-50 text-rose-800'}
  };

  tbody.innerHTML = pageQuestions.map((q, idx) => {
    const absIndex = start + idx + 1;
    const vStatus = String(q.verification_status || 'VERIFICATION_PENDING').toUpperCase();
    const isVerified = vStatus === 'VERIFIED';
    const statusCls = isVerified ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
    const statusText = isVerified ? 'Verified' : 'Pending';

    const section = String(q.section_type || 'SECTION-A').toUpperCase();
    const secMeta = sectionMeta[section] || {label: section.replace('SECTION-', ''), cls: 'bg-slate-50 text-slate-800 border-slate-200'};

    const type = String(q.question_type || 'MCQ').toUpperCase();
    const typeMetaObj = typeMeta[type] || {label: type, cls: 'bg-slate-50 text-slate-800'};

    // Corrections column
    let correctionsHtml = '';
    if (q.correction_status === 'NEEDS_CORRECTION' && q.latest_open_correction) {
      correctionsHtml = `
        <div class="space-y-2">
          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-rose-100 text-rose-700 text-[11px] font-bold border border-rose-200">
            <i data-lucide="alert-triangle" class="w-3 h-3"></i> Correction
          </span>
          <p class="text-[11px] text-rose-700 bg-rose-50 p-2 rounded border border-rose-100 max-h-16 overflow-auto">${escapeHtml(q.latest_open_correction)}</p>
          ${q.correction_role ? `<p class="text-[10px] text-rose-600">By: <strong>${escapeHtml(q.correction_role)}</strong>${q.correction_date ? ' · ' + escapeHtml(q.correction_date) : ''}</p>` : ''}
          <button type="button" onclick="openResolveModal(${q.latest_open_correction_id}, ${escapeHtml(JSON.stringify(q.latest_open_correction))}, '${escapeHtml(q.correction_role || '')}', '${escapeHtml(q.correction_date || '')}', ${q.id})" class="mt-1 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-[11px] font-bold hover:bg-emerald-100 transition">
            <i data-lucide="check-circle" class="w-3 h-3"></i> Mark Resolved
          </button>
        </div>
      `;
    } else if (q.correction_status === 'NEEDS_CORRECTION') {
      correctionsHtml = `
        <div class="space-y-1">
          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-rose-100 text-rose-700 text-[11px] font-bold border border-rose-200">
            <i data-lucide="alert-triangle" class="w-3 h-3"></i> Correction Required
          </span>
          <p class="text-[11px] text-rose-600">See Review Status for details</p>
        </div>
      `;
    } else {
      correctionsHtml = `
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-bold border border-emerald-200">
          <i data-lucide="check" class="w-3 h-3"></i> Clear
        </span>
      `;
    }

    // Question text with expand
    const fullText = String(q.question_text || '');
    const truncated = fullText.length > 100 ? fullText.substring(0, 100) + '...' : fullText;
    const questionHtml = fullText.length > 100
      ? `<div class="question-text" data-full="${escapeHtml(fullText)}" data-truncated="${escapeHtml(truncated)}">
          <span class="text-slate-800">${escapeHtml(truncated)}</span>
          <button type="button" onclick="toggleQuestionExpand(this)" class="text-indigo-600 hover:underline text-[11px] font-semibold ml-1">Expand</button>
        </div>`
      : `<span class="text-slate-800">${escapeHtml(truncated)}</span>`;

    // Check if draft is locked (not editable)
    const isLocked = currentDraft && !['DRAFT', 'RETURNED_TO_STAFF', 'STAFF_CORRECTION'].includes(currentDraft.status);
    const hasPackageLock = currentDraft && currentDraft.package_status && !(['HOD_REMARKS', 'STAFF_CORRECTION'].includes(currentDraft.package_status) && currentDraft.current_owner_role === 'STAFF');
    const isEditable = !isLocked && !hasPackageLock;

    const editBtn = isEditable
      ? `<button type="button" onclick="openEditModal(${q.id})" class="px-2.5 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold hover:bg-indigo-100 transition" title="Edit"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button>`
      : `<button type="button" disabled class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-400 text-xs font-bold cursor-not-allowed" title="Locked"><i data-lucide="lock" class="w-3.5 h-3.5"></i></button>`;

    const deleteBtn = isEditable
      ? `<button type="button" onclick="confirmDeleteQuestion(${q.id})" class="px-2.5 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs font-bold ml-1 hover:bg-rose-100 transition" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>`
      : `<button type="button" disabled class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-400 text-xs font-bold ml-1 cursor-not-allowed" title="Locked"><i data-lucide="lock" class="w-3.5 h-3.5"></i></button>`;

    return `
      <tr class="hover:bg-slate-50 transition-colors" data-qid="${q.id}">
        <td class="p-3 font-bold text-slate-700 whitespace-nowrap">${absIndex}</td>
        <td class="p-3">
          <div class="font-semibold text-slate-800">Unit ${Number(q.unit_no || 1)}</div>
          <div class="text-xs text-slate-500">${escapeHtml(q.sub_unit || '')}</div>
        </td>
        <td class="p-3 max-w-md">${questionHtml}</td>
        <td class="p-3 text-center font-bold">${Number(q.marks || 0)}</td>
        <td class="p-3 text-center">
          <span class="inline-flex px-2 py-1 rounded text-[10px] font-bold ${typeMetaObj.cls}">${typeMetaObj.label}</span>
        </td>
        <td class="p-3 text-center font-mono font-bold text-slate-700">${escapeHtml(q.k_level || 'K1')}</td>
        <td class="p-3 text-center font-mono font-bold text-slate-700">${escapeHtml(q.co_level || 'CO1')}</td>
        <td class="p-3 text-center">
          <span class="inline-flex px-2 py-1 rounded text-[10px] font-bold ${secMeta.cls} border ${secMeta.cls.replace('bg-', 'border-').replace('text-', '')}">${secMeta.label}</span>
        </td>
        <td class="p-3 text-center">
          <span class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-bold ${statusCls}">${statusText}</span>
        </td>
        <td class="p-3 corrections-cell">${correctionsHtml}</td>
        <td class="p-3 text-right whitespace-nowrap">
          <button type="button" onclick="openViewModal(${q.id})" class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200 transition" title="View"><i data-lucide="eye" class="w-3.5 h-3.5"></i></button>
          ${editBtn}
          ${deleteBtn}
        </td>
      </tr>
    `;
  }).join('');

  lucide.createIcons();
}

function renderPagination() {
  const pagination = document.getElementById('pagination');
  const paginationInfo = document.getElementById('paginationInfo');
  const paginationControls = document.getElementById('paginationControls');

  const totalPages = Math.max(1, Math.ceil(filteredQuestions.length / perPage));
  currentPage = Math.min(Math.max(1, currentPage), totalPages);

  if (totalPages <= 1) {
    pagination.classList.add('hidden');
    return;
  }

  pagination.classList.remove('hidden');
  paginationInfo.textContent = `Page ${currentPage} of ${totalPages}`;

  let controlsHtml = '';
  if (currentPage > 1) {
    controlsHtml += `<button type="button" onclick="goToPage(${currentPage - 1})" class="px-3 py-1.5 rounded-lg border text-xs font-semibold hover:bg-slate-100 transition">Previous</button>`;
  }

  // Page numbers
  let startPage = Math.max(1, currentPage - 2);
  let endPage = Math.min(totalPages, startPage + 4);
  if (endPage - startPage < 4) {
    startPage = Math.max(1, endPage - 4);
  }

  if (startPage > 1) {
    controlsHtml += `<button type="button" onclick="goToPage(1)" class="px-3 py-1.5 rounded-lg border text-xs font-semibold hover:bg-slate-100 transition">1</button>`;
    if (startPage > 2) controlsHtml += `<span class="px-2 text-slate-400">...</span>`;
  }

  for (let p = startPage; p <= endPage; p++) {
    if (p === currentPage) {
      controlsHtml += `<button type="button" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-bold" disabled>${p}</button>`;
    } else {
      controlsHtml += `<button type="button" onclick="goToPage(${p})" class="px-3 py-1.5 rounded-lg border text-xs font-semibold hover:bg-slate-100 transition">${p}</button>`;
    }
  }

  if (endPage < totalPages) {
    if (endPage < totalPages - 1) controlsHtml += `<span class="px-2 text-slate-400">...</span>`;
    controlsHtml += `<button type="button" onclick="goToPage(${totalPages})" class="px-3 py-1.5 rounded-lg border text-xs font-semibold hover:bg-slate-100 transition">${totalPages}</button>`;
  }

  if (currentPage < totalPages) {
    controlsHtml += `<button type="button" onclick="goToPage(${currentPage + 1})" class="px-3 py-1.5 rounded-lg border text-xs font-semibold hover:bg-slate-100 transition">Next</button>`;
  }

  paginationControls.innerHTML = controlsHtml;
}

function goToPage(page) {
  currentPage = page;
  renderQuestionsTable();
  renderPagination();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleQuestionExpand(btn) {
  const container = btn.closest('.question-text');
  const isExpanded = btn.textContent === 'Collapse';
  btn.textContent = isExpanded ? 'Expand' : 'Collapse';
  const span = container.querySelector('span');
  span.textContent = isExpanded ? container.dataset.truncated : container.dataset.full;
}

// View Modal
function openViewModal(qId) {
  const q = currentQuestions.find(item => String(item.id) === String(qId)) || filteredQuestions.find(item => String(item.id) === String(qId));
  if (!q) return;
  currentViewQuestion = q;

  const modal = document.getElementById('viewModal');
  const content = document.getElementById('viewModalContent');
  const title = document.getElementById('viewModalTitle');

  const sectionMeta = {
    'SECTION-A': {label: 'Section A (1M)', cls: 'bg-indigo-50 text-indigo-800 border-indigo-100'},
    'SECTION-B': {label: 'Section B (5M)', cls: 'bg-amber-50 text-amber-800 border-amber-100'},
    'SECTION-C': {label: 'Section C (10M)', cls: 'bg-emerald-50 text-emerald-800 border-emerald-100'},
    'SECTION-D': {label: 'Section D (10M Compulsory)', cls: 'bg-rose-50 text-rose-800 border-rose-100'}
  };
  const section = String(q.section_type || 'SECTION-A').toUpperCase();
  const secMeta = sectionMeta[section] || {label: section, cls: 'bg-slate-50 text-slate-800 border-slate-200'};

  const typeMeta = {
    'MCQ': 'Multiple Choice Question', 'MATCH': 'Match the Following', 'VSA': 'Very Short Answer',
    'ASSERTION_REASON': 'Assertion & Reason', 'FILL_BLANKS': 'Fill in the Blanks',
    'TRUE_FALSE': 'True / False', 'PARAGRAPH': 'Paragraph / Either-Or',
    'CASE_STUDY': 'Case Study', 'ESSAY': 'Essay / Descriptive', 'COMPULSORY': 'Compulsory'
  };

  const vStatus = String(q.verification_status || 'VERIFICATION_PENDING').toUpperCase();
  const isVerified = vStatus === 'VERIFIED';
  const statusCls = isVerified ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
  const statusText = isVerified ? 'Verified' : 'Verification Pending';

  let html = `
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="col-span-1 md:col-span-2">
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Question Text</label>
        <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-800 whitespace-pre-wrap">${escapeHtml(q.question_text || '')}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Unit / Sub-unit</label>
        <div class="font-semibold">Unit ${Number(q.unit_no || 1)} · ${escapeHtml(q.sub_unit || '')}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Marks</label>
        <div class="font-semibold">${Number(q.marks || 0)}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Type</label>
        <div class="font-semibold">${typeMeta[String(q.question_type || 'MCQ').toUpperCase()] || q.question_type}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">K-Level</label>
        <div class="font-semibold">${escapeHtml(q.k_level || 'K1')}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">CO</label>
        <div class="font-semibold">${escapeHtml(q.co_level || 'CO1')}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Section</label>
        <div class="inline-flex px-2 py-1 rounded text-xs font-bold ${secMeta.cls} border">${secMeta.label}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Status</label>
        <div class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-bold ${statusCls}">${statusText}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Question No.</label>
        <div class="font-semibold">Q${Number(q.q_number || q.id)}</div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Fixed Q No.</label>
        <div class="font-semibold">${Number(q.fixed_q_no || 0)}</div>
      </div>
    </div>
  `;

  // MCQ Options
  if (q.question_type === 'MCQ' && q.options) {
    try {
      const opts = typeof q.options === 'string' ? JSON.parse(q.options) : q.options;
      html += `
        <div class="col-span-1 md:col-span-2">
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Options</label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            ${['A','B','C','D'].map(letter => `
              <div class="flex items-center gap-2 p-2 bg-white border border-slate-200 rounded-lg ${opts.correct === letter ? 'border-indigo-400 bg-indigo-50' : ''}">
                <span class="w-6 h-6 rounded-lg ${opts.correct === letter ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700'} font-bold flex items-center justify-center text-xs">${letter}</span>
                <span class="text-sm ${opts.correct === letter ? 'font-semibold text-indigo-700' : 'text-slate-600'}">${escapeHtml(opts[letter] || '')}</span>
                ${opts.correct === letter ? '<i data-lucide="check" class="w-4 h-4 text-indigo-600 ml-auto"></i>' : ''}
              </div>
            `).join('')}
          </div>
        </div>
      `;
    } catch (e) {}
  }

  // Match the Following
  if (q.question_type === 'MATCH') {
    const parseArr = (val) => {
      if (Array.isArray(val)) return val;
      if (typeof val === 'string' && val.trim()) {
        try { const p = JSON.parse(val); return Array.isArray(p) ? p : []; } catch (e) { return []; }
      }
      return [];
    };
    const colA = parseArr(q.match_column_a);
    const colB = parseArr(q.match_column_b);
    const assignments = String(q.answer_key || '').split(',').map(v => v.trim());

    if (colA.length || colB.length) {
      html += `
        <div class="col-span-1 md:col-span-2">
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Match the Following</label>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
              <div class="px-3 py-2 bg-slate-100 font-bold text-xs uppercase tracking-wider text-slate-600 border-b">Column A</div>
              <div class="p-2 space-y-1 max-h-60 overflow-auto">
                ${colA.map((item, i) => `
                  <div class="flex items-start gap-2 p-2 bg-slate-50 rounded text-sm">
                    <span class="w-6 text-center font-bold text-indigo-600">${i + 1}.</span>
                    <span class="flex-1">${escapeHtml(item)}</span>
                    ${assignments[i] ? `<span class="px-2 py-0.5 bg-indigo-100 text-indigo-700 text-xs rounded">→ Option ${assignments[i]}</span>` : ''}
                  </div>
                `).join('') || '<p class="text-slate-400 text-sm p-2">No items</p>'}
              </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
              <div class="px-3 py-2 bg-slate-100 font-bold text-xs uppercase tracking-wider text-slate-600 border-b">Column B</div>
              <div class="p-2 space-y-1 max-h-60 overflow-auto">
                ${colB.map((item, i) => `
                  <div class="flex items-start gap-2 p-2 bg-slate-50 rounded text-sm">
                    <span class="w-6 text-center font-bold text-emerald-600">${i + 1}.</span>
                    <span class="flex-1">${escapeHtml(item)}</span>
                  </div>
                `).join('') || '<p class="text-slate-400 text-sm p-2">No options</p>'}
              </div>
            </div>
          </div>
        </div>
      `;
    }
  }

  // Assertion & Reason
  if (q.question_type === 'ASSERTION_REASON') {
    html += `
      <div class="col-span-1 md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Assertion (A)</label>
          <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-800">${escapeHtml(q.assertion_text || '')}</div>
        </div>
        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Reason (R)</label>
          <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-800">${escapeHtml(q.reason_text || '')}</div>
        </div>
      </div>
    `;
  }

  // Image
  const imageUrl = String(q.image_url || q.diagram_url || q.image_path || '').trim();
  if (imageUrl) {
    html += `
      <div class="col-span-1 md:col-span-2">
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Attached Diagram</label>
        <div class="text-center">
          <img src="${escapeHtml(imageUrl)}" alt="Question diagram" class="max-w-full max-h-96 object-contain rounded-lg border border-slate-200 shadow-sm">
        </div>
      </div>
    `;
  }

  // Corrections
  if (q.correction_status === 'NEEDS_CORRECTION' && q.latest_open_correction) {
    html += `
      <div class="col-span-1 md:col-span-2 p-3 bg-rose-50 border border-rose-200 rounded-lg">
        <div class="flex items-center gap-2 mb-2">
          <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-600"></i>
          <strong class="text-rose-800">Open Correction</strong>
          ${q.correction_role ? `<span class="px-2 py-0.5 bg-rose-100 text-rose-700 text-[10px] font-bold rounded">${escapeHtml(q.correction_role)}</span>` : ''}
          ${q.correction_date ? `<span class="text-[10px] text-rose-600">${escapeHtml(q.correction_date)}</span>` : ''}
        </div>
        <p class="text-sm text-rose-700">${escapeHtml(q.latest_open_correction)}</p>
      </div>
    `;
  }

  // Answer Key
  if (q.answer_key && q.answer_key.trim()) {
    html += `
      <div class="col-span-1 md:col-span-2">
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Answer Key</label>
        <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-800 font-mono">${escapeHtml(q.answer_key)}</div>
      </div>
    `;
  }

  title.textContent = `Question Q${Number(q.q_number || q.id)} Preview`;
  content.innerHTML = html;
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  document.body.classList.add('overflow-hidden');
  lucide.createIcons();
}

function closeViewModal() {
  const modal = document.getElementById('viewModal');
  const content = document.getElementById('viewModalContent');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
  content.innerHTML = '';
  document.body.classList.remove('overflow-hidden');
  currentViewQuestion = null;
}

function editFromViewModal() {
  if (currentViewQuestion) {
    closeViewModal();
    openEditModal(currentViewQuestion.id);
  }
}

// Edit Modal
function openEditModal(qId) {
  const q = currentQuestions.find(item => String(item.id) === String(qId)) || filteredQuestions.find(item => String(item.id) === String(qId));
  if (!q) return;
  editingQuestionId = q.id;

  // Check if question has open correction
  if (q.correction_status === 'NEEDS_CORRECTION' && q.latest_open_correction) {
    Swal.fire({
      title: 'Question Has Open Correction',
      html: `This question has an open correction from <strong>${escapeHtml(q.correction_role || 'Reviewer')}</strong>. Editing this question should resolve the correction.<br><br>Do you want to proceed?`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Edit & Resolve',
      cancelButtonText: 'Cancel'
    }).then(result => {
      if (result.isConfirmed) {
        renderEditModal(q);
      }
    });
    return;
  }

  renderEditModal(q);
}

function renderEditModal(q) {
  const modal = document.getElementById('editModal');
  const content = document.getElementById('editModalContent');
  const title = document.getElementById('editModalTitle');
  const subtitle = document.getElementById('editModalSubtitle');

  // Build edit form similar to upload.php but simplified
  const sectionMeta = {
    'SECTION-A': 'Section A (1 Mark Questions)',
    'SECTION-B': 'Section B (5 Mark Questions)',
    'SECTION-C': 'Section C (10 Mark Questions)',
    'SECTION-D': 'Section D (10 Mark Compulsory)'
  };

  const typeOptions = {
    'SECTION-A': [
      {value: 'MCQ', label: 'Multiple Choice Question (MCQ)'},
      {value: 'MATCH', label: 'Match the Following'},
      {value: 'ASSERTION_REASON', label: 'Assertion & Reason'},
      {value: 'VSA', label: 'Very Short Answer'},
      {value: 'FILL_BLANKS', label: 'Fill in the Blanks'},
      {value: 'TRUE_FALSE', label: 'True / False'}
    ],
    'SECTION-B': [
      {value: 'PARAGRAPH', label: 'Paragraph / Either-Or'},
      {value: 'CASE_STUDY', label: 'Case Study'}
    ],
    'SECTION-C': [
      {value: 'ESSAY', label: 'Essay / Descriptive'},
      {value: 'CASE_STUDY', label: 'Case Study'}
    ],
    'SECTION-D': [
      {value: 'COMPULSORY', label: 'Compulsory Question'}
    ]
  };

  const currentSection = q.section_type || 'SECTION-A';
  const typesForSection = typeOptions[currentSection] || typeOptions['SECTION-A'];
  const typeSelectOptions = typesForSection.map(t => `<option value="${t.value}" ${q.question_type === t.value ? 'selected' : ''}>${t.label}</option>`).join('');

  let matchAItems = [], matchBItems = [], matchAssignments = [];
  if (q.question_type === 'MATCH') {
    try { matchAItems = typeof q.match_column_a === 'string' ? JSON.parse(q.match_column_a) : (q.match_column_a || []); } catch (e) { matchAItems = ['','','','']; }
    try { matchBItems = typeof q.match_column_b === 'string' ? JSON.parse(q.match_column_b) : (q.match_column_b || []); } catch (e) { matchBItems = ['','','','','']; }
    matchAssignments = String(q.answer_key || '').split(',').map(v => v.trim());
  }

  let mcqOptions = {A: '', B: '', C: '', D: '', correct: 'A'};
  if (q.question_type === 'MCQ' && q.options) {
    try { mcqOptions = typeof q.options === 'string' ? JSON.parse(q.options) : q.options; } catch (e) {}
  }

  subtitle.textContent = `Editing Question Q${Number(q.q_number || q.id)} · Unit ${Number(q.unit_no || 1)} · ${escapeHtml(q.sub_unit || '')}`;

  content.innerHTML = `
    <input type="hidden" id="editQId" value="${q.id}">
    <input type="hidden" id="editQDraftId" value="${currentDraft?.id || 0}">

    <!-- Correction Alert Banner -->
    ${q.correction_status === 'NEEDS_CORRECTION' && q.latest_open_correction ? `
      <div id="editCorrectionBanner" class="p-4 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 text-sm flex items-start gap-3">
        <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
        <div>
          <h5 class="font-bold">HOD Correction Remarks:</h5>
          <p class="mt-1 text-slate-700">${escapeHtml(q.latest_open_correction)}</p>
          ${q.correction_role ? `<p class="text-xs text-amber-700 mt-1">From: ${escapeHtml(q.correction_role)}${q.correction_date ? ' · ' + escapeHtml(q.correction_date) : ''}</p>` : ''}
        </div>
      </div>
    ` : ''}

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Unit <span class="text-rose-500">*</span></label>
        <select id="editFieldUnit" onchange="updateEditSubunitOptions()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${[1,2,3,4,5].map(u => `<option value="${u}" ${Number(q.unit_no) === u ? 'selected' : ''}>Unit ${u}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Sub-Unit <span class="text-rose-500">*</span></label>
        <select id="editFieldSubUnit" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${[1,2,3,4,5,6].map(s => `<option value="${Number(q.unit_no || 1)}.${s}" ${q.sub_unit === `${Number(q.unit_no || 1)}.${s}` ? 'selected' : ''}>${Number(q.unit_no || 1)}.${s}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Marks <span class="text-rose-500">*</span></label>
        <input type="number" id="editFieldMarks" min="1" max="200" value="${Number(q.marks || 1)}" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-bold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Section <span class="text-rose-500">*</span></label>
        <select id="editFieldSection" onchange="handleEditSectionChange()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="SECTION-A" ${currentSection === 'SECTION-A' ? 'selected' : ''}>Section A (1 Mark)</option>
          <option value="SECTION-B" ${currentSection === 'SECTION-B' ? 'selected' : ''}>Section B (5 Marks)</option>
          <option value="SECTION-C" ${currentSection === 'SECTION-C' ? 'selected' : ''}>Section C (10 Marks)</option>
          <option value="SECTION-D" ${currentSection === 'SECTION-D' ? 'selected' : ''}>Section D (10 Marks Compulsory)</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Question Type <span class="text-rose-500">*</span></label>
        <select id="editFieldType" onchange="handleEditTypeChange()" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${typeSelectOptions}
        </select>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Bloom's Taxonomy (K-Level) <span class="text-rose-500">*</span></label>
        <select id="editFieldKLevel" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${['K1','K2','K3','K4','K5','K6'].map(k => `<option value="${k}" ${q.k_level === k ? 'selected' : ''}>${k}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Course Outcome (CO) <span class="text-rose-500">*</span></label>
        <select id="editFieldCOLevel" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${['CO1','CO2','CO3','CO4','CO5','CO6'].map(c => `<option value="${c}" ${q.co_level === c ? 'selected' : ''}>${c}</option>`).join('')}
        </select>
      </div>
      <div id="editSectionDCheckbox" class="hidden flex items-center pt-6">
        <label class="inline-flex items-center gap-2.5 cursor-pointer">
          <input type="checkbox" id="editFieldIsCompulsory" ${q.section_type === 'SECTION-D' ? 'checked' : ''} class="w-5 h-5 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
          <span class="text-xs font-bold text-indigo-900 uppercase">Compulsory Question (Section D)</span>
        </label>
      </div>
    </div>

    <div>
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Question Text <span class="text-rose-500">*</span></label>
      <textarea id="editFieldQuestionText" rows="4" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:ring-2 focus:ring-indigo-500 focus:bg-white text-sm">${escapeHtml(q.question_text || '')}</textarea>
    </div>

    <!-- MCQ Container -->
    <div id="editContainerMCQ" class="${q.question_type === 'MCQ' ? '' : 'hidden'} space-y-3 bg-slate-50/70 p-4 rounded-xl border border-slate-200/80">
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Multiple Choice Options (A, B, C, D) & Answer Key</label>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        ${['A','B','C','D'].map(letter => `
          <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center text-xs flex-shrink-0">${letter}</span>
            <input type="text" id="editMcqOpt${letter}" value="${escapeHtml(mcqOptions[letter] || '')}" placeholder="Option ${letter} text..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-800">
          </div>
        `).join('')}
      </div>
      <div class="flex items-center gap-3 pt-2">
        <span class="text-xs font-bold text-slate-600 uppercase">Correct Option:</span>
        <div class="flex items-center gap-4 text-sm font-semibold">
          ${['A','B','C','D'].map(letter => `
            <label class="inline-flex items-center gap-1.5 cursor-pointer">
              <input type="radio" name="editMcqCorrect" value="${letter}" ${mcqOptions.correct === letter ? 'checked' : ''} class="text-indigo-600">
              Option ${letter}
            </label>
          `).join('')}
        </div>
      </div>
    </div>

    <!-- MATCH Container -->
    <div id="editContainerMatch" class="${q.question_type === 'MATCH' ? '' : 'hidden'} space-y-3 bg-slate-50/70 p-4 rounded-xl border border-slate-200/80">
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Match Following Pairs (Column A & Column B)</label>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <span class="text-xs font-bold text-slate-500 uppercase block mb-2">Column A</span>
          <div id="editMatchColumnA" class="space-y-2"></div>
          <button type="button" onclick="addEditMatchRow()" class="mt-2 px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold">+ Add Row</button>
        </div>
        <div>
          <span class="text-xs font-bold text-slate-500 uppercase block mb-2">Column B (includes one extra option)</span>
          <div id="editMatchColumnB" class="space-y-2"></div>
          <button type="button" onclick="addEditMatchOption()" class="mt-2 px-3 py-2 rounded-lg bg-sky-50 text-sky-700 text-xs font-bold">+ Add Option</button>
        </div>
      </div>
      <div class="mt-3">
        <span class="text-xs font-bold text-slate-500 uppercase block mb-2">Assign Column B options to Column A</span>
        <div id="editMatchAssignments" class="grid grid-cols-1 sm:grid-cols-2 gap-2"></div>
      </div>
    </div>

    <!-- AR Container -->
    <div id="editContainerAR" class="${q.question_type === 'ASSERTION_REASON' ? '' : 'hidden'} space-y-3 bg-slate-50/70 p-4 rounded-xl border border-slate-200/80">
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Assertion & Reason Details</label>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Assertion (A):</label>
          <textarea id="editArAssertionText" rows="2" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm">${escapeHtml(q.assertion_text || '')}</textarea>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Reason (R):</label>
          <textarea id="editArReasonText" rows="2" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm">${escapeHtml(q.reason_text || '')}</textarea>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Diagram / Image (Optional)</label>
        <div class="flex items-center gap-3">
          <input type="file" id="editDiagramFileInput" accept="image/*" onchange="uploadEditDiagramFile(this)" class="block w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700">
          <input type="hidden" id="editFieldImageUrl" value="${escapeHtml(q.image_url || '')}">
        </div>
        <div id="editDiagramPreview" class="hidden mt-2 relative inline-block">
          <img id="editDiagramPreviewImg" src="" alt="Diagram" class="h-20 w-auto rounded-lg border border-slate-200 shadow-sm object-contain bg-white p-1">
          <button type="button" onclick="removeEditDiagram()" class="absolute -top-2 -right-2 w-5 h-5 bg-rose-600 text-white rounded-full flex items-center justify-center text-xs shadow-md">✕</button>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Answer Key (Auto-generated)</label>
        <input type="text" id="editFieldAnswerKey" readonly class="w-full px-3.5 py-2 bg-slate-100 border border-slate-200 rounded-xl text-slate-800 text-sm cursor-not-allowed" value="${escapeHtml(q.answer_key || '')}">
      </div>
    </div>
  `;

  modal.classList.remove('hidden');
  modal.classList.add('flex');
  document.body.classList.add('overflow-hidden');
  lucide.createIcons();

  // Initialize match editor if needed
  if (q.question_type === 'MATCH') {
    renderEditMatchEditor();
  }
  updateEditSectionDVisibility();
}

function closeEditModal() {
  const modal = document.getElementById('editModal');
  const content = document.getElementById('editModalContent');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
  content.innerHTML = '';
  document.body.classList.remove('overflow-hidden');
  editingQuestionId = null;
}

function handleEditSectionChange() {
  const section = document.getElementById('editFieldSection').value;
  const typeSelect = document.getElementById('editFieldType');
  const typeOptions = {
    'SECTION-A': [
      {value: 'MCQ', label: 'Multiple Choice Question (MCQ)'},
      {value: 'MATCH', label: 'Match the Following'},
      {value: 'ASSERTION_REASON', label: 'Assertion & Reason'},
      {value: 'VSA', label: 'Very Short Answer'},
      {value: 'FILL_BLANKS', label: 'Fill in the Blanks'},
      {value: 'TRUE_FALSE', label: 'True / False'}
    ],
    'SECTION-B': [
      {value: 'PARAGRAPH', label: 'Paragraph / Either-Or'},
      {value: 'CASE_STUDY', label: 'Case Study'}
    ],
    'SECTION-C': [
      {value: 'ESSAY', label: 'Essay / Descriptive'},
      {value: 'CASE_STUDY', label: 'Case Study'}
    ],
    'SECTION-D': [
      {value: 'COMPULSORY', label: 'Compulsory Question'}
    ]
  };
  const types = typeOptions[section] || typeOptions['SECTION-A'];
  const currentType = typeSelect.value;
  typeSelect.innerHTML = types.map(t => `<option value="${t.value}" ${t.value === currentType ? 'selected' : ''}>${t.label}</option>`).join('');
  handleEditTypeChange();
  updateEditSectionDVisibility();
}

function handleEditTypeChange() {
  const type = document.getElementById('editFieldType').value;
  document.getElementById('editContainerMCQ').classList.toggle('hidden', type !== 'MCQ');
  document.getElementById('editContainerMatch').classList.toggle('hidden', type !== 'MATCH');
  document.getElementById('editContainerAR').classList.toggle('hidden', type !== 'ASSERTION_REASON');

  if (type === 'MATCH') {
    renderEditMatchEditor();
  }
}

function updateEditSectionDVisibility() {
  const section = document.getElementById('editFieldSection').value;
  document.getElementById('editSectionDCheckbox').classList.toggle('hidden', section !== 'SECTION-D');
}

function updateEditSubunitOptions() {
  const u = document.getElementById('editFieldUnit').value;
  const suSel = document.getElementById('editFieldSubUnit');
  suSel.innerHTML = [1,2,3,4,5,6].map(s => `<option value="${u}.${s}">${u}.${s}</option>`).join('');
}

// Match editor for edit modal
let editMatchAItems = [];
let editMatchBItems = [];
let editMatchAssignments = [];

function renderEditMatchEditor() {
  const a = document.getElementById('editMatchColumnA');
  const b = document.getElementById('editMatchColumnB');
  const as = document.getElementById('editMatchAssignments');
  if (!a || !b || !as) return;

  // Initialize from global arrays if empty
  if (editMatchAItems.length === 0) {
    editMatchAItems = ['', '', '', ''];
    editMatchBItems = ['', '', '', '', ''];
    editMatchAssignments = ['', '', '', ''];
  }

  a.innerHTML = editMatchAItems.map((v, i) => `
    <div class="flex gap-2">
      <input class="match-a flex-1 min-w-0 px-3 py-2 border rounded-lg text-sm" value="${escapeHtml(v)}" placeholder="Row ${i+1}" oninput="editMatchAItems[${i}]=this.value">
      <button type="button" onclick="removeEditMatchRow(${i})" class="text-rose-600 p-1">×</button>
    </div>
  `).join('');

  b.innerHTML = editMatchBItems.map((v, i) => `
    <div class="flex gap-2">
      <input class="match-b flex-1 min-w-0 px-3 py-2 border rounded-lg text-sm" value="${escapeHtml(v)}" placeholder="Option ${i+1}" oninput="editMatchBItems[${i}]=this.value">
      <button type="button" onclick="removeEditMatchOption(${i})" class="text-rose-600 p-1">×</button>
    </div>
  `).join('');

  as.innerHTML = editMatchAItems.map((v, i) => {
    const usedByOthers = editMatchAssignments.map((value, row) => row !== i ? String(value) : '').filter(Boolean);
    return `
      <label class="text-xs text-slate-600 flex items-center gap-2">
        Row ${i+1}
        <select class="border rounded-lg p-2 flex-1" onchange="setEditMatchAssignment(${i},this.value)">
          <option value="">Choose option</option>
          ${editMatchBItems.map((x, j) => {
            const optionNo = String(j+1);
            if (usedByOthers.includes(optionNo)) return '';
            return `<option value="${optionNo}" ${String(editMatchAssignments[i] || '') === optionNo ? 'selected' : ''}>Option ${j+1}</option>`;
          }).join('')}
        </select>
      </label>
    `;
  }).join('');
}

function setEditMatchAssignment(row, value) {
  editMatchAssignments[row] = value;
  renderEditMatchEditor();
}

function addEditMatchRow() {
  editMatchAItems.push('');
  editMatchAssignments.push('');
  editMatchBItems.push('');
  renderEditMatchEditor();
}

function addEditMatchOption() {
  if (editMatchBItems.length >= editMatchAItems.length + 3) {
    Swal.fire('Limit reached', 'Column B can have at most 2 extra options.', 'info');
    return;
  }
  editMatchBItems.push('');
  renderEditMatchEditor();
}

function removeEditMatchRow(i) {
  if (editMatchAItems.length <= 1) return;
  editMatchAItems.splice(i, 1);
  editMatchAssignments.splice(i, 1);
  renderEditMatchEditor();
}

function removeEditMatchOption(i) {
  if (editMatchBItems.length <= editMatchAItems.length + 1) {
    Swal.fire('Keep extra option', 'Column B must have at least one more option than Column A.', 'info');
    return;
  }
  editMatchBItems.splice(i, 1);
  editMatchAssignments = editMatchAssignments.map(v => v == i+1 ? '' : (v > i+1 ? String(+v-1) : v));
  renderEditMatchEditor();
}

function getEditMatchColumnA() { return editMatchAItems.slice(); }
function getEditMatchColumnB() { return editMatchBItems.slice(); }
function getEditMatchAnswerKey() { return editMatchAssignments.join(','); }

// Diagram upload for edit modal
async function uploadEditDiagramFile(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const formData = new FormData();
  formData.append('csrf', '<?= $csrf ?>');
  formData.append('diagram_file', file);

  try {
    const res = await fetch('<?= getBaseUrl() ?>/api/upload_diagram.php', {method: 'POST', body: formData});
    const data = await res.json();
    if (data.success) {
      document.getElementById('editFieldImageUrl').value = data.url;
      document.getElementById('editDiagramPreviewImg').src = data.url;
      document.getElementById('editDiagramPreview').classList.remove('hidden');
      Swal.fire({icon: 'success', title: 'Diagram Uploaded', timer: 1500, showConfirmButton: false});
    } else {
      throw new Error(data.message || 'Upload failed');
    }
  } catch (err) {
    Swal.fire({icon: 'error', title: 'Upload Failed', text: err.message});
  }
}

function removeEditDiagram() {
  document.getElementById('editFieldImageUrl').value = '';
  document.getElementById('editDiagramPreview').classList.add('hidden');
  document.getElementById('editDiagramFileInput').value = '';
}

// Save edited question
async function saveEditQuestion() {
  const qId = document.getElementById('editQId').value;
  const draftId = document.getElementById('editQDraftId').value;

  const unitNo = parseInt(document.getElementById('editFieldUnit').value, 10);
  const subUnit = document.getElementById('editFieldSubUnit').value;
  const marks = parseInt(document.getElementById('editFieldMarks').value, 10);
  const qType = document.getElementById('editFieldType').value;
  const kLevel = document.getElementById('editFieldKLevel').value;
  const coLevel = document.getElementById('editFieldCOLevel').value;
  const qText = document.getElementById('editFieldQuestionText').value.trim();
  const isCompulsory = document.getElementById('editFieldIsCompulsory').checked;
  const sectionType = document.getElementById('editFieldSection').value;
  const language = 'EN';
  const imageUrl = document.getElementById('editFieldImageUrl').value;

  if (!qText) {
    Swal.fire({icon: 'warning', title: 'Missing Question Text', text: 'Please enter the question statement.'});
    return;
  }

  let options = null;
  let matchColA = null, matchColB = null, answerKey = '';
  let assertionText = '', reasonText = '';

  if (qType === 'MCQ') {
    const correctOpt = document.querySelector('input[name="editMcqCorrect"]:checked')?.value || '';
    options = {
      A: document.getElementById('editMcqOptA').value.trim(),
      B: document.getElementById('editMcqOptB').value.trim(),
      C: document.getElementById('editMcqOptC').value.trim(),
      D: document.getElementById('editMcqOptD').value.trim(),
      correct: correctOpt
    };
    if (!options.A || !options.B || !options.C || !options.D || !correctOpt) {
      Swal.fire({icon: 'warning', title: 'Complete MCQ options', text: 'Enter all four options and select the correct answer.'});
      return;
    }
    answerKey = correctOpt;
  } else if (qType === 'MATCH') {
    matchColA = getEditMatchColumnA();
    matchColB = getEditMatchColumnB();
    answerKey = getEditMatchAnswerKey();
    if (!matchColA.length || matchColA.some(v => !v) || matchColB.some(v => !v) || editMatchAssignments.some(v => !v) || new Set(editMatchAssignments).size !== editMatchAssignments.length) {
      Swal.fire({icon: 'warning', title: 'Complete matching pairs', text: 'Enter every Column A/B item and assign an option to each row.'});
      return;
    }
  } else if (qType === 'ASSERTION_REASON') {
    assertionText = document.getElementById('editArAssertionText').value.trim();
    reasonText = document.getElementById('editArReasonText').value.trim();
  }

  const payload = {
    csrf: '<?= $csrf ?>',
    action: 'save_question',
    question_id: parseInt(qId, 10),
    paper_code: currentCourseCode,
    semester: currentDraft?.semester || 'Semester 1',
    academic_year: currentDraft?.academic_year || '',
    programme_year: currentDraft?.programme_year || '',
    programme_level: currentDraft?.programme_level || currentDraft?.degree_level || '',
    unit_no: unitNo,
    sub_unit: subUnit,
    marks: marks,
    question_type: qType,
    section_type: sectionType,
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
    const res = await fetch('<?= getBaseUrl() ?>/api/save_draft_question.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      Swal.fire({icon: 'success', title: 'Question Updated', text: data.message, timer: 1500, showConfirmButton: false});
      closeEditModal();
      loadCourseData(currentCourseCode);
    } else {
      throw new Error(data.message || 'Failed to update question');
    }
  } catch (err) {
    Swal.fire({icon: 'error', title: 'Error', text: err.message});
  }
}

// Delete Question
function confirmDeleteQuestion(qId) {
  Swal.fire({
    title: 'Delete this question?',
    text: 'This will remove the question from the draft question bank.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Yes, Delete'
  }).then(result => {
    if (result.isConfirmed) deleteQuestion(qId);
  });
}

async function deleteQuestion(qId) {
  try {
    const res = await fetch('<?= getBaseUrl() ?>/api/save_draft_question.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'delete_question', question_id: qId, csrf: '<?= $csrf ?>'})
    });
    const data = await res.json();
    if (data.success) {
      Swal.fire({icon: 'success', title: 'Deleted', text: 'Question removed from draft.', timer: 1200, showConfirmButton: false});
      loadCourseData(currentCourseCode);
    } else {
      throw new Error(data.message || 'Delete failed');
    }
  } catch (err) {
    Swal.fire({icon: 'error', title: 'Error', text: err.message});
  }
}

// Resolve Correction Modal
function openResolveModal(remarkId, correctionText, correctionRole, correctionDate, questionId) {
  resolvingCorrectionId = remarkId;
  const info = document.getElementById('resolveCorrectionInfo');
  info.innerHTML = `
    <strong>Correction:</strong> ${escapeHtml(correctionText)}<br>
    <strong>From:</strong> ${escapeHtml(correctionRole || 'Reviewer')}${correctionDate ? ' · ' + escapeHtml(correctionDate) : ''}<br>
    <strong>Question ID:</strong> ${questionId}
  `;
  document.getElementById('resolveNote').value = '';

  const modal = document.getElementById('resolveModal');
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  document.body.classList.add('overflow-hidden');
}

function closeResolveModal() {
  const modal = document.getElementById('resolveModal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
  document.body.classList.remove('overflow-hidden');
  resolvingCorrectionId = null;
}

async function confirmResolveCorrection() {
  if (!resolvingCorrectionId) return;
  const note = document.getElementById('resolveNote').value.trim();

  try {
    const res = await fetch('<?= getBaseUrl() ?>/api/remarks.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'resolve', remark_id: resolvingCorrectionId, resolver_note: note, csrf: '<?= $csrf ?>'})
    });
    const data = await res.json();
    if (data.success) {
      Swal.fire({icon: 'success', title: 'Resolved', text: data.message, timer: 1500, showConfirmButton: false});
      closeResolveModal();
      loadCourseData(currentCourseCode);
    } else {
      throw new Error(data.message || 'Unable to resolve correction');
    }
  } catch (err) {
    Swal.fire({icon: 'error', title: 'Error', text: err.message});
  }
}
</script>

<?php require_once __DIR__.'/../../includes/footer.php'; ?>