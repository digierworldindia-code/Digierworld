/* Document view: open the finalize dialog automatically after "Save & review". */
(function () {
    'use strict';
    if (new URLSearchParams(window.location.search).get('finalize') === '1') {
        const m = document.getElementById('finalizeModal');
        if (m) { bootstrap.Modal.getOrCreateInstance(m).show(); }
    }
})();
