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

  /*
   * <form data-sum-form> keeps <span data-sum-total> equal to the sum of its
   * [data-sum-input] fields. Used where a total must agree with the parts it
   * is made of — the quantities on a new batch — so the operator sees the
   * number the button is about to act on rather than typing it twice.
   */
  function retotal(form) {
    var total = 0;
    form.querySelectorAll('[data-sum-input]').forEach(function (input) {
      var value = parseInt(input.value, 10);
      if (!isNaN(value) && value > 0) total += value;
    });
    form.querySelectorAll('[data-sum-total]').forEach(function (out) {
      out.textContent = String(total);
    });
  }

  document.addEventListener('input', function (event) {
    var input = event.target;
    if (input.hasAttribute && input.hasAttribute('data-sum-input')) {
      var form = input.closest('[data-sum-form]');
      if (form) retotal(form);
    }
  });

  // Totals shown on load too: a form returned with input still has numbers.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-sum-form]').forEach(retotal);
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
