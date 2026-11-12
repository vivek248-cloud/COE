<?php
/**
 * Additive Dean Portal dashboard.
 * Uses the existing staff session, qps_dean_users authorization registry,
 * and qps_question_bank_drafts workflow. No existing module is modified.
 */
define('PAGE_TITLE', 'Dean Review Dashboard');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
requireDean();

$pdo = getDBConnection();
$user = getCurrentUser();
if (empty($_SESSION['qps_dean_csrf'])) {
    $_SESSION['qps_dean_csrf'] = bin2hex(random_bytes(32));
}

$drafts = [];
$error = '';
try {
    // Only submissions explicitly routed to Dean are shown here.
    $stmt = $pdo->prepare("SELECT d.*,
        (SELECT COUNT(*) FROM qps_question_bank_draft_questions q WHERE q.draft_id = d.id) AS question_count,
        (SELECT COUNT(*) FROM qps_question_bank_draft_questions q WHERE q.draft_id = d.id AND q.correction_status = 'NEEDS_CORRECTION') AS correction_count
      FROM qps_question_bank_drafts d
      WHERE d.status = 'SUBMITTED_TO_DEAN'
      ORDER BY COALESCE(d.dept_name, d.dept_code), d.paper_code, d.id DESC");
    $stmt->execute();
    $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Unable to load Dean submissions. Confirm the question-bank draft migrations have been run.';
}

$groups = [];
foreach ($drafts as $draft) {
    $department = trim((string)($draft['dept_name'] ?? ''));
    if ($department === '') $department = trim((string)($draft['dept_code'] ?? 'Unassigned')) ?: 'Unassigned';
    $groups[$department][] = $draft;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">
  <div class="max-w-7xl mx-auto px-4 pt-5 flex flex-wrap gap-2">
    <a href="<?= htmlspecialchars(getBaseUrl()) ?>/modules/dean/question_rules.php" class="rounded-xl bg-purple-600 text-white px-4 py-3 text-sm font-black">Manage K-Level / CO-Level Rules</a>
    <a href="<?= htmlspecialchars(getBaseUrl()) ?>/modules/review/index.php" class="rounded-xl bg-indigo-600 text-white px-4 py-3 text-sm font-black">Open HOD Submitted Courses</a>
  </div>

  <section class="rounded-3xl bg-slate-950 text-white p-6 md:p-8 shadow-xl">
    <div class="text-xs uppercase tracking-[.2em] font-black text-purple-300">Dean Portal</div>
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mt-2">
      <div><h1 class="text-3xl font-black">Academic Review</h1>
        <p class="text-sm text-slate-300 mt-1">Review course submissions routed by the HOD before forwarding them to COE.</p></div>
      <div class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3">
        <div class="text-xs text-slate-300">Signed in as</div>
        <div class="font-bold"><?= htmlspecialchars((string)($user['name'] ?? $user['staff_code'] ?? 'Dean')) ?></div>
        <div class="text-xs text-purple-200"><?= htmlspecialchars((string)$user['staff_code']) ?></div>
      </div>
    </div>
  </section>

  <?php if ($error): ?><div class="rounded-xl bg-red-50 border border-red-200 text-red-700 p-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="bg-white border rounded-2xl p-5"><div class="text-xs text-slate-500">Pending courses</div><div class="text-3xl font-black mt-1"><?= count($drafts) ?></div></div>
    <div class="bg-white border rounded-2xl p-5"><div class="text-xs text-slate-500">Departments</div><div class="text-3xl font-black mt-1"><?= count($groups) ?></div></div>
    <div class="bg-white border rounded-2xl p-5"><div class="text-xs text-slate-500">Questions awaiting correction</div><div class="text-3xl font-black mt-1"><?= array_sum(array_map(static fn($d) => (int)$d['correction_count'], $drafts)) ?></div></div>
  </section>

  <?php if (!$drafts && !$error): ?>
    <section class="bg-white border rounded-2xl p-12 text-center"><div class="text-4xl mb-3">✓</div><h2 class="font-black text-xl">No courses waiting for Dean review</h2><p class="text-sm text-slate-500 mt-2">Courses will appear here after the HOD submits them to the Dean.</p></section>
  <?php endif; ?>

  <?php foreach ($groups as $department => $items): ?>
    <section class="bg-white border rounded-2xl overflow-hidden shadow-sm">
      <header class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between"><h2 class="font-black"><?= htmlspecialchars($department) ?></h2><span class="text-xs bg-white/10 rounded-full px-3 py-1"><?= count($items) ?> course(s)</span></header>
      <div class="divide-y">
        <?php foreach ($items as $d): ?>
          <article class="p-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="min-w-0"><div class="font-black text-slate-900"><?= htmlspecialchars((string)$d['paper_code']) ?></div>
              <div class="text-sm text-slate-600 mt-1"><?= htmlspecialchars((string)($d['course_title'] ?? '')) ?></div>
              <div class="text-xs text-slate-500 mt-2"><?= htmlspecialchars((string)$d['semester']) ?> · <?= htmlspecialchars((string)$d['academic_year']) ?> · Staff: <?= htmlspecialchars((string)$d['staff_code']) ?></div>
              <div class="text-xs text-slate-500 mt-1"><?= (int)$d['question_count'] ?> questions · <?= (int)$d['correction_count'] ?> correction flag(s)</div>
            </div>
            <a class="inline-flex justify-center items-center rounded-xl bg-purple-700 hover:bg-purple-800 text-white font-bold text-sm px-5 py-3" href="review.php?draft_id=<?= (int)$d['id'] ?>">Review submission →</a>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
