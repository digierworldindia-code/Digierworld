/* ==========================================================================
   DIGIE 'R' WORLD — chatbot.js
   "Digie Assistant": scripted conversational widget. Answers questions,
   suggests packages, captures leads and routes hot leads to WhatsApp.

   OPTIONAL true-AI upgrade:
   This is a fast, reliable rule-based assistant that needs no server. A real
   LLM chatbot (ChatGPT/Claude/Gemini) must go through a server or serverless
   proxy — an API key must NEVER sit in this client-side file. To add one
   later, host a small proxy (e.g. a Cloudflare/Netlify function that holds the
   key) and set its URL here; wire it into handleText() with the rule-based
   flow below kept as the offline fallback.
   ========================================================================== */
var DRW_CHAT_API_URL = ""; // e.g. "https://your-proxy.example.com/chat" (optional)

(function ($) {
  "use strict";

  var state = { open: false, leadStep: null, lead: {}, greeted: false };

  var QUICK_MAIN = [
    { t: "Our Services", k: "services" },
    { t: "Pricing & Packages", k: "pricing" },
    { t: "Free Website Audit", k: "audit" },
    { t: "Talk to an Expert", k: "lead" }
  ];

  var INTENTS = [
    {
      keys: ["service", "what do you do", "offer", "help me with"],
      reply: "We're a full-stack digital growth agency. Our core services:<br><br>" +
        "• Social Media Marketing & Reels<br>• SEO + Google Maps Ranking<br>• Google & Meta Ads<br>" +
        "• Website, App & Software Development<br>• WhatsApp, SMS & IVR Campaigns<br>• Branding, Video & Drone Shoots<br><br>" +
        'Explore the full list on our <a href="services.html">Services page</a>.',
      quick: [{ t: "Pricing & Packages", k: "pricing" }, { t: "Talk to an Expert", k: "lead" }]
    },
    {
      keys: ["price", "pricing", "package", "cost", "charge", "fee", "rate", "budget", "how much"],
      reply: "Our Social Media Marketing plans start at:<br><br>" +
        "• <b>Basic</b> — ₹15,000/mo (12 posts + 8 reels)<br>" +
        "• <b>Standard</b> — ₹25,000/mo (20 posts + 15 reels)<br>" +
        "• <b>Premium</b> — ₹35,000/mo (25 posts + 20 reels + drone shoot)<br><br>" +
        'Websites, SEO, ads & software are quoted to your exact need. See <a href="packages.html">Packages</a> for details.',
      quick: [{ t: "Get a Custom Quote", k: "lead" }, { t: "Free Website Audit", k: "audit" }]
    },
    {
      keys: ["audit", "analyse", "analyze", "check my website", "review my site"],
      reply: 'Smart move! Our free audit checks SEO, speed, mobile experience and security in about 30 seconds — and you get the report instantly. ' +
        'Run it here: <a href="index.html#audit">Free Website Audit</a>.',
      quick: [{ t: "Talk to an Expert", k: "lead" }]
    },
    {
      keys: ["seo", "rank", "google search", "search engine"],
      reply: "Our SEO programme covers technical fixes, on-page optimisation, content, local SEO and Google Maps ranking — built to bring you customers who are already searching for you. Most clients see meaningful movement in 90 days.",
      quick: [{ t: "Get SEO Quote", k: "lead" }, { t: "Free Website Audit", k: "audit" }]
    },
    {
      keys: ["social", "instagram", "facebook", "reel", "post", "smm"],
      reply: "We run complete social media growth: strategy, custom creatives, reels (up to cinematic DSLR + drone), community management and Google review management. Plans from ₹15,000/month.",
      quick: [{ t: "See Packages", k: "pricing" }, { t: "Talk to an Expert", k: "lead" }]
    },
    {
      keys: ["website", "web design", "ecommerce", "e-commerce", "landing"],
      reply: "We design fast, conversion-focused websites — business sites, e-commerce, landing pages and web apps — engineered for SEO and lead generation from day one.",
      quick: [{ t: "Get Website Quote", k: "lead" }]
    },
    {
      keys: ["app", "android", "ios", "software", "erp", "crm"],
      reply: "We build mobile apps (Android/iOS) and custom software — school ERPs, billing systems, restaurant software, CRMs and more, used by businesses across India.",
      quick: [{ t: "Discuss My Project", k: "lead" }]
    },
    {
      keys: ["ads", "google ads", "meta", "ppc", "campaign", "advertis"],
      reply: "We manage high-ROI Google Ads & Meta Ads campaigns — search, display, shopping, lead forms and remarketing — with transparent reporting on every rupee spent.",
      quick: [{ t: "Plan My Campaign", k: "lead" }]
    },
    {
      keys: ["whatsapp marketing", "bulk", "sms", "ivr", "call campaign"],
      reply: "Yes! We run bulk WhatsApp campaigns, bulk SMS, IVR systems and voice-call campaigns — perfect for promotions, reminders and lead nurturing at scale.",
      quick: [{ t: "Talk to an Expert", k: "lead" }]
    },
    {
      keys: ["contact", "phone", "number", "call you", "email", "reach"],
      reply: "You can reach us anytime:<br><br>• Call: <b>" + "+91 95414 12288" + "</b><br>• Sales: <b>+91 98969 38988</b><br>• Email: <b>info@digierworld.com</b><br><br>Or tap the WhatsApp button — we reply fast!",
      quick: [{ t: "WhatsApp Now", k: "wa" }]
    },
    {
      keys: ["where", "location", "address", "office", "bhiwadi"],
      reply: "We're at SF 22, Avalon Royal Plaza, Near Haldiram, Bhiwadi, Rajasthan – 301019. We serve clients across India — most of our work happens online, wherever you are.",
      quick: [{ t: "Talk to an Expert", k: "lead" }]
    },
    {
      keys: ["experience", "how long", "years", "clients", "trust", "portfolio"],
      reply: "Digie 'R' World has <b>8+ years of experience</b> and has served <b>105+ clients</b> across India — hospitals, schools, real estate, hotels, restaurants, manufacturers and startups. See our <a href=\"case-studies.html\">case studies</a>.",
      quick: [{ t: "Talk to an Expert", k: "lead" }]
    },
    {
      keys: ["hi", "hello", "hey", "namaste", "good morning", "good evening"],
      reply: "Hello! Great to see you here. I can explain our services, share pricing, run a free website audit or connect you with a growth expert. What would you like?",
      quick: QUICK_MAIN
    },
    {
      keys: ["thank", "great", "ok", "nice"],
      reply: "You're welcome! Anything else I can help you with?",
      quick: QUICK_MAIN
    }
  ];

  function el(html) { return $(html); }

  function scrollBottom() {
    var b = document.getElementById("chatBody");
    if (b) b.scrollTop = b.scrollHeight;
  }

  function addMsg(html, who) {
    el('<div class="msg ' + (who || "bot") + '"></div>').html(html).appendTo("#chatBody");
    scrollBottom();
  }

  function addQuick(buttons) {
    var $wrap = el('<div class="chat-quick"></div>');
    buttons.forEach(function (b) {
      el("<button type='button'></button>").text(b.t).on("click", function () {
        $(".chat-quick").remove();
        addMsg($("<div>").text(b.t).html(), "user");
        route(b.k, b.t);
      }).appendTo($wrap);
    });
    $wrap.appendTo("#chatBody");
    scrollBottom();
  }

  function botSay(html, quick, delay) {
    var $t = el('<div class="msg bot"><span class="typing"><span></span><span></span><span></span></span></div>').appendTo("#chatBody");
    scrollBottom();
    setTimeout(function () {
      $t.html(html);
      if (quick && quick.length) addQuick(quick);
      scrollBottom();
    }, delay || 750);
  }

  /* ---------- Lead capture flow ---------- */
  function startLead() {
    state.leadStep = "name";
    state.lead = {};
    botSay("Excellent! Let me connect you with a growth expert. May I have your <b>name</b>?");
  }

  function leadFlow(text) {
    if (state.leadStep === "name") {
      state.lead.name = text;
      state.leadStep = "phone";
      botSay("Nice to meet you, <b>" + $("<div>").text(text).html() + "</b>! What's the best <b>mobile number</b> to reach you on?");
      return;
    }
    if (state.leadStep === "phone") {
      var digits = text.replace(/\D/g, "");
      if (digits.length < 10) {
        botSay("That number looks incomplete — please share a 10-digit mobile number.");
        return;
      }
      state.lead.phone = text;
      state.leadStep = "need";
      botSay("Got it. Briefly, <b>what do you need help with</b>? (e.g. social media, website, SEO, ads, software)");
      return;
    }
    if (state.leadStep === "need") {
      state.lead.need = text;
      state.leadStep = null;
      DRW.saveLead({ type: "chatbot", data: state.lead });

      var msg = "Hi Digie 'R' World! I chatted with your website assistant.\n——————————\n" +
        "Name: " + state.lead.name + "\nPhone: " + state.lead.phone + "\nRequirement: " + state.lead.need +
        "\n——————————\nPlease call me back with the best plan.";

      botSay("Perfect, <b>" + $("<div>").text(state.lead.name).html() + "</b>! Our expert will reach you shortly. " +
        "For the fastest response, send your request straight to our team on WhatsApp:<br><br>" +
        '<a class="btn btn-gold btn-sm-pill" target="_blank" rel="noopener" href="' + DRW.waLink(msg) + '"><i class="bi bi-whatsapp me-1"></i>Send on WhatsApp</a>',
        [{ t: "Back to Menu", k: "menu" }], 900);
    }
  }

  /* ---------- Routing ---------- */
  function route(key, label) {
    if (key === "lead") { startLead(); return; }
    if (key === "wa") { window.open(DRW.waLink(), "_blank", "noopener"); return; }
    if (key === "menu") { botSay("Sure — what would you like to explore?", QUICK_MAIN, 500); return; }
    if (key === "audit") {
      botSay('Our free audit checks SEO, speed, mobile & security and shows your scores instantly. ' +
        'Tap here: <a href="index.html#audit">Run my free audit</a> — it takes 30 seconds.',
        [{ t: "Talk to an Expert", k: "lead" }]);
      return;
    }
    if (key === "services" || key === "pricing") {
      var intent = INTENTS.find(function (i) { return i.keys.indexOf(key === "services" ? "service" : "price") !== -1; });
      botSay(intent.reply, intent.quick);
      return;
    }
    handleText(label || key);
  }

  function handleText(text) {
    var low = text.toLowerCase();
    for (var i = 0; i < INTENTS.length; i++) {
      for (var j = 0; j < INTENTS[i].keys.length; j++) {
        if (low.indexOf(INTENTS[i].keys[j]) !== -1) {
          botSay(INTENTS[i].reply, INTENTS[i].quick);
          return;
        }
      }
    }
    botSay("Good question! For a precise answer, our experts are one tap away — or pick an option below.",
      QUICK_MAIN.concat([{ t: "WhatsApp Us", k: "wa" }]));
  }

  /* ---------- UI wiring ---------- */
  $(function () {
    if (!document.getElementById("chatFab")) return;

    $("#chatFab").on("click", function () {
      state.open = !state.open;
      $("#chatPanel").toggleClass("open", state.open);
      $("#chatFab .chat-ping").hide();
      if (state.open && !state.greeted) {
        state.greeted = true;
        setTimeout(function () {
          botSay("Namaste! I'm <b>Digie Assistant</b> — your digital growth guide. " +
            "I can explain services, share pricing, audit your website or connect you to an expert. How can I help today?",
            QUICK_MAIN, 600);
        }, 250);
      }
      if (state.open) setTimeout(function () { $("#chatInput").trigger("focus"); }, 350);
    });

    $("#chatClose").on("click", function () {
      state.open = false;
      $("#chatPanel").removeClass("open");
    });

    function send() {
      var v = $("#chatInput").val().trim();
      if (!v) return;
      $(".chat-quick").remove();
      addMsg($("<div>").text(v).html(), "user");
      $("#chatInput").val("");
      if (state.leadStep) leadFlow(v); else handleText(v);
    }
    $("#chatSend").on("click", send);
    $("#chatInput").on("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); send(); } });
  });
})(jQuery);
