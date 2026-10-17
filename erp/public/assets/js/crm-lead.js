/* Lead page: follow-up quick picks, lost-reason toggle, client search in the convert dialog. */
(function () {
    'use strict';
    const pad = (n) => String(n).padStart(2, '0');
    const local = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const next = document.querySelector('[data-next]');
    document.querySelectorAll('[data-quick]').forEach((b) => b.addEventListener('click', () => {
        const d = new Date();
        switch (b.dataset.quick) {
            case '2h': d.setHours(d.getHours() + 2, 0, 0, 0); break;
            case 'tomorrow': d.setDate(d.getDate() + 1); d.setHours(11, 0, 0, 0); break;
            case '3d': d.setDate(d.getDate() + 3); d.setHours(11, 0, 0, 0); break;
            case 'week': d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(11, 0, 0, 0); break;
        }
        if (next) { next.value = local(d); }
    }));

    const stageSel = document.querySelector('[data-stage-select]');
    const lostWrap = document.querySelector('[data-lost-wrap]');
    const syncLost = () => { if (stageSel && lostWrap) { lostWrap.classList.toggle('d-none', stageSel.selectedOptions[0]?.dataset.lost !== '1'); } };
    if (stageSel) { stageSel.addEventListener('change', syncLost); syncLost(); }

    const search = document.querySelector('[data-cv-search]');
    if (search) {
        const results = document.querySelector('[data-cv-results]');
        const hidden = document.querySelector('[data-cv-contact]');
        search.addEventListener('input', DGR.debounce(async () => {
            hidden.value = '';
            const q = search.value.trim();
            if (q.length < 2) { results.classList.remove('show'); return; }
            try {
                const r = await DGR.api(search.dataset.lookup + '&q=' + encodeURIComponent(q));
                results.innerHTML = r.items.length ? '' : '<span class="dropdown-item-text small text-muted">No client found — a new one will be created.</span>';
                r.items.forEach((c) => {
                    const b = document.createElement('button'); b.type = 'button'; b.className = 'dropdown-item';
                    b.innerHTML = `<strong>${DGR.escape(c.legal_name || c.name)}</strong> <span class="small text-muted">${DGR.escape(c.gstin || '')}</span>`;
                    b.addEventListener('click', () => { hidden.value = c.id; search.value = c.legal_name || c.name; results.classList.remove('show'); });
                    results.appendChild(b);
                });
                results.classList.add('show');
            } catch (e) { DGR.toast(e.message, 'danger'); }
        }, 250));
    }

    // Keyboard: Alt+1..9 picks a call outcome
    document.addEventListener('keydown', (e) => {
        if (!e.altKey || !/^[1-9]$/.test(e.key)) { return; }
        const radios = document.querySelectorAll('[data-call-form] input[name="outcome"]');
        const r = radios[Number(e.key) - 1];
        if (r) { r.checked = true; e.preventDefault(); }
    });
})();
