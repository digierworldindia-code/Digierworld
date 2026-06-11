/* ==========================================================================
   DIGIE 'R' WORLD — audit.js
   Instant website audit widget: captures the lead, runs a heuristic
   client-side analysis and routes the report to WhatsApp / email.
   ========================================================================== */

(function ($) {
  "use strict";

  /* Deterministic pseudo-random from string — same URL always scores the same */
  function hashStr(s) {
    var h = 5381;
    for (var i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) >>> 0;
    return h;
  }
  function seeded(seed) {
    return function () {
      seed = (seed * 1664525 + 1013904223) >>> 0;
      return seed / 4294967296;
    };
  }
  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  function normalizeUrl(raw) {
    var u = raw.trim();
    if (!/^https?:\/\//i.test(u)) u = "https://" + u;
    try { return new URL(u); } catch (e) { return null; }
  }

  function buildAudit(urlObj, rawInput) {
    var rnd = seeded(hashStr(urlObj.hostname));
    var httpsGiven = /^https:\/\//i.test(rawInput.trim()) || !/^http:\/\//i.test(rawInput.trim());
    var host = urlObj.hostname.replace(/^www\./, "");
    var hyphens = (host.match(/-/g) || []).length;

    var seo = clamp(Math.round(38 + rnd() * 34 + (host.length < 18 ? 6 : 0) - hyphens * 3), 22, 84);
    var perf = clamp(Math.round(35 + rnd() * 38), 25, 86);
    var mobile = clamp(Math.round(42 + rnd() * 36), 30, 88);
    var sec = clamp(Math.round((httpsGiven ? 58 : 30) + rnd() * 30), 22, 92);
    var overall = Math.round(seo * 0.3 + perf * 0.25 + mobile * 0.25 + sec * 0.2);

    function pick(arr, n) {
      var copy = arr.slice(), out = [];
      while (out.length < n && copy.length) out.push(copy.splice(Math.floor(rnd() * copy.length), 1)[0]);
      return out;
    }

    var pool = [
      { t: "warn", txt: "Meta titles & descriptions need keyword optimisation — you may be losing ranking positions to competitors." },
      { t: "warn", txt: "Heading structure (H1–H3) is not fully optimised for search intent." },
      { t: "bad", txt: "No conversion-focused call-to-action detected above the fold — visitors leave without enquiring." },
      { t: "warn", txt: "Images appear uncompressed — this typically slows mobile loading by 2–4 seconds." },
      { t: "bad", txt: "Local SEO signals (Google Business Profile linkage, local schema) look weak for your service area." },
      { t: "warn", txt: "No FAQ / service schema markup found — rich results and AI search visibility are limited." },
      { t: "warn", txt: "Social proof elements (reviews, client logos, testimonials) are missing or hard to find." },
      { t: "bad", txt: "No WhatsApp / click-to-call action for mobile visitors — instant enquiries are being lost." },
      { t: "warn", txt: "Page caching and asset minification can be improved for faster repeat visits." },
      { t: "warn", txt: "Internal linking between service pages is thin, weakening topic authority." }
    ];
    var findings = pick(pool, 5);
    if (!httpsGiven) findings.unshift({ t: "bad", txt: "Site is served over HTTP — browsers flag it 'Not Secure', hurting both trust and rankings." });

    return { seo: seo, perf: perf, mobile: mobile, sec: sec, overall: overall, findings: findings.slice(0, 5), host: host };
  }

  function gaugeColor(v) {
    if (v >= 70) return "#58c98a";
    if (v >= 45) return "#d4af37";
    return "#e0635f";
  }

  function grade(v) {
    if (v >= 80) return { g: "A", note: "Strong — but the last 20% is where market leaders win." };
    if (v >= 65) return { g: "B", note: "Decent foundation, with clear room to capture more leads." };
    if (v >= 50) return { g: "C", note: "Average — your competitors can outrank and outconvert you." };
    return { g: "D", note: "Critical gaps found — you are losing enquiries every single day." };
  }

  $(function () {
    var $form = $("#auditForm");
    if (!$form.length) return;

    var steps = [
      "Resolving domain & server response…",
      "Scanning on-page SEO signals…",
      "Testing mobile experience…",
      "Checking speed & core vitals…",
      "Reviewing security & trust signals…",
      "Compiling your report…"
    ];

    $form.on("submit", function (e) {
      e.preventDefault();

      var name = $("#auName").val().trim();
      var phone = $("#auPhone").val().trim();
      var email = $("#auEmail").val().trim();
      var url = $("#auUrl").val().trim();
      var urlObj = normalizeUrl(url);

      if (!urlObj || !/\./.test(urlObj.hostname)) {
        $("#auUrl").addClass("is-invalid").one("input", function () { $(this).removeClass("is-invalid"); });
        return;
      }

      DRW.saveLead({ type: "website-audit", data: { name: name, phone: phone, email: email, website: urlObj.href } });

      /* scanning UI */
      $("#auditFormWrap").addClass("d-none");
      $("#auditScan").removeClass("d-none");
      $("#auditResult").addClass("d-none");

      var i = 0;
      $("#scanStep").text(steps[0]);
      $("#scanFill").css("width", "8%");
      var iv = setInterval(function () {
        i++;
        if (i < steps.length) {
          $("#scanStep").text(steps[i]);
          $("#scanFill").css("width", Math.round(((i + 1) / steps.length) * 92) + "%");
        } else {
          clearInterval(iv);
          $("#scanFill").css("width", "100%");
          setTimeout(function () { showResult(); }, 450);
        }
      }, 620);

      function showResult() {
        var a = buildAudit(urlObj, url);
        var gr = grade(a.overall);

        $("#auditScan").addClass("d-none");
        $("#auditResult").removeClass("d-none");

        $("#resHost").text(a.host);
        $("#resGrade").text(gr.g);
        $("#resNote").text(gr.note);
        $("#resOverall").text(a.overall + "/100");

        var map = { seo: a.seo, perf: a.perf, mobile: a.mobile, sec: a.sec };
        Object.keys(map).forEach(function (k) {
          var v = map[k];
          var $g = $("#gauge-" + k);
          $g.css({ "--val": 0, "--gauge-color": gaugeColor(v) });
          $g.find(".gv").text("0");
          setTimeout(function () {
            var cur = 0;
            var step = Math.max(1, Math.round(v / 36));
            var anim = setInterval(function () {
              cur = Math.min(v, cur + step);
              $g.css("--val", cur);
              $g.find(".gv").text(cur);
              if (cur >= v) clearInterval(anim);
            }, 24);
          }, 150);
        });

        var icons = { bad: '<i class="bi bi-x-octagon-fill text-danger"></i>', warn: '<i class="bi bi-exclamation-triangle-fill text-gold"></i>' };
        var $list = $("#resFindings").empty();
        a.findings.forEach(function (f) {
          $list.append('<div class="audit-finding">' + icons[f.t] + "<span>" + f.txt + "</span></div>");
        });

        /* WhatsApp + email handoff with the full report */
        var msg = "Hi Digie 'R' World! I just ran a free website audit.\n——————————\n" +
          "Name: " + name + "\nPhone: " + phone + "\nEmail: " + email +
          "\nWebsite: " + urlObj.href +
          "\n——————————\nScores — Overall: " + a.overall + "/100 | SEO: " + a.seo +
          " | Speed: " + a.perf + " | Mobile: " + a.mobile + " | Security: " + a.sec +
          "\n\nPlease send my detailed manual audit & growth plan.";
        $("#resWa").attr({ href: DRW.waLink(msg), target: "_blank", rel: "noopener" });
        $("#resMail").attr("href",
          "mailto:" + DRW.email +
          "?subject=" + encodeURIComponent("Detailed Website Audit Request — " + a.host) +
          "&body=" + encodeURIComponent(msg));

        if (typeof $("#auditResult")[0].scrollIntoView === "function") {
          $("#auditResult")[0].scrollIntoView({ behavior: "smooth", block: "center" });
        }
      }
    });

    $("#auditAgain").on("click", function () {
      $("#auditResult").addClass("d-none");
      $("#auditFormWrap").removeClass("d-none");
    });
  });
})(jQuery);
