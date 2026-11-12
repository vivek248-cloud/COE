<?php
/**
 * HOD Portal Dashboard
 * Uses the existing centralized Staff -> HOD -> Dean -> COE workflow.
 * HOD can only see packages belonging to the department in the staff session.
 */
define('PAGE_TITLE', 'HOD Dashboard');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/workflow.php';

requireHOD();

$pdo = getDBConnection();
$user = getCurrentUser();
$staffCode = (string)($user['staff_code'] ?? '');
$deptCode = strtoupper(trim((string)($user['dept_code'] ?? '')));
$deptName = trim((string)($user['department'] ?? 'Department'));

if ($deptCode === '') {
    http_response_code(403);
    exit('Your staff profile does not contain a department code. Please contact the ERP administrator.');
}

$counts = [
    'pending' => 0,
    'recheck' => 0,
    'dean_remarks' => 0,
    'forwarded' => 0,
    'total' => 0,
];
$recent = [];
$error = '';

try {
    $sql = "SELECT
        COUNT(*) AS total,
        SUM(status IN ('SUBMITTED_TO_HOD')) AS pending,
        SUM(status IN ('HOD_RECHECK')) AS recheck,
        SUM(status IN ('DEAN_REMARKS')) AS dean_remarks,
        SUM(status IN ('SUBMITTED_TO_DEAN','SUBMITTED_TO_COE','COE_APPROVED','ALLOTTED','PAPER_GENERATED')) AS forwarded
      FROM qps_submission_packages
      WHERE UPPER(COALESCE(dept_code,'')) = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$deptCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($counts as $key => $_) {
        $counts[$key] = (int)($row[$key] ?? 0);
    }

    $st = $pdo->prepare("
      SELECT p.id, p.paper_code, p.semester, p.academic_year, p.exam_type,
             p.status, p.updated_at, d.course_title, d.total_questions,
             d.staff_code AS submitting_staff
      FROM qps_submission_packages p
      LEFT JOIN qps_question_bank_drafts d ON d.id = p.bank_draft_id
      WHERE UPPER(COALESCE(p.dept_code,'')) = ?
        AND p.status <> 'DRAFT'
      ORDER BY p.updated_at DESC, p.id DESC
      LIMIT 8
    ");
    $st->execute([$deptCode]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('QPS HOD dashboard: ' . $e->getMessage());
    $error = 'Unable to load the HOD workflow summary. Please verify that the workflow and question-bank migrations have been applied.';
}

$base = getBaseUrl();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">
  <section class="rounded-3xl bg-gradient-to-r from-slate-950 via-slate-900 to-slate-800 text-white p-6 md:p-8 shadow-xl">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-5">
      <div>
        <div class="inline-flex rounded-full bg-blue-500/15 border border-blue-300/20 px-3 py-1 text-xs font-black uppercase tracking-wider text-blue-200">HOD Portal</div>
        <h1 class="text-2xl md:text-3xl font-black mt-3">Department Review Dashboard</h1>
        <p class="text-sm text-slate-300 mt-2">
          Welcome, <?= htmlspecialchars((string)($user['name'] ?? $staffCode), ENT_QUOTES, 'UTF-8') ?>.
          <?= htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') ?>
          <span class="text-slate-400">(<?= htmlspecialchars($deptCode, ENT_QUOTES, 'UTF-8') ?>)</span>
        </p>
      </div>
      <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/index.php"
         class="inline-flex items-center justify-center rounded-full bg-blue-600 hover:bg-blue-700 px-5 py-3 text-sm font-black text-white">
        Review pending submissions <span class="ml-2">→</span>
      </a>
    </div>
  </section>

  <?php if ($error): ?>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
    <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/index.php" class="rounded-2xl border bg-white p-5 shadow-sm hover:border-blue-300">
      <div class="text-xs font-bold text-slate-500">New submissions</div>
      <div class="mt-2 text-3xl font-black text-slate-900"><?= $counts['pending'] ?></div>
      <div class="mt-1 text-xs text-blue-700 font-bold">Awaiting HOD review →</div>
    </a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/index.php" class="rounded-2xl border bg-white p-5 shadow-sm hover:border-amber-300">
      <div class="text-xs font-bold text-slate-500">Resubmissions</div>
      <div class="mt-2 text-3xl font-black text-slate-900"><?= $counts['recheck'] ?></div>
      <div class="mt-1 text-xs text-amber-700 font-bold">Ready for re-verification</div>
    </a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/index.php?queue=dean_remarks" class="rounded-2xl border bg-white p-5 shadow-sm hover:border-purple-300">
      <div class="text-xs font-bold text-slate-500">Dean remarks</div>
      <div class="mt-2 text-3xl font-black text-slate-900"><?= $counts['dean_remarks'] ?></div>
      <div class="mt-1 text-xs text-purple-700 font-bold">Review returned remarks →</div>
    </a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/submitted.php" class="rounded-2xl border bg-white p-5 shadow-sm hover:border-emerald-300">
      <div class="text-xs font-bold text-slate-500">Forwarded / completed</div>
      <div class="mt-2 text-3xl font-black text-slate-900"><?= $counts['forwarded'] ?></div>
      <div class="mt-1 text-xs text-emerald-700 font-bold">Track submission history →</div>
    </a>
  </section>

  <section class="rounded-2xl border bg-white shadow-sm overflow-hidden">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4 border-b">
      <div>
        <h2 class="font-black text-slate-900">Recent department submissions</h2>
        <p class="text-xs text-slate-500 mt-1">Only submissions matching your department code are displayed.</p>
      </div>
      <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/submitted.php" class="text-xs font-black text-blue-700 hover:underline">View all history →</a>
    </div>
    <?php if (!$recent && !$error): ?>
      <div class="p-10 text-center text-sm text-slate-500">No submissions have been recorded for your department yet.</div>
    <?php elseif ($recent): ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-5 py-3 text-left">Course</th>
              <th class="px-5 py-3 text-left">Exam context</th>
              <th class="px-5 py-3 text-left">Staff</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
          <?php foreach ($recent as $item):
            $status = strtoupper((string)$item['status']);
            $statusClass = in_array($status, ['SUBMITTED_TO_HOD','HOD_RECHECK'], true)
              ? 'bg-amber-100 text-amber-800'
              : (in_array($status, ['SUBMITTED_TO_DEAN','SUBMITTED_TO_COE','COE_APPROVED','ALLOTTED','PAPER_GENERATED'], true)
                ? 'bg-emerald-100 text-emerald-800'
                : 'bg-purple-100 text-purple-800');
          ?>
            <tr>
              <td class="px-5 py-4">
                <div class="font-black text-slate-900"><?= htmlspecialchars((string)$item['paper_code'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($item['course_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td class="px-5 py-4 text-xs text-slate-600">
                <?= htmlspecialchars((string)$item['semester'], ENT_QUOTES, 'UTF-8') ?> ·
                <?= htmlspecialchars((string)$item['academic_year'], ENT_QUOTES, 'UTF-8') ?>
                <div class="text-slate-400"><?= htmlspecialchars((string)$item['exam_type'], ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td class="px-5 py-4 text-xs text-slate-600"><?= htmlspecialchars((string)($item['submitting_staff'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="px-5 py-4"><span class="inline-flex rounded-full px-3 py-1 text-[10px] font-black <?= $statusClass ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="px-5 py-4 text-right">
                <?php if (in_array($status, ['SUBMITTED_TO_HOD','HOD_RECHECK','DEAN_REMARKS'], true)): ?>
                  <a class="text-xs font-black text-blue-700 hover:underline" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/view.php?package_id=<?= (int)$item['id'] ?>&artifact=questions">Open review</a>
                <?php else: ?>
                  <a class="text-xs font-black text-slate-600 hover:underline" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/review/submitted.php">View history</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
