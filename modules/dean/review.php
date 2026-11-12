<?php
/** Dean review of one existing draft submission. */
define('PAGE_TITLE', 'Dean Course Review');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
requireDean();
$pdo = getDBConnection();
if (empty($_SESSION['qps_dean_csrf'])) $_SESSION['qps_dean_csrf'] = bin2hex(random_bytes(32));
$draftId = filter_input(INPUT_GET, 'draft_id', FILTER_VALIDATE_INT);
if (!$draftId) { http_response_code(400); exit('Invalid draft ID.'); }
$stmt = $pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE id = ? AND status = 'SUBMITTED_TO_DEAN' LIMIT 1");
$stmt->execute([$draftId]);
$draft = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$draft) { http_response_code(404); exit('This submission is not currently available for Dean review.'); }
$stmt = $pdo->prepare('SELECT * FROM qps_question_bank_draft_questions WHERE draft_id = ? ORDER BY q_number, id');
$stmt->execute([$draftId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pendingCorrections = count(array_filter($questions, static fn($q) => ($q['correction_status'] ?? '') === 'NEEDS_CORRECTION'));
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-5">
  <a href="dashboard.php" class="text-sm font-bold text-purple-700">← Dean dashboard</a>
  <section class="bg-white border rounded-2xl p-5 md:p-6">
    <div class="flex flex-col md:flex-row md:justify-between gap-3"><div><div class="text-xs uppercase font-black tracking-wider text-purple-700">Dean course review</div><h1 class="text-2xl font-black mt-1"><?= htmlspecialchars((string)$draft['paper_code']) ?></h1><p class="text-slate-600 text-sm mt-1"><?= htmlspecialchars((string)($draft['course_title'] ?? '')) ?></p></div><div class="text-sm text-slate-500"><?= htmlspecialchars((string)$draft['dept_name']) ?> · <?= htmlspecialchars((string)$draft['semester']) ?> · <?= htmlspecialchars((string)$draft['academic_year']) ?></div></div>
    <div class="mt-4 flex flex-wrap gap-2 text-xs"><span class="rounded-full bg-slate-100 px-3 py-1">Staff: <?= htmlspecialchars((string)$draft['staff_code']) ?></span><span class="rounded-full bg-slate-100 px-3 py-1"><?= count($questions) ?> questions</span><span class="rounded-full <?= $pendingCorrections ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' ?> px-3 py-1"><?= $pendingCorrections ?> correction(s) pending</span></div>
  </section>
  <?php if (isset($_GET['msg'])): ?><div class="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 p-3 text-sm"><?= htmlspecialchars((string)$_GET['msg']) ?></div><?php endif; ?>
  <?php foreach ($questions as $q): ?>
    <article class="bg-white border rounded-2xl p-5 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-black">Question <?= (int)$q['q_number'] ?> <span class="text-xs font-normal text-slate-500">· <?= (int)$q['marks'] ?> mark(s)</span></h2><span class="text-xs rounded-full px-3 py-1 <?= ($q['correction_status'] ?? '') === 'NEEDS_CORRECTION' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' ?>"><?= htmlspecialchars((string)($q['correction_status'] ?? 'CLEAN')) ?></span></div>
      <div class="text-sm text-slate-800 whitespace-pre-wrap"><?= htmlspecialchars((string)$q['question_text']) ?></div>
      <div class="text-xs text-slate-500">Unit <?= htmlspecialchars((string)$q['unit_no']) ?> · Subunit <?= htmlspecialchars((string)$q['sub_unit']) ?> · <?= htmlspecialchars((string)$q['question_type']) ?> · <?= htmlspecialchars((string)$q['k_level']) ?> · <?= htmlspecialchars((string)$q['co_level']) ?></div>
      <?php if (!empty($q['options_json'])): ?><details class="text-sm"><summary class="cursor-pointer font-bold text-slate-600">View options</summary><pre class="mt-2 whitespace-pre-wrap text-xs bg-slate-50 rounded-lg p-3"><?= htmlspecialchars((string)$q['options_json']) ?></pre></details><?php endif; ?>
      <?php if (!empty($q['match_column_a']) || !empty($q['match_column_b'])): ?><details class="text-sm"><summary class="cursor-pointer font-bold text-slate-600">View matching columns</summary><div class="grid md:grid-cols-2 gap-3 mt-2"><pre class="whitespace-pre-wrap bg-slate-50 rounded-lg p-3 text-xs"><?= htmlspecialchars((string)$q['match_column_a']) ?></pre><pre class="whitespace-pre-wrap bg-slate-50 rounded-lg p-3 text-xs"><?= htmlspecialchars((string)$q['match_column_b']) ?></pre></div></details><?php endif; ?>
      <?php if (!empty($q['image_url'])): ?><a class="text-sm text-blue-700 underline" target="_blank" rel="noopener" href="<?= htmlspecialchars((string)$q['image_url']) ?>">Open attached diagram</a><?php endif; ?>
      <?php if (!empty($q['remarks'])): ?><div class="rounded-xl bg-amber-50 border border-amber-200 p-3"><div class="text-xs font-black text-amber-800">Current correction remark</div><p class="text-sm whitespace-pre-wrap mt-1"><?= htmlspecialchars((string)$q['remarks']) ?></p></div><?php endif; ?>
      <form method="post" action="actions.php" class="border-t pt-3 space-y-2">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['qps_dean_csrf']) ?>"><input type="hidden" name="action" value="remark"><input type="hidden" name="draft_id" value="<?= (int)$draftId ?>"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
        <label class="block text-xs font-bold text-slate-600">Correction remark for teaching staff</label><textarea name="remarks" required maxlength="5000" rows="2" class="w-full border border-slate-200 rounded-xl p-3 text-sm" placeholder="Explain exactly what should be corrected..."></textarea>
        <button class="rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs px-4 py-2" type="submit">Send correction remark</button>
      </form>
      <?php if (($q['correction_status'] ?? '') === 'NEEDS_CORRECTION'): ?>
      <form method="post" action="actions.php" class="border-t pt-3"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['qps_dean_csrf']) ?>"><input type="hidden" name="action" value="verify_corrected"><input type="hidden" name="draft_id" value="<?= (int)$draftId ?>"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>"><button class="rounded-lg border border-emerald-300 text-emerald-800 hover:bg-emerald-50 font-bold text-xs px-4 py-2" type="submit">Verify corrected version</button><span class="text-xs text-slate-500 ml-2">Available only after staff saves a newer question version.</span></form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  <section class="bg-white border rounded-2xl p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4"><div><h2 class="font-black">Forward to COE</h2><p class="text-xs text-slate-500 mt-1">All question correction flags must be resolved before forwarding.</p></div><form method="post" action="actions.php" onsubmit="return confirm('Submit this reviewed course to COE?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['qps_dean_csrf']) ?>"><input type="hidden" name="action" value="submit_coe"><input type="hidden" name="draft_id" value="<?= (int)$draftId ?>"><button <?= $pendingCorrections || !$questions ? 'disabled' : '' ?> class="rounded-xl px-5 py-3 font-black text-sm text-white <?= $pendingCorrections || !$questions ? 'bg-slate-300 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700' ?>">Approve &amp; submit to COE</button></form></section>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
