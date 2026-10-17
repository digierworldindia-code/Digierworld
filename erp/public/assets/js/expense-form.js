/* Expense form: vendor lookup, GST type hint, on-screen estimate. The server computes the real figures. */
(function () {
    'use strict';
    const form = document.querySelector('[data-expense-form]');
    if (!form) { return; }
    const data = JSON.parse(document.getElementById('expense-data').textContent);
    const paise = (v) => { const n = String(v || '').replace(/[,\s₹]/g, ''); if (!/^\d*(\.\d{0,2})?$/.test(n) || n === '') { return 0; } const [i, d = ''] = n.split('.'); return Number(i || 0) * 100 + Number((d + '00').slice(0, 2)); };
    const rupees = (p) => '₹' + (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const $ = (s) => form.querySelector(s);

    const vInput = $('[data-vendor-input]'); const vRes = $('[data-vendor-results]'); const vId = $('[data-vendor-id]'); const vGstin = $('[data-vendor-gstin]');
    vInput.addEventListener('input', DGR.debounce(async () => {
        const q = vInput.value.trim();
        if (q === '') { vId.value = ''; }
        if (q.length < 2) { vRes.classList.remove('show'); return; }
        try {
            const r = await DGR.api(data.lookupUrl + '?kind=vendor&q=' + encodeURIComponent(q));
            vRes.innerHTML = r.items.length ? '' : '<span class="dropdown-item-text small text-muted">No vendor found.</span>';
            r.items.forEach((c) => {
                const b = document.createElement('button'); b.type = 'button'; b.className = 'dropdown-item';
                b.innerHTML = `<strong>${DGR.escape(c.name)}</strong> <span class="small text-muted">${DGR.escape(c.gstin || 'Unregistered')}</span>`;
                b.addEventListener('click', () => { vId.value = c.id; vInput.value = c.name; vGstin.value = c.gstin || ''; vRes.classList.remove('show'); hint(); calc(); });
                vRes.appendChild(b);
            });
            vRes.classList.add('show');
        } catch (e) { DGR.toast(e.message, 'danger'); }
    }, 250));

    function hint() {
        const g = vGstin.value.trim().toUpperCase();
        const el = $('[data-gst-hint]');
        if (g.length >= 2 && data.companyState) {
            const same = g.slice(0, 2) === data.companyState;
            el.textContent = same ? 'Vendor is in your state: the bill should show CGST + SGST (or CGST + UTGST in a UT).' : 'Vendor is in another state: the bill should show IGST.';
        } else { el.textContent = 'Enter exactly what the vendor charged on the bill.'; }
    }

    function calc() {
        const taxable = paise($('[data-taxable]').value);
        const type = $('[data-gst-type]').value;
        const rate = Number($('[data-gst-rate]').value || 0);
        let tax = 0;
        if (type === 'igst') { tax = Math.round(taxable * rate / 100); }
        else if (type !== 'none') { const half = Math.round(taxable * rate / 200); tax = half * 2; }
        const ruleSel = $('[data-itc-rule]');
        const ruleId = (ruleSel && ruleSel.value) ? Number(ruleSel.value) : Number(data.categories[$('[data-category]').value] || 0);
        const rule = data.rules.find((r) => r.id === ruleId);
        const pct = rule && rule.eligible && vGstin.value.trim().length === 15 ? Number(rule.pct) : 0;
        const itc = Math.round(tax * pct / 100);
        form.querySelector('[data-s="taxable"]').textContent = rupees(taxable);
        form.querySelector('[data-s="tax"]').textContent = rupees(tax);
        form.querySelector('[data-s="total"]').textContent = rupees(taxable + tax);
        form.querySelector('[data-s="itc"]').textContent = rupees(itc);
    }
    form.addEventListener('input', calc);
    form.addEventListener('change', calc);
    vGstin.addEventListener('input', hint);
    $('[data-paid-through]').addEventListener('change', (e) => { $('[data-employee-wrap]').classList.toggle('d-none', e.target.value !== 'employee_reimbursable'); });
    hint(); calc();
})();
