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


  /* Envanter ekranı (faz 46): <dialog> modalları, form doldurma, bağımlı alan/seçenek süzme.
   * data-modal-open="#id" (+ data-action, data-method, data-title, data-fill='{json}')
   * data-when="alan:v1,v2"  → alan değeri kümedeyse görünür, değilse gizli + girdileri devre dışı
   * data-filter-by="alan"   → select seçenekleri data-filter="…" ile alan değerine (ya da seçili option'ın data-key'ine) göre süzülür
   * data-group-source       → seçili option'ın data-group değeri form data-<group>-action'a bağlanır (alan/oda) */
  function initModals() {
    function fieldValue(form, name) {
      var el = form.querySelector('[name="' + name + '"]');
      if (!el) return '';
      if (el.tagName === 'SELECT' && el.selectedIndex >= 0) {
        var opt = el.options[el.selectedIndex];
        return opt && opt.getAttribute('data-key') !== null ? opt.getAttribute('data-key') : el.value;
      }
      return el.value;
    }

    function applyDeps(form) {
      form.querySelectorAll('[data-when]').forEach(function (box) {
        var parts = box.getAttribute('data-when').split(':');
        var value = fieldValue(form, parts[0]);
        var on = parts[1].split(',').indexOf(value) !== -1;
        box.hidden = !on;
        box.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
      });
      form.querySelectorAll('[data-filter-by]').forEach(function (sel) {
        var key = String(fieldValue(form, sel.getAttribute('data-filter-by')));
        Array.prototype.forEach.call(sel.options, function (o) {
          var own = o.getAttribute('data-filter');
          var show = own === null || own === key || key === '';
          o.hidden = !show; o.disabled = !show;
          if (!show && o.selected) o.selected = false;
        });
      });
      var src = form.querySelector('[data-group-source]');
      if (src && !form.hasAttribute('data-action-locked')) {
        var opt = src.options[src.selectedIndex];
        var group = opt ? opt.getAttribute('data-group') : null;
        var action = group ? form.getAttribute('data-' + group + '-action') : null;
        if (action) form.setAttribute('action', action);
      }
      var mirror = form.querySelector('input[name="_action"]');
      if (mirror) mirror.value = form.getAttribute('action') || '';
    }

    function fill(form, data) {
      Object.keys(data).forEach(function (k) {
        var v = data[k];
        form.querySelectorAll('[name="' + k + '"],[name="' + k + '[]"]').forEach(function (el) {
          if (el.type === 'checkbox') { el.checked = Array.isArray(v) ? v.map(String).indexOf(el.value) !== -1 : (v === true || String(v) === el.value); }
          else if (el.tagName === 'SELECT' && el.multiple) { Array.prototype.forEach.call(el.options, function (o) { o.selected = Array.isArray(v) && v.map(String).indexOf(o.value) !== -1; }); }
          else { el.value = v === null || v === undefined ? '' : v; }
        });
      });
    }

    function open(dlg, btn) {
      var form = dlg.querySelector('form[data-modal-form]');
      if (form) {
        form.reset();
        form.removeAttribute('data-action-locked');
        form.querySelectorAll('[data-lock-group]').forEach(function (o) { o.disabled = false; o.removeAttribute('data-lock-group'); });
        var method = form.querySelector('input[name="_method"]');
        if (method) method.value = (btn && btn.getAttribute('data-method')) || 'POST';
        var action = btn && btn.getAttribute('data-action');
        if (action) { form.setAttribute('action', action); form.setAttribute('data-action-locked', '1'); }
        var title = dlg.querySelector('[data-modal-title]');
        if (title) title.textContent = (btn && btn.getAttribute('data-title')) || title.getAttribute('data-default') || title.textContent;
        var raw = btn && btn.getAttribute('data-fill');
        if (raw) {
          try { fill(form, JSON.parse(raw)); } catch (e) { /* bozuk veri: boş form */ }
          var src = form.querySelector('[data-group-source]');
          if (src) {
            var current = src.options[src.selectedIndex];
            var group = current ? current.getAttribute('data-group') : null;
            Array.prototype.forEach.call(src.options, function (o) { if (group && o.getAttribute('data-group') !== group) { o.disabled = true; o.setAttribute('data-lock-group', '1'); } });
          }
        }
        applyDeps(form);
      }
      if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    }

    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var dlg = document.querySelector(btn.getAttribute('data-modal-open'));
        if (dlg) open(dlg, btn);
      });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (b) {
      b.addEventListener('click', function () { var d = b.closest('dialog'); if (d) d.close(); });
    });
    document.querySelectorAll('form[data-modal-form]').forEach(function (form) {
      form.addEventListener('change', function () { applyDeps(form); });
      applyDeps(form);
    });
    // Doğrulama hatasından dönüşte ilgili modal (eski girdilerle) yeniden açılır.
    var auto = document.querySelector('dialog[data-modal-auto]');
    if (auto) {
      var autoForm = auto.querySelector('form[data-modal-form]');
      if (autoForm) {
        var a = auto.getAttribute('data-modal-auto-action');
        if (a) { autoForm.setAttribute('action', a); autoForm.setAttribute('data-action-locked', '1'); }
        var m = auto.getAttribute('data-modal-auto-method');
        var mi = autoForm.querySelector('input[name="_method"]');
        if (m && mi) mi.value = m;
        applyDeps(autoForm);
      }
      if (typeof auto.showModal === 'function') auto.showModal();
    }
    // Menü (•••): dışarı tıklayınca kapanır.
    document.addEventListener('click', function (e) {
      document.querySelectorAll('details.menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
  }

  function boot() { initShell(); initTheme(); initModals(); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
