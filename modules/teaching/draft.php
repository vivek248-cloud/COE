<?php
/**
 * Question Bank Drafts - Universal Creation & Management Page
 * Holy Cross College (Autonomous) - Examination Management System
 *
 * Features:
 * - Universal form for Academic Year, Semester, Course selection to create/save draft
 * - Course code cards for existing drafts
 * - Click card to view/edit/delete questions
 * - Dynamic sync with master blueprint tables on edit/delete
 */
declare(strict_types=1);
define('PAGE_TITLE', 'Question Bank Drafts');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
requireTeachingStaff();

$pdo = getDBConnection();
$user = getCurrentUser();
if (empty($_SESSION['qps_review_csrf'])) $_SESSION['qps_review_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['qps_review_csrf'];

// Handle POST: Create new draft bank
$createMsg = '';
$createError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_draft') {
    $paperCode = strtoupper(trim((string)($_POST['paper_code'] ?? '')));
    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $programmeYear = strtoupper(trim((string)($_POST['programme_year'] ?? '')));
    $language = strtoupper(trim((string)($_POST['language'] ?? 'EN')));

    try {
        // Validate
        if (!$paperCode) throw new Exception('Select a course.');
        if (!preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/', $academicYear)) throw new Exception('Academic Year must use YYYY-YYYY format (e.g., 2026-2027).');
        [$ys, $ye] = array_map('intval', explode('-', $academicYear));
        if ($ye !== $ys + 1) throw new Exception('Academic Year must contain consecutive years.');
        if (!in_array($programmeYear, ['I','II','III','IV'], true)) throw new Exception('Year of Programme must be I, II, III or IV.');
        if (!preg_match('/^Semester ([1-8])$/', $semester)) throw new Exception('Semester must be "Semester 1" to "Semester 8".');
        if (!in_array($language, ['EN','TL','HI','FR'], true)) throw new Exception('Select a valid language.');

        // Check staff allocation
        $stAlloc = $pdo->prepare("SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1");
        $stAlloc->execute([$user['staff_code'], $paperCode]);
        if (!$stAlloc->fetchColumn()) throw new Exception('You are not allocated to this course.');

        // Get course info
        $stCourse = $pdo->prepare("SELECT c.coursetitle, c.dept_code, d.name as dept_name, c.papertype, c.coursetype1, c.coursetype FROM courses c LEFT JOIN departments d ON d.code=c.dept_code WHERE UPPER(c.coursecode)=UPPER(?) LIMIT 1");
        $stCourse->execute([$paperCode]);
        $courseRow = $stCourse->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$courseRow) throw new Exception('Course not found.');

        // Check if draft already exists
        $stDraft = $pdo->prepare("SELECT id FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND staff_code=? AND semester=? AND academic_year=? AND programme_year=? AND language=? AND status IN ('DRAFT','RETURNED_TO_STAFF','STAFF_CORRECTION') ORDER BY id DESC LIMIT 1");
        $stDraft->execute([$paperCode, $user['staff_code'], $semester, $academicYear, $programmeYear, $language]);
        $existingDraft = $stDraft->fetch(PDO::FETCH_ASSOC);

        if ($existingDraft) {
            $createMsg = 'Draft already exists for this course/year/semester. <a href="?paper_code='.rawurlencode($paperCode).'&draft_id='.(int)$existingDraft['id'].'">Open it</a>.';
        } else {
            $courseType = strtoupper(trim((string)($courseRow['papertype'] ?? $courseRow['coursetype1'] ?? $courseRow['coursetype'] ?? 'OBE')));
            $ins = $pdo->prepare("INSERT INTO qps_question_bank_drafts (staff_code, dept_code, dept_name, paper_code, course_title, course_type, degree_level, programme_year, programme_level, max_marks, total_marks, semester, academic_year, language, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,0,?,?,?,'DRAFT',NOW(),NOW())");
            $ins->execute([$user['staff_code'], $courseRow['dept_code']??($user['dept_code']??''), $courseRow['dept_name']??'', $paperCode, $courseRow['coursetitle']??$paperCode, $courseType?:'OBE', $programmeYear, $programmeYear, $programmeYear, $semester, $academicYear, $language]);
            $draftId = (int)$pdo->lastInsertId();
            $createMsg = 'Draft created successfully. <a href="?paper_code='.rawurlencode($paperCode).'&draft_id='.$draftId.'">Open to add questions</a>.';
            qps_audit($pdo, 'DRAFT_BANK_CREATED', 'DRAFT_BANK', (string)$draftId, ['paper_code'=>$paperCode, 'semester'=>$semester, 'academic_year'=>$academicYear]);
        }
    } catch (Throwable $e) {
        $createError = $e->getMessage();
    }
}

// Fetch assigned courses for dropdown
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

// Fetch existing drafts for this staff
$drafts = [];
try {
    $st = $pdo->prepare("SELECT d.*,
        (SELECT COUNT(*) FROM qps_question_bank_draft_questions q WHERE q.draft_id=d.id) question_count
        FROM qps_question_bank_drafts d
        WHERE d.staff_code=? AND d.status NOT IN ('APPROVED','COE_APPROVED')
        ORDER BY d.updated_at DESC");
    $st->execute([$user['staff_code']]);
    $drafts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Get selected draft details
$selectedDraft = null;
$questions = [];
$paperCodeParam = strtoupper(trim((string)($_GET['paper_code'] ?? '')));
$draftIdParam = (int)($_GET['draft_id'] ?? 0);

if ($draftIdParam > 0) {
    try {
        $stD = $pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE id=? AND staff_code=? LIMIT 1");
        $stD->execute([$draftIdParam, $user['staff_code']]);
        $selectedDraft = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
} elseif ($paperCodeParam !== '') {
    try {
        $stD = $pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND staff_code=? AND status NOT IN ('APPROVED','COE_APPROVED') ORDER BY updated_at DESC LIMIT 1");
        $stD->execute([$paperCodeParam, $user['staff_code']]);
        $selectedDraft = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}

if ($selectedDraft) {
    try {
        $stQ = $pdo->prepare("SELECT q.*,
            (SELECT r.remark_text FROM qps_remarks r INNER JOIN qps_submission_packages p ON p.id=r.package_id WHERE p.bank_draft_id=q.draft_id AND r.target_type='QUESTION' AND r.target_ref=CAST(q.id AS CHAR) AND r.status='OPEN' AND r.to_role='STAFF' ORDER BY r.created_at DESC LIMIT 1) AS latest_open_correction,
            (SELECT r.id FROM qps_remarks r INNER JOIN qps_submission_packages p ON p.id=r.package_id WHERE p.bank_draft_id=q.draft_id AND r.target_type='QUESTION' AND r.target_ref=CAST(q.id AS CHAR) AND r.status='OPEN' AND r.to_role='STAFF' ORDER BY r.created_at DESC LIMIT 1) AS latest_open_correction_id
            FROM qps_question_bank_draft_questions q WHERE q.draft_id=? AND q.staff_code=? ORDER BY q.unit_no, q.sub_unit, q.q_number, q.id");
        $stQ->execute([(int)$selectedDraft['id'], $user['staff_code']]);
        $questions = $stQ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/navbar.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-7xl mx-auto px-4 py-6 space-y-6">

  <!-- Page Header -->
  <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black text-slate-900">Question Bank Drafts</h1>
        <p class="text-sm text-slate-500 mt-1">Create new draft banks, manage existing ones, and edit questions with live blueprint sync.</p>
      </div>
    </div>
  </div>

  <!-- Universal Creation Form -->
  <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm space-y-5">
    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
      <i data-lucide="plus-circle" class="w-5 h-5 text-indigo-600"></i>
      Create New Question Bank Draft
    </h2>
    <p class="text-sm text-slate-500">Select academic context and course to initialize a new draft question bank.</p>

    <?php if ($createMsg): ?>
      <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm"><?= $createMsg ?></div>
    <?php endif; ?>
    <?php if ($createError): ?>
      <div class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm"><?= htmlspecialchars($createError) ?></div>
    <?php endif; ?>

    <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4" id="createDraftForm">
      <input type="hidden" name="action" value="create_draft">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

      <!-- Course Selection -->
      <div class="lg:col-span-2">
        <label for="paper_code" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Course <span class="text-rose-500">*</span></label>
        <select name="paper_code" id="paper_code" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="">Select a course...</option>
          <?php foreach ($assignedCourses as $c): ?>
            <option value="<?= htmlspecialchars($c['papercode'], ENT_QUOTES) ?>">
              <?= htmlspecialchars($c['papercode'] . ' - ' . $c['coursetitle']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-400 mt-1"><?= count($assignedCourses) ?> courses assigned to you</p>
      </div>

      <!-- Academic Year -->
      <div>
        <label for="academic_year" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Academic Year <span class="text-rose-500">*</span></label>
        <select name="academic_year" id="academic_year" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <?php
            $currentYear = (int)date('Y');
            $currentMonth = (int)date('n');
            // Academic year typically starts June/July
            $startYear = $currentMonth >= 7 ? $currentYear : $currentYear - 1;
            for ($i = $startYear + 1; $i >= $startYear - 3; $i--):
              $ay = "$i-" . ($i + 1);
              $selected = ($i === $startYear) ? 'selected' : '';
          ?>
            <option value="<?= $ay ?>" <?= $selected ?>><?= $ay ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <!-- Semester -->
      <div>
        <label for="semester" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Semester <span class="text-rose-500">*</span></label>
        <select name="semester" id="semester" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <?php for ($s = 1; $s <= 8; $s++): ?>
            <option value="Semester <?= $s ?>" <?= $s === 1 ? 'selected' : '' ?>>Semester <?= $s ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <!-- Programme Year -->
      <div>
        <label for="programme_year" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Year of Programme <span class="text-rose-500">*</span></label>
        <select name="programme_year" id="programme_year" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="I">I</option>
          <option value="II">II</option>
          <option value="III">III</option>
          <option value="IV">IV</option>
        </select>
      </div>

      <!-- Language (hidden in grid, full width) -->
      <div class="lg:col-span-5">
        <label for="language" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Question Language</label>
        <select name="language" id="language" class="w-full sm:w-[200px] px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="EN">English</option>
          <option value="TL">Tamil</option>
          <option value="HI">Hindi</option>
          <option value="FR">French</option>
        </select>
      </div>

      <div class="lg:col-span-5 pt-2">
        <button type="submit" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm flex items-center gap-2">
          <i data-lucide="save" class="w-4 h-4"></i> Create Draft Bank
        </button>
      </div>
    </form>
  </div>

  <!-- Existing Drafts as Course Cards -->
  <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-5">
      <div>
        <h2 class="text-lg font-bold text-slate-900">Your Question Bank Drafts</h2>
        <p class="text-sm text-slate-500 mt-1">Click a course card to view, edit, or delete questions. Changes sync to blueprint automatically.</p>
      </div>
      <span class="bg-slate-100 text-slate-700 px-3 py-1 rounded-full text-xs font-bold"><?= count($drafts) ?> draft(s)</span>
    </div>

    <?php if (empty($drafts)): ?>
      <div class="text-center py-12 border-2 border-dashed border-slate-200 rounded-2xl">
        <i data-lucide="folder-open" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>
        <h3 class="text-lg font-bold text-slate-700">No Draft Banks Yet</h3>
        <p class="text-slate-500 mt-1">Create your first question bank draft using the form above.</p>
      </div>
    <?php else: ?>
      <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4" id="draftCards">
        <?php foreach ($drafts as $d):
          $isSelected = $selectedDraft && (int)$selectedDraft['id'] === (int)$d['id'];
          $qCount = (int)$d['question_count'];
        ?>
          <article class="bg-white border rounded-2xl p-4 hover:border-indigo-300 hover:shadow-md transition-all duration-200 cursor-pointer relative <?= $isSelected ? 'ring-2 ring-indigo-500 bg-indigo-50' : '' ?>"
                   onclick="window.location.href='?paper_code=<?= rawurlencode($d['paper_code']) ?>&draft_id=<?= (int)$d['id'] ?>'">
            <div class="flex justify-between items-start">
              <strong class="text-slate-900 text-lg"><?= htmlspecialchars($d['paper_code']) ?></strong>
              <span class="text-xs rounded-full px-2 py-1 font-bold
                <?= match($d['status']) {
                  'DRAFT' => 'bg-slate-100 text-slate-700',
                  'RETURNED_TO_STAFF', 'STAFF_CORRECTION' => 'bg-rose-100 text-rose-700',
                  'SUBMITTED_TO_HOD' => 'bg-blue-100 text-blue-700',
                  'HOD_APPROVED' => 'bg-emerald-100 text-emerald-700',
                  default => 'bg-slate-100 text-slate-700'
                } ?>">
                <?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?>
              </span>
            </div>
            <p class="text-sm text-slate-600 mt-1 line-clamp-1"><?= htmlspecialchars($d['course_title'] ?? '') ?></p>
            <div class="flex flex-wrap gap-1.5 mt-2 text-xs">
              <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600"><?= htmlspecialchars($d['semester']) ?></span>
              <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600"><?= htmlspecialchars($d['academic_year']) ?></span>
              <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600">Year <?= htmlspecialchars($d['programme_year'] ?? '') ?></span>
              <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600"><?= htmlspecialchars($d['language'] ?? 'EN') ?></span>
            </div>
            <p class="text-xs font-bold text-indigo-700 mt-2"><?= $qCount ?> question(s)</p>
            <p class="text-[10px] text-slate-400 mt-1">Updated: <?= htmlspecialchars(date('M j, Y H:i', strtotime($d['updated_at']))) ?></p>
            <?php if ($isSelected): ?>
              <div class="absolute inset-0 border-2 border-indigo-500 rounded-2xl pointer-events-none"></div>
              <div class="absolute bottom-2 right-2 bg-indigo-600 text-white text-[10px] px-2 py-0.5 rounded font-bold">Selected</div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Selected Draft Questions Detail -->
  <?php if ($selectedDraft): ?>
    <div id="questionsDetail" class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm space-y-5" data-draft-id="<?= (int)$selectedDraft['id'] ?>">
      <!-- Draft Header -->
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
          <div class="flex items-center gap-3 flex-wrap">
            <span class="bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full font-bold text-sm border border-indigo-100">
              D-<?= (int)$selectedDraft['id'] ?>
            </span>
            <h3 class="font-black text-slate-900"><?= htmlspecialchars($selectedDraft['paper_code']) ?> — <?= htmlspecialchars($selectedDraft['course_title'] ?? '') ?></h3>
          </div>
          <p class="text-xs text-slate-500 mt-1">
            <?= htmlspecialchars($selectedDraft['semester']) ?> · <?= htmlspecialchars($selectedDraft['academic_year']) ?> · Year <?= htmlspecialchars($selectedDraft['programme_year'] ?? '') ?> · <?= htmlspecialchars($selectedDraft['language'] ?? 'EN') ?>
            <span class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200"><?= count($questions) ?> Questions</span>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a href="draft_questions.php?paper_code=<?= rawurlencode($selectedDraft['paper_code']) ?>&draft_id=<?= (int)$selectedDraft['id'] ?>"
             class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-sm font-bold flex items-center gap-2">
            <i data-lucide="edit-3" class="w-4 h-4"></i> Full Question Entry Grid
          </a>
          <button type="button" onclick="confirmDeleteDraft(<?= (int)$selectedDraft['id'] ?>, '<?= htmlspecialchars($selectedDraft['paper_code']) ?>')"
                  class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 px-4 py-2 rounded-xl text-sm font-bold flex items-center gap-2">
            <i data-lucide="trash-2" class="w-4 h-4"></i> Delete Draft
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-3">
        <label class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Unit:</label>
        <select id="filterUnit" onchange="filterQuestions()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="ALL">All Units</option>
          <option value="1">Unit I</option>
          <option value="2">Unit II</option>
          <option value="3">Unit III</option>
          <option value="4">Unit IV</option>
          <option value="5">Unit V</option>
        </select>

        <label class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Section:</label>
        <select id="filterSection" onchange="filterQuestions()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="ALL">All Sections</option>
          <option value="SECTION-A">Section A (1M)</option>
          <option value="SECTION-B">Section B (5M)</option>
          <option value="SECTION-C">Section C (10M)</option>
          <option value="SECTION-D">Section D (10M Compulsory)</option>
        </select>

        <label class="text-xs font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">Search:</label>
        <input type="search" id="filterSearch" oninput="debounceFilter()" placeholder="Search question text..." class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm w-64 focus:ring-2 focus:ring-indigo-500 focus:bg-white">
      </div>

      <!-- Questions Table -->
      <div class="overflow-x-auto">
        <table class="w-full min-w-[1100px] text-sm" id="questionsTable">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="p-3 text-left sticky left-0 bg-slate-50 z-10 w-12">Q.No</th>
              <th class="p-3 text-left w-36">Unit / Sub</th>
              <th class="p-3 text-left max-w-md">Question Text</th>
              <th class="p-3 text-center w-20">Marks</th>
              <th class="p-3 text-center w-28">Type</th>
              <th class="p-3 text-center w-16">K-Level</th>
              <th class="p-3 text-center w-16">CO</th>
              <th class="p-3 text-center w-24">Section</th>
              <th class="p-3 text-center w-28">Status</th>
              <th class="p-3 text-left w-48">Corrections</th>
              <th class="p-3 text-right whitespace-nowrap w-40">Actions</th>
            </tr>
          </thead>
          <tbody id="questionsBody" class="divide-y divide-slate-200">
            <!-- Rendered via JS -->
          </tbody>
        </table>
      </div>

      <!-- Empty State -->
      <div id="emptyState" class="hidden text-center py-12">
        <i data-lucide="inbox" class="w-12 h-12 text-slate-400 mx-auto mb-3"></i>
        <h3 class="text-lg font-bold text-slate-700">No Questions in This Draft</h3>
        <p class="text-sm text-slate-500 mt-1">Click "Full Question Entry Grid" above to add questions.</p>
      </div>

      <!-- Blueprint Sync Status -->
      <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mt-4">
        <div class="flex items-center gap-3">
          <i data-lucide="refresh-cw" class="w-5 h-5 text-indigo-600"></i>
          <div>
            <p class="font-bold text-slate-800 text-sm">Live Blueprint Sync</p>
            <p class="text-xs text-slate-500 mt-0.5">When you edit or delete questions above, the master blueprint tables (unit/section/K-level/CO distribution) are updated automatically via the <code class="bg-white px-1 rounded">save_draft_question.php</code> API.</p>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

</main>

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

<script>
// ============================================
// STATE & CONFIG
// ============================================
const csrf = '<?= json_encode($csrf) ?>';
const baseUrl = '<?= json_encode(getBaseUrl()) ?>';
const currentDraftId = <?= $selectedDraft ? (int)$selectedDraft['id'] : 'null' ?>;
const currentDraft = <?= $selectedDraft ? json_encode($selectedDraft) : 'null' ?>;
const allQuestions = <?= json_encode($questions) ?>;

let filteredQuestions = [...allQuestions];
let editingQuestionId = null;
let filterDebounce = null;

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
  lucide.createIcons();
  if (currentDraftId) {
    renderQuestionsTable();
  }
});

// ============================================
// FILTERING
// ============================================
function filterQuestions() {
  const unit = document.getElementById('filterUnit')?.value || 'ALL';
  const section = document.getElementById('filterSection')?.value || 'ALL';
  const search = (document.getElementById('filterSearch')?.value || '').trim().toLowerCase();

  filteredQuestions = allQuestions.filter(q => {
    if (unit !== 'ALL' && Number(q.unit_no) !== Number(unit)) return false;
    if (section !== 'ALL' && q.section_type !== section) return false;
    if (search) {
      const text = String(q.question_text || '').toLowerCase();
      const num = String(q.q_number || '').toLowerCase();
      if (!text.includes(search) && !num.includes(search)) return false;
    }
    return true;
  });

  // Sort
  filteredQuestions.sort((a, b) => {
    const uA = Number(a.unit_no || 1), uB = Number(b.unit_no || 1);
    if (uA !== uB) return uA - uB;
    const sA = String(a.sub_unit || '').localeCompare(String(b.sub_unit || ''), undefined, {numeric: true});
    if (sA !== 0) return sA;
    return Number(a.q_number || 0) - Number(b.q_number || 0);
  });

  renderQuestionsTable();
}

function debounceFilter() {
  clearTimeout(filterDebounce);
  filterDebounce = setTimeout(filterQuestions, 300);
}

// ============================================
// RENDER QUESTIONS TABLE
// ============================================
function renderQuestionsTable() {
  const tbody = document.getElementById('questionsBody');
  const emptyState = document.getElementById('emptyState');
  const table = document.getElementById('questionsTable');

  if (!filteredQuestions.length) {
    tbody.innerHTML = '';
    emptyState.classList.remove('hidden');
    table.querySelector('thead').style.display = 'none';
    return;
  }

  emptyState.classList.add('hidden');
  table.querySelector('thead').style.display = '';

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

  tbody.innerHTML = filteredQuestions.map((q, idx) => {
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
        <div class="space-y-1">
          <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-rose-100 text-rose-700 text-[10px] font-bold border border-rose-200">
            <i data-lucide="alert-triangle" class="w-3 h-3"></i> Correction
          </span>
          <p class="text-[10px] text-rose-700 bg-rose-50 p-1.5 rounded border border-rose-100 max-h-16 overflow-auto">${escapeHtml(q.latest_open_correction)}</p>
          ${q.correction_role ? `<p class="text-[9px] text-rose-600">By: <strong>${escapeHtml(q.correction_role)}</strong></p>` : ''}
        </div>
      `;
    } else if (q.correction_status === 'NEEDS_CORRECTION') {
      correctionsHtml = `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-rose-100 text-rose-700 text-[10px] font-bold border border-rose-200"><i data-lucide="alert-triangle" class="w-3 h-3"></i> Needs Correction</span>`;
    } else {
      correctionsHtml = `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold border border-emerald-200"><i data-lucide="check" class="w-3 h-3"></i> Clear</span>`;
    }

    // Question text truncate
    const fullText = String(q.question_text || '');
    const truncated = fullText.length > 80 ? fullText.substring(0, 80) + '...' : fullText;
    const questionHtml = fullText.length > 80
      ? `<div class="question-text" data-full="${escapeHtml(fullText)}" data-truncated="${escapeHtml(truncated)}"><span class="text-slate-800">${escapeHtml(truncated)}</span><button type="button" onclick="toggleExpand(this)" class="text-indigo-600 hover:underline text-[10px] font-semibold ml-1">Expand</button></div>`
      : `<span class="text-slate-800">${escapeHtml(truncated)}</span>`;

    // Check if draft is locked
    const isLocked = currentDraft && !['DRAFT', 'RETURNED_TO_STAFF', 'STAFF_CORRECTION'].includes(currentDraft.status);

    const editBtn = !isLocked
      ? `<button type="button" onclick="openEditModal(${q.id})" class="px-2.5 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold hover:bg-indigo-100 transition" title="Edit"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button>`
      : `<button type="button" disabled class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-400 text-xs font-bold cursor-not-allowed" title="Draft locked"><i data-lucide="lock" class="w-3.5 h-3.5"></i></button>`;

    const deleteBtn = !isLocked
      ? `<button type="button" onclick="confirmDeleteQuestion(${q.id})" class="px-2.5 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs font-bold ml-1 hover:bg-rose-100 transition" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>`
      : `<button type="button" disabled class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-400 text-xs font-bold ml-1 cursor-not-allowed"><i data-lucide="lock" class="w-3.5 h-3.5"></i></button>`;

    return `
      <tr class="hover:bg-slate-50 transition-colors" data-qid="${q.id}">
        <td class="p-3 font-bold text-slate-700 whitespace-nowrap">Q${Number(q.q_number || q.id)}</td>
        <td class="p-3">
          <div class="font-semibold text-slate-800">Unit ${Number(q.unit_no || 1)}</div>
          <div class="text-xs text-slate-500">${escapeHtml(q.sub_unit || '')}</div>
        </td>
        <td class="p-3 max-w-md">${questionHtml}</td>
        <td class="p-3 text-center font-bold">${Number(q.marks || 0)}</td>
        <td class="p-3 text-center"><span class="inline-flex px-2 py-1 rounded text-[10px] font-bold ${typeMetaObj.cls}">${typeMetaObj.label}</span></td>
        <td class="p-3 text-center font-mono font-bold text-slate-700">${escapeHtml(q.k_level || 'K1')}</td>
        <td class="p-3 text-center font-mono font-bold text-slate-700">${escapeHtml(q.co_level || 'CO1')}</td>
        <td class="p-3 text-center"><span class="inline-flex px-2 py-1 rounded text-[10px] font-bold ${secMeta.cls} border ${secMeta.cls.replace('bg-', 'border-').replace('text-', '')}">${secMeta.label}</span></td>
        <td class="p-3 text-center"><span class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-bold ${statusCls}">${statusText}</span></td>
        <td class="p-3 corrections-cell">${correctionsHtml}</td>
        <td class="p-3 text-right whitespace-nowrap">${editBtn}${deleteBtn}</td>
      </tr>
    `;
  }).join('');

  lucide.createIcons();
}

function toggleExpand(btn) {
  const container = btn.closest('.question-text');
  const isExpanded = btn.textContent === 'Collapse';
  btn.textContent = isExpanded ? 'Expand' : 'Collapse';
  const span = container.querySelector('span');
  span.textContent = isExpanded ? container.dataset.truncated : container.dataset.full;
}

// ============================================
// EDIT MODAL
// ============================================
function openEditModal(qId) {
  const q = allQuestions.find(item => String(item.id) === String(qId)) || filteredQuestions.find(item => String(item.id) === String(qId));
  if (!q) return;
  editingQuestionId = q.id;

  const modal = document.getElementById('editModal');
  const content = document.getElementById('editModalContent');
  const title = document.getElementById('editModalTitle');
  const subtitle = document.getElementById('editModalSubtitle');

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

  subtitle.textContent = `Editing Q${Number(q.q_number || q.id)} · Unit ${Number(q.unit_no || 1)} · ${escapeHtml(q.sub_unit || '')}`;

  content.innerHTML = `
    <input type="hidden" id="editQId" value="${q.id}">
    <input type="hidden" id="editQDraftId" value="${currentDraftId}">

    ${q.correction_status === 'NEEDS_CORRECTION' && q.latest_open_correction ? `
      <div class="p-3 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 text-sm flex items-start gap-2">
        <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
        <div>
          <h5 class="font-bold">HOD Correction:</h5>
          <p class="mt-1 text-slate-700">${escapeHtml(q.latest_open_correction)}</p>
        </div>
      </div>
    ` : ''}

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Unit <span class="text-rose-500">*</span></label>
        <select id="editFieldUnit" onchange="updateEditSubunitOptions()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${[1,2,3,4,5].map(u => `<option value="${u}" ${Number(q.unit_no) === u ? 'selected' : ''}>Unit ${u}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Sub-Unit <span class="text-rose-500">*</span></label>
        <select id="editFieldSubUnit" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${[1,2,3,4,5,6].map(s => `<option value="${Number(q.unit_no || 1)}.${s}" ${q.sub_unit === `${Number(q.unit_no || 1)}.${s}` ? 'selected' : ''}>${Number(q.unit_no || 1)}.${s}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Marks <span class="text-rose-500">*</span></label>
        <input type="number" id="editFieldMarks" min="1" max="200" value="${Number(q.marks || 1)}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-bold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Section <span class="text-rose-500">*</span></label>
        <select id="editFieldSection" onchange="handleEditSectionChange()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          <option value="SECTION-A" ${currentSection === 'SECTION-A' ? 'selected' : ''}>Section A (1 Mark)</option>
          <option value="SECTION-B" ${currentSection === 'SECTION-B' ? 'selected' : ''}>Section B (5 Marks)</option>
          <option value="SECTION-C" ${currentSection === 'SECTION-C' ? 'selected' : ''}>Section C (10 Marks)</option>
          <option value="SECTION-D" ${currentSection === 'SECTION-D' ? 'selected' : ''}>Section D (10 Marks Compulsory)</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Question Type <span class="text-rose-500">*</span></label>
        <select id="editFieldType" onchange="handleEditTypeChange()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${typeSelectOptions}
        </select>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Bloom's (K-Level) <span class="text-rose-500">*</span></label>
        <select id="editFieldKLevel" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${['K1','K2','K3','K4','K5','K6'].map(k => `<option value="${k}" ${q.k_level === k ? 'selected' : ''}>${k}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Course Outcome (CO) <span class="text-rose-500">*</span></label>
        <select id="editFieldCOLevel" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-semibold focus:ring-2 focus:ring-indigo-500 focus:bg-white">
          ${['CO1','CO2','CO3','CO4','CO5','CO6'].map(c => `<option value="${c}" ${q.co_level === c ? 'selected' : ''}>${c}</option>`).join('')}
        </select>
      </div>
      <div id="editSectionDCheckbox" class="hidden flex items-center pt-4">
        <label class="inline-flex items-center gap-2 cursor-pointer">
          <input type="checkbox" id="editFieldIsCompulsory" ${q.section_type === 'SECTION-D' ? 'checked' : ''} class="w-4 h-4 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
          <span class="text-xs font-bold text-indigo-900 uppercase">Compulsory (Section D)</span>
        </label>
      </div>
    </div>

    <div>
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Question Text <span class="text-rose-500">*</span></label>
      <textarea id="editFieldQuestionText" rows="4" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 focus:ring-2 focus:ring-indigo-500 focus:bg-white text-sm">${escapeHtml(q.question_text || '')}</textarea>
    </div>

    <!-- MCQ Container -->
    <div id="editContainerMCQ" class="${q.question_type === 'MCQ' ? '' : 'hidden'} space-y-2 bg-slate-50/70 p-3 rounded-lg border border-slate-200/80">
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">MCQ Options & Answer</label>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        ${['A','B','C','D'].map(letter => `
          <div class="flex items-center gap-2">
            <span class="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center text-xs flex-shrink-0">${letter}</span>
            <input type="text" id="editMcqOpt${letter}" value="${escapeHtml(mcqOptions[letter] || '')}" placeholder="Option ${letter}..." class="w-full px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-sm">
          </div>
        `).join('')}
      </div>
      <div class="flex items-center gap-3 pt-1">
        <span class="text-xs font-bold text-slate-600 uppercase">Correct:</span>
        <div class="flex items-center gap-3 text-sm font-semibold">
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
    <div id="editContainerMatch" class="${q.question_type === 'MATCH' ? '' : 'hidden'} space-y-3 bg-slate-50/70 p-3 rounded-lg border border-slate-200/80">
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Match the Following</label>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <span class="text-xs font-bold text-slate-500 uppercase block mb-1">Column A</span>
          <div id="editMatchColumnA" class="space-y-1"></div>
          <button type="button" onclick="addEditMatchRow()" class="mt-2 px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold">+ Add Row</button>
        </div>
        <div>
          <span class="text-xs font-bold text-slate-500 uppercase block mb-1">Column B (extra option)</span>
          <div id="editMatchColumnB" class="space-y-1"></div>
          <button type="button" onclick="addEditMatchOption()" class="mt-2 px-2 py-1 rounded-lg bg-sky-50 text-sky-700 text-xs font-bold">+ Add Option</button>
        </div>
      </div>
      <div class="mt-2">
        <span class="text-xs font-bold text-slate-500 uppercase block mb-1">Assignments</span>
        <div id="editMatchAssignments" class="grid grid-cols-1 sm:grid-cols-2 gap-1"></div>
      </div>
    </div>

    <!-- AR Container -->
    <div id="editContainerAR" class="${q.question_type === 'ASSERTION_REASON' ? '' : 'hidden'} space-y-2 bg-slate-50/70 p-3 rounded-lg border border-slate-200/80">
      <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Assertion & Reason</label>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-0.5">Assertion (A):</label>
          <textarea id="editArAssertionText" rows="2" class="w-full px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-sm">${escapeHtml(q.assertion_text || '')}</textarea>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-0.5">Reason (R):</label>
          <textarea id="editArReasonText" rows="2" class="w-full px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-sm">${escapeHtml(q.reason_text || '')}</textarea>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Diagram / Image</label>
        <div class="flex items-center gap-2">
          <input type="file" id="editDiagramFileInput" accept="image/*" onchange="uploadEditDiagram(this)" class="block w-full text-xs text-slate-500 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700">
          <input type="hidden" id="editFieldImageUrl" value="${escapeHtml(q.image_url || '')}">
        </div>
        <div id="editDiagramPreview" class="hidden mt-1 relative inline-block">
          <img id="editDiagramPreviewImg" src="" alt="Diagram" class="h-16 w-auto rounded-lg border border-slate-200 shadow-sm object-contain bg-white p-1">
          <button type="button" onclick="removeEditDiagram()" class="absolute -top-1 -right-1 w-4 h-4 bg-rose-600 text-white rounded-full flex items-center justify-center text-xs shadow">✕</button>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Answer Key</label>
        <input type="text" id="editFieldAnswerKey" readonly class="w-full px-3 py-2 bg-slate-100 border border-slate-200 rounded-lg text-slate-800 text-sm cursor-not-allowed" value="${escapeHtml(q.answer_key || '')}">
      </div>
    </div>
  `;

  modal.classList.remove('hidden');
  modal.classList.add('flex');
  document.body.classList.add('overflow-hidden');
  lucide.createIcons();

  // Initialize match editor
  window.editMatchAItems = matchAItems;
  window.editMatchBItems = matchBItems;
  window.editMatchAssignments = matchAssignments;
  if (q.question_type === 'MATCH') renderEditMatchEditor();
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
    'SECTION-A': [{value: 'MCQ', label: 'Multiple Choice Question (MCQ)'},{value: 'MATCH', label: 'Match the Following'},{value: 'ASSERTION_REASON', label: 'Assertion & Reason'},{value: 'VSA', label: 'Very Short Answer'},{value: 'FILL_BLANKS', label: 'Fill in the Blanks'},{value: 'TRUE_FALSE', label: 'True / False'}],
    'SECTION-B': [{value: 'PARAGRAPH', label: 'Paragraph / Either-Or'},{value: 'CASE_STUDY', label: 'Case Study'}],
    'SECTION-C': [{value: 'ESSAY', label: 'Essay / Descriptive'},{value: 'CASE_STUDY', label: 'Case Study'}],
    'SECTION-D': [{value: 'COMPULSORY', label: 'Compulsory Question'}]
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
  if (type === 'MATCH') renderEditMatchEditor();
}

function updateEditSectionDVisibility() {
  document.getElementById('editSectionDCheckbox').classList.toggle('hidden', document.getElementById('editFieldSection').value !== 'SECTION-D');
}

function updateEditSubunitOptions() {
  const u = document.getElementById('editFieldUnit').value;
  const suSel = document.getElementById('editFieldSubUnit');
  suSel.innerHTML = [1,2,3,4,5,6].map(s => `<option value="${u}.${s}">${u}.${s}</option>`).join('');
}

// Match editor for edit modal
function renderEditMatchEditor() {
  const a = document.getElementById('editMatchColumnA');
  const b = document.getElementById('editMatchColumnB');
  const as = document.getElementById('editMatchAssignments');
  if (!a || !b || !as) return;

  if (!window.editMatchAItems) window.editMatchAItems = ['', '', '', ''];
  if (!window.editMatchBItems) window.editMatchBItems = ['', '', '', '', ''];
  if (!window.editMatchAssignments) window.editMatchAssignments = ['', '', '', ''];

  a.innerHTML = window.editMatchAItems.map((v, i) => `
    <div class="flex gap-1">
      <input class="match-a flex-1 min-w-0 px-2 py-1 border rounded text-xs" value="${escapeHtml(v)}" placeholder="Row ${i+1}" oninput="window.editMatchAItems[${i}]=this.value">
      <button type="button" onclick="removeEditMatchRow(${i})" class="text-rose-600 p-0.5">×</button>
    </div>
  `).join('');

  b.innerHTML = window.editMatchBItems.map((v, i) => `
    <div class="flex gap-1">
      <input class="match-b flex-1 min-w-0 px-2 py-1 border rounded text-xs" value="${escapeHtml(v)}" placeholder="Option ${i+1}" oninput="window.editMatchBItems[${i}]=this.value">
      <button type="button" onclick="removeEditMatchOption(${i})" class="text-rose-600 p-0.5">×</button>
    </div>
  `).join('');

  as.innerHTML = window.editMatchAItems.map((v, i) => {
    const usedByOthers = window.editMatchAssignments.map((val, row) => row !== i ? String(val) : '').filter(Boolean);
    return `
      <label class="text-[10px] text-slate-600 flex items-center gap-1">
        Row ${i+1}
        <select class="border rounded p-1 flex-1 text-xs" onchange="setEditMatchAssignment(${i},this.value)">
          <option value="">Choose</option>
          ${window.editMatchBItems.map((x, j) => {
            const optNo = String(j+1);
            if (usedByOthers.includes(optNo)) return '';
            return `<option value="${optNo}" ${String(window.editMatchAssignments[i] || '') === optNo ? 'selected' : ''}>Option ${j+1}</option>`;
          }).join('')}
        </select>
      </label>
    `;
  }).join('');
}

function setEditMatchAssignment(row, value) {
  window.editMatchAssignments[row] = value;
  renderEditMatchEditor();
}

function addEditMatchRow() {
  window.editMatchAItems.push('');
  window.editMatchAssignments.push('');
  window.editMatchBItems.push('');
  renderEditMatchEditor();
}

function addEditMatchOption() {
  if (window.editMatchBItems.length >= window.editMatchAItems.length + 3) {
    Swal.fire('Limit reached', 'Column B can have at most 2 extra options.', 'info');
    return;
  }
  window.editMatchBItems.push('');
  renderEditMatchEditor();
}

function removeEditMatchRow(i) {
  if (window.editMatchAItems.length <= 1) return;
  window.editMatchAItems.splice(i, 1);
  window.editMatchAssignments.splice(i, 1);
  renderEditMatchEditor();
}

function removeEditMatchOption(i) {
  if (window.editMatchBItems.length <= window.editMatchAItems.length + 1) {
    Swal.fire('Keep extra option', 'Column B must have at least one more option than Column A.', 'info');
    return;
  }
  window.editMatchBItems.splice(i, 1);
  window.editMatchAssignments = window.editMatchAssignments.map(v => v == i+1 ? '' : (v > i+1 ? String(+v-1) : v));
  renderEditMatchEditor();
}

function getEditMatchColumnA() { return window.editMatchAItems.slice(); }
function getEditMatchColumnB() { return window.editMatchBItems.slice(); }
function getEditMatchAnswerKey() { return window.editMatchAssignments.join(','); }

// Diagram upload for edit modal
async function uploadEditDiagram(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const formData = new FormData();
  formData.append('csrf', <?= $csrf ?>);
  formData.append('diagram_file', file);

  try {
    const res = await fetch(baseUrl + '/api/upload_diagram.php', {method: 'POST', body: formData});
    const data = await res.json();
    if (data.success) {
      document.getElementById('editFieldImageUrl').value = data.url;
      document.getElementById('editDiagramPreviewImg').src = data.url;
      document.getElementById('editDiagramPreview').classList.remove('hidden');
      Swal.fire({icon: 'success', title: 'Diagram Uploaded', timer: 1500, showConfirmButton: false});
    } else throw new Error(data.message || 'Upload failed');
  } catch (err) {
    Swal.fire({icon: 'error', title: 'Upload Failed', text: err.message});
  }
}

function removeEditDiagram() {
  document.getElementById('editFieldImageUrl').value = '';
  document.getElementById('editDiagramPreview').classList.add('hidden');
  document.getElementById('editDiagramFileInput').value = '';
}

// ============================================
// SAVE EDITED QUESTION (syncs to blueprint via API)
// ============================================
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

  let options = null, matchColA = null, matchColB = null, answerKey = '';
  let assertionText = '', reasonText = '';

  if (qType === 'MCQ') {
    const correctOpt = document.querySelector('input[name="editMcqCorrect"]:checked')?.value || '';
    options = {A: document.getElementById('editMcqOptA').value.trim(), B: document.getElementById('editMcqOptB').value.trim(), C: document.getElementById('editMcqOptC').value.trim(), D: document.getElementById('editMcqOptD').value.trim(), correct: correctOpt};
    if (!options.A || !options.B || !options.C || !options.D || !correctOpt) { Swal.fire({icon: 'warning', title: 'Complete MCQ', text: 'Enter all four options and select correct answer.'}); return; }
    answerKey = correctOpt;
  } else if (qType === 'MATCH') {
    matchColA = getEditMatchColumnA();
    matchColB = getEditMatchColumnB();
    answerKey = getEditMatchAnswerKey();
    if (!matchColA.length || matchColA.some(v => !v) || matchColB.some(v => !v) || window.editMatchAssignments.some(v => !v) || new Set(window.editMatchAssignments).size !== window.editMatchAssignments.length) { Swal.fire({icon: 'warning', title: 'Complete matching', text: 'Enter all items and assign each row.'}); return; }
  } else if (qType === 'ASSERTION_REASON') {
    assertionText = document.getElementById('editArAssertionText').value.trim();
    reasonText = document.getElementById('editArReasonText').value.trim();
  }

  const payload = {
    csrf: <?= $csrf ?>,
    action: 'save_question',
    question_id: parseInt(qId, 10),
    paper_code: currentDraft?.paper_code || '',
    semester: currentDraft?.semester || '',
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
    const res = await fetch(baseUrl + '/api/save_draft_question.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
    const data = await res.json();
    if (data.success) {
      Swal.fire({icon: 'success', title: 'Question Updated', text: 'Changes saved and blueprint synced.', timer: 1500, showConfirmButton: false});
      closeEditModal();
      location.reload(); // Refresh to show updated data
    } else throw new Error(data.message || 'Failed to update question');
  } catch (err) {
    Swal.fire({icon: 'error', title: 'Error', text: err.message});
  }
}

// ============================================
// DELETE QUESTION (syncs to blueprint via API)
// ============================================
function confirmDeleteQuestion(qId) {
  Swal.fire({
    title: 'Delete this question?',
    text: 'This will remove the question from the draft and update the blueprint distribution.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Yes, Delete'
  }).then(result => { if (result.isConfirmed) deleteQuestion(qId); });
}

async function deleteQuestion(qId) {
  try {
    const res = await fetch(baseUrl + '/api/save_draft_question.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'delete_question', question_id: qId, csrf: <?= $csrf ?>})});
    const data = await res.json();
    if (data.success) {
      Swal.fire({icon: 'success', title: 'Deleted', text: 'Question removed and blueprint updated.', timer: 1200, showConfirmButton: false});
      location.reload();
    } else throw new Error(data.message || 'Delete failed');
  } catch (err) {
    Swal.fire({icon: 'error', title: 'Error', text: err.message});
  }
}

// ============================================
// DELETE DRAFT
// ============================================
function confirmDeleteDraft(draftId, paperCode) {
  Swal.fire({
    title: `Delete Draft Bank #${draftId}?`,
    text: `This will permanently delete the draft for ${paperCode} and all its questions.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#647280',
    confirmButtonText: 'Yes, Delete Permanently'
  }).then(result => {
    if (result.isConfirmed) {
      fetch(baseUrl + '/api/save_draft_question.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'delete_draft', draft_id: draftId, csrf: <?= $csrf ?>})})
        .then(r => r.json())
        .then(d => { if (d.success) { Swal.fire('Deleted', d.message, 'success').then(() => location.href='draft.php'); } else throw new Error(d.message); })
        .catch(e => Swal.fire('Error', e.message, 'error'));
    }
  });
}

// ============================================
// HTML ESCAPE HELPER
// ============================================
function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>'"]/g, tag => ({'&': '&', '<': '<', '>': '>', "'": ''', '"': '"'}[tag] || tag));
}
</script>

<?php require_once __DIR__.'/../../includes/footer.php'; ?>