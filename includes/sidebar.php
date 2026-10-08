<?php
/**
 * Modular Dynamic Role-Based Sidebar Navigation Component
 * Holy Cross College (Autonomous) - Examination Management System
 * Features:
 *  1. Desktop Collapsible Sidebar (w-72 <-> w-20 rail with localStorage persistence, lg:flex only)
 *  2. Mobile Floating Bottom Navigation Dock with Elevated Center Action Button (#FF5B00)
 *  3. Mobile "More" Slide-Up Bottom Sheet Drawer
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';

$user = getCurrentUser();
$baseUrl = getBaseUrl();
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$isCoeAdmin = isCOE();
$isSuperAdmin = isSuperAdmin();
$isHod = isHOD();

// Count badges
$badgeStats = [
    'pending_banks' => 0,
    'approved_banks' => 0,
    'my_assigned' => 0,
    'my_banks' => 0,
    'my_pending_batches' => 0,
    'blueprints' => 0,
    'generated' => 0,
    'total_staff' => 0
];

try {
    $pdo = getDBConnection();

    if ($isCoeAdmin) {
        try {
            $badgeStats['pending_banks'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM question_banks WHERE status IN ('Submitted','Submitted to COE')"
            )->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $badgeStats['pending_banks'] += (int)$pdo->query(
                "SELECT COUNT(*) FROM question_bank_drafts WHERE status = 'SUBMITTED_TO_HOD'"
            )->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $badgeStats['approved_banks'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM question_banks WHERE status = 'Approved'"
            )->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $badgeStats['blueprints'] = (int)$pdo->query("SELECT COUNT(*) FROM blueprints")->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $badgeStats['generated'] = (int)$pdo->query("SELECT COUNT(*) FROM generated_papers")->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $badgeStats['total_staff'] = (int)$pdo->query("SELECT COUNT(*) FROM pr_x_xxxx_staf_prof_mast WHERE status = 'Y'")->fetchColumn();
        } catch (Throwable $e) {}
    } elseif ($user) {
        try {
            $stA = $pdo->prepare("SELECT COUNT(DISTINCT papercode) FROM timetablefaculty WHERE fid = ?");
            $stA->execute([$user['staff_code']]);
            $badgeStats['my_assigned'] = (int)$stA->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $stM = $pdo->prepare("SELECT COUNT(*) FROM question_banks WHERE UPPER(staff_code) = UPPER(?)");
            $stM->execute([$user['staff_code']]);
            $badgeStats['my_banks'] = (int)$stM->fetchColumn();
        } catch (Throwable $e) {}

        try {
            if ($isHod) {
                $dept = trim((string)($user['dept_code'] ?? ''));
                if ($dept !== '') {
                    $stP = $pdo->prepare(
                        "SELECT COUNT(*) FROM question_bank_drafts
                         WHERE status = 'SUBMITTED_TO_HOD' AND UPPER(dept_code) = UPPER(?)"
                    );
                    $stP->execute([$dept]);
                } else {
                    $stP = $pdo->query(
                        "SELECT COUNT(*) FROM question_bank_drafts WHERE status = 'SUBMITTED_TO_HOD'"
                    );
                }
            } else {
                $stP = $pdo->prepare(
                    "SELECT COUNT(*) FROM question_bank_drafts
                     WHERE status = 'SUBMITTED_TO_HOD' AND UPPER(staff_code) = UPPER(?)"
                );
                $stP->execute([$user['staff_code']]);
            }
            $badgeStats['my_pending_batches'] = (int)$stP->fetchColumn();
        } catch (Throwable $e) {
            $badgeStats['my_pending_batches'] = 0;
        }
    }
} catch (Throwable $e) {}

if (!$user) return;
?>

<!-- ========================================================================= -->
<!-- 1. DESKTOP COLLAPSIBLE SIDEBAR (lg:flex ONLY - Never on Mobile)           -->
<!-- ========================================================================= -->
<aside id="qps-desktop-sidebar" class="hidden lg:flex flex-col fixed top-0 bottom-0 left-0 bg-slate-950 text-white z-40 transition-all duration-300 ease-in-out border-r border-slate-800 shadow-2xl overflow-hidden">
  
  <!-- Sidebar Header -->
  <div class="p-4 border-b border-slate-800/80 flex items-center justify-between bg-slate-950/90 h-16 shrink-0">
    <div class="flex items-center space-x-3 overflow-hidden">
      <div class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-amber-500 via-orange-500 to-rose-500 flex items-center justify-center text-white shadow-lg shadow-orange-500/20 shrink-0">
        <i data-lucide="book-open" class="w-4 h-4"></i>
      </div>
      <div class="sidebar-label truncate">
        <h3 class="font-black text-xs text-white uppercase tracking-wider font-serif truncate">HOLY CROSS (AUTO)</h3>
        <span class="text-[9px] text-orange-400 font-mono font-bold tracking-tight">Exam Navigation</span>
      </div>
    </div>
    <button type="button" onclick="toggleQpsDesktopSidebar()" class="sidebar-label p-1.5 rounded-xl bg-white/5 hover:bg-white/15 text-slate-400 hover:text-white transition shrink-0" title="Collapse Sidebar">
      <i data-lucide="chevrons-left" class="w-4 h-4"></i>
    </button>
  </div>

  <!-- Sidebar Nav Links -->
  <div class="flex-1 overflow-y-auto custom-scrollbar-y p-3 space-y-4 text-xs">
    
    <!-- User Quick Info Card -->
    <div class="sidebar-full-info bg-slate-900/90 border border-slate-800 rounded-2xl p-3 flex items-center space-x-3 shadow-inner">
      <div class="w-8 h-8 rounded-xl <?php echo $isCoeAdmin ? 'bg-gradient-to-tr from-amber-500 to-orange-500 text-white' : 'bg-gradient-to-tr from-indigo-500 to-blue-600 text-white'; ?> font-black text-xs flex items-center justify-center shadow shrink-0">
        <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
      </div>
      <div class="truncate flex-1">
        <div class="font-bold text-white truncate text-xs"><?php echo htmlspecialchars($user['name']); ?></div>
        <div class="text-[10px] text-orange-300 font-mono"><?php echo htmlspecialchars($user['staff_code']); ?></div>
      </div>
    </div>

    <?php if (!$isCoeAdmin): ?>
      <!-- TEACHING STAFF LINKS -->
      <div class="space-y-1">
        <div class="sidebar-section-title px-2 py-1 text-[9px] font-black uppercase tracking-wider text-orange-400">Teaching Staff Portal</div>
        
        <!-- Assigned Courses -->
        <a href="<?php echo $baseUrl; ?>/modules/teaching/dashboard.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'teaching/dashboard.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Assigned Courses Dashboard">
          <div class="flex items-center space-x-3">
            <i data-lucide="layout-dashboard" class="w-4 h-4 text-orange-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label">Assigned Courses</span>
          </div>
          <?php if (!empty($badgeStats['my_assigned'])): ?>
            <span class="sidebar-badge-text bg-slate-800 text-orange-300 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['my_assigned']; ?></span>
            <span class="sidebar-badge-dot w-2 h-2 rounded-full bg-orange-400"></span>
          <?php endif; ?>
        </a>

        <!-- Course Question Bank Blueprint -->
        <a href="<?php echo $baseUrl; ?>/modules/teaching/question_bank_blueprint.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'question_bank_blueprint.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Course Question Bank Blueprint">
          <div class="flex items-center space-x-3">
            <i data-lucide="layout-template" class="w-4 h-4 text-purple-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label">Course Blueprint</span>
          </div>
          <?php if ($isHod): ?><span class="sidebar-badge-text bg-amber-400/20 text-amber-300 border border-amber-400/30 text-[9px] px-2 py-0.5 rounded-full font-black">HOD</span><?php endif; ?>
        </a>

        <!-- Upload Question Bank Workspace -->
        <a href="<?php echo $baseUrl; ?>/modules/teaching/upload.php" class="sidebar-link flex items-center space-x-3 px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'teaching/upload.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Upload Question Bank Workspace">
          <i data-lucide="upload-cloud" class="w-4 h-4 text-orange-500 group-hover:scale-110 transition shrink-0"></i>
          <span class="sidebar-label">Upload Workspace</span>
        </a>

        <!-- My Question Banks -->
        <a href="<?php echo $baseUrl; ?>/modules/teaching/view_banks.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'teaching/view_banks.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="My Question Banks">
          <div class="flex items-center space-x-3 min-w-0">
            <i data-lucide="archive" class="w-4 h-4 text-emerald-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label truncate">Question Banks</span>
          </div>
          <div class="sidebar-badge-text flex items-center gap-1.5 ml-2 shrink-0">
            <?php if (!empty($badgeStats['my_banks'])): ?>
              <span class="bg-emerald-950 text-emerald-300 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono border border-emerald-800/40"><?php echo $badgeStats['my_banks']; ?></span>
            <?php endif; ?>
            <?php if (!empty($badgeStats['my_pending_batches'])): ?>
              <span class="bg-orange-500 text-white text-[10px] font-black min-w-[20px] h-[20px] inline-flex items-center justify-center rounded-full shadow-sm animate-pulse">
                <?php echo $badgeStats['my_pending_batches']; ?>
              </span>
            <?php endif; ?>
          </div>
          <?php if (!empty($badgeStats['my_pending_batches'])): ?>
            <span class="sidebar-badge-dot w-2 h-2 rounded-full bg-orange-400"></span>
          <?php endif; ?>
        </a>

        <!-- Download Templates -->
        <a href="<?php echo $baseUrl; ?>/modules/teaching/download_template.php" class="sidebar-link flex items-center space-x-3 px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'teaching/download_template.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Download Import Templates">
          <i data-lucide="file-spreadsheet" class="w-4 h-4 text-sky-400 group-hover:scale-110 transition shrink-0"></i>
          <span class="sidebar-label">Download Templates</span>
        </a>
      </div>

    <?php else: ?>
      <!-- COE EXAMINATION SUITE -->
      <div class="space-y-1">
        <div class="sidebar-section-title px-2 py-1 text-[9px] font-black uppercase tracking-wider text-orange-400">COE Examination Suite</div>

        <a href="<?php echo $baseUrl; ?>/modules/coe/dashboard.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'coe/dashboard.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="COE Overview & Approvals">
          <div class="flex items-center space-x-3">
            <i data-lucide="layout-dashboard" class="w-4 h-4 text-orange-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label">COE Overview</span>
          </div>
          <?php if ($badgeStats['pending_banks'] > 0): ?>
            <span class="sidebar-badge-text bg-orange-500 text-white font-black text-[10px] px-2 py-0.5 rounded-full animate-pulse"><?php echo $badgeStats['pending_banks']; ?></span>
            <span class="sidebar-badge-dot w-2 h-2 rounded-full bg-orange-400"></span>
          <?php endif; ?>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/blueprints.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'coe/blueprints.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Blueprint Manager (CRUD)">
          <div class="flex items-center space-x-3">
            <i data-lucide="layers" class="w-4 h-4 text-cyan-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label">Blueprint Manager</span>
          </div>
          <span class="sidebar-badge-text bg-slate-800 text-cyan-300 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['blueprints']; ?></span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/blueprint.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'coe/blueprint.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="30-Question Paper Blueprint Matrix (TSS.PDF)">
          <div class="flex items-center space-x-3">
            <i data-lucide="table" class="w-4 h-4 text-indigo-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label">30-Q Matrix Matrix</span>
          </div>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/shuffle.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'coe/shuffle.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Paper Shuffler & Sets (A, B, C)">
          <div class="flex items-center space-x-3">
            <i data-lucide="shuffle" class="w-4 h-4 text-emerald-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label">Paper Shuffler & Sets</span>
          </div>
          <span class="sidebar-badge-text bg-slate-800 text-emerald-300 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['generated']; ?></span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/view_paper.php" class="sidebar-link flex items-center space-x-3 px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'coe/view_paper.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Official Paper Printing & DOCX">
          <i data-lucide="printer" class="w-4 h-4 text-sky-400 group-hover:scale-110 transition shrink-0"></i>
          <span class="sidebar-label">Official Paper Print</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/coe/file_manager.php" class="sidebar-link flex items-center space-x-3 px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'coe/file_manager.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="COE File Manager">
          <i data-lucide="folder-open" class="w-4 h-4 text-amber-300 group-hover:scale-110 transition shrink-0"></i>
          <span class="sidebar-label">File Manager</span>
        </a>

        <!-- Staff Info Directory (Read-Only for COE) -->
        <a href="<?php echo $baseUrl; ?>/modules/coe/staff_directory.php" class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'staff_directory.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Faculty & Staff Directory (Read-Only)">
          <div class="flex items-center space-x-3">
            <i data-lucide="users" class="w-4 h-4 text-orange-400 group-hover:scale-110 transition shrink-0"></i>
            <span class="sidebar-label">Staff Directory</span>
          </div>
          <?php if (!empty($badgeStats['total_staff'])): ?>
            <span class="sidebar-badge-text bg-slate-800 text-orange-300 text-[10px] font-bold px-2 py-0.5 rounded-full font-mono"><?php echo $badgeStats['total_staff']; ?></span>
          <?php endif; ?>
        </a>

        <?php if ($isCoeAdmin && !$isSuperAdmin): ?>
        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=settings" class="sidebar-link flex items-center space-x-3 px-3 py-2.5 rounded-2xl font-bold transition group <?php echo (strpos($currentScript, 'admin/index.php') !== false && ($_GET['tab'] ?? '') === 'settings') ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="COE Settings">
          <i data-lucide="settings" class="w-4 h-4 text-amber-300 group-hover:scale-110 transition shrink-0"></i>
          <span class="sidebar-label">COE Settings</span>
        </a>
        <?php endif; ?>
      </div>

      <!-- Question Banks Section -->
      <div class="space-y-1 pt-2 border-t border-slate-800/80">
        <div class="sidebar-section-title px-2 py-1 text-[9px] font-black uppercase tracking-wider text-slate-400">Question Repository</div>

        <a href="<?php echo $baseUrl; ?>/modules/teaching/upload.php" class="sidebar-link flex items-center space-x-3 px-3 py-2.5 rounded-2xl font-bold transition group <?php echo strpos($currentScript, 'teaching/upload.php') !== false ? 'bg-gradient-to-r from-orange-500/20 to-amber-500/10 text-orange-300 border border-orange-500/30' : 'text-slate-300 hover:bg-slate-900 hover:text-white'; ?>" title="Upload Question Bank Workspace">
          <i data-lucide="upload-cloud" class="w-4 h-4 text-orange-400 group-hover:scale-110 transition shrink-0"></i>
          <span class="sidebar-label">Upload Workspace</span>
        </a>
      </div>

      <?php if ($isSuperAdmin): ?>
      <!-- ERP Master Repositories (Visible ONLY in ERP Staff / Super Admin Login) -->
      <div class="space-y-1 pt-2 border-t border-slate-800/80">
        <div class="sidebar-section-title px-2 py-1 text-[9px] font-black uppercase tracking-wider text-purple-400 flex items-center justify-between">
          <span class="sidebar-label">ERP Masters (Full CRUD)</span>
          <span class="sidebar-label text-[8px] bg-purple-900/60 text-purple-200 px-1.5 py-0.5 rounded font-mono">ADMIN</span>
        </div>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=staff" class="sidebar-link flex items-center space-x-3 px-3 py-2 rounded-2xl font-bold transition text-slate-300 hover:bg-slate-900 hover:text-white" title="Staff Master">
          <i data-lucide="users" class="w-4 h-4 text-purple-400 shrink-0"></i>
          <span class="sidebar-label">Staff Master</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=departments" class="sidebar-link flex items-center space-x-3 px-3 py-2 rounded-2xl font-bold transition text-slate-300 hover:bg-slate-900 hover:text-white" title="Departments Master">
          <i data-lucide="building-2" class="w-4 h-4 text-purple-400 shrink-0"></i>
          <span class="sidebar-label">Departments</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=courses" class="sidebar-link flex items-center space-x-3 px-3 py-2 rounded-2xl font-bold transition text-slate-300 hover:bg-slate-900 hover:text-white" title="Courses Master">
          <i data-lucide="book-marked" class="w-4 h-4 text-purple-400 shrink-0"></i>
          <span class="sidebar-label">Courses Master</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=allocations" class="sidebar-link flex items-center space-x-3 px-3 py-2 rounded-2xl font-bold transition text-slate-300 hover:bg-slate-900 hover:text-white" title="Timetable Allocations">
          <i data-lucide="calendar" class="w-4 h-4 text-purple-400 shrink-0"></i>
          <span class="sidebar-label">Allocations</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/modules/admin/index.php?tab=audit" class="sidebar-link flex items-center space-x-3 px-3 py-2 rounded-2xl font-bold transition text-slate-300 hover:text-white hover:bg-slate-900" title="Security Audit Logs">
          <i data-lucide="shield-alert" class="w-4 h-4 text-amber-400 shrink-0"></i>
          <span class="sidebar-label">Audit Logs</span>
        </a>
      </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>

  <!-- Sidebar Footer with Logout & Toggle -->
  <div class="p-3 border-t border-slate-800/80 bg-slate-950/90 shrink-0">
    <a href="<?php echo $baseUrl; ?>/modules/auth/logout.php" onclick="return confirmQpsLogout(event, this.href);" class="sidebar-link w-full flex items-center justify-center space-x-2 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 px-3 py-2.5 rounded-full font-bold transition text-xs" title="Sign Out">
      <i data-lucide="log-out" class="w-4 h-4 shrink-0"></i>
      <span class="sidebar-label">Sign Out</span>
    </a>
  </div>

</aside>


<!-- ========================================================================= -->
<!-- 2. MOBILE FLOATING BOTTOM NAVIGATION DOCK (lg:hidden)                     -->
<!-- ========================================================================= -->
<?php
$navHomeUrl = $isCoeAdmin ? "$baseUrl/modules/coe/dashboard.php" : "$baseUrl/modules/teaching/dashboard.php";
$navBpUrl = $isCoeAdmin ? "$baseUrl/modules/coe/blueprints.php" : "$baseUrl/modules/teaching/question_bank_blueprint.php";
$navFabUrl = $isCoeAdmin ? "$baseUrl/modules/coe/shuffle.php" : "$baseUrl/modules/teaching/upload.php";
$navFabIcon = $isCoeAdmin ? "shuffle" : "upload-cloud";
$navFabTitle = $isCoeAdmin ? "Generate Paper" : "Upload Bank";
$navBankUrl = $isCoeAdmin ? "$baseUrl/modules/coe/file_manager.php" : "$baseUrl/modules/teaching/view_banks.php";

$isHomeActive = strpos($currentScript, 'dashboard.php') !== false;
$isBpActive = strpos($currentScript, 'blueprint') !== false;
$isFabActive = strpos($currentScript, 'upload.php') !== false || strpos($currentScript, 'shuffle.php') !== false;
$isBankActive = strpos($currentScript, 'view_banks.php') !== false || strpos($currentScript, 'file_manager.php') !== false;
?>

<nav id="qps-mobile-bottom-nav" class="lg:hidden fixed bottom-3 left-1/2 -translate-x-1/2 z-40 w-[92%] max-w-sm mobile-bottom-dock rounded-full px-2 py-1.5 flex items-center justify-between no-print shadow-2xl">
  
  <!-- 1. Home / Dashboard -->
  <a href="<?php echo $navHomeUrl; ?>" class="flex flex-col items-center justify-center flex-1 py-1 transition group <?php echo $isHomeActive ? 'text-orange-400 font-bold' : 'text-slate-400 hover:text-white'; ?>">
    <i data-lucide="layout-dashboard" class="w-5 h-5 transition group-active:scale-90 <?php echo $isHomeActive ? 'text-orange-400' : 'text-slate-400'; ?>"></i>
    <span class="text-[9px] mt-0.5 tracking-tight">Home</span>
  </a>

  <!-- 2. Blueprint -->
  <a href="<?php echo $navBpUrl; ?>" class="flex flex-col items-center justify-center flex-1 py-1 transition group <?php echo $isBpActive ? 'text-orange-400 font-bold' : 'text-slate-400 hover:text-white'; ?>">
    <i data-lucide="layers" class="w-5 h-5 transition group-active:scale-90 <?php echo $isBpActive ? 'text-orange-400' : 'text-slate-400'; ?>"></i>
    <span class="text-[9px] mt-0.5 tracking-tight">Blueprint</span>
  </a>

  <!-- 3. ELEVATED CENTER ACTION BUTTON (#FF5B00 Vibrant Pill) -->
  <div class="flex flex-col items-center justify-center flex-1 -mt-5">
    <a href="<?php echo $navFabUrl; ?>" class="mobile-center-fab w-12 h-12 rounded-full border-4 border-slate-950 flex items-center justify-center text-white shadow-xl shadow-orange-500/50" title="<?php echo $navFabTitle; ?>">
      <i data-lucide="<?php echo $navFabIcon; ?>" class="w-5 h-5"></i>
    </a>
    <span class="text-[9px] font-black text-orange-400 -mt-3.5 tracking-tight truncate max-w-[60px]"><?php echo $isCoeAdmin ? 'Generate' : 'Upload'; ?></span>
  </div>

  <!-- 4. Library / Banks -->
  <a href="<?php echo $navBankUrl; ?>" class="flex flex-col items-center justify-center flex-1 py-1 transition group <?php echo $isBankActive ? 'text-orange-400 font-bold' : 'text-slate-400 hover:text-white'; ?>">
    <i data-lucide="archive" class="w-5 h-5 transition group-active:scale-90 <?php echo $isBankActive ? 'text-orange-400' : 'text-slate-400'; ?>"></i>
    <span class="text-[9px] mt-0.5 tracking-tight">Banks</span>
  </a>

  <!-- 5. More (Opens Slide-up Sheet) -->
  <button type="button" onclick="toggleQpsMoreSheet()" class="flex flex-col items-center justify-center flex-1 py-1 text-slate-400 hover:text-white transition group focus:outline-none">
    <i data-lucide="grid" class="w-5 h-5 transition group-active:scale-90 text-slate-400"></i>
    <span class="text-[9px] mt-0.5 tracking-tight">More</span>
  </button>

</nav>


<!-- ========================================================================= -->
<!-- 3. MOBILE "MORE" SLIDE-UP BOTTOM SHEET (lg:hidden)                        -->
<!-- ========================================================================= -->
<div id="qps-mobile-more-backdrop" onclick="toggleQpsMoreSheet()" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 transition-opacity duration-300"></div>

<div id="qps-mobile-more-sheet" class="lg:hidden fixed bottom-0 left-0 right-0 bg-slate-950 text-white z-50 rounded-t-[32px] border-t border-slate-800 shadow-2xl transform translate-y-full transition-transform duration-300 ease-in-out max-h-[85vh] flex flex-col">
  
  <!-- Sheet Handle -->
  <div class="pt-3 pb-2 flex flex-col items-center shrink-0 cursor-pointer" onclick="toggleQpsMoreSheet()">
    <div class="w-12 h-1.5 bg-slate-700 rounded-full"></div>
    <div class="flex items-center justify-between w-full px-6 mt-2">
      <h3 class="font-black text-sm text-white">Menu & Quick Actions</h3>
      <button type="button" onclick="toggleQpsMoreSheet()" class="text-slate-400 hover:text-white p-1 rounded-full bg-slate-900">
        <i data-lucide="x" class="w-4 h-4"></i>
      </button>
    </div>
  </div>

  <!-- Sheet Scrollable Body -->
  <div class="flex-1 overflow-y-auto custom-scrollbar-y px-5 py-3 space-y-4 text-xs">
    
    <!-- User Profile Strip -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-3 flex items-center justify-between shadow-inner">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-2xl <?php echo $isCoeAdmin ? 'bg-gradient-to-tr from-amber-500 to-orange-500 text-white' : 'bg-gradient-to-tr from-indigo-500 to-blue-600 text-white'; ?> font-black text-sm flex items-center justify-center shadow">
          <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
        </div>
        <div>
          <div class="font-bold text-white text-xs"><?php echo htmlspecialchars($user['name']); ?></div>
          <div class="text-[10px] text-orange-400 font-mono"><?php echo htmlspecialchars($user['staff_code']); ?></div>
          <div class="text-[9px] text-slate-400"><?php echo htmlspecialchars($user['department'] ?? 'Department Faculty'); ?></div>
        </div>
      </div>
      <span class="text-[9px] font-mono font-black uppercase px-2.5 py-1 rounded-full <?php echo $isCoeAdmin ? 'bg-orange-500/20 text-orange-300 border border-orange-500/30' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'; ?>">
        <?php echo $isSuperAdmin ? 'ERP ADMIN' : ($isCoeAdmin ? 'COE' : 'FACULTY'); ?>
      </span>
    </div>

    <!-- Quick Action Grid -->
    <div class="grid grid-cols-2 gap-2.5">
      <a href="<?php echo $baseUrl; ?>/modules/teaching/download_template.php" class="bg-slate-900 border border-slate-800 hover:border-slate-700 p-3 rounded-2xl flex items-center space-x-2.5 transition">
        <div class="w-8 h-8 rounded-xl bg-sky-500/15 text-sky-400 flex items-center justify-center shrink-0">
          <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <div class="font-bold text-white text-xs truncate">Templates</div>
          <div class="text-[9px] text-slate-400">Word / Excel / CSV</div>
        </div>
      </a>

      <?php if ($isCoeAdmin): ?>
      <a href="<?php echo $baseUrl; ?>/modules/coe/view_paper.php" class="bg-slate-900 border border-slate-800 hover:border-slate-700 p-3 rounded-2xl flex items-center space-x-2.5 transition">
        <div class="w-8 h-8 rounded-xl bg-emerald-500/15 text-emerald-400 flex items-center justify-center shrink-0">
          <i data-lucide="printer" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <div class="font-bold text-white text-xs truncate">Print Paper</div>
          <div class="text-[9px] text-slate-400">Official DOCX</div>
        </div>
      </a>
      <a href="<?php echo $baseUrl; ?>/modules/coe/blueprint.php" class="bg-slate-900 border border-slate-800 hover:border-slate-700 p-3 rounded-2xl flex items-center space-x-2.5 transition">
        <div class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-400 flex items-center justify-center shrink-0">
          <i data-lucide="table" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <div class="font-bold text-white text-xs truncate">30-Q Matrix</div>
          <div class="text-[9px] text-slate-400">TSS.PDF Grid</div>
        </div>
      </a>
      <a href="<?php echo $baseUrl; ?>/modules/coe/staff_directory.php" class="bg-slate-900 border border-slate-800 hover:border-slate-700 p-3 rounded-2xl flex items-center space-x-2.5 transition">
        <div class="w-8 h-8 rounded-xl bg-orange-500/15 text-orange-400 flex items-center justify-center shrink-0">
          <i data-lucide="users" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <div class="font-bold text-white text-xs truncate">Staff Directory</div>
          <div class="text-[9px] text-slate-400">Faculty Contacts</div>
        </div>
      </a>
      <?php else: ?>
      <a href="<?php echo $baseUrl; ?>/modules/teaching/question_bank_blueprint.php" class="bg-slate-900 border border-slate-800 hover:border-slate-700 p-3 rounded-2xl flex items-center space-x-2.5 transition">
        <div class="w-8 h-8 rounded-xl bg-purple-500/15 text-purple-400 flex items-center justify-center shrink-0">
          <i data-lucide="layout-template" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <div class="font-bold text-white text-xs truncate">Course Blueprint</div>
          <div class="text-[9px] text-slate-400">HOD Matrix</div>
        </div>
      </a>
      <?php endif; ?>

      <?php if ($isCoeAdmin): ?>
      <a href="<?php echo $baseUrl; ?>/modules/coe/file_manager.php" class="bg-slate-900 border border-slate-800 hover:border-slate-700 p-3 rounded-2xl flex items-center space-x-2.5 transition">
        <div class="w-8 h-8 rounded-xl bg-amber-500/15 text-amber-400 flex items-center justify-center shrink-0">
          <i data-lucide="folder-open" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <div class="font-bold text-white text-xs truncate">File Manager</div>
          <div class="text-[9px] text-slate-400">COE Archives</div>
        </div>
      </a>
      <?php endif; ?>
    </div>

    <!-- Sign Out Button -->
    <div class="pt-2">
      <a href="<?php echo $baseUrl; ?>/modules/auth/logout.php" onclick="return confirmQpsLogout(event, this.href);" class="w-full flex items-center justify-center space-x-2 bg-rose-500/15 hover:bg-rose-500/25 text-rose-300 border border-rose-500/30 px-4 py-3 rounded-2xl font-bold transition text-xs shadow-sm">
        <i data-lucide="log-out" class="w-4 h-4"></i>
        <span>Sign Out from Holy Cross QPS</span>
      </a>
    </div>

  </div>
</div>


<!-- ========================================================================= -->
<!-- 4. SIDEBAR & MOBILE NAVIGATION SCRIPT HANDLER                             -->
<!-- ========================================================================= -->
<script>
// Desktop Sidebar Toggle with LocalStorage Persistence
function toggleQpsDesktopSidebar() {
  const sidebar = document.getElementById('qps-desktop-sidebar');
  const body = document.body;
  if (!sidebar) return;

  const isCollapsed = sidebar.classList.contains('collapsed');
  if (isCollapsed) {
    sidebar.classList.remove('collapsed');
    body.classList.remove('sidebar-collapsed');
    localStorage.setItem('qps_desktop_sidebar_collapsed', '0');
    updateDesktopSidebarIcon(false);
  } else {
    sidebar.classList.add('collapsed');
    body.classList.add('sidebar-collapsed');
    localStorage.setItem('qps_desktop_sidebar_collapsed', '1');
    updateDesktopSidebarIcon(true);
  }
}

function updateDesktopSidebarIcon(collapsed) {
  const iconEl = document.getElementById('qps-desktop-sidebar-icon');
  if (!iconEl) return;
  if (collapsed) {
    iconEl.setAttribute('data-lucide', 'panel-left-open');
  } else {
    iconEl.setAttribute('data-lucide', 'panel-left-close');
  }
  if (window.lucide) {
    lucide.createIcons();
  }
}

// Mobile "More" Slide-Up Sheet Toggle
function toggleQpsMoreSheet() {
  const sheet = document.getElementById('qps-mobile-more-sheet');
  const backdrop = document.getElementById('qps-mobile-more-backdrop');
  if (!sheet || !backdrop) return;

  if (sheet.classList.contains('translate-y-full')) {
    sheet.classList.remove('translate-y-full');
    backdrop.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
  } else {
    sheet.classList.add('translate-y-full');
    backdrop.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
  }
}

// SweetAlert2 Confirmation for Logout
function confirmQpsLogout(event, logoutUrl) {
  if (event) event.preventDefault();
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Sign Out?',
      text: 'Are you sure you want to log out of Holy Cross College QPS?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Sign Out',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#FF5B00',
      cancelButtonColor: '#475569',
      customClass: {
        popup: 'rounded-[28px]',
        confirmButton: 'rounded-full px-5 py-2.5 font-bold',
        cancelButton: 'rounded-full px-5 py-2.5 font-bold'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        window.location.href = logoutUrl;
      }
    });
  } else {
    if (confirm('Are you sure you want to sign out?')) {
      window.location.href = logoutUrl;
    }
  }
  return false;
}

// Initialize Desktop Sidebar on Page Load
document.addEventListener('DOMContentLoaded', function() {
  const savedState = localStorage.getItem('qps_desktop_sidebar_collapsed');
  const sidebar = document.getElementById('qps-desktop-sidebar');
  if (savedState === '1' && sidebar) {
    sidebar.classList.add('collapsed');
    document.body.classList.add('sidebar-collapsed');
    updateDesktopSidebarIcon(true);
  }
  if (window.lucide) {
    lucide.createIcons();
  }
});
</script>
