<?php
/**
 * Global Header Component
 * Holy Cross College (Autonomous) - Modern Mobile & Desktop System
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/pagination.php';

$isUserActive = isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
  </script>

  <!-- Searchable Select with Typing Finder -->
  <script src="<?php echo getBaseUrl(); ?>/assets/js/searchable-select.js"></script>

  <!-- Early Sidebar State Init to Prevent Layout Shift -->
  <script>
    (function() {
      try {
        if (<?php echo $isUserActive ? 'true' : 'false'; ?>) {
          const isCollapsed = localStorage.getItem('qps_desktop_sidebar_collapsed') === '1';
          if (isCollapsed) {
            document.documentElement.classList.add('sidebar-collapsed-init');
          }
        }
      } catch (e) {}
    })();
  </script>

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
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col font-sans antialiased <?php echo $isUserActive ? 'has-desktop-sidebar pb-28 lg:pb-8' : 'pb-6'; ?>">
