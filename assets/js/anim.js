/* ==========================================================================
   DIGIE 'R' WORLD — anim.js
   Premium, restrained motion via GSAP + ScrollTrigger (loaded from CDN).
   Purely additive: the IntersectionObserver reveals in main.js remain the
   baseline, so the site is fully functional even if GSAP fails to load.
   All motion is disabled under prefers-reduced-motion.
   ========================================================================== */
(function () {
  "use strict";

  var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (reduce || typeof window.gsap === "undefined") return;

  var gsap = window.gsap;
  if (window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

  /* Hero entrance — a calm, confident staggered rise. */
  var heroBits = document.querySelectorAll(".hero-content .reveal");
  if (heroBits.length) {
    gsap.set(".hero-content .reveal", { clearProps: "opacity,transform" });
    gsap.from(".hero-content .reveal", {
      y: 26, opacity: 0, duration: 0.9, ease: "power3.out", stagger: 0.12
    });
  }

  if (!window.ScrollTrigger) return;

  /* Gentle parallax on the hero glow orbs. */
  gsap.utils.toArray(".hero-orb").forEach(function (orb, i) {
    gsap.to(orb, {
      yPercent: i % 2 === 0 ? 22 : -18,
      ease: "none",
      scrollTrigger: { trigger: ".hero", start: "top top", end: "bottom top", scrub: true }
    });
  });

  /* Depth on inner service illustrations (wrapper keeps its own reveal). */
  gsap.utils.toArray(".svc-visual svg").forEach(function (svg) {
    gsap.from(svg, {
      y: 18, opacity: 0, duration: 0.8, ease: "power2.out",
      scrollTrigger: { trigger: svg, start: "top 88%" }
    });
  });

  /* Subtle parallax lift on stat blocks for a premium feel. */
  gsap.utils.toArray(".cta-band").forEach(function (band) {
    gsap.from(band, {
      y: 30, opacity: 0, duration: 0.9, ease: "power2.out",
      scrollTrigger: { trigger: band, start: "top 90%" }
    });
  });

  /* ---- Optional Lottie hook -------------------------------------------------
     lottie-web is loaded from CDN. To use a custom animation, add a container
     <div class="lottie" data-lottie="assets/lottie/your-file.json"></div>
     and drop the JSON into assets/lottie/. (No default file ships, so this is
     a no-op until the founder adds one.)
  --------------------------------------------------------------------------- */
  if (typeof window.lottie !== "undefined") {
    document.querySelectorAll(".lottie[data-lottie]").forEach(function (el) {
      try {
        window.lottie.loadAnimation({
          container: el, renderer: "svg", loop: true, autoplay: true,
          path: el.getAttribute("data-lottie")
        });
      } catch (e) { /* ignore */ }
    });
  }
})();
