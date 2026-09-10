/* Satya Moulds — site behaviour
   Plain JavaScript; Bootstrap 5 bundle handles navbar collapse and dropdowns. */
(function () {
  'use strict';

  /* ------------------------------------------------------------------
     Site configuration
     ------------------------------------------------------------------ */
  var SM = {
    // Set this to a form-handling endpoint (your own script, Formspree,
    // Basin, etc.) that accepts multipart POST. Until it is set, the
    // enquiry form opens the visitor's email client with the enquiry
    // prefilled, addressed to the tool room mailbox.
    formEndpoint: '',
    enquiryEmail: 'toolroom@satyamoulds.com'
  };
  window.SM = SM;

  document.documentElement.classList.remove('no-js');

  /* ------------------------------------------------------------------
     Navbar: shadow on scroll, close mobile menu after tapping a link
     ------------------------------------------------------------------ */
  var nav = document.querySelector('.sm-nav');
  var toTop = document.querySelector('.to-top');
  function onScroll() {
    var y = window.scrollY || window.pageYOffset;
    if (nav) nav.classList.toggle('is-scrolled', y > 8);
    if (toTop) toTop.classList.toggle('is-visible', y > 600);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  if (toTop) {
    toTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  var collapse = document.getElementById('mainNav');
  if (collapse && window.bootstrap) {
    collapse.querySelectorAll('.nav-link:not(.dropdown-toggle), .dropdown-item, .nav-cta .btn').forEach(function (link) {
      link.addEventListener('click', function () {
        if (collapse.classList.contains('show')) {
          bootstrap.Collapse.getOrCreateInstance(collapse).hide();
        }
      });
    });
  }

  /* ------------------------------------------------------------------
     Reveal on scroll (subtle, respects reduced motion)
     ------------------------------------------------------------------ */
  var reveals = document.querySelectorAll('.reveal');
  if (reveals.length) {
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || !('IntersectionObserver' in window)) {
      reveals.forEach(function (el) { el.classList.add('is-visible'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target);
          }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
      reveals.forEach(function (el) { io.observe(el); });
    }
  }

  /* ------------------------------------------------------------------
     Portfolio filter
     Buttons carry data-filter="all|pet|hpdc|injection|rubber|fixtures"
     Items carry data-category with the same keys.
     A hash such as projects.html#hpdc pre-selects a filter.
     ------------------------------------------------------------------ */
  document.querySelectorAll('[data-portfolio]').forEach(function (root) {
    var buttons = root.querySelectorAll('.filter-btn');
    var grid = root.querySelector('.portfolio-grid');
    var items = root.querySelectorAll('.project-item');
    if (!buttons.length || !items.length) return;

    function apply(key) {
      var shown = 0;
      items.forEach(function (item) {
        var match = key === 'all' || item.getAttribute('data-category') === key;
        item.classList.toggle('is-hidden', !match);
        if (match) shown++;
      });
      buttons.forEach(function (b) {
        var on = b.getAttribute('data-filter') === key;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (grid) grid.classList.toggle('is-empty', shown === 0);
    }

    buttons.forEach(function (b) {
      b.addEventListener('click', function () {
        var key = b.getAttribute('data-filter');
        apply(key);
        if (root.hasAttribute('data-portfolio-hash')) {
          try { history.replaceState(null, '', key === 'all' ? location.pathname : '#' + key); } catch (e) {}
        }
      });
    });

    var initial = 'all';
    if (root.hasAttribute('data-portfolio-hash')) {
      var h = (location.hash || '').replace('#', '');
      if (h && root.querySelector('.filter-btn[data-filter="' + h + '"]')) initial = h;
    }
    apply(initial);
  });

  /* ------------------------------------------------------------------
     Enquiry form
     ------------------------------------------------------------------ */
  var form = document.getElementById('enquiryForm');
  if (form) {
    var status = form.querySelector('.form-status');
    var fileInput = form.querySelector('input[type="file"]');
    var MAX_MB = 20;

    function setStatus(kind, msg) {
      if (!status) return;
      status.className = 'form-status ' + (kind === 'ok' ? 'is-ok' : 'is-error');
      status.textContent = msg;
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (f && f.size > MAX_MB * 1024 * 1024) {
          fileInput.setCustomValidity('File is larger than ' + MAX_MB + ' MB.');
          fileInput.reportValidity();
        } else {
          fileInput.setCustomValidity('');
        }
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      form.classList.add('was-validated');
      if (!form.checkValidity()) {
        var firstInvalid = form.querySelector(':invalid');
        if (firstInvalid) firstInvalid.focus();
        return;
      }

      var data = new FormData(form);
      var btn = form.querySelector('button[type="submit"]');

      if (SM.formEndpoint) {
        if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Sending…'; }
        fetch(SM.formEndpoint, { method: 'POST', body: data, headers: { 'Accept': 'application/json' } })
          .then(function (r) { if (!r.ok) throw new Error('Request failed'); return r; })
          .then(function () {
            setStatus('ok', 'Thank you. Your enquiry has been sent to our tool room. We will get back to you shortly.');
            form.reset();
            form.classList.remove('was-validated');
          })
          .catch(function () {
            setStatus('error', 'The enquiry could not be sent. Please email ' + SM.enquiryEmail + ' directly or call +91-9650648852.');
          })
          .then(function () {
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
          });
        return;
      }

      // No endpoint configured: open the visitor's email client with the enquiry prefilled.
      var lines = [
        'Name: ' + (data.get('name') || ''),
        'Company: ' + (data.get('company') || ''),
        'Email: ' + (data.get('email') || ''),
        'Phone: ' + (data.get('phone') || ''),
        'Tooling type: ' + (data.get('tooling_type') || ''),
        '',
        'Requirement:',
        data.get('requirement') || ''
      ];
      var f = fileInput && fileInput.files && fileInput.files[0];
      if (f) lines.push('', 'Drawing / CAD file to attach: ' + f.name);
      var subject = 'Tooling enquiry — ' + (data.get('company') || data.get('name') || 'Website');
      var href = 'mailto:' + SM.enquiryEmail + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(lines.join('\n'));
      window.location.href = href;
      setStatus('ok', 'Your email client should open with the enquiry prefilled' + (f ? '. Please attach "' + f.name + '" before sending.' : '. Send it and our team will respond.'));
    });
  }

  /* ------------------------------------------------------------------
     Footer year
     ------------------------------------------------------------------ */
  document.querySelectorAll('[data-year]').forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });
})();
