/*
 * Google Analytics 4, loaded only when a measurement ID is configured.
 * IP anonymisation on, advertising signals off. Kept in a file (not inline)
 * so the Content-Security-Policy can stay script-src 'self'.
 */
(function () {
  var el = document.currentScript;
  var id = el && el.getAttribute('data-ga');
  if (!id) return;
  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  gtag('js', new Date());
  gtag('config', id, { anonymize_ip: true, allow_google_signals: false, allow_ad_personalization_signals: false });
})();
