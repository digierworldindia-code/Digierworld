/* Create/edit billing document: customer search, line rows, live server-side GST calculation. */
(function () {
    'use strict';
    const form = document.querySelector('[data-billing-form]');
    if (!form) { return; }
    const data = DGR.readJson('billing-data') || {};
    const services = data.services || [];
    const body = form.querySelector('[data-line-body]');
    const tpl = document.getElementById('line-template');
    const posSelect = form.querySelector('#place_of_supply_state_code');
    let posTouched = !!(posSelect && posSelect.value && data.docId);
    let gstTouched = !!form.querySelector('input[name="gst_type"]:checked');

    const fmt = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const rateLabel = (r) => String(Number(r));

    // ---------- customer search ----------
    const searchWrap = form.querySelector('[data-customer-search]');
    const card = form.querySelector('[data-customer-card]');
    const cInput = form.querySelector('[data-customer-input]');
    const cResults = form.querySelector('[data-customer-results]');
    const cId = form.querySelector('[data-contact-id]');
    const fillCustomer = (c) => {
        cId.value = c.id;
        card.querySelector('[data-c="name"]').textContent = c.legal_name || c.name;
        card.querySelector('[data-c="address"]').textContent = c.billing_address || '';
        card.querySelector('[data-c="gstin"]').textContent = c.gstin || 'Unregistered';
        card.querySelector('[data-c="state"]').textContent = c.billing_state_code || '—';
        card.querySelector('[data-c="ctype"]').textContent = (c.customer_type || '').toUpperCase();
        card.querySelector('[data-c="terms"]').textContent = c.credit_days !== null && c.credit_days !== '' && c.credit_days !== undefined ? 'Credit terms: ' + c.credit_days + ' days' : '';
        const rl = form.querySelector('[data-recipient-line]');
        if (rl) { rl.textContent = (c.gstin ? 'Registered · GSTIN ' + c.gstin : 'Unregistered') + ' · state ' + (c.billing_state_code || '?'); }
        searchWrap.classList.add('d-none'); card.classList.remove('d-none');
        posTouched = false; gstTouched = false;
        calc();
    };
    if (cInput) {
        cInput.addEventListener('input', DGR.debounce(async () => {
            const q = cInput.value.trim();
            if (q.length < 2) { cResults.classList.remove('show'); return; }
            try {
                const r = await DGR.api(data.lookupUrl + '?q=' + encodeURIComponent(q));
                cResults.innerHTML = r.items.length ? '' : '<span class="dropdown-item-text small text-muted">No matching customer.</span>';
                r.items.forEach((c) => {
                    const a = document.createElement('button');
                    a.type = 'button'; a.className = 'dropdown-item';
                    a.innerHTML = `<strong>${DGR.escape(c.legal_name || c.name)}</strong> <span class="small text-muted">${DGR.escape(c.contact_code)} · ${DGR.escape(c.gstin || 'Unregistered')} · ${DGR.escape(c.city || '')}</span>`;
                    a.addEventListener('click', () => { cResults.classList.remove('show'); fillCustomer(c); });
                    cResults.appendChild(a);
                });
                cResults.classList.add('show');
            } catch (e) { DGR.toast(e.message, 'danger'); }
        }, 250));
    }
    const changeBtn = form.querySelector('[data-change-customer]');
    if (changeBtn) { changeBtn.addEventListener('click', () => { card.classList.add('d-none'); searchWrap.classList.remove('d-none'); cInput.focus(); }); }

    // ---------- lines ----------
    const reindex = () => {
        body.querySelectorAll('[data-line]').forEach((tr, i) => {
            tr.querySelectorAll('[data-f]').forEach((el) => { el.name = `lines[${i}][${el.dataset.f}]`; });
        });
    };
    const addLine = (focus = true) => {
        const node = tpl.content.firstElementChild.cloneNode(true);
        body.appendChild(node); reindex();
        if (focus) { node.querySelector('[data-f="item_name"]').focus(); }
        return node;
    };
    form.querySelector('[data-add-line]').addEventListener('click', () => addLine());
    body.addEventListener('click', (e) => {
        const b = e.target.closest('[data-remove-line]');
        if (!b) { return; }
        if (body.querySelectorAll('[data-line]').length === 1) { DGR.toast('A document needs at least one line.', 'warning'); return; }
        b.closest('[data-line]').remove(); reindex(); calc();
    });
    body.addEventListener('change', (e) => {
        if (e.target.dataset.f !== 'item_name') { return; }
        const tr = e.target.closest('[data-line]');
        const s = services.find((x) => x.name === e.target.value);
        if (!s) { tr.querySelector('[data-f="service_id"]').value = ''; return; }
        tr.querySelector('[data-f="service_id"]').value = s.id;
        const set = (f, v, onlyEmpty = false) => { const el = tr.querySelector(`[data-f="${f}"]`); if (el && v !== null && v !== undefined && (!onlyEmpty || !el.value || el.value === '0')) { el.value = v; } };
        set('description', s.description, true);
        set('sac_code', s.sac_code || '');
        set('unit', s.unit || '');
        set('unit_rate', Number(s.default_rate) ? String(Number(s.default_rate)) : '', true);
        set('tax_treatment', s.default_tax_treatment || 'regular');
        if (s.gst_rate !== null) {
            const sel = tr.querySelector('[data-f="gst_rate"]');
            const opt = Array.from(sel.options).find((o) => Number(o.value) === Number(s.gst_rate));
            if (opt) { sel.value = opt.value; }
        }
        calc();
    });
    // keyboard: Enter → next field, Ctrl+Enter → new line, Alt+↑/↓ → move line
    body.addEventListener('keydown', (e) => {
        const tr = e.target.closest('[data-line]');
        if (!tr) { return; }
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); addLine(); return; }
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            const fields = Array.from(body.querySelectorAll('input:not([type=hidden]), select, textarea'));
            const next = fields[fields.indexOf(e.target) + 1];
            if (next) { next.focus(); } else { addLine(); }
            return;
        }
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            const sib = e.key === 'ArrowUp' ? tr.previousElementSibling : tr.nextElementSibling;
            if (sib) { e.key === 'ArrowUp' ? body.insertBefore(tr, sib) : body.insertBefore(sib, tr); reindex(); e.target.focus(); calc(); }
        }
    });
    form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input') && !e.target.closest('[data-line]')) { e.preventDefault(); } });

    // ---------- GST cards ----------
    const cards = form.querySelectorAll('[data-gst-card]');
    const syncCards = () => cards.forEach((c) => c.classList.toggle('selected', c.querySelector('input').checked));
    cards.forEach((c) => c.querySelector('input').addEventListener('change', () => { gstTouched = true; syncCards(); }));
    if (posSelect) { posSelect.addEventListener('change', () => { posTouched = true; }); }

    // ---------- live calculation ----------
    const collect = () => {
        const fd = new FormData(form);
        const out = { lines: [] };
        ['doc_type', 'contact_id', 'issue_date', 'place_of_supply_state_code', 'export_type', 'price_mode'].forEach((k) => { out[k] = fd.get(k) || ''; });
        out.gst_type = fd.get('gst_type') || '';
        out.is_sez_recipient = fd.get('is_sez_recipient') ? 1 : 0;
        out.reverse_charge = fd.get('reverse_charge') ? 1 : 0;
        body.querySelectorAll('[data-line]').forEach((tr) => {
            const l = {};
            tr.querySelectorAll('[data-f]').forEach((el) => { l[el.dataset.f] = el.value; });
            out.lines.push(l);
        });
        return out;
    };
    let seq = 0;
    const render = (r) => {
        // per-line outputs (server skips blank lines, so map in order of non-blank rows)
        const rows = Array.from(body.querySelectorAll('[data-line]')).filter((tr) => tr.querySelector('[data-f="item_name"]').value.trim() !== '' || Number(tr.querySelector('[data-f="unit_rate"]').value) !== 0);
        body.querySelectorAll('[data-out]').forEach((td) => { td.textContent = '—'; });
        rows.forEach((tr, i) => {
            const l = r.lines[i]; if (!l) { return; }
            tr.querySelector('[data-out="taxable_value"]').textContent = fmt(l.taxable_value);
            tr.querySelector('[data-out="line_total"]').textContent = fmt(l.line_total);
        });
        const t = r.totals;
        form.querySelectorAll('[data-t]').forEach((el) => { el.textContent = fmt(t[el.dataset.t]); });
        ['cgst', 'sgst', 'utgst', 'igst'].forEach((k) => {
            const row = form.querySelector(`[data-t-row="${k}"]`);
            if (row) { row.classList.toggle('d-none', Number(t[k + '_total']) === 0); }
        });
        form.querySelector('[data-words]').textContent = r.words || '';
        const sum = form.querySelector('[data-tax-summary]');
        sum.innerHTML = r.summary.length ? '<table class="table table-sm mb-0"><thead><tr><th>Tax</th><th>Rate</th><th class="num">Tax ₹</th></tr></thead><tbody>' +
            r.summary.map((s) => `<tr><td>${DGR.escape(s.tax_type)}</td><td>${rateLabel(s.rate)}%</td><td class="num">${fmt(s.tax_amount)}</td></tr>`).join('') + '</tbody></table>' : '<span class="text-muted">No GST charged.</span>';

        // GST cards: preview amounts + legality
        const allowed = r.advice.allowed_types || [];
        cards.forEach((c) => {
            const k = c.dataset.gstCard;
            const p = r.preview[k];
            const prev = c.querySelector('[data-gst-preview]');
            if (k === 'none') { prev.textContent = 'Tax ₹0.00'; }
            else if (p) {
                prev.textContent = p.summary.length ? p.summary.map((s) => `${s.tax_type} ${rateLabel(s.rate)}% ${fmt(s.tax_amount)}`).join(' + ') + ' = ' + fmt(p.tax_total) : 'No taxable lines';
            }
            const ok = allowed.length === 0 || allowed.includes(k);
            c.classList.toggle('disabled', !ok);
            c.title = ok ? '' : 'Not legally possible for this place of supply';
        });
        // suggestions (only until the user makes an explicit choice)
        if (!posTouched && r.advice.suggested_pos && posSelect && posSelect.value !== r.advice.suggested_pos) {
            posSelect.value = r.advice.suggested_pos; posTouched = false; calc(); return;
        }
        if (!gstTouched && r.advice.suggested_type) {
            const radio = form.querySelector(`input[name="gst_type"][value="${r.advice.suggested_type}"]`);
            if (radio && !radio.checked) { radio.checked = true; syncCards(); calc(); return; }
        }
        // advice panel
        const exp = form.querySelector('[data-advice-explain]');
        exp.textContent = r.advice.explanation ? 'Suggested: place of supply ' + (r.advice.suggested_pos || '—') + ', ' + ({ cgst_sgst: 'CGST + SGST', cgst_utgst: 'CGST + UTGST', igst: 'IGST', none: 'No GST' }[r.advice.suggested_type] || '—') + '. ' + r.advice.explanation : '';
        exp.classList.toggle('d-none', !r.advice.explanation);
        const box = form.querySelector('[data-advice-messages]');
        const icon = { block: 'x-octagon', review: 'person-check', warn: 'exclamation-triangle' };
        const cls = { block: 'block', review: 'warn', warn: '' };
        box.innerHTML = r.advice.messages.map((m) => `<div class="tax-advice ${cls[m.level] || ''} mb-2"><i class="bi bi-${icon[m.level] || 'info-circle'}"></i> <strong>${m.level === 'block' ? 'Blocks finalization' : (m.level === 'review' ? 'Needs authorised review' : 'Note')}:</strong> ${DGR.escape(m.text)}</div>`).join('')
            || '<div class="tax-advice"><i class="bi bi-check-circle"></i> Tax type and place of supply are consistent.</div>';
    };
    const calc = DGR.debounce(async () => {
        const mine = ++seq;
        try {
            const r = await DGR.api(data.calcUrl, { method: 'POST', body: collect() });
            if (mine === seq) { render(r); }
        } catch (e) { if (e.status === 403) { DGR.toast(e.message, 'danger'); } }
    }, 300);
    form.addEventListener('input', (e) => { if (e.target.matches('[data-calc]')) { calc(); } });
    form.addEventListener('change', (e) => { if (e.target.matches('[data-calc]')) { calc(); } });
    syncCards();
    calc();
})();
