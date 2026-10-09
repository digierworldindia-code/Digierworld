/* BearingCave UI behaviours — vanilla JS, no framework. */
(function () {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));

  // Confirm destructive actions: <form data-confirm="..."> or <button data-confirm="...">
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const msg = form.dataset.confirm || (e.submitter && e.submitter.dataset.confirm);
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    // Prevent double submission and show busy state
    const btn = e.submitter || $('button[type=submit]', form);
    if (btn && !form.hasAttribute('data-no-busy')) {
      btn.setAttribute('aria-busy', 'true');
      if (!btn.dataset.originalHtml) { btn.dataset.originalHtml = btn.innerHTML; }
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + btn.innerHTML;
      setTimeout(() => { btn.removeAttribute('aria-busy'); btn.innerHTML = btn.dataset.originalHtml; }, 8000);
    }
  });

  // Search autocomplete
  $$('[data-autocomplete]').forEach((input) => {
    const wrap = input.closest('.position-relative') || input.parentElement;
    const menu = document.createElement('div');
    menu.className = 'autocomplete-menu d-none';
    menu.setAttribute('role', 'listbox');
    menu.id = 'ac-' + Math.random().toString(36).slice(2);
    input.setAttribute('aria-controls', menu.id);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('autocomplete', 'off');
    wrap.appendChild(menu);
    let timer = null, items = [], active = -1, controller = null;
    const close = () => { menu.classList.add('d-none'); active = -1; input.setAttribute('aria-expanded', 'false'); };
    const render = () => {
      menu.innerHTML = '';
      items.forEach((it, i) => {
        const a = document.createElement('a');
        a.href = it.url; a.setAttribute('role', 'option');
        if (i === active) a.classList.add('active');
        const label = document.createElement('span'); label.textContent = it.label;
        const type = document.createElement('small'); type.textContent = it.type;
        a.append(label, type); menu.appendChild(a);
      });
      menu.classList.toggle('d-none', items.length === 0);
      input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
    };
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { items = []; render(); return; }
      timer = setTimeout(() => {
        if (controller) controller.abort();
        controller = new AbortController();
        fetch(input.dataset.autocomplete + '?q=' + encodeURIComponent(q), { signal: controller.signal, headers: { 'Accept': 'application/json' } })
          .then((r) => r.ok ? r.json() : { data: [] })
          .then((json) => { items = json.data || []; active = -1; render(); })
          .catch(() => {});
      }, 200);
    });
    input.addEventListener('keydown', (e) => {
      if (menu.classList.contains('d-none')) return;
      if (e.key === 'ArrowDown') { active = Math.min(items.length - 1, active + 1); render(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { active = Math.max(-1, active - 1); render(); e.preventDefault(); }
      else if (e.key === 'Enter' && active >= 0) { window.location = items[active].url; e.preventDefault(); }
      else if (e.key === 'Escape') { close(); }
    });
    document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) close(); });
  });

  // Repeaters: <div data-repeater="name"> ... <template> ... __INDEX__ ... </template>
  $$('[data-repeater]').forEach((box) => {
    const tpl = $('template', box);
    const list = $('[data-repeater-list]', box);
    let index = $$('[data-repeater-item]', list).length;
    $('[data-repeater-add]', box)?.addEventListener('click', () => {
      const html = tpl.innerHTML.replace(/__INDEX__/g, String(index++));
      list.insertAdjacentHTML('beforeend', html);
      const first = list.lastElementChild?.querySelector('input,select,textarea');
      first && first.focus();
    });
    list.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-repeater-remove]');
      if (btn) { btn.closest('[data-repeater-item]').remove(); }
    });
  });

  // Show/hide dependent fields: <select data-toggle-target="#id" data-toggle-hide-value="all">
  $$('[data-toggle-target]').forEach((sel) => {
    const target = $(sel.dataset.toggleTarget);
    const hideVal = sel.dataset.toggleHideValue;
    const sync = () => { if (target) target.classList.toggle('d-none', sel.value === hideVal); };
    sel.addEventListener('change', sync); sync();
  });

  // Client-side preview of service fee estimates (server recalculates authoritatively)
  $$('[data-fee-estimate]').forEach((box) => {
    const pct = parseFloat(box.dataset.pct || '0');
    const min = parseFloat(box.dataset.min || '0');
    const value = $(box.dataset.valueInput);
    const type = $(box.dataset.typeInput);
    const out = $('[data-fee-output]', box);
    const sync = () => {
      if (!out) return;
      if (type && type.value !== 'in_house') { out.textContent = 'Quoted by BearingCave after review'; return; }
      const v = parseFloat(value && value.value);
      if (!v || v <= 0) { out.textContent = 'Enter invoice value for an estimate'; return; }
      const fee = Math.max(v * pct / 100, min);
      out.textContent = fee.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' (estimate)';
    };
    [value, type].forEach((el) => el && el.addEventListener('input', sync));
    [value, type].forEach((el) => el && el.addEventListener('change', sync));
    sync();
  });

  // Product gallery
  $$('[data-gallery]').forEach((g) => {
    const main = $('[data-gallery-main]', g);
    $$('[data-gallery-thumb]', g).forEach((btn) => btn.addEventListener('click', () => {
      main.src = btn.dataset.src; main.alt = btn.dataset.alt || '';
      $$('[data-gallery-thumb]', g).forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
    }));
  });

  // Mobile sidebar
  $$('[data-sidebar-toggle]').forEach((b) => b.addEventListener('click', () => {
    const sb = $('#appSidebar'); if (!sb) return;
    sb.classList.toggle('show');
    b.setAttribute('aria-expanded', sb.classList.contains('show') ? 'true' : 'false');
  }));
  document.addEventListener('click', (e) => {
    const sb = $('#appSidebar');
    if (sb && sb.classList.contains('show') && !sb.contains(e.target) && !e.target.closest('[data-sidebar-toggle]')) sb.classList.remove('show');
  });

  // Select all checkboxes: <input type=checkbox data-check-all=".selector">
  $$('[data-check-all]').forEach((c) => c.addEventListener('change', () => {
    $$(c.dataset.checkAll).forEach((x) => { x.checked = c.checked; });
  }));

  // Tooltips
  if (window.bootstrap) { $$('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el)); }
})();
