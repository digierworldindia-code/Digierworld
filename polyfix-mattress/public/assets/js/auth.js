/*
 * Show/hide for a password field.
 *
 * Progressive: the field is a normal password input until this runs, so if the
 * script is blocked or slow the form still works.
 *
 * Markup, inside an .input-group:
 *   <input id="password" type="password" …>
 *   <button type="button" class="btn btn-outline-secondary" data-password-toggle="password">…</button>
 */
(function () {
    'use strict';

    var SHOW = 'Show password';
    var HIDE = 'Hide password';

    function wire(button) {
        var field = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!field) {
            return;
        }

        var icon = button.querySelector('i');

        function paint(revealed) {
            button.setAttribute('aria-pressed', revealed ? 'true' : 'false');
            button.setAttribute('aria-label', revealed ? HIDE : SHOW);
            button.setAttribute('title', revealed ? HIDE : SHOW);
            if (icon) {
                icon.className = revealed ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
        }

        paint(false);
        button.hidden = false;

        button.addEventListener('click', function () {
            var revealed = field.type === 'text';
            field.type = revealed ? 'password' : 'text';
            paint(!revealed);

            /*
             * Changing the type moves the caret to the start in several
             * browsers, so put it back where it was. Keeping focus on the
             * field also stops a phone keyboard closing and reopening.
             */
            var at = field.value.length;
            field.focus();
            try {
                field.setSelectionRange(at, at);
            } catch (e) {
                /* not every input type allows this; harmless */
            }
        });
    }

    function init() {
        var buttons = document.querySelectorAll('[data-password-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            wire(buttons[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
