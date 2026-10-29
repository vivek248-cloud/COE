<?php
/**
 * Global Modern Page & Action Loader Component
 * Holy Cross College (Autonomous) - Examination System
 */
?>
<!-- Global Page Loader Overlay (Hidden by Default) -->
<div id="qps-global-loader" style="display: none !important;" class="hidden fixed inset-0 bg-slate-950/60 backdrop-blur-md z-50 items-center justify-center transition-all duration-200">
  <div class="bg-white/95 border border-stone-200/80 p-6 rounded-[24px] shadow-2xl flex flex-col items-center space-y-3 max-w-xs mx-4 text-center">
    <div class="relative flex items-center justify-center">
      <div class="w-12 h-12 rounded-full border-4 border-stone-200 border-t-[#1C1D21] animate-spin"></div>
      <div class="absolute w-6 h-6 rounded-full bg-amber-400/20 flex items-center justify-center">
        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
      </div>
    </div>
    <div>
      <h4 class="font-extrabold text-slate-900 text-sm" id="qps-loader-title">Processing Request</h4>
      <p class="text-[11px] text-slate-500 mt-0.5" id="qps-loader-text">Please wait a moment...</p>
    </div>
  </div>
</div>

<script>
window.showQpsLoader = function(title = 'Processing Request', text = 'Please wait a moment...') {
  const el = document.getElementById('qps-global-loader');
  const t = document.getElementById('qps-loader-title');
  const s = document.getElementById('qps-loader-text');
  if (t) t.textContent = title;
  if (s) s.textContent = text;
  if (el) {
    el.classList.remove('hidden', 'hidden-loader');
    el.style.setProperty('display', 'flex', 'important');
    el.style.opacity = '1';
    el.style.visibility = 'visible';
    el.style.pointerEvents = 'auto';
  }
};

window.hideQpsLoader = function() {
  const el = document.getElementById('qps-global-loader');
  if (el) {
    el.classList.add('hidden', 'hidden-loader');
    el.style.setProperty('display', 'none', 'important');
    el.style.opacity = '0';
    el.style.visibility = 'hidden';
    el.style.pointerEvents = 'none';
  }
};

// Guarantee loader is closed immediately on load & DOMContentLoaded & pageshow
window.hideQpsLoader();
document.addEventListener('DOMContentLoaded', window.hideQpsLoader);
window.addEventListener('load', window.hideQpsLoader);
window.addEventListener('pageshow', window.hideQpsLoader);
</script>
