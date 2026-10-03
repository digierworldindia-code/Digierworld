/* Print button on the report preview (no inline handlers: strict CSP). */
document.querySelectorAll('[data-print]').forEach((button) => {
  button.addEventListener('click', () => window.print());
});
