<?php
declare(strict_types=1);
define('PAGE_TITLE','COE Question Bank Repository');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
requireCOE();
$pdo=getDBConnection();
$user=getCurrentUser();
$course=trim((string)($_GET['course_code']??''));
$status=trim((string)($_GET['status']??''));
$year=trim((string)($_GET['academic_year']??''));
$search=trim((string)($_GET['q']??''));
$bankId=(int)($_GET['bank_id']??0);
$params=[];
$sql="SELECT qb.*, COALESCE(s.FIRST_NAME,qb.staff_code) staff_name,
       (SELECT COUNT(*) FROM questions q WHERE q.bank_id=qb.id) actual_questions
       FROM question_banks qb
       LEFT JOIN pr_x_xxxx_staf_prof_mast s ON UPPER(s.STAFF_CODE)=UPPER(qb.staff_code)
       WHERE 1=1";
if($course!==''){ $sql.=" AND UPPER(qb.paper_code)=UPPER(?)"; $params[]=$course; }
if($status!==''){ $sql.=" AND UPPER(qb.status)=UPPER(?)"; $params[]=$status; }
if($year!==''){ $sql.=" AND qb.academic_year=?"; $params[]=$year; }
if($search!==''){ $sql.=" AND (qb.paper_code LIKE ? OR qb.course_title LIKE ? OR qb.staff_code LIKE ? OR s.FIRST_NAME LIKE ?)"; array_push($params,"%$search%","%$search%","%$search%","%$search%"); }
$sql.=" ORDER BY qb.id DESC";
$st=$pdo->prepare($sql);$st->execute($params);$banks=$st->fetchAll(PDO::FETCH_ASSOC);
$courses=$pdo->query("SELECT DISTINCT paper_code FROM question_banks WHERE paper_code IS NOT NULL AND paper_code<>'' ORDER BY paper_code")->fetchAll(PDO::FETCH_COLUMN);
$years=$pdo->query("SELECT DISTINCT academic_year FROM question_banks WHERE academic_year IS NOT NULL AND academic_year<>'' ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$detail=null;$detailQuestions=[];
if($bankId>0){
  $ds=$pdo->prepare("SELECT qb.*, COALESCE(s.FIRST_NAME,qb.staff_code) staff_name FROM question_banks qb LEFT JOIN pr_x_xxxx_staf_prof_mast s ON UPPER(s.STAFF_CODE)=UPPER(qb.staff_code) WHERE qb.id=? LIMIT 1");
  $ds->execute([$bankId]);$detail=$ds->fetch(PDO::FETCH_ASSOC);
  if($detail){$qs=$pdo->prepare("SELECT * FROM questions WHERE bank_id=? ORDER BY q_number,id");$qs->execute([$bankId]);$detailQuestions=$qs->fetchAll(PDO::FETCH_ASSOC);}
}
include __DIR__.'/../../includes/header.php';
include __DIR__.'/../../includes/navbar.php';
?>
<div class="flex min-h-[calc(100vh-64px)] bg-[#F8F9FA]"><?php include __DIR__.'/../../includes/sidebar.php'; ?>
<main class="flex-1 p-4 md:p-8 overflow-y-auto space-y-6">
  <section class="bg-gradient-to-r from-[#111827] via-slate-900 to-indigo-950 rounded-[28px] p-7 text-white shadow-xl border border-slate-800">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div><div class="inline-flex items-center gap-2 bg-emerald-400/15 text-emerald-300 border border-emerald-400/25 rounded-full px-3 py-1 text-[10px] font-black uppercase">COE Repository</div>
      <h1 class="text-2xl md:text-3xl font-black mt-2">View Question Banks</h1>
      <p class="text-slate-300 text-xs mt-1">Browse verified question banks, inspect questions, units, sections, K-levels, COs and marks without entering the staff upload workflow.</p></div>
      <a href="<?php echo getBaseUrl(); ?>/modules/coe/blueprint.php" class="bg-amber-400 text-slate-950 px-5 py-2.5 rounded-full text-xs font-black">Open 30Q Blueprint</a>
    </div>
  </section>

  <section class="bg-white rounded-[28px] border border-stone-200 shadow-sm p-5">
    <form class="grid grid-cols-1 md:grid-cols-4 gap-3" method="get">
      <input name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search paper code, title or staff..." class="rounded-xl border border-stone-300 bg-stone-50 p-2.5 text-xs font-bold">
      <select name="course_code" class="rounded-xl border border-stone-300 bg-stone-50 p-2.5 text-xs font-bold"><option value="">All Paper Codes</option><?php foreach($courses as $c): ?><option value="<?php echo htmlspecialchars($c); ?>" <?php echo strcasecmp($course,$c)===0?'selected':''; ?>><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?></select>
      <select name="academic_year" class="rounded-xl border border-stone-300 bg-stone-50 p-2.5 text-xs font-bold"><option value="">All Academic Years</option><?php foreach($years as $y): ?><option <?php echo $year===$y?'selected':''; ?>><?php echo htmlspecialchars($y); ?></option><?php endforeach; ?></select>
      <div class="flex gap-2"><select name="status" class="flex-1 rounded-xl border border-stone-300 bg-stone-50 p-2.5 text-xs font-bold"><option value="">All Status</option><option value="Submitted to COE" <?php echo strcasecmp($status,'Submitted to COE')===0?'selected':''; ?>>Submitted to COE</option><option value="Approved" <?php echo strcasecmp($status,'Approved')===0?'selected':''; ?>>Approved</option><option value="Verified" <?php echo strcasecmp($status,'Verified')===0?'selected':''; ?>>Verified</option></select><button class="rounded-xl bg-indigo-600 text-white px-4 text-xs font-black">Filter</button></div>
    </form>
  </section>

  <?php if($detail): ?>
  <section class="bg-white rounded-[28px] border border-emerald-200 shadow-sm overflow-hidden">
    <div class="p-5 bg-emerald-50 border-b border-emerald-200 flex flex-col md:flex-row md:items-center justify-between gap-3"><div><div class="text-[10px] uppercase font-black text-emerald-700">Question Bank #<?php echo (int)$detail['id']; ?></div><h2 class="text-lg font-black text-slate-900"><?php echo htmlspecialchars($detail['paper_code'].' — '.$detail['course_title']); ?></h2><p class="text-[11px] text-slate-600"><?php echo htmlspecialchars($detail['semester'].' • '.$detail['academic_year'].' • '.$detail['exam_type'].' • '.$detail['staff_name']); ?></p></div><a href="<?php echo getBaseUrl(); ?>/modules/coe/view_question_banks.php" class="rounded-full bg-white border border-stone-300 px-4 py-2 text-xs font-black">Back to Repository</a></div>
    <div class="overflow-x-auto"><table class="w-full min-w-[1150px] text-[11px] border-collapse"><thead class="bg-slate-950 text-white uppercase font-black"><tr><th class="p-3">Q.No</th><th class="p-3">Unit</th><th class="p-3">Sub-Unit</th><th class="p-3">Section</th><th class="p-3">Type</th><th class="p-3">K</th><th class="p-3">CO</th><th class="p-3">Marks</th><th class="p-3 text-left">Question</th></tr></thead><tbody class="divide-y divide-stone-200"><?php foreach($detailQuestions as $q): ?><tr class="hover:bg-sky-50"><td class="p-3 font-mono font-black text-indigo-700"><?php echo htmlspecialchars((string)$q['q_number']); ?></td><td class="p-3 font-black">Unit <?php echo htmlspecialchars((string)$q['unit_no']); ?></td><td class="p-3 font-bold text-indigo-700"><?php echo htmlspecialchars((string)$q['sub_unit']); ?></td><td class="p-3 font-black"><?php echo htmlspecialchars((string)$q['section_type']); ?></td><td class="p-3"><?php echo htmlspecialchars((string)($q['question_type']??'')); ?></td><td class="p-3 font-black"><?php echo htmlspecialchars((string)$q['k_level']); ?></td><td class="p-3 font-black text-violet-700"><?php echo htmlspecialchars((string)$q['co_level']); ?></td><td class="p-3 font-black"><?php echo (int)$q['marks']; ?></td><td class="p-3 text-left whitespace-pre-wrap max-w-[700px]"><?php echo htmlspecialchars((string)$q['question_text']); ?></td></tr><?php endforeach; ?></tbody></table></div>
  </section>
  <?php else: ?>
  <section class="bg-white rounded-[28px] border border-stone-200 shadow-sm overflow-hidden"><div class="p-5 border-b border-stone-100 flex items-center justify-between"><div><h2 class="font-black text-slate-900">Verified Question Banks</h2><p class="text-[11px] text-slate-500"><?php echo count($banks); ?> bank(s) found. Open a bank to inspect every stored question.</p></div></div><div class="overflow-x-auto"><table class="w-full min-w-[1050px] text-xs border-collapse"><thead class="bg-slate-950 text-white uppercase text-[10px] font-black"><tr><th class="p-3">Bank</th><th class="p-3">Staff</th><th class="p-3">Paper Code & Title</th><th class="p-3">Semester / Year</th><th class="p-3">Questions</th><th class="p-3">Status</th><th class="p-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-stone-200"><?php if(!$banks): ?><tr><td colspan="7" class="p-12 text-center text-slate-400 font-bold">No question banks found.</td></tr><?php endif; ?><?php foreach($banks as $b): ?><tr class="hover:bg-slate-50"><td class="p-3 font-mono font-black text-indigo-700">#<?php echo (int)$b['id']; ?></td><td class="p-3 font-bold"><?php echo htmlspecialchars((string)$b['staff_name']); ?><div class="text-[9px] text-slate-500 font-mono"><?php echo htmlspecialchars((string)$b['staff_code']); ?></div></td><td class="p-3"><div class="font-black text-slate-900"><?php echo htmlspecialchars((string)$b['paper_code']); ?></div><div class="text-[10px] text-slate-500"><?php echo htmlspecialchars((string)$b['course_title']); ?></div></td><td class="p-3"><?php echo htmlspecialchars((string)$b['semester']); ?><div class="text-[10px] text-slate-500"><?php echo htmlspecialchars((string)$b['academic_year']); ?></div></td><td class="p-3 font-black text-emerald-700"><?php echo (int)$b['actual_questions']; ?></td><td class="p-3"><span class="rounded-full bg-emerald-100 text-emerald-700 px-2.5 py-1 text-[10px] font-black"><?php echo htmlspecialchars((string)$b['status']); ?></span></td><td class="p-3 text-right"><a href="?bank_id=<?php echo (int)$b['id']; ?>" class="rounded-xl bg-indigo-600 text-white px-3 py-2 text-[11px] font-black">View Questions</a></td></tr><?php endforeach; ?></tbody></table></div></section>
  <?php endif; ?>
</main></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
