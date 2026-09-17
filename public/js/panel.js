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
      form.addEventListener('change', function (e) {
        applyDeps(form);
        // data-sync="kaynak:attr": kaynak select değişince seçili option'ın data-attr değeri alana yazılır (tutar, açıklama, para birimi).
        var src = e.target && e.target.getAttribute ? e.target.getAttribute('name') : null;
        if (src) {
          form.querySelectorAll('[data-sync^="' + src + ':"]').forEach(function (el) {
            var attr = el.getAttribute('data-sync').split(':')[1];
            var opt = e.target.options ? e.target.options[e.target.selectedIndex] : null;
            if (opt && opt.getAttribute('data-' + attr) !== null) el.value = opt.getAttribute('data-' + attr);
          });
        }
      });
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

  /* Belge şablonu canlı önizleme (faz 47): form alanları → önizlemedeki [data-slot] öğeleri; {{yer_tutucu}} örnek değerlerle değişir. */
  function initLivePreview() {
    var form = document.querySelector('form[data-live-preview]');
    var preview = document.querySelector('[data-preview]');
    if (!form || !preview) return;
    var sample = {};
    try { sample = JSON.parse(preview.getAttribute('data-sample') || '{}'); } catch (e) { sample = {}; }
    var labels = {};
    try { labels = JSON.parse(preview.getAttribute('data-column-labels') || '{}'); } catch (e) { labels = {}; }

    function sub(text) {
      return String(text || '').replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (m, key) { return Object.prototype.hasOwnProperty.call(sample, key) ? sample[key] : m; });
    }
    function lines(text) { return String(text || '').split(/\r?\n/).map(sub); }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function update() {
      var v = function (name) { var el = form.querySelector('[name="' + name + '"]'); return el ? el.value : ''; };
      var accent = v('accent') || '#1f5f4b';
      preview.querySelectorAll('[data-slot="heading"]').forEach(function (el) { el.textContent = sub(v('heading')); el.style.color = accent; });
      preview.querySelectorAll('[data-slot="subheading"]').forEach(function (el) { el.textContent = sub(v('subheading')); });
      preview.querySelectorAll('[data-slot="intro"]').forEach(function (el) { el.textContent = sub(v('intro')); el.style.display = v('intro') ? '' : 'none'; });
      preview.querySelectorAll('[data-slot="body"]').forEach(function (el) { el.innerHTML = lines(v('body')).map(function (l) { return '<div>' + esc(l) + '</div>'; }).join(''); el.style.display = v('body') ? '' : 'none'; });
      preview.querySelectorAll('[data-slot="footer"]').forEach(function (el) { el.innerHTML = lines(v('footer')).map(function (l) { return '<div>' + esc(l) + '</div>'; }).join(''); el.style.display = v('footer') ? '' : 'none'; });
      preview.querySelectorAll('[data-slot="signature"]').forEach(function (el) { el.querySelector('span[data-text]').textContent = sub(v('signature')); el.style.display = v('signature') ? '' : 'none'; });
      preview.querySelectorAll('[data-slot="stamp"]').forEach(function (el) { el.textContent = sub(v('stamp')); el.style.display = v('stamp') ? '' : 'none'; });
      preview.querySelectorAll('[data-slot="business"]').forEach(function (el) { var on = form.querySelector('[name="show_business"]'); el.style.display = on && on.checked ? '' : 'none'; });
      preview.querySelectorAll('[data-slot="logo"]').forEach(function (el) { var url = v('logo_url'); var img = el.querySelector('img'); var txt = el.querySelector('[data-text]'); if (img) { img.src = url || ''; img.style.display = url ? '' : 'none'; } if (txt) { txt.style.display = url ? 'none' : ''; txt.style.color = accent; } });
      var table = preview.querySelector('[data-slot="table"]');
      if (table) {
        var cols = Array.prototype.filter.call(form.querySelectorAll('[name="columns[]"]'), function (c) { return c.checked; }).map(function (c) { return c.value; });
        table.style.display = cols.length ? '' : 'none';
        var right = ['amount', 'paid_amount', 'remaining_amount', 'invoice_total'];
        table.querySelector('thead tr').innerHTML = cols.map(function (c) { return '<th style="text-align:' + (right.indexOf(c) !== -1 ? 'right' : 'left') + ';padding:7px 8px;background:' + accent + ';color:#fff;font-weight:600">' + esc(labels[c] || c) + '</th>'; }).join('');
        table.querySelector('tbody tr').innerHTML = cols.map(function (c) { return '<td style="text-align:' + (right.indexOf(c) !== -1 ? 'right' : 'left') + ';padding:7px 8px;border-bottom:1px solid #ddd">' + esc(sample[c] || '') + '</td>'; }).join('');
      }
    }

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
  }

  // Gönderim durumu (faz 52): form gönderilince düğmeler devre dışı + aria-busy (çift tıklama koruması, "işlem gerçekleşti mi"
  // belirsizliği yok). Submitter'ın name/value'su form verisine girsin diye devre dışı bırakma bir tık sonraya ertelenir;
  // 20 sn sonra (sunucu yanıt vermezse) yeniden etkin. data-no-busy ile kapatılır.
  function initBusyForms() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy') || e.defaultPrevented) return;
      if (form.getAttribute('data-busy') === '1') { e.preventDefault(); return; }
      form.setAttribute('data-busy', '1');
      var buttons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'));
      setTimeout(function () { buttons.forEach(function (b) { b.disabled = true; b.setAttribute('aria-busy', 'true'); b.classList.add('is-busy'); }); }, 0);
      setTimeout(function () { form.removeAttribute('data-busy'); buttons.forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); b.classList.remove('is-busy'); }); }, 20000);
    }); // kabarcık evresi: hedef dinleyiciler (confirm/preventDefault) önce koşar
    window.addEventListener('pageshow', function () { document.querySelectorAll('form[data-busy]').forEach(function (f) { f.removeAttribute('data-busy'); f.querySelectorAll('[aria-busy]').forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); b.classList.remove('is-busy'); }); }); });
  }

  function boot() { initShell(); initTheme(); initModals(); initLivePreview(); initBusyForms(); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
