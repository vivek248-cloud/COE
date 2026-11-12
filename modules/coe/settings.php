<?php
define('PAGE_TITLE','COE Examination Settings');
require_once __DIR__.'/../../config/config.php';require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/system.php';require_once __DIR__.'/../../includes/workflow.php';
qps_workflow_require_role(['COE']);$pdo=getDBConnection();$user=getCurrentUser();if(empty($_SESSION['qps_review_csrf']))$_SESSION['qps_review_csrf']=bin2hex(random_bytes(32));$csrf=$_SESSION['qps_review_csrf'];$notice='';$error='';
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Security token expired.');
  $action=(string)($_POST['action']??'');
  if($action==='save_header'){
   $name=trim((string)($_POST['header_name']??''));$lines=$_POST['header_lines']??[];$lines=array_values(array_filter(array_map('trim',is_array($lines)?$lines:[]),static fn($v)=>$v!==''));
   if($name===''||!$lines)throw new RuntimeException('Enter a header name and at least one line.');
   $pdo->beginTransaction();
   if(!empty($_POST['is_default']))$pdo->exec('UPDATE qps_exam_headers SET is_default=0');
   $st=$pdo->prepare('INSERT INTO qps_exam_headers(name,is_default,created_by) VALUES(?,?,?)');$st->execute([$name,!empty($_POST['is_default'])?1:0,$user['staff_code']??'COE']);$hid=(int)$pdo->lastInsertId();
   $ins=$pdo->prepare('INSERT INTO qps_exam_header_lines(header_id,line_no,line_text) VALUES(?,?,?)');foreach($lines as $i=>$line)$ins->execute([$hid,$i+1,$line]);
   $pdo->commit();$notice='Exam header saved.';
  }elseif($action==='set_default'){
   $hid=(int)($_POST['header_id']??0);$pdo->beginTransaction();$pdo->exec('UPDATE qps_exam_headers SET is_default=0');$pdo->prepare('UPDATE qps_exam_headers SET is_default=1 WHERE id=?')->execute([$hid]);$pdo->commit();$notice='Default header updated.';
  }elseif($action==='save_paper_types'){
   throw new RuntimeException('Paper types are read-only in COE Settings. Use Admin Settings to manage paper types, marks and sections.');
  }
 }
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
$headers=$pdo->query('SELECT * FROM qps_exam_headers ORDER BY is_default DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
foreach($headers as &$h){$st=$pdo->prepare('SELECT line_no,line_text FROM qps_exam_header_lines WHERE header_id=? ORDER BY line_no');$st->execute([$h['id']]);$h['lines']=$st->fetchAll(PDO::FETCH_ASSOC);}unset($h);
$paperTypes = hcc_paper_types($pdo);
// Normalize only complete section configurations; legacy types with blank patterns remain visible but untouched.
$completeTypes = array_filter($paperTypes, static function($type) {
    if (empty($type['sections']) || !is_array($type['sections'])) return false;
    foreach ($type['sections'] as $section) if (trim((string)($section['pattern'] ?? '')) === '') return false;
    return true;
});
if ($completeTypes) {
    try { $normalizedCompleteTypes = qps_normalize_paper_types($completeTypes); $paperTypes = array_replace($paperTypes, $normalizedCompleteTypes); }
    catch (RuntimeException $ignored) { /* Display saved values read-only; admin save remains the validator. */ }
}
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/navbar.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-6"><div><h1 class="text-3xl font-black">COE Settings</h1><p class="text-sm text-slate-500">Configure official exam headers, paper types, marks and section instructions.</p></div>
<?php if($notice):?><div class="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 p-3 text-sm"><?=htmlspecialchars($notice)?></div><?php endif;?><?php if($error):?><div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 p-3 text-sm"><?=htmlspecialchars($error)?></div><?php endif;?>
<section class="bg-white border rounded-2xl p-5 space-y-4"><h2 class="text-xl font-black">Exam Header Builder</h2><form method="post" class="space-y-3"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="save_header"><input name="header_name" required placeholder="Header name (e.g. November 2026)" class="w-full rounded-xl border-slate-300"><div id="headerLines" class="space-y-2"><input name="header_lines[]" required value="Holy Cross College Trichy-2" class="w-full rounded-xl border-slate-300"><input name="header_lines[]" value="Semester Examination, November-2026" class="w-full rounded-xl border-slate-300"></div><button type="button" id="addHeaderLine" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-bold">+ Add line</button><label class="flex gap-2 text-sm font-bold"><input type="checkbox" name="is_default" value="1"> Set as default header</label><button class="px-4 py-3 rounded-xl bg-indigo-600 text-white font-black">Save Header</button></form>
<div class="grid md:grid-cols-2 gap-3"><?php foreach($headers as $h):?><article class="border rounded-xl p-4"><div class="flex justify-between"><strong><?=htmlspecialchars($h['name'])?></strong><?php if($h['is_default']):?><span class="text-xs font-black text-emerald-700">Default</span><?php endif;?></div><ol class="list-decimal pl-5 mt-2 text-sm text-slate-600"><?php foreach($h['lines'] as $line):?><li><?=htmlspecialchars($line['line_text'])?></li><?php endforeach;?></ol><?php if(!$h['is_default']):?><form method="post" class="mt-3"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="set_default"><input type="hidden" name="header_id" value="<?=(int)$h['id']?>"><button class="text-xs font-bold text-indigo-700">Make default</button></form><?php endif;?></article><?php endforeach;?></div></section>
<section class="bg-white border rounded-2xl p-5 space-y-4">
 <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-black">Paper Types, Marks & Sections</h2><p class="text-sm text-slate-500">Read-only configuration. Changes are managed centrally by the administrator.</p></div><a href="<?=htmlspecialchars(getBaseUrl(), ENT_QUOTES)?>/modules/admin/index.php?tab=settings" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-300 px-4 py-2.5 text-sm font-black text-white"><i data-lucide="external-link" class="w-4 h-4"></i> Open Admin Settings</a></div>
 <div class="grid md:grid-cols-2 gap-3">
 <?php foreach ($paperTypes as $type): ?>
  <article class="rounded-xl border border-slate-200 p-4 space-y-3 hover:shadow-sm transition">
   <div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-black text-slate-900"><?=htmlspecialchars($type['label'])?></h3><p class="text-xs text-slate-500 font-mono"><?=htmlspecialchars($type['code'])?> · <?=htmlspecialchars($type['layout']??'')?></p></div><span class="rounded-full bg-indigo-50 text-indigo-700 px-3 py-1 text-xs font-black"><?= (int)$type['total_marks'] ?> marks</span></div>
   <?php if (!empty($type['sections']) && is_array($type['sections'])): ?><div class="space-y-2"><?php foreach ($type['sections'] as $section): ?><div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 p-3 text-xs"><span class="font-bold"><?=htmlspecialchars($section['title']??('Section '.($section['code']??'')))?></span><span class="font-mono text-slate-600"><?=htmlspecialchars($section['pattern']??'Pattern not configured')?></span><?php if(!empty($section['q_start'])&&!empty($section['q_end'])):?><span class="text-slate-500">Q<?= (int)$section['q_start'] ?>–<?= (int)$section['q_end'] ?></span><?php endif;?></div><?php endforeach;?></div><?php else: ?><p class="text-xs text-amber-700">Legacy paper type — section patterns are not configured.</p><?php endif; ?>
   <p class="text-xs text-slate-500">Shuffle ranges: <span class="font-mono"><?=htmlspecialchars((string)($type['shuffle_ranges']??''))?></span><?php if(isset($type['slot_count'])):?> · <?= (int)$type['slot_count'] ?> question slots<?php endif;?></p>
  </article>
 <?php endforeach; ?>
 </div>
</section></main>

<script>
document.getElementById('addHeaderLine').onclick=()=>{const i=document.createElement('input');i.name='header_lines[]';i.className='w-full rounded-xl border-slate-300';i.placeholder='Additional header line';document.getElementById('headerLines').appendChild(i);};
</script>
<?php require_once __DIR__.'/../../includes/footer.php';?>
