/* DGR Office — global behaviours. No inline scripts anywhere (CSP script-src 'self'). */
(function () {
    'use strict';

    const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.getAttribute('content') || '';
    const BASE = meta('base-url');

    const DGR = {
        url(path) { return BASE + '/' + String(path).replace(/^\/+/, ''); },
        csrf() { return { header: meta('csrf-header'), token: meta('csrf-token'), field: meta('csrf-field') }; },

        /** fetch wrapper: JSON in/out, CSRF header, friendly errors. */
        async api(path, opts = {}) {
            const c = DGR.csrf();
            const headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, opts.headers || {});
            let body = opts.body;
            if (body && !(body instanceof FormData) && typeof body !== 'string') {
                headers['Content-Type'] = 'application/json';
                body = JSON.stringify(body);
            }
            if ((opts.method || 'GET').toUpperCase() !== 'GET') { headers[c.header] = c.token; }
            const res = await fetch(path.startsWith('http') ? path : DGR.url(path), { method: opts.method || 'GET', headers, body, credentials: 'same-origin' });
            let data = null;
            try { data = await res.json(); } catch (e) { data = null; }
            if (!res.ok) {
                const msg = (data && (data.message || data.error)) || (res.status === 403 ? 'You do not have permission for this action.' : 'Request failed (' + res.status + ').');
                const err = new Error(msg); err.status = res.status; err.data = data; throw err;
            }
            return data;
        },

        /** Chart.js defaults and series colours from the design-system tokens (dgr-theme.css). */
        chartTheme() {
            const cs = getComputedStyle(document.documentElement);
            const v = (n) => cs.getPropertyValue(n).trim();
            const t = {
                gold: v('--dgr-gold'), text: v('--dgr-text'), text2: v('--dgr-text-2'), border: v('--dgr-border'), card: v('--dgr-card'),
                series: [1, 2, 3, 4, 5, 6, 7, 8].map((i) => v('--dgr-chart-' + i)),
            };
            if (window.Chart) {
                const d = window.Chart.defaults;
                d.font.family = getComputedStyle(document.body).fontFamily;
                d.color = t.text2;
                d.borderColor = t.border;
                d.elements.arc.borderColor = t.card;
                d.plugins.legend.labels.color = t.text2;
                Object.assign(d.plugins.tooltip, { backgroundColor: v('--dgr-bg'), borderColor: v('--dgr-border-strong'), borderWidth: 1, titleColor: t.text, bodyColor: t.text });
            }
            return t;
        },

        /**
         * Marks a button as working (spinner, disabled look, aria-busy) and
         * returns a function that restores it. busy(btn, false) restores now.
         */
        busy(btn, on = true) {
            if (!btn || btn.tagName === 'FORM') { return () => {}; }
            const restore = () => {
                btn.removeAttribute('aria-busy');
                btn.classList.remove('is-busy');
                btn.querySelectorAll('.dgr-spinner').forEach((s) => s.remove());
            };
            if (!on) { restore(); return restore; }
            if (btn.getAttribute('aria-busy') !== 'true') {
                btn.setAttribute('aria-busy', 'true');
                btn.classList.add('is-busy');
                const spin = document.createElement('span');
                spin.className = 'spinner-border spinner-border-sm me-1 dgr-spinner';
                spin.setAttribute('aria-hidden', 'true');
                btn.prepend(spin);
            }
            return restore;
        },

        toast(message, type = 'success') {
            const wrap = document.querySelector('[data-toasts]');
            if (!wrap) { return; }
            const el = document.createElement('div');
            const tone = ['success', 'danger', 'warning', 'info'].includes(type) ? type : 'info';
            el.className = `toast align-items-center toast-${tone}`;
            el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
            el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
            el.querySelector('.toast-body').textContent = message;
            wrap.appendChild(el);
            const t = new bootstrap.Toast(el, { delay: 4500 }); t.show();
            el.addEventListener('hidden.bs.toast', () => el.remove());
        },

        /** Promise-based confirm using the shared modal. */
        confirm(message, okLabel = 'Confirm', danger = false) {
            return new Promise((resolve) => {
                const modalEl = document.getElementById('confirmModal');
                if (!modalEl) { resolve(window.confirm(message)); return; }
                modalEl.querySelector('[data-confirm-body]').textContent = message;
                const ok = modalEl.querySelector('[data-confirm-ok]');
                ok.textContent = okLabel;
                ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                let decided = false;
                const onOk = () => { decided = true; modal.hide(); resolve(true); };
                ok.addEventListener('click', onOk, { once: true });
                modalEl.addEventListener('hidden.bs.modal', () => { ok.removeEventListener('click', onOk); if (!decided) { resolve(false); } }, { once: true });
                modal.show();
            });
        },

        escape(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; },

        inr(v) {
            const n = Number(v || 0);
            return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        debounce(fn, ms = 250) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; },

        readJson(id) { const el = document.getElementById(id); if (!el) { return null; } try { return JSON.parse(el.textContent); } catch (e) { return null; } },
    };
    window.DGR = DGR;

    // Confirm before submitting forms / following links marked data-confirm
    document.addEventListener('submit', async (ev) => {
        const form = ev.target;
        if (!(form instanceof HTMLFormElement)) { return; }
        const submitter = ev.submitter;
        const msg = (submitter && submitter.dataset.confirm) || form.dataset.confirm;
        if (msg && !form.dataset.confirmed) {
            ev.preventDefault();
            const danger = (submitter && submitter.dataset.confirmDanger !== undefined) || form.dataset.confirmDanger !== undefined;
            if (await DGR.confirm(msg, (submitter && submitter.dataset.confirmLabel) || 'Confirm', danger)) {
                form.dataset.confirmed = '1';
                if (submitter && submitter.name) {
                    const h = document.createElement('input'); h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value; form.appendChild(h);
                }
                form.submit();
            }
            return;
        }
        // prevent double submission, and show that the request is on its way
        if (form.dataset.submitting) { ev.preventDefault(); return; }
        if (!form.hasAttribute('data-allow-resubmit')) {
            form.dataset.submitting = '1';
            const busy = DGR.busy(submitter || form.querySelector('[type="submit"], button:not([type])'));
            setTimeout(() => { delete form.dataset.submitting; busy(); }, 4000);
        }
    });

    // pages restored from the back/forward cache must not keep "busy" buttons
    window.addEventListener('pageshow', (ev) => {
        if (ev.persisted) {
            document.querySelectorAll('form[data-submitting]').forEach((f) => { delete f.dataset.submitting; });
            document.querySelectorAll('[aria-busy="true"]').forEach((b) => DGR.busy(b, false));
        }
    });

    // POST-link helper: <a data-post="/url" data-confirm="…"> submits a CSRF-protected POST
    document.addEventListener('click', async (ev) => {
        const a = ev.target.closest('[data-post]');
        if (!a) { return; }
        ev.preventDefault();
        if (a.dataset.confirm && !(await DGR.confirm(a.dataset.confirm, a.dataset.confirmLabel || 'Confirm', a.dataset.confirmDanger !== undefined))) { return; }
        const c = DGR.csrf();
        const f = document.createElement('form');
        f.method = 'post'; f.action = a.dataset.post;
        f.innerHTML = `<input type="hidden" name="${DGR.escape(c.field)}" value="${DGR.escape(c.token)}">`;
        Object.entries(a.dataset).forEach(([k, v]) => {
            if (k.startsWith('field')) { const i = document.createElement('input'); i.type = 'hidden'; i.name = k.slice(5).replace(/^./, (x) => x.toLowerCase()); i.value = v; f.appendChild(i); }
        });
        document.body.appendChild(f); f.submit();
    });

    // Sidebar menu filter
    const filter = document.querySelector('[data-menu-filter]');
    if (filter) {
        filter.addEventListener('input', () => {
            const q = filter.value.trim().toLowerCase();
            document.querySelectorAll('[data-menu-section]').forEach((sec) => {
                let any = false;
                sec.querySelectorAll('[data-menu-item]').forEach((it) => {
                    const hit = !q || it.textContent.toLowerCase().includes(q);
                    it.classList.toggle('d-none', !hit); any = any || hit;
                });
                sec.classList.toggle('d-none', !any);
                const col = sec.querySelector('.collapse');
                if (q && any && col) { col.classList.add('show'); }
            });
        });
    }

    // Notifications dropdown (lazy load)
    const notifToggle = document.querySelector('[data-notif-toggle]');
    if (notifToggle) {
        notifToggle.addEventListener('show.bs.dropdown', async () => {
            const list = document.querySelector('[data-notif-list]');
            try {
                const data = await DGR.api('notifications/feed');
                if (!data.items.length) { list.innerHTML = '<div class="p-3 text-muted small">You are all caught up.</div>'; return; }
                list.innerHTML = data.items.map((n) => `<a class="notif-item ${n.read ? '' : 'unread'}" href="${DGR.escape(n.url)}"><div class="fw-semibold small">${DGR.escape(n.title)}</div>${n.body ? `<div class="small text-muted">${DGR.escape(n.body)}</div>` : ''}<div class="small text-muted">${DGR.escape(n.when)}</div></a>`).join('');
            } catch (e) { list.innerHTML = '<div class="p-3 text-danger small">Could not load notifications.</div>'; }
        });
    }

    // Global search suggestions (authorisation-filtered server side)
    const gs = document.querySelector('[data-global-search]');
    const sugg = document.querySelector('[data-search-suggest]');
    if (gs && sugg) {
        const run = DGR.debounce(async () => {
            const q = gs.value.trim();
            if (q.length < 2) { sugg.classList.remove('show'); return; }
            try {
                const data = await DGR.api('search/suggest?q=' + encodeURIComponent(q));
                if (!data.items.length) { sugg.innerHTML = '<span class="dropdown-item-text small text-muted">No matches you can access.</span>'; }
                else {
                    sugg.innerHTML = data.items.map((i) => `<a class="dropdown-item" href="${DGR.escape(i.url)}"><span class="badge badge-neutral me-2">${DGR.escape(i.type)}</span>${DGR.escape(i.label)} <span class="small text-muted">${DGR.escape(i.sub || '')}</span></a>`).join('');
                }
                sugg.classList.add('show');
            } catch (e) { sugg.classList.remove('show'); }
        }, 250);
        gs.addEventListener('input', run);
        gs.addEventListener('blur', () => setTimeout(() => sugg.classList.remove('show'), 200));
    }

    // Column chooser: <table data-columns-key="contacts"> + <button data-col-chooser="contacts">
    document.querySelectorAll('table[data-columns-key]').forEach((table) => {
        const key = 'dgr.cols.' + table.dataset.columnsKey;
        let hidden = [];
        try { hidden = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { hidden = []; }
        const apply = () => {
            const heads = Array.from(table.tHead ? table.tHead.rows[0].cells : []);
            heads.forEach((th, idx) => {
                const name = th.dataset.col; if (!name) { return; }
                const hide = hidden.includes(name);
                table.querySelectorAll('tr').forEach((tr) => { if (tr.cells[idx]) { tr.cells[idx].classList.toggle('d-none', hide); } });
            });
        };
        apply();
        const menu = document.querySelector(`[data-col-menu="${table.dataset.columnsKey}"]`);
        if (menu) {
            Array.from(table.tHead.rows[0].cells).forEach((th) => {
                if (!th.dataset.col) { return; }
                const id = 'col-' + table.dataset.columnsKey + '-' + th.dataset.col;
                const li = document.createElement('div'); li.className = 'form-check px-4 py-1';
                li.innerHTML = `<input class="form-check-input" type="checkbox" id="${id}" ${hidden.includes(th.dataset.col) ? '' : 'checked'}><label class="form-check-label small" for="${id}">${DGR.escape(th.textContent.trim())}</label>`;
                li.querySelector('input').addEventListener('change', (e) => {
                    hidden = e.target.checked ? hidden.filter((h) => h !== th.dataset.col) : [...hidden, th.dataset.col];
                    localStorage.setItem(key, JSON.stringify(hidden)); apply();
                });
                menu.appendChild(li);
            });
        }
    });

    // Auto-submit selects in filter bars
    document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form && el.form.submit()));

    // Bootstrap tooltips for KPI explanations
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));

    // Copy-to-clipboard helper
    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-copy]');
        if (!b) { return; }
        navigator.clipboard.writeText(b.dataset.copy).then(() => DGR.toast('Copied to clipboard', 'info'));
    });
    // Print buttons (no inline handlers under CSP)
    document.addEventListener('click', (e) => { if (e.target.closest('[data-print]')) { window.print(); } });
})();
