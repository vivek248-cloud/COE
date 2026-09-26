<?php
/**
 * COE Central Examination Portal Dashboard
 * Holy Cross College (Autonomous) - Examination Management System
 * Crextio Soft Corners & Dynamic Department Course Filter
 */
define('PAGE_TITLE', 'COE Examination Portal');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireCOE();
$user = getCurrentUser();
$pdo = getDBConnection();
qps_ensure_aux_schema($pdo);

// Summary metrics
$totalBanksCount = 0;
$pendingReviewCount = 0;
$approvedCount = 0;
$totalQuestionsCount = 0;

try {
    $totalBanksCount = (int)$pdo->query("SELECT COUNT(*) FROM question_banks")->fetchColumn();
    $pendingReviewCount = (int)$pdo->query("SELECT COUNT(*) FROM question_banks WHERE status = 'Submitted'")->fetchColumn();
    $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM question_banks WHERE status = 'Approved'")->fetchColumn();
    $totalQuestionsCount = (int)$pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
} catch (Exception $e) {}

// Load departments for filter
$departments = [];
try {
    $stmt = $pdo->query("SELECT * FROM departments ORDER BY name ASC");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Filters & Pagination
$deptFilter = $_GET['dept'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$courseFilter = $_GET['course'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$query = "SELECT qb.*, s.FIRST_NAME as staff_name FROM question_banks qb LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = qb.staff_code WHERE 1=1";
$cntQuery = "SELECT COUNT(*) FROM question_banks qb WHERE 1=1";
$params = [];

if (!empty($deptFilter)) {
    $query .= " AND qb.dept_code = ?";
    $cntQuery .= " AND qb.dept_code = ?";
    $params[] = $deptFilter;
}
if (!empty($statusFilter)) {
    $query .= " AND qb.status = ?";
    $cntQuery .= " AND qb.status = ?";
    $params[] = $statusFilter;
}
if (!empty($courseFilter)) {
    $query .= " AND qb.paper_code = ?";
    $cntQuery .= " AND qb.paper_code = ?";
    $params[] = $courseFilter;
}

$stmtCnt = $pdo->prepare($cntQuery);
$stmtCnt->execute($params);
$totalBanks = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalBanks / $perPage));

$query .= " ORDER BY qb.id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle COE review actions
if (isset($_GET['approve_id'])) {
    $approveId = intval($_GET['approve_id']);
    $now = date('Y-m-d H:i:s');
    try {
        $stmtApprove = $pdo->prepare("UPDATE question_banks SET status='Approved', review_comments='Approved by COE Office.', reviewed_by='COE_OFFICE', reviewed_at=?, locked_at=COALESCE(locked_at,?) WHERE id=?");
        $stmtApprove->execute([$now, $now, $approveId]);
    } catch (Exception $e) {
        $pdo->prepare("UPDATE question_banks SET status='Approved' WHERE id=?")->execute([$approveId]);
    }
    qps_audit($pdo, 'QUESTION_BANK_APPROVED', 'QUESTION_BANK', (string)$approveId);
    header("Location: " . getBaseUrl() . "/modules/coe/dashboard.php?msg=approved"); exit;
}
if (isset($_GET['reject_id'])) {
    $rejectId = intval($_GET['reject_id']);
    $now = date('Y-m-d H:i:s');
    try {
        $stmtReject = $pdo->prepare("UPDATE question_banks SET status='Rejected', review_comments='Returned by COE Office for correction.', reviewed_by='COE_OFFICE', reviewed_at=? WHERE id=?");
        $stmtReject->execute([$now, $rejectId]);
    } catch (Exception $e) {
        $pdo->prepare("UPDATE question_banks SET status='Rejected' WHERE id=?")->execute([$rejectId]);
    }
    qps_audit($pdo, 'QUESTION_BANK_REJECTED', 'QUESTION_BANK', (string)$rejectId);
    header("Location: " . getBaseUrl() . "/modules/coe/dashboard.php?msg=rejected"); exit;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- COE Banner with Warm Crextio Styling -->
  <div class="bg-gradient-to-r from-[#1C1D21] via-slate-900 to-amber-950 rounded-[28px] p-7 text-white shadow-xl relative overflow-hidden border border-stone-800">
    <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-amber-400/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div class="inline-flex items-center gap-2 bg-amber-400/25 text-amber-300 border border-amber-400/30 text-[11px] px-3.5 py-1 rounded-full font-extrabold uppercase tracking-wider">
          <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-300"></i> Controller of Examinations (COE) Office
        </div>
        <h2 class="text-xl sm:text-2xl font-extrabold mt-2.5 tracking-tight">COE Central Question Bank & Paper Generator</h2>
        <p class="text-stone-300 text-xs mt-1 max-w-2xl leading-relaxed">
          Review OBE Question Banks submitted across college departments, configure Bloom's taxonomy blueprints, and generate randomized Multi-Set examination papers (Set A, Set B, Set C) with zero errors.
        </p>
      </div>
      <div class="flex items-center gap-2.5 flex-wrap">
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/blueprint.php" class="bg-white/10 hover:bg-white/20 text-white text-xs font-bold px-4 py-2.5 rounded-full border border-white/25 transition flex items-center space-x-1.5 shadow-sm">
          <i data-lucide="table" class="w-4 h-4 text-amber-300"></i>
          <span>OBE Blueprint Matrix</span>
        </a>
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="bg-[#F6C443] hover:bg-[#EAB326] text-slate-950 font-black px-5 py-2.5 rounded-full shadow-lg flex items-center space-x-2 transition text-xs">
          <i data-lucide="shuffle" class="w-4 h-4"></i>
          <span>Launch Paper Shuffler</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Summary Stats Cards with Crextio Soft Styling -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-white border border-stone-200/80 rounded-[24px] p-5 shadow-sm space-y-1">
      <div class="flex items-center justify-between text-slate-400 text-xs font-bold uppercase">
        <span>Total Banks</span>
        <i data-lucide="folder" class="w-4 h-4 text-indigo-500"></i>
      </div>
      <div class="text-2xl font-extrabold text-slate-900"><?php echo $totalBanksCount; ?></div>
      <div class="text-[11px] text-slate-500 font-medium">Uploaded Question Banks</div>
    </div>

    <div class="bg-white border border-stone-200/80 rounded-[24px] p-5 shadow-sm space-y-1">
      <div class="flex items-center justify-between text-amber-700 text-xs font-bold uppercase">
        <span>Pending Review</span>
        <i data-lucide="clock" class="w-4 h-4 text-amber-500"></i>
      </div>
      <div class="text-2xl font-extrabold text-amber-600"><?php echo $pendingReviewCount; ?></div>
      <div class="text-[11px] text-slate-500 font-medium">Awaiting COE Approval</div>
    </div>

    <div class="bg-white border border-stone-200/80 rounded-[24px] p-5 shadow-sm space-y-1">
      <div class="flex items-center justify-between text-emerald-700 text-xs font-bold uppercase">
        <span>Approved</span>
        <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-500"></i>
      </div>
      <div class="text-2xl font-extrabold text-emerald-600"><?php echo $approvedCount; ?></div>
      <div class="text-[11px] text-slate-500 font-medium">Ready for Shuffling</div>
    </div>

    <div class="bg-white border border-stone-200/80 rounded-[24px] p-5 shadow-sm space-y-1">
      <div class="flex items-center justify-between text-blue-700 text-xs font-bold uppercase">
        <span>Question Pool</span>
        <i data-lucide="help-circle" class="w-4 h-4 text-blue-500"></i>
      </div>
      <div class="text-2xl font-extrabold text-indigo-950 font-mono"><?php echo $totalQuestionsCount; ?></div>
      <div class="text-[11px] text-slate-500 font-medium">Total Questions in DB</div>
    </div>
  </div>

  <!-- Filter & Submissions Card -->
  <div class="bg-white rounded-[28px] shadow-sm border border-stone-200/80 p-6 space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-stone-100 pb-4">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-full bg-amber-50 text-amber-800 flex items-center justify-center border border-amber-200 shadow-sm">
          <i data-lucide="inbox" class="w-5 h-5"></i>
        </div>
        <div>
          <h3 class="text-base sm:text-lg font-extrabold text-slate-900">Submitted Question Banks (<?php echo $totalBanks; ?>)</h3>
          <p class="text-xs text-slate-500">Filter by Department to narrow down to relevant course banks.</p>
        </div>
      </div>
      
      <!-- Filter Form with Dynamic Searchable Dropdowns -->
      <form method="GET" action="" class="flex flex-wrap items-center gap-2">
        <div class="w-48">
          <select name="dept" onchange="this.form.submit()" class="bg-stone-50 border border-stone-300 rounded-full text-xs px-3.5 py-2 font-bold shadow-sm">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?php echo htmlspecialchars($d['code']); ?>" <?php echo $deptFilter === $d['code'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($d['code'] . ' - ' . $d['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="w-44">
          <select name="status" onchange="this.form.submit()" class="bg-stone-50 border border-stone-300 rounded-full text-xs px-3.5 py-2 font-bold shadow-sm">
            <option value="">All Statuses</option>
            <option value="Submitted" <?php echo $statusFilter === 'Submitted' ? 'selected' : ''; ?>>Submitted (Pending)</option>
            <option value="Approved" <?php echo $statusFilter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="Draft" <?php echo $statusFilter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
            <option value="Rejected" <?php echo $statusFilter === 'Rejected' ? 'selected' : ''; ?>>Returned</option>
          </select>
        </div>
      </form>
    </div>

    <div class="overflow-x-auto custom-scrollbar-x text-xs">
      <table class="w-full text-left border-collapse min-w-[850px]">
        <thead class="bg-stone-50 text-slate-700 text-[11px] uppercase font-black border-y border-stone-200">
          <tr>
            <th class="py-3 px-4">Staff Member</th>
            <th class="py-3 px-4">Paper Code & Title</th>
            <th class="py-3 px-4">Dept / Sem</th>
            <th class="py-3 px-4">Total Qs / Marks</th>
            <th class="py-3 px-4 text-center">Status</th>
            <th class="py-3 px-4 text-right">COE Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 font-medium">
          <?php if (empty($banks)): ?>
            <tr>
              <td colspan="6" class="py-12 text-center text-slate-400 text-xs font-semibold">
                No question banks matching selected filter.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($banks as $b): ?>
              <tr class="hover:bg-stone-50/70 transition">
                <td class="py-3.5 px-4 font-bold text-slate-800">
                  <div><?php echo htmlspecialchars($b['staff_name'] ?? $b['staff_code']); ?></div>
                  <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($b['staff_code']); ?></div>
                </td>
                <td class="py-3.5 px-4 font-bold text-slate-900">
                  <div class="font-mono text-indigo-950 font-black"><?php echo htmlspecialchars($b['paper_code']); ?></div>
                  <div class="text-[11px] text-slate-600 font-normal"><?php echo htmlspecialchars($b['course_title']); ?></div>
                </td>
                <td class="py-3.5 px-4 text-slate-700"><?php echo htmlspecialchars($b['dept_name'] ?: $b['dept_code']); ?> • <span class="font-bold"><?php echo htmlspecialchars($b['semester']); ?></span></td>
                <td class="py-3.5 px-4 font-bold text-slate-800">
                  <span class="bg-stone-100 text-slate-900 border border-stone-200 px-2 py-0.5 rounded-full text-[11px] font-mono"><?php echo htmlspecialchars($b['total_questions'] ?: 30); ?> Qs</span>
                  <span class="text-amber-900 ml-1">• <?php echo htmlspecialchars($b['max_marks'] ?: 75); ?> M</span>
                </td>
                <td class="py-3.5 px-4 text-center">
                  <?php if ($b['status'] === 'Approved'): ?>
                    <span class="bg-emerald-100 text-emerald-900 border border-emerald-300 text-[10px] px-3 py-1 rounded-full font-black">Approved</span>
                  <?php elseif ($b['status'] === 'Submitted'): ?>
                    <span class="bg-amber-100 text-amber-900 border border-amber-300 text-[10px] px-3 py-1 rounded-full font-black">Pending COE</span>
                  <?php elseif ($b['status'] === 'Rejected'): ?>
                    <span class="bg-rose-100 text-rose-900 border border-rose-300 text-[10px] px-3 py-1 rounded-full font-black">Returned</span>
                  <?php else: ?>
                    <span class="bg-stone-100 text-slate-700 border border-stone-200 text-[10px] px-3 py-1 rounded-full font-bold">Draft</span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-4 text-right">
                  <div class="flex items-center justify-end space-x-1.5">
                    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/view_banks.php?view_id=<?php echo $b['id']; ?>#inspect-bank-section" class="bg-stone-100 hover:bg-stone-200 text-slate-800 border border-stone-200 text-xs font-bold px-3 py-1.5 rounded-full transition">Inspect</a>
                    <?php if ($b['status'] === 'Submitted'): ?>
                      <a href="<?php echo getBaseUrl(); ?>/modules/coe/dashboard.php?approve_id=<?php echo $b['id']; ?>" class="bg-[#1C1D21] hover:bg-slate-800 text-amber-300 text-xs font-black px-3.5 py-1.5 rounded-full shadow-sm transition">Approve</a>
                      <a href="<?php echo getBaseUrl(); ?>/modules/coe/dashboard.php?reject_id=<?php echo $b['id']; ?>" onclick="return confirm('Return this bank to the faculty?')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold px-3 py-1.5 rounded-full transition">Return</a>
                    <?php elseif ($b['status'] === 'Approved'): ?>
                      <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php?bank_id=<?php echo $b['id']; ?>" class="bg-[#F6C443] hover:bg-[#EAB326] text-slate-950 text-xs font-black px-3.5 py-1.5 rounded-full shadow-sm transition">Shuffle</a>
                    <?php endif; ?>
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

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
