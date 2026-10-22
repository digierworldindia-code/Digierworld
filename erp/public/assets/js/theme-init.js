/*
 * Light / Dark / System theme. Loaded in <head> before the page is painted,
 * so the chosen theme shows from the first frame (no flash).
 *
 * The choice comes from the signed-in user's profile (data-theme-pref on
 * <html>, so it follows them to every device), else from this browser's
 * last choice, else "system" (the operating system's light/dark setting).
 * Picking a theme in the top bar applies it at once, remembers it in this
 * browser and saves it to the profile. Printing always uses white paper.
 */
(function () {
    'use strict';
    var root = document.documentElement;
    var KEY = 'dgr-theme';
    var CHOICES = ['light', 'dark', 'system'];
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;

    function stored() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }
    function remember(pref) {
        try { localStorage.setItem(KEY, pref); } catch (e) { /* private mode: profile still saves it */ }
    }
    function valid(pref) {
        return CHOICES.indexOf(pref) !== -1 ? pref : 'system';
    }

    var pref = valid(root.getAttribute('data-theme-pref') || stored() || 'system');

    function resolved() {
        return pref === 'system' ? (media && media.matches ? 'light' : 'dark') : pref;
    }

    function apply() {
        var mode = resolved();
        var changed = root.getAttribute('data-bs-theme') !== mode;
        root.setAttribute('data-bs-theme', mode);
        root.setAttribute('data-theme-pref', pref);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', getComputedStyle(root).getPropertyValue('--dgr-bg').trim() || meta.getAttribute('content')); } // browser bar = page background
        var scheme = document.querySelector('meta[name="color-scheme"]');
        if (scheme) { scheme.setAttribute('content', mode); }
        document.querySelectorAll('[data-theme-choice]').forEach(function (el) {
            var on = el.getAttribute('data-theme-choice') === pref;
            el.classList.toggle('active', on);
            el.setAttribute('aria-checked', on ? 'true' : 'false');
        });
        document.querySelectorAll('[data-theme-icon]').forEach(function (el) {
            el.className = 'bi ' + (pref === 'system' ? 'bi-circle-half' : (pref === 'light' ? 'bi-sun' : 'bi-moon-stars'));
        });
        if (changed && document.body) {
            document.dispatchEvent(new CustomEvent('dgr:themechange', { detail: { theme: mode, pref: pref } }));
        }
    }

    function save(choice) {
        var url = document.querySelector('meta[name="theme-save-url"]');
        var header = document.querySelector('meta[name="csrf-header"]');
        var token = document.querySelector('meta[name="csrf-token"]');
        if (!url || !header || !token || !window.fetch) { return; }
        var headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
        headers[header.content] = token.content;
        fetch(url.content, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify({ theme: choice }) })
            .catch(function () { /* offline: the choice is still kept in this browser */ });
    }

    window.DGRTheme = {
        pref: function () { return pref; },
        current: resolved,
        set: function (choice) {
            pref = valid(choice);
            remember(pref);
            apply();
            save(pref);
        },
    };

    apply();

    // Desktop menu hidden or shown (remembered in this browser; applied before paint so the page does not jump)
    var SIDEBAR = 'dgr-sidebar';
    try { if (localStorage.getItem(SIDEBAR) === 'collapsed') { root.classList.add('sidebar-collapsed'); } } catch (e) { /* ignore */ }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-sidebar-collapse]') : null;
        if (!btn) { return; }
        var collapsed = root.classList.toggle('sidebar-collapsed');
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem(SIDEBAR, collapsed ? 'collapsed' : 'open'); } catch (err) { /* ignore */ }
    });
    document.addEventListener('DOMContentLoaded', function () {
        var collapsed = root.classList.contains('sidebar-collapsed');
        document.querySelectorAll('[data-sidebar-collapse]').forEach(function (b) { b.setAttribute('aria-expanded', collapsed ? 'false' : 'true'); });
    });

    if (media) {
        var onSystemChange = function () { if (pref === 'system') { apply(); } };
        if (media.addEventListener) { media.addEventListener('change', onSystemChange); } else if (media.addListener) { media.addListener(onSystemChange); }
    }
    // the switcher is in the page body, which is not parsed yet: listen on the document
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-theme-choice]') : null;
        if (btn) {
            e.preventDefault();
            window.DGRTheme.set(btn.getAttribute('data-theme-choice'));
        }
    });
    document.addEventListener('DOMContentLoaded', apply);
})();
