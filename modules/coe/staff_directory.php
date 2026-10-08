<?php
/**
 * COE Staff Info Directory (Read-Only)
 * Holy Cross College (Autonomous) - Examination System
 * Allows COE Office to look up staff contact, allocated courses, and department details.
 */
define('PAGE_TITLE', 'Faculty & Staff Directory');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireCOE();
$pdo = getDBConnection();
$user = getCurrentUser();

$search = trim($_GET['q'] ?? '');
$filterDept = trim($_GET['dept'] ?? '');

// Load departments for filter dropdown
$departments = [];
try {
    $departments = $pdo->query("SELECT code, name FROM departments WHERE is_active = '1' OR is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Build query
$where = ["s.status = 'Y'"];
$params = [];

if ($search !== '') {
    $where[] = "(UPPER(s.STAFF_CODE) LIKE UPPER(?) OR UPPER(s.FIRST_NAME) LIKE UPPER(?) OR UPPER(s.MOBILE_NO) LIKE UPPER(?) OR UPPER(s.email) LIKE UPPER(?))";
    $term = "%{$search}%";
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
}

if ($filterDept !== '') {
    $where[] = "(UPPER(s.deptcode) = UPPER(?) OR UPPER(s.dept_code1) = UPPER(?) OR UPPER(s.DEPARTMENT) = UPPER(?))";
    $params[] = $filterDept; $params[] = $filterDept; $params[] = $filterDept;
}

$whereSql = implode(' AND ', $where);

// Count total
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM pr_x_xxxx_staf_prof_mast s WHERE {$whereSql}");
$cntStmt->execute($params);
$totalStaff = (int)$cntStmt->fetchColumn();

// Pagination
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($totalStaff / $perPage));

// Fetch staff list with grouped allocated courses
$sql = "
    SELECT 
        s.STAFF_CODE, 
        s.FIRST_NAME, 
        s.DEPARTMENT, 
        COALESCE(NULLIF(s.dept_code1,''), s.deptcode) as resolved_dept_code,
        s.designation, 
        s.MOBILE_NO, 
        s.email,
        s.hod_status,
        (SELECT GROUP_CONCAT(DISTINCT tf.papercode) FROM timetablefaculty tf WHERE UPPER(tf.fid) = UPPER(s.STAFF_CODE)) as allocated_courses
    FROM pr_x_xxxx_staf_prof_mast s
    WHERE {$whereSql}
    ORDER BY s.FIRST_NAME ASC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header Banner -->
  <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-[#121826] rounded-[28px] p-7 text-white shadow-xl border border-orange-500/20 relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-orange-500/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div class="inline-flex items-center gap-2 bg-orange-500/20 text-orange-300 border border-orange-500/30 text-xs px-3.5 py-1 rounded-full font-bold">
          <i data-lucide="users" class="w-3.5 h-3.5 text-orange-400"></i>
          <span>COE Office Directory • Read-Only View</span>
        </div>
        <h2 class="text-2xl sm:text-3xl font-black mt-2 tracking-tight">Faculty & Staff Info Directory</h2>
        <p class="text-slate-300 text-xs sm:text-sm mt-1 max-w-2xl leading-relaxed">
          Look up teaching staff details, staff codes, contact mobile numbers, and allocated examination courses for seamless COE examination coordination.
        </p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span class="bg-white/10 border border-white/20 text-white text-xs font-bold px-4 py-2 rounded-full">
          Total Faculty: <?php echo $totalStaff; ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Search & Filter Card -->
  <div class="card-modern p-5 space-y-4">
    <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
      
      <!-- Search Input -->
      <div class="lg:col-span-2">
        <label class="block font-bold text-slate-700 mb-1">Search Faculty Name, Staff Code, Phone</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
            <i data-lucide="search" class="w-4 h-4"></i>
          </span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, staff code (e.g. 38747), mobile..." class="pill-search-input w-full font-bold text-xs pl-9">
        </div>
      </div>

      <!-- Department Filter -->
      <div>
        <label class="block font-bold text-slate-700 mb-1">Filter by Department</label>
        <select name="dept" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold text-slate-800 shadow-sm focus:ring-2 focus:ring-orange-500">
          <option value="">All Academic Departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?php echo htmlspecialchars($d['code']); ?>" <?php echo $filterDept === $d['code'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($d['code'] . ' — ' . $d['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Action Buttons -->
      <div class="flex items-end space-x-2">
        <button type="submit" class="btn-orange-pill text-xs flex-1 py-2.5">
          <i data-lucide="filter" class="w-3.5 h-3.5"></i>
          <span>Filter</span>
        </button>
        <?php if ($search || $filterDept): ?>
          <a href="?" class="px-4 py-2.5 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition">
            Clear
          </a>
        <?php endif; ?>
      </div>

    </form>
  </div>

  <!-- Staff Directory Table -->
  <div class="card-modern p-5 space-y-4">
    <div class="overflow-x-auto custom-scrollbar-x rounded-2xl border border-slate-200">
      <table class="w-full text-left border-collapse text-xs">
        <thead class="bg-slate-950 text-white uppercase text-[10px] font-black tracking-wider">
          <tr>
            <th class="p-3">Staff Code</th>
            <th class="p-3">Faculty Name</th>
            <th class="p-3">Department (Deptcode)</th>
            <th class="p-3">Allocated Course(s)</th>
            <th class="p-3">Mobile Number</th>
            <th class="p-3">Designation / Role</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 font-medium bg-white">
          <?php if (empty($staffList)): ?>
            <tr>
              <td colspan="6" class="p-8 text-center text-slate-400 text-xs">
                No staff members found matching the specified search criteria.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($staffList as $s): ?>
              <tr class="hover:bg-slate-50 transition">
                <td class="p-3 font-mono font-black text-orange-950">
                  <span class="bg-orange-50 text-orange-900 border border-orange-200 px-2.5 py-1 rounded-full text-[11px]">
                    <?php echo htmlspecialchars($s['STAFF_CODE']); ?>
                  </span>
                </td>
                <td class="p-3">
                  <div class="font-bold text-slate-900 text-xs"><?php echo htmlspecialchars($s['FIRST_NAME']); ?></div>
                  <?php if (!empty($s['email'])): ?>
                    <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($s['email']); ?></div>
                  <?php endif; ?>
                </td>
                <td class="p-3">
                  <div class="font-bold text-slate-800"><?php echo htmlspecialchars($s['DEPARTMENT'] ?: 'Academic Dept'); ?></div>
                  <div class="text-[10px] text-slate-500 font-mono">Code: <?php echo htmlspecialchars($s['resolved_dept_code'] ?: '—'); ?></div>
                </td>
                <td class="p-3">
                  <?php if (!empty($s['allocated_courses'])): ?>
                    <div class="flex flex-wrap gap-1">
                      <?php foreach (explode(',', (string)$s['allocated_courses']) as $ac): ?>
                        <span class="bg-indigo-50 text-indigo-800 border border-indigo-200 text-[10px] font-mono font-bold px-2 py-0.5 rounded-full">
                          <?php echo htmlspecialchars(trim($ac)); ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-slate-400 italic text-[11px]">None assigned</span>
                  <?php endif; ?>
                </td>
                <td class="p-3 font-mono font-bold text-slate-700">
                  <?php if (!empty($s['MOBILE_NO'])): ?>
                    <span class="flex items-center space-x-1">
                      <i data-lucide="phone" class="w-3 h-3 text-slate-400"></i>
                      <span><?php echo htmlspecialchars($s['MOBILE_NO']); ?></span>
                    </span>
                  <?php else: ?>
                    <span class="text-slate-400 italic">—</span>
                  <?php endif; ?>
                </td>
                <td class="p-3">
                  <div class="text-slate-700 text-[11px]"><?php echo htmlspecialchars($s['designation'] ?: 'Faculty'); ?></div>
                  <?php if (strtoupper((string)($s['hod_status'] ?? '')) === 'Y'): ?>
                    <span class="bg-amber-100 text-amber-900 font-black text-[9px] px-2 py-0.5 rounded-full inline-block mt-0.5 border border-amber-300">
                      HOD
                    </span>
                  <?php endif; ?>
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
        <?php echo render_pagination($page, $totalPages, $totalStaff, $perPage, $_GET); ?>
      </div>
    <?php endif; ?>

  </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
