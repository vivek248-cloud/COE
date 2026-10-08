<?php
declare(strict_types=1);

define('PAGE_TITLE', 'COE Blueprint Manager');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireCOE();
$pdo=getDBConnection();
function cb_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function cb_j($v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function cb_redirect(string $u): never { header('Location: '.$u); exit; }
if(empty($_SESSION['coe_blueprint_csrf']))$_SESSION['coe_blueprint_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['coe_blueprint_csrf'];
$examTypes=function_exists('hcc_exam_types')?hcc_exam_types():['Odd Semester End Examination'=>'Odd Semester End Examination','Even Semester End Examination'=>'Even Semester End Examination','Internal Examination'=>'Internal Examination'];
$courses=[];try{$courses=$pdo->query('SELECT coursecode,coursetitle,dept_code FROM courses ORDER BY coursecode')->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){}
function cb_defaults(): array{return[
 ['code'=>'A','name'=>'SECTION-A','display_questions'=>20,'answer_questions'=>20,'marks_each'=>1,'choice'=>'ALL','instruction'=>'Answer ALL the questions:','selection_rules'=>[]],
 ['code'=>'B','name'=>'SECTION-B','display_questions'=>5,'answer_questions'=>5,'marks_each'=>5,'choice'=>'ALL','instruction'=>'Answer FIVE questions:','selection_rules'=>[]],
 ['code'=>'C','name'=>'SECTION-C','display_questions'=>4,'answer_questions'=>2,'marks_each'=>10,'choice'=>'ANY','instruction'=>'Answer any TWO of the following questions:','selection_rules'=>[]],
 ['code'=>'D','name'=>'SECTION-D','display_questions'=>1,'answer_questions'=>1,'marks_each'=>10,'choice'=>'COMPULSORY','instruction'=>'Answer the following question (COMPULSORY):','selection_rules'=>[]]
];}
function cb_normalize($raw): array{
 $defs=cb_defaults();if(!is_array($raw)||!$raw)return $defs;$out=[];
 foreach($raw as $i=>$s){if(!is_array($s))continue;$code=strtoupper(trim((string)($s['code']??chr(65+$i))));if(!preg_match('/^[A-Z]$/',$code))continue;$display=max(1,(int)($s['display_questions']??1));$rules=[];foreach((array)($s['selection_rules']??[]) as $r)if(is_array($r))$rules[]=$r;
  $out[]=['code'=>$code,'name'=>(string)($s['name']??'SECTION-'.$code),'display_questions'=>$display,'answer_questions'=>max(1,(int)($s['answer_questions']??$display)),'marks_each'=>max(1,(int)($s['marks_each']??1)),'choice'=>strtoupper((string)($s['choice']??'ALL')),'instruction'=>(string)($s['instruction']??''),'selection_rules'=>$rules];}
 return $out?:$defs;
}
$error='';$msg='';$editId=(int)($_GET['edit_id']??$_POST['edit_id']??0);
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Security token expired. Refresh the page.');
  $action=strtolower(trim((string)($_POST['action']??'')));$id=(int)($_POST['id']??0);
  if($action==='delete'){
   if($id<=0)throw new RuntimeException('Invalid blueprint ID.');$st=$pdo->prepare('SELECT id,name FROM blueprints WHERE id=? LIMIT 1');$st->execute([$id]);$bp=$st->fetch(PDO::FETCH_ASSOC);if(!$bp)throw new RuntimeException('Blueprint not found.');
   try{$st=$pdo->prepare('SELECT COUNT(*) FROM generated_papers WHERE blueprint_id=?');$st->execute([$id]);if((int)$st->fetchColumn()>0)throw new RuntimeException('This blueprint is already used by generated papers and cannot be deleted. Archive it instead.');}catch(PDOException $e){}
   $pdo->prepare('DELETE FROM blueprints WHERE id=?')->execute([$id]);cb_redirect(getBaseUrl().'/modules/coe/blueprints.php?deleted=1');
  }
  if($action==='save'){
   $name=trim((string)($_POST['name']??''));$paper=strtoupper(trim((string)($_POST['paper_code']??'')));$semester=trim((string)($_POST['semester']??'Semester 1'));$year=trim((string)($_POST['academic_year']??''));$exam=trim((string)($_POST['exam_type']??''));$totalMarks=max(0,(int)($_POST['total_marks']??75));$duration=trim((string)($_POST['duration_hours']??'3 Hours'));$instructions=trim((string)($_POST['instructions']??''));
   $sections=cb_normalize(json_decode((string)($_POST['sections_json']??'[]'),true));if($name==='')throw new RuntimeException('Blueprint name is required.');if($paper==='')throw new RuntimeException('Course / paper code is required.');if($year==='')throw new RuntimeException('Academic year is required.');
   $qTotal=0;$calcMarks=0;foreach($sections as $s){if((int)$s['answer_questions']>(int)$s['display_questions'])throw new RuntimeException($s['name'].' answer count cannot exceed display count.');$qTotal+=(int)$s['display_questions'];$calcMarks+=(int)$s['display_questions']*(int)$s['marks_each'];}
   $courseTitle='';$dept='';$st=$pdo->prepare('SELECT coursetitle,dept_code FROM courses WHERE UPPER(coursecode)=UPPER(?) LIMIT 1');$st->execute([$paper]);$c=$st->fetch(PDO::FETCH_ASSOC);if($c){$courseTitle=(string)$c['coursetitle'];$dept=(string)$c['dept_code'];}
   $sectionsJson=cb_j($sections);$previousSelection=[];if($id>0){try{$pst=$pdo->prepare('SELECT matrix_config FROM blueprints WHERE id=? LIMIT 1');$pst->execute([$id]);$pm=json_decode((string)$pst->fetchColumn(),true);if(is_array($pm))$previousSelection=$pm['selection_rules']??[];}catch(Throwable $e){}}$matrix=cb_j(['source'=>'COE_UNIT_SCOPE_BLUEPRINT','version'=>1,'sections'=>$sections,'total_questions'=>$qTotal,'calculated_marks'=>$calcMarks,'selection_mode'=>'UNIT_SUBUNIT_ONLY','selection_rules'=>$previousSelection]);
   if($id>0){$st=$pdo->prepare('UPDATE blueprints SET name=?,dept_code=?,paper_code=?,course_title=?,semester=?,academic_year=?,exam_type=?,total_marks=?,duration_hours=?,sections_config=?,instructions=?,updated_at=NOW(),matrix_config=? WHERE id=?');$st->execute([$name,$dept,$paper,$courseTitle,$semester,$year,$exam,$totalMarks,$duration,$sectionsJson,$instructions,$matrix,$id]);}
   else{$st=$pdo->prepare('INSERT INTO blueprints (name,dept_code,paper_code,course_title,semester,academic_year,exam_type,total_marks,duration_hours,sections_config,instructions,created_by,created_at,updated_at,matrix_config) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,NOW(),NOW(),?)');$st->execute([$name,$dept,$paper,$courseTitle,$semester,$year,$exam,$totalMarks,$duration,$sectionsJson,$instructions,'COE_OFFICE',$matrix]);$id=(int)$pdo->lastInsertId();}
   cb_redirect(getBaseUrl().'/modules/coe/blueprints.php?edit_id='.$id.'&saved=1');
  }
 }
}catch(Throwable $e){$error=$e->getMessage();}
if(isset($_GET['saved']))$msg='Blueprint saved successfully.';if(isset($_GET['deleted']))$msg='Blueprint deleted successfully.';
$editing=null;if($editId>0){$st=$pdo->prepare('SELECT * FROM blueprints WHERE id=? LIMIT 1');$st->execute([$editId]);$editing=$st->fetch(PDO::FETCH_ASSOC);if($editing)$editing['sections']=cb_normalize(json_decode((string)$editing['sections_config'],true));}
$blueprints=[];try{$blueprints=$pdo->query('SELECT id,name,paper_code,course_title,semester,academic_year,exam_type,total_marks,duration_hours,sections_config,updated_at FROM blueprints ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$error=$error?:$e->getMessage();}
$uniqueYears = array_unique(array_filter(array_map(fn($b) => trim((string)($b['academic_year'] ?? '')), $blueprints)));
sort($uniqueYears);
$uniqueCourses = [];
foreach($blueprints as $bp) {
    if(!empty($bp['paper_code'])) {
        $uniqueCourses[$bp['paper_code']] = $bp['course_title'] ? ($bp['paper_code'].' - '.$bp['course_title']) : $bp['paper_code'];
    }
}
ksort($uniqueCourses);

$form=$editing?:['id'=>0,'name'=>'Holy Cross 75M Unit/Sub-Unit Blueprint','paper_code'=>'','semester'=>'Semester 1','academic_year'=>defined('DEFAULT_ACADEMIC_YEAR')?DEFAULT_ACADEMIC_YEAR:'2026-2027','exam_type'=>array_key_first($examTypes),'total_marks'=>75,'duration_hours'=>'3 Hours','instructions'=>'A: answer all. B: answer five. C: answer any two of four. D: compulsory.','sections'=>cb_defaults()];
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/navbar.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header Banner -->
  <section class="rounded-[28px] bg-gradient-to-r from-slate-950 via-slate-900 to-[#121826] text-white p-7 shadow-xl border border-orange-500/20 relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-orange-500/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="relative z-10 flex flex-col xl:flex-row justify-between items-start xl:items-center gap-5">
      <div>
        <div class="inline-flex items-center gap-2 bg-orange-500/20 text-orange-300 border border-orange-500/30 rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider">
          <i data-lucide="layers" class="w-3.5 h-3.5 text-orange-400"></i> COE EXAMINATION SUITE • BLUEPRINT MANAGER
        </div>
        <h1 class="text-2xl md:text-3xl font-black mt-2 tracking-tight">Master Blueprint Manager (CRUD)</h1>
        <p class="text-xs md:text-sm text-slate-300 mt-1 max-w-4xl leading-relaxed">
          Create and manage reusable paper structures. After creating a blueprint, use <b>Build Selection</b> to configure Unit / Sub-Unit scopes into Sections A–D.
        </p>
      </div>
      <div class="flex flex-wrap gap-2.5">
        <a href="<?=cb_h(getBaseUrl())?>/modules/coe/blueprints.php" class="btn-orange-pill text-xs shadow-md">+ New Blueprint</a>
        <a href="<?=cb_h(getBaseUrl())?>/modules/coe/blueprint.php" class="bg-white/10 hover:bg-white/20 text-white border border-white/20 text-xs font-bold px-4 py-2.5 rounded-full transition flex items-center space-x-1.5">
          <span>Open Matrix Builder</span>
          <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </a>
      </div>
    </div>
  </section>

  <?php if($msg): ?>
    <div class="card-modern p-4 bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-bold flex items-center space-x-2">
      <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600 shrink-0"></i>
      <span><?=cb_h($msg)?></span>
    </div>
  <?php endif; ?>

  <?php if($error): ?>
    <div class="card-modern p-4 bg-rose-50 border-rose-200 text-rose-800 text-xs font-bold flex items-center space-x-2">
      <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 shrink-0"></i>
      <span><?=cb_h($error)?></span>
    </div>
  <?php endif; ?>

  <!-- Stats Grid -->
  <section class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
    <div class="card-modern p-4">
      <div class="text-[10px] uppercase font-black tracking-wider text-slate-400">Total Blueprints</div>
      <div class="text-2xl font-black text-slate-900 mt-1"><?=count($blueprints)?></div>
    </div>
    <div class="card-modern p-4">
      <div class="text-[10px] uppercase font-black tracking-wider text-slate-400">Current Mode</div>
      <div class="text-sm font-black text-orange-600 mt-2">Unit / Sub-Unit</div>
    </div>
    <div class="card-modern p-4">
      <div class="text-[10px] uppercase font-black tracking-wider text-slate-400">Standard Paper</div>
      <div class="text-sm font-black text-slate-900 mt-2">75 Marks OBE</div>
    </div>
    <div class="card-modern p-4">
      <div class="text-[10px] uppercase font-black tracking-wider text-slate-400">Sections</div>
      <div class="text-sm font-black text-indigo-700 mt-2">A • B • C • D</div>
    </div>
  </section>

  <!-- Blueprint Library with Overflow-y Scroll and Filtering -->
  <section class="card-modern p-6 space-y-4">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-100 pb-4">
      <div>
        <h2 class="font-black text-slate-900 text-base">Blueprint Library</h2>
        <p class="text-[11px] text-slate-500">Filter and choose a blueprint to edit its structure or build its 30-question matrix.</p>
      </div>
      <div class="flex items-center gap-2">
        <span id="bpFilterCount" class="text-xs font-bold text-slate-500 bg-slate-100 px-3 py-1 rounded-full"><?=count($blueprints)?> Total</span>
        <a href="?" class="btn-dark-pill text-xs">+ Create New</a>
      </div>
    </div>

    <!-- Filter Bar by Academic Year and Course -->
    <div class="bg-slate-50 border border-slate-200/80 rounded-2xl p-3 grid grid-cols-1 md:grid-cols-3 gap-3">
      <!-- Search Input -->
      <div class="relative">
        <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-3 pointer-events-none"></i>
        <input type="text" id="bpSearchInput" placeholder="Search blueprint or course..." class="w-full pl-9 pr-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-orange-500">
      </div>

      <!-- Academic Year Filter -->
      <div>
        <select id="bpYearFilter" class="w-full py-2 px-3 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-orange-500">
          <option value="">All Academic Years</option>
          <?php foreach($uniqueYears as $yr): ?>
            <option value="<?=cb_h($yr)?>"><?=cb_h($yr)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Course Filter -->
      <div>
        <select id="bpCourseFilter" class="w-full py-2 px-3 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-orange-500">
          <option value="">All Courses</option>
          <?php foreach($uniqueCourses as $cc => $ct): ?>
            <option value="<?=cb_h($cc)?>"><?=cb_h($ct)?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Scrollable Cards Grid Container -->
    <div class="overflow-y-auto max-h-[520px] custom-scrollbar-y p-1">
      <div id="bpCardsContainer" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach($blueprints as $bp): 
          $secs=cb_normalize(json_decode((string)$bp['sections_config'],true)); 
          $summary=implode(' • ',array_map(fn($s)=>$s['code'].':'.$s['display_questions'].'×'.$s['marks_each'].'M',$secs)); 
        ?>
          <article class="bp-card rounded-2xl border border-slate-200 bg-white p-5 hover:shadow-lg hover:border-orange-300 transition duration-200 flex flex-col justify-between"
                   data-year="<?=cb_h(strtoupper($bp['academic_year'] ?? ''))?>"
                   data-course="<?=cb_h(strtoupper($bp['paper_code'] ?? ''))?>"
                   data-search="<?=cb_h(strtolower(($bp['name'] ?? '').' '.($bp['paper_code'] ?? '').' '.($bp['course_title'] ?? '').' '.($bp['academic_year'] ?? '')))?>">
            <div>
              <div class="flex items-start justify-between gap-2">
                <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-slate-100 text-slate-700 border border-slate-200">#<?=cb_h($bp['id'])?></span>
                <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-orange-50 text-orange-700 border border-orange-200"><?=cb_h($bp['total_marks'])?>M</span>
              </div>
              <h3 class="font-black text-slate-900 mt-3 leading-snug"><?=cb_h($bp['name'])?></h3>
              <div class="text-xs font-bold text-orange-600 mt-1"><?=cb_h($bp['paper_code']?:'No course selected')?></div>
              <div class="text-[11px] text-slate-500 mt-0.5 truncate"><?=cb_h($bp['course_title'])?></div>
              <div class="flex flex-wrap gap-1.5 mt-3">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600"><?=cb_h($bp['semester'])?></span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-orange-50 text-orange-800 border border-orange-200/50"><?=cb_h($bp['academic_year'])?></span>
              </div>
              <div class="text-[11px] font-bold text-slate-600 mt-3 p-2 bg-slate-50 rounded-xl border border-slate-200/60">
                Structure: <?=cb_h($summary)?>
              </div>
            </div>

            <div class="space-y-2 mt-4 pt-3 border-t border-slate-100">
              <div class="grid grid-cols-2 gap-2">
                <a href="?edit_id=<?=cb_h($bp['id'])?>" class="text-center border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-full py-2 text-xs font-bold transition">Edit Structure</a>
                <a href="<?=cb_h(getBaseUrl())?>/modules/coe/blueprint.php?blueprint_id=<?=cb_h($bp['id'])?>" class="text-center bg-orange-500 hover:bg-orange-600 text-white rounded-full py-2 text-xs font-bold transition shadow-sm">Matrix Builder</a>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <a href="<?=cb_h(getBaseUrl())?>/modules/coe/blueprint.php?blueprint_id=<?=cb_h($bp['id'])?>" class="text-center bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-full py-2 text-xs font-bold transition">Open Matrix</a>
                <form method="post" onsubmit="return confirmDeleteBlueprint(event, this);">
                  <input type="hidden" name="csrf" value="<?=cb_h($csrf)?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=cb_h($bp['id'])?>">
                  <button type="submit" class="w-full bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-full py-2 text-xs font-bold transition">Delete</button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$blueprints): ?>
          <div class="md:col-span-2 xl:col-span-3 p-12 text-center text-slate-400 text-xs">No blueprints created yet. Use the form below to create your first blueprint.</div>
        <?php endif; ?>
      </div>
      <div id="bpNoMatches" class="hidden p-12 text-center text-slate-400 text-xs font-bold">
        <i data-lucide="filter" class="w-8 h-8 text-slate-300 mx-auto mb-2"></i>
        No blueprints match the selected academic year and course filters.
      </div>
    </div>
  </section>

  <!-- Create / Edit Blueprint Form -->
  <section class="card-modern p-6 space-y-4">
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
      <div>
        <h2 class="font-black text-slate-900 text-base"><?=$editing?'Edit Blueprint Structure':'Create New Blueprint'?></h2>
        <p class="text-[11px] text-slate-500">Define only the paper structure here. Unit/Sub-Unit 30-question selection is configured in the Builder.</p>
      </div>
      <?php if($editing): ?>
        <a href="?" class="text-xs font-bold text-orange-600 hover:underline">Cancel Edit</a>
      <?php endif; ?>
    </div>

    <form method="post" id="cbForm" class="space-y-4">
      <input type="hidden" name="csrf" value="<?=cb_h($csrf)?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?=cb_h($form['id'])?>">
      <input type="hidden" name="sections_json" id="sectionsJson">

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <label class="text-xs font-bold text-slate-700 lg:col-span-2">
          Blueprint Name *
          <input class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500" name="name" value="<?=cb_h($form['name'])?>" required>
        </label>

        <label class="text-xs font-bold text-slate-700">
          Course / Paper *
          <select class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500" name="paper_code" required>
            <option value="">Select course</option>
            <?php foreach($courses as $c): ?>
              <option value="<?=cb_h($c['coursecode'])?>" <?=$form['paper_code']===$c['coursecode']?'selected':''?>><?=cb_h($c['coursecode'].' — '.$c['coursetitle'])?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="text-xs font-bold text-slate-700">
          Total Marks *
          <input class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500" type="number" name="total_marks" value="<?=cb_h($form['total_marks'])?>" min="1">
        </label>

        <label class="text-xs font-bold text-slate-700">
          Semester
          <select class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500" name="semester">
            <?php for($i=1;$i<=8;$i++): ?>
              <option <?=$form['semester']==='Semester '.$i?'selected':''?>>Semester <?=$i?></option>
            <?php endfor; ?>
          </select>
        </label>

        <label class="text-xs font-bold text-slate-700">
          Academic Year *
          <input class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500" name="academic_year" value="<?=cb_h($form['academic_year'])?>" required>
        </label>

        <label class="text-xs font-bold text-slate-700">
          Exam Type
          <select class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500" name="exam_type">
            <?php foreach($examTypes as $k=>$v): ?>
              <option value="<?=cb_h($k)?>" <?=$form['exam_type']===$k?'selected':''?>><?=cb_h($v)?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="text-xs font-bold text-slate-700">
          Duration
          <input class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-bold text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500" name="duration_hours" value="<?=cb_h($form['duration_hours'])?>">
        </label>

        <label class="text-xs font-bold text-slate-700 md:col-span-2 lg:col-span-4">
          Exam Instructions
          <textarea class="w-full mt-1 border border-stone-300 rounded-2xl p-2.5 font-medium text-slate-800 bg-white shadow-sm focus:ring-2 focus:ring-orange-500 text-xs" rows="2" name="instructions"><?=cb_h($form['instructions'])?></textarea>
        </label>
      </div>

      <div class="flex items-center justify-between pt-4 border-t border-slate-100">
        <div>
          <h3 class="font-black text-slate-900 text-sm">Section Structure</h3>
          <p class="text-[11px] text-slate-500">Set question count and marks per question for each section.</p>
        </div>
      </div>

      <div id="sectionEditor" class="grid grid-cols-1 lg:grid-cols-2 gap-4"></div>

      <div class="flex justify-end pt-3">
        <button type="submit" class="btn-orange-pill text-xs px-8 shadow-lg shadow-orange-500/20">Save Blueprint</button>
      </div>
    </form>
  </section>

</main>

<script>
const INITIAL = <?=cb_j($form['sections'])?>;
const esc = v => String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));

function renderSections() {
  const wrap = document.getElementById('sectionEditor');
  wrap.innerHTML = '';
  INITIAL.forEach((s, i) => {
    const b = document.createElement('div');
    b.className = 'rounded-2xl border border-slate-200 bg-slate-50/60 p-4 space-y-3';
    b.dataset.i = i;
    b.innerHTML = `
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-[9px] uppercase tracking-widest text-orange-600 font-black">Section ${esc(s.code)}</div>
          <div class="font-black text-sm text-slate-900 mt-0.5">${esc(s.name)}</div>
        </div>
        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-white text-slate-700 border border-slate-200">${Number(s.display_questions)} questions</span>
      </div>
      <div class="grid grid-cols-2 gap-2 text-xs">
        <label class="text-[10px] font-bold text-slate-600">Code<input data-f="code" class="w-full mt-1 border border-stone-300 rounded-xl p-2 font-mono font-bold bg-white" value="${esc(s.code)}"></label>
        <label class="text-[10px] font-bold text-slate-600">Name<input data-f="name" class="w-full mt-1 border border-stone-300 rounded-xl p-2 font-bold bg-white" value="${esc(s.name)}"></label>
        <label class="text-[10px] font-bold text-slate-600">Display Questions<input data-f="display_questions" class="w-full mt-1 border border-stone-300 rounded-xl p-2 font-bold bg-white" type="number" min="1" value="${Number(s.display_questions)}"></label>
        <label class="text-[10px] font-bold text-slate-600">Answer Questions<input data-f="answer_questions" class="w-full mt-1 border border-stone-300 rounded-xl p-2 font-bold bg-white" type="number" min="1" value="${Number(s.answer_questions)}"></label>
        <label class="text-[10px] font-bold text-slate-600">Marks / Question<input data-f="marks_each" class="w-full mt-1 border border-stone-300 rounded-xl p-2 font-bold bg-white" type="number" min="1" value="${Number(s.marks_each)}"></label>
        <label class="text-[10px] font-bold text-slate-600">Choice Mode
          <select data-f="choice" class="w-full mt-1 border border-stone-300 rounded-xl p-2 font-bold bg-white">
            <option ${s.choice==='ALL'?'selected':''}>ALL</option>
            <option ${s.choice==='ANY'?'selected':''}>ANY</option>
            <option ${s.choice==='COMPULSORY'?'selected':''}>COMPULSORY</option>
            <option ${s.choice==='ONE_OF_PAIR'?'selected':''}>ONE_OF_PAIR</option>
          </select>
        </label>
        <label class="text-[10px] font-bold text-slate-600 col-span-2">Instruction<input data-f="instruction" class="w-full mt-1 border border-stone-300 rounded-xl p-2 bg-white" value="${esc(s.instruction||'')}"></label>
      </div>
      <div class="rounded-xl bg-orange-50 border border-orange-200 p-2.5 text-[10px] text-orange-900 font-medium">
        <b>OBE Step:</b> Specific Unit, Sub-Unit, K-Level, and CO slot mapping is configured in the Matrix Builder.
      </div>
    `;
    wrap.appendChild(b);
  });
}

function confirmDeleteBlueprint(event, form) {
  event.preventDefault();
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Delete Blueprint?',
      text: 'Are you sure you want to permanently delete this master blueprint?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Delete',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#FF5B00',
      cancelButtonColor: '#64748B',
      customClass: {
        popup: 'rounded-[28px]',
        confirmButton: 'rounded-full px-5 py-2.5 font-bold',
        cancelButton: 'rounded-full px-5 py-2.5 font-bold'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  } else {
    if (confirm('Delete this blueprint?')) {
      form.submit();
    }
  }
  return false;
}

// Live Blueprint Filtering by Academic Year, Course, and Search Query
function applyBlueprintFilters() {
  const searchVal = (document.getElementById('bpSearchInput')?.value || '').toLowerCase().trim();
  const yearVal = (document.getElementById('bpYearFilter')?.value || '').toUpperCase().trim();
  const courseVal = (document.getElementById('bpCourseFilter')?.value || '').toUpperCase().trim();

  const cards = document.querySelectorAll('.bp-card');
  let visibleCount = 0;

  cards.forEach(card => {
    const cardYear = (card.dataset.year || '').toUpperCase();
    const cardCourse = (card.dataset.course || '').toUpperCase();
    const cardSearch = (card.dataset.search || '').toLowerCase();

    const matchesSearch = !searchVal || cardSearch.includes(searchVal);
    const matchesYear = !yearVal || cardYear === yearVal;
    const matchesCourse = !courseVal || cardCourse === courseVal;

    if (matchesSearch && matchesYear && matchesCourse) {
      card.style.display = 'flex';
      visibleCount++;
    } else {
      card.style.display = 'none';
    }
  });

  const countBadge = document.getElementById('bpFilterCount');
  if (countBadge) countBadge.textContent = `${visibleCount} Showing`;

  const noMatches = document.getElementById('bpNoMatches');
  if (noMatches) {
    if (visibleCount === 0 && cards.length > 0) {
      noMatches.classList.remove('hidden');
    } else {
      noMatches.classList.add('hidden');
    }
  }
}

document.getElementById('bpSearchInput')?.addEventListener('input', applyBlueprintFilters);
document.getElementById('bpYearFilter')?.addEventListener('change', applyBlueprintFilters);
document.getElementById('bpCourseFilter')?.addEventListener('change', applyBlueprintFilters);

renderSections();
document.getElementById('cbForm').addEventListener('submit', () => {
  const sections = [];
  document.querySelectorAll('#sectionEditor>[data-i]').forEach(b => {
    const g = f => b.querySelector(`[data-f="${f}"]`).value;
    sections.push({
      code: g('code'),
      name: g('name'),
      display_questions: Number(g('display_questions')),
      answer_questions: Number(g('answer_questions')),
      marks_each: Number(g('marks_each')),
      choice: g('choice'),
      instruction: g('instruction'),
      selection_rules: []
    });
  });
  document.getElementById('sectionsJson').value = JSON.stringify(sections);
});
</script>

<?php require_once __DIR__.'/../../includes/footer.php'; ?>
