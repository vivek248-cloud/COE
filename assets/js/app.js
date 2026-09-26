/**
 * Global client helpers.
 * Question creation/editing is intentionally not implemented here.
 * Faculty question content enters the system through the secure upload API only.
 */
document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) lucide.createIcons();
  if (window.MathJax && window.MathJax.typesetPromise) {
    window.MathJax.typesetPromise().catch(() => {});
  }
});
