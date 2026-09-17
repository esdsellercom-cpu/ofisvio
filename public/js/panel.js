/* Ofisvio — panel kabuğu etkileşimleri (faz 38)
 *
 * Bağımlılık yok, tarayıcı depolaması yok. JS kapalıyken de çalışır: menü
 * geniş ekranda zaten görünür, tema formu sunucuya POST eder (tercih DB'de).
 * Burada yalnız: dar ekranda kenar menüsünü aç/kapat, tema düğmesinin
 * hedefini sistem temasına göre belirle (tercih yokken "koyu"ya değil,
 * o an görünenin tersine geç).
 */
(function () {
  'use strict';

  function initShell() {
    var shell = document.querySelector('[data-shell]');
    var toggle = document.querySelector('[data-shell-toggle]');
    var backdrop = document.querySelector('[data-shell-close]');
    if (!shell || !toggle || !backdrop) return;

    function setOpen(open) {
      shell.classList.toggle('is-open', open);
      backdrop.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
    }

    toggle.addEventListener('click', function () { setOpen(!shell.classList.contains('is-open')); });
    backdrop.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
    shell.querySelectorAll('.ap-nav a').forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
  }

  function initTheme() {
    var form = document.querySelector('[data-theme-form]');
    if (!form) return;
    var input = form.querySelector('input[name="theme"]');
    var html = document.documentElement;

    form.addEventListener('submit', function () {
      var current = html.getAttribute('data-theme');
      if (!current) {
        current = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      input.value = current === 'dark' ? 'light' : 'dark';
    });
  }

  function boot() { initShell(); initTheme(); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
