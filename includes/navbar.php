<?php
/**
 * Modular Dynamic Role-Based Top Navigation Bar & Header
 * Holy Cross College (Autonomous) - Examination System
 * Soft Modern Glassmorphism & Interactive Calming Clock Section
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

<!-- Top Header Navigation Bar with Modern Soft Glassmorphism -->
<header class="bg-gradient-to-r from-slate-950 via-slate-900 to-[#101726] text-white shadow-xl border-b border-orange-500/20 sticky top-0 z-40 backdrop-blur-md">
  <div class="px-3 sm:px-6 py-2 flex items-center justify-between gap-3 max-w-full">
    
    <!-- Left: Desktop Rail Toggle / Mobile More Sheet Trigger & College Brand -->
    <div class="flex items-center space-x-2.5 sm:space-x-3 shrink-0">
      <?php if ($user): ?>
        <!-- Mobile Trigger (Opens Clean Slide-Up Sheet) -->
        <button type="button" onclick="toggleQpsMoreSheet()" class="lg:hidden p-2 rounded-2xl bg-white/10 hover:bg-white/20 text-orange-300 hover:text-white transition focus:outline-none shadow-sm" title="Menu">
          <i data-lucide="menu" class="w-5 h-5"></i>
        </button>

        <!-- Desktop Sidebar Rail Toggle -->
        <button type="button" onclick="toggleQpsDesktopSidebar()" id="qps-desktop-sidebar-btn" class="hidden lg:flex p-2 rounded-2xl bg-white/10 hover:bg-white/20 text-orange-300 hover:text-white transition focus:outline-none shadow-sm" title="Toggle Sidebar Width">
          <i data-lucide="panel-left-close" class="w-5 h-5" id="qps-desktop-sidebar-icon"></i>
        </button>
      <?php endif; ?>

      <a href="<?php echo $isCoeAdmin ? $baseUrl . '/modules/coe/dashboard.php' : $baseUrl . '/modules/teaching/dashboard.php'; ?>" class="flex items-center space-x-2.5 group">
        <div class="w-9 h-9 bg-gradient-to-tr from-amber-500 via-orange-500 to-rose-500 rounded-2xl border border-white/30 flex items-center justify-center p-1 shadow-lg shadow-orange-500/20 group-hover:scale-105 transition shrink-0">
          <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-0.5-5"/>
            <path d="M6 6h10"/>
            <path d="M6 10h10"/>
            <path d="M6 14h6"/>
            <circle cx="18" cy="18" r="2.5" fill="#ffffff" stroke="#ff5b00"/>
          </svg>
        </div>
        <div class="min-w-0">
          <div class="flex items-center space-x-2">
            <h1 class="text-xs sm:text-sm font-black tracking-wide text-white uppercase font-serif truncate max-w-[170px] sm:max-w-none"><?php echo COLLEGE_NAME; ?></h1>
            <span class="hidden md:inline-block bg-orange-500/20 text-orange-300 text-[9px] font-extrabold px-2 py-0.5 rounded-full border border-orange-400/30">NAAC A++ • OBE</span>
          </div>
          <p class="text-[10px] text-slate-300 hidden sm:block truncate">Exam Question Paper Bank & Blueprint System • <span class="font-mono text-orange-300 font-bold"><?php echo $driverName; ?></span></p>
        </div>
      </a>
    </div>

    <!-- Center: Interactive Calming Time & Date Section (Stress Relief for Staff) -->
    <?php if ($user): ?>
      <div class="relative hidden md:flex items-center">
        <div onclick="toggleCalmModePopover()" class="cursor-pointer bg-slate-900/90 hover:bg-slate-800/90 border border-slate-700/80 hover:border-orange-400/60 px-3.5 py-1.5 rounded-full text-xs font-mono text-slate-200 shadow-inner backdrop-blur-md transition group flex items-center space-x-2.5" title="Click to open Mindful Calm Time & Greeting">
          
          <!-- Calming Breathing Pulse Indicator -->
          <span class="relative flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
          </span>

          <i data-lucide="clock" class="w-3.5 h-3.5 text-orange-400 group-hover:rotate-12 transition"></i>
          <span id="hcc-live-clock" class="font-extrabold tracking-tight text-orange-100 font-mono"><?php echo date('d M Y, h:i:s A'); ?> IST</span>

          <!-- Calm Sparkle Tag -->
          <span class="bg-orange-500/20 text-orange-300 text-[9px] px-2 py-0.5 rounded-full font-sans font-bold flex items-center space-x-1 border border-orange-400/30">
            <i data-lucide="sparkles" class="w-2.5 h-2.5"></i>
            <span id="nav-calm-greeting">Keep Calm ✨</span>
          </span>
        </div>

        <!-- Stress-Relief Mindful Popover -->
        <div id="qps-calm-popover" class="hidden absolute top-full mt-2 left-1/2 -translate-x-1/2 w-72 bg-slate-950 text-white rounded-3xl p-4 shadow-2xl border border-orange-500/30 z-50 soft-card">
          <div class="flex items-center justify-between border-b border-slate-800 pb-2 mb-2">
            <span class="text-[10px] uppercase font-black text-orange-400 tracking-wider flex items-center space-x-1">
              <i data-lucide="heart" class="w-3 h-3 text-rose-400"></i>
              <span>Mindful Focus & Time</span>
            </span>
            <button type="button" onclick="toggleCalmModePopover()" class="text-slate-400 hover:text-white p-1 rounded-full"><i data-lucide="x" class="w-3.5 h-3.5"></i></button>
          </div>
          <p class="text-xs text-slate-200 font-medium leading-relaxed" id="mindful-quote">
            "Take a deep breath. You are shaping bright futures with each exam question!" 🌟
          </p>
          <div class="mt-3 pt-2 border-t border-slate-800/80 flex items-center justify-between text-[10px] text-slate-400 font-mono">
            <span>IST Timezone • Asia/Kolkata</span>
            <span class="text-emerald-400 font-bold">● System Active</span>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Right: User Role Pill, Switcher & Logout -->
    <div class="flex items-center space-x-2 sm:space-x-3 shrink-0">
      <?php if ($user): ?>
        
        <!-- User Role Badge Pill -->
        <div class="hidden sm:flex items-center space-x-2 bg-slate-900/90 border border-slate-700/80 px-3 py-1.5 rounded-full text-xs shadow-sm">
          <div class="w-2 h-2 rounded-full <?php echo $isCoeAdmin ? 'bg-orange-400' : 'bg-emerald-400'; ?> animate-pulse"></div>
          <div class="truncate max-w-[120px] md:max-w-[160px]">
            <div class="font-bold text-white text-[11px] truncate"><?php echo htmlspecialchars($user['name']); ?></div>
            <div class="text-slate-300 text-[9px] font-mono"><?php echo htmlspecialchars($user['staff_code']); ?></div>
          </div>
          <span class="<?php echo $isSuperAdmin ? 'bg-purple-600 text-white' : ($isCoeAdmin ? 'bg-gradient-to-r from-orange-500 to-amber-500 text-white font-black' : 'bg-indigo-600 text-white'); ?> text-[9px] px-2.5 py-0.5 rounded-full font-mono font-black uppercase">
            <?php echo $isSuperAdmin ? 'ERP ADMIN' : ($isCoeAdmin ? 'COE' : 'FACULTY'); ?>
          </span>
        </div>

        <!-- Quick Switcher Dropdown -->
        <div class="relative">
          <button type="button" onclick="document.getElementById('qps-nav-user-dropdown').classList.toggle('hidden')" class="bg-gradient-to-r from-slate-800 to-slate-900 hover:from-slate-700 hover:to-slate-800 text-slate-100 border border-slate-700/80 text-xs px-3 py-1.5 rounded-full font-bold transition flex items-center space-x-1.5 shadow">
            <i data-lucide="users" class="w-3.5 h-3.5 text-orange-400"></i>
            <span class="hidden md:inline font-bold">Switch</span>
            <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400"></i>
          </button>
          <div id="qps-nav-user-dropdown" class="hidden absolute right-0 mt-2 w-72 bg-white rounded-2xl shadow-2xl border border-slate-200 py-2 z-50 text-slate-800 soft-card">
            <div class="px-3 py-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-100 flex items-center justify-between">
              <span>Quick Role Switcher</span>
              <i data-lucide="shuffle" class="w-3 h-3 text-orange-500"></i>
            </div>
            <div class="max-h-60 overflow-y-auto custom-scrollbar-y p-1 space-y-0.5">
              
              <!-- ERP Administrator option -->
              <a href="<?php echo $baseUrl; ?>/modules/auth/login.php?quick_login=ERPAD2023M01" class="flex items-center justify-between px-2.5 py-2 rounded-xl text-xs hover:bg-purple-50 text-slate-800 font-bold transition">
                <div class="flex items-center space-x-2">
                  <div class="w-6 h-6 rounded-lg bg-purple-700 text-white flex items-center justify-center font-black text-[10px]">ERP</div>
                  <div>
                    <div class="text-xs font-bold text-slate-900">ERP System Administrator</div>
                    <div class="text-[10px] text-slate-500">Full ERP Masters & System Repos</div>
                  </div>
                </div>
                <?php if ($isSuperAdmin): ?><i data-lucide="check" class="w-3.5 h-3.5 text-purple-600"></i><?php endif; ?>
              </a>

              <!-- COE Administrator option -->
              <a href="<?php echo $baseUrl; ?>/modules/auth/login.php?login_as_coe=1" class="flex items-center justify-between px-2.5 py-2 rounded-xl text-xs hover:bg-orange-50 text-slate-800 font-bold transition">
                <div class="flex items-center space-x-2">
                  <div class="w-6 h-6 rounded-lg bg-orange-500 text-white flex items-center justify-center font-black text-[10px]">COE</div>
                  <div>
                    <div class="text-xs font-bold text-slate-900">Controller of Examinations</div>
                    <div class="text-[10px] text-slate-500">COE Office Admin</div>
                  </div>
                </div>
                <?php if ($isCoeAdmin && !$isSuperAdmin): ?><i data-lucide="check" class="w-3.5 h-3.5 text-orange-600"></i><?php endif; ?>
              </a>

              <!-- Teaching Staff list -->
              <?php foreach ($quickStaffList as $qs): ?>
                <?php $isMe = ($user['staff_code'] === $qs['STAFF_CODE']); ?>
                <a href="<?php echo $baseUrl; ?>/modules/auth/login.php?quick_login=<?php echo urlencode($qs['STAFF_CODE']); ?>" class="flex items-center justify-between px-2.5 py-1.5 rounded-xl text-xs hover:bg-orange-50 text-slate-800 transition <?php echo $isMe ? 'bg-orange-50/80 font-bold text-orange-950' : ''; ?>">
                  <div class="truncate mr-2">
                    <div class="font-medium text-slate-900 truncate"><?php echo htmlspecialchars($qs['FIRST_NAME']); ?></div>
                    <div class="text-[10px] text-slate-500 truncate"><?php echo htmlspecialchars($qs['STAFF_CODE']); ?> • <?php echo htmlspecialchars($qs['dept_code1'] ?: $qs['DEPARTMENT']); ?></div>
                  </div>
                  <?php if ($isMe): ?><i data-lucide="check" class="w-3.5 h-3.5 text-orange-600 shrink-0"></i><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Logout Action with Confirm -->
        <a href="<?php echo $baseUrl; ?>/modules/auth/logout.php" onclick="return confirmQpsLogout(event, this.href);" class="bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 p-2 rounded-full transition shadow-sm" title="Logout">
          <i data-lucide="log-out" class="w-4 h-4"></i>
        </a>

      <?php else: ?>
        <a href="<?php echo $baseUrl; ?>/modules/auth/login.php" class="btn-orange-pill text-xs shadow-md">
          <i data-lucide="log-in" class="w-3.5 h-3.5"></i>
          <span>Sign In</span>
        </a>
      <?php endif; ?>
    </div>

  </div>
</header>

<script>
function toggleCalmModePopover() {
  const p = document.getElementById('qps-calm-popover');
  if (p) p.classList.toggle('hidden');
}

// Gentle dynamic greetings based on time
function updateCalmGreeting() {
  const el = document.getElementById('nav-calm-greeting');
  if (!el) return;
  const hour = new Date().getHours();
  if (hour < 12) el.textContent = 'Good Morning ☀️';
  else if (hour < 17) el.textContent = 'Good Afternoon ☕';
  else if (hour < 21) el.textContent = 'Good Evening 🌆';
  else el.textContent = 'Peaceful Night 🌙';
}
document.addEventListener('DOMContentLoaded', updateCalmGreeting);
</script>
