/*
 * Console behaviour. Everything works without it; it only adds confirmation
 * on irreversible actions and a few conveniences. No inline handlers: the
 * Content-Security-Policy allows scripts from this origin only.
 */
(function () {
  'use strict';

  // <form data-confirm="Cancel this dispatch?"> asks before submitting.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    // Stop a double tap from submitting twice.
    var button = form.querySelector('button[type="submit"]:not([data-keep-enabled])');
    if (button && !event.defaultPrevented) {
      window.setTimeout(function () { button.disabled = true; }, 0);
    }
  });

  // <select data-autosubmit> submits its form on change (list filters).
  document.addEventListener('change', function (event) {
    var el = event.target;
    if (el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) el.form.submit();
  });

  // <button data-print> prints the page (labels).
  document.addEventListener('click', function (event) {
    var el = event.target.closest && event.target.closest('[data-print]');
    if (el) { event.preventDefault(); window.print(); }
  });

  // <input data-check-all="name"> toggles every checkbox named name.
  document.addEventListener('change', function (event) {
    var el = event.target;
    var name = el.getAttribute && el.getAttribute('data-check-all');
    if (!name) return;
    document.querySelectorAll('input[type="checkbox"][name="' + name + '"]').forEach(function (box) {
      box.checked = el.checked;
    });
  });
})();
