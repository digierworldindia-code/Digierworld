/* Audited reveal of masked bank values (server checks permission and logs every reveal). */
(function () {
    'use strict';
    document.querySelectorAll('[data-reveal]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const target = document.querySelector(`[data-reveal-target="${btn.dataset.target}"]`);
            if (!target) { return; }
            if (btn.dataset.revealed) { target.textContent = btn.dataset.masked; btn.textContent = 'reveal'; delete btn.dataset.revealed; return; }
            if (!(await DGR.confirm('Reveal this confidential value? The reveal is recorded in the audit log.', 'Reveal'))) { return; }
            try {
                const fd = new FormData(); fd.append('field', btn.dataset.field);
                const data = await DGR.api(btn.dataset.reveal, { method: 'POST', body: fd });
                btn.dataset.masked = target.textContent;
                target.textContent = data.value || '(empty)';
                btn.dataset.revealed = '1'; btn.textContent = 'hide';
                setTimeout(() => { if (btn.dataset.revealed) { btn.click(); } }, 30000);
            } catch (e) { DGR.toast(e.message, 'danger'); }
        });
    });
})();
