/* Record payment: customer lookup, open invoices, allocation helper. Server re-validates everything. */
(function () {
    'use strict';
    const form = document.querySelector('[data-receipt-form]');
    if (!form) { return; }
    const data = JSON.parse(document.getElementById('receipt-data').textContent);

    // Money in paise to avoid float drift in the on-screen summary.
    const paise = (v) => { const n = String(v || '').replace(/[,\s₹]/g, ''); if (!/^\d*(\.\d{0,2})?$/.test(n) || n === '') { return 0; } const [i, d = ''] = n.split('.'); return Number(i || 0) * 100 + Number((d + '00').slice(0, 2)); };
    const rupees = (p) => '₹' + (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const plain = (p) => (p / 100).toFixed(2);

    const cId = form.querySelector('[data-contact-id]');
    const search = form.querySelector('[data-customer-search]');
    const card = form.querySelector('[data-customer-card]');
    const input = form.querySelector('[data-customer-input]');
    const results = form.querySelector('[data-customer-results]');
    const tbody = form.querySelector('[data-open-docs]');
    const amount = form.querySelector('[data-amount]');
    const mode = form.querySelector('[data-mode]');
    const account = form.querySelector('[data-account]');

    function pickCustomer(c) {
        cId.value = c.id;
        card.querySelector('[data-c-name]').textContent = c.legal_name || c.name;
        card.querySelector('[data-c-meta]').textContent = (c.contact_code || '') + ' · ' + (c.gstin || 'Unregistered');
        search.classList.add('d-none'); card.classList.remove('d-none');
        loadDocs();
    }
    if (input) {
        input.addEventListener('input', DGR.debounce(async () => {
            const q = input.value.trim();
            if (q.length < 2) { results.classList.remove('show'); return; }
            try {
                const r = await DGR.api(data.lookupUrl + '?q=' + encodeURIComponent(q));
                results.innerHTML = r.items.length ? '' : '<span class="dropdown-item-text small text-muted">No matching customer.</span>';
                r.items.forEach((c) => {
                    const b = document.createElement('button');
                    b.type = 'button'; b.className = 'dropdown-item';
                    b.innerHTML = `<strong>${DGR.escape(c.legal_name || c.name)}</strong> <span class="small text-muted">${DGR.escape(c.contact_code)} · ${DGR.escape(c.gstin || 'Unregistered')}</span>`;
                    b.addEventListener('click', () => { results.classList.remove('show'); pickCustomer(c); });
                    results.appendChild(b);
                });
                results.classList.add('show');
            } catch (e) { DGR.toast(e.message, 'danger'); }
        }, 250));
    }
    form.querySelector('[data-change-customer]').addEventListener('click', () => { card.classList.add('d-none'); search.classList.remove('d-none'); input.focus(); });

    async function loadDocs() {
        if (!cId.value) { return; }
        tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Loading…</td></tr>';
        try {
            const r = await DGR.api(data.openUrl + '?contact=' + encodeURIComponent(cId.value));
            tbody.innerHTML = '';
            if (!r.documents.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-muted">No unpaid invoices. The amount will be kept as an advance.</td></tr>'; }
            r.documents.forEach((d) => {
                const tr = document.createElement('tr');
                const prev = data.old.alloc && data.old.alloc[d.id] !== undefined ? data.old.alloc[d.id] : '';
                tr.innerHTML = `<td><span class="mono fw-semibold">${DGR.escape(d.document_number)}</span> <span class="text-muted">${DGR.escape(d.label)}</span></td>`
                    + `<td>${DGR.escape(d.issue_date)}</td><td class="${d.overdue ? 'text-danger' : ''}">${DGR.escape(d.due_date || '—')}</td>`
                    + `<td class="num">${rupees(paise(d.grand_total))}</td><td class="num">${rupees(paise(d.balance_due))}</td>`
                    + `<td class="num"><input class="form-control form-control-sm text-end" inputmode="decimal" name="alloc[${Number(d.id)}]" data-due="${paise(d.balance_due)}" aria-label="Apply to ${DGR.escape(d.document_number)}" ${data.canAllocate ? '' : 'disabled'}></td>`;
                tr.querySelector('input').value = prev;
                tbody.appendChild(tr);
            });
            const adv = form.querySelector('[data-advances]');
            const total = r.advances.reduce((s, a) => s + paise(a.unapplied_amount), 0);
            adv.textContent = total > 0 ? `This customer already has ${rupees(total)} in unapplied advances (${r.advances.map((a) => a.receipt_number).join(', ')}). Apply those from the receipt page instead of recording a new payment if no new money arrived.` : '';
            if (data.focusDocument && !Object.keys(data.old.alloc || {}).length) {
                const inp = tbody.querySelector(`input[name="alloc[${Number(data.focusDocument)}]"]`);
                if (inp && !amount.value) { amount.value = plain(Number(inp.dataset.due)); inp.value = plain(Number(inp.dataset.due)); }
            }
            summary();
        } catch (e) { tbody.innerHTML = ''; DGR.toast(e.message, 'danger'); }
    }

    function summary() {
        const amt = paise(amount.value);
        let applied = 0; let over = '';
        tbody.querySelectorAll('input[data-due]').forEach((i) => {
            const p = paise(i.value); applied += p;
            i.classList.toggle('is-invalid', p > Number(i.dataset.due));
            if (p > Number(i.dataset.due)) { over = 'An amount is more than the balance due on that invoice.'; }
        });
        form.querySelector('[data-s-amount]').textContent = rupees(amt);
        form.querySelector('[data-s-applied]').textContent = rupees(applied);
        form.querySelector('[data-s-advance]').textContent = rupees(Math.max(0, amt - applied));
        const err = form.querySelector('[data-s-error]');
        const msg = over || (applied > amt ? 'Applied total is more than the amount received.' : '');
        err.textContent = msg; err.classList.toggle('d-none', !msg);
        form.querySelector('[data-submit]').disabled = !!msg;
    }
    form.addEventListener('input', (e) => { if (e.target.matches('[data-amount], input[data-due]')) { summary(); } });

    const auto = form.querySelector('[data-auto-allocate]');
    if (auto) {
        auto.addEventListener('click', () => {
            let left = paise(amount.value);
            tbody.querySelectorAll('input[data-due]').forEach((i) => { const take = Math.min(left, Number(i.dataset.due)); i.value = take > 0 ? plain(take) : ''; left -= take; });
            summary();
        });
        form.querySelector('[data-clear-allocate]').addEventListener('click', () => { tbody.querySelectorAll('input[data-due]').forEach((i) => { i.value = ''; }); summary(); });
    }

    // Mode ↔ account consistency (cash → cash account; others → bank/UPI).
    function syncMode() {
        const m = mode.value;
        form.querySelector('[data-cheque-only]').classList.toggle('d-none', m !== 'cheque');
        Array.from(account.options).forEach((o) => {
            if (!o.value) { return; }
            o.disabled = m !== '' && ((m === 'cash') !== (o.dataset.kind === 'cash'));
        });
        if (account.selectedOptions[0] && account.selectedOptions[0].disabled) { account.value = ''; }
        const sel = data.accounts.find((a) => String(a.id) === account.value);
        form.querySelector('[data-s-review]').classList.toggle('d-none', !(sel && sel.review));
        const usable = Array.from(account.options).filter((o) => o.value && !o.disabled);
        if (!account.value && usable.length === 1) { account.value = usable[0].value; }
    }
    mode.addEventListener('change', syncMode);
    account.addEventListener('change', syncMode);
    syncMode();
    if (cId.value) { loadDocs(); }
})();
