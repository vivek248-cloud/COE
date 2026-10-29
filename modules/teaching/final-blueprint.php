<?php
define('PAGE_TITLE','Final Blueprint Builder');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
requireAuth();
$baseUrl=getBaseUrl();
require_once __DIR__.'/../../includes/header.php';
?>
<main class="max-w-screen-2xl mx-auto px-4 py-6 space-y-5">
 <section class="rounded-3xl bg-gradient-to-br from-indigo-950 via-slate-900 to-slate-800 text-white p-6 md:p-8">
  <div class="text-xs uppercase tracking-[.2em] text-indigo-200 font-bold">Question planning workspace</div>
  <h1 class="text-2xl md:text-3xl font-black mt-2">Final Blueprint Builder</h1>
  <p class="text-slate-300 mt-2 max-w-3xl">Choose the course and exam context, define each question location, and select an approved question matching the unit, sub-unit, question type, K-level, CO-level and marks.</p>
 </section>
 <section class="bg-white border rounded-2xl p-5 space-y-4">
  <h2 class="font-extrabold text-lg">1. Blueprint details</h2>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
   <label class="text-sm font-semibold">Approved course<select id="course" class="mt-1 w-full border rounded-xl px-3 py-2.5"><option value="">Loading courses…</option></select></label>
   <label class="text-sm font-semibold">Blueprint name<input id="name" class="mt-1 w-full border rounded-xl px-3 py-2.5" placeholder="e.g. Nov 2026 — Final Blueprint"></label>
   <label class="text-sm font-semibold">Exam type<input id="exam" class="mt-1 w-full border rounded-xl px-3 py-2.5" placeholder="Semester Examination"></label>
  </div>
  <div id="courseMeta" class="text-sm text-slate-500"></div>
 </section>
 <section class="bg-white border rounded-2xl p-5 space-y-4">
  <div class="flex flex-wrap justify-between items-center gap-3"><div><h2 class="font-extrabold text-lg">2. Question locations</h2><p class="text-sm text-slate-500">Each row becomes a numbered slot in the final blueprint.</p></div><button id="addRow" class="rounded-xl border px-4 py-2 font-bold">+ Add location</button></div>
  <div id="rows" class="space-y-3"></div>
  <div class="flex flex-wrap justify-between items-center gap-3 border-t pt-4"><span id="count" class="text-sm text-slate-500">0 selected questions</span><button id="save" class="rounded-xl bg-indigo-600 text-white px-5 py-3 font-bold hover:bg-indigo-700">Save final blueprint</button></div>
 </section>
 <section class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-sm text-amber-900"><strong>Important:</strong> Only questions already approved and published to the final question pool are selectable. A selected question is revalidated by the server during save to prevent stale IDs or mismatched course criteria.</section>
</main>
<script>
const api='<?= htmlspecialchars($baseUrl,ENT_QUOTES) ?>/api/final_blueprint.php';
let courses=[], rowSeq=0;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function call(url,opts={}){const r=await fetch(url,opts);const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Request failed');return j.data||{};}
function selectedCourse(){return courses.find(c=>c.paper_code===document.getElementById('course').value)||null;}
function updateMeta(){const c=selectedCourse();document.getElementById('courseMeta').textContent=c?`${c.paper_code} · ${c.course_title} · Semester ${c.semester} · ${c.academic_year} · ${c.exam_type||''}`:'Choose an approved course to begin.';if(c&&!document.getElementById('exam').value)document.getElementById('exam').value=c.exam_type||'Semester Examination';}
function addRow(){
 const id=++rowSeq, box=document.createElement('article');box.className='row-card border rounded-2xl p-4 space-y-3';box.dataset.row=id;
 box.innerHTML=`<div class="flex justify-between items-center"><strong>Question location <span class="row-num">${document.querySelectorAll('.row-card').length+1}</span></strong><button type="button" class="remove text-rose-600 text-sm font-bold">Remove</button></div>
 <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2">
 <label class="text-xs font-bold">Q.No<input class="qn mt-1 w-full border rounded-lg px-2 py-2" value="${document.querySelectorAll('.row-card').length+1}"></label>
 <label class="text-xs font-bold">Section<select class="section mt-1 w-full border rounded-lg px-2 py-2"><option>SECTION-A</option><option>SECTION-B</option><option>SECTION-C</option><option>SECTION-D</option></select></label>
 <label class="text-xs font-bold">Unit<input type="number" min="1" class="unit mt-1 w-full border rounded-lg px-2 py-2" value="1"></label>
 <label class="text-xs font-bold">Sub-unit<input class="sub mt-1 w-full border rounded-lg px-2 py-2" value="1"></label>
 <label class="text-xs font-bold">Type<select class="type mt-1 w-full border rounded-lg px-2 py-2"><option>MCQ</option><option>VSA</option><option>MATCH</option><option>ASSERTION_REASON</option><option>SHORT_ANSWER</option><option>ESSAY</option></select></label>
 <label class="text-xs font-bold">K-Level<select class="k mt-1 w-full border rounded-lg px-2 py-2"><option>K1</option><option>K2</option><option>K3</option><option>K4</option><option>K5</option><option>K6</option></select></label>
 <label class="text-xs font-bold">CO-Level<select class="co mt-1 w-full border rounded-lg px-2 py-2"><option>CO1</option><option>CO2</option><option>CO3</option><option>CO4</option><option>CO5</option><option>CO6</option></select></label>
 </div>
 <div class="flex flex-wrap items-end gap-3"><label class="text-xs font-bold">Marks<select class="marks mt-1 border rounded-lg px-3 py-2"><option value="1">1 mark</option><option value="5">5 marks</option><option value="10">10 marks</option></select></label><button type="button" class="find rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-bold">Find matching questions</button><span class="result-note text-xs text-slate-500"></span></div>
 <label class="block text-xs font-bold">Approved question<select class="candidate mt-1 w-full border rounded-lg px-3 py-2.5"><option value="">Find candidates first…</option></select></label>
 <p class="preview text-sm text-slate-600 whitespace-pre-wrap"></p>`;
 document.getElementById('rows').appendChild(box);
 box.querySelector('.remove').onclick=()=>{box.remove();renumber();};
 box.querySelector('.find').onclick=()=>findCandidates(box);
 box.querySelector('.candidate').onchange=()=>{const o=box.querySelector('.candidate').selectedOptions[0];box.querySelector('.preview').textContent=o?.dataset.text||'';refreshCount();};
 box.querySelector('.section').onchange=()=>{const s=box.querySelector('.section').value;box.querySelector('.marks').value=s==='SECTION-A'?'1':s==='SECTION-B'?'5':'10';};
 box.querySelectorAll('input,select').forEach(el=>el.addEventListener('change',()=>{box.querySelector('.candidate').innerHTML='<option value="">Criteria changed — find candidates again</option>';box.querySelector('.preview').textContent='';refreshCount();}));
 renumber();
}
async function findCandidates(box){
 const c=selectedCourse();if(!c){alert('Select an approved course first.');return;}
 const p=new URLSearchParams({action:'candidates',paper_code:c.paper_code,semester:c.semester,academic_year:c.academic_year,unit_no:box.querySelector('.unit').value,sub_unit:box.querySelector('.sub').value,question_type:box.querySelector('.type').value,k_level:box.querySelector('.k').value,co_level:box.querySelector('.co').value,marks:box.querySelector('.marks').value});
 const note=box.querySelector('.result-note'),sel=box.querySelector('.candidate');note.textContent='Searching…';sel.innerHTML='<option value="">Loading…</option>';
 try{const d=await call(api+'?'+p.toString());sel.innerHTML='<option value="">Choose a question…</option>'+d.questions.map(q=>`<option value="${q.id}" data-bank="${q.bank_id}" data-text="${esc(q.question_text)}" data-type="${esc(q.question_type)}" data-unit="${q.unit_no}" data-sub="${esc(q.sub_unit)}" data-marks="${q.marks}" data-k="${esc(q.k_level)}" data-co="${esc(q.co_level)}" data-qno="${q.q_number}">#${q.q_number} · ${esc(q.question_type)} · ${q.marks}m · ${esc(q.question_text).slice(0,130)}</option>`).join('');note.textContent=`${d.questions.length} matching questions`;}catch(e){note.textContent=e.message;sel.innerHTML='<option value="">No candidates loaded</option>';}
}
function renumber(){document.querySelectorAll('.row-card').forEach((r,i)=>r.querySelector('.row-num').textContent=i+1);refreshCount();}
function refreshCount(){document.getElementById('count').textContent=`${document.querySelectorAll('.candidate option:checked[value]').length} selected questions`;}
document.getElementById('course').onchange=updateMeta;document.getElementById('addRow').onclick=addRow;
document.getElementById('save').onclick=async()=>{
 const c=selectedCourse();if(!c){alert('Select an approved course.');return;}
 const rows=[...document.querySelectorAll('.row-card')];if(!rows.length){alert('Add at least one question location.');return;}
 const items=rows.map(r=>{const o=r.querySelector('.candidate').selectedOptions[0];return {question_number:r.querySelector('.qn').value.trim(),section_type:r.querySelector('.section').value,unit_no:Number(r.querySelector('.unit').value),sub_unit:r.querySelector('.sub').value.trim(),question_type:r.querySelector('.type').value,k_level:r.querySelector('.k').value,co_level:r.querySelector('.co').value,marks:Number(r.querySelector('.marks').value),question_id:Number(o?.value||0),choice_group:null};});
 if(items.some(i=>!i.question_number||!i.question_id)){alert('Every row needs a question number and a selected approved question.');return;}
 const fd=new FormData();fd.set('action','save');fd.set('blueprint_name',document.getElementById('name').value.trim());fd.set('paper_code',c.paper_code);fd.set('course_title',c.course_title||'');fd.set('semester',c.semester);fd.set('academic_year',c.academic_year);fd.set('exam_type',document.getElementById('exam').value.trim()||c.exam_type||'Semester Examination');fd.set('items_json',JSON.stringify(items));
 try{const d=await call(api,{method:'POST',body:fd});alert(`Final blueprint saved successfully. ID: ${d.blueprint_id}`);location.reload();}catch(e){alert(e.message);}
};
(async()=>{try{const d=await call(api+'?action=courses');courses=d.courses||[];const sel=document.getElementById('course');sel.innerHTML='<option value="">Select an approved course…</option>'+courses.map(c=>`<option value="${esc(c.paper_code)}">${esc(c.paper_code)} — ${esc(c.course_title)} (Sem ${esc(c.semester)}, ${esc(c.academic_year)})</option>`).join('');updateMeta();addRow();}catch(e){document.getElementById('course').innerHTML='<option value="">Could not load courses</option>';document.getElementById('courseMeta').textContent=e.message;}})();
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
