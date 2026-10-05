<?php
/**
 * COE Question Bank Inspection, Review & Management Portal
 * Holy Cross College (Autonomous) - Examination System
 */
define('PAGE_TITLE', 'COE Question Bank Review');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireCOE();
$pdo = getDBConnection();
qps_ensure_aux_schema($pdo);

$msg = '';
$error = '';
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));

// Handle POST actions: Delete Bank / Approve / Return
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_bank' && $id > 0) {
        try {
            $pdo->prepare("DELETE FROM questions WHERE bank_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM qps_bank_versions WHERE bank_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM qps_upload_history WHERE bank_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM question_banks WHERE id = ?")->execute([$id]);
            qps_audit($pdo, 'QUESTION_BANK_DELETE', 'QUESTION_BANK', (string)$id);
            
            header("Location: " . getBaseUrl() . "/modules/coe/dashboard.php?msg=" . urlencode("Question Bank #$id permanently deleted."));
            exit;
        } catch (Exception $e) {
            $error = "Failed to delete question bank: " . $e->getMessage();
        }
    }
}

$st = $pdo->prepare("SELECT * FROM question_banks WHERE id = ?");
$st->execute([$id]);
$bank = $st->fetch(PDO::FETCH_ASSOC);

if (!$bank) {
    http_response_code(404);
    die('Question bank not found.');
}

// Fetch questions
$stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
$stQ->execute([$id]);
$questions = $stQ->fetchAll(PDO::FETCH_ASSOC);

if (empty($questions) && !empty($bank['questions_json'])) {
    $dec = json_decode($bank['questions_json'], true);
    $questions = $dec['questions'] ?? (is_array($dec) ? $dec : []);
}

// Fetch versions
$stV = $pdo->prepare("SELECT * FROM qps_bank_versions WHERE bank_id = ? ORDER BY version_no DESC");
$stV->execute([$id]);
$versions = $stV->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-5">

  <!-- Header Card -->
  <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm space-y-4">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-2">
          <span class="bg-amber-100 text-amber-900 px-2.5 py-0.5 rounded-md font-mono text-[11px] font-bold border border-amber-300">
            <?php echo htmlspecialchars($bank['status']); ?>
          </span>
          <span class="text-xs text-slate-500 font-bold">Version <?php echo htmlspecialchars($bank['version_no'] ?? 1); ?></span>
          <span class="bg-indigo-50 text-indigo-800 text-[10px] font-bold px-2 py-0.5 rounded border border-indigo-200">
            <?php echo htmlspecialchars($bank['academic_year'] ?? DEFAULT_ACADEMIC_YEAR); ?>
          </span>
        </div>
        <h2 class="text-xl font-extrabold text-slate-900 mt-2"><?php echo htmlspecialchars($bank['paper_code']); ?> — <?php echo htmlspecialchars($bank['course_title']); ?></h2>
        <p class="text-xs text-slate-500 mt-1">
          <?php echo htmlspecialchars($bank['dept_name'] ?: $bank['dept_code']); ?> • <?php echo htmlspecialchars($bank['semester']); ?> • Exam: <?php echo htmlspecialchars($bank['exam_type'] ?? 'End Semester'); ?> • Uploaded by Faculty: <strong class="text-slate-800"><?php echo htmlspecialchars($bank['staff_code']); ?></strong>
        </p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/dashboard.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-1">
          <i data-lucide="arrow-left" class="w-4 h-4"></i>
          <span>Back to Dashboard</span>
        </a>

        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php?bank_id=<?php echo $id; ?>" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-3 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-1">
          <i data-lucide="edit-3" class="w-4 h-4"></i>
          <span>Edit Question Bank</span>
        </a>

        <?php if ($bank['status'] === 'Submitted'): ?>
          <a href="<?php echo getBaseUrl(); ?>/modules/coe/dashboard.php?approve_id=<?php echo $id; ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-1 shadow">
            <i data-lucide="check" class="w-4 h-4"></i>
            <span>Approve Bank</span>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/coe/dashboard.php?reject_id=<?php echo $id; ?>" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 px-3 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-1">
            <i data-lucide="x" class="w-4 h-4"></i>
            <span>Return to Faculty</span>
          </a>
        <?php endif; ?>

        <!-- Delete Bank Button -->
        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to permanently delete Question Bank #<?php echo $id; ?> and all its questions?');" class="inline">
          <input type="hidden" name="action" value="delete_bank">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white px-3 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-1 shadow" title="Delete Question Bank">
            <i data-lucide="trash-2" class="w-4 h-4"></i>
            <span>Delete Bank</span>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Metric Badges -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
    <div class="bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-[10px] text-slate-400 font-bold uppercase">Total Questions</div>
      <div class="text-base font-extrabold text-indigo-950 mt-0.5"><?php echo count($questions); ?> Qs</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-[10px] text-slate-400 font-bold uppercase">Source Format</div>
      <div class="text-base font-extrabold text-slate-900 mt-0.5"><?php echo htmlspecialchars(strtoupper($bank['source_format'] ?? 'GRID')); ?></div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-[10px] text-slate-400 font-bold uppercase">OCR Extraction</div>
      <div class="text-base font-extrabold text-slate-900 mt-0.5"><?php echo !empty($bank['ocr_used']) ? 'Used (' . htmlspecialchars($bank['ocr_language'] ?? 'eng') . ')' : 'Native Grid / Parser'; ?></div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-[10px] text-slate-400 font-bold uppercase">File Location</div>
      <div class="text-xs font-mono font-bold text-slate-700 mt-1 truncate" title="<?php echo htmlspecialchars($bank['source_path'] ?? ''); ?>">
        <?php echo htmlspecialchars(basename($bank['source_path'] ?? 'Database Grid')); ?>
      </div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-[10px] text-slate-400 font-bold uppercase">Actions</div>
      <div class="mt-1">
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php?bank_id=<?php echo $id; ?>" class="bg-amber-500 hover:bg-amber-400 text-slate-950 px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center space-x-1 shadow-sm">
          <i data-lucide="shuffle" class="w-3.5 h-3.5"></i>
          <span>Shuffle into Sets</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Questions Table -->
  <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm space-y-4">
    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
      <h3 class="font-extrabold text-slate-900 text-sm">Question Pool Inspection (<?php echo count($questions); ?>)</h3>
      <span class="text-xs text-slate-500">Unit-wise & Bloom's Cognitive Breakdown</span>
    </div>

    <div class="overflow-x-auto max-h-[750px] overflow-y-auto">
      <table class="w-full text-left text-xs border-collapse">
        <thead class="sticky top-0 z-10 bg-slate-800 text-white font-extrabold border-b border-slate-600">
          <tr>
            <th class="py-2.5 px-3 w-12 text-center">#</th>
            <th class="py-2.5 px-3 w-20 text-center">Unit</th>
            <th class="py-2.5 px-3 w-24 text-center">Sub-unit</th>
            <th class="py-2.5 px-3 w-16 text-center">Marks</th>
            <th class="py-2.5 px-3 w-28 text-center">Bloom Level</th>
            <th class="py-2.5 px-3 w-20 text-center">CO Level</th>
            <th class="py-2.5 px-3 w-32">Section / Part</th>
            <th class="py-2.5 px-4">Question Text & Media</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($questions as $idx => $q): ?>
            <?php 
              $uNum = (int)($q['unit_no'] ?? 1);
              $roman = ['I', 'II', 'III', 'IV', 'V'][$uNum - 1] ?? $uNum;
              $sub = $q['subunit_no'] ?? ($uNum . '.1');
            ?>
            <tr class="hover:bg-slate-50 transition align-top">
              <td class="py-3 px-3 text-center font-bold font-mono text-indigo-800"><?php echo $idx + 1; ?></td>
              <td class="py-3 px-3 text-center font-extrabold text-indigo-950">Unit <?php echo $roman; ?></td>
              <td class="py-3 px-3 text-center font-mono font-bold text-slate-700 bg-slate-50"><?php echo htmlspecialchars($sub); ?></td>
              <td class="py-3 px-3 text-center font-extrabold text-amber-900"><?php echo htmlspecialchars($q['marks'] ?? 2); ?>M</td>
              <td class="py-3 px-3 text-center font-bold text-blue-900"><?php echo htmlspecialchars($q['k_level'] ?? 'K1'); ?></td>
              <td class="py-3 px-3 text-center font-bold text-emerald-900"><?php echo htmlspecialchars($q['co_level'] ?? 'CO1'); ?></td>
              <td class="py-3 px-3 font-bold text-slate-800"><?php echo htmlspecialchars($q['section_type'] ?? 'Part A'); ?></td>
              <td class="py-3 px-4 space-y-2">
                <div class="leading-relaxed whitespace-pre-line text-slate-900"><?php echo htmlspecialchars($q['question_text'] ?? ''); ?></div>
                <?php if (!empty($q['image_url'])): ?>
                  <div class="mt-2 bg-slate-50 p-2 rounded-xl border border-slate-200 inline-block">
                    <img src="<?php echo htmlspecialchars($q['image_url']); ?>" class="max-h-28 rounded border border-slate-300">
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
