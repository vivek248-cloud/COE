<?php
/**
 * Modular Dynamic Role-Based Top Navigation Bar & Header
 * Holy Cross College (Autonomous) - Examination System
 * Shared operations-dashboard header and account actions
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/loader.php';

$user = getCurrentUser();
$baseUrl = getBaseUrl();
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$isCoeAdmin = isCOE();
$isSuperAdmin = isSuperAdmin();

?>

<!-- Shared Operations Dashboard Header -->
<header class="qps-top-header bg-gradient-to-r from-slate-950 via-slate-900 to-[#101726] text-white shadow-xl border-b border-orange-500/20 sticky top-0 z-40 backdrop-blur-md">
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
        <img src="https://hcctrichy.ac.in/img/logo.png" alt="Holy Cross College Logo" class="w-9 h-9 object-contain rounded-xl bg-white p-0.5 shadow-md shadow-orange-500/20 group-hover:scale-105 transition shrink-0">
        <div class="min-w-0">
          <div class="flex items-center space-x-2">
            <h1 class="text-xs sm:text-sm font-black tracking-wide text-white uppercase font-serif truncate max-w-[170px] sm:max-w-none"><?php echo COLLEGE_NAME; ?></h1>
            <span class="hidden md:inline-block bg-orange-500/20 text-orange-300 text-[9px] font-extrabold px-2 py-0.5 rounded-full border border-orange-400/30">NAAC A++ • OBE</span>
          </div>
          <p class="text-[10px] text-slate-300 hidden sm:block truncate">Exam Question Paper Bank & Blueprint System</p>
        </div>
      </a>
    </div>

    <!-- Right: User Role Pill & Logout -->
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

