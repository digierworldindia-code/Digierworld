/* ==========================================================================
   DIGIE 'R' WORLD — main.js
   Global config, navigation, hero canvas, counters, reveal, lead helpers
   ========================================================================== */

/* ------- Central business config (edit once, applies site-wide) ------- */
window.DRW = {
  brand: "Digie 'R' World",
  site: "https://www.digierworld.com",
  email: "info@digierworld.com",
  phonePrimary: "+919541412288",
  phonePrimaryDisplay: "+91 95414 12288",
  phoneSales: "+919896938988",
  phoneSalesDisplay: "+91 98969 38988",
  whatsapp: "919541412288",
  address: "SF 22, Avalon Royal Plaza, Near Haldiram, Bhiwadi, Rajasthan – 301019",
  /* Official profiles. Set a value to null to hide that icon site-wide. */
  social: {
    instagram: "https://www.instagram.com/digierworld/",
    facebook: "https://www.facebook.com/digierworldindia/",
    linkedin: "https://in.linkedin.com/in/ravi-sehrawat-151721316",
    youtube: null,
    twitter: null,
    google: "https://share.google/YpFadDgL5Pwh9icAh"
  }
};

/* WhatsApp deep link with prefilled message */
window.DRW.waLink = function (msg) {
  return "https://wa.me/" + DRW.whatsapp + "?text=" + encodeURIComponent(msg || "Hi Digie 'R' World! I'd like to know more about your services.");
};

/* Minimal local lead store (mirrors leads the visitor submits on this device) */
window.DRW.saveLead = function (lead) {
  try {
    var leads = JSON.parse(localStorage.getItem("drw_leads") || "[]");
    lead.ts = new Date().toISOString();
    lead.page = location.pathname;
    leads.push(lead);
    localStorage.setItem("drw_leads", JSON.stringify(leads));
  } catch (e) { /* storage unavailable — ignore */ }
};

(function ($) {
  "use strict";

  var prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  $(function () {

    /* ---------------- Theme: light (default) / dark, auto + manual ---------------- */
    var root = document.documentElement;
    var mq = window.matchMedia("(prefers-color-scheme: dark)");
    function applyTheme(t) {
      root.setAttribute("data-bs-theme", t);
      $(".theme-toggle").attr("aria-label", t === "dark" ? "Switch to light theme" : "Switch to dark theme");
      window.dispatchEvent(new CustomEvent("drw:themechange", { detail: t }));
    }
    // Honour the no-flash inline value already on <html>; default to light.
    if (!root.getAttribute("data-bs-theme")) {
      var saved0 = null;
      try { saved0 = localStorage.getItem("drw-theme"); } catch (e) {}
      applyTheme(saved0 || (mq.matches ? "dark" : "light"));
    }
    $(".theme-toggle").on("click", function () {
      var next = root.getAttribute("data-bs-theme") === "dark" ? "light" : "dark";
      applyTheme(next);
      try { localStorage.setItem("drw-theme", next); } catch (e) {}
    });
    // Follow OS changes only while the visitor hasn't set a manual preference.
    mq.addEventListener("change", function (e) {
      var saved = null;
      try { saved = localStorage.getItem("drw-theme"); } catch (err) {}
      if (!saved) applyTheme(e.matches ? "dark" : "light");
    });

    /* ---------------- Navbar scrolled state ---------------- */
    var $nav = $(".navbar-drw");
    function onScroll() {
      $nav.toggleClass("scrolled", window.scrollY > 24);
      $("#backTop").toggleClass("show", window.scrollY > 600);
    }
    $(window).on("scroll", onScroll);
    onScroll();

    /* ---------------- Back to top ---------------- */
    $("#backTop").on("click", function () {
      window.scrollTo({ top: 0, behavior: prefersReduced ? "auto" : "smooth" });
    });

    /* ---------------- Reveal on scroll ---------------- */
    if ("IntersectionObserver" in window && !prefersReduced) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            en.target.classList.add("in");
            io.unobserve(en.target);
          }
        });
      }, { threshold: 0.12, rootMargin: "0px 0px -40px 0px" });
      document.querySelectorAll(".reveal").forEach(function (el) { io.observe(el); });
    } else {
      $(".reveal").addClass("in");
    }

    /* ---------------- Animated counters ---------------- */
    function animateCounter(el) {
      var target = parseFloat(el.getAttribute("data-count")) || 0;
      var suffix = el.getAttribute("data-suffix") || "";
      var dur = 1600, start = null;
      if (prefersReduced) { el.textContent = target + suffix; return; }
      function step(ts) {
        if (!start) start = ts;
        var p = Math.min((ts - start) / dur, 1);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased) + suffix;
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    }
    if ("IntersectionObserver" in window) {
      var cio = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { animateCounter(en.target); cio.unobserve(en.target); }
        });
      }, { threshold: 0.6 });
      document.querySelectorAll("[data-count]").forEach(function (el) { cio.observe(el); });
    } else {
      document.querySelectorAll("[data-count]").forEach(animateCounter);
    }

    /* ---------------- Marquee: duplicate track for seamless loop ---------------- */
    document.querySelectorAll(".marquee-track").forEach(function (track) {
      track.innerHTML += track.innerHTML;
    });

    /* ---------------- Footer year ---------------- */
    $(".js-year").text(new Date().getFullYear());

    /* ---------------- Inject dynamic contact links ---------------- */
    $(".js-tel").attr("href", "tel:" + DRW.phonePrimary);
    $(".js-tel-sales").attr("href", "tel:" + DRW.phoneSales);
    $(".js-mail").attr("href", "mailto:" + DRW.email);
    $(".js-wa").each(function () {
      var msg = $(this).data("wamsg");
      $(this).attr({ href: DRW.waLink(msg), target: "_blank", rel: "noopener" });
    });
    Object.keys(DRW.social).forEach(function (k) {
      var $els = $(".js-soc-" + k);
      if (DRW.social[k]) {
        $els.attr({ href: DRW.social[k], target: "_blank", rel: "noopener" });
      } else {
        $els.remove();
      }
    });

    /* ---------------- Generic enquiry forms → WhatsApp handoff ---------------- */
    $(".js-lead-form").on("submit", function (e) {
      e.preventDefault();
      var $f = $(this);
      var data = {};
      $f.serializeArray().forEach(function (i) { data[i.name] = i.value.trim(); });
      if (!data.name || !data.phone) return;

      DRW.saveLead({ type: $f.data("leadtype") || "enquiry", data: data });

      var lines = ["New enquiry from " + DRW.site.replace("https://", "") + " %0A——————————"];
      var msg = "New enquiry from digierworld.com\n——————————\n";
      Object.keys(data).forEach(function (k) {
        if (data[k]) msg += k.charAt(0).toUpperCase() + k.slice(1) + ": " + data[k] + "\n";
      });
      msg += "——————————\nPlease share details. Thank you!";

      var $btn = $f.find("[type=submit]");
      $btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm me-2"></span>Opening WhatsApp…');
      setTimeout(function () {
        window.open(DRW.waLink(msg), "_blank", "noopener");
        $btn.prop("disabled", false).html($btn.data("label") || "Send Enquiry");
        $f.find(".js-form-done").removeClass("d-none");
        $f.trigger("reset");
      }, 650);
    });

    /* ---------------- Hero particle constellation ---------------- */
    var canvas = document.getElementById("heroCanvas");
    if (canvas && !prefersReduced) {
      var ctx = canvas.getContext("2d");
      var P = [], W, H, raf;
      var DENSITY = 16000, MAXLINK = 130;

      /* Particle colour is read from the active theme so dots stay visible
         on both the white and dark backgrounds. */
      var particleRGB = "212,175,55";
      function refreshParticleColor() {
        var v = getComputedStyle(document.documentElement).getPropertyValue("--particle-rgb").trim();
        if (v) particleRGB = v;
      }
      refreshParticleColor();
      window.addEventListener("drw:themechange", refreshParticleColor);

      function size() {
        W = canvas.width = canvas.offsetWidth;
        H = canvas.height = canvas.offsetHeight;
        var n = Math.min(90, Math.floor((W * H) / DENSITY));
        P = [];
        for (var i = 0; i < n; i++) {
          P.push({
            x: Math.random() * W,
            y: Math.random() * H,
            vx: (Math.random() - 0.5) * 0.35,
            vy: (Math.random() - 0.5) * 0.35,
            r: Math.random() * 1.7 + 0.5
          });
        }
      }

      function tick() {
        ctx.clearRect(0, 0, W, H);
        for (var i = 0; i < P.length; i++) {
          var p = P[i];
          p.x += p.vx; p.y += p.vy;
          if (p.x < 0 || p.x > W) p.vx *= -1;
          if (p.y < 0 || p.y > H) p.vy *= -1;
          ctx.beginPath();
          ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
          ctx.fillStyle = "rgba(" + particleRGB + ",0.55)";
          ctx.fill();
          for (var j = i + 1; j < P.length; j++) {
            var q = P[j], dx = p.x - q.x, dy = p.y - q.y;
            var d = Math.sqrt(dx * dx + dy * dy);
            if (d < MAXLINK) {
              ctx.beginPath();
              ctx.moveTo(p.x, p.y);
              ctx.lineTo(q.x, q.y);
              ctx.strokeStyle = "rgba(" + particleRGB + "," + (0.16 * (1 - d / MAXLINK)) + ")";
              ctx.lineWidth = 1;
              ctx.stroke();
            }
          }
        }
        raf = requestAnimationFrame(tick);
      }

      size();
      tick();
      var rto;
      window.addEventListener("resize", function () {
        clearTimeout(rto);
        rto = setTimeout(size, 200);
      });
      document.addEventListener("visibilitychange", function () {
        if (document.hidden) cancelAnimationFrame(raf); else tick();
      });
    }

  });
})(jQuery);
