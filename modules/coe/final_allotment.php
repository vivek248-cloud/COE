<?php
define('PAGE_TITLE', 'COE Final Question Allotment');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireCOE();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
$base = getBaseUrl();
?>
<div class="max-w-7xl mx-auto px-4 py-6 pb-24 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-indigo-600">
                <span class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">📋</span>
                Controller of Examinations
            </div>
            <h1 class="text-2xl md:text-3xl font-black text-slate-900 mt-2">Final Question Slot Allocation</h1>
            <p class="text-sm text-slate-500 mt-1">Master Blueprint Specification · Select a numbered slot and allot an eligible approved question.</p>
        </div>
        <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/modules/coe/dashboard.php"
           class="px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 font-bold text-sm">Back to COE Dashboard</a>
    </div>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
        <div class="grid md:grid-cols-[minmax(260px,1fr)_180px_auto] gap-3 items-end">
            <label class="text-xs font-bold text-slate-600">MASTER BLUEPRINT
                <select id="blueprint" class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm">
                    <option value="">Loading saved blueprints…</option>
                </select>
            </label>
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3">
                <div class="text-[11px] font-bold uppercase text-emerald-700">Assigned Slots</div>
                <div id="assignedCount" class="text-xl font-black text-emerald-900 mt-1">0 / 0</div>
            </div>
            <button id="saveBtn" type="button" disabled
                class="rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed text-white font-black px-5 py-3 text-sm shadow-sm">
                Save Final Allotment
            </button>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200/70">
                <span class="text-[11px] font-bold text-slate-500 uppercase">Paper Code</span>
                <div id="summaryPaper" class="text-sm font-black text-slate-900 mt-1">—</div>
            </div>
            <div class="p-3.5 bg-indigo-50 rounded-xl border border-indigo-100">
                <span class="text-[11px] font-bold text-indigo-700 uppercase">Configured Slots</span>
                <div id="summarySlots" class="text-sm font-black text-indigo-900 mt-1">0 Slots</div>
            </div>
            <div class="p-3.5 bg-blue-50 rounded-xl border border-blue-100">
                <span class="text-[11px] font-bold text-blue-700 uppercase">Total Marks</span>
                <div id="summaryMarks" class="text-sm font-black text-blue-900 mt-1">— Marks</div>
            </div>
            <div class="p-3.5 bg-emerald-50 rounded-xl border border-emerald-100">
                <span class="text-[11px] font-bold text-emerald-700 uppercase">Approved Question Pool</span>
                <div id="summaryQuestions" class="text-sm font-black text-emerald-900 mt-1">0 Questions</div>
            </div>
        </div>

        <div class="bg-indigo-50/70 border border-indigo-200 rounded-xl p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-indigo-950">
            <div class="flex items-center gap-2">
                <span class="text-indigo-600 text-base">🛡</span>
                <span><strong>COE Confidential Allocation:</strong> Teaching/HOD blueprint requirements are read-only here. COE selects and validates the actual approved questions.</span>
            </div>
            <span class="text-[10px] font-mono font-bold text-indigo-700 bg-white px-2.5 py-1 rounded-full border border-indigo-200 shrink-0">SAVED SPECIFICATION</span>
        </div>
        <div class="p-3.5 bg-indigo-50/80 border border-indigo-200 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-indigo-950">
            <div class="flex items-center gap-2.5">
                <span class="w-6 h-6 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-bold shrink-0">🎯</span>
                <span id="bpMeta">Loading saved Teaching/HOD Master Blueprint Specifications…</span>
            </div>
            <span id="poolCount" class="text-[11px] font-bold text-indigo-700 bg-white px-3 py-1 rounded-full border border-indigo-200 shrink-0">Approved pool pending</span>
        </div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-100">
            <div>
                <h2 class="text-base font-black text-slate-900">Final Question Slot Allocation (Master Blueprint Specification)</h2>
                <p class="text-xs text-slate-500 mt-1">Question-number circles are grouped by section. Select a circle to view its saved requirements and eligible approved questions.</p>
            </div>
            <div class="flex flex-wrap gap-3 text-[11px] text-slate-500">
                <span><i class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500 mr-1"></i>Assigned</span>
                <span><i class="inline-block w-2.5 h-2.5 rounded-full bg-orange-500 mr-1"></i>Selected</span>
                <span><i class="inline-block w-2.5 h-2.5 rounded-full bg-slate-300 mr-1"></i>Open</span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(210px,.65fr)_minmax(0,1.7fr)] gap-4 items-start">
            <aside class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-4">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-black text-slate-900">Question Slots</h3>
                    <span id="slotTotal" class="text-[10px] font-bold bg-slate-100 rounded-full px-2.5 py-1">0 slots</span>
                </div>
                <p class="text-xs text-slate-500 mt-1">Select a numbered circle.</p>
                <div id="slots" class="mt-4 space-y-5">
                    <div class="text-center text-slate-400 py-8 text-sm">Choose a saved blueprint.</div>
                </div>
            </aside>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden min-h-[620px]">
                <div class="p-4 border-b border-slate-100 flex flex-wrap justify-between gap-2 items-center">
                    <div>
                        <h3 class="font-black text-slate-900">Selected Question Slot</h3>
                        <p id="slotHint" class="text-xs text-slate-500 mt-1">Select a question circle on the left.</p>
                    </div>
                    <span id="slotStatus" class="text-[10px] font-black tracking-wide rounded-full px-3 py-2 bg-slate-100 text-slate-500">NO SLOT SELECTED</span>
                </div>
                <div id="slotDetails" class="p-4 border-b border-slate-100">
                    <div class="py-10 text-center text-slate-400 text-sm">The selected slot's Master Blueprint Specification will appear here.</div>
                </div>
                <div class="p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <div>
                            <h3 class="font-black text-slate-900">Eligible Approved Questions</h3>
                            <p class="text-xs text-slate-500 mt-1">Full question, options, matching columns and answer key are shown where stored.</p>
                        </div>
                        <span id="eligibleCount" class="text-xs text-slate-500"></span>
                    </div>
                    <input id="search" type="search" placeholder="Search question text, unit, subunit, K-level…"
                        class="w-full rounded-xl border-slate-300 bg-slate-50 p-3 text-sm mb-3" disabled>
                    <div id="pool" class="space-y-3 max-h-[72vh] overflow-y-auto pr-1">
                        <div class="text-sm text-slate-400 p-4">Select a blueprint first.</div>
                    </div>
                </div>
            </section>
        </div>
    </section>
    <div id="notice" role="status" aria-live="polite" class="hidden fixed bottom-5 right-5 z-50 max-w-md rounded-xl p-4 shadow-xl text-sm font-bold"></div>
</div>
<style>
.qp-section-title{font-size:10px;font-weight:900;letter-spacing:.08em;color:#64748b;text-transform:uppercase;margin-bottom:10px}
.qp-circle{width:40px;height:40px;border-radius:999px;border:2px solid #cbd5e1;background:#f8fafc;color:#334155;font-weight:900;font-size:12px;display:inline-flex;align-items:center;justify-content:center;transition:.15s}
.qp-circle:hover{transform:translateY(-1px);filter:brightness(.97)}
.qp-circle.section-a{background:#4f46e5;border-color:#818cf8;color:#fff}
.qp-circle.section-b{background:#fbbf24;border-color:#fcd34d;color:#1e293b}
.qp-circle.section-c{background:#059669;border-color:#34d399;color:#fff}
.qp-circle.section-d{background:#e11d48;border-color:#fb7185;color:#fff}
.qp-circle.is-active{box-shadow:0 0 0 4px #fed7aa;outline:2px solid #f97316;outline-offset:2px;transform:scale(1.06)}
.qp-circle.is-assigned:not(.is-active){background:#dcfce7;border-color:#22c55e;color:#166534}
.qp-detail{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.qp-detail>div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:11px}
.qp-detail small{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}
.qp-detail strong{display:block;color:#0f172a;font-size:13px;margin-top:3px;overflow-wrap:anywhere}
.qp-question-body{white-space:pre-line;overflow-wrap:anywhere}
@media(max-width:640px){.qp-circle{width:37px;height:37px}.qp-detail{grid-template-columns:1fr 1fr}}
</style>

<script>
'use strict';
const api = <?= json_encode($base . '/api/coe_final_allotment.php') ?>;
let slots = [], questions = [], assignments = {}, activeSlot = null, blueprint = null;
const $ = id => document.getElementById(id);

function notify(message, ok = true) {
    const node = $('notice');
    node.textContent = message;
    node.className = 'fixed bottom-5 right-5 z-50 max-w-md rounded-xl p-4 shadow-xl text-sm font-bold ' +
        (ok ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white');
    window.setTimeout(() => node.classList.add('hidden'), 5000);
}
async function req(url, options = {}) {
    const response = await fetch(url, {...options, cache:'no-store', credentials:'same-origin'});
    const text = await response.text();
    let json;
    try { json = JSON.parse(text); }
    catch (_) { throw new Error('The server returned a non-JSON response. Check PHP errors and the Network → Response tab.'); }
    if (!response.ok || !json.success) throw new Error(json.message || json.error || `Request failed (${response.status})`);
    return json;
}
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}
function sectionName(value) { return String(value || 'SECTION-A').replace(/SECTION[-_ ]?/i, 'SECTION '); }
function slotLabel(slot) {
    return `${sectionName(slot.section_type)} · Q${slot.slot_number} · ${slot.marks || '?'} marks · Unit ${slot.unit_no || '—'} · ${slot.question_type || 'Any'}`;
}
function selectedQuestion(slot) {
    return questions.find(question => String(question.id) === String(assignments[slot.slot_key]));
}
function renderSlots() {
    $('slotTotal').textContent = `${slots.length} slots`;
    $('assignedCount').textContent = `${Object.values(assignments).filter(Boolean).length} / ${slots.length}`;
    if (!slots.length) {
        $('slots').innerHTML = '<div class="text-center text-slate-400 py-8">This blueprint has no saved question slots.</div>';
        return;
    }
    const groups = {};
    slots.forEach(slot => (groups[slot.section_type || 'SECTION-A'] ||= []).push(slot));
    $('slots').innerHTML = Object.entries(groups).map(([section, items]) => `
        <div>
            <div class="qp-section-title">${esc(sectionName(section))} <span class="font-medium normal-case tracking-normal">· ${items.length} slots</span></div>
            <div class="flex flex-wrap gap-2">${items.map(slot => {
                const assigned = !!assignments[slot.slot_key], active = activeSlot === slot.slot_key;
                return `<button type="button" title="${esc(slotLabel(slot))}" aria-label="Question ${esc(slot.slot_number)}"
                    data-slot="${esc(slot.slot_key)}" aria-pressed="${active ? 'true':'false'}"
                    class="qp-circle ${String(section).toUpperCase().includes('SECTION-A')?'section-a':String(section).toUpperCase().includes('SECTION-B')?'section-b':String(section).toUpperCase().includes('SECTION-C')?'section-c':'section-d'} ${assigned?'is-assigned':''} ${active?'is-active':''}">${esc(slot.slot_number)}</button>`;
            }).join('')}</div>
        </div>`).join('');
    document.querySelectorAll('[data-slot]').forEach(button => {
        button.addEventListener('click', () => {
            activeSlot = button.dataset.slot;
            renderSlots(); renderDetails(); renderPool();
        });
    });
}
function parseJsonValue(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'object') return value;
    try { return JSON.parse(value); } catch (_) { return value; }
}
function formatAnswer(value) {
    const parsed = parseJsonValue(value);
    if (parsed === null) return '';
    if (typeof parsed === 'string') return parsed;
    if (Array.isArray(parsed)) return parsed.map(item => typeof item === 'object' ? JSON.stringify(item) : String(item)).join('\n');
    if (typeof parsed === 'object') return Object.entries(parsed).map(([key,val]) => `${key}: ${typeof val === 'object' ? JSON.stringify(val) : val}`).join('\n');
    return String(parsed);
}
function renderQuestionExtras(question) {
    let html = '';
    const type = String(question.question_type || '').toUpperCase();
    const data = parseJsonValue(question.question_json);
    let options = parseJsonValue(question.options_json);
    if (!options && data && typeof data === 'object') options = data.options || data.choices || data.mcq_options || null;

    if (options && (type.includes('MCQ') || Array.isArray(options) || typeof options === 'object')) {
        const entries = Array.isArray(options) ? options : Object.entries(options).map(([key,value]) => ({label:key,text:value}));
        html += `<div class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3">
            <div class="text-[10px] font-black uppercase tracking-wide text-indigo-700 mb-2">Options</div>
            <div class="grid sm:grid-cols-2 gap-2">${entries.map((option,index) => {
                const label = option && typeof option === 'object' ? (option.label ?? option.key ?? option.option ?? String.fromCharCode(65+index)) : String.fromCharCode(65+index);
                const value = option && typeof option === 'object' ? (option.text ?? option.value ?? option.content ?? option.option_text ?? '') : option;
                return `<div class="rounded-lg bg-white border border-indigo-100 px-3 py-2 text-sm"><b class="text-indigo-700 mr-2">${esc(label)}</b>${esc(typeof value === 'object' ? JSON.stringify(value) : value)}</div>`;
            }).join('')}</div></div>`;
    }

    const matching = data && typeof data === 'object' ? (data.matching || {}) : {};
    const colA = parseJsonValue(question.match_column_a ?? data?.match_column_a ?? data?.column_a ?? matching.column_a);
    const colB = parseJsonValue(question.match_column_b ?? data?.match_column_b ?? data?.column_b ?? matching.column_b);
    if (colA !== null || colB !== null) {
        const toItems = value => Array.isArray(value) ? value : (value && typeof value === 'object' ? Object.values(value) : String(value ?? '').split(/\r?\n/).filter(Boolean));
        const a = toItems(colA), b = toItems(colB);
        html += `<div class="mt-3 rounded-xl border border-violet-100 bg-violet-50/60 p-3">
            <div class="text-[10px] font-black uppercase tracking-wide text-violet-700 mb-2">Match the following</div>
            <div class="grid grid-cols-2 gap-3"><div><b class="block text-xs text-slate-500 mb-1">Column A</b>${a.map((v,i)=>`<div class="text-sm py-1 border-b border-violet-100">${i+1}. ${esc(typeof v==='object'?JSON.stringify(v):v)}</div>`).join('')}</div>
            <div><b class="block text-xs text-slate-500 mb-1">Column B</b>${b.map((v,i)=>`<div class="text-sm py-1 border-b border-violet-100">${String.fromCharCode(65+i)}. ${esc(typeof v==='object'?JSON.stringify(v):v)}</div>`).join('')}</div></div></div>`;
    }

    const assertion = question.assertion_text || data?.assertion_text || data?.assertion || '';
    const reason = question.reason_text || data?.reason_text || data?.reason || '';
    if (assertion || reason || type.includes('ASSERT') || type === 'AR') {
        html += `<div class="mt-3 rounded-xl border border-amber-100 bg-amber-50/70 p-3">
            <div class="text-[10px] font-black uppercase tracking-wide text-amber-700 mb-2">Assertion & Reason</div>
            ${assertion ? `<p class="text-sm mb-2 whitespace-pre-line"><b>Assertion:</b> ${esc(assertion)}</p>` : ''}
            ${reason ? `<p class="text-sm whitespace-pre-line"><b>Reason:</b> ${esc(reason)}</p>` : ''}
        </div>`;
    }

    const answer = formatAnswer(question.answer_key ?? data?.answer_key ?? data?.correct_answer ?? data?.answer ?? data?.correct_matches ?? data?.matches_answer);
    if (answer) html += `<div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3">
        <div class="text-[10px] font-black uppercase tracking-wide text-emerald-700 mb-1">${colA !== null || colB !== null ? 'Correct matches / Answer key' : 'Answer key'}</div>
        <div class="text-sm font-semibold text-emerald-900 whitespace-pre-line">${esc(answer)}</div></div>`;
    if (question.image_url) html += `<div class="mt-3"><a class="text-sm font-bold text-indigo-700 underline" href="${esc(question.image_url)}" target="_blank" rel="noopener">View question diagram</a></div>`;
    return html;
}
function renderDetails() {
    const slot = slots.find(item => item.slot_key === activeSlot);
    if (!slot) {
        $('slotHint').textContent = 'Select a question circle on the left.';
        $('slotStatus').textContent = 'NO SLOT SELECTED';
        $('slotDetails').innerHTML = '<div class="py-10 text-center text-slate-400">The selected slot specification will appear here.</div>';
        return;
    }
    const question = selectedQuestion(slot);
    $('slotHint').textContent = `${sectionName(slot.section_type)} · Question ${slot.slot_number}`;
    $('slotStatus').textContent = question ? 'QUESTION ASSIGNED' : 'WAITING FOR ALLOCATION';
    $('slotStatus').className = 'text-xs font-bold rounded-full px-3 py-2 ' + (question ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700');
    const fields = [['Section',sectionName(slot.section_type)],['Question number','Q'+slot.slot_number],['Unit',slot.unit_no||'Any'],['Subunit',slot.sub_unit||'Any'],['Marks',slot.marks||'Any'],['Question type',slot.question_type||'Any'],['K-Level',slot.k_level||'Any'],['CO-Level',slot.co_level||'Any']];
    $('slotDetails').innerHTML = `<div class="flex flex-wrap justify-between items-center gap-2 mb-3">
        <div class="font-black text-slate-900">${esc(slotLabel(slot))}</div>
        ${question ? '<button id="clearAssignment" type="button" class="text-xs font-bold text-rose-600 border border-rose-200 rounded-lg px-3 py-2">Remove assignment</button>' : ''}
        </div><div class="qp-detail">${fields.map(([key,value])=>`<div><small>${esc(key)}</small><strong>${esc(value)}</strong></div>`).join('')}</div>
        ${question ? `<article class="mt-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200">
            <div class="text-[10px] font-black text-emerald-700 uppercase">Currently assigned · Bank Q${esc(question.fixed_q_no||question.q_number)}</div>
            <p class="qp-question-body text-sm text-slate-800 mt-2">${esc(question.question_text)}</p>${renderQuestionExtras(question)}
        </article>` : ''}`;
    const clear = $('clearAssignment');
    if (clear) clear.addEventListener('click', () => { delete assignments[slot.slot_key]; renderSlots(); renderDetails(); renderPool(); });
}
function compatible(question, slot) {
    return (!slot.unit_no || Number(question.unit_no) === Number(slot.unit_no)) &&
        (!slot.sub_unit || String(question.sub_unit||'').trim() === String(slot.sub_unit).trim()) &&
        (!slot.question_type || String(question.question_type).toUpperCase() === String(slot.question_type).toUpperCase()) &&
        (!slot.marks || Number(question.marks) === Number(slot.marks)) &&
        (!slot.k_level || String(question.k_level).toUpperCase() === String(slot.k_level).toUpperCase()) &&
        (!slot.co_level || String(question.co_level).toUpperCase() === String(slot.co_level).toUpperCase()) &&
        !Number(question.previously_used || 0);
}
function renderPool() {
    const slot = slots.find(item => item.slot_key === activeSlot);
    const term = $('search').value.toLowerCase().trim();
    if (!slot) {
        $('pool').innerHTML = '<div class="p-5 text-sm text-slate-400">Select a question number on the left to see eligible approved questions.</div>';
        $('eligibleCount').textContent = '';
        return;
    }
    const current = String(assignments[slot.slot_key] || '');
    const list = questions.filter(question => compatible(question,slot) &&
        (!term || String(question.question_text||'').toLowerCase().includes(term) ||
        String(question.unit_no).includes(term) || String(question.sub_unit||'').toLowerCase().includes(term) ||
        String(question.k_level||'').toLowerCase().includes(term)))
        .filter(question => !Object.entries(assignments).some(([key,value]) => String(value) === String(question.id) && key !== slot.slot_key));
    $('eligibleCount').textContent = `${list.length} eligible questions`;
    $('pool').innerHTML = list.length ? list.map(question => `<article class="border ${String(question.id)===current?'border-emerald-400 bg-emerald-50':'border-slate-200'} rounded-xl p-4">
        <div class="flex flex-wrap justify-between gap-2"><span class="text-[11px] font-black text-slate-500">BANK Q${esc(question.fixed_q_no||question.q_number)} · Unit ${esc(question.unit_no)}${question.sub_unit?'.'+esc(question.sub_unit):''} · ${esc(question.marks)} mark(s)</span><span class="text-[10px] font-bold text-indigo-600">${esc(question.k_level||'')}</span></div>
        <p class="qp-question-body text-sm text-slate-800 mt-3 leading-relaxed">${esc(question.question_text)}</p>${renderQuestionExtras(question)}
        <div class="flex flex-wrap justify-between items-center gap-2 mt-3"><span class="text-[10px] text-slate-500">${esc(question.question_type||'')} ${question.co_level?'· '+esc(question.co_level):''}</span>
        <button type="button" data-pick="${esc(question.id)}" class="text-xs font-black px-4 py-2 rounded-lg ${String(question.id)===current?'bg-emerald-600':'bg-orange-600'} text-white">${String(question.id)===current?'Assigned':'Assign to Q'+esc(slot.slot_number)}</button></div></article>`).join('') :
        '<div class="p-5 text-sm text-slate-400 border border-dashed rounded-xl">No unused eligible approved questions match this slot.</div>';
    document.querySelectorAll('[data-pick]').forEach(button => button.addEventListener('click', () => {
        const questionId = button.dataset.pick;
        for (const [key,value] of Object.entries(assignments)) if (String(value) === String(questionId)) delete assignments[key];
        assignments[activeSlot] = questionId;
        renderSlots(); renderDetails(); renderPool();
    }));
}
async function loadBlueprints() {
    const select = $('blueprint');
    select.innerHTML = '<option value="">Loading saved blueprints…</option>';
    $('saveBtn').disabled = true;
    try {
        const result = await req(api + '?action=blueprints');
        const rows = Array.isArray(result.blueprints) ? result.blueprints : [];
        select.innerHTML = '<option value="">Select a saved master blueprint</option>' + rows.map(row =>
            `<option value="${esc(row.id)}">${esc(row.paper_code)} · ${esc(row.course_title||'')} · Sem ${esc(row.semester)} · ${esc(row.academic_year)} · ${esc(row.status||'')}</option>`
        ).join('');
        $('bpMeta').textContent = rows.length ? `${rows.length} saved blueprint(s) found. Select one to display its slot circles.` :
            (result.message || 'No saved blueprint with a readable slot configuration was found.');
        if (!rows.length) select.innerHTML = '<option value="">No saved blueprint with slots found</option>';
    } catch (error) {
        select.innerHTML = '<option value="">Could not load blueprints</option>';
        $('bpMeta').textContent = error.message;
        notify(error.message, false);
    }
}
$('blueprint').addEventListener('change', async () => {
    const id = $('blueprint').value;
    slots = []; questions = []; assignments = {}; activeSlot = null; blueprint = null;
    $('saveBtn').disabled = true;
    if (!id) {
        $('summaryPaper').textContent = '—'; $('summarySlots').textContent = '0 Slots';
        $('summaryMarks').textContent = '— Marks'; $('summaryQuestions').textContent = '0 Questions';
        $('poolCount').textContent = 'Approved pool pending'; $('eligibleCount').textContent = '';
        renderSlots(); renderDetails(); renderPool(); return;
    }
    $('bpMeta').textContent = 'Loading blueprint slots and approved questions…';
    try {
        const result = await req(api + '?action=load&blueprint_id=' + encodeURIComponent(id));
        blueprint = result.blueprint;
        slots = Array.isArray(result.slots) ? result.slots : [];
        questions = Array.isArray(result.questions) ? result.questions : [];
        Object.entries(result.assignments || {}).forEach(([key,value]) => { assignments[key] = value.question_id; });
        activeSlot = slots[0]?.slot_key || null;
        $('bpMeta').textContent = `${blueprint.paper_code} · ${blueprint.course_title||''} · Semester ${blueprint.semester} · ${blueprint.academic_year} · ${blueprint.exam_type||''}`;
        $('summaryPaper').textContent = blueprint.paper_code || '—';
        $('summarySlots').textContent = `${slots.length} Slots`;
        $('summaryMarks').textContent = `${blueprint.total_marks ?? '—'} Marks`;
        $('summaryQuestions').textContent = `${questions.length} Questions`;
        $('poolCount').textContent = `${questions.length} approved questions loaded`;
        $('search').disabled = false;
        $('search').value = '';
        $('saveBtn').disabled = false;
        renderSlots(); renderDetails(); renderPool();
    } catch (error) {
        $('bpMeta').textContent = error.message;
        renderSlots(); renderDetails(); renderPool();
        notify(error.message, false);
    }
});
$('search').addEventListener('input', renderPool);
$('saveBtn').addEventListener('click', async () => {
    if (!blueprint) return notify('Select a blueprint first.', false);
    $('saveBtn').disabled = true;
    try {
        const result = await req(api + '?action=save&blueprint_id=' + encodeURIComponent(blueprint.id), {
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({assignments})
        });
        notify(`${result.message} Assigned: ${result.assigned_count}`);
    } catch (error) {
        notify(error.message, false);
    } finally {
        $('saveBtn').disabled = false;
    }
});
loadBlueprints();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
