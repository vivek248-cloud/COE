<?php
define('PAGE_TITLE', 'Submitted Courses');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
require_once __DIR__.'/../../includes/workflow.php';
requireAuth();
$role = qps_workflow_role();
if ($role === 'DEAN') requireDean();
elseif ($role === 'STAFF' && (strtoupper((string)(getCurrentUser()['role'] ?? '')) === 'HOD' || !empty(getCurrentUser()['is_hod'])) && empty(getCurrentUser()['is_erp_staff'])) { $_SESSION['qps_portal_role'] = 'HOD'; $role = 'HOD'; }
elseif ($role !== 'HOD') { http_response_code(403); exit('HOD or Dean access required.'); }
$pdo = getDBConnection();
$user = getCurrentUser();
$deptCode = strtoupper(trim((string)($user['dept_code'] ?? '')));

$statuses = ['SUBMITTED_TO_HOD','HOD_RECHECK','HOD_REMARKS','STAFF_CORRECTION','SUBMITTED_TO_DEAN','DEAN_REMARKS','SUBMITTED_TO_COE','COE_APPROVED','ALLOTTED','PAPER_GENERATED'];
$statusFilter = trim((string)($_GET['status'] ?? ''));
$departmentFilter = strtoupper(trim((string)($_GET['department'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 15;

$where = ["p.status <> 'DRAFT'"];
$params = [];
if ($role === 'HOD') {
    if ($deptCode === '') $where[] = '1=0';
    else { $where[] = 'UPPER(COALESCE(p.dept_code,\'\')) = ?'; $params[] = $deptCode; }
} elseif ($departmentFilter !== '') {
    $where[] = 'UPPER(COALESCE(p.dept_code,\'\')) = ?'; $params[] = $departmentFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, $statuses, true)) { $where[] = 'p.status = ?'; $params[] = $statusFilter; }
if ($search !== '') { $where[] = '(p.paper_code LIKE ? OR d.course_title LIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
$whereSql = implode(' AND ', $where);

$departmentOptions = [];
if ($role === 'DEAN') {
    $deptStmt = $pdo->query("SELECT DISTINCT p.dept_code, COALESCE(dep.name,p.dept_code) AS dept_name FROM qps_submission_packages p LEFT JOIN departments dep ON UPPER(dep.code)=UPPER(p.dept_code) WHERE p.status <> 'DRAFT' AND COALESCE(p.dept_code,'') <> '' ORDER BY dept_name");
    $departmentOptions = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM qps_submission_packages p LEFT JOIN qps_question_bank_drafts d ON d.id=p.bank_draft_id WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $pageSize;
$sql = "SELECT p.*, d.course_title, d.dept_name AS draft_dept_name, COALESCE(NULLIF(dep.name,''),NULLIF(d.dept_name,''),p.dept_code) AS display_department, COALESCE(NULLIF(st.FIRST_NAME,''),p.staff_code) AS staff_name
        FROM qps_submission_packages p
        LEFT JOIN qps_question_bank_drafts d ON d.id=p.bank_draft_id
        LEFT JOIN departments dep ON UPPER(dep.code)=UPPER(p.dept_code)
        LEFT JOIN pr_x_xxxx_staf_prof_mast st ON UPPER(st.STAFF_CODE)=UPPER(p.staff_code)
        WHERE {$whereSql}
        ORDER BY p.updated_at DESC, p.id DESC LIMIT {$pageSize} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

function qps_submitted_url(array $overrides=[]): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key=>$value) if ($value === '' || $value === null) unset($query[$key]);
    return 'submitted.php'.($query ? '?'.http_build_query($query) : '');
}
require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/navbar.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-5">
 <div><p class="text-xs uppercase tracking-widest font-black text-indigo-600"><?=htmlspecialchars($role)?> Portal</p><h1 class="text-3xl font-black text-slate-900">Submitted Courses</h1><p class="text-sm text-slate-500 mt-1">All submission packages beyond DRAFT, including completed and returned packages.</p></div>
 <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
  <?php if ($role === 'DEAN'): ?><label class="text-xs font-bold text-slate-600">Department<select name="department" class="mt-1 w-full rounded-xl border-slate-300"><option value="">All departments</option><?php foreach ($departmentOptions as $dept): ?><option value="<?=htmlspecialchars($dept['dept_code'])?>" <?=$departmentFilter===$dept['dept_code']?'selected':''?>><?=htmlspecialchars($dept['dept_name'])?></option><?php endforeach;?></select></label><?php else: ?><div class="text-xs font-bold text-slate-600">Department<div class="mt-1 rounded-xl bg-slate-50 border border-slate-200 p-2.5"><?=htmlspecialchars($user['department'] ?? $deptCode)?></div></div><?php endif; ?>
  <label class="text-xs font-bold text-slate-600">Status<select name="status" class="mt-1 w-full rounded-xl border-slate-300"><option value="">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?=htmlspecialchars($status)?>" <?=$statusFilter===$status?'selected':''?>><?=htmlspecialchars(str_replace('_',' ',$status))?></option><?php endforeach;?></select></label>
  <label class="text-xs font-bold text-slate-600 md:col-span-2">Search course code or title<input name="q" value="<?=htmlspecialchars($search)?>" placeholder="Search course..." class="mt-1 w-full rounded-xl border-slate-300"></label>
  <div class="md:col-span-4 flex justify-end gap-2"><a href="submitted.php" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold">Clear</a><button class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-300 text-white text-sm font-bold">Apply Filters</button></div>
 </form>
 <?php if (!$packages): ?><div class="rounded-2xl border border-dashed p-10 text-center text-slate-500">No submitted courses match these filters.</div><?php endif; ?>
 <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
 <?php foreach ($packages as $package): ?>
  <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition">
   <h1 class="text-2xl font-black text-slate-900"><?=htmlspecialchars($package['paper_code'])?></h1>
   <div class="flex flex-wrap gap-2 mt-3"><?php foreach (['questions'=>'Question Bank','qbb'=>'Question Bank Blueprint','final'=>'Final Blueprint'] as $artifact=>$label): ?><a href="view.php?package_id=<?=(int)$package['id']?>&amp;artifact=<?=$artifact?>" class="rounded-xl bg-slate-100 hover:bg-indigo-50 focus:ring-2 focus:ring-indigo-300 text-slate-800 hover:text-indigo-700 px-3 py-2 text-xs font-black transition"><?=htmlspecialchars($label)?></a><?php endforeach;?></div>
   <p class="font-semibold text-slate-700 mt-4"><?=htmlspecialchars($package['course_title'] ?? '')?></p>
   <p class="text-xs text-slate-500 mt-1"><?=htmlspecialchars($package['display_department'] ?? $package['dept_code'] ?? 'Department not set')?></p>
   <p class="text-xs text-slate-500 mt-1">Staff: <strong class="text-slate-700"><?=htmlspecialchars($package['staff_name'] ?? $package['staff_code'] ?? '')?></strong></p>
   <div class="flex flex-wrap items-center justify-between gap-2 mt-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-black bg-indigo-50 text-indigo-700"><?=htmlspecialchars(str_replace('_',' ',$package['status']))?></span><span class="text-[11px] text-slate-500">Round <?= (int)$package['round_no'] ?></span></div>
   <p class="text-[11px] text-slate-400 mt-3">Last updated: <?=htmlspecialchars((string)($package['updated_at'] ?? '—'))?></p>
  </article>
 <?php endforeach; ?>
 </div>
 <?php if ($totalPages > 1): ?><nav class="flex items-center justify-center gap-2 pt-2"><a href="<?=htmlspecialchars(qps_submitted_url(['page'=>max(1,$page-1)]))?>" class="px-3 py-2 rounded-lg border hover:bg-slate-50 <?=$page<=1?'pointer-events-none opacity-40':''?>">Previous</a><span class="text-sm text-slate-600">Page <?=$page?> of <?=$totalPages?> · <?=$totalRows?> packages</span><a href="<?=htmlspecialchars(qps_submitted_url(['page'=>min($totalPages,$page+1)]))?>" class="px-3 py-2 rounded-lg border hover:bg-slate-50 <?=$page>=$totalPages?'pointer-events-none opacity-40':''?>">Next</a></nav><?php endif; ?>
</main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
