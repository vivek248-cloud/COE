<?php
/**
 * COE Comprehensive Audit Trail & Activity Logs
 * Holy Cross College (Autonomous) - Examination Management System
 * Records Staff Code, Name, Mobile, Role, Action, Details, IP Address, Timestamp
 */
define('PAGE_TITLE', 'Audit Logs & Activity Monitor');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireCOE();
$pdo = getDBConnection();
qps_ensure_audit_schema($pdo);

// Filters
$filterStaff = trim((string)($_GET['staff'] ?? ''));
$filterRole = trim((string)($_GET['role'] ?? ''));
$filterAction = trim((string)($_GET['action_name'] ?? ''));
$filterSearch = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ["1=1"];
$params = [];

if ($filterStaff !== '') {
    $where[] = "(UPPER(a.staff_code) LIKE UPPER(?) OR UPPER(a.staff_name) LIKE UPPER(?))";
    $params[] = "%$filterStaff%";
    $params[] = "%$filterStaff%";
}

if ($filterRole !== '') {
    $where[] = "a.role = ?";
    $params[] = $filterRole;
}

if ($filterAction !== '') {
    $where[] = "a.action LIKE ?";
    $params[] = "%$filterAction%";
}

if ($filterSearch !== '') {
    $where[] = "(a.details LIKE ? OR a.entity_id LIKE ? OR a.ip_address LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

if ($dateFrom !== '') {
    $where[] = "a.created_at >= ?";
    $params[] = "$dateFrom 00:00:00";
}

if ($dateTo !== '') {
    $where[] = "a.created_at <= ?";
    $params[] = "$dateTo 23:59:59";
}

$whereClause = implode(" AND ", $where);

// Count
$stCnt = $pdo->prepare("SELECT COUNT(*) FROM qps_audit_log a WHERE $whereClause");
$stCnt->execute($params);
$totalLogs = (int)$stCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));

// Fetch Records
$stLogs = $pdo->prepare("SELECT a.*, 
    COALESCE(a.staff_name, s.FIRST_NAME, a.actor, 'SYSTEM') as display_name,
    COALESCE(a.staff_mobile, s.MOBILE_NO, '') as display_mobile
    FROM qps_audit_log a
    LEFT JOIN pr_x_xxxx_staf_prof_mast s ON UPPER(s.STAFF_CODE) = UPPER(a.staff_code)
    WHERE $whereClause
    ORDER BY a.id DESC
    LIMIT $perPage OFFSET $offset");
$stLogs->execute($params);
$logs = $stLogs->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct actions for dropdown
$distinctActions = [];
try {
    $stAct = $pdo->query("SELECT DISTINCT action FROM qps_audit_log ORDER BY action ASC");
    $distinctActions = $stAct->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header Banner -->
  <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl border border-slate-800">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-400/30 uppercase tracking-wider">
          <i data-lucide="shield-alert" class="w-3.5 h-3.5 text-indigo-400"></i> COE Audit & Compliance Ledger
        </div>
        <h1 class="text-2xl sm:text-3xl font-black mt-2 tracking-tight">System Audit Logs & Activity Monitor</h1>
        <p class="text-xs sm:text-sm text-slate-300 mt-1 max-w-2xl leading-relaxed">
          Comprehensive traceability of faculty submissions, HOD verifications, COE approvals, paper generation, downloads, and user sessions.
        </p>
      </div>
      <div class="flex items-center gap-3">
        <div class="bg-slate-800/80 px-4 py-3 rounded-2xl border border-slate-700/80 text-right">
          <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Total Logged Events</span>
          <span class="text-2xl font-black text-amber-400 font-mono"><?php echo number_format($totalLogs); ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Filter Card -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
      
      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Staff Code / Name</label>
        <input type="text" name="staff" value="<?php echo htmlspecialchars($filterStaff); ?>" placeholder="e.g. 23PH01 or John" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
      </div>

      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Role</label>
        <select name="role" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
          <option value="">All Roles</option>
          <option value="TEACHING_STAFF" <?php echo $filterRole === 'TEACHING_STAFF' ? 'selected' : ''; ?>>Teaching Staff</option>
          <option value="HOD" <?php echo $filterRole === 'HOD' ? 'selected' : ''; ?>>HOD</option>
          <option value="COE_ADMIN" <?php echo $filterRole === 'COE_ADMIN' ? 'selected' : ''; ?>>COE Admin</option>
          <option value="ERP_ADMIN" <?php echo $filterRole === 'ERP_ADMIN' ? 'selected' : ''; ?>>ERP Admin</option>
          <option value="SYSTEM" <?php echo $filterRole === 'SYSTEM' ? 'selected' : ''; ?>>System</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Action Type</label>
        <select name="action_name" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
          <option value="">All Actions</option>
          <?php foreach ($distinctActions as $act): ?>
            <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $filterAction === $act ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($act); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">From Date</label>
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
      </div>

      <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">To Date</label>
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
      </div>

      <div class="flex items-center gap-2">
        <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-md transition flex items-center justify-center gap-1.5">
          <i data-lucide="filter" class="w-3.5 h-3.5"></i> Apply
        </button>
        <a href="?" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs rounded-xl transition">
          Reset
        </a>
      </div>

    </form>
  </div>

  <!-- Logs Table -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs">
        <thead class="bg-slate-900 text-white font-bold uppercase tracking-wider text-[11px]">
          <tr>
            <th class="p-3.5">ID</th>
            <th class="p-3.5">Timestamp</th>
            <th class="p-3.5">Faculty / Staff</th>
            <th class="p-3.5">Mobile No</th>
            <th class="p-3.5">Role</th>
            <th class="p-3.5">Action Done</th>
            <th class="p-3.5">Entity / Details</th>
            <th class="p-3.5">IP Address</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 font-sans">
          <?php if (empty($logs)): ?>
            <tr>
              <td colspan="8" class="p-8 text-center text-slate-400">
                <i data-lucide="activity" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                <p class="font-bold">No audit records found matching criteria.</p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($logs as $log): 
              $roleBadgeColor = match($log['role']) {
                'COE_ADMIN' => 'bg-purple-100 text-purple-800 border-purple-200',
                'ERP_ADMIN' => 'bg-rose-100 text-rose-800 border-rose-200',
                'HOD' => 'bg-amber-100 text-amber-800 border-amber-200',
                'TEACHING_STAFF' => 'bg-blue-100 text-blue-800 border-blue-200',
                default => 'bg-slate-100 text-slate-700 border-slate-200'
              };
            ?>
              <tr class="hover:bg-slate-50/80 transition-colors">
                <td class="p-3.5 font-mono text-slate-400 font-bold">#<?php echo $log['id']; ?></td>
                <td class="p-3.5 font-mono text-slate-600 whitespace-nowrap">
                  <?php echo date('d M Y, h:i:s A', strtotime($log['created_at'])); ?>
                </td>
                <td class="p-3.5">
                  <div class="font-bold text-slate-900"><?php echo htmlspecialchars($log['display_name']); ?></div>
                  <div class="text-[10px] font-mono text-slate-400"><?php echo htmlspecialchars($log['staff_code'] ?: ($log['actor'] ?: '---')); ?></div>
                </td>
                <td class="p-3.5 font-mono font-semibold text-slate-700">
                  <?php echo htmlspecialchars($log['display_mobile'] ?: '---'); ?>
                </td>
                <td class="p-3.5">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $roleBadgeColor; ?>">
                    <?php echo htmlspecialchars($log['role'] ?: 'UNKNOWN'); ?>
                  </span>
                </td>
                <td class="p-3.5">
                  <span class="px-2.5 py-1 rounded-lg text-[11px] font-bold font-mono bg-slate-100 text-indigo-950 border border-slate-200">
                    <?php echo htmlspecialchars(qps_audit_label((string)$log['action'])); ?>
                  </span>
                </td>
                <td class="p-3.5 max-w-xs truncate text-slate-600" title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                  <?php if (!empty($log['entity_type'])): ?>
                    <span class="font-bold text-slate-800"><?php echo htmlspecialchars($log['entity_type']); ?></span>
                    <?php if (!empty($log['entity_id'])): ?><span class="font-mono text-slate-500">(#<?php echo htmlspecialchars($log['entity_id']); ?>)</span><?php endif; ?>
                  <?php endif; ?>
                  <div class="text-[11px] font-mono text-slate-500 truncate"><?php echo htmlspecialchars($log['details'] ?? ''); ?></div>
                </td>
                <td class="p-3.5 font-mono text-[11px] text-slate-500">
                  <?php echo htmlspecialchars($log['ip_address'] ?: '127.0.0.1'); ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="p-4 border-t border-slate-100">
        <?php echo render_pagination($page, $totalPages, $totalLogs, $perPage, $_GET); ?>
      </div>
    <?php endif; ?>
  </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
