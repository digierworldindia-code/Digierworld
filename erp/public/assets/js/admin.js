/* Users & Roles helpers: permission table filter, matrix bulk toggles, danger warnings. */
(function () {
    'use strict';
    const filter = document.querySelector('[data-perm-filter]');
    if (filter) {
        filter.addEventListener('input', () => {
            const q = filter.value.toLowerCase().trim();
            document.querySelectorAll('[data-perm-row]').forEach((tr) => {
                tr.classList.toggle('d-none', q !== '' && !tr.dataset.permRow.toLowerCase().includes(q));
            });
        });
    }

    // Role editor: toggle a whole module, and warn when sensitive permissions get ticked
    document.querySelectorAll('[data-module-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const boxes = document.querySelectorAll(`input[data-module="${btn.dataset.moduleToggle}"]`);
            const allOn = Array.from(boxes).every((b) => b.checked);
            boxes.forEach((b) => { b.checked = !allOn; b.dispatchEvent(new Event('change', { bubbles: true })); });
        });
    });
    const warn = document.querySelector('[data-danger-warning]');
    const updateWarn = () => {
        if (!warn) { return; }
        const n = document.querySelectorAll('input[data-danger="1"]:checked').length;
        warn.classList.toggle('d-none', n === 0);
        const c = warn.querySelector('[data-danger-count]'); if (c) { c.textContent = n; }
    };
    document.addEventListener('change', (e) => { if (e.target.matches('input[data-module]')) { updateWarn(); } });
    updateWarn();
})();
