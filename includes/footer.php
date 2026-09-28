<?php
/**
 * Global Footer Component
 * Holy Cross College (Autonomous) - Examination System
 */
?>
  <footer class="mt-auto py-6 bg-slate-900 text-slate-400 text-xs border-t border-indigo-950 no-print">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-3">
      <div class="flex items-center space-x-2">
        <div class="w-6 h-6 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-serif font-black text-xs">H</div>
        <span class="font-bold text-slate-200"><?php echo COLLEGE_NAME; ?></span>
        <span>•</span>
        <span class="text-amber-400 font-medium">NAAC A++ (4th Cycle)</span>
      </div>
      <div class="text-[11px] text-slate-400 font-medium flex items-center space-x-2">
        <span>Autonomous Question Paper & OBE Blueprint System</span>
        <span>•</span>
        <span class="font-mono text-indigo-300"><?php echo date('Y'); ?></span>
      </div>
    </div>
  </footer>

  <!-- Global Core Scripts -->
  <script>
    // Initialize Lucide Icons
    if (window.lucide) {
      lucide.createIcons();
    }

    // Close Dropdowns on Click Outside
    document.addEventListener('click', function(e) {
      const userDropdown = document.getElementById('qps-nav-user-dropdown');
      if (userDropdown && !userDropdown.contains(e.target) && !e.target.closest('button[onclick*="qps-nav-user-dropdown"]')) {
        userDropdown.classList.add('hidden');
      }
    });

    // Auto-update IST Clock
    function updateHccLiveClock() {
      const el = document.getElementById('hcc-live-clock');
      if (!el) return;
      try {
        const now = new Date();
        const formatter = new Intl.DateTimeFormat('en-GB', {
          timeZone: 'Asia/Kolkata',
          day: '2-digit',
          month: 'short',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hour12: true
        });
        const parts = formatter.formatToParts(now);
        const p = {};
        parts.forEach(pt => { p[pt.type] = pt.value; });
        el.textContent = `${p.day} ${p.month} ${p.year}, ${p.hour}:${p.minute}:${p.second} ${(p.dayPeriod || '').toUpperCase()} IST`;
      } catch (e) {
        const d = new Date();
        el.textContent = d.toLocaleDateString('en-GB') + ' ' + d.toLocaleTimeString('en-US') + ' IST';
      }
    }
    setInterval(updateHccLiveClock, 1000);
    document.addEventListener('DOMContentLoaded', updateHccLiveClock);
  </script>
</body>
</html>
