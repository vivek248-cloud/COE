<?php
define('PAGE_TITLE','My Corrections');
require_once __DIR__.'/../../config/config.php';require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/system.php';require_once __DIR__.'/../../includes/workflow.php';
requireAuth();$pdo=getDBConnection();$user=getCurrentUser();if(empty($_SESSION['qps_static_role']))$_SESSION['qps_portal_role']='STAFF';
if(!in_array(qps_workflow_role(),['STAFF','HOD','DEAN'],true)){http_response_code(403);exit('Teaching portal access denied.');}
$st=$pdo->prepare("SELECT r.*,p.paper_code,p.bank_draft_id,d.course_title FROM qps_remarks r JOIN qps_submission_packages p ON p.id=r.package_id LEFT JOIN qps_question_bank_drafts d ON d.id=p.bank_draft_id WHERE p.staff_code=? AND r.to_role='STAFF' AND r.status='OPEN' ORDER BY r.created_at DESC");$st->execute([$user['staff_code']]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/navbar.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-6xl mx-auto px-4 py-6 space-y-5"><div><h1 class="text-3xl font-black">Corrections</h1><p class="text-sm text-slate-500">Questions and blueprint items returned by HOD or Dean. Correct the source course and resubmit all three artifacts.</p></div>
<?php if(!$rows):?><div class="border border-dashed rounded-2xl p-10 text-center text-slate-500">No open corrections.</div><?php endif;?>
<?php foreach($rows as $r):?><article class="bg-white border border-rose-200 rounded-2xl p-5"><div class="flex flex-wrap justify-between gap-2"><h2 class="text-xl font-black"><?=htmlspecialchars($r['paper_code'])?></h2><span class="rounded-full bg-rose-100 text-rose-800 px-3 py-1 text-xs font-black"><?=htmlspecialchars($r['target_type'])?></span></div><p class="text-sm text-slate-700 mt-2"><?=nl2br(htmlspecialchars($r['remark_text']))?></p><p class="text-xs text-slate-400 mt-2">From <?=htmlspecialchars($r['author_role'].' · '.$r['author_name']??'Reviewer')?> · <?=htmlspecialchars($r['created_at'])?></p><a class="inline-flex mt-4 px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-black" href="draft_questions.php?paper_code=<?=rawurlencode($r['paper_code'])?>">View & Correct Course</a></article><?php endforeach;?></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
