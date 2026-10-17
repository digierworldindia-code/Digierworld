/* Visit form: pick the lead and prefill location/address. */
(function () {
    'use strict';
    const input = document.querySelector('[data-lead-search]');
    if (!input) { return; }
    const results = document.querySelector('[data-lead-results]');
    const hidden = document.querySelector('[data-lead-id]');
    input.addEventListener('input', DGR.debounce(async () => {
        hidden.value = '';
        const q = input.value.trim();
        if (q.length < 2) { results.classList.remove('show'); return; }
        try {
            const r = await DGR.api(input.dataset.lookup + '?q=' + encodeURIComponent(q));
            results.innerHTML = r.items.length ? '' : '<span class="dropdown-item-text small text-muted">No lead found.</span>';
            r.items.forEach((l) => {
                const b = document.createElement('button'); b.type = 'button'; b.className = 'dropdown-item';
                b.innerHTML = `<strong>${DGR.escape(l.company_name)}</strong> <span class="small text-muted">${DGR.escape(l.lead_code)} · ${DGR.escape(l.city || '')}</span>`;
                b.addEventListener('click', () => {
                    hidden.value = l.id; input.value = l.company_name; results.classList.remove('show');
                    const loc = document.querySelector('[data-loc]'); const addr = document.querySelector('[data-addr]');
                    if (loc && !loc.value) { loc.value = l.company_name; }
                    if (addr && !addr.value) { addr.value = [l.address, l.city].filter(Boolean).join(', '); }
                });
                results.appendChild(b);
            });
            results.classList.add('show');
        } catch (e) { DGR.toast(e.message, 'danger'); }
    }, 250));
})();
