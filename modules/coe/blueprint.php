<?php
declare(strict_types=1);

define('PAGE_TITLE', 'COE Final 30-Question Blueprint');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireCOE();
$pdo = getDBConnection();
$user = getCurrentUser() ?: ['staff_code'=>'COE_OFFICE','role'=>'COE_ADMIN'];
$baseUrl = getBaseUrl();

function bp_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$examTypes = function_exists('hcc_exam_types') ? hcc_exam_types() : [
    'Internal 1'=>'Internal 1 (CIA-I)',
    'Internal 2'=>'Internal 2 (CIA-II)',
    'Odd Semester End Examination'=>'Odd Semester End Examination (Nov/Dec)',
    'Even Semester End Examination'=>'Even Semester End Examination (Apr/May)',
    'Year'=>'Year End Examination'
];

$selectedCourse = strtoupper(trim((string)($_GET['course_code'] ?? $_POST['paper_code'] ?? '')));
$selectedSemester = trim((string)($_GET['semester'] ?? $_POST['semester'] ?? 'Semester 1'));
$selectedYear = trim((string)($_GET['academic_year'] ?? $_POST['academic_year'] ?? DEFAULT_ACADEMIC_YEAR));
$selectedExam = trim((string)($_GET['exam_type'] ?? $_POST['exam_type'] ?? 'Odd Semester End Examination'));

$courses = [];
try {
    $courses = $pdo->query("SELECT coursecode,coursetitle,dept_code,level,maxmark,credit,type FROM courses ORDER BY coursecode ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$courseMap = [];
foreach ($courses as $c) $courseMap[strtoupper((string)$c['coursecode'])] = $c;

$editingId = (int)($_GET['edit_id'] ?? 0);
$editing = null;
if ($editingId > 0) {
    $st = $pdo->prepare("SELECT * FROM blueprints WHERE id=? LIMIT 1");
    $st->execute([$editingId]);
    $editing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editing) {
        $selectedCourse = strtoupper((string)$editing['paper_code']);
        $selectedSemester = (string)$editing['semester'];
        $selectedYear = (string)$editing['academic_year'];
        $selectedExam = (string)$editing['exam_type'];
    }
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_blueprint'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $paperCode = strtoupper(trim((string)($_POST['paper_code'] ?? '')));
    $semester = trim((string)($_POST['semester'] ?? 'Semester 1'));
    $academicYear = trim((string)($_POST['academic_year'] ?? DEFAULT_ACADEMIC_YEAR));
    $examType = trim((string)($_POST['exam_type'] ?? 'Odd Semester End Examination'));
    $selectedIds = json_decode((string)($_POST['selected_question_ids'] ?? '[]'), true);
    if (!is_array($selectedIds)) $selectedIds=[];
    $selectedIds = array_values(array_unique(array_map('intval',$selectedIds)));
    try {
        if ($name==='') throw new RuntimeException('Blueprint name is required.');
        if ($paperCode==='' || !isset($courseMap[$paperCode])) throw new RuntimeException('Select a valid course/paper code.');
        if (count($selectedIds)!==30) throw new RuntimeException('Select exactly 30 questions. Currently selected: '.count($selectedIds).'.');

        $course = $courseMap[$paperCode];
        $examInfo = function_exists('hcc_course_exam_info') ? hcc_course_exam_info($course) : ['exam_marks'=>75];
        $totalMarks=(int)($examInfo['exam_marks'] ?? 75);

        $ph=implode(',',array_fill(0,count($selectedIds),'?'));
        $params=$selectedIds;
        $sql="SELECT q.*, qb.paper_code, qb.semester, qb.academic_year, qb.exam_type, qb.status, qb.hod_status
              FROM questions q INNER JOIN question_banks qb ON qb.id=q.bank_id
              WHERE q.id IN ($ph) AND UPPER(qb.paper_code)=UPPER(?)
                AND (UPPER(COALESCE(qb.status,'')) IN ('SUBMITTED TO COE','APPROVED','HOD APPROVED','VERIFIED','COE VERIFIED','PUBLISHED') OR UPPER(COALESCE(qb.hod_status,''))='APPROVED')";
        $params[]=$paperCode;
        $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        $byId=[]; foreach($rows as $r) $byId[(int)$r['id']]=$r;
        if(count($byId)!==30) throw new RuntimeException('One or more selected questions are not present in the verified uploaded pool for '.$paperCode.'. Please refresh the question pool.');

        // Never allow a question already used in a generated paper for this course.
        $usageBlocked=[];
        try {
            $ph2=implode(',',array_fill(0,count($selectedIds),'?')); $uParams=$selectedIds; $uParams[]=$paperCode;
            $u="SELECT DISTINCT question_id FROM qps_question_usage WHERE question_id IN ($ph2) AND UPPER(COALESCE(paper_code,''))=UPPER(?)";
            $ust=$pdo->prepare($u); $ust->execute($uParams);
            foreach($ust->fetchAll(PDO::FETCH_COLUMN) as $qid) $usageBlocked[(int)$qid]=true;
        } catch(Throwable $e) {}
        if($usageBlocked){
            $bad=[]; foreach($usageBlocked as $qid=>$_) $bad[]='#'.$qid;
            throw new RuntimeException('These selected questions were already used in a generated paper for '.$paperCode.': '.implode(', ',$bad).'. Pick fresh questions.');
        }

        $matrix=[];
        foreach($selectedIds as $qid){
            $q=$byId[$qid];
            $qnum=(string)($q['source_question_no'] ?? $q['q_number'] ?? $q['id']);
            $unit=(string)($q['unit_no'] ?? $q['unit'] ?? '');
            $sub=(string)($q['sub_unit'] ?? $q['subunit'] ?? ($unit.'.1'));
            $section=strtoupper((string)($q['section_type'] ?? '')) ?: 'SECTION-A';
            $matrix[]=[
                'section'=>$section,'unit'=>$unit,'sub_unit'=>$sub,
                'question_type'=>(string)($q['question_type'] ?? $q['type'] ?? ''),
                'k_level'=>(string)($q['k_level'] ?? 'K1'),
                'co_level'=>(string)($q['co_level'] ?? $q['co'] ?? ''),
                'marks'=>(int)($q['marks'] ?? 0),'required_count'=>1,'available_subunit'=>1,
                'question_numbers'=>$qnum,'question_id'=>$qid
            ];
        }
        $matrixConfig=json_encode(['source'=>'COE_DIRECT_VERIFIED_POOL','matrix_30q'=>$matrix],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($matrixConfig===false) throw new RuntimeException('Could not encode selected questions.');

        $dept=(string)($course['dept_code'] ?? ''); $duration='3 Hours';
        if($editingId>0){
            $st=$pdo->prepare("UPDATE blueprints SET name=?,dept_code=?,paper_code=?,course_title=?,semester=?,academic_year=?,exam_type=?,total_marks=?,duration_hours=?,matrix_config=?,updated_at=NOW() WHERE id=?");
            $st->execute([$name,$dept,$paperCode,$course['coursetitle'],$semester,$academicYear,$examType,$totalMarks,$duration,$matrixConfig,$editingId]);
            $savedId=$editingId; $msg='Blueprint updated successfully.';
        }else{
            $st=$pdo->prepare("INSERT INTO blueprints (name,dept_code,paper_code,course_title,semester,academic_year,exam_type,total_marks,duration_hours,sections_config,instructions,created_by,created_at,updated_at,matrix_config) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?)");
            $st->execute([$name,$dept,$paperCode,$course['coursetitle'],$semester,$academicYear,$examType,$totalMarks,$duration,'','','COE_OFFICE',$matrixConfig]);
            $savedId=(int)$pdo->lastInsertId(); $msg='Blueprint created successfully.';
        }
        try { qps_audit($pdo,'BLUEPRINT_SAVE','BLUEPRINT',(string)$savedId,['paper_code'=>$paperCode,'selected_count'=>30,'source'=>'COE_DIRECT_VERIFIED_POOL']); } catch(Throwable $e) {}
        $editingId=$savedId;
    } catch(Throwable $e){ $error=$e->getMessage(); }
}

$existingSelected=[];
if($editing){
    $cfg=json_decode((string)($editing['matrix_config']??''),true);
    foreach(($cfg['matrix_30q']??[]) as $r) if(!empty($r['question_id'])) $existingSelected[]=(int)$r['question_id'];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="max-w-[1500px] w-full mx-auto px-3 sm:px-5 py-5 space-y-5">
  <section class="bg-gradient-to-r from-slate-950 via-indigo-950 to-slate-900 rounded-[26px] p-6 text-white shadow-xl border border-indigo-900/50">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <span class="inline-flex items-center gap-2 bg-amber-400/15 text-amber-300 border border-amber-400/25 rounded-full px-3 py-1 text-[10px] font-black uppercase">COE • Final Question Selection</span>
        <h1 class="text-2xl font-black mt-2">30-Question Blueprint Manager</h1>
        <p class="text-slate-300 text-xs mt-1 max-w-3xl">COE selects the final 30 questions directly from the verified uploaded question pool. No HOD Question Bank Blueprint is required on this page.</p>
      </div>
      <span id="selectedBadge" class="rounded-full bg-amber-400 text-slate-950 px-4 py-2 text-xs font-black">0 / 30 Selected</span>
    </div>
  </section>
  <?php if($msg): ?><div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl p-4 text-xs font-bold"><?php echo bp_h($msg); ?></div><?php endif; ?>
  <?php if($error): ?><div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl p-4 text-xs font-bold"><?php echo bp_h($error); ?></div><?php endif; ?>

  <section class="bg-white rounded-[26px] border border-stone-200 shadow-sm p-5">
    <form id="bpForm" method="post" class="space-y-4">
      <input type="hidden" name="save_blueprint" value="1"><input type="hidden" name="selected_question_ids" id="selectedQuestionIds">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
        <label class="text-xs font-bold text-slate-700 lg:col-span-2">Blueprint Name<input name="name" required value="<?php echo bp_h($editing['name'] ?? 'Course 30-Question Blueprint'); ?>" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5 font-bold"></label>
        <label class="text-xs font-bold text-slate-700">Course / Paper Code<select name="paper_code" id="bpCourse" required class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5 font-bold"><option value="">Select course</option><?php foreach($courses as $c): $cc=strtoupper((string)$c['coursecode']); ?><option value="<?php echo bp_h($cc); ?>" <?php echo $cc===$selectedCourse?'selected':''; ?>><?php echo bp_h($c['coursecode'].' — '.$c['coursetitle']); ?></option><?php endforeach; ?></select></label>
        <label class="text-xs font-bold text-slate-700">Semester<select name="semester" id="bpSemester" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5"><?php for($s=1;$s<=8;$s++): $sv='Semester '.$s; ?><option value="<?php echo $sv; ?>" <?php echo $selectedSemester===$sv?'selected':''; ?>><?php echo $sv; ?></option><?php endfor; ?></select></label>
        <label class="text-xs font-bold text-slate-700">Academic Year<select name="academic_year" id="bpYear" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5"><?php foreach(['2026-2027','2025-2026','2024-2025','2023-2024'] as $ay): ?><option value="<?php echo $ay; ?>" <?php echo $selectedYear===$ay?'selected':''; ?>><?php echo $ay; ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <label class="text-xs font-bold text-slate-700">Exam Type<select name="exam_type" id="bpExam" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5"><?php foreach($examTypes as $k=>$v): ?><option value="<?php echo bp_h($k); ?>" <?php echo $selectedExam===$k?'selected':''; ?>><?php echo bp_h($v); ?></option><?php endforeach; ?></select></label>
        <div class="md:col-span-2 flex items-end"><button type="button" id="loadPool" class="w-full rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-black px-4 py-3 text-xs">Load Verified Question Pool</button></div>
      </div>
    </form>
  </section>

  <section class="bg-white rounded-[26px] border border-stone-200 shadow-sm p-5 space-y-4">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
      <div><h2 class="font-black text-slate-900">Select Final 30 Questions</h2><p class="text-[11px] text-slate-500">Questions already used in previous generated papers are locked. Selected rows are highlighted.</p></div>
      <div class="flex flex-wrap gap-2"><select id="sectionFilter" class="rounded-xl border border-stone-300 p-2 text-xs font-bold"><option value="">All Sections</option><option value="SECTION-A">Section A</option><option value="SECTION-B">Section B</option><option value="SECTION-C">Section C</option><option value="SECTION-D">Section D</option></select><select id="unitFilter" class="rounded-xl border border-stone-300 p-2 text-xs font-bold"><option value="">All Units</option></select><select id="marksFilter" class="rounded-xl border border-stone-300 p-2 text-xs font-bold"><option value="">All Marks</option></select><select id="kFilter" class="rounded-xl border border-stone-300 p-2 text-xs font-bold"><option value="">All K-Level</option></select></div>
    </div>
    <div id="poolSummary" class="rounded-2xl bg-sky-50 border border-sky-200 p-3 text-xs font-bold text-sky-900">Select a course and click Load Verified Question Pool.</div>
    <div id="poolTable" class="space-y-4"></div>
  </section>

  <section class="sticky bottom-3 bg-white/95 backdrop-blur border border-stone-200 shadow-2xl rounded-2xl p-3 flex items-center justify-between gap-3"><div class="text-xs font-bold text-slate-700"><span id="footerCount">0 / 30</span> questions selected</div><button type="button" id="saveBtn" class="rounded-xl bg-[#F6C443] hover:bg-[#EAB326] text-slate-950 font-black px-6 py-3 text-xs disabled:opacity-50" disabled>Save & Lock 30-Question Blueprint</button></section>
</main>

<script>
let pool=[]; let selected=new Set(<?php echo json_encode($existingSelected); ?>); const preCourse=<?php echo json_encode($selectedCourse); ?>;
function esc(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function updateCount(){const n=selected.size;document.getElementById('selectedBadge').textContent=n+' / 30 Selected';document.getElementById('footerCount').textContent=n+' / 30';document.getElementById('saveBtn').disabled=n!==30;document.getElementById('selectedQuestionIds').value=JSON.stringify([...selected]);}
function fillFilters(){const unit=document.getElementById('unitFilter'),marks=document.getElementById('marksFilter'),k=document.getElementById('kFilter');const units=[...new Set(pool.map(q=>q.unit))].filter(Boolean).sort((a,b)=>String(a).localeCompare(String(b),undefined,{numeric:true}));const ms=[...new Set(pool.map(q=>Number(q.marks)||0))].filter(x=>x>0).sort((a,b)=>a-b);const ks=[...new Set(pool.map(q=>q.k_level).filter(Boolean))].sort();unit.innerHTML='<option value="">All Units</option>'+units.map(x=>`<option value="${esc(x)}">Unit ${esc(x)}</option>`).join('');marks.innerHTML='<option value="">All Marks</option>'+ms.map(x=>`<option value="${x}">${x} Mark${x==1?'':'s'}</option>`).join('');k.innerHTML='<option value="">All K-Level</option>'+ks.map(x=>`<option value="${esc(x)}">${esc(x)}</option>`).join('');}
function matches(q){const sf=document.getElementById('sectionFilter').value,uf=document.getElementById('unitFilter').value,mf=document.getElementById('marksFilter').value,kf=document.getElementById('kFilter').value;return(!sf||q.section===sf)&&(!uf||String(q.unit)===uf)&&(!mf||Number(q.marks)===Number(mf))&&(!kf||q.k_level===kf);}
function render(){const box=document.getElementById('poolTable'),filtered=pool.filter(matches);const groups={};filtered.forEach(q=>{const s=q.section||'SECTION-A';(groups[s]??=[]).push(q);});const order=['SECTION-A','SECTION-B','SECTION-C','SECTION-D'];let html='';order.concat(Object.keys(groups).filter(x=>!order.includes(x))).forEach(section=>{const arr=groups[section];if(!arr||!arr.length)return;html+=`<div class="border border-stone-200 rounded-2xl overflow-hidden"><div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between"><div class="font-black">${esc(section.replace('SECTION-','SECTION '))}</div><div class="text-[10px] bg-white/10 rounded-full px-3 py-1">${arr.length} questions</div></div><div class="overflow-x-auto"><table class="w-full text-[11px]"><thead class="bg-stone-100 text-slate-700 font-black"><tr><th class="p-2 w-10">Pick</th><th class="p-2">Q.No</th><th class="p-2">Unit</th><th class="p-2">Sub-Unit</th><th class="p-2">K</th><th class="p-2">CO</th><th class="p-2">Marks</th><th class="p-2">Type</th><th class="p-2 text-left min-w-[360px]">Question</th></tr></thead><tbody>`;arr.forEach(q=>{const checked=selected.has(Number(q.id));const disabled=q.used_previous||(!checked&&selected.size>=30);const rowClass=checked?'bg-sky-100 ring-1 ring-inset ring-sky-300':q.used_previous?'bg-rose-50 opacity-70':'hover:bg-stone-50';html+=`<tr class="${rowClass} border-b border-stone-100"><td class="p-2 text-center"><input type="checkbox" ${checked?'checked':''} ${disabled?'disabled':''} onchange="toggleQ(${q.id},this.checked)" class="w-5 h-5 accent-indigo-600"></td><td class="p-2 font-black text-indigo-700">${esc(q.q_number)}</td><td class="p-2 font-bold">Unit ${esc(q.unit)}</td><td class="p-2 font-mono">${esc(q.sub_unit)}</td><td class="p-2 font-black">${esc(q.k_level)}</td><td class="p-2 font-black">${esc(q.co_level)}</td><td class="p-2 font-black">${Number(q.marks)||0}</td><td class="p-2">${esc(q.question_type)}</td><td class="p-2 text-left">${esc(q.question_text)}${q.used_previous?'<div class="mt-1 text-[9px] font-black text-rose-700">USED IN PREVIOUS GENERATED PAPER — LOCKED</div>':''}</td></tr>`;});html+='</tbody></table></div></div>';});box.innerHTML=html||'<div class="p-10 text-center text-slate-500 font-bold">No questions match the selected filters.</div>';}
function toggleQ(id,on){if(on){if(selected.size>=30&&!selected.has(Number(id)))return;selected.add(Number(id));}else selected.delete(Number(id));updateCount();render();}
async function loadPool(){const c=document.getElementById('bpCourse').value;if(!c){Swal.fire('Course Required','Select a course/paper code first.','warning');return;}const b=document.getElementById('loadPool');b.disabled=true;b.textContent='Loading…';try{const u='<?php echo $baseUrl; ?>/api/get_coe_question_pool.php?paper_code='+encodeURIComponent(c)+'&semester='+encodeURIComponent(document.getElementById('bpSemester').value)+'&academic_year='+encodeURIComponent(document.getElementById('bpYear').value)+'&exam_type='+encodeURIComponent(document.getElementById('bpExam').value);const r=await fetch(u);const d=await r.json();if(!d.success)throw new Error(d.error||'Could not load question pool');pool=d.questions||[];fillFilters();render();document.getElementById('poolSummary').textContent=`${d.available_questions||0} usable / ${pool.length} verified questions loaded for ${c}. Questions used in previous generated papers are locked.`;}catch(e){document.getElementById('poolSummary').textContent=e.message;}finally{b.disabled=false;b.textContent='Load Verified Question Pool';}}
document.getElementById('loadPool').addEventListener('click',loadPool);['sectionFilter','unitFilter','marksFilter','kFilter'].forEach(id=>document.getElementById(id).addEventListener('change',render));document.getElementById('saveBtn').addEventListener('click',()=>document.getElementById('bpForm').requestSubmit());document.addEventListener('DOMContentLoaded',()=>{updateCount();if(preCourse)document.getElementById('loadPool').click();if(window.lucide)lucide.createIcons();});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>