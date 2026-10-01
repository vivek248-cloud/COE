<?php
/**
 * Global Header Component
 * Holy Cross College (Autonomous)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/pagination.php';

// COE staff should see only the COE application surface. Admin URLs are
// additionally protected server-side by requireSuperAdmin(); this flag only
// controls navigation visibility so the UI does not expose admin routes.
$qpsRestrictedCOE = isCOE() && !isSuperAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo defined('PAGE_TITLE') ? PAGE_TITLE . ' | ' . APP_NAME : APP_NAME; ?></title>

  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?php echo getBaseUrl(); ?>/assets/css/custom.css">

  <!-- Base URL Definition for JS -->
  <script>
    window.QPS_BASE_URL = "<?php echo getBaseUrl(); ?>";
    window.QPS_RESTRICTED_COE = <?php echo $qpsRestrictedCOE ? 'true' : 'false'; ?>;
  </script>

  <?php if ($qpsRestrictedCOE): ?>
  <style id="qps-coe-admin-route-hide">
    .qps-coe-restricted a[href*="/modules/admin/"],
    .qps-coe-restricted a[href*="login_as_erp=1"] {
      display: none !important;
    }
  </style>
  <?php endif; ?>

  <!-- Searchable Select with Typing Finder -->
  <script src="<?php echo getBaseUrl(); ?>/assets/js/searchable-select.js"></script>

  <!-- MathJax 3 for Live LaTeX Formulas & Equations Rendering -->
  <script>
    window.MathJax = {
      tex: {
        inlineMath: [['$', '$'], ['\\(', '\\)']],
        displayMath: [['$$', '$$'], ['\\[', '\\]']],
        processEscapes: true
      },
      svg: {
        fontCache: 'global'
      }
    };
  </script>
  <script type="text/javascript" id="MathJax-script" async
    src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js">
  </script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col font-sans antialiased<?php echo $qpsRestrictedCOE ? ' qps-coe-restricted' : ''; ?>">
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.QPS_RESTRICTED_COE) return;
  document.querySelectorAll('a[href*="/modules/admin/"], a[href*="login_as_erp=1"]').forEach(function (el) {
    el.remove();
  });
  document.querySelectorAll('#qps-sidebar-drawer .space-y-1').forEach(function (section) {
    if (!section.querySelector('a')) section.remove();
  });
});
</script>
