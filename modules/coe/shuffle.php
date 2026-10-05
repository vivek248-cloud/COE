<?php
/**
 * COE Question Paper Shuffler & Multi-Set Generator
 * Holy Cross College (Autonomous) - Examination Management System
 * Crextio Modern Design & Dynamic Department Filter
 */
define('PAGE_TITLE', 'Paper Shuffler & Multi-Set Generator');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireCOE();
$pdo = getDBConnection();
$user = getCurrentUser() ?: ['staff_code' => 'COE_OFFICE', 'role' => 'COE_ADMIN'];

$selectedBankId = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;

// Handle delete paper
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_paper') {
    $delId = (int)($_POST['paper_id'] ?? 0);
    if ($delId > 0) {
        try {
            qps_delete_paper_cascade($pdo, $delId);
            qps_audit($pdo, 'GENERATED_PAPER_DELETE', 'PAPER', (string)$delId);
        } catch (Exception $e) {}
    }
}

// Load departments for filter
$departments = [];
try {
    $stDept = $pdo->query("SELECT code, name FROM departments WHERE is_active = '1' OR is_active = 1 ORDER BY name ASC");
    $departments = $stDept->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Load all approved or submitted question banks across all departments (including CS, AI, etc.)
$banks = [];
try {
    $stmt = $pdo->query("SELECT qb.*, COALESCE(d.name, qb.dept_name, qb.dept_code) as dept_name,
                                COALESCE(s.deptcode, s.dept_code1, qb.dept_code) as staff_deptcode
                         FROM question_banks qb 
                         LEFT JOIN departments d ON d.code = qb.dept_code 
                         LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = qb.staff_code
                         ORDER BY qb.id DESC");
    $banks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Find selected bank details if bank_id is provided
$selectedBank = null;
if ($selectedBankId > 0) {
    foreach ($banks as $b) {
        if ((int)$b['id'] === $selectedBankId) {
            $selectedBank = $b;
            break;
        }
    }
}

// Load previously generated papers with pagination
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$totalPapers = 0;
try {
    $totalPapers = (int)$pdo->query("SELECT COUNT(*) FROM generated_papers")->fetchColumn();
} catch (Exception $e) {}
$totalPages = max(1, (int)ceil($totalPapers / $perPage));

$generatedPapers = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM generated_papers ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $generatedPapers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header Banner with Warm Crextio Ambient Styling -->
  <div class="bg-gradient-to-r from-[#1C1D21] via-slate-900 to-indigo-950 rounded-[28px] p-7 text-white shadow-xl border border-stone-800 relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-amber-400/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="inline-flex items-center gap-2 bg-amber-400/25 text-amber-300 border border-amber-400/30 rounded-full px-3.5 py-1 text-[11px] font-extrabold uppercase tracking-wider">
          <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-300"></i> HOLY CROSS COLLEGE (AUTONOMOUS) • SHUFFLER ENGINE
        </div>
        <h2 class="text-xl sm:text-2xl font-extrabold mt-2.5 tracking-tight">Randomized Question Paper Multi-Set Generator</h2>
        <p class="text-stone-300 text-xs mt-1.5 max-w-3xl leading-relaxed">
          Generate official examination sets (<strong>SET A, SET B, SET C</strong>) strictly conforming to Holy Cross College OBE blueprints (Matching <strong>U23BC3ALT05.pdf</strong>). Supports live question swapping, DOCX exports, and print-ready layout generation with zero question duplication.
        </p>
      </div>
      <div class="flex items-center gap-2.5">
        <button type="button" onclick="runCOEShuffle()" class="bg-[#F6C443] hover:bg-[#EAB326] text-slate-950 font-black px-6 py-3 rounded-full shadow-lg flex items-center space-x-2 transition text-xs">
          <i data-lucide="shuffle" class="w-4 h-4"></i>
          <span>Shuffle & Generate Sets</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Shuffler Controls Card with Department Filter -->
  <div class="bg-white rounded-[28px] shadow-sm border border-stone-200/80 p-6 space-y-5">
    <div class="flex items-center justify-between border-b border-stone-100 pb-3">
      <div class="flex items-center space-x-3">
        <div class="w-8 h-8 rounded-full bg-amber-50 text-amber-800 flex items-center justify-center font-extrabold text-sm border border-amber-200 shadow-sm">
          <i data-lucide="sliders" class="w-4 h-4"></i>
        </div>
        <div>
          <h3 class="font-extrabold text-slate-900 text-sm">Shuffler Parameters & Constraints</h3>
          <p class="text-[11px] text-slate-500">Filter by Department to view relevant course banks, blueprint matrix, and desired number of unique sets.</p>
        </div>
      </div>

      <!-- Department Quick Filter -->
      <div class="flex items-center space-x-2">
        <span class="text-xs font-bold text-slate-500">Filter Dept:</span>
        <select id="shuffler-dept-filter" onchange="filterShufflerBanksByDept()" class="bg-stone-50 border border-stone-300 rounded-full text-xs px-3 py-1.5 font-bold text-slate-800 shadow-sm">
          <option value="">All Departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?php echo htmlspecialchars($d['code']); ?>" <?php echo ($selectedBank && ($selectedBank['dept_code'] === $d['code'] || $selectedBank['staff_deptcode'] === $d['code'])) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($d['code'] . ' - ' . $d['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Parameters Grid with Searchable Typing Dropdowns -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
      <div>
        <label class="block font-bold text-slate-700 mb-1">Select Question Bank *</label>
        <select id="shuffler-bank-select" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm">
          <?php if (empty($banks)): ?>
            <option value="">No Question Banks Available</option>
          <?php else: ?>
            <?php foreach ($banks as $b): ?>
              <option value="<?php echo $b['id']; ?>" data-dept="<?php echo htmlspecialchars($b['dept_code']); ?>" data-staff-dept="<?php echo htmlspecialchars($b['staff_deptcode'] ?? ''); ?>" <?php echo ($selectedBankId === intval($b['id'])) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($b['paper_code'] . ' — ' . $b['course_title'] . ' (' . ($b['total_questions'] ?: 30) . ' Qs)'); ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>

      <div>
        <label class="block font-bold text-slate-700 mb-1">OBE Blueprint Matrix *</label>
        <select id="shuffler-blueprint-select" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm">
          <?php
          $bps=[];
          try{$bps=$pdo->query("SELECT id, name, total_marks, duration_hours, paper_code FROM blueprints ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){}
          foreach($bps as $bp): ?>
            <option value="<?php echo (int)$bp['id']; ?>"><?php echo htmlspecialchars($bp['name'].' — '.$bp['total_marks'].'M'.(!empty($bp['paper_code']) ? ' ['.$bp['paper_code'].']' : '')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block font-bold text-slate-700 mb-1">Sets to Generate</label>
        <select id="shuffler-sets-count" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm">
          <option value="3">3 Sets (SET A, SET B, SET C)</option>
          <option value="2">2 Sets (SET A, SET B)</option>
          <option value="1">1 Set (SET A only)</option>
        </select>
      </div>

      <div>
        <label class="block font-bold text-slate-700 mb-1">Examination Session / Month</label>
        <input type="text" id="shuffler-exam-date" value="NOVEMBER 2026" class="w-full bg-stone-50 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 shadow-sm" />
      </div>
    </div>
  </div>

  <!-- Shuffled Results Container -->
  <div id="shuffled-results-container" class="space-y-6">
    <!-- Populated dynamically upon shuffling -->
  </div>

  <!-- Previously Generated Papers History Repository -->
  <?php if (!empty($generatedPapers)): ?>
    <div class="bg-white rounded-[28px] shadow-sm border border-stone-200/80 p-6 space-y-4">
      <div class="flex items-center justify-between border-b border-stone-100 pb-3">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 rounded-full bg-stone-100 text-slate-900 flex items-center justify-center border border-stone-200 shadow-sm">
            <i data-lucide="history" class="w-5 h-5"></i>
          </div>
          <div>
            <h3 class="font-extrabold text-slate-900 text-sm sm:text-base">Generated Examination Papers History (<?php echo $totalPapers; ?>)</h3>
            <p class="text-[11px] text-slate-500">Official archived sets ready for printing, live swapping, and DOCX export.</p>
          </div>
        </div>
      </div>

      <div class="overflow-x-auto custom-scrollbar-x text-xs">
        <table class="w-full text-left border-collapse min-w-[850px]">
          <thead class="bg-stone-50 text-slate-700 font-black uppercase text-[11px] border-b border-stone-200">
            <tr>
              <th class="py-3 px-3">Paper ID</th>
              <th class="py-3 px-3">Paper Code & Title</th>
              <th class="py-3 px-3 text-center">Set</th>
              <th class="py-3 px-3">Semester / Year</th>
              <th class="py-3 px-3 text-center">Marks</th>
              <th class="py-3 px-3">Generated At</th>
              <th class="py-3 px-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100 font-medium">
            <?php foreach ($generatedPapers as $gp): ?>
              <tr class="hover:bg-stone-50/70 transition">
                <td class="py-3.5 px-3 font-mono font-black text-indigo-700">#<?php echo $gp['id']; ?></td>
                <td class="py-3.5 px-3 font-bold text-slate-900">
                  <div class="font-mono text-indigo-950 font-black"><?php echo htmlspecialchars($gp['paper_code']); ?></div>
                  <div class="text-[11px] text-slate-600 font-normal"><?php echo htmlspecialchars($gp['course_title']); ?></div>
                </td>
                <td class="py-3.5 px-3 text-center">
                  <span class="bg-[#1C1D21] text-amber-300 px-3 py-1 rounded-full font-mono font-black text-xs"><?php echo htmlspecialchars($gp['set_name'] ?: 'SET A'); ?></span>
                </td>
                <td class="py-3.5 px-3 text-slate-700"><?php echo htmlspecialchars($gp['semester'] ?? ''); ?> (<?php echo htmlspecialchars($gp['academic_year'] ?? ''); ?>)</td>
                <td class="py-3.5 px-3 text-center font-black text-amber-900"><?php echo $gp['total_marks']; ?>M</td>
                <td class="py-3.5 px-3 text-slate-500 font-mono text-[11px]"><?php echo htmlspecialchars($gp['created_at']); ?></td>
                <td class="py-3.5 px-3 text-right">
                  <div class="flex items-center justify-end space-x-1.5">
                    <a href="<?php echo getBaseUrl(); ?>/modules/coe/view_paper.php?paper_id=<?php echo $gp['id']; ?>" class="bg-stone-100 hover:bg-stone-200 text-slate-800 border border-stone-200 px-3.5 py-1.5 rounded-full font-bold text-xs flex items-center space-x-1 shadow-sm transition">
                      <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                      <span>Print</span>
                    </a>
                    <a href="<?php echo getBaseUrl(); ?>/api/export_docx.php?paper_id=<?php echo $gp['id']; ?>" class="bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-3.5 py-1.5 rounded-full font-bold text-xs flex items-center space-x-1 shadow-sm transition">
                      <i data-lucide="file-down" class="w-3.5 h-3.5"></i>
                      <span>DOCX</span>
                    </a>
                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete Generated Paper #<?php echo $gp['id']; ?>?');" class="inline">
                      <input type="hidden" name="action" value="delete_paper">
                      <input type="hidden" name="paper_id" value="<?php echo $gp['id']; ?>">
                      <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 p-1.5 rounded-full font-bold text-xs transition border border-rose-200 shadow-sm" title="Delete Generated Paper">
                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
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
        <div class="pt-2">
          <?php echo render_pagination($page, $totalPages, $totalPapers, $perPage, $_GET); ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</main>

<!-- Swap Candidates Modal with Crextio Soft Corners & Backdrop Blur -->
<div id="swap-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-[28px] max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[85vh] flex flex-col border border-stone-200">
    <div class="flex items-center justify-between border-b border-stone-100 pb-3">
      <div>
        <h3 class="font-extrabold text-base text-slate-900">Swap Question with Alternative from Bank</h3>
        <p class="text-xs text-slate-500" id="swap-modal-subtitle">Matching Unit and Marks bracket</p>
      </div>
      <button onclick="closeSwapModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-stone-100"><i data-lucide="x" class="w-5 h-5"></i></button>
    </div>

    <div class="overflow-y-auto custom-scrollbar-y flex-1 space-y-2.5 pr-1" id="swap-candidates-list">
      <!-- Dynamically populated candidate questions -->
    </div>

    <div class="flex justify-end pt-3 border-t border-stone-100">
      <button onclick="closeSwapModal()" class="px-5 py-2 text-xs text-slate-700 bg-stone-100 hover:bg-stone-200 rounded-full font-bold transition">Close</button>
    </div>
  </div>
</div>

<script>
const allBanksData = <?php echo json_encode($banks); ?>;
const preselectedBankId = <?php echo $selectedBankId; ?>;

function filterShufflerBanksByDept() {
  const dept = document.getElementById('shuffler-dept-filter').value;
  const select = document.getElementById('shuffler-bank-select');
  if (!select) return;

  select.innerHTML = '';
  const filtered = allBanksData.filter(b => {
    if (!dept) return true;
    return (b.dept_code === dept || b.staff_deptcode === dept);
  });

  if (filtered.length > 0) {
    filtered.forEach(b => {
      const opt = document.createElement('option');
      opt.value = b.id;
      opt.textContent = `${b.paper_code} — ${b.course_title} (${b.total_questions || 30} Qs)`;
      opt.dataset.dept = b.dept_code;
      opt.dataset.staffDept = b.staff_deptcode || '';
      if (preselectedBankId === parseInt(b.id)) {
        opt.selected = true;
      }
      select.appendChild(opt);
    });
  } else {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'No question banks found for selected department';
    select.appendChild(opt);
  }
}
</script>

<script src="<?php echo getBaseUrl(); ?>/assets/js/shuffler.js"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
