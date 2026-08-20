/* ==========================================================================
   MITHAAS — site behaviour
   Vanilla JS on top of Bootstrap 5.3. No build step.
   ========================================================================== */
(function () {
  "use strict";

  var CFG = window.MITHAAS || {};
  var REDUCED = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------------------------------------------------------------------
     Small helpers
     --------------------------------------------------------------------- */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function has(v) { return v !== null && v !== undefined && v !== ""; }

  /* Public helpers used by page markup */
  var M = window.MithaasUI = {};

  M.telHref = function () { return has(CFG.phone) ? "tel:" + CFG.phone : null; };
  M.waHref = function (text) {
    if (!has(CFG.whatsapp)) return null;
    return "https://wa.me/" + CFG.whatsapp + (text ? "?text=" + encodeURIComponent(text) : "");
  };
  M.mailHref = function (subject) {
    if (!has(CFG.email)) return null;
    return "mailto:" + CFG.email + (subject ? "?subject=" + encodeURIComponent(subject) : "");
  };
  M.addressLines = function () {
    var a = CFG.address || {};
    var l = [a.line1, a.line2, a.landmark, [a.locality, a.region].filter(has).join(", "), a.postalCode];
    return l.filter(has);
  };
  M.addressOneLine = function () { return M.addressLines().join(", "); };

  /* ---------------------------------------------------------------------
     1. Navigation — solid state, current page, action bar
     --------------------------------------------------------------------- */
  function initNav() {
    var nav = $(".nav-mithaas");
    if (!nav) return;

    var hero = $(".hero");
    var bar = $(".actionbar");
    var last = -1;

    function onScroll() {
      var y = window.scrollY || window.pageYOffset;
      var solid = y > 24;
      if (solid !== (nav.dataset.solid === "true")) nav.dataset.solid = solid ? "true" : "false";

      if (hero) {
        var overDark = y < hero.offsetHeight - 120;
        nav.dataset.overDark = overDark ? "true" : "false";
      }
      if (bar) bar.dataset.show = y > 380 ? "true" : "false";
      last = y;
    }

    nav.dataset.solid = hero ? "false" : "true";
    if (!hero) nav.dataset.overDark = "false";

    var ticking = false;
    window.addEventListener("scroll", function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () { onScroll(); ticking = false; });
    }, { passive: true });
    onScroll();

    /* Mark the current page in every nav list */
    var here = location.pathname.split("/").pop() || "index.html";
    $$("[data-navlink]").forEach(function (a) {
      if (a.getAttribute("data-navlink") === here) a.setAttribute("aria-current", "page");
    });
  }

  /* ---------------------------------------------------------------------
     2. Scroll reveal
     --------------------------------------------------------------------- */
  function initReveal() {
    var nodes = $$("[data-reveal]");
    if (!nodes.length) return;

    if (REDUCED || !("IntersectionObserver" in window)) {
      nodes.forEach(function (n) { n.classList.add("is-in"); });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add("is-in");
        io.unobserve(e.target);
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });

    nodes.forEach(function (n) {
      /* Stagger siblings that opt in */
      var group = n.closest("[data-stagger]");
      if (group && !n.style.getPropertyValue("--delay")) {
        var sibs = $$("[data-reveal]", group);
        var i = sibs.indexOf(n);
        if (i > 0) n.style.setProperty("--delay", Math.min(i, 6) * 90 + "ms");
      }
      io.observe(n);
    });
  }

  /* ---------------------------------------------------------------------
     3. Photography fallback
     Real photographs live in assets/img/. Until they are dropped in, an
     image that fails to load is replaced by a branded plate carrying the
     dish name — never a broken image icon.
     --------------------------------------------------------------------- */
  function plateFor(img) {
    var fig = img.closest(".fig") || img.parentNode;
    if (!fig || $(".plate", fig)) { img.remove(); return; }
    var name = img.getAttribute("data-label") || img.getAttribute("alt") || "Mithaas";
    var dark = fig.hasAttribute("data-plate-dark");
    var el = document.createElement("div");
    el.className = "plate" + (dark ? " plate--wine" : "");
    el.setAttribute("aria-hidden", "true");
    el.innerHTML =
      '<div class="plate__inner">' +
        '<svg class="plate__mark" viewBox="0 0 64 24" fill="none" aria-hidden="true">' +
          '<path d="M2 12h18M44 12h18" stroke="currentColor" stroke-width="1"/>' +
          '<path d="M32 4c3.2 3 4.8 5.6 4.8 8S35.2 17 32 20c-3.2-3-4.8-5.6-4.8-8S28.8 7 32 4z" ' +
            'stroke="currentColor" stroke-width="1"/>' +
          '<circle cx="23.5" cy="12" r="1.4" fill="currentColor"/>' +
          '<circle cx="40.5" cy="12" r="1.4" fill="currentColor"/>' +
        '</svg>' +
        '<div class="plate__name">' + esc(name) + '</div>' +
        '<div class="plate__note">Photography coming soon</div>' +
      '</div>';
    fig.appendChild(el);
    img.remove();
  }

  /* ---------------------------------------------------------------------
     Missing-logo notice
     The official Mithaas logo is used as-is and is NEVER recreated,
     redrawn or substituted with type. If the file has not been added yet,
     we show an unmistakable placeholder naming the file to drop in —
     deliberately NOT a stand-in logo.
     --------------------------------------------------------------------- */
  function initLogo() {
    $$("img[data-logo]").forEach(function (img) {
      function swap() {
        if (!img.parentNode) return;
        var slot = img.getAttribute("data-logo") || "nav";
        var box = document.createElement("span");
        box.className = "logo-missing logo-missing--" + slot;
        box.setAttribute("role", "img");
        box.setAttribute("aria-label", img.getAttribute("alt") || "Mithaas");
        box.innerHTML =
          '<b>Logo file missing</b>' +
          '<small>assets/img/brand/mithaas-logo.png</small>';
        img.parentNode.replaceChild(box, img);
      }
      if (img.complete && img.naturalWidth === 0) { swap(); return; }
      img.addEventListener("error", swap, { once: true });
    });
  }

  function initPhotos() {
    $$("img[data-photo]").forEach(function (img) {
      if (img.complete && img.naturalWidth === 0) { plateFor(img); return; }
      img.addEventListener("error", function () { plateFor(img); }, { once: true });
    });
  }

  /* ---------------------------------------------------------------------
     4. Business data injection
     Every value comes from config.js. Nothing is invented: a missing value
     renders an honest "to be confirmed" state and disables the control.
     --------------------------------------------------------------------- */
  var DAYS = [
    ["sun", "Sunday"], ["mon", "Monday"], ["tue", "Tuesday"], ["wed", "Wednesday"],
    ["thu", "Thursday"], ["fri", "Friday"], ["sat", "Saturday"]
  ];

  function fmtTime(t) {
    if (!has(t)) return null;
    var p = String(t).split(":");
    var h = parseInt(p[0], 10);
    var m = p[1] || "00";
    var ap = h >= 12 ? "pm" : "am";
    var h12 = h % 12 === 0 ? 12 : h % 12;
    return h12 + (m === "00" ? "" : ":" + m) + " " + ap;
  }

  function disable(el, label) {
    el.setAttribute("aria-disabled", "true");
    el.removeAttribute("href");
    el.setAttribute("tabindex", "-1");
    if (label) el.setAttribute("title", label);
  }

  function fillLinks() {
    $$("[data-action]").forEach(function (el) {
      var kind = el.getAttribute("data-action");
      var msg = el.getAttribute("data-message") || "";
      var href = null;
      if (kind === "call") href = M.telHref();
      else if (kind === "whatsapp") href = M.waHref(msg || "Hello Mithaas, I would like to know more.");
      else if (kind === "email") href = M.mailHref(msg || "Enquiry from the Mithaas website");
      else if (kind === "directions") href = has(CFG.mapLink) ? CFG.mapLink : null;
      else if (kind === "instagram") href = (CFG.social || {}).instagram;
      else if (kind === "facebook") href = (CFG.social || {}).facebook;
      else if (kind === "google") href = (CFG.social || {}).google;

      if (has(href)) {
        el.setAttribute("href", href);
        if (kind !== "call" && kind !== "email") {
          el.setAttribute("target", "_blank");
          el.setAttribute("rel", "noopener");
        }
      } else {
        disable(el, "Details coming soon");
      }
    });

    /* Text slots */
    $$("[data-field]").forEach(function (el) {
      var key = el.getAttribute("data-field");
      var val = null;
      if (key === "phone") val = CFG.phoneDisplay || CFG.phone;
      else if (key === "email") val = CFG.email;
      else if (key === "address") val = M.addressLines().join("\n");
      else if (key === "addressInline") val = M.addressOneLine();
      else if (key === "landmark") val = (CFG.address || {}).landmark;
      else if (key === "instagramHandle") val = (CFG.social || {}).instagramHandle;
      else if (key === "hoursNote") val = CFG.hoursNote;

      if (has(val)) {
        el.textContent = val;
        el.classList.remove("pending");
      } else {
        el.textContent = el.getAttribute("data-pending") || "To be confirmed";
        el.classList.add("pending");
      }
    });
  }

  function fillHours() {
    var list = $("[data-hours]");
    if (!list) return;
    var hours = CFG.hours || {};
    var any = DAYS.some(function (d) { return has(hours[d[0]]); });

    if (!any) {
      list.innerHTML = '<li><span>Opening hours</span><span class="pending">To be confirmed</span></li>';
      return;
    }
    var today = new Date().getDay();
    list.innerHTML = DAYS.map(function (d, i) {
      var h = hours[d[0]];
      var txt = h && has(h.open) && has(h.close)
        ? fmtTime(h.open) + " – " + fmtTime(h.close)
        : "Closed";
      return '<li' + (i === today ? ' data-today="true"' : '') + '>' +
        '<span>' + d[1] + '</span><span>' + txt + '</span></li>';
    }).join("");
  }

  function fillMap() {
    var frame = $("[data-map]");
    if (!frame) return;
    if (has(CFG.mapEmbed)) {
      var f = document.createElement("iframe");
      f.src = CFG.mapEmbed;
      f.loading = "lazy";
      f.referrerPolicy = "no-referrer-when-downgrade";
      f.title = "Mithaas on Google Maps";
      f.setAttribute("allowfullscreen", "");
      frame.innerHTML = "";
      frame.appendChild(f);
    } else {
      frame.innerHTML =
        '<div class="plate"><div class="plate__inner">' +
          '<div class="plate__name">Find us on the map</div>' +
          '<div class="plate__note">Map link coming soon</div>' +
        '</div></div>';
    }
  }

  function fillTestimonials() {
    var wrap = $("[data-testimonials]");
    if (!wrap) return;
    var list = Array.isArray(CFG.testimonials) ? CFG.testimonials : [];

    if (!list.length) {
      wrap.innerHTML = [0, 1, 2].map(function () {
        return '<div class="col-lg-4" data-reveal>' +
          '<figure class="quote quote--pending">' +
            '<div class="quote__stars" aria-hidden="true">★★★★★</div>' +
            '<blockquote class="quote__text">Real customer reviews will appear here once they are collected.</blockquote>' +
            '<figcaption class="quote__by">Awaiting customer review</figcaption>' +
          '</figure></div>';
      }).join("");
      return;
    }

    wrap.innerHTML = list.map(function (t) {
      var r = Math.max(1, Math.min(5, t.rating || 5));
      return '<div class="col-lg-4" data-reveal>' +
        '<figure class="quote">' +
          '<div class="quote__stars" aria-label="' + r + ' out of 5">' +
            new Array(r + 1).join("★") + '</div>' +
          '<blockquote class="quote__text">' + esc(t.text) + '</blockquote>' +
          '<figcaption class="quote__by">' + esc(t.author) +
            (t.source ? ' &middot; ' + esc(t.source) : '') + '</figcaption>' +
        '</figure></div>';
    }).join("");
  }

  /* ---------------------------------------------------------------------
     5. Structured data (LocalBusiness / Restaurant)
     Only emits properties that are actually known.
     --------------------------------------------------------------------- */
  function initSchema() {
    if (!$("[data-schema-localbusiness]")) return;
    var a = CFG.address || {};
    var d = {
      "@context": "https://schema.org",
      "@type": ["Restaurant", "Bakery", "Store"],
      name: CFG.legalName || CFG.name,
      alternateName: CFG.name,
      description: "Mithaas — traditional and premium Indian sweets, a daily bakery, and a vegetarian restaurant serving North Indian, South Indian, snacks, street food and Chinese.",
      servesCuisine: ["Indian", "North Indian", "South Indian", "Chinese", "Bakery", "Desserts"],
      url: CFG.origin
    };
    if (has(CFG.phone)) d.telephone = CFG.phone;
    if (has(CFG.email)) d.email = CFG.email;
    if (M.addressLines().length) {
      d.address = { "@type": "PostalAddress", addressCountry: a.country || "IN" };
      if (has(a.line1) || has(a.line2)) d.address.streetAddress = [a.line1, a.line2].filter(has).join(", ");
      if (has(a.locality)) d.address.addressLocality = a.locality;
      if (has(a.region)) d.address.addressRegion = a.region;
      if (has(a.postalCode)) d.address.postalCode = a.postalCode;
    }
    if (has((CFG.geo || {}).lat) && has((CFG.geo || {}).lng)) {
      d.geo = { "@type": "GeoCoordinates", latitude: CFG.geo.lat, longitude: CFG.geo.lng };
    }
    if (has(CFG.mapLink)) d.hasMap = CFG.mapLink;

    var spec = DAYS.map(function (day) {
      var h = (CFG.hours || {})[day[0]];
      if (!h || !has(h.open) || !has(h.close)) return null;
      return { "@type": "OpeningHoursSpecification", dayOfWeek: day[1], opens: h.open, closes: h.close };
    }).filter(Boolean);
    if (spec.length) d.openingHoursSpecification = spec;

    var same = [(CFG.social || {}).instagram, (CFG.social || {}).facebook, (CFG.social || {}).google].filter(has);
    if (same.length) d.sameAs = same;
    if (window.MITHAAS_MENU) d.hasMenu = CFG.origin + "/menu.html";

    var s = document.createElement("script");
    s.type = "application/ld+json";
    s.textContent = JSON.stringify(d);
    document.head.appendChild(s);
  }

  /* ---------------------------------------------------------------------
     6. Digital menu
     --------------------------------------------------------------------- */
  function priceHTML(item) {
    if (!CFG.showPrices || item.p === null || item.p === undefined) {
      return '<span class="menu-item__price--pending">Price on request</span>';
    }
    var cur = CFG.currency || "₹";
    if (typeof item.p === "object") {
      var parts = Object.keys(item.p).map(function (k) { return k + " " + cur + item.p[k]; });
      return '<span class="menu-item__price">' + esc(parts.join("  ·  ")) + '</span>';
    }
    return '<span class="menu-item__price">' + cur + esc(item.p) + '</span>';
  }

  function tagHTML(t) {
    if (!t) return "";
    var label = t === "signature" ? "Signature" : t === "seasonal" ? "In season" : "New";
    return '<span class="tag' + (t === "signature" ? " tag--signature" : "") + '">' + label + "</span>";
  }

  function itemHTML(item) {
    return '<article class="menu-item">' +
      '<div>' +
        '<h3 class="menu-item__name">' + esc(item.n) + tagHTML(item.t) + '</h3>' +
        (item.d ? '<p class="menu-item__desc">' + esc(item.d) + '</p>' : '') +
      '</div>' +
      '<div class="menu-item__side">' +
        (item.v && item.v.length
          ? '<span class="menu-item__variants">' + esc(item.v.join("  /  ")) + '</span>' : '') +
        priceHTML(item) +
      '</div>' +
    '</article>';
  }

  function initMenu() {
    var root = $("[data-menu-root]");
    if (!root || !window.MITHAAS_MENU) return;

    var DATA = window.MITHAAS_MENU;
    var chipWrap = $("[data-menu-chips]");
    var input = $("[data-menu-search]");
    var searchBox = input ? input.closest(".search") : null;
    var clearBtn = $("[data-menu-clear]");
    var countEl = $("[data-menu-count]");
    var state = { cat: "all", q: "" };

    /* Category chips */
    if (chipWrap) {
      chipWrap.innerHTML =
        '<button type="button" class="chip" data-cat="all" aria-pressed="true">All</button>' +
        DATA.map(function (c) {
          return '<button type="button" class="chip" data-cat="' + c.id + '" aria-pressed="false">' +
            esc(c.short || c.name) + '</button>';
        }).join("");
    }

    function matches(item, q) {
      if (!q) return true;
      var hay = (item.n + " " + (item.d || "") + " " + (item.v || []).join(" ")).toLowerCase();
      return hay.indexOf(q) !== -1;
    }

    function render() {
      var q = state.q.trim().toLowerCase();
      var total = 0;
      var html = DATA.map(function (cat) {
        if (state.cat !== "all" && state.cat !== cat.id) return "";
        var items = cat.items.filter(function (i) { return matches(i, q); });
        if (!items.length) return "";
        total += items.length;
        return '<section class="menu-group" id="' + cat.id + '" aria-labelledby="h-' + cat.id + '">' +
          '<header class="menu-group__head">' +
            '<h2 id="h-' + cat.id + '">' + esc(cat.name) + '</h2>' +
            (cat.note ? '<span class="menu-group__note">' + esc(cat.note) + '</span>' : '') +
          '</header>' +
          (cat.intro && !q ? '<p class="menu-group__intro">' + esc(cat.intro) + '</p>' : '') +
          '<div class="menu-list">' + items.map(itemHTML).join("") + '</div>' +
        '</section>';
      }).join("");

      if (!total) {
        html = '<div class="menu-empty">' +
          '<h3>Nothing matched &ldquo;' + esc(state.q) + '&rdquo;</h3>' +
          '<p>Try a shorter word — or ask us at the counter. If we can make it, we usually will.</p>' +
          '<button type="button" class="btn btn-ghost mt-4" data-menu-reset>Show the full menu</button>' +
        '</div>';
      }
      root.innerHTML = html;
      if (countEl) {
        countEl.textContent = total ? total + (total === 1 ? " item" : " items") : "";
      }
    }

    if (chipWrap) {
      chipWrap.addEventListener("click", function (e) {
        var b = e.target.closest(".chip");
        if (!b) return;
        state.cat = b.getAttribute("data-cat");
        $$(".chip", chipWrap).forEach(function (c) {
          c.setAttribute("aria-pressed", c === b ? "true" : "false");
        });
        b.scrollIntoView({ block: "nearest", inline: "center", behavior: REDUCED ? "auto" : "smooth" });
        render();
      });
    }

    if (input) {
      var t;
      input.addEventListener("input", function () {
        clearTimeout(t);
        if (searchBox) searchBox.dataset.filled = input.value ? "true" : "false";
        t = setTimeout(function () { state.q = input.value; render(); }, 140);
      });
      input.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && input.value) { input.value = ""; state.q = ""; if (searchBox) searchBox.dataset.filled = "false"; render(); }
      });
    }
    if (clearBtn && input) {
      clearBtn.addEventListener("click", function () {
        input.value = ""; state.q = "";
        if (searchBox) searchBox.dataset.filled = "false";
        input.focus(); render();
      });
    }
    root.addEventListener("click", function (e) {
      if (!e.target.closest("[data-menu-reset]")) return;
      state.q = ""; state.cat = "all";
      if (input) { input.value = ""; if (searchBox) searchBox.dataset.filled = "false"; }
      if (chipWrap) $$(".chip", chipWrap).forEach(function (c) {
        c.setAttribute("aria-pressed", c.getAttribute("data-cat") === "all" ? "true" : "false");
      });
      render();
    });

    render();

    /* Deep links: menu.html#north-indian
       Also handles hashchange, because following a #anchor while already on
       this page is a same-document navigation — nothing would re-render. */
    function applyHash() {
      var hash = (location.hash || "").replace("#", "");
      if (!hash) return;
      var known = DATA.some(function (c) { return c.id === hash; });
      if (!known) return;

      if (state.cat !== hash || state.q) {
        state.cat = hash;
        state.q = "";
        if (input) { input.value = ""; if (searchBox) searchBox.dataset.filled = "false"; }
        if (chipWrap) {
          $$(".chip", chipWrap).forEach(function (c) {
            var on = c.getAttribute("data-cat") === hash;
            c.setAttribute("aria-pressed", on ? "true" : "false");
            if (on) c.scrollIntoView({ block: "nearest", inline: "center", behavior: REDUCED ? "auto" : "smooth" });
          });
        }
        render();
      }
      setTimeout(function () {
        var el = document.getElementById(hash);
        if (el) el.scrollIntoView({ behavior: REDUCED ? "auto" : "smooth", block: "start" });
      }, 60);
    }

    applyHash();
    window.addEventListener("hashchange", applyHash);
  }

  /* ---------------------------------------------------------------------
     7. Gallery lightbox (Bootstrap modal)
     --------------------------------------------------------------------- */
  function initGallery() {
    var grid = $("[data-gallery]");
    var modalEl = $("#lightbox");
    if (!grid || !modalEl || !window.bootstrap) return;

    var modal = new bootstrap.Modal(modalEl);
    var stage = $("[data-lb-stage]", modalEl);
    var cap = $("[data-lb-cap]", modalEl);
    var items = $$("[data-lb]", grid);
    var idx = 0;

    function visible() { return items.filter(function (i) { return !i.hidden; }); }

    function show(i) {
      var pool = visible();
      if (!pool.length) return;
      idx = (i + pool.length) % pool.length;
      var btn = pool[idx];
      var src = btn.getAttribute("data-lb-src");
      var label = btn.getAttribute("data-lb-cap") || "";
      stage.innerHTML = '<div class="fig">' +
        '<img src="' + esc(src) + '" alt="' + esc(label) + '" data-photo data-label="' + esc(label) + '">' +
      '</div>';
      cap.textContent = label;
      initPhotos();
    }

    grid.addEventListener("click", function (e) {
      var b = e.target.closest("[data-lb]");
      if (!b) return;
      show(visible().indexOf(b));
      modal.show();
    });

    $$("[data-lb-nav]", modalEl).forEach(function (b) {
      b.addEventListener("click", function () {
        show(idx + (b.getAttribute("data-lb-nav") === "next" ? 1 : -1));
      });
    });

    document.addEventListener("keydown", function (e) {
      if (!modalEl.classList.contains("show")) return;
      if (e.key === "ArrowRight") show(idx + 1);
      if (e.key === "ArrowLeft") show(idx - 1);
    });

    /* Filter chips */
    var filters = $("[data-gallery-filters]");
    if (filters) {
      filters.addEventListener("click", function (e) {
        var b = e.target.closest(".chip");
        if (!b) return;
        var f = b.getAttribute("data-filter");
        $$(".chip", filters).forEach(function (c) {
          c.setAttribute("aria-pressed", c === b ? "true" : "false");
        });
        items.forEach(function (it) {
          it.hidden = !(f === "all" || it.getAttribute("data-cat") === f);
        });
      });
    }
  }

  /* ---------------------------------------------------------------------
     8. Enquiry form
     --------------------------------------------------------------------- */
  function initForm() {
    var form = $("[data-enquiry]");
    if (!form) return;
    var status = $("[data-form-status]", form);

    function setErr(field, msg) {
      var box = $('[data-err="' + field.name + '"]', form);
      if (box) box.textContent = msg || "";
      field.setAttribute("aria-invalid", msg ? "true" : "false");
      return !msg;
    }

    function validate() {
      var ok = true;
      var name = form.elements.name;
      var phone = form.elements.phone;
      var email = form.elements.email;
      var message = form.elements.message;

      ok = setErr(name, name.value.trim().length < 2 ? "Please tell us your name." : "") && ok;
      ok = setErr(phone, !/^[0-9+\-\s()]{8,18}$/.test(phone.value.trim()) ? "Please enter a phone number we can reach you on." : "") && ok;
      ok = setErr(email, email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim()) ? "That email address doesn't look right." : "") && ok;
      ok = setErr(message, message.value.trim().length < 5 ? "A line or two about what you need is enough." : "") && ok;
      return ok;
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (!validate()) {
        var bad = $('[aria-invalid="true"]', form);
        if (bad) bad.focus();
        return;
      }

      var d = {
        name: form.elements.name.value.trim(),
        phone: form.elements.phone.value.trim(),
        email: form.elements.email.value.trim(),
        message: form.elements.message.value.trim()
      };
      var text = "Enquiry from the Mithaas website\n\nName: " + d.name +
        "\nPhone: " + d.phone + (d.email ? "\nEmail: " + d.email : "") +
        "\n\n" + d.message;

      var mode = CFG.formMode;
      var opened = false;

      if (mode === "endpoint" && has(CFG.formEndpoint)) {
        var btn = $('button[type="submit"]', form);
        if (btn) { btn.setAttribute("aria-disabled", "true"); btn.textContent = "Sending…"; }
        fetch(CFG.formEndpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(d)
        }).then(function (r) {
          if (!r.ok) throw new Error("bad status");
          form.reset();
          say("Thank you — we have your message and will get back to you shortly.");
        }).catch(function () {
          say("We couldn't send that just now. Please call or WhatsApp us instead.", true);
        }).finally(function () {
          if (btn) { btn.removeAttribute("aria-disabled"); btn.textContent = "Send Enquiry"; }
        });
        return;
      }

      if (mode === "whatsapp" && has(CFG.whatsapp)) {
        window.open(M.waHref(text), "_blank", "noopener");
        opened = true;
      } else if (has(CFG.email)) {
        window.location.href = M.mailHref("Enquiry from the Mithaas website") +
          "&body=" + encodeURIComponent(text);
        opened = true;
      }

      if (opened) {
        say("Thank you — your enquiry is ready to send. Just hit send in the window that opened.");
        form.reset();
      } else {
        say("Our contact details are being finalised. Please check back shortly — we're sorry for the wait.", true);
      }
    });

    function say(msg, warn) {
      if (!status) return;
      status.textContent = msg;
      status.hidden = false;
      status.style.borderColor = warn ? "var(--wine-soft)" : "var(--gold)";
      status.style.background = warn ? "rgba(138,48,64,0.08)" : "var(--gold-wash)";
      status.focus && status.focus();
    }

    /* Live-clear errors as the visitor fixes them */
    $$("input, textarea", form).forEach(function (f) {
      f.addEventListener("input", function () {
        if (f.getAttribute("aria-invalid") === "true") setErr(f, "");
      });
    });
  }

  /* ---------------------------------------------------------------------
     9. Year stamp + config sanity notice (console only)
     --------------------------------------------------------------------- */
  function initMisc() {
    $$("[data-year]").forEach(function (el) { el.textContent = new Date().getFullYear(); });

    var missing = [];
    if (!has(CFG.phone)) missing.push("phone");
    if (!has(CFG.whatsapp)) missing.push("whatsapp");
    if (!has(CFG.email)) missing.push("email");
    if (!M.addressLines().length) missing.push("address");
    if (!has(CFG.mapEmbed)) missing.push("mapEmbed");
    if (!(CFG.social || {}).instagram) missing.push("social.instagram");
    if (!Object.keys(CFG.hours || {}).some(function (k) { return has(CFG.hours[k]); })) missing.push("hours");
    if (!CFG.showPrices) missing.push("prices (showPrices is false)");

    if (missing.length && console && console.info) {
      console.info(
        "%cMITHAAS setup","font:600 12px/1.6 sans-serif;color:#A07C33",
        "\nAwaiting real business details in assets/js/config.js:\n  • " + missing.join("\n  • ") +
        "\nUntil these are filled in the site shows honest placeholder states rather than invented information."
      );
    }
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  function boot() {
    initNav();
    fillLinks();
    fillHours();
    fillMap();
    fillTestimonials();
    initMenu();
    initGallery();
    initForm();
    initPhotos();
    initLogo();
    initSchema();
    initMisc();
    initReveal();   /* last — picks up anything the renderers injected */
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
