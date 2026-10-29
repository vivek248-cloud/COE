<?php
define('PAGE_TITLE', 'Activity & Security Logs');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
requireAuth();
if (!isCOE() && !isSuperAdmin() && !isERPStaff()) { http_response_code(403); exit('Access denied.'); }
$pdo = getDBConnection();
qps_activity_log($pdo, 'VIEW_ACTIVITY_LOGS', 'LOGS', '', []);
$filters = [
  'staff_code' => trim((string)($_GET['staff_code'] ?? '')),
  'action' => trim((string)($_GET['action'] ?? '')),
  'from' => trim((string)($_GET['from'] ?? '')),
  'to' => trim((string)($_GET['to'] ?? ''))
];
$where = []; $args = [];
if ($filters['staff_code'] !== '') { $where[] = 'staff_code LIKE ?'; $args[] = '%'.$filters['staff_code'].'%'; }
if ($filters['action'] !== '') { $where[] = 'action LIKE ?'; $args[] = '%'.$filters['action'].'%'; }
if ($filters['from'] !== '') { $where[] = 'created_at >= ?'; $args[] = $filters['from'].' 00:00:00'; }
if ($filters['to'] !== '') { $where[] = 'created_at <= ?'; $args[] = $filters['to'].' 23:59:59'; }
$page = max(1, (int)($_GET['page'] ?? 1)); $limit = 50; $offset = ($page-1)*$limit;
$rows = []; $total = 0;
try {
  $clause = $where ? ' WHERE '.implode(' AND ', $where) : '';
  $st = $pdo->prepare('SELECT COUNT(*) FROM qps_activity_logs'.$clause); $st->execute($args); $total=(int)$st->fetchColumn();
  $st = $pdo->prepare('SELECT * FROM qps_activity_logs'.$clause.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset); $st->execute($args); $rows=$st->fetchAll();
} catch (Throwable $e) { $error = 'Activity log table will be created when the next action is logged. Refresh this page.'; }
require_once __DIR__ . '/../../includes/header.php';
?>
<main class="max-w-screen-2xl mx-auto px-4 py-6 space-y-5">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div><p class="text-xs font-bold uppercase tracking-widest text-orange-600">Security & Accountability</p><h1 class="text-2xl font-black text-slate-900">Activity logs</h1><p class="text-sm text-slate-500 mt-1">Login, question workflow, approvals and exports.</p></div>
    <div class="rounded-xl bg-slate-900 text-white px-4 py-3 text-sm"><strong><?= number_format($total) ?></strong> events</div>
  </div>
  <form class="bg-white border border-slate-200 rounded-2xl p-4 grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
    <input name="staff_code" value="<?= htmlspecialchars($filters['staff_code']) ?>" placeholder="Staff code" class="border rounded-xl px-3 py-2 text-sm">
    <input name="action" value="<?= htmlspecialchars($filters['action']) ?>" placeholder="Action" class="border rounded-xl px-3 py-2 text-sm">
    <input type="date" name="from" value="<?= htmlspecialchars($filters['from']) ?>" class="border rounded-xl px-3 py-2 text-sm">
    <input type="date" name="to" value="<?= htmlspecialchars($filters['to']) ?>" class="border rounded-xl px-3 py-2 text-sm">
    <button class="rounded-xl bg-indigo-600 text-white font-bold px-4 py-2">Filter logs</button>
  </form>
  <div class="overflow-x-auto bg-white border border-slate-200 rounded-2xl">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600"><tr><th class="text-left p-3">Date / time</th><th class="text-left p-3">Staff</th><th class="text-left p-3">Action</th><th class="text-left p-3">Entity</th><th class="text-left p-3">IP address</th><th class="text-left p-3">Details</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="p-8 text-center text-slate-500">No activity logs match these filters.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?><tr class="border-t align-top hover:bg-slate-50"><td class="p-3 whitespace-nowrap"><?= htmlspecialchars($r['created_at']) ?></td><td class="p-3"><strong><?= htmlspecialchars($r['staff_code']) ?></strong><div class="text-xs text-slate-500"><?= htmlspecialchars($r['staff_name']) ?></div><div class="text-xs text-slate-500"><?= htmlspecialchars($r['staff_mobile']) ?> · <?= htmlspecialchars($r['role']) ?></div></td><td class="p-3 font-semibold"><?= htmlspecialchars($r['action']) ?></td><td class="p-3"><?= htmlspecialchars($r['entity_type']) ?> <?= htmlspecialchars($r['entity_id']) ?></td><td class="p-3"><?= htmlspecialchars($r['ip_address']) ?></td><td class="p-3 max-w-sm break-words text-xs"><?= htmlspecialchars((string)$r['details_json']) ?><div class="text-slate-400 mt-1"><?= htmlspecialchars((string)$r['user_agent']) ?></div></td></tr><?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="flex justify-between items-center text-sm"><span class="text-slate-500">Page <?= $page ?> of <?= max(1,(int)ceil($total/$limit)) ?></span><div class="flex gap-2"><?php if($page>1): ?><a class="border rounded-lg px-3 py-2" href="?<?= http_build_query(array_merge($filters,['page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if($page*$limit<$total): ?><a class="border rounded-lg px-3 py-2" href="?<?= http_build_query(array_merge($filters,['page'=>$page+1])) ?>">Next</a><?php endif; ?></div></div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
