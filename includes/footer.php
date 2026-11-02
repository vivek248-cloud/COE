<?php
/**
 * Global Footer Component
 * Holy Cross College (Autonomous) - Examination System
 */
?>
  <footer class="qps-site-footer mt-auto py-5 bg-slate-950 text-slate-400 text-xs border-t border-slate-800 no-print pb-24 lg:pb-5">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-3">
      <div class="flex items-center space-x-2.5">
        <img src="https://hcctrichy.ac.in/img/logo.png" alt="Holy Cross College Logo" class="w-6 h-6 object-contain rounded-md bg-white p-0.5 shadow-sm">
        <span class="font-bold text-slate-200"><?php echo COLLEGE_NAME; ?></span>
        <span class="text-slate-600">•</span>
        <span class="text-orange-400 font-semibold">NAAC A++ (4th Cycle)</span>
      </div>
      <div class="text-[11px] text-slate-400 font-medium flex items-center space-x-2">
        <span>Autonomous Question Paper & OBE Blueprint System</span>
        <span class="text-slate-600">•</span>
        <span class="font-mono text-orange-300 font-bold"><?php echo date('Y'); ?></span>
      </div>
    </div>
  </footer>

  <!-- Global Core Scripts -->
  <script>
    // Initialize Lucide Icons
    if (window.lucide) {
      lucide.createIcons();
    }

  </script>
</body>
</html>
