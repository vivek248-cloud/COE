<?php
/**
 * Full-Page Hourglass Loader Component
 * Examination Management System
 *
 * Include once, just before </body>:
 *     <?php include __DIR__ . '/institutional-loader.php'; ?>
 *
 * JS API:
 *     HCCLoader.show('Please wait', 'Generating question paper...');
 *     HCCLoader.hide();
 *
 * Auto-behaviour:
 *   - Shows on form submit and on internal link clicks.
 *   - Opt out per element with  data-no-loader
 *   - For forms/links that trigger a file DOWNLOAD (page stays open) add
 *     data-loader-timeout="4000"  (ms) so the loader closes itself.
 *   - Custom text per element: data-loader-title="..." data-loader-text="..."
 */
?>
<div id="hcc-loader" role="status" aria-live="polite" aria-hidden="true">
  <div class="hcc-wrap">
    <div class="hcc-hourglass"></div>
    <h4 id="hcc-loader-title">Please wait</h4>
    <p id="hcc-loader-text">Processing your request...</p>
  </div>
</div>

<style>
  #hcc-loader{
    --c1:#673b14; --c2:#f8b13b;
    position:fixed; inset:0; z-index:99999;
    display:flex; align-items:center; justify-content:center;
    background:#fff8; background:linear-gradient(90deg,rgba(255,248,248,.95),rgba(255,248,248,.95)),#fff;
    opacity:0; visibility:hidden; pointer-events:none;
    transition:opacity .25s ease, visibility 0s linear .25s;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; user-select:none;
  }
  #hcc-loader.hcc-on{opacity:1; visibility:visible; pointer-events:auto; transition:opacity .25s ease}
  #hcc-loader .hcc-wrap{display:flex; flex-direction:column; align-items:center; text-align:center; gap:10px; padding:0 20px}
  #hcc-loader h4{margin:18px 0 0; font-size:20px; font-weight:800; color:var(--c1); letter-spacing:.02em}
  #hcc-loader p{margin:0; font-size:13px; font-weight:500; color:#8a6a4a}

  /* Hourglass */
  .hcc-hourglass{
    width:80px; height:120px;
    border-top:4px solid var(--c1);
    border-bottom:4px solid var(--c1);
    background:linear-gradient(90deg,var(--c1) 2px,var(--c2) 0 5px,var(--c1) 0) 50%/7px 8px no-repeat;
    display:grid; overflow:hidden;
    animation:hcc-l5-0 2s infinite linear;
  }
  .hcc-hourglass::before,
  .hcc-hourglass::after{
    content:""; grid-area:1/1;
    width:75%; height:calc(50% - 4px); margin:0 auto;
    border:2px solid var(--c1); border-top:0; box-sizing:content-box;
    border-radius:0 0 40% 40%;
    -webkit-mask:linear-gradient(#000 0 0) bottom/4px 2px no-repeat, linear-gradient(#000 0 0);
    -webkit-mask-composite:destination-out;
            mask:linear-gradient(#000 0 0) bottom/4px 2px no-repeat, linear-gradient(#000 0 0);
            mask-composite:exclude;
    background:
      linear-gradient(var(--d,0deg),var(--c2) 50%,#0000 0) bottom/100% 205%,
      linear-gradient(var(--c2) 0 0) center/0 100%;
    background-repeat:no-repeat;
    animation:inherit; animation-name:hcc-l5-1;
  }
  .hcc-hourglass::after{
    transform-origin:50% calc(100% + 2px);
    transform:scaleY(-1);
    --s:3px; --d:180deg;
  }
  @keyframes hcc-l5-0{80%{transform:rotate(0)}100%{transform:rotate(.5turn)}}
  @keyframes hcc-l5-1{
    10%,70%{background-size:100% 205%,var(--s,0) 100%}
    70%,100%{background-position:top,center}
  }
  @media (prefers-reduced-motion:reduce){ .hcc-hourglass{animation-duration:4s} }
</style>

<script>
(function () {
  var el    = document.getElementById('hcc-loader');
  var title = document.getElementById('hcc-loader-title');
  var text  = document.getElementById('hcc-loader-text');
  var DELAY = 120;      // ms before showing (avoids flicker on instant actions)
  var MIN   = 500;      // ms minimum visible time once shown
  var SAFETY = 30000;   // ms auto-hide if nothing else closes it
  var delayT, safetyT, shownAt = 0, visible = false;

  function show(t, msg, timeout) {
    title.textContent = t   || 'Please wait';
    text.textContent  = msg || 'Processing your request...';
    clearTimeout(delayT); clearTimeout(safetyT);
    delayT = setTimeout(function () {
      el.classList.add('hcc-on');
      el.setAttribute('aria-hidden', 'false');
      visible = true; shownAt = Date.now();
    }, DELAY);
    safetyT = setTimeout(hide, timeout || SAFETY);
  }

  function hide() {
    clearTimeout(delayT); clearTimeout(safetyT);
    if (!visible) return;
    var wait = Math.max(0, MIN - (Date.now() - shownAt));
    setTimeout(function () {
      el.classList.remove('hcc-on');
      el.setAttribute('aria-hidden', 'true');
      visible = false;
    }, wait);
  }

  window.HCCLoader = { show: show, hide: hide };

  function opts(node) {
    var t = node.getAttribute('data-loader-timeout');
    return [node.getAttribute('data-loader-title'),
            node.getAttribute('data-loader-text'),
            t ? parseInt(t, 10) : 0];
  }

  // Forms
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute('data-no-loader') || f.target === '_blank') return;
    setTimeout(function () {
      if (e.defaultPrevented) return;
      var o = opts(f); show(o[0], o[1], o[2]);
    }, 0);
  });

  // Internal links
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if (a.hasAttribute('data-no-loader') || a.hasAttribute('download')) return;
    if (a.target && a.target !== '_self') return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
    if (a.origin !== location.origin) return;
    if (a.pathname === location.pathname && a.search === location.search && a.hash) return;
    var o = opts(a); show(o[0], o[1] || 'Opening the requested page...', o[2]);
  });

  // Back/forward cache: never leave a stuck loader
  window.addEventListener('pageshow', function (e) { if (e.persisted) { visible = true; hide(); } });
})();
</script>