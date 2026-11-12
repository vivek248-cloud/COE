<?php
define('PAGE_TITLE','COE Submission Status');
require_once __DIR__.'/../../config/config.php';require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/system.php';require_once __DIR__.'/../../includes/workflow.php';
requireDean();$pdo=getDBConnection();
$st=$pdo->query("SELECT p.*,d.course_title,d.dept_name FROM qps_submission_packages p LEFT JOIN qps_question_bank_drafts d ON d.id=p.bank_draft_id WHERE p.status IN ('SUBMITTED_TO_COE','COE_APPROVED','ALLOTTED','PAPER_GENERATED') ORDER BY p.updated_at DESC");$rows=$st->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/navbar.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-5"><div><h1 class="text-3xl font-black">COE Submission Status</h1><p class="text-sm text-slate-500">Track every course approved and forwarded by the Dean.</p></div>
<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4"><?php foreach($rows as $r):?><article class="rounded-2xl border bg-white p-5 shadow-sm"><h2 class="text-xl font-black"><?=htmlspecialchars($r['paper_code'])?></h2><p class="text-sm text-slate-600"><?=htmlspecialchars($r['course_title']??'')?></p><p class="text-xs text-slate-500 mt-2"><?=htmlspecialchars(($r['dept_name']??$r['dept_code']??'').' · '.$r['semester'].' · '.$r['academic_year'])?></p><div class="mt-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-black <?=$r['status']==='PAPER_GENERATED'?'bg-emerald-100 text-emerald-800':($r['status']==='SUBMITTED_TO_COE'?'bg-amber-100 text-amber-800':'bg-indigo-100 text-indigo-800')?>"><?=htmlspecialchars($r['status'])?></span></div></article><?php endforeach;?></div>
<?php if(!$rows):?><div class="rounded-2xl border border-dashed p-10 text-center text-slate-500">No courses have been submitted to COE yet.</div><?php endif;?></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
