/**
 * Application JavaScript.
 *
 * Progressive enhancement only: every page must work with JS disabled, because
 * validation and authorisation are enforced server-side regardless.
 */

document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) {
    window.lucide.createIcons();
  }
});

// Re-draw icons after Alpine swaps DOM (x-if / x-for).
document.addEventListener('alpine:initialized', () => {
  if (window.lucide) {
    window.lucide.createIcons();
  }
});
