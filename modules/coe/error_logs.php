<?php
define('PAGE_TITLE', 'System Error Monitor');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/workflow.php';
qps_workflow_require_role(['COE']);
$logFile = dirname(__DIR__, 2) . '/storage/private/qps-error-log.jsonl';
$entries = [];
if (is_file($logFile) && is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice(array_reverse($lines), 0, 1000) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) $entries[] = $row;
    }
}
$filter = strtoupper(trim((string)($_GET['level'] ?? 'ALL')));
$search = trim((string)($_GET['q'] ?? ''));
$entries = array_values(array_filter($entries, static function ($row) use ($filter, $search) {
    if ($filter !== 'ALL' && strtoupper((string)($row['level'] ?? '')) !== $filter) return false;
    if ($search !== '') {
        $haystack = implode(' ', array_map('strval', [$row['message'] ?? '', $row['file'] ?? '', $row['staff_code'] ?? '', $row['uri'] ?? '']));
        if (stripos($haystack, $search) === false) return false;
    }
    return true;
}));
$baseUrl = getBaseUrl();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>
<main class="max-w-7xl mx-auto p-4 md:p-8 space-y-6">
  <section class="rounded-3xl bg-slate-950 text-white p-6 md:p-8 shadow-xl overflow-hidden relative">
    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div><p class="text-xs uppercase tracking-[.2em] text-rose-300 font-black">COE · Diagnostics</p><h1 class="text-2xl md:text-3xl font-black mt-2">System Error Monitor</h1><p class="text-sm text-slate-300 mt-2">PHP warnings and fatal errors captured by the application.</p></div>
      <div class="rounded-2xl bg-white/10 border border-white/15 px-5 py-4"><div class="text-xs text-slate-300 font-bold uppercase">Matching log entries</div><div class="text-3xl font-black mt-1"><?=count($entries)?></div></div>
    </div>
  </section>
  <section class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
    <form method="get" class="grid grid-cols-1 md:grid-cols-[200px_1fr_auto] gap-3">
      <select name="level" class="rounded-xl border-slate-300"><option value="ALL">All severities</option><option value="FATAL" <?=$filter==='FATAL'?'selected':''?>>Fatal</option><option value="CLIENT" <?=$filter==='CLIENT'?'selected':''?>>Browser / JavaScript</option><?php foreach ([E_WARNING=>'PHP '.E_WARNING,E_NOTICE=>'PHP '.E_NOTICE,E_USER_WARNING=>'PHP '.E_USER_WARNING,E_USER_NOTICE=>'PHP '.E_USER_NOTICE,E_DEPRECATED=>'PHP '.E_DEPRECATED] as $n=>$label): ?><option value="<?=htmlspecialchars($label)?>" <?=$filter===$label?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach; ?></select>
      <input type="search" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Search message, file, staff code or page…" class="rounded-xl border-slate-300">
      <button class="rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 font-bold">Filter logs</button>
    </form>
  </section>
  <section class="space-y-3">
    <?php if (!$entries): ?><div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-8 text-center"><div class="text-emerald-700 font-black">No matching errors</div><p class="text-sm text-emerald-700 mt-1">No captured PHP errors match your filter.</p></div><?php endif; ?>
    <?php foreach ($entries as $i=>$entry): $fatal = strtoupper((string)($entry['level'] ?? '')) === 'FATAL'; ?>
      <article class="rounded-2xl border <?=$fatal?'border-rose-300 bg-rose-50/50':'border-amber-200 bg-white'?> shadow-sm overflow-hidden">
        <button type="button" class="w-full text-left p-4 flex flex-col md:flex-row md:items-center gap-3" onclick="document.getElementById('err<?=$i?>').classList.toggle('hidden')">
          <span class="shrink-0 rounded-full px-3 py-1 text-[11px] font-black <?=$fatal?'bg-rose-600 text-white':'bg-amber-100 text-amber-800'?>"><?=htmlspecialchars((string)($entry['level'] ?? 'ERROR'))?></span>
          <span class="min-w-0 flex-1"><strong class="block text-sm text-slate-900 break-words"><?=htmlspecialchars((string)($entry['message'] ?? 'Unknown error'))?></strong><span class="text-xs text-slate-500"><?=htmlspecialchars((string)($entry['time'] ?? ''))?> · <?=htmlspecialchars((string)($entry['staff_code'] ?? 'SYSTEM'))?> · <?=htmlspecialchars((string)($entry['uri'] ?? ''))?></span></span>
          <span class="text-xs font-bold text-indigo-600">View details ↓</span>
        </button>
        <div id="err<?=$i?>" class="hidden border-t border-slate-200 p-4 space-y-2 text-xs">
          <div><strong>File:</strong> <code class="break-all"><?=htmlspecialchars((string)($entry['file'] ?? ''))?>:<?= (int)($entry['line'] ?? 0) ?></code></div>
          <div><strong>Role / Staff:</strong> <?=htmlspecialchars((string)($entry['role'] ?? ''))?> / <?=htmlspecialchars((string)($entry['staff_name'] ?? ''))?></div>
          <div><strong>IP:</strong> <?=htmlspecialchars((string)($entry['ip'] ?? ''))?></div>
          <?php if (!empty($entry['trace'])): ?><details class="rounded-xl bg-slate-950 text-slate-100 p-3"><summary class="cursor-pointer font-bold">Stack trace</summary><pre class="mt-3 whitespace-pre-wrap break-all"><?=htmlspecialchars((string)$entry['trace'])?></pre></details><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
  <p class="text-xs text-slate-500">Logs are stored in <code>storage/private/qps-error-log.jsonl</code> and are accessible only to COE-authorised users.</p>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
