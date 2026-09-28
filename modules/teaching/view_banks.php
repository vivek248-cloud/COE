<?php
/**
 * View Question Banks Repository
 * Holy Cross College (Autonomous) - Examination Management System
 * - Teaching Staff: My Banks & Submit to HOD
 * - HOD: Department Banks, Multi-Filter Question Verification Workspace, Duplicate Check SweetAlert & Submit to COE
 * - COE: Institutional Banks & Paper Shuffling
 */
define('PAGE_TITLE', 'Question Banks Repository & Verification');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireAuth();
$user = getCurrentUser();
$pdo = getDBConnection();

$isCoeUser = isCOE();
$isHodUser = isHOD();
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$query = "SELECT qb.*, s.FIRST_NAME as staff_name FROM question_banks qb LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = qb.staff_code WHERE 1=1";
$cntQuery = "SELECT COUNT(*) FROM question_banks qb WHERE 1=1";
$params = [];

if (!$isCoeUser) {
    if ($isHodUser) {
        // HOD sees question banks from their department OR assigned courses
        $userDept = $user['dept_code'] ?? '';
        $query .= " AND (UPPER(qb.dept_code) = UPPER(?) OR qb.staff_code = ?)";
        $cntQuery .= " AND (UPPER(qb.dept_code) = UPPER(?) OR qb.staff_code = ?)";
        $params[] = $userDept;
        $params[] = $user['staff_code'];
    } else {
        // Regular faculty sees their own banks
        $query .= " AND qb.staff_code = ?";
        $cntQuery .= " AND qb.staff_code = ?";
        $params[] = $user['staff_code'];
    }
}

$stmtCnt = $pdo->prepare($cntQuery);
$stmtCnt->execute($params);
$totalBanks = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalBanks / $perPage));

$query .= " ORDER BY qb.id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If viewing a specific bank details / HOD verification
$viewBankId = intval($_GET['view_id'] ?? 0);
$inspectedBank = null;
$inspectedQuestions = [];
if ($viewBankId) {
    $stB = $pdo->prepare("SELECT qb.*, s.FIRST_NAME as staff_name FROM question_banks qb LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = qb.staff_code WHERE qb.id = ?");
    $stB->execute([$viewBankId]);
    $inspectedBank = $stB->fetch(PDO::FETCH_ASSOC);
    if ($inspectedBank) {
        $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
        $stQ->execute([$viewBankId]);
        $inspectedQuestions = $stQ->fetchAll(PDO::FETCH_ASSOC);
    }
}

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
          <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-300"></i> HOLY CROSS COLLEGE (AUTONOMOUS) • <?php echo $isCoeUser ? 'COE OFFICE' : ($isHodUser ? 'HOD DEPARTMENT REPOSITORY' : 'FACULTY REPOSITORY'); ?>
        </div>
        <h2 class="text-xl sm:text-2xl font-extrabold mt-2.5 tracking-tight">Question Bank Repository & Verification Dashboard</h2>
        <p class="text-stone-300 text-xs mt-1.5 max-w-3xl leading-relaxed">
          <?php if ($isHodUser): ?>
            Review, verify, and check duplicate questions submitted by faculty members across all units and sections. Approved banks can be directly forwarded to the Controller of Examinations.
          <?php else: ?>
            Manage uploaded question pools, check answer keys, view version histories, and track COE status.
          <?php endif; ?>
        </p>
      </div>
      <div class="flex items-center gap-2.5">
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php" class="bg-amber-400 hover:bg-amber-300 text-slate-950 text-xs font-black px-4 py-2.5 rounded-full flex items-center space-x-2 shadow-lg transition">
          <i data-lucide="plus-circle" class="w-4 h-4"></i>
          <span>Upload Question Bank</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Main Repository Card with Crextio Soft Cards -->
  <div class="bg-white rounded-[28px] shadow-sm border border-stone-200/80 p-6 space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-stone-100 pb-4">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-700 flex items-center justify-center border border-indigo-200 shadow-sm">
          <i data-lucide="folder-kanban" class="w-5 h-5"></i>
        </div>
        <div>
          <h3 class="text-base sm:text-lg font-extrabold text-slate-900"><?php echo $isCoeUser ? 'Institutional Question Banks' : ($isHodUser ? 'Department Question Banks (HOD View)' : 'My Assigned Course Banks'); ?></h3>
          <p class="text-xs text-slate-500">Track status, answer keys, Bloom's cognitive K-levels, and course outcomes.</p>
        </div>
      </div>
    </div>

    <div class="overflow-x-auto text-xs custom-scrollbar-x rounded-2xl border border-stone-200">
      <table class="w-full text-left border-collapse min-w-[850px]">
        <thead class="bg-stone-900 text-stone-200 uppercase font-black text-[10px] tracking-wider">
          <tr>
            <th class="py-3 px-3">Bank ID</th>
            <?php if ($isCoeUser || $isHodUser): ?><th class="py-3 px-3">Faculty / Staff</th><?php endif; ?>
            <th class="py-3 px-3">Paper Code & Title</th>
            <th class="py-3 px-3">Department / Degree</th>
            <th class="py-3 px-3">Semester & Year</th>
            <th class="py-3 px-3 text-center">Questions</th>
            <th class="py-3 px-3 text-center">Status</th>
            <th class="py-3 px-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 font-medium bg-white">
          <?php if (empty($banks)): ?>
            <tr>
              <td colspan="<?php echo ($isCoeUser || $isHodUser) ? '8' : '7'; ?>" class="py-12 text-center text-slate-400 text-xs font-semibold">
                No question banks recorded yet. Click <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php" class="text-indigo-600 font-bold underline">here</a> to upload or create one.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($banks as $b): ?>
              <tr class="hover:bg-stone-50/80 transition <?php echo ($viewBankId === (int)$b['id']) ? 'bg-amber-50/40 font-bold' : ''; ?>">
                <td class="py-3.5 px-3 font-mono font-black text-indigo-700">#<?php echo $b['id']; ?></td>
                
                <?php if ($isCoeUser || $isHodUser): ?>
                  <td class="py-3.5 px-3 font-bold text-slate-800">
                    <div><?php echo htmlspecialchars($b['staff_name'] ?? $b['staff_code']); ?></div>
                    <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($b['staff_code']); ?></div>
                  </td>
                <?php endif; ?>

                <td class="py-3.5 px-3 font-bold text-slate-900">
                  <div class="font-mono text-indigo-950 font-black"><?php echo htmlspecialchars($b['paper_code']); ?></div>
                  <div class="text-[11px] text-slate-600 font-normal"><?php echo htmlspecialchars($b['course_title']); ?></div>
                </td>
                
                <td class="py-3.5 px-3 text-slate-700">
                  <?php echo htmlspecialchars($b['dept_name'] ?: $b['dept_code']); ?> • <span class="font-bold"><?php echo htmlspecialchars($b['degree_level'] ?: 'UG'); ?></span>
                </td>
                
                <td class="py-3.5 px-3 text-slate-700">
                  <div class="font-bold"><?php echo htmlspecialchars($b['semester']); ?></div>
                  <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($b['academic_year']); ?></div>
                </td>
                
                <td class="py-3.5 px-3 text-center font-bold text-indigo-950">
                  <span class="bg-indigo-50 text-indigo-800 border border-indigo-200 px-2.5 py-1 rounded-full text-[11px] font-mono"><?php echo htmlspecialchars($b['total_questions'] ?: 30); ?> Qs</span>
                </td>
                
                <td class="py-3.5 px-3 text-center">
                  <?php 
                    $stStr = $b['status'] ?? 'Draft';
                    if ($stStr === 'Submitted to COE' || $stStr === 'Approved' || $stStr === 'Submitted'): 
                  ?>
                    <span class="bg-emerald-100 text-emerald-900 border border-emerald-300 text-[10px] px-2.5 py-1 rounded-full font-black">Approved & Submitted to COE</span>
                  <?php elseif ($stStr === 'Submitted to HOD'): ?>
                    <span class="bg-amber-100 text-amber-950 border border-amber-300 text-[10px] px-2.5 py-1 rounded-full font-black">Submitted to HOD</span>
                  <?php else: ?>
                    <span class="bg-stone-100 text-slate-700 border border-stone-200 text-[10px] px-2.5 py-1 rounded-full font-bold">Draft</span>
                  <?php endif; ?>
                </td>
                
                <td class="py-3.5 px-3 text-right">
                  <div class="flex items-center justify-end space-x-1.5">
                    
                    <!-- HOD / Verification Button -->
                    <?php if ($isHodUser || $isCoeUser): ?>
                      <a href="?view_id=<?php echo $b['id']; ?>#inspect-bank-section" class="bg-[#1C1D21] hover:bg-slate-800 text-white px-3 py-1.5 rounded-full text-xs font-bold transition flex items-center space-x-1 shadow-xs">
                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-amber-400"></i>
                        <span>Verify & Review</span>
                      </a>
                    <?php else: ?>
                      <a href="?view_id=<?php echo $b['id']; ?>#inspect-bank-section" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded-full text-xs font-bold transition">
                        View
                      </a>
                    <?php endif; ?>

                    <?php if ($isCoeUser): ?>
                      <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php?bank_id=<?php echo $b['id']; ?>" class="bg-amber-400 hover:bg-amber-300 text-slate-950 text-xs font-black px-3 py-1.5 rounded-full shadow-xs transition">
                        Shuffle
                      </a>
                    <?php endif; ?>

                    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php?bank_id=<?php echo $b['id']; ?>" class="bg-stone-100 hover:bg-stone-200 text-slate-700 px-2.5 py-1.5 rounded-full text-xs font-bold transition" title="Edit in Grid">
                      Edit
                    </a>

                    <button type="button" onclick="deleteBankPrompt(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars($b['paper_code']); ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 p-1.5 rounded-full text-xs font-bold transition border border-rose-200" title="Delete Question Bank">
                      <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="pt-2">
        <?php echo render_pagination($page, $totalPages, $totalBanks, $perPage, $_GET); ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Interactive HOD / Faculty Verification & Review Workspace -->
  <?php if ($inspectedBank): ?>
    <div id="inspect-bank-section" class="bg-white rounded-[28px] shadow-xl border border-stone-200/90 p-6 space-y-6">
      
      <!-- Drawer Header with Course Info & Actions -->
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-stone-200">
        <div>
          <div class="flex items-center space-x-2.5">
            <span class="bg-[#1C1D21] text-amber-300 font-mono font-black text-xs px-3 py-1 rounded-full shadow-xs">BANK #<?php echo $inspectedBank['id']; ?></span>
            <h3 class="font-black text-slate-900 text-base"><?php echo htmlspecialchars($inspectedBank['paper_code'] . ' — ' . $inspectedBank['course_title']); ?></h3>
          </div>
          <p class="text-xs text-slate-500 mt-1.5">
            Faculty: <strong><?php echo htmlspecialchars($inspectedBank['staff_name'] ?? $inspectedBank['staff_code']); ?></strong> (<?php echo htmlspecialchars($inspectedBank['staff_code']); ?>) • <?php echo htmlspecialchars($inspectedBank['semester']); ?> (<?php echo htmlspecialchars($inspectedBank['academic_year']); ?>) • Pool: <strong class="text-slate-900 font-bold"><?php echo count($inspectedQuestions); ?> Questions</strong>
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          
          <!-- HOD Check Duplicates Button -->
          <button type="button" onclick="runBankDuplicateCheck(<?php echo $inspectedBank['id']; ?>)" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-black px-4 py-2 rounded-full text-xs shadow flex items-center space-x-1.5 transition">
            <i data-lucide="search-check" class="w-4 h-4"></i>
            <span>Check Duplicates</span>
          </button>

          <!-- HOD Submit to COE Button (Visible for HOD only, NOT COE) -->
          <?php if ($isHodUser && !$isCoeUser): ?>
            <button type="button" onclick="submitBankToCoe(<?php echo $inspectedBank['id']; ?>)" class="bg-[#1C1D21] hover:bg-slate-800 text-white font-black px-4 py-2 rounded-full text-xs shadow flex items-center space-x-1.5 transition">
              <i data-lucide="send" class="w-4 h-4 text-amber-400"></i>
              <span>Submit to COE</span>
            </button>
          <?php endif; ?>

          <!-- COE Office Actions -->
          <?php if ($isCoeUser): ?>
            <button type="button" onclick="approveBankAsCOE(<?php echo $inspectedBank['id']; ?>)" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black px-4 py-2 rounded-full text-xs shadow flex items-center space-x-1.5 transition">
              <i data-lucide="shield-check" class="w-4 h-4 text-amber-300"></i>
              <span>Approve Bank</span>
            </button>
            <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php?bank_id=<?php echo $inspectedBank['id']; ?>" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-black px-4 py-2 rounded-full text-xs shadow flex items-center space-x-1.5 transition">
              <i data-lucide="shuffle" class="w-3.5 h-3.5"></i>
              <span>Shuffle Paper</span>
            </a>
          <?php endif; ?>

          <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php?bank_id=<?php echo $inspectedBank['id']; ?>" class="bg-stone-100 hover:bg-stone-200 text-slate-800 font-bold px-3.5 py-2 rounded-full text-xs transition">
            Edit in Grid
          </a>
          <button type="button" onclick="deleteBankPrompt(<?php echo $inspectedBank['id']; ?>, '<?php echo htmlspecialchars($inspectedBank['paper_code']); ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold px-3.5 py-2 rounded-full text-xs border border-rose-200 transition flex items-center space-x-1">
            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
            <span>Delete Bank</span>
          </button>
          <a href="?" class="bg-stone-100 hover:bg-stone-200 text-slate-700 font-bold px-3.5 py-2 rounded-full text-xs transition">
            Close
          </a>
        </div>
      </div>

      <!-- Quick Filter Navigation Tabs for Section A, B, C, D and Units -->
      <div class="flex flex-wrap items-center justify-between gap-3 bg-stone-50 p-3 rounded-2xl border border-stone-200">
        <div class="flex items-center space-x-1 text-xs font-bold">
          <span class="text-slate-400 uppercase tracking-wider text-[10px] mr-2">Filter Section:</span>
          <button type="button" onclick="filterInspectCards('ALL')" id="insp-btn-all" class="px-3 py-1 rounded-full bg-[#1C1D21] text-white text-[11px] shadow-sm">All</button>
          <button type="button" onclick="filterInspectCards('SECTION-A')" id="insp-btn-a" class="px-3 py-1 rounded-full text-slate-700 hover:text-slate-900 text-[11px]">Section A</button>
          <button type="button" onclick="filterInspectCards('SECTION-B')" id="insp-btn-b" class="px-3 py-1 rounded-full text-slate-700 hover:text-slate-900 text-[11px]">Section B</button>
          <button type="button" onclick="filterInspectCards('SECTION-C')" id="insp-btn-c" class="px-3 py-1 rounded-full text-slate-700 hover:text-slate-900 text-[11px]">Section C</button>
          <button type="button" onclick="filterInspectCards('SECTION-D')" id="insp-btn-d" class="px-3 py-1 rounded-full text-slate-700 hover:text-slate-900 text-[11px]">Section D</button>
        </div>

        <div class="flex items-center space-x-2 text-xs">
          <label class="text-slate-500 font-bold">Unit:</label>
          <select id="insp-unit-select" onchange="filterInspectCardsByUnit(this.value)" class="border border-stone-300 rounded-xl px-2 py-1 bg-white font-bold text-xs">
            <option value="ALL">All Units</option>
            <option value="1">Unit I</option>
            <option value="2">Unit II</option>
            <option value="3">Unit III</option>
            <option value="4">Unit IV</option>
            <option value="5">Unit V</option>
          </select>
        </div>
      </div>

      <!-- Question Cards Grid with Answer Key Viewer -->
      <div id="inspectCardsContainer" class="space-y-4 max-h-[700px] overflow-y-auto custom-scrollbar-y pr-1">
        <?php foreach ($inspectedQuestions as $q): ?>
          <div class="inspect-q-card border border-stone-200 rounded-2xl p-5 bg-white hover:border-indigo-400 hover:shadow-md transition space-y-3"
               data-section="<?php echo strtoupper($q['section_type'] ?? 'SECTION-A'); ?>"
               data-unit="<?php echo $q['unit_no']; ?>">
            
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-100 pb-3 text-xs">
              <div class="flex items-center space-x-2">
                <span class="bg-[#1C1D21] text-white font-black font-mono px-2.5 py-1 rounded-xl text-[11px]">Q.<?php echo $q['q_number']; ?></span>
                <span class="font-extrabold text-slate-800 bg-stone-100 px-2.5 py-0.5 rounded-full"><?php echo htmlspecialchars($q['section_type'] ?: 'SECTION-A'); ?></span>
                <span class="bg-indigo-50 text-indigo-900 font-bold px-2.5 py-0.5 rounded-full text-[11px] border border-indigo-200">
                  Unit <?php echo $q['unit_no']; ?><?php echo !empty($q['sub_unit']) ? ' (' . htmlspecialchars($q['sub_unit']) . ')' : ''; ?>
                </span>
              </div>
              <div class="flex items-center space-x-2 font-bold text-[11px]">
                <span class="bg-amber-100 text-amber-950 px-2.5 py-0.5 rounded-full font-mono"><?php echo $q['marks']; ?> Marks</span>
                <span class="bg-blue-50 text-blue-900 px-2.5 py-0.5 rounded-full font-mono border border-blue-200"><?php echo $q['k_level']; ?></span>
                <span class="bg-emerald-50 text-emerald-900 px-2.5 py-0.5 rounded-full font-mono border border-emerald-200"><?php echo $q['co_level']; ?></span>
              </div>
            </div>

            <div class="text-xs text-slate-900 leading-relaxed font-sans whitespace-pre-wrap">
              <?php echo htmlspecialchars($q['question_text']); ?>
            </div>

            <!-- Answer Key Card -->
            <?php if (!empty($q['answer_key'])): ?>
              <div class="bg-emerald-50/80 border border-emerald-200 rounded-xl p-3 flex items-start space-x-2.5 text-xs">
                <span class="bg-emerald-600 text-white font-black text-[10px] px-2 py-0.5 rounded-md uppercase shrink-0">Answer Key</span>
                <div class="font-bold text-emerald-950 whitespace-pre-wrap leading-relaxed">
                  <?php echo htmlspecialchars($q['answer_key']); ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if (!empty($q['image_url'])): ?>
              <div class="mt-2 bg-stone-50 p-3 border border-stone-200 rounded-2xl inline-block shadow-xs">
                <div class="text-[10px] font-bold text-slate-400 mb-1">Attached Diagram / Figure:</div>
                <img src="<?php echo htmlspecialchars($q['image_url']); ?>" class="max-h-36 rounded-xl border border-stone-200">
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

    </div>
  <?php endif; ?>

</main>

<script>
const baseUrl = <?php echo json_encode(getBaseUrl()); ?>;

function filterInspectCards(sec) {
  ['ALL', 'SECTION-A', 'SECTION-B', 'SECTION-C', 'SECTION-D'].forEach(s => {
    const id = 'insp-btn-' + (s === 'ALL' ? 'all' : s.replace('SECTION-', '').toLowerCase());
    const btn = document.getElementById(id);
    if (btn) {
      if (s === sec) {
        btn.className = 'px-3 py-1 rounded-full bg-[#1C1D21] text-white text-[11px] shadow-sm';
      } else {
        btn.className = 'px-3 py-1 rounded-full text-slate-700 hover:text-slate-900 text-[11px]';
      }
    }
  });

  const cards = document.querySelectorAll('.inspect-q-card');
  cards.forEach(c => {
    const s = c.dataset.section || '';
    if (sec === 'ALL' || s.includes(sec.replace('SECTION-', ''))) {
      c.style.display = 'block';
    } else {
      c.style.display = 'none';
    }
  });
}

function filterInspectCardsByUnit(unit) {
  const cards = document.querySelectorAll('.inspect-q-card');
  cards.forEach(c => {
    const u = c.dataset.unit;
    if (unit === 'ALL' || u === unit) {
      c.style.display = 'block';
    } else {
      c.style.display = 'none';
    }
  });
}

// SweetAlert Duplicate Checker for HOD
async function runBankDuplicateCheck(bankId) {
  showQpsLoader('Checking Duplicates', 'Scanning question pool across units and cognitive levels...');

  try {
    const res = await fetch(baseUrl + '/api/verify_bank.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bank_id: bankId, action: 'check_duplicates' })
    });
    const data = await res.json();
    hideQpsLoader();

    if (!data.success) throw new Error(data.message || 'Verification check failed.');

    if (data.duplicate_count === 0) {
      await Swal.fire({
        icon: 'success',
        title: 'Zero Duplicates Found',
        text: 'All questions in this question bank are verified clean and unique.',
        confirmButtonColor: '#1C1D21'
      });
    } else {
      let html = '<div style="text-align:left; max-height: 250px; overflow-y:auto;" class="space-y-2 text-xs">';
      data.duplicates.forEach(d => {
        html += `
          <div style="background:#FFFBEB; border:1px solid #FCD34D; border-radius:12px; padding:10px; margin-bottom:8px;">
            <div style="display:flex; justify-content:space-between; font-weight:bold; color:#78350F; margin-bottom:4px;">
              <span>Question #${d.q_number} (${d.section} • ${d.k_level})</span>
              <span>${d.matched_with}</span>
            </div>
            <div style="color:#1F2937;">${esc(d.question_text)}</div>
          </div>
        `;
      });
      html += '</div>';

      await Swal.fire({
        icon: 'warning',
        title: `${data.duplicate_count} Duplicate Question(s) Found`,
        html: html,
        confirmButtonColor: '#1C1D21',
        confirmButtonText: 'Review Questions'
      });
    }

  } catch (err) {
    hideQpsLoader();
    Swal.fire('Error', err.message || 'Failed to check duplicates.', 'error');
  }
}

// Submit to COE by HOD
async function submitBankToCoe(bankId) {
  const confirmResult = await Swal.fire({
    title: 'Forward to COE Office?',
    text: 'This question bank will be marked as Approved and submitted to COE for examination paper generation.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#1C1D21',
    cancelButtonColor: '#9CA3AF',
    confirmButtonText: 'Yes, Submit to COE'
  });

  if (!confirmResult.isConfirmed) return;

  showQpsLoader('Submitting to COE', 'Marking question bank as HOD approved...');

  try {
    const res = await fetch(baseUrl + '/api/verify_bank.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bank_id: bankId, action: 'submit_to_coe' })
    });
    const data = await res.json();
    hideQpsLoader();

    if (!data.success) throw new Error(data.message || 'Submission failed.');

    await Swal.fire({
      icon: 'success',
      title: 'Submitted to COE',
      text: data.message,
      confirmButtonColor: '#1C1D21'
    });

    location.reload();

  } catch (err) {
    hideQpsLoader();
    Swal.fire('Error', err.message || 'Failed to submit to COE.', 'error');
  }
}

// Approve Bank as COE
async function approveBankAsCOE(bankId) {
  const confirmResult = await Swal.fire({
    title: 'Approve Question Bank?',
    text: 'Mark Question Bank #' + bankId + ' as Approved & Verified for Paper Shuffling and Multi-Set Generation.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#059669',
    cancelButtonColor: '#9CA3AF',
    confirmButtonText: 'Yes, Approve Bank'
  });

  if (!confirmResult.isConfirmed) return;

  showQpsLoader('Approving Bank', 'Updating question bank verification status...');

  try {
    const res = await fetch(baseUrl + '/api/verify_bank.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bank_id: bankId, action: 'submit_to_coe' })
    });
    const data = await res.json();
    hideQpsLoader();

    if (!data.success) throw new Error(data.message || 'Approval failed.');

    await Swal.fire({
      icon: 'success',
      title: 'Question Bank Approved',
      text: 'Question bank has been verified and approved for examination paper generation.',
      confirmButtonColor: '#1C1D21'
    });

    location.reload();

  } catch (err) {
    hideQpsLoader();
    Swal.fire('Error', err.message || 'Failed to approve bank.', 'error');
  }
}


// SweetAlert Delete Question Bank
async function deleteBankPrompt(bankId, paperCode) {
  const result = await Swal.fire({
    title: `Delete Question Bank #${bankId}?`,
    text: `Are you sure you want to permanently delete the question bank for ${paperCode}? All associated questions and generated papers will be removed.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#E11D48',
    cancelButtonColor: '#6B7280',
    confirmButtonText: 'Yes, Delete Permanently'
  });

  if (!result.isConfirmed) return;

  showQpsLoader('Deleting Question Bank', 'Permanently removing question pool and dependencies...');

  try {
    const res = await fetch(baseUrl + '/api/delete_bank.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bank_id: bankId })
    });
    const data = await res.json();
    hideQpsLoader();

    if (!data.success) throw new Error(data.message || 'Failed to delete bank.');

    await Swal.fire({
      icon: 'success',
      title: 'Question Bank Deleted',
      text: data.message,
      confirmButtonColor: '#1C1D21'
    });

    location.href = baseUrl + '/modules/teaching/view_banks.php';

  } catch (err) {
    hideQpsLoader();
    Swal.fire('Delete Failed', err.message || 'Could not delete question bank.', 'error');
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
