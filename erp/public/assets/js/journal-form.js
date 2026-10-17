/* Manual journal: add lines and show Dr/Cr totals. The server re-checks the balance. */
(function () {
    'use strict';
    const form = document.querySelector('[data-journal-form]');
    if (!form) { return; }
    const body = form.querySelector('[data-jv-body]');
    const paise = (v) => { const n = String(v || '').replace(/[,\s]/g, ''); if (!/^\d*(\.\d{0,2})?$/.test(n) || n === '') { return 0; } const [i, d = ''] = n.split('.'); return Number(i || 0) * 100 + Number((d + '00').slice(0, 2)); };
    function totals() {
        let dr = 0; let cr = 0;
        body.querySelectorAll('[data-dr]').forEach((i) => { dr += paise(i.value); });
        body.querySelectorAll('[data-cr]').forEach((i) => { cr += paise(i.value); });
        form.querySelector('[data-jv-dr]').textContent = (dr / 100).toFixed(2);
        form.querySelector('[data-jv-cr]').textContent = (cr / 100).toFixed(2);
        const ok = dr > 0 && dr === cr;
        const st = form.querySelector('[data-jv-status]');
        st.textContent = ok ? 'Balanced' : (dr || cr ? 'Difference: ' + (Math.abs(dr - cr) / 100).toFixed(2) : '');
        st.className = 'small ms-2 ' + (ok ? 'text-success' : 'text-danger');
        form.querySelector('[data-jv-submit]').disabled = !ok;
    }
    form.addEventListener('input', totals);
    form.querySelector('[data-jv-add]').addEventListener('click', () => {
        const last = body.lastElementChild;
        const clone = last.cloneNode(true);
        const n = body.children.length;
        clone.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace(/lines\[\d+\]/, `lines[${n}]`); el.value = ''; });
        body.appendChild(clone);
    });
    totals();
})();
