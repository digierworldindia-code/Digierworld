/* ==========================================================================
   DEV ENGINEERING — site behaviour
   Vanilla JS only. No dependencies beyond Bootstrap's own bundle.
   ========================================================================== */
(function () {
  'use strict';

  /* ---- 1. Sticky header shadow ---------------------------------------- */
  var header = document.querySelector('.de-header');
  if (header) {
    var onScrollHeader = function () {
      header.classList.toggle('is-stuck', window.scrollY > 8);
    };
    onScrollHeader();
    window.addEventListener('scroll', onScrollHeader, { passive: true });
  }

  /* ---- 2. Close the mobile menu after a link tap ----------------------- */
  var collapseEl = document.getElementById('deNav');
  if (collapseEl && window.bootstrap) {
    collapseEl.addEventListener('click', function (e) {
      var link = e.target.closest('a');
      if (!link || window.innerWidth >= 992) return;
      /* the Products toggle opens a submenu — it must not collapse the menu */
      if (link.classList.contains('dropdown-toggle')) return;
      var inst = window.bootstrap.Collapse.getInstance(collapseEl);
      if (inst) inst.hide();
    });
  }

  /* ---- 3. Scroll reveal ------------------------------------------------ */
  var revealTargets = document.querySelectorAll('.de-reveal');
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (revealTargets.length) {
    if (reduceMotion || !('IntersectionObserver' in window)) {
      revealTargets.forEach(function (el) { el.classList.add('is-visible'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          var el = entry.target;
          var delay = parseInt(el.getAttribute('data-de-delay') || '0', 10);
          window.setTimeout(function () { el.classList.add('is-visible'); }, delay);
          io.unobserve(el);
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
      revealTargets.forEach(function (el) { io.observe(el); });
    }
  }

  /* ---- 4. Back to top -------------------------------------------------- */
  var topBtn = document.querySelector('.de-top');
  if (topBtn) {
    var onScrollTop = function () {
      topBtn.classList.toggle('is-shown', window.scrollY > 520);
    };
    onScrollTop();
    window.addEventListener('scroll', onScrollTop, { passive: true });
    topBtn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
  }

  /* ---- 5. Prefill the enquiry form from ?product= ----------------------- */
  var productField = document.getElementById('deProduct');
  if (productField) {
    var requested = new URLSearchParams(window.location.search).get('product');
    if (requested) {
      var match = Array.prototype.find.call(productField.options, function (opt) {
        return opt.value.toLowerCase() === requested.toLowerCase();
      });
      if (match) {
        productField.value = match.value;
      } else {
        var other = document.getElementById('deMessage');
        if (other && !other.value) {
          other.value = 'Enquiry regarding: ' + requested.replace(/[<>]/g, '').slice(0, 120);
        }
      }
    }
  }

  /* ---- 6. Enquiry form validation -------------------------------------- */
  var form = document.getElementById('deEnquiryForm');
  if (form) {
    var status = document.getElementById('deFormStatus');

    var showError = function (field, show) {
      field.classList.toggle('is-invalid', show);
      field.setAttribute('aria-invalid', show ? 'true' : 'false');
      var msg = document.querySelector('[data-de-error-for="' + field.id + '"]');
      if (msg) msg.classList.toggle('is-shown', show);
    };

    var isValid = function (field) {
      var value = (field.value || '').trim();
      if (field.hasAttribute('required') && !value) return false;
      if (field.type === 'email' && value) return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
      if (field.type === 'tel' && value) {
        var digits = value.replace(/\D/g, '');
        return digits.length >= 10 && digits.length <= 15;
      }
      return true;
    };

    var fields = Array.prototype.slice.call(
      form.querySelectorAll('input[required], select[required], textarea[required], input[type="email"], input[type="tel"]')
    );

    fields.forEach(function (field) {
      field.addEventListener('blur', function () { showError(field, !isValid(field)); });
      field.addEventListener('input', function () {
        if (field.classList.contains('is-invalid')) showError(field, !isValid(field));
      });
    });

    form.addEventListener('submit', function (e) {
      var firstBad = null;
      fields.forEach(function (field) {
        var ok = isValid(field);
        showError(field, !ok);
        if (!ok && !firstBad) firstBad = field;
      });

      if (firstBad) {
        e.preventDefault();
        firstBad.focus();
        if (status) {
          status.textContent = 'Please complete the highlighted fields so we can get back to you.';
          status.classList.add('is-shown', 'is-error');
        }
        return;
      }

      /* No mail handler is wired up yet. Until form.action points at a real
         endpoint, stop the submit and tell the visitor how to reach us. */
      if (!form.getAttribute('action')) {
        e.preventDefault();
        if (status) {
          status.classList.remove('is-error');
          status.textContent = 'This form is not connected to a mail handler yet. Please call or email us using the details on this page.';
          status.classList.add('is-shown');
        }
      }
    });
  }

  /* ---- 7. Current year in the footer ------------------------------------ */
  Array.prototype.forEach.call(document.querySelectorAll('[data-de-year]'), function (el) {
    el.textContent = String(new Date().getFullYear());
  });
})();
