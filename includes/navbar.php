<?php
/**
 * Modular Dynamic Role-Based Top Navigation Bar & Header
 * Holy Cross College (Autonomous) - Examination System
 * Soft Corners & Glassmorphism Design System
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/loader.php';

$user = getCurrentUser();
$baseUrl = getBaseUrl();
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$isCoeAdmin = isCOE();
$isSuperAdmin = isSuperAdmin();

// Quick switcher list
$quickStaffList = [];
try {
    $pdo = getDBConnection();
    if ($user && !$isCoeAdmin) {
        $userDept = $user['department'] ?? '';
        $userDeptCode = $user['dept_code'] ?? '';
        $stQuick = $pdo->prepare("SELECT s.STAFF_CODE, s.FIRST_NAME, s.DEPARTMENT, s.dept_code1, (SELECT COUNT(DISTINCT papercode) FROM timetablefaculty WHERE fid = s.STAFF_CODE) as paper_count 
                                  FROM pr_x_xxxx_staf_prof_mast s 
                                  WHERE s.status = 'Y' AND (UPPER(s.DEPARTMENT) = UPPER(?) OR UPPER(s.dept_code1) = UPPER(?) OR UPPER(s.deptcode) = UPPER(?))
                                  ORDER BY paper_count DESC, s.FIRST_NAME ASC LIMIT 8");
        $stQuick->execute([$userDept, $userDeptCode, $userDeptCode]);
        $quickStaffList = $stQuick->fetchAll(PDO::FETCH_ASSOC);

        if (count($quickStaffList) < 4) {
            $stMore = $pdo->prepare("SELECT s.STAFF_CODE, s.FIRST_NAME, s.DEPARTMENT, s.dept_code1, (SELECT COUNT(DISTINCT papercode) FROM timetablefaculty WHERE fid = s.STAFF_CODE) as paper_count 
                                     FROM pr_x_xxxx_staf_prof_mast s 
                                     WHERE s.status = 'Y' AND s.STAFF_CODE != ?
                                     ORDER BY paper_count DESC, s.FIRST_NAME ASC LIMIT 6");
            $stMore->execute([$user['staff_code']]);
            $more = $stMore->fetchAll(PDO::FETCH_ASSOC);
            $existingCodes = array_column($quickStaffList, 'STAFF_CODE');
            foreach ($more as $m) {
                if (!in_array($m['STAFF_CODE'], $existingCodes, true)) {
                    $quickStaffList[] = $m;
                }
            }
        }
    } else {
        $stQuick = $pdo->query("SELECT s.STAFF_CODE, s.FIRST_NAME, s.DEPARTMENT, s.dept_code1, (SELECT COUNT(DISTINCT papercode) FROM timetablefaculty WHERE fid = s.STAFF_CODE) as paper_count 
                                FROM pr_x_xxxx_staf_prof_mast s 
                                WHERE s.status = 'Y' 
                                ORDER BY paper_count DESC, s.FIRST_NAME ASC LIMIT 8");
        $quickStaffList = $stQuick->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$driverName = 'MySQL';
try {
    $driverName = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') ? 'SQLite' : 'MySQL (' . DB_NAME . ')';
} catch (Exception $e) {}
?>

<!-- Top Header Navigation Bar with Soft Glassmorphism -->
<header class="bg-gradient-to-r from-blue-950 via-indigo-950 to-slate-900 text-white shadow-lg border-b border-indigo-800/40 sticky top-0 z-40 backdrop-blur-md">
  <div class="px-4 sm:px-6 py-2.5 flex items-center justify-between gap-3 max-w-7xl mx-auto">
    
    <!-- Left: Sidebar Toggle & Institutional Brand -->
    <div class="flex items-center space-x-3">
      <?php if ($user): ?>
        <button type="button" onclick="toggleQpsSidebar()" class="p-2 rounded-2xl bg-white/10 hover:bg-white/20 text-indigo-200 hover:text-white transition focus:outline-none shadow-sm" title="Toggle Navigation Sidebar">
          <i data-lucide="menu" class="w-5 h-5"></i>
        </button>
      <?php endif; ?>

      <a href="<?php echo $isCoeAdmin ? $baseUrl . '/modules/coe/dashboard.php' : $baseUrl . '/modules/teaching/dashboard.php'; ?>" class="flex items-center space-x-2.5 group">
        <div class="w-9 h-9 bg-white/10 rounded-2xl border border-white/20 flex items-center justify-center p-1 shadow-inner backdrop-blur-sm group-hover:bg-white/20 transition shrink-0">
          <svg class="w-6 h-6 text-amber-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-0.5-5"/>
            <path d="M6 6h10"/>
            <path d="M6 10h10"/>
            <path d="M6 14h6"/>
            <circle cx="18" cy="18" r="2.5" fill="#f59e0b" stroke="#ffffff"/>
          </svg>
        </div>
        <div>
          <div class="flex items-center space-x-2">
            <h1 class="text-xs sm:text-sm font-black tracking-wide text-white uppercase font-serif truncate max-w-[200px] sm:max-w-none"><?php echo COLLEGE_NAME; ?></h1>
            <span class="hidden md:inline-block bg-amber-400/20 text-amber-300 text-[9px] font-extrabold px-2 py-0.5 rounded-full border border-amber-400/30">NAAC A++ • OBE</span>
          </div>
          <p class="text-[10px] text-indigo-200 hidden sm:block">Exam Question Paper Bank & Blueprint System • <span class="font-mono text-amber-300"><?php echo $driverName; ?></span></p>
        </div>
      </a>
    </div>

    <!-- Right: Elegant Live IST Clock, Quick Switcher, User Role Pill, Logout -->
    <div class="flex items-center space-x-2 sm:space-x-3">
      <?php if ($user): ?>
        
        <!-- Live IST Date & Time Badge -->
        <div class="hidden md:flex items-center space-x-2 bg-slate-950/80 border border-indigo-900/80 hover:border-amber-400/50 px-3 py-1.5 rounded-2xl text-xs font-mono text-slate-200 shadow-inner backdrop-blur-md transition group" title="Indian Standard Time (IST) - Asia/Kolkata">
          <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
          </span>
          <i data-lucide="clock" class="w-3.5 h-3.5 text-amber-400 group-hover:rotate-12 transition"></i>
          <span id="hcc-live-clock" class="font-extrabold tracking-tight text-amber-100"><?php echo date('d M Y, h:i:s A'); ?> IST</span>
        </div>

        <!-- User Role Badge Pill -->
        <div class="hidden sm:flex items-center space-x-2 bg-slate-900/90 border border-slate-700/80 px-3 py-1.5 rounded-2xl text-xs shadow-sm">
          <div class="w-2 h-2 rounded-full <?php echo $isCoeAdmin ? 'bg-amber-400' : 'bg-emerald-400'; ?> animate-pulse"></div>
          <div class="truncate max-w-[150px] md:max-w-[180px]">
            <div class="font-bold text-white text-[11px] truncate"><?php echo htmlspecialchars($user['name']); ?></div>
            <div class="text-slate-300 text-[9px] font-mono"><?php echo htmlspecialchars($user['staff_code']); ?></div>
          </div>
          <span class="<?php echo $isSuperAdmin ? 'bg-purple-600 text-white' : ($isCoeAdmin ? 'bg-amber-500 text-slate-950' : 'bg-indigo-600 text-white'); ?> text-[9px] px-2 py-0.5 rounded-full font-mono font-black uppercase">
            <?php echo $isSuperAdmin ? 'ERP ADMIN' : ($isCoeAdmin ? 'COE' : 'FACULTY'); ?>
          </span>
        </div>

        <!-- Quick Switcher Dropdown with Soft Corners -->
        <div class="relative">
          <button type="button" onclick="document.getElementById('qps-nav-user-dropdown').classList.toggle('hidden')" class="bg-indigo-900/80 hover:bg-indigo-800 text-indigo-100 border border-indigo-700/60 text-xs px-2.5 py-1.5 rounded-2xl font-medium transition flex items-center space-x-1 shadow">
            <i data-lucide="users" class="w-3.5 h-3.5"></i>
            <span class="hidden md:inline font-bold">Switch</span>
            <i data-lucide="chevron-down" class="w-3 h-3"></i>
          </button>
          <div id="qps-nav-user-dropdown" class="hidden absolute right-0 mt-2 w-72 bg-white rounded-2xl shadow-2xl border border-slate-200 py-2 z-50 text-slate-800 soft-card">
            <div class="px-3 py-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-100 flex items-center justify-between">
              <span>Quick Role Switcher</span>
              <i data-lucide="shuffle" class="w-3 h-3 text-indigo-500"></i>
            </div>
            <div class="max-h-60 overflow-y-auto custom-scrollbar-y p-1 space-y-0.5">
              
              <!-- ERP Administrator option -->
              <a href="<?php echo $baseUrl; ?>/modules/auth/login.php?login_as_erp=1" class="flex items-center justify-between px-2.5 py-2 rounded-xl text-xs hover:bg-purple-50 text-slate-800 font-bold transition">
                <div class="flex items-center space-x-2">
                  <div class="w-6 h-6 rounded-lg bg-purple-700 text-white flex items-center justify-center font-black text-[10px]">ERP</div>
                  <div>
                    <div class="text-xs font-bold text-slate-900">ERP System Administrator</div>
                    <div class="text-[10px] text-slate-500">Grand Admin & Master Tables Access</div>
                  </div>
                </div>
                <?php if ($isSuperAdmin): ?><i data-lucide="check" class="w-3.5 h-3.5 text-purple-600"></i><?php endif; ?>
              </a>

              <!-- COE Administrator option -->
              <a href="<?php echo $baseUrl; ?>/modules/auth/login.php?login_as_coe=1" class="flex items-center justify-between px-2.5 py-2 rounded-xl text-xs hover:bg-amber-50 text-slate-800 font-bold transition">
                <div class="flex items-center space-x-2">
                  <div class="w-6 h-6 rounded-lg bg-amber-500 text-slate-950 flex items-center justify-center font-black text-[10px]">COE</div>
                  <div>
                    <div class="text-xs font-bold text-slate-900">Controller of Examinations</div>
                    <div class="text-[10px] text-slate-500">COE Office Admin</div>
                  </div>
                </div>
                <?php if ($isCoeAdmin && !$isSuperAdmin): ?><i data-lucide="check" class="w-3.5 h-3.5 text-amber-600"></i><?php endif; ?>
              </a>

              <!-- Teaching Staff list -->
              <?php foreach ($quickStaffList as $qs): ?>
                <?php $isMe = ($user['staff_code'] === $qs['STAFF_CODE']); ?>
                <a href="<?php echo $baseUrl; ?>/modules/auth/login.php?quick_login=<?php echo urlencode($qs['STAFF_CODE']); ?>" class="flex items-center justify-between px-2.5 py-1.5 rounded-xl text-xs hover:bg-indigo-50 text-slate-800 transition <?php echo $isMe ? 'bg-indigo-50/80 font-bold text-indigo-900' : ''; ?>">
                  <div class="truncate mr-2">
                    <div class="font-medium text-slate-900 truncate"><?php echo htmlspecialchars($qs['FIRST_NAME']); ?></div>
                    <div class="text-[10px] text-slate-500 truncate"><?php echo htmlspecialchars($qs['STAFF_CODE']); ?> • <?php echo htmlspecialchars($qs['dept_code1'] ?: $qs['DEPARTMENT']); ?></div>
                  </div>
                  <?php if ($isMe): ?><i data-lucide="check" class="w-3.5 h-3.5 text-indigo-600 shrink-0"></i><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Logout Action -->
        <a href="<?php echo $baseUrl; ?>/modules/auth/logout.php" class="bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 p-2 rounded-2xl transition shadow-sm" title="Logout">
          <i data-lucide="log-out" class="w-4 h-4"></i>
        </a>

      <?php else: ?>
        <a href="<?php echo $baseUrl; ?>/modules/auth/login.php" class="bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold px-4 py-2 rounded-2xl text-xs shadow-md transition flex items-center space-x-1">
          <i data-lucide="log-in" class="w-3.5 h-3.5"></i>
          <span>Sign In</span>
        </a>
      <?php endif; ?>
    </div>

  </div>
</header>
