/* Contact form: live GSTIN validation, state/PAN suggestion and duplicate warning. */
(function () {
    'use strict';
    const form = document.querySelector('[data-contact-form]');
    if (!form) { return; }
    const gstin = form.querySelector('[data-gstin-check]');
    const result = form.querySelector('[data-gstin-result]');
    const state = form.querySelector('#state_code');
    const pan = form.querySelector('#pan');
    const reg = form.querySelector('#gst_registration_type');
    const dupeAlert = form.querySelector('[data-dupe-alert]');

    const check = DGR.debounce(async () => {
        const v = gstin.value.toUpperCase().replace(/\s+/g, '');
        gstin.value = v;
        if (v.length !== 15) { result.textContent = v ? `${v.length}/15 characters` : ''; result.className = 'form-text'; return; }
        try {
            const r = await DGR.api('contacts/gstin-check?gstin=' + encodeURIComponent(v));
            if (r.valid) {
                result.textContent = `✓ Valid structure · state ${r.state_code} ${r.state_name || ''}. ${r.note}`;
                result.className = 'form-text text-success';
                if (state && !state.value) { state.value = r.state_code; }
                if (state && state.value && state.value !== r.state_code) { result.textContent += ` ⚠ Selected state differs from GSTIN state — give a reason.`; result.className = 'form-text text-warning'; }
                if (pan && !pan.value && r.pan) { pan.value = r.pan; }
                if (reg && (reg.value === 'unregistered' || reg.value === 'consumer')) { reg.value = 'registered'; }
            } else { result.textContent = '✗ ' + r.error; result.className = 'form-text text-danger'; }
        } catch (e) { result.textContent = ''; }
    }, 300);
    if (gstin) { gstin.addEventListener('input', check); }

    const dupes = DGR.debounce(async () => {
        const g = (form.querySelector('#gstin') || {}).value || '';
        const p = (form.querySelector('#phone') || {}).value || '';
        if (g.length < 15 && p.replace(/\D/g, '').length < 10) { dupeAlert.classList.add('d-none'); return; }
        try {
            const r = await DGR.api(`contacts/duplicates?gstin=${encodeURIComponent(g)}&phone=${encodeURIComponent(p)}&exclude=${form.dataset.exclude}`);
            if (r.items.length) {
                dupeAlert.innerHTML = '<i class="bi bi-files"></i> Possible duplicate: ' + r.items.map((i) => `<a href="${DGR.escape(i.url)}" target="_blank" rel="noopener">${DGR.escape(i.label)}</a>`).join(', ');
                dupeAlert.classList.remove('d-none');
            } else { dupeAlert.classList.add('d-none'); }
        } catch (e) { dupeAlert.classList.add('d-none'); }
    }, 400);
    form.querySelectorAll('[data-dupe-check]').forEach((el) => el.addEventListener('input', dupes));
})();
