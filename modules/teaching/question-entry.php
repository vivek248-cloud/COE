<?php
define('PAGE_TITLE','Structured Question Bank Entry');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
requireAuth();
$pdo=getDBConnection(); qps_ensure_aux_schema($pdo); qps_ensure_question_draft_schema($pdo);
try { $collegePdo=getCollegeDBConnection(); } catch(Throwable $e) { $collegePdo=null; }
$user=getCurrentUser()?:[]; $staff=(string)($user['staff_code']??''); $courses=[];
try {
 if(!$collegePdo) throw new RuntimeException('College database is not configured.');
 if(isCOE()) { $courses=$collegePdo->query("SELECT c.coursecode AS paper_code,c.coursetitle AS course_title,COALESCE(c.dept_code,'') AS dept_code FROM courses c ORDER BY c.coursecode")->fetchAll(); }
 else { $st=$collegePdo->prepare("SELECT DISTINCT tf.papercode AS paper_code,COALESCE(c.coursetitle,tf.papercode) AS course_title,COALESCE(c.dept_code,tf.deptcode,'') AS dept_code FROM timetablefaculty tf LEFT JOIN courses c ON UPPER(c.coursecode)=UPPER(tf.papercode) WHERE UPPER(tf.fid)=UPPER(?) ORDER BY tf.papercode");$st->execute([$staff]);$courses=$st->fetchAll(); }
} catch(Throwable $e) { $courses=[]; }
require_once __DIR__.'/../../includes/header.php';
?>
<main class="max-w-7xl mx-auto w-full px-4 py-6 space-y-5">
 <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm text-indigo-600 font-semibold">TEACHING PORTAL</p><h1 class="text-2xl md:text-3xl font-bold text-slate-900">Structured Question Bank Entry</h1><p class="text-slate-600 mt-1">Choose your allocated course and add questions in the form. Questions are auto-saved as you type; you can edit or delete them before sending the bank to your HOD.</p></div><a href="dashboard.php" class="rounded-xl border px-4 py-2 bg-white hover:bg-slate-50">Back to dashboard</a></div>
 <div class="grid lg:grid-cols-3 gap-5">
  <section class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm lg:col-span-1 space-y-4">
   <h2 class="font-bold text-lg">1. Question bank details</h2>
   <form id="bankForm" class="space-y-3">
    <label class="block text-sm font-medium">Assigned course <select name="paper_code" id="paper_code" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">Choose course</option><?php foreach($courses as $c): ?><option value="<?=htmlspecialchars($c['paper_code'],ENT_QUOTES)?>" data-title="<?=htmlspecialchars($c['course_title']??'',ENT_QUOTES)?>" data-dept="<?=htmlspecialchars($c['dept_code']??'',ENT_QUOTES)?>"><?=htmlspecialchars($c['paper_code'].' — '.$c['course_title'])?></option><?php endforeach;?></select></label>
    <label class="block text-sm font-medium">Course title <input name="course_title" id="course_title" class="mt-1 w-full rounded-lg border-slate-300" readonly></label>
    <label class="block text-sm font-medium">Semester <select name="semester" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">Select</option><?php for($i=1;$i<=8;$i++):?><option>Semester <?=$i?></option><?php endfor;?></select></label>
    <label class="block text-sm font-medium">Academic year <input name="academic_year" required value="<?=htmlspecialchars(DEFAULT_ACADEMIC_YEAR)?>" class="mt-1 w-full rounded-lg border-slate-300"></label>
    <label class="block text-sm font-medium">Examination <select name="exam_type" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">Select</option><option>Internal Assessment</option><option>Model Examination</option><option>End Semester Examination</option><option>Supplementary Examination</option></select></label>
    <button id="createBank" class="w-full rounded-xl bg-indigo-600 text-white font-semibold py-2.5 hover:bg-indigo-700">Create / Open draft</button>
   </form>
   <div id="draftStatus" class="text-sm text-slate-500">No draft selected.</div>
   <div class="rounded-xl bg-indigo-50 border border-indigo-100 p-3 text-sm"><b>75-mark pattern</b><ul class="list-disc ml-5 mt-1 space-y-1"><li>Section A: 20 × 1 (8 MCQ, 1 Match, 1 Assertion-Reason, 10 VSA)</li><li>Section B: 5 × 5, A/B pairs</li><li>Section C: answer any 2 × 10 marks from 4 questions</li><li>Section D: 1 compulsory × 10 marks</li></ul><p class="mt-2 text-slate-600">Pattern validation will be enforced at submission. Question entry remains editable while the draft is in correction status.</p></div>
   <!-- Real-time validation summary -->
   <div id="validationSummary" class="hidden rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm">
    <h4 class="font-semibold text-amber-800 mb-2">Validation Issues</h4>
    <ul id="validationIssues" class="list-disc ml-5 space-y-1"></ul>
   </div>
  </section>
  <section class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm lg:col-span-2 space-y-4">
   <h2 class="font-bold text-lg">2. Enter question</h2>
   <!-- Auto-save status indicator -->
   <div id="autoSaveStatus" class="flex items-center gap-2 text-sm text-slate-500">
    <span id="autoSaveIndicator" class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-slate-300" id="autoSaveDot"></span><span id="autoSaveText">Ready</span></span>
    <span id="lastSaved" class="text-xs"></span>
   </div>
   <form id="questionForm" class="grid md:grid-cols-2 gap-3" enctype="multipart/form-data">
    <input type="hidden" name="draft_id" id="draft_id"><input type="hidden" name="question_id" id="question_id">
    <label class="block text-sm font-medium">Question text <textarea name="question_text" id="question_text" rows="4" required class="mt-1 w-full rounded-lg border-slate-300" placeholder="Enter the complete question..."></textarea></label>
    <div class="grid grid-cols-2 gap-3">
     <label class="block text-sm font-medium">Unit <select name="unit_no" required class="mt-1 w-full rounded-lg border-slate-300"><?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>">Unit <?=$i?></option><?php endfor;?></select></label>
     <label class="block text-sm font-medium">Subunit <input name="sub_unit" required placeholder="e.g. 1.1" class="mt-1 w-full rounded-lg border-slate-300"></label>
     <label class="block text-sm font-medium">Section <select name="section_type" id="section_type" required class="mt-1 w-full rounded-lg border-slate-300"><option value="SECTION-A">Section A</option><option value="SECTION-B">Section B</option><option value="SECTION-C">Section C</option><option value="SECTION-D">Section D</option></select></label>
     <label class="block text-sm font-medium">Marks <input type="number" name="marks" id="marks" min="1" max="20" value="1" required class="mt-1 w-full rounded-lg border-slate-300"></label>
     <label class="block text-sm font-medium">Question type <select name="question_type" id="question_type" required class="mt-1 w-full rounded-lg border-slate-300"><option value="MCQ">MCQ</option><option value="MATCH">Match the following</option><option value="ASSERTION_REASON">Assertion–Reason</option><option value="VSA">Very short answer</option><option value="SHORT_ANSWER">Short answer</option><option value="ESSAY">Essay / descriptive</option><option value="DIAGRAM">Diagram-based</option></select></label>
     <label class="block text-sm font-medium">K-level <select name="k_level" required class="mt-1 w-full rounded-lg border-slate-300"><?php for($i=1;$i<=6;$i++):?><option>K<?=$i?></option><?php endfor;?></select></label>
     <label class="block text-sm font-medium">CO-level <select name="co_level" required class="mt-1 w-full rounded-lg border-slate-300"><?php for($i=1;$i<=6;$i++):?><option>CO<?=$i?></option><?php endfor;?></select></label>
     <label class="block text-sm font-medium">Choice group (optional) <input name="choice_group" id="choice_group" placeholder="e.g. B21" class="mt-1 w-full rounded-lg border-slate-300"></label>
    </div>
    <div id="mcqFields" class="md:col-span-2 grid sm:grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3">
      <div class="grid grid-cols-2 gap-2"><label class="text-sm">Option A<input name="option_a" class="mt-1 w-full rounded-lg border-slate-300" placeholder="Option A"></label><label class="text-sm">Option B<input name="option_b" class="mt-1 w-full rounded-lg border-slate-300" placeholder="Option B"></label><label class="text-sm">Option C<input name="option_c" class="mt-1 w-full rounded-lg border-slate-300" placeholder="Option C"></label><label class="text-sm">Option D<input name="option_d" class="mt-1 w-full rounded-lg border-slate-300" placeholder="Option D"></label><input type="hidden" name="options_json" id="options_json"></div>
      <label class="text-sm">Correct answer / answer key<textarea name="answer_key" rows="3" class="mt-1 w-full rounded-lg border-slate-300" placeholder="Correct option or marking guide"></textarea></label>
    </div>
    <div id="matchFields" class="md:col-span-2 grid sm:grid-cols-2 gap-3 hidden rounded-xl bg-amber-50 p-3"><label class="text-sm">Match Column A<textarea name="match_column_a" rows="3" class="mt-1 w-full rounded-lg border-slate-300"></textarea></label><label class="text-sm">Match Column B<textarea name="match_column_b" rows="3" class="mt-1 w-full rounded-lg border-slate-300"></textarea></label></div>
    <label class="md:col-span-2 text-sm font-medium">Diagram / image (PNG, JPG, WEBP, GIF; max 8 MB)<input type="file" name="diagram" accept="image/png,image/jpeg,image/webp,image/gif" class="mt-1 block w-full rounded-lg border p-2"></label>
    <label class="md:col-span-2 flex items-center gap-2 text-sm"><input type="checkbox" name="compulsory" id="compulsory" value="1" checked class="rounded"> Compulsory question</label>
    <div class="md:col-span-2 flex flex-wrap gap-2"><button id="saveQuestion" class="rounded-xl bg-indigo-600 text-white px-5 py-2.5 font-semibold">Save question to draft</button><button type="button" id="resetQuestion" class="rounded-xl border px-5 py-2.5">Clear form</button><button type="button" id="submitHod" class="ml-auto rounded-xl bg-emerald-600 text-white px-5 py-2.5 font-semibold">Submit bank to HOD</button></div>
   </form>
  </section>
 </div>
 <section class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm"><div class="flex justify-between items-center gap-3"><h2 class="font-bold text-lg">3. Questions in current draft</h2><span id="questionCount" class="rounded-full bg-slate-100 px-3 py-1 text-sm">0 questions</span></div><div id="questionsList" class="mt-4 space-y-3"><p class="text-slate-500">Create or open a draft to begin.</p></div></section>
</main>
<script>
const api='../../api/draft_entry.php';const requestedDraftId=<?= (int)($_GET['draft_id']??0) ?>;let currentDraft=0, draftStatus='';
let autoSaveTimer=null; let validationTimer=null; let lastFormData=''; let isAutoSaving=false;
let autoSaveInitialized=false;

// Validation issue tracking
let validationIssues = {
    courseCodeMismatch: [],
    duplicateQuestions: [],
    kLevelMismatch: [],
    marksMismatch: []
};

async function request(action, form, options={}) {
    const fd = form instanceof FormData ? form : new FormData();
    if(!(form instanceof FormData)) {}
    fd.set('action', action);
    const r = await fetch(api, {method:'POST', body:fd, credentials:'same-origin', ...options});
    const j = await r.json();
    if(!r.ok || !j.ok) throw new Error(j.message || 'Request failed');
    return j.data || {};
}

function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

function toggleFields(){
    document.getElementById('matchFields').classList.toggle('hidden',!['MATCH','MATCHING'].includes(document.getElementById('question_type').value));
    document.getElementById('mcqFields').classList.toggle('hidden',!['MCQ','ASSERTION_REASON'].includes(document.getElementById('question_type').value));
}

function syncSectionFields(){
    const section = document.getElementById('section_type').value;
    document.getElementById('compulsory').checked = ['SECTION-A','SECTION-D'].includes(section);
    document.getElementById('choice_group').required = false;
}

function suggestSection(){
    const marks = Number(document.getElementById('marks').value);
    const section = document.getElementById('section_type');
    if(marks===1) section.value='SECTION-A';
    else if(marks===5) section.value='SECTION-B';
    else if(marks===10 && !['SECTION-C','SECTION-D'].includes(section.value)) section.value='SECTION-C';
    syncSectionFields();
    validateForm(); // Re-validate on section change
}

document.getElementById('question_type').addEventListener('change',toggleFields);
document.getElementById('marks').addEventListener('change',suggestSection);
document.getElementById('section_type').addEventListener('change',syncSectionFields);
document.getElementById('paper_code').addEventListener('change',e=>{const o=e.target.selectedOptions[0];document.getElementById('course_title').value=o?.dataset.title||'';});

// Auto-save functionality
function getFormDataString() {
    const form = document.getElementById('questionForm');
    const data = {};
    for(const [key, value] of new FormData(form).entries()) {
        if(key !== 'diagram') data[key] = value; // Skip file input
    }
    return JSON.stringify(data);
}

function getEmptyFormString() {
    // Dynamically generate empty form string based on current form defaults
    const form = document.getElementById('questionForm');
    const data = {};
    const defaults = {
        draft_id: '',
        question_id: '',
        question_text: '',
        unit_no: '1',
        sub_unit: '',
        section_type: 'SECTION-A',
        marks: '1',
        question_type: 'MCQ',
        k_level: 'K1',
        co_level: 'CO1',
        options_json: '',
        answer_key: '',
        match_column_a: '',
        match_column_b: '',
        choice_group: '',
        compulsory: '1'
    };
    for(const [key, value] of new FormData(form).entries()) {
        if(key !== 'diagram') data[key] = value;
    }
    // Merge with defaults for any missing fields
    for(const [key, defValue] of Object.entries(defaults)) {
        if(!(key in data)) data[key] = defValue;
    }
    return JSON.stringify(data);
}

function setAutoSaveStatus(status, message) {
    const dot = document.getElementById('autoSaveDot');
    const text = document.getElementById('autoSaveText');
    const lastSaved = document.getElementById('lastSaved');

    switch(status) {
        case 'saving':
            dot.className = 'w-2 h-2 rounded-full bg-amber-400 animate-pulse';
            text.textContent = ' Saving...';
            break;
        case 'saved':
            dot.className = 'w-2 h-2 rounded-full bg-emerald-500';
            text.textContent = ' Saved';
            lastSaved.textContent = `Last saved: ${new Date().toLocaleTimeString()}`;
            break;
        case 'error':
            dot.className = 'w-2 h-2 rounded-full bg-rose-500';
            text.textContent = ' Save failed';
            break;
        default:
            dot.className = 'w-2 h-2 rounded-full bg-slate-300';
            text.textContent = ' Ready';
    }
}

async function autoSave() {
    if(!currentDraft || isAutoSaving) return;

    const form = document.getElementById('questionForm');
    const formDataStr = getFormDataString();

    // Don't auto-save if form is empty or hasn't changed
    const emptyForm = getEmptyFormString();
    if(formDataStr === lastFormData || formDataStr === emptyForm) {
        return;
    }

    // Check if required fields are filled
    const questionText = document.getElementById('question_text').value.trim();
    const unitNo = document.getElementById('unit_no').value;
    const subUnit = document.getElementById('sub_unit').value.trim();

    if(!questionText || !unitNo || !subUnit) {
        return; // Don't auto-save incomplete questions
    }

    isAutoSaving = true;
    setAutoSaveStatus('saving');

    try {
        const f = new FormData(form);
        const options = ['option_a','option_b','option_c','option_d'].map(k=>String(f.get(k)||'').trim());
        f.set('options_json', options.some(Boolean) ? JSON.stringify(options) : '');
        f.set('draft_id', currentDraft);

        const action = f.get('question_id') ? 'update_question' : 'add_question';
        const result = await request(action, f);

        if(!f.get('question_id')) {
            // New question was added, update the form with the new question_id
            form.question_id.value = result.question_id;
        }

        lastFormData = getFormDataString();
        setAutoSaveStatus('saved');
        // Refresh only the question list without full re-render to avoid flickering
        await refreshQuestionListOnly(currentDraft);

    } catch(err) {
        console.error('Auto-save failed:', err);
        setAutoSaveStatus('error');
    } finally {
        isAutoSaving = false;
    }
}

function debounceAutoSave() {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(autoSave, 2000); // 2 second debounce
}

// Attach auto-save listeners to form fields (only once)
function setupAutoSave() {
    if(autoSaveInitialized) return;
    const form = document.getElementById('questionForm');
    const fields = form.querySelectorAll('input:not([type="file"]), textarea, select');
    fields.forEach(field => {
        field.addEventListener('input', debounceAutoSave);
        field.addEventListener('change', debounceAutoSave);
    });
    autoSaveInitialized = true;
}

// Refresh only the question list without re-rendering the entire draft
async function refreshQuestionListOnly(draftId) {
    try {
        const d = await request('list');
        const drafts = d.drafts || [];
        const target = drafts.find(x=>Number(x.id)===Number(draftId));
        if(target && target.questions) {
            document.getElementById('questionCount').textContent = `${target.questions.length} questions`;
            document.getElementById('questionsList').innerHTML = target.questions.length ? target.questions.map(q => `<article class="border rounded-xl p-4"><div class="flex flex-wrap justify-between gap-2"><div class="flex flex-wrap gap-2 text-xs"><span class="bg-indigo-50 text-indigo-700 rounded px-2 py-1">${esc(q.section_type)}</span><span class="bg-slate-100 rounded px-2 py-1">${esc(q.marks)} mark(s)</span><span class="bg-slate-100 rounded px-2 py-1">Unit ${esc(q.unit_no)}.${esc(q.sub_unit)}</span><span class="bg-slate-100 rounded px-2 py-1">${esc(q.question_type)}</span>${q.choice_group?`<span class="bg-amber-50 text-amber-800 rounded px-2 py-1">Pair ${esc(q.choice_group)}</span>`:''}</div><span class="text-xs text-slate-500">${esc(q.review_status||'PENDING')}</span></div><p class="mt-3 whitespace-pre-wrap">${esc(q.question_text)}</p>${q.options_json?`<p class="mt-2 text-sm text-slate-600">Options: ${esc(q.options_json)}</p>`:''}${q.match_column_a?`<p class="mt-2 text-sm text-slate-600">Column A: ${esc(q.match_column_a)}<br>Column B: ${esc(q.match_column_b||'')}</p>`:''}${q.image_path?`<img class="mt-2 max-h-48 rounded border" alt="Question diagram" src="../../api/draft_diagram.php?draft_id=${encodeURIComponent(draftId)}&question_id=${encodeURIComponent(q.id)}">`:''}${q.review_remarks?`<p class="mt-2 text-sm text-amber-800">Review: ${esc(q.review_remarks)}</p>`:''}<div class="mt-3 flex gap-2"><button class="editQ rounded-lg border px-3 py-1.5 text-sm" data-id="${q.id}">Edit</button><button class="deleteQ rounded-lg border border-rose-200 text-rose-700 px-3 py-1.5 text-sm" data-id="${q.id}">Delete</button></div></article>`).join('') : '<p class="text-slate-500">No questions saved yet.</p>';
            document.querySelectorAll('.editQ').forEach(b=>b.onclick=()=>editQuestion(target.questions.find(q=>Number(q.id)===Number(b.dataset.id))));
            document.querySelectorAll('.deleteQ').forEach(b=>b.onclick=()=>deleteQuestion(b.dataset.id));
        }
    } catch(e) {
        console.warn('Failed to refresh question list:', e);
    }
}

// Real-time validation
async function validateForm() {
    if(!currentDraft) return;

    const issues = {
        courseCodeMismatch: [],
        duplicateQuestions: [],
        kLevelMismatch: [],
        marksMismatch: []
    };

    const form = document.getElementById('questionForm');
    const paperCode = document.getElementById('paper_code').value;
    const section = document.getElementById('section_type').value;
    const marks = parseInt(document.getElementById('marks').value);
    const kLevel = document.getElementById('k_level').value;
    const questionText = document.getElementById('question_text').value.trim().toLowerCase();
    const questionId = form.question_id.value;

    // 1. Course code mismatch - check against college timetable (real-time)
    try {
        if(paperCode && $collegePdo) {
            // We can't directly check PHP variable in JS, so we'll rely on server-side validation
            // at draft creation. For real-time, we'd need an API endpoint.
            // This is a placeholder for future enhancement.
        }
    } catch(e) {
        console.warn('Course code check failed:', e);
    }

    // 2. Duplicate questions - check against existing questions in draft
    try {
        const draftData = await request('list');
        const currentDraftData = draftData.drafts?.find(d => Number(d.id) === Number(currentDraft));
        if(currentDraftData && currentDraftData.questions) {
            for(const q of currentDraftData.questions) {
                // Skip current question being edited
                if(questionId && Number(q.id) === Number(questionId)) continue;

                const existingText = (q.question_text || '').trim().toLowerCase();
                if(existingText && questionText && existingText === questionText) {
                    issues.duplicateQuestions.push({
                        id: q.id,
                        text: q.question_text.substring(0, 100) + '...'
                    });
                }
            }
        }
    } catch(e) {
        console.warn('Duplicate check failed:', e);
    }

    // 3. K-Level mismatch - validate against blueprint/section expectations
    // Section A: MCQ (8), Match (1), Assertion-Reason (1), VSA (10) - typically K1-K3
    // Section B: Short answer - typically K2-K4
    // Section C: Essay - typically K3-K6
    // Section D: Compulsory essay - typically K4-K6
    const expectedKLevels = {
        'SECTION-A': ['K1', 'K2', 'K3'], // MCQ, Match, Assertion-Reason, VSA typically K1-K3
        'SECTION-B': ['K2', 'K3', 'K4'], // Short answer typically K2-K4
        'SECTION-C': ['K3', 'K4', 'K5', 'K6'], // Essay typically K3-K6
        'SECTION-D': ['K4', 'K5', 'K6'] // Compulsory essay typically K4-K6
    };

    if(expectedKLevels[section] && !expectedKLevels[section].includes(kLevel)) {
        issues.kLevelMismatch.push({
            section: section,
            current: kLevel,
            expected: expectedKLevels[section].join(', ')
        });
    }

    // 4. Marks mismatch - validate marks against section
    const expectedMarks = {
        'SECTION-A': 1,
        'SECTION-B': 5,
        'SECTION-C': 10,
        'SECTION-D': 10
    };

    if(expectedMarks[section] && marks !== expectedMarks[section]) {
        issues.marksMismatch.push({
            section: section,
            current: marks,
            expected: expectedMarks[section]
        });
    }

    // Update validation issues display
    updateValidationDisplay(issues);
    validationIssues = issues;
}

function updateValidationDisplay(issues) {
    const summary = document.getElementById('validationSummary');
    const list = document.getElementById('validationIssues');

    const allIssues = [];

    if(issues.courseCodeMismatch.length > 0) {
        allIssues.push(...issues.courseCodeMismatch.map(i => `Course code mismatch: ${i}`));
    }
    if(issues.duplicateQuestions.length > 0) {
        allIssues.push(...issues.duplicateQuestions.map(i => `Duplicate question detected: "${i.text}" (ID: ${i.id})`));
    }
    if(issues.kLevelMismatch.length > 0) {
        allIssues.push(...issues.kLevelMismatch.map(i => `K-Level mismatch in ${i.section}: ${i.current} (expected: ${i.expected})`));
    }
    if(issues.marksMismatch.length > 0) {
        allIssues.push(...issues.marksMismatch.map(i => `Marks mismatch in ${i.section}: ${i.current} marks (expected: ${i.expected} marks)`));
    }

    if(allIssues.length > 0) {
        list.innerHTML = allIssues.map(i => `<li class="text-amber-800">${esc(i)}</li>`).join('');
        summary.classList.remove('hidden');
    } else {
        summary.classList.add('hidden');
    }
}

// Also validate on field changes (using separate timer to avoid conflict with auto-save)
['question_text', 'section_type', 'marks', 'k_level', 'unit_no', 'sub_unit'].forEach(id => {
    const el = document.getElementById(id);
    if(el) {
        el.addEventListener('change', validateForm);
        el.addEventListener('input', () => {
            clearTimeout(validationTimer);
            validationTimer = setTimeout(validateForm, 1000);
        });
    }
});

function renderDraft(d) {
    currentDraft = Number(d.id);
    draftStatus = d.status;
    document.getElementById('draft_id').value = currentDraft;
    document.getElementById('draftStatus').textContent = `Draft #${d.id} · ${d.status} · ${d.total_questions} questions`;
    document.getElementById('questionCount').textContent = `${d.questions.length} questions`;
    document.getElementById('questionsList').innerHTML = d.questions.length ? d.questions.map(q => `<article class="border rounded-xl p-4"><div class="flex flex-wrap justify-between gap-2"><div class="flex flex-wrap gap-2 text-xs"><span class="bg-indigo-50 text-indigo-700 rounded px-2 py-1">${esc(q.section_type)}</span><span class="bg-slate-100 rounded px-2 py-1">${esc(q.marks)} mark(s)</span><span class="bg-slate-100 rounded px-2 py-1">Unit ${esc(q.unit_no)}.${esc(q.sub_unit)}</span><span class="bg-slate-100 rounded px-2 py-1">${esc(q.question_type)}</span>${q.choice_group?`<span class="bg-amber-50 text-amber-800 rounded px-2 py-1">Pair ${esc(q.choice_group)}</span>`:''}</div><span class="text-xs text-slate-500">${esc(q.review_status||'PENDING')}</span></div><p class="mt-3 whitespace-pre-wrap">${esc(q.question_text)}</p>${q.options_json?`<p class="mt-2 text-sm text-slate-600">Options: ${esc(q.options_json)}</p>`:''}${q.match_column_a?`<p class="mt-2 text-sm text-slate-600">Column A: ${esc(q.match_column_a)}<br>Column B: ${esc(q.match_column_b||'')}</p>`:''}${q.image_path?`<img class="mt-2 max-h-48 rounded border" alt="Question diagram" src="../../api/draft_diagram.php?draft_id=${encodeURIComponent(d.id)}&question_id=${encodeURIComponent(q.id)}">`:''}${q.review_remarks?`<p class="mt-2 text-sm text-amber-800">Review: ${esc(q.review_remarks)}</p>`:''}<div class="mt-3 flex gap-2"><button class="editQ rounded-lg border px-3 py-1.5 text-sm" data-id="${q.id}">Edit</button><button class="deleteQ rounded-lg border border-rose-200 text-rose-700 px-3 py-1.5 text-sm" data-id="${q.id}">Delete</button></div></article>`).join('') : '<p class="text-slate-500">No questions saved yet.</p>';
    document.querySelectorAll('.editQ').forEach(b=>b.onclick=()=>editQuestion(d.questions.find(q=>Number(q.id)===Number(b.dataset.id))));
    document.querySelectorAll('.deleteQ').forEach(b=>b.onclick=()=>deleteQuestion(b.dataset.id));
    const editable = ['DRAFT','RETURNED_BY_HOD','RETURNED_BY_COE','CORRECTION_REQUIRED'].includes(d.status);
    document.getElementById('questionForm').querySelectorAll('input,textarea,select,button').forEach(x=>{if(!['draft_id','question_id'].includes(x.id))x.disabled=!editable;});
    document.getElementById('submitHod').disabled = !editable || !d.questions.length;

    // Run validation after loading draft
    validateForm();

    // Setup auto-save listeners (only once)
    setupAutoSave();
}

async function loadDrafts(selectId) {
    const d = await request('list');
    const drafts = d.drafts || [];
    let target = drafts.find(x=>Number(x.id)===Number(selectId)) || drafts[0];
    if(target) renderDraft(target);
    else {
        currentDraft = 0;
        document.getElementById('questionsList').innerHTML = '<p class="text-slate-500">Create a draft to begin.</p>';
    }
}

document.getElementById('createBank').addEventListener('click',async e=>{
    e.preventDefault();
    const f = new FormData(document.getElementById('bankForm'));
    try{
        const d = await request('create',f);
        await loadDrafts(d.draft_id);
        Swal.fire({icon:'success',title:'Draft ready',text:'Your question bank draft is ready.'});
    }catch(err){
        Swal.fire({icon:'error',title:'Could not create draft',text:err.message});
    }
});

document.getElementById('questionForm').addEventListener('submit',async e=>{
    e.preventDefault();
    if(!currentDraft){Swal.fire('Create a draft first','','info');return;}
    const f = new FormData(e.currentTarget);
    const options = ['option_a','option_b','option_c','option_d'].map(k=>String(f.get(k)||'').trim());
    f.set('options_json',options.some(Boolean)?JSON.stringify(options):'');
    const id = f.get('question_id');
    try{
        await request(id?'update_question':'add_question',f);
        e.currentTarget.reset();
        document.getElementById('draft_id').value = currentDraft;
        document.getElementById('question_id').value = '';
        lastFormData = '';
        toggleFields();
        syncSectionFields();
        await loadDrafts(currentDraft);
        Swal.fire({toast:true,position:'top-end',icon:'success',title:'Saved to draft',showConfirmButton:false,timer:1400});
    }catch(err){
        Swal.fire({icon:'error',title:'Save failed',text:err.message});
    }
});

function editQuestion(q){
    if(!q) return;
    const f = document.getElementById('questionForm');
    f.question_id.value = q.id;
    for(const k of ['question_text','unit_no','sub_unit','section_type','marks','question_type','k_level','co_level','answer_key','match_column_a','match_column_b','choice_group']) if(f.elements[k]) f.elements[k].value = q[k]??'';
    let opts = [];
    try{opts=JSON.parse(q.options_json||'[]')}catch(e){}
    ['option_a','option_b','option_c','option_d'].forEach((k,i)=>{f.elements[k].value=opts[i]||''});
    f.elements.compulsory.checked = String(q.compulsory)==='1';
    toggleFields();
    syncSectionFields();
    f.scrollIntoView({behavior:'smooth',block:'center'});
    document.getElementById('saveQuestion').textContent = 'Update question';

    // Update lastFormData to prevent immediate auto-save
    lastFormData = getFormDataString();

    // Validate after editing
    validateForm();
}

async function deleteQuestion(id){
    const c = await Swal.fire({icon:'warning',title:'Delete question?',text:'This removes the question from the current draft.',showCancelButton:true,confirmButtonText:'Delete'});
    if(!c.isConfirmed) return;
    const f = new FormData();
    f.set('draft_id', currentDraft);
    f.set('question_id', id);
    try{
        await request('delete_question',f);
        await loadDrafts(currentDraft);
    }catch(e){
        Swal.fire('Delete failed',e.message,'error');
    }
}

document.getElementById('resetQuestion').onclick=()=>{
    const f = document.getElementById('questionForm');
    f.reset();
    f.question_id.value = '';
    f.draft_id.value = currentDraft;
    document.getElementById('saveQuestion').textContent = 'Save question to draft';
    lastFormData = '';
    toggleFields();
    suggestSection();
    // Clear validation display
    document.getElementById('validationSummary').classList.add('hidden');
};

document.getElementById('submitHod').onclick=async()=>{
    if(!currentDraft) return;
    const c = await Swal.fire({icon:'question',title:'Submit to HOD?',text:'You will not be able to edit unless the bank is returned for correction.',showCancelButton:true,confirmButtonText:'Submit'});
    if(!c.isConfirmed) return;
    const f = new FormData();
    f.set('draft_id', currentDraft);
    try{
        await request('submit_hod',f);
        await loadDrafts(currentDraft);
        Swal.fire('Submitted','Your question bank has been submitted to HOD.','success');
    }catch(e){
        Swal.fire('Submission failed',e.message,'error');
    }
};

toggleFields();
loadDrafts(requestedDraftId).catch(e=>Swal.fire('Could not load drafts',e.message,'error'));
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
