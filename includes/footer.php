<?php
/**
 * Global Footer Component
 * Holy Cross College (Autonomous) - Examination System
 */
?>
  <footer class="mt-auto py-6 bg-slate-950 text-slate-400 text-xs border-t border-slate-800 no-print">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-3">
      <div class="flex items-center space-x-2.5">
        <div class="w-6 h-6 rounded-lg bg-gradient-to-tr from-amber-500 via-orange-500 to-rose-500 text-white flex items-center justify-center font-serif font-black text-xs shadow-sm">H</div>
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
