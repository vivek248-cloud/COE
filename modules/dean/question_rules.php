<?php
/** Dean Question Paper Template Builder (UI/UX refresh). Auth, DB, API contract and payload shape unchanged. */
define('PAGE_TITLE', 'Question Paper Templates');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
requireDean();
$pdo = getDBConnection();

if (empty($_SESSION['qps_dean_templates_csrf'])) {
    $_SESSION['qps_dean_templates_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['qps_dean_templates_csrf'];

// Dean authorization is handled by requireDean(). The Dean portal is enabled
// through pr_x_xxxx_staf_prof_mast.deanstatus = 1 at login; no separate
// qps_dean_users mapping table is required.
$staffCode = trim((string)(getCurrentUser()['staff_code'] ?? ''));
if ($staffCode === '') {
    http_response_code(403);
    exit('Unable to identify the logged-in Dean.');
}

// Sections may come from the ERP master table. Fall back to the standard college list.
$sectionOptions = ['A'];//'B','C','D','E','F'
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'sections'")->fetchColumn();
    if ($tables) {
        $cols = $pdo->query('SHOW COLUMNS FROM sections')->fetchAll(PDO::FETCH_COLUMN);
        $nameCol = in_array('section_name',$cols,true) ? 'section_name' : (in_array('name',$cols,true) ? 'name' : null);
        if ($nameCol) {
            $q = $pdo->query('SELECT DISTINCT `'.$nameCol.'` FROM sections WHERE `'.$nameCol.'` IS NOT NULL AND TRIM(`'.$nameCol.'`)<>\'\' ORDER BY `'.$nameCol.'`');
            $vals = $q->fetchAll(PDO::FETCH_COLUMN);
            if ($vals) $sectionOptions = array_values(array_map('strval',$vals));
        }
    }
} catch (Throwable $e) { error_log('QPS template section lookup: '.$e->getMessage()); }
require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<style>
 .qb-input{width:100%;border:1px solid #cbd5e1;border-radius:.75rem;padding:.6rem .85rem;background:#fff;font-weight:500;color:#0f172a;transition:box-shadow .15s,border-color .15s}
 .qb-input:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.18)}
 .qb-label{display:block;font-size:.8rem;font-weight:700;color:#334155}
 .qb-btn{display:inline-flex;align-items:center;gap:.4rem;border-radius:.75rem;padding:.55rem 1rem;font-size:.875rem;font-weight:700;transition:background .15s,transform .05s}
 .qb-btn:active{transform:translateY(1px)}
 .qb-btn:focus-visible,.qb-ico:focus-visible{outline:2px solid #4f46e5;outline-offset:2px}
 .qb-ico{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:.6rem;color:#475569;transition:background .15s,color .15s}
 .qb-ico:hover{background:#eef2ff;color:#4338ca}.qb-ico.danger:hover{background:#fff1f2;color:#be123c}.qb-ico:disabled{opacity:.3;pointer-events:none}
 #rulesTable thead th{position:sticky;top:0;z-index:2}
 #rulesBody tr.slot-row:hover{background:#f8fafc}
 .chip{display:inline-flex;align-items:center;border-radius:999px;padding:.15rem .6rem;font-size:.75rem;font-weight:700}
 .pill input:checked + span{background:#4f46e5;color:#fff;border-color:#4f46e5}
 .pill input:focus-visible + span{outline:2px solid #4f46e5;outline-offset:2px}
 @media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
<main class="p-4 md:p-8 max-w-6xl mx-auto space-y-6 pb-32">
 <header class="flex flex-wrap items-end justify-between gap-4">
  <div>
   <p class="text-sm font-semibold text-indigo-600">Dean › Configuration</p>
   <h1 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Question paper templates</h1>
   <p class="text-sm text-slate-600 mt-1 max-w-xl">Define the structure of a paper once. Teaching staff pick a saved template and assign units and questions to each slot.</p>
  </div>
  <div class="flex items-center gap-2">
   <span id="modeBadge" class="chip bg-emerald-50 text-emerald-700">New template</span>
   <span id="dirtyBadge" class="chip bg-amber-50 text-amber-700 hidden">Unsaved changes</span>
  </div>
 </header>

 <!-- 1. Header -->
 <section class="bg-white border border-slate-200 rounded-2xl shadow-sm">
  <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
   <div><h2 class="font-black text-slate-800">Template details</h2><p class="text-xs text-slate-500">Who the paper is for and how many marks it carries.</p></div>
  </div>
  <div class="p-5 grid grid-cols-1 md:grid-cols-6 gap-4">
   <input type="hidden" id="templateId" value="">
   <label class="qb-label md:col-span-3">Template name
    <input id="templateName" maxlength="150" class="qb-input mt-1" placeholder="e.g. UG 50 marks internal pattern" required>
   </label>
   <div class="md:col-span-3">
    <label class="qb-label" for="totalMarks">Total marks</label>
    <div class="flex items-center gap-2 mt-1">
     <div class="relative flex-1">
      <input id="totalMarks" type="number" min="1" max="200" step="1" class="qb-input pr-10" placeholder="50" required>
      <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm font-bold text-slate-400 pointer-events-none">M</span>
     </div>
     <div class="flex gap-1" role="group" aria-label="Quick total marks">
      <?php foreach([30,50,75,100] as $m): ?><button type="button" data-quick="<?=$m?>" class="qb-btn bg-slate-100 hover:bg-slate-200 text-slate-700 !px-3"><?=$m?></button><?php endforeach; ?>
     </div>
    </div>
   </div>
   <label class="qb-label md:col-span-2">Academic year
    <input id="academicYear" class="qb-input mt-1" placeholder="2026-2027" inputmode="numeric" required>
    <span id="yearHint" class="text-xs text-rose-600 hidden">Use the format 2026-2027.</span>
   </label>
   <label class="qb-label md:col-span-2">Year of programme
    <select id="programmeYear" class="qb-input mt-1"><option value="">Select year</option><option>I</option><option>II</option><option>III</option><option>IV</option></select>
   </label>
   <label class="qb-label md:col-span-2">Semester
    <select id="semester" class="qb-input mt-1"><option value="">Select semester</option><?php for($i=1;$i<=8;$i++): ?><option value="<?=$i?>">Semester <?=$i?></option><?php endfor; ?></select>
   </label>
   <fieldset class="md:col-span-6">
    <legend class="qb-label">Class sections <span class="font-normal text-slate-500">(tap to select one or more)</span></legend>
    <div id="classSections" class="mt-2 flex flex-wrap gap-2">
     <!-- <?php foreach($sectionOptions as $s): $e=htmlspecialchars($s,ENT_QUOTES,'UTF-8'); ?> -->
      <label class="pill cursor-pointer"><input type="checkbox" class="sr-only" value="<?=$e?>"><span class="inline-flex min-w-[2.5rem] justify-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold text-slate-700 hover:border-indigo-400 transition"><?=$e?></span></label>
     <!-- <?php endforeach; ?> -->
     <button type="button" id="selectAllSections" class="text-xs font-bold text-indigo-600 px-2">Select all</button>
    </div>
   </fieldset>
  </div>
 </section>

 <!-- 2. Rules -->
 <section class="bg-white border border-slate-200 rounded-2xl shadow-sm">
  <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
   <div><h2 class="font-black text-slate-800">Question rules</h2><p class="text-xs text-slate-500">Group slots under paper sections. Each slot carries marks, K level and CO level.</p></div>
   <div class="flex flex-wrap items-center gap-2">
    <label class="text-xs text-slate-600 flex items-center gap-2 mr-2"><input type="checkbox" id="renumber" class="rounded"> Renumber labels after delete</label>
    <button id="addSection" type="button" class="qb-btn bg-slate-100 hover:bg-slate-200 text-slate-800">+ Add section</button>
    <button id="addSlot" type="button" class="qb-btn bg-indigo-600 hover:bg-indigo-700 text-white">+ Add slot</button>
   </div>
  </div>
  <div class="overflow-x-auto max-h-[60vh]">
   <table class="w-full min-w-[720px] text-sm" id="rulesTable">
    <thead class="bg-slate-50 text-slate-600 text-left">
     <tr><th class="px-5 py-3 font-bold border-b border-slate-200">Question</th><th class="px-5 py-3 font-bold border-b border-slate-200 w-32">K level</th><th class="px-5 py-3 font-bold border-b border-slate-200 w-32">CO level</th><th class="px-5 py-3 font-bold border-b border-slate-200 w-52 text-right">Action</th></tr>
    </thead>
    <tbody id="rulesBody"></tbody>
   </table>
  </div>
 </section>

 <!-- 3. Saved -->
 <section class="bg-white border border-slate-200 rounded-2xl shadow-sm">
  <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
   <div><h2 class="font-black text-slate-800">Saved templates</h2><p class="text-xs text-slate-500">Edit, duplicate or remove templates you have created.</p></div>
   <div class="flex gap-2">
    <input id="templateSearch" type="search" class="qb-input !w-56" placeholder="Search templates">
    <button id="refreshTemplates" type="button" class="qb-btn border border-slate-300 hover:bg-slate-50">Refresh</button>
   </div>
  </div>
  <div id="templateList" class="p-5 grid grid-cols-1 md:grid-cols-2 gap-3"><p class="text-sm text-slate-500">Loading templates…</p></div>
 </section>
</main>

<!-- Sticky action bar -->
<div class="fixed bottom-0 inset-x-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur">
 <div class="max-w-6xl mx-auto px-4 md:px-8 py-3 flex flex-wrap items-center gap-4">
  <div class="flex-1 min-w-[240px]">
   <div class="flex items-center justify-between text-sm font-bold"><span id="marksText" class="text-slate-800">0 of 0 marks allocated</span><span id="marksState" class="chip bg-amber-50 text-amber-700">Set total marks</span></div>
   <div class="mt-2 h-2 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="marksBarWrap"><div id="marksBar" class="h-full w-0 bg-amber-500 transition-all duration-300"></div></div>
  </div>
  <div class="flex gap-2">
   <button type="button" id="resetBuilder" class="qb-btn border border-slate-300 hover:bg-slate-50">Clear</button>
   <button type="button" id="saveTemplate" class="qb-btn bg-indigo-600 hover:bg-indigo-700 text-white !px-5 !py-3">Save as question paper template</button>
  </div>
 </div>
</div>

<!-- Slot modal -->
<div id="slotModal" class="hidden fixed inset-0 z-[100] bg-slate-950/50 p-4 items-center justify-center" role="dialog" aria-modal="true" aria-labelledby="slotModalTitle">
 <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
  <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100"><h3 id="slotModalTitle" class="text-lg font-black">Add question slot</h3><button id="closeSlotModal" type="button" class="qb-ico text-xl" aria-label="Close">×</button></div>
  <div class="p-5 space-y-4">
   <input type="hidden" id="editSlotId">
   <label class="qb-label">Paper section<select id="slotSection" class="qb-input mt-1"></select></label>
   <div class="grid grid-cols-3 gap-3">
    <label class="qb-label col-span-2">Slot label<input id="slotLabel" maxlength="40" class="qb-input mt-1" placeholder="Q1 or Q3(a)"></label>
    <label class="qb-label">Marks<input id="slotMarks" type="number" min="1" max="200" step="1" class="qb-input mt-1"></label>
   </div>
   <div class="grid grid-cols-2 gap-3">
    <label class="qb-label">K level<select id="slotK" class="qb-input mt-1"><option value="">Select</option><?php for($i=1;$i<=6;$i++): ?><option>K<?=$i?></option><?php endfor; ?></select></label>
    <label class="qb-label">CO level<select id="slotCo" class="qb-input mt-1"><option value="">Select</option><?php for($i=1;$i<=6;$i++): ?><option>CO<?=$i?></option><?php endfor; ?></select></label>
   </div>
   <p id="slotError" class="hidden text-sm font-semibold text-rose-600"></p>
  </div>
  <div class="flex flex-wrap justify-end gap-2 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
   <button id="cancelSlot" type="button" class="qb-btn border border-slate-300 bg-white">Cancel</button>
   <button id="saveSlotNext" type="button" class="qb-btn bg-indigo-50 text-indigo-700 hover:bg-indigo-100">Save and add next</button>
   <button id="saveSlot" type="button" class="qb-btn bg-indigo-600 hover:bg-indigo-700 text-white">Save slot</button>
  </div>
 </div>
</div>

<script>
const API=<?=json_encode(getBaseUrl().'/api/dean_question_templates.php')?>, CSRF=<?=json_encode($csrf)?>;
const $=id=>document.getElementById(id);
let sections=[], slots=[], templates=[], dirty=false;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const toast=(icon,title)=>Swal.fire({toast:true,position:'top-end',icon,title,showConfirmButton:false,timer:2200,timerProgressBar:true});
const uid=p=>p+Date.now()+Math.random().toString(16).slice(2);

async function api(action,data={}){
  const r=await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf:CSRF,...data})});
  let j;try{j=await r.json()}catch(e){throw Error('Server returned an invalid response.')}
  if(!r.ok||!j.success)throw Error(j.message||'Request failed');
  return j;
}
const sectionBoxes=()=>[...document.querySelectorAll('#classSections input[type=checkbox]')];
const selectedSections=()=>sectionBoxes().filter(c=>c.checked).map(c=>c.value);
const allocated=()=>slots.reduce((n,s)=>n+Number(s.marks||0),0);
const total=()=>Number($('totalMarks').value||0);

function markDirty(v=true){dirty=v;$('dirtyBadge').classList.toggle('hidden',!v)}
function setMode(){const id=$('templateId').value;const b=$('modeBadge');b.textContent=id?'Editing saved template':'New template';b.className='chip '+(id?'bg-indigo-50 text-indigo-700':'bg-emerald-50 text-emerald-700')}

function summary(){
  const a=allocated(),t=total(),diff=a-t;
  $('marksText').textContent=`${a} of ${t} marks allocated`;
  const st=$('marksState'),bar=$('marksBar');
  let cls,txt,col;
  if(t<1){cls='bg-amber-50 text-amber-700';txt='Set total marks';col='bg-amber-500'}
  else if(diff===0){cls='bg-emerald-50 text-emerald-700';txt='Balanced, ready to save';col='bg-emerald-500'}
  else if(diff>0){cls='bg-rose-50 text-rose-700';txt=`${diff} marks over`;col='bg-rose-500'}
  else{cls='bg-amber-50 text-amber-700';txt=`${Math.abs(diff)} marks remaining`;col='bg-amber-500'}
  st.className='chip '+cls;st.textContent=txt;
  const pct=t>0?Math.min(100,Math.round(a/t*100)):0;
  bar.className='h-full transition-all duration-300 '+col;bar.style.width=pct+'%';
  $('marksBarWrap').setAttribute('aria-valuenow',pct);
}

const kClass=k=>({K1:'bg-sky-50 text-sky-700',K2:'bg-sky-50 text-sky-700',K3:'bg-indigo-50 text-indigo-700',K4:'bg-indigo-50 text-indigo-700',K5:'bg-violet-50 text-violet-700',K6:'bg-violet-50 text-violet-700'}[k]||'bg-slate-100 text-slate-600');
const ico={
 up:'<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5l6 7H4z"/></svg>',
 down:'<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path d="M10 15L4 8h12z"/></svg>',
 edit:'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13.5 3.5l3 3L7 16H4v-3z"/></svg>',
 copy:'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="7" y="7" width="9" height="9" rx="2"/><path d="M4 13V5a1 1 0 011-1h8"/></svg>',
 del:'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h12M8 6V4h4v2m-6 0l1 10h6l1-10"/></svg>'
};
const iconBtn=(attr,id,title,svg,extra='')=>`<button type="button" ${attr}="${esc(id)}" title="${title}" aria-label="${title}" class="qb-ico ${extra}">${svg}</button>`;

function render(){
  const body=$('rulesBody');body.innerHTML='';
  if(!sections.length){
    body.innerHTML=`<tr><td colspan="4" class="px-5 py-14 text-center"><div class="mx-auto max-w-sm space-y-3"><div class="text-4xl">🗂️</div><p class="font-black text-slate-800">No sections yet</p><p class="text-sm text-slate-500">Start with a paper section such as Section A, then add question slots to it.</p><div class="flex justify-center gap-2"><button type="button" data-empty-add class="qb-btn bg-indigo-600 text-white hover:bg-indigo-700">+ Add section</button><button type="button" data-empty-preset class="qb-btn bg-slate-100 hover:bg-slate-200">Use Section A, B, C</button></div></div></td></tr>`;
    body.querySelector('[data-empty-add]').onclick=addSection;
    body.querySelector('[data-empty-preset]').onclick=()=>{['Section A','Section B','Section C'].forEach(n=>sections.push({id:uid('s'),section_name:n,sort_order:sections.length+1}));render();markDirty()};
    summary();return;
  }
  sections.forEach(sec=>{
    const own=slots.filter(s=>String(s.section_id)===String(sec.id));
    const sub=own.reduce((n,s)=>n+Number(s.marks||0),0);
    const head=document.createElement('tr');
    head.className='bg-indigo-50/70 border-y border-indigo-100';
    head.innerHTML=`<td colspan="4" class="px-5 py-3"><div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-3"><span class="font-black text-indigo-900">${esc(sec.section_name)}</span><span class="chip bg-white text-indigo-700 border border-indigo-100">${own.length} slot${own.length===1?'':'s'}</span><span class="chip bg-white text-slate-700 border border-slate-200">${sub} marks</span></div>
      <div class="flex items-center gap-1"><button type="button" data-add-in="${esc(sec.id)}" class="qb-btn !py-1 !px-3 bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50">+ Slot</button>${iconBtn('data-ren-section',sec.id,'Rename section',ico.edit)}${iconBtn('data-del-section',sec.id,'Delete section',ico.del,'danger')}</div></div></td>`;
    body.appendChild(head);
    if(!own.length){
      const e=document.createElement('tr');
      e.innerHTML=`<td colspan="4" class="px-5 py-5 text-center text-sm text-slate-500">No slots in this section. <button type="button" data-add-in="${esc(sec.id)}" class="font-bold text-indigo-600">Add the first slot</button></td>`;
      body.appendChild(e);
    }
    own.forEach((slot,i)=>{
      const tr=document.createElement('tr');tr.className='slot-row border-b border-slate-100';
      tr.innerHTML=`<td class="px-5 py-3"><span class="font-bold text-slate-900">${esc(slot.slot_label)}</span> <span class="chip ml-2 bg-slate-100 text-slate-700">${Number(slot.marks)}M</span></td>
       <td class="px-5 py-3"><span class="chip ${kClass(slot.k_level)}">${esc(slot.k_level)}</span></td>
       <td class="px-5 py-3"><span class="chip bg-emerald-50 text-emerald-700">${esc(slot.co_level)}</span></td>
       <td class="px-5 py-3"><div class="flex justify-end gap-0.5">
        <button type="button" data-up="${esc(slot.id)}" title="Move up" aria-label="Move up" class="qb-ico" ${i===0?'disabled':''}>${ico.up}</button>
        <button type="button" data-down="${esc(slot.id)}" title="Move down" aria-label="Move down" class="qb-ico" ${i===own.length-1?'disabled':''}>${ico.down}</button>
        ${iconBtn('data-dup-slot',slot.id,'Duplicate slot',ico.copy)}${iconBtn('data-edit-slot',slot.id,'Edit slot',ico.edit)}${iconBtn('data-delete-slot',slot.id,'Delete slot',ico.del,'danger')}</div></td>`;
      body.appendChild(tr);
    });
  });
  const on=(sel,attr,fn)=>body.querySelectorAll(sel).forEach(b=>b.onclick=()=>fn(b.getAttribute(attr)));
  on('[data-edit-slot]','data-edit-slot',openSlot);on('[data-delete-slot]','data-delete-slot',deleteSlot);
  on('[data-del-section]','data-del-section',deleteSection);on('[data-ren-section]','data-ren-section',renameSection);
  on('[data-add-in]','data-add-in',id=>openModal(null,id));on('[data-dup-slot]','data-dup-slot',dupSlot);
  on('[data-up]','data-up',id=>moveSlot(id,-1));on('[data-down]','data-down',id=>moveSlot(id,1));
  summary();
}

function sectionSelect(){$('slotSection').innerHTML=sections.map(s=>`<option value="${esc(s.id)}">${esc(s.section_name)}</option>`).join('')}
function nextLabel(){return 'Q'+(slots.length+1)}
function slotErr(msg){const e=$('slotError');e.textContent=msg||'';e.classList.toggle('hidden',!msg)}
function openModal(slot=null,sectionId=null){
  if(!sections.length){Swal.fire('Add a section first','Create at least one paper section before adding a slot.','info');return}
  sectionSelect();slotErr('');
  $('editSlotId').value=slot?.id||'';
  $('slotModalTitle').textContent=slot?'Edit question slot':'Add question slot';
  $('slotSection').value=slot?.section_id||sectionId||sections[sections.length-1].id;
  $('slotLabel').value=slot?.slot_label||nextLabel();
  $('slotMarks').value=slot?.marks||'';$('slotK').value=slot?.k_level||'';$('slotCo').value=slot?.co_level||'';
  $('saveSlotNext').classList.toggle('hidden',!!slot);
  $('slotModal').classList.remove('hidden');$('slotModal').classList.add('flex');
  setTimeout(()=>{$('slotLabel').focus();$('slotLabel').select()},30);
}
function closeModal(){$('slotModal').classList.add('hidden');$('slotModal').classList.remove('flex')}
function openSlot(id){const s=slots.find(x=>String(x.id)===String(id));if(s)openModal(s)}

function commitSlot(keepOpen){
  const label=$('slotLabel').value.trim(),marks=Number($('slotMarks').value),k=$('slotK').value,co=$('slotCo').value,section_id=$('slotSection').value;
  if(!label)return slotErr('Enter a slot label such as Q1.');
  if(!Number.isInteger(marks)||marks<1||marks>200)return slotErr('Marks must be a whole number from 1 to 200.');
  if(!k)return slotErr('Choose a K level.');
  if(!co)return slotErr('Choose a CO level.');
  if(!section_id)return slotErr('Choose a section.');
  const id=$('editSlotId').value;
  if(id){Object.assign(slots.find(x=>String(x.id)===String(id)),{slot_label:label,marks,k_level:k,co_level:co,section_id})}
  else slots.push({id:uid('p'),slot_label:label,marks,k_level:k,co_level:co,section_id,sort_order:slots.length+1,unit_id:null,question_id:null});
  markDirty();render();
  if(keepOpen&&!id){
    $('slotLabel').value=nextLabel();$('slotMarks').value='';slotErr('');$('slotMarks').focus();toast('success','Slot added');
  }else{closeModal();toast('success',id?'Slot updated':'Slot added')}
}

function addSection(){
  Swal.fire({title:'Add paper section',input:'text',inputLabel:'Section name',inputPlaceholder:'Section A',showCancelButton:true,confirmButtonText:'Add section',inputValidator:v=>!v?.trim()?'Enter a section name':null}).then(r=>{
    if(!r.isConfirmed)return;const name=r.value.trim();
    if(sections.some(s=>s.section_name.toLowerCase()===name.toLowerCase()))return Swal.fire('Already exists','This section is already added.','warning');
    sections.push({id:uid('s'),section_name:name,sort_order:sections.length+1});markDirty();render();
  });
}
function renameSection(id){
  const sec=sections.find(s=>String(s.id)===String(id));if(!sec)return;
  Swal.fire({title:'Rename section',input:'text',inputValue:sec.section_name,showCancelButton:true,inputValidator:v=>!v?.trim()?'Enter a section name':(sections.some(s=>s!==sec&&s.section_name.toLowerCase()===v.trim().toLowerCase())?'That name is already used':null)}).then(r=>{if(r.isConfirmed){sec.section_name=r.value.trim();markDirty();render()}});
}
function deleteSection(id){
  const sec=sections.find(s=>String(s.id)===String(id));if(!sec)return;
  const count=slots.filter(s=>String(s.section_id)===String(id)).length;
  Swal.fire({icon:'warning',title:'Delete section?',text:`${sec.section_name} and its ${count} slot(s) will be removed.`,showCancelButton:true,confirmButtonText:'Delete section',confirmButtonColor:'#e11d48'}).then(r=>{
    if(!r.isConfirmed)return;
    sections=sections.filter(s=>String(s.id)!==String(id));slots=slots.filter(s=>String(s.section_id)!==String(id));
    if($('renumber').checked)renumber();markDirty();render();toast('success','Section deleted');
  });
}
function deleteSlot(id){
  const slot=slots.find(s=>String(s.id)===String(id));if(!slot)return;
  Swal.fire({icon:'warning',title:'Delete this slot?',text:`${slot.slot_label} (${slot.marks}M) will be removed.`,showCancelButton:true,confirmButtonText:'Delete',confirmButtonColor:'#e11d48'}).then(r=>{
    if(!r.isConfirmed)return;slots=slots.filter(s=>String(s.id)!==String(id));
    if($('renumber').checked)renumber();markDirty();render();toast('success','Slot deleted');
  });
}
function dupSlot(id){
  const i=slots.findIndex(s=>String(s.id)===String(id));if(i<0)return;
  slots.splice(i+1,0,{...slots[i],id:uid('p'),slot_label:slots[i].slot_label+' copy',unit_id:null,question_id:null});
  markDirty();render();toast('success','Slot duplicated');
}
function moveSlot(id,dir){
  const i=slots.findIndex(s=>String(s.id)===String(id));if(i<0)return;
  const sid=String(slots[i].section_id);let j=i+dir;
  while(j>=0&&j<slots.length&&String(slots[j].section_id)!==sid)j+=dir;
  if(j<0||j>=slots.length)return;
  [slots[i],slots[j]]=[slots[j],slots[i]];markDirty();render();
}
function renumber(){let n=1;sections.forEach(sec=>slots.filter(s=>String(s.section_id)===String(sec.id)).forEach(s=>s.slot_label='Q'+n++))}
function normalizeOrder(){
  sections.forEach((s,i)=>s.sort_order=i+1);
  let n=1;sections.forEach(sec=>slots.filter(s=>String(s.section_id)===String(sec.id)).forEach(s=>s.sort_order=n++));
}

/* Events */
$('saveSlot').onclick=()=>commitSlot(false);
$('saveSlotNext').onclick=()=>commitSlot(true);
$('addSection').onclick=addSection;$('addSlot').onclick=()=>openModal();
$('closeSlotModal').onclick=$('cancelSlot').onclick=closeModal;
$('slotModal').addEventListener('mousedown',e=>{if(e.target===$('slotModal'))closeModal()});
$('slotModal').addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.tagName!=='BUTTON'){e.preventDefault();commitSlot(false)}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('slotModal').classList.contains('hidden'))closeModal()});
$('totalMarks').oninput=()=>{summary();markDirty()};
document.querySelectorAll('[data-quick]').forEach(b=>b.onclick=()=>{$('totalMarks').value=b.dataset.quick;summary();markDirty()});
['templateName','academicYear','programmeYear','semester'].forEach(id=>$(id).addEventListener('input',()=>markDirty()));
$('academicYear').addEventListener('blur',()=>$('yearHint').classList.toggle('hidden',!$('academicYear').value||/^\d{4}-\d{4}$/.test($('academicYear').value.trim())));
sectionBoxes().forEach(c=>c.addEventListener('change',()=>markDirty()));
$('selectAllSections').onclick=()=>{const all=sectionBoxes().every(c=>c.checked);sectionBoxes().forEach(c=>c.checked=!all);markDirty()};
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue=''}});

function clearBuilder(){
  $('templateId').value='';['templateName','totalMarks','academicYear','programmeYear','semester'].forEach(id=>$(id).value='');
  sectionBoxes().forEach(c=>c.checked=false);sections=[];slots=[];markDirty(false);setMode();render();
}
$('resetBuilder').onclick=()=>Swal.fire({title:'Clear builder?',text:'Unsaved sections and slots will be removed.',showCancelButton:true,confirmButtonText:'Clear'}).then(r=>{if(r.isConfirmed)clearBuilder()});

$('saveTemplate').onclick=async()=>{
  const marks=Number($('totalMarks').value),year=$('academicYear').value.trim(),name=$('templateName').value.trim();
  if(!name||!Number.isInteger(marks)||marks<1||marks>200||!/^\d{4}-\d{4}$/.test(year)||!$('programmeYear').value||!$('semester').value||!selectedSections().length){
    Swal.fire('Complete template details','Fill every field in Template details. Total marks must be a whole number from 1 to 200, and the academic year looks like 2026-2027.','warning');window.scrollTo({top:0,behavior:'smooth'});return}
  if(!sections.length||!slots.length){Swal.fire('Add sections and slots','At least one section and one slot are required.','warning');return}
  if(slots.some(s=>!s.k_level||!s.co_level)){Swal.fire('Incomplete slot','Every slot needs a K level and a CO level.','warning');return}
  const diff=allocated()-marks;
  if(diff!==0){Swal.fire({icon:'error',title:'Marks total does not match',text:diff>0?`Allocated marks exceed the template total by ${diff}.`:`Allocated marks are short by ${Math.abs(diff)}.`});return}
  normalizeOrder();
  const btn=$('saveTemplate');btn.disabled=true;btn.classList.add('opacity-60');
  try{
    const result=await api('save',{template_id:$('templateId').value||null,template_name:name,total_marks:marks,academic_year:year,year_of_programme:$('programmeYear').value,semester:Number($('semester').value),class_sections:selectedSections(),sections,slots});
    $('templateId').value=result.data.id;markDirty(false);setMode();
    await Swal.fire('Saved',result.message,'success');await loadTemplates();
  }catch(e){Swal.fire('Unable to save',e.message,'error')}
  finally{btn.disabled=false;btn.classList.remove('opacity-60')}
};

/* Saved templates */
function templateCard(t){
  const secs=(t.class_sections||[]).map(s=>`<span class="chip bg-slate-100 text-slate-700">${esc(s)}</span>`).join(' ');
  return `<article class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 transition space-y-3">
   <div class="flex items-start justify-between gap-3"><div><div class="font-black text-slate-900">${esc(t.template_name)}</div><div class="text-xs text-slate-500 mt-0.5">Created ${esc(t.created_at)}</div></div><span class="chip bg-indigo-50 text-indigo-700 whitespace-nowrap">${Number(t.total_marks)} marks</span></div>
   <div class="flex flex-wrap items-center gap-1.5 text-xs"><span class="chip bg-slate-100 text-slate-700">${esc(t.academic_year)}</span><span class="chip bg-slate-100 text-slate-700">Year ${esc(t.year_of_programme)}</span><span class="chip bg-slate-100 text-slate-700">Semester ${Number(t.semester)}</span>${secs}</div>
   <div class="flex gap-2 pt-1"><button data-load="${t.id}" class="qb-btn !py-1.5 bg-indigo-600 text-white hover:bg-indigo-700">Edit</button><button data-duplicate="${t.id}" class="qb-btn !py-1.5 bg-slate-100 hover:bg-slate-200">Duplicate</button><button data-delete="${t.id}" class="qb-btn !py-1.5 text-rose-700 hover:bg-rose-50 ml-auto">Delete</button></div></article>`;
}
function renderTemplates(){
  const q=$('templateSearch').value.trim().toLowerCase();
  const list=templates.filter(t=>!q||[t.template_name,t.academic_year,t.year_of_programme,'semester '+t.semester,(t.class_sections||[]).join(' ')].join(' ').toLowerCase().includes(q));
  const box=$('templateList');
  box.innerHTML=list.length?list.map(templateCard).join(''):`<p class="text-sm text-slate-500 md:col-span-2">${templates.length?'No templates match your search.':'No templates saved yet. Build one above and save it.'}</p>`;
  box.querySelectorAll('[data-load]').forEach(b=>b.onclick=()=>confirmLoad(()=>loadTemplate(b.dataset.load)));
  box.querySelectorAll('[data-duplicate]').forEach(b=>b.onclick=()=>confirmLoad(()=>loadTemplate(b.dataset.duplicate,true)));
  box.querySelectorAll('[data-delete]').forEach(b=>b.onclick=()=>deleteTemplate(b.dataset.delete));
}
async function loadTemplates(){
  try{const r=await api('list');templates=r.data||[];renderTemplates()}
  catch(e){$('templateList').innerHTML=`<p class="text-sm text-rose-600 md:col-span-2">${esc(e.message)}</p>`}
}
function confirmLoad(fn){
  if(!dirty)return fn();
  Swal.fire({title:'Discard unsaved changes?',text:'Loading another template replaces what is in the builder.',showCancelButton:true,confirmButtonText:'Discard and load'}).then(r=>{if(r.isConfirmed)fn()});
}
async function loadTemplate(id,duplicate=false){
  try{
    const r=await api('get',{template_id:Number(id)}),t=r.data;
    $('templateId').value=duplicate?'':t.id;
    $('templateName').value=duplicate?t.template_name+' Copy':t.template_name;
    $('totalMarks').value=t.total_marks;$('academicYear').value=t.academic_year;$('programmeYear').value=t.year_of_programme;$('semester').value=t.semester;
    sectionBoxes().forEach(c=>c.checked=t.class_sections.includes(c.value));
    sections=t.sections.map((s,i)=>({...s,id:'s'+s.id,sort_order:i+1}));
    slots=t.slots.map((s,i)=>({...s,id:'p'+s.id,section_id:'s'+s.section_id,sort_order:i+1}));
    markDirty(duplicate);setMode();render();window.scrollTo({top:0,behavior:'smooth'});
    toast('info',duplicate?'Copy ready. Save to create it.':'Template loaded');
  }catch(e){Swal.fire('Unable to load template',e.message,'error')}
}
async function deleteTemplate(id){
  const r=await Swal.fire({icon:'warning',title:'Delete template?',text:'Templates already assigned to staff cannot be deleted.',showCancelButton:true,confirmButtonText:'Delete',confirmButtonColor:'#e11d48'});
  if(!r.isConfirmed)return;
  try{await api('delete',{template_id:Number(id)});if(String($('templateId').value)===String(id))clearBuilder();await loadTemplates();toast('success','Template deleted')}
  catch(e){Swal.fire('Cannot delete',e.message,'warning')}
}
$('refreshTemplates').onclick=loadTemplates;
$('templateSearch').oninput=renderTemplates;
setMode();render();loadTemplates();
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>