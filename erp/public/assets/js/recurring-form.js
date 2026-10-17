/* Retainer lines: add rows and fill from the service catalogue. */
(function () {
    'use strict';
    const form = document.querySelector('[data-recurring-form]');
    if (!form) { return; }
    const services = JSON.parse(document.getElementById('recurring-data').textContent);
    const body = form.querySelector('[data-rlines]');
    body.addEventListener('change', (e) => {
        if (!e.target.matches('[data-svc]')) { return; }
        const s = services[e.target.value]; const tr = e.target.closest('tr');
        if (!s) { return; }
        const set = (f, v) => { const el = tr.querySelector(`[data-f="${f}"]`); if (el && v !== null && v !== undefined && v !== '') { el.value = v; } };
        set('item_name', s.name); set('sac_code', s.sac_code); set('unit', s.unit); set('unit_rate', s.default_rate); set('gst_rate', s.gst_rate);
    });
    form.querySelector('[data-radd]').addEventListener('click', () => {
        const clone = body.lastElementChild.cloneNode(true);
        const n = body.children.length;
        clone.querySelectorAll('[name]').forEach((el) => {
            el.name = el.name.replace(/lines\[\d+\]/, `lines[${n}]`);
            if (el.type === 'hidden') { el.value = 'regular'; } else if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = el.dataset.f === 'qty' ? '1' : (el.dataset.f === 'gst_rate' ? '18' : ''); }
        });
        body.appendChild(clone);
    });
})();
