<?php
define('PAGE_TITLE','Course Review Queue');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
require_once __DIR__.'/../../includes/workflow.php';
requireAuth();
$role=qps_workflow_role();
if($role==='DEAN') requireDean();
elseif($role==='COE') qps_workflow_require_role(['COE']);
elseif($role==='STAFF' && (strtoupper((string)(getCurrentUser()['role']??''))==='HOD' || !empty(getCurrentUser()['is_hod'])) && empty(getCurrentUser()['is_erp_staff'])) { $_SESSION['qps_portal_role']='HOD'; $role='HOD'; }
elseif($role!=='HOD'){http_response_code(403);exit('HOD, Dean or COE access required.');}
$pdo=getDBConnection();
if(empty($_SESSION['qps_review_csrf']))$_SESSION['qps_review_csrf']=bin2hex(random_bytes(32));
$queue=(string)($_GET['queue']??'');
$statuses=$role==='HOD'?($queue==='dean_remarks'?['DEAN_REMARKS']:['SUBMITTED_TO_HOD','HOD_RECHECK']):($role==='DEAN'?['SUBMITTED_TO_DEAN']:['SUBMITTED_TO_COE']);
$ph=implode(',',array_fill(0,count($statuses),'?'));
$sql="SELECT p.*,d.course_title,d.dept_name,d.total_questions FROM qps_submission_packages p
 LEFT JOIN qps_question_bank_drafts d ON d.id=p.bank_draft_id
 WHERE p.status IN ($ph)";
$params=$statuses;
if($role==='HOD'){
  $currentUser=getCurrentUser();$dept=(string)($currentUser['dept_code']??'');
  if($dept==='') $sql.=' AND 1=0'; else { $sql.=" AND UPPER(COALESCE(p.dept_code,''))=UPPER(?)"; $params[]=$dept; }
}
$sql.=" ORDER BY COALESCE(d.dept_name,p.dept_code),p.paper_code,p.id DESC";
$st=$pdo->prepare($sql);$st->execute($params);$packages=$st->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/navbar.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-5">
 <div><p class="text-xs uppercase tracking-widest font-black text-indigo-600"><?=htmlspecialchars($role)?> Review</p><h1 class="text-3xl font-black text-slate-900"><?=$role==='HOD'?($queue==='dean_remarks'?'Remarks from Deans':'Staff Submitted Courses'):($role==='DEAN'?'HOD Submitted Courses':'Dean Submitted Courses — COE Review')?></h1><p class="text-sm text-slate-500 mt-1">Review the question bank, question bank blueprint and final question paper blueprint as one submission package.</p></div>
 <?php if(!$packages):?><div class="rounded-2xl border border-dashed p-10 text-center text-slate-500">No courses are currently waiting for your review.</div><?php endif;?>
 <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
 <?php foreach($packages as $p):?><article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition">
  <h1 class="text-2xl font-black text-slate-900"><?=htmlspecialchars($p['paper_code'])?></h1><p class="font-semibold text-slate-700 mt-1"><?=htmlspecialchars($p['course_title']??'')?></p>
  <p class="text-xs text-slate-500 mt-2"><?=htmlspecialchars(($p['dept_name']??$p['dept_code']??'Department not set').' · '.$p['semester'].' · '.$p['academic_year'])?></p>
  <div class="flex flex-wrap gap-2 mt-4">
   <?php foreach(['questions'=>'Question Bank','qbb'=>'Question Bank Blueprint','final'=>'Final Blueprint'] as $artifact=>$label):?><a href="view.php?package_id=<?=(int)$p['id']?>&artifact=<?=$artifact?>" class="rounded-xl bg-slate-100 hover:bg-indigo-50 text-slate-800 hover:text-indigo-700 px-3 py-2 text-xs font-black"><?=$label?></a><?php endforeach;?>
  </div>
  <div class="mt-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-black <?=$role==='HOD'?'bg-amber-100 text-amber-800':'bg-indigo-100 text-indigo-800'?>"><?=htmlspecialchars($p['status'])?></span></div>
 </article><?php endforeach;?>
 </div>
</main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
