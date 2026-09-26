<?php
/**
 * Modular Dynamic Role-Based Sidebar Navigation Component
 * Holy Cross College (Autonomous) - Examination Management System
 * Soft Corners & Glassmorphism Design
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';

$user = getCurrentUser();
$baseUrl = getBaseUrl();
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$isCoeAdmin = isCOE();
$isSuperAdmin = isSuperAdmin();

// Count badges
$badgeStats = [
    'pending_banks' => 0,
    'approved_banks' => 0,
    'my_assigned' => 0,
    'blueprints' => 0,
    'generated' => 0
];

try {
    $pdo = getDBConnection();
    if ($isCoeAdmin) {
        $badgeStats['pending_banks'] = (int)$pdo->query("SELECT COUNT(*) FROM question_banks WHERE status = 'Submitted'")->fetchColumn();
        $badgeStats['approved_banks'] = (int)$pdo->query("SELECT COUNT(*) FROM question_banks WHERE status = 'Approved'")->fetchColumn();
        $badgeStats['blueprints'] = (int)$pdo->query("SELECT COUNT(*) FROM blueprints")->fetchColumn();
        $badgeStats['generated'] = (int)$pdo->query("SELECT COUNT(*) FROM generated_papers")->fetchColumn();
    } elseif ($user) {
        $stA = $pdo->prepare("SELECT COUNT(DISTINCT papercode) FROM timetablefaculty WHERE fid = ?");
        $stA->execute([$user['staff_code']]);
        $badgeStats['my_assigned'] = (int)$stA->fetchColumn();

        $stM = $pdo->prepare("SELECT COUNT(*) FROM question_banks WHERE staff_code = ?");
        $stM->execute([$user['staff_code']]);
        $badgeStats['my_banks'] = (int)$stM->fetchColumn();
    }
} catch (Exception $e) {}

if (!$user) return;
?>

<!-- Slide-over Drawer Sidebar for Mobile & Desktop -->
<div id="qps-sidebar-backdrop" onclick="toggleQpsSidebar()" class="hidden fixed inset-0 bg-slate-950/60 backdrop-blur-sm z-40 transition-opacity duration-300"></div>

<aside id="qps-sidebar-drawer" class="fixed top-0 left-0 bottom-0 w-72 bg-slate-900 text-white z-50 transform -translate-x-full transition-transform duration-300 ease-in-out shadow-2xl flex flex-col justify-between border-r border-indigo-900/60">
  
  <!-- Sidebar Header -->
  <div class="p-4 border-b border-indigo-900/60 flex items-center justify-between bg-slate-950/80">
    <div class="flex items-center space-x-2.5">
      <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-amber-500 to-indigo-600 flex items-center justify-center text-white shadow-md">
        <i data-lucide="book-open" class="w-4 h-4"></i>
      </div>
      <div>
        <h3 class="font-extrabold text-xs text-white uppercase tracking-wider font-serif">HOLY CROSS (AUTONOMOUS)</h3>
        <span class="text-[10px] text-amber-300 font-mono">Exam Navigation System</span>
      </div>
    </div>
    <button type="button" onclick="toggleQpsSidebar()" class="p-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-indigo-200 hover:text-white transition" title="Close Sidebar">
      <i data-lucide="x" class="w-4 h-4"></i>
    </button>
  </div>

  <!-- Sidebar Nav Links (Role-Specific) -->
  <div class="flex-1 overflow-y-auto custom-scrollbar-y p-3.5 space-y-5 text-xs">
    
    <!-- User Quick Info Card -->
    <div class="bg-indigo-950/60 border border-indigo-800/60 rounded-2xl p-3 flex items-center space-x-3 shadow-inner">
      <div class="w-9 h-9 rounded-xl <?php echo $isCoeAdmin ? 'bg-amber-400 text-slate-950' : 'bg-indigo-600 text-white'; ?> font-bold text-xs flex items-center justify-center shadow">
        <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
      </div>
      <div class="truncate flex-1">
        <div class="font-bold text-white truncate text-xs"><?php echo htmlspecialchars($user['name']); ?></div>
        <div class="text-[10px] text-indigo-300 font-mono"><?php echo htmlspecialchars($user['staff_code']); ?></div>
      </div>
      <span class="text-[9px] font-mono font-black uppercase px-2 py-0.5 rounded-full <?php echo $isCoeAdmin ? 'bg-amber-400/20 text-amber-300 border border-amber-400/30' : 'bg-emerald-400/20 text-emerald-300 border border-emerald-400/30'; ?>">
        <?php echo $isSuperAdmin ? 'ADMIN' : ($isCoeAdmin ? 'COE' : 'STAFF'); ?>
      </span>
    </div>

    <?php if (!$isCoeAdmin): ?>
      <!-- ==================== TEACHING STAFF ONLY LINKS ==================== -->
      <div class="space-y-1">
        <div class="px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-indigo-400">Teaching Staff Portal</div>
        
        <a href="<?php echo $baseUrl; ?>/modules/teaching/dashboard.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'teaching/dashboard.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <div class="flex items-center space-x-2.5">
            <i data-lucide="layout-dashboard" class="w-4 h-4 text-indigo-400"></i>
            <span>Assigned Courses</span>
          </div>
          <?php if (!empty($badgeStats['my_assigned'])): ?>
            <span class="bg-indigo-900 text-indigo-200 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['my_assigned']; ?></span>
          <?php endif; ?>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/blueprint.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'blueprint.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <i data-lucide="layout-template" class="w-4 h-4 text-purple-400"></i>
          <span>Master Blueprint (275-Q Pool)</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/teaching/upload.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'teaching/upload.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <i data-lucide="upload-cloud" class="w-4 h-4 text-amber-400"></i>
          <span>Upload Question Bank Workspace</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/teaching/view_banks.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'teaching/view_banks.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <div class="flex items-center space-x-2.5">
            <i data-lucide="archive" class="w-4 h-4 text-emerald-400"></i>
            <span>My Question Banks</span>
          </div>
          <?php if (!empty($badgeStats['my_banks'])): ?>
            <span class="bg-emerald-900 text-emerald-200 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['my_banks']; ?></span>
          <?php endif; ?>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/teaching/download_template.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'teaching/download_template.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <i data-lucide="file-spreadsheet" class="w-4 h-4 text-sky-400"></i>
          <span>Download Word / CSV Templates</span>
        </a>
      </div>

    <?php else: ?>
      <!-- ==================== COE & ADMIN PORTAL LINKS (ALL ACCESS) ==================== -->
      
      <!-- Section 1: Examination & OBE Tools -->
      <div class="space-y-1">
        <div class="px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-amber-400">COE Examination Suite</div>

        <a href="<?php echo $baseUrl; ?>/modules/coe/dashboard.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'coe/dashboard.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <div class="flex items-center space-x-2.5">
            <i data-lucide="layout-dashboard" class="w-4 h-4 text-amber-400"></i>
            <span>COE Overview & Approvals</span>
          </div>
          <?php if ($badgeStats['pending_banks'] > 0): ?>
            <span class="bg-amber-500 text-slate-950 font-black text-[10px] px-2 py-0.5 rounded-full animate-pulse"><?php echo $badgeStats['pending_banks']; ?> Pending</span>
          <?php endif; ?>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/blueprint.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'coe/blueprint.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <div class="flex items-center space-x-2.5">
            <i data-lucide="table" class="w-4 h-4 text-indigo-400"></i>
            <span>OBE Question Blue Print</span>
          </div>
          <span class="bg-indigo-900 text-indigo-200 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['blueprints']; ?></span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/shuffle.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'coe/shuffle.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <div class="flex items-center space-x-2.5">
            <i data-lucide="shuffle" class="w-4 h-4 text-emerald-400"></i>
            <span>Paper Shuffler & Sets (A, B, C)</span>
          </div>
          <span class="bg-emerald-900 text-emerald-200 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['generated']; ?></span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/view_paper.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'coe/view_paper.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <i data-lucide="printer" class="w-4 h-4 text-sky-400"></i>
          <span>Official Paper Printing & DOCX</span>
        </a>
      </div>

      <!-- Section 2: Question Banks Management -->
      <div class="space-y-1 pt-2 border-t border-indigo-900/40">
        <div class="px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-indigo-400">Question Banks & Upload</div>

        <a href="<?php echo $baseUrl; ?>/modules/teaching/upload.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-xl font-bold transition <?php echo strpos($currentScript, 'teaching/upload.php') !== false ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <i data-lucide="file-plus" class="w-4 h-4 text-amber-300"></i>
          <span>Question Bank Upload / Grid</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=banks" class="flex items-center justify-between px-3 py-2.5 rounded-xl font-bold transition <?php echo (strpos($currentScript, 'admin') !== false && ($_GET['tab'] ?? '') === 'banks') ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
          <div class="flex items-center space-x-2.5">
            <i data-lucide="layers" class="w-4 h-4 text-purple-400"></i>
            <span>Master Question Pool</span>
          </div>
          <span class="bg-purple-900 text-purple-200 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['approved_banks']; ?></span>
        </a>
      </div>

      <!-- Section 3: ERP Master Repositories (Read-Only for COE, Manage for Super Admin) -->
      <div class="space-y-1 pt-2 border-t border-indigo-900/40">
        <div class="px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-slate-400 flex items-center justify-between">
          <span>ERP Master Repositories</span>
          <span class="text-[9px] bg-slate-800 px-1.5 py-0.5 rounded font-mono"><?php echo canEditERPMasters() ? 'FULL CRUD' : 'READ-ONLY'; ?></span>
        </div>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=staff" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl font-bold transition text-slate-300 hover:bg-slate-800 hover:text-white">
          <i data-lucide="users" class="w-4 h-4 text-indigo-400"></i>
          <span>Staff Master</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=departments" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl font-bold transition text-slate-300 hover:bg-slate-800 hover:text-white">
          <i data-lucide="building-2" class="w-4 h-4 text-indigo-400"></i>
          <span>Departments</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=courses" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl font-bold transition text-slate-300 hover:bg-slate-800 hover:text-white">
          <i data-lucide="book-marked" class="w-4 h-4 text-indigo-400"></i>
          <span>Courses Master (OBE / Non-OBE)</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=allocations" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl font-bold transition text-slate-300 hover:bg-slate-800 hover:text-white">
          <i data-lucide="calendar" class="w-4 h-4 text-indigo-400"></i>
          <span>Timetable Allocations</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=audit" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl font-bold transition text-slate-300 hover:bg-slate-800 hover:text-white">
          <i data-lucide="shield-alert" class="w-4 h-4 text-amber-400"></i>
          <span>Security Audit Logs</span>
        </a>
      </div>
    <?php endif; ?>

  </div>

  <!-- Sidebar Footer with Logout -->
  <div class="p-3.5 border-t border-indigo-900/60 bg-slate-950/80">
    <a href="<?php echo $baseUrl; ?>/modules/auth/logout.php" class="w-full flex items-center justify-center space-x-2 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 px-3 py-2.5 rounded-xl font-bold transition text-xs">
      <i data-lucide="log-out" class="w-4 h-4"></i>
      <span>Sign Out</span>
    </a>
  </div>

</aside>

<script>
function toggleQpsSidebar() {
  const drawer = document.getElementById('qps-sidebar-drawer');
  const backdrop = document.getElementById('qps-sidebar-backdrop');
  if (!drawer || !backdrop) return;
  
  if (drawer.classList.contains('-translate-x-full')) {
    drawer.classList.remove('-translate-x-full');
    backdrop.classList.remove('hidden');
  } else {
    drawer.classList.add('-translate-x-full');
    backdrop.classList.add('hidden');
  }
}
</script>
