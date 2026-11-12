/**
 * Holy Cross College Searchable Dropdown with Live Typing Finder
 * Transforms standard <select> elements into searchable UI dropdowns.
 */

(function () {
  'use strict';

  function initSelect(select) {
    if (select.dataset.noSearch === 'true') return;

    let wrapper = select.nextElementSibling;
    if (wrapper && wrapper.classList && wrapper.classList.contains('qps-searchable-wrapper')) {
      // Wrapper already exists - refresh its trigger and options
      if (typeof select._qpsUpdate === 'function') {
        select._qpsUpdate();
      }
      return;
    }

    select.dataset.searchableInit = 'true';
    select.style.display = 'none';

    // Wrapper container
    wrapper = document.createElement('div');
    wrapper.className = 'qps-searchable-wrapper relative inline-block w-full text-xs text-slate-800';

    // Button / Selected Display Trigger
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'qps-searchable-trigger w-full flex items-center justify-between bg-white border border-slate-300 rounded-xl px-3 py-2.5 text-left font-medium hover:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 shadow-sm transition';
    
    const labelSpan = document.createElement('span');
    labelSpan.className = 'truncate mr-2 font-medium text-slate-700';

    const chevron = document.createElement('span');
    chevron.className = 'text-slate-400 shrink-0';
    chevron.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>`;

    trigger.appendChild(labelSpan);
    trigger.appendChild(chevron);
    wrapper.appendChild(trigger);

    // Dropdown Menu Panel
    const panel = document.createElement('div');
    panel.className = 'qps-searchable-panel hidden absolute z-50 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden';
    panel.style.minWidth = '220px';

    // Search Box Header
    const searchHeader = document.createElement('div');
    searchHeader.className = 'p-2 border-b border-slate-100 bg-slate-50 flex items-center space-x-2';

    const searchIcon = document.createElement('span');
    searchIcon.className = 'text-slate-400 pl-1';
    searchIcon.innerHTML = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>`;

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Type to search...';
    searchInput.className = 'w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-1 focus:ring-indigo-500';

    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'hidden text-slate-400 hover:text-slate-600 px-1';
    clearBtn.innerHTML = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>`;

    searchHeader.appendChild(searchIcon);
    searchHeader.appendChild(searchInput);
    searchHeader.appendChild(clearBtn);
    panel.appendChild(searchHeader);

    // Options List Container
    const list = document.createElement('div');
    list.className = 'qps-searchable-list max-h-60 overflow-y-auto divide-y divide-slate-50 text-xs py-1';
    panel.appendChild(list);

    // Empty state container
    const emptyState = document.createElement('div');
    emptyState.className = 'hidden p-3 text-center text-xs text-slate-400 font-medium';
    emptyState.textContent = 'No matching options found';
    panel.appendChild(emptyState);

    wrapper.appendChild(panel);
    select.parentNode.insertBefore(wrapper, select.nextSibling);

    function updateTriggerLabel() {
      const selected = select.options[select.selectedIndex];
      if (selected) {
        labelSpan.textContent = selected.textContent || selected.value || 'Select option';
        if (!selected.value) {
          labelSpan.className = 'truncate mr-2 text-slate-400 font-normal';
        } else {
          labelSpan.className = 'truncate mr-2 text-slate-800 font-semibold';
        }
      } else {
        labelSpan.textContent = 'Select option';
        labelSpan.className = 'truncate mr-2 text-slate-400 font-normal';
      }
    }

    function renderOptions(query = '') {
      list.innerHTML = '';
      const q = query.toLowerCase().trim();
      let matches = 0;

      Array.from(select.options).forEach((opt, idx) => {
        const text = opt.textContent || '';
        const val = opt.value || '';
        const match = !q || text.toLowerCase().includes(q) || val.toLowerCase().includes(q);

        if (match) {
          matches++;
          const item = document.createElement('div');
          const isSelected = (idx === select.selectedIndex);
          item.className = `px-3 py-2 cursor-pointer transition flex items-center justify-between ${
            isSelected ? 'bg-indigo-50 font-bold text-indigo-800' : 'hover:bg-slate-50 text-slate-700'
          }`;

          if (q && text.toLowerCase().includes(q)) {
            const regex = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
            item.innerHTML = `<span>${text.replace(regex, '<mark class="bg-amber-200 text-slate-900 rounded px-0.5">$1</mark>')}</span>`;
          } else {
            item.textContent = text;
          }

          if (isSelected) {
            const check = document.createElement('span');
            check.className = 'text-indigo-600 font-bold text-xs ml-2';
            check.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
            item.appendChild(check);
          }

          item.addEventListener('click', (e) => {
            e.stopPropagation();
            select.selectedIndex = idx;
            updateTriggerLabel();
            closePanel();
            select.dispatchEvent(new Event('change', { bubbles: true }));
          });

          list.appendChild(item);
        }
      });

      if (matches === 0) {
        emptyState.classList.remove('hidden');
      } else {
        emptyState.classList.add('hidden');
      }
    }

    function openPanel() {
      document.querySelectorAll('.qps-searchable-panel').forEach(p => {
        if (p !== panel) p.classList.add('hidden');
      });

      panel.classList.remove('hidden');
      searchInput.value = '';
      clearBtn.classList.add('hidden');
      renderOptions();
      setTimeout(() => searchInput.focus(), 50);
    }

    function closePanel() {
      panel.classList.add('hidden');
    }

    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      if (panel.classList.contains('hidden')) {
        openPanel();
      } else {
        closePanel();
      }
    });

    searchInput.addEventListener('input', (e) => {
      const q = searchInput.value;
      if (q) {
        clearBtn.classList.remove('hidden');
      } else {
        clearBtn.classList.add('hidden');
      }
      renderOptions(q);
    });

    clearBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      searchInput.value = '';
      clearBtn.classList.add('hidden');
      searchInput.focus();
      renderOptions();
    });

    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closePanel();
        trigger.focus();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const first = list.querySelector('div');
        if (first) first.click();
      }
    });

    select.addEventListener('change', () => {
      updateTriggerLabel();
    });

    select._qpsUpdate = function () {
      updateTriggerLabel();
      renderOptions();
    };

    updateTriggerLabel();
  }

  // Global initializer
  window.initSearchableSelects = function (root = document) {
    const selects = root.querySelectorAll('select:not([data-no-search="true"])');
    selects.forEach(sel => {
      initSelect(sel);
    });
  };

  // Close dropdowns on outside click
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.qps-searchable-wrapper')) {
      document.querySelectorAll('.qps-searchable-panel').forEach(p => p.classList.add('hidden'));
    }
  });

  // Auto initialize
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.initSearchableSelects());
  } else {
    window.initSearchableSelects();
  }

})();
