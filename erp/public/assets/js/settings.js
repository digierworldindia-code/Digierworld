/* Settings pages: show/hide dependent sections, GSTIN → state/PAN hints, live template preview refresh. */
(function () {
    'use strict';
    document.querySelectorAll('[data-toggle-target]').forEach((cb) => {
        const target = document.querySelector(cb.dataset.toggleTarget);
        const apply = () => { if (target) { target.classList.toggle('d-none', cb.checked); } };
        cb.addEventListener('change', apply); apply();
    });
    document.querySelectorAll('[data-show-when]').forEach((el) => {
        const [name, value] = el.dataset.showWhen.split('=');
        const inputs = document.querySelectorAll(`[name="${name}"]`);
        const apply = () => {
            const cur = Array.from(inputs).find((i) => (i.type === 'radio' || i.type === 'checkbox') ? i.checked : true);
            el.classList.toggle('d-none', !cur || String(cur.value) !== value);
        };
        inputs.forEach((i) => i.addEventListener('change', apply)); apply();
    });

    const gstin = document.getElementById('gstin');
    if (gstin) {
        gstin.addEventListener('input', () => {
            const v = gstin.value.toUpperCase().replace(/\s+/g, '');
            gstin.value = v;
            if (v.length >= 12) {
                const state = document.getElementById('state_code');
                const pan = document.getElementById('pan');
                if (state && !state.value && /^\d{2}/.test(v)) { state.value = v.slice(0, 2); }
                if (pan && !pan.value) { pan.value = v.slice(2, 12); }
            }
        });
    }

    const frame = document.querySelector('[data-template-preview]');
    if (frame) {
        document.querySelectorAll('[data-preview-refresh]').forEach((b) => b.addEventListener('click', () => {
            frame.src = frame.dataset.templatePreview + '?t=' + Date.now();
        }));
    }
})();
