/**
 * COE Paper Shuffler & Question Swap Controller
 * Holy Cross College (Autonomous) - Examination System
 * Conforms to Official Holy Cross College Format (U23BC3ALT05.pdf & Tss.pdf)
 */

let shufflerState = {
  generatedPapers: [],
  activeSwapInfo: null
};

function escapeHtmlQPS(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

async function runCOEShuffle() {
  const bankId = document.getElementById('shuffler-bank-select').value;
  const numSets = parseInt(document.getElementById('shuffler-sets-count').value) || 3;
  const examDate = document.getElementById('shuffler-exam-date').value;
  const blueprintId = parseInt(document.getElementById('shuffler-blueprint-select').value) || 0;

  if (!bankId) { Swal.fire('Error', 'Please select an approved Question Bank first.', 'error'); return; }

  const baseUrl = window.QPS_BASE_URL || '';

  Swal.fire({
    title: 'Generating Randomized OBE Paper Sets...',
    text: 'Applying Holy Cross College multi-tier constraint shuffling and outcome balance...',
    allowOutsideClick: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });

  try {
    const res = await fetch(baseUrl + '/api/shuffle_generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        bank_id: parseInt(bankId),
        blueprint_id: blueprintId,
        num_sets: numSets,
        set_count: numSets,
        exam_date: examDate
      })
    });
    const data = await res.json();

    if (data.success) {
      shufflerState.generatedPapers = data.papers || data.generated_papers || data.sets || [];
      renderShuffledSets(shufflerState.generatedPapers);
      Swal.fire({
        icon: 'success',
        title: 'Papers Generated Successfully',
        text: data.message || `Generated ${shufflerState.generatedPapers.length} question paper sets.`
      });
    } else {
      Swal.fire('Shuffle Error', data.message || 'Failed to generate question papers.', 'error');
    }
  } catch (err) {
    console.error(err);
    Swal.fire('Shuffle Error', err.message || 'Shuffle request failed. Please check server logs.', 'error');
  }
}

function renderShuffledSets(papers) {
  const container = document.getElementById('shuffled-results-container');
  if (!container) return;
  container.innerHTML = '';

  if (!papers || papers.length === 0) return;

  const baseUrl = window.QPS_BASE_URL || '';
  const card = document.createElement('div');
  card.className = 'bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6';

  let tabsHtml = '<div class="flex space-x-2 border-b border-slate-200 pb-3 overflow-x-auto">';
  papers.forEach((p, idx) => {
    tabsHtml += `
      <button type="button" onclick="switchShuffledSetTab(${idx})" id="btn-set-tab-${idx}" class="px-4 py-2 text-xs font-bold rounded-xl border ${idx === 0 ? 'bg-indigo-600 text-white border-indigo-600 shadow' : 'bg-slate-50 text-slate-700 border-slate-300 hover:bg-slate-100'} transition flex items-center space-x-1.5 whitespace-nowrap">
        <i data-lucide="file-check-2" class="w-3.5 h-3.5"></i>
        <span>${escapeHtmlQPS(p.set_name || 'SET ' + (idx + 1))} (${escapeHtmlQPS(p.paper_code)})</span>
      </button>
    `;
  });
  tabsHtml += '</div>';

  let viewsHtml = '<div id="shuffled-set-views">';
  papers.forEach((p, idx) => {
    viewsHtml += `
      <div id="set-view-${idx}" class="set-view-panel ${idx === 0 ? '' : 'hidden'} space-y-4 pt-2">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200">
          <div>
            <div class="flex items-center space-x-2">
              <span class="bg-indigo-100 text-indigo-800 text-xs font-mono font-bold px-2 py-0.5 rounded">${escapeHtmlQPS(p.paper_code)}</span>
              <h3 class="font-extrabold text-slate-900 text-base">${escapeHtmlQPS(p.course_title)} — <span class="text-indigo-700 font-bold">${escapeHtmlQPS(p.set_name || 'SET A')}</span></h3>
            </div>
            <p class="text-xs text-slate-500 mt-1">${escapeHtmlQPS(p.school_name || p.dept_name)} • ${escapeHtmlQPS(p.degree_exam_line || p.semester)} • Max Marks: <strong class="text-amber-800 font-bold">${p.total_marks || p.max_marks || 75}M</strong> • Time: ${escapeHtmlQPS(p.duration_hours || '3 Hours')}</p>
          </div>
          <div class="flex items-center space-x-2">
            <a href="${baseUrl}/api/export_docx.php?paper_id=${p.id}" class="bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold px-4 py-2.5 rounded-xl flex items-center space-x-1.5 shadow transition">
              <i data-lucide="file-down" class="w-4 h-4"></i>
              <span>Download Word (.docx)</span>
            </a>
            <a href="${baseUrl}/modules/coe/view_paper.php?paper_id=${p.id}" class="bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold px-4 py-2.5 rounded-xl flex items-center space-x-1.5 shadow transition">
              <i data-lucide="printer" class="w-4 h-4"></i>
              <span>View & Print</span>
            </a>
          </div>
        </div>

        ${renderSectionsTable(p, idx)}
      </div>
    `;
  });
  viewsHtml += '</div>';

  card.innerHTML = tabsHtml + viewsHtml;
  container.appendChild(card);

  if (window.lucide) lucide.createIcons();
  if (window.MathJax && window.MathJax.typesetPromise) {
    window.MathJax.typesetPromise().catch(e => console.warn(e));
  }
}

function switchShuffledSetTab(idx) {
  document.querySelectorAll('.set-view-panel').forEach(el => el.classList.add('hidden'));
  document.querySelectorAll('[id^="btn-set-tab-"]').forEach(el => {
    el.className = 'px-4 py-2 text-xs font-bold rounded-xl border bg-slate-50 text-slate-700 border-slate-300 hover:bg-slate-100 transition flex items-center space-x-1.5 whitespace-nowrap';
  });

  const targetView = document.getElementById(`set-view-${idx}`);
  const targetBtn = document.getElementById(`btn-set-tab-${idx}`);
  if (targetView) targetView.classList.remove('hidden');
  if (targetBtn) targetBtn.className = 'px-4 py-2 text-xs font-bold rounded-xl border bg-indigo-600 text-white border-indigo-600 shadow transition flex items-center space-x-1.5 whitespace-nowrap';

  if (window.lucide) lucide.createIcons();
  if (window.MathJax && window.MathJax.typesetPromise) {
    window.MathJax.typesetPromise().catch(e => console.warn(e));
  }
}

function renderSectionsTable(paper, setIdx) {
  let html = '<div class="space-y-4">';

  (paper.sections || []).forEach((sec, secIdx) => {
    html += `
      <div class="border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
        <div class="bg-gradient-to-r from-slate-900 to-indigo-950 text-white p-3.5 flex justify-between items-center text-xs font-bold">
          <div>
            <span class="font-extrabold text-sm text-white">${escapeHtmlQPS(sec.section_name)}</span>
            <span class="text-indigo-200 font-normal ml-2 italic">— ${escapeHtmlQPS(sec.instruction)}</span>
          </div>
          <span class="bg-white/10 px-2.5 py-0.5 rounded-lg text-amber-300 font-mono font-bold">${escapeHtmlQPS(sec.choice_formula || (sec.total_marks + ' Marks'))}</span>
        </div>
        <table class="w-full text-left text-xs border-collapse">
          <thead class="bg-slate-100 text-slate-800 font-extrabold border-b border-slate-200 text-[11px]">
            <tr>
              <th class="py-2.5 px-3 w-16 text-center">Q.No</th>
              <th class="py-2.5 px-3 w-28 text-center">Unit & Subunit</th>
              <th class="py-2.5 px-4">Question Description & Options</th>
              <th class="py-2.5 px-3 w-16 text-center">Marks</th>
              <th class="py-2.5 px-3 w-20 text-center">Bloom Level</th>
              <th class="py-2.5 px-3 w-16 text-center">COs</th>
              <th class="py-2.5 px-3 w-20 text-center">COE Swap</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
    `;

    (sec.questions || []).forEach((q, qIdx) => {
      if (q.is_or_divider) {
        html += `
          <tr class="bg-stone-100/80 font-black">
            <td colspan="7" class="py-1.5 text-center font-black text-slate-800 text-xs tracking-widest uppercase">
              — ( OR ) —
            </td>
          </tr>
        `;
        return;
      }

      let imgThumb = '';
      if (q.image_url && q.image_url.length > 0) {
        imgThumb = `<div class="mt-2"><img src="${escapeHtmlQPS(q.image_url)}" class="max-h-28 rounded border border-slate-300 shadow-sm" /></div>`;
      }

      let ansKeyHtml = '';
      if (q.answer_key && q.answer_key.length > 0) {
        ansKeyHtml = `<div class="mt-1.5 text-[11px] font-bold text-emerald-800 bg-emerald-50 px-2.5 py-0.5 rounded border border-emerald-200 inline-block">Key: ${escapeHtmlQPS(q.answer_key)}</div>`;
      }

      const qno = q.display_qno || q.q_number || '';
      const coRaw = (q.co_level || 'CO1').replace(' ', '').toUpperCase();
      const coDisp = 'CO ' + coRaw.replace('CO', '');

      html += `
        <tr class="hover:bg-slate-50 transition align-top">
          <td class="py-3 px-3 text-center font-bold font-mono text-slate-900">${escapeHtmlQPS(qno)}</td>
          <td class="py-3 px-3 text-center font-mono font-bold text-indigo-900">
            <span class="bg-indigo-50 px-2 py-0.5 rounded text-[11px]">Unit ${q.unit_no || 1}${q.sub_unit ? ' (' + escapeHtmlQPS(q.sub_unit) + ')' : ''}</span>
          </td>
          <td class="py-3 px-4">
            <div class="font-normal text-slate-900 leading-relaxed whitespace-pre-line">${escapeHtmlQPS(q.question_text)}</div>
            ${imgThumb}
            ${ansKeyHtml}
          </td>
          <td class="py-3 px-3 text-center font-bold text-amber-900 font-mono">${q.marks || 1}M</td>
          <td class="py-3 px-3 text-center"><span class="bg-blue-50 text-blue-900 font-bold px-2 py-0.5 rounded text-[11px] font-mono">${escapeHtmlQPS(q.k_level || 'K1')}</span></td>
          <td class="py-3 px-3 text-center"><span class="bg-emerald-50 text-emerald-900 font-bold px-2 py-0.5 rounded text-[11px] font-mono">${coDisp}</span></td>
          <td class="py-3 px-3 text-center">
            <button type="button" onclick="openSwapModal(${paper.id}, ${setIdx}, ${secIdx}, ${qIdx}, ${q.unit_no || 1}, ${q.marks || 1}, ${paper.bank_id || paper.id})" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-2.5 py-1 rounded-lg text-[11px] flex items-center justify-center space-x-1 mx-auto transition shadow-xs">
              <i data-lucide="repeat" class="w-3 h-3"></i>
              <span>Swap</span>
            </button>
          </td>
        </tr>
      `;
    });

    html += `
          </tbody>
        </table>
      </div>
    `;
  });

  html += '</div>';
  return html;
}

async function openSwapModal(paperId, setIdx, secIdx, qIdx, unitNo, marks, bankId) {
  shufflerState.activeSwapInfo = { paperId, setIdx, secIdx, qIdx, unitNo, marks, bankId };
  const modal = document.getElementById('swap-modal');
  const listEl = document.getElementById('swap-candidates-list');
  const subEl = document.getElementById('swap-modal-subtitle');
  const baseUrl = window.QPS_BASE_URL || '';
  
  subEl.textContent = `Showing alternative questions for Unit ${unitNo} (${marks} Marks)`;
  listEl.innerHTML = '<div class="p-4 text-center text-xs text-slate-400">Loading candidates from bank...</div>';
  modal.classList.remove('hidden');

  try {
    const res = await fetch(`${baseUrl}/api/get_candidates.php?paper_id=${paperId}&section_index=${secIdx}&bank_id=${bankId}&unit_no=${unitNo}&marks=${marks}`);
    const data = await res.json();
    const candidates = data.candidates || [];

    listEl.innerHTML = '';
    if (candidates.length === 0) {
      listEl.innerHTML = '<div class="p-4 text-center text-xs text-slate-500">No alternative unused questions available in bank for this unit.</div>';
      return;
    }

    candidates.forEach(cand => {
      const item = document.createElement('div');
      item.className = 'p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-xl cursor-pointer transition flex items-center justify-between gap-3 text-xs';
      item.onclick = () => executeSwap(cand.id);

      item.innerHTML = `
        <div class="flex-1">
          <div class="flex items-center space-x-2 mb-1">
            <span class="bg-indigo-100 text-indigo-800 font-bold px-1.5 py-0.5 rounded text-[10px]">Q#${cand.q_number}</span>
            <span class="bg-slate-200 text-slate-700 font-bold px-1.5 py-0.5 rounded text-[10px]">Unit ${cand.unit_no}</span>
            <span class="bg-blue-100 text-blue-800 font-bold px-1.5 py-0.5 rounded text-[10px]">${cand.k_level}</span>
            <span class="bg-emerald-100 text-emerald-800 font-bold px-1.5 py-0.5 rounded text-[10px]">${cand.co_level}</span>
          </div>
          <div class="text-slate-800 font-medium">${escapeHtmlQPS(cand.question_text).replace(/\n/g,'<br>')}</div>
        </div>
        <button type="button" class="bg-indigo-600 text-white font-bold px-3 py-1.5 rounded-lg text-xs shadow">Select</button>
      `;
      listEl.appendChild(item);
    });

    if (window.MathJax && window.MathJax.typesetPromise) {
      window.MathJax.typesetPromise().catch(e => console.warn(e));
    }
  } catch (err) {
    console.error(err);
    listEl.innerHTML = '<div class="p-4 text-center text-xs text-red-500">Failed to load candidates.</div>';
  }
}

function closeSwapModal() {
  document.getElementById('swap-modal').classList.add('hidden');
}

async function executeSwap(newQuestionId) {
  const { paperId, setIdx, secIdx, qIdx } = shufflerState.activeSwapInfo;
  const baseUrl = window.QPS_BASE_URL || '';

  try {
    const res = await fetch(baseUrl + '/api/swap_question.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        paper_id: paperId,
        section_index: secIdx,
        question_index: qIdx,
        new_question_id: newQuestionId
      })
    });
    const data = await res.json();

    if (data.success) {
      shufflerState.generatedPapers[setIdx] = data.paper_data;
      shufflerState.generatedPapers[setIdx].id = paperId;
      renderShuffledSets(shufflerState.generatedPapers);
      switchShuffledSetTab(setIdx);
      closeSwapModal();
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: data.message || 'Question swapped successfully!',
        timer: 1500,
        showConfirmButton: false
      });
    } else {
      Swal.fire('Swap Error', data.message || 'Could not swap question.', 'error');
    }
  } catch (err) {
    Swal.fire('Swap Error', err.message || 'Network error during swap.', 'error');
  }
}
