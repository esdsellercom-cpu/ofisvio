/* Ofisvio — Canlı düzenleme (faz 59). Yalnız yetkili oturumda yüklenir (composer). JS'ten HTTP çağrısı YOK: seçim/yükleme
   sayfada anında önizlenir, Kaydet tek form gönderimidir (sunucu yetki + sahiplik doğrular). Tarayıcı depolaması yok.
   Hedef: [data-le="kind:id:field[:index]"] görseller (data-le-label, data-le-media, data-le-alt). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var modal = $('[data-le-modal]');
  if (!modal) return;
  var form = $('[data-le-form]', modal);
  var preview = $('[data-le-preview]', modal);
  var titleEl = $('[data-le-title]', modal), scopeEl = $('[data-le-scope]', modal), globalNote = $('[data-le-global]', modal);
  var fileInput = $('[data-le-file]', modal), altInput = $('[data-le-alt]', modal), captionInput = $('[data-le-caption]', modal);
  var saveBtn = $('[data-le-save]', modal), undoBtn = $('[data-le-undo]', modal);
  var tiles = $$('[data-le-tile]', modal);
  var current = null; // { el, originalSrc, originalSrcset, target, chosen: 'media'|'file'|'remove'|null }

  /* ---------- hover etiketi ---------- */
  var tag = document.createElement('div'); tag.className = 'le-tag'; tag.hidden = true; document.body.appendChild(tag);
  document.addEventListener('mousemove', function (e) {
    var el = e.target.closest ? e.target.closest('[data-le]') : null;
    if (!el) { tag.hidden = true; return; }
    tag.innerHTML = '✎ Görseli Değiştir<small>' + esc(el.getAttribute('data-le-label') || '') + '</small>';
    tag.hidden = false;
    tag.style.left = Math.min(window.innerWidth - tag.offsetWidth - 8, e.clientX + 14) + 'px';
    tag.style.top = Math.max(4, e.clientY - tag.offsetHeight - 10) + 'px';
  });

  /* ---------- global alanlar (faz 61a): header/footer → ayar ekranı (tüm sitede uygulanır) ---------- */
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-le]') || e.target.closest('a, button, input, select, textarea, label')) return;
    var area = e.target.closest('[data-le-area]');
    if (!area) return;
    var url = modal.getAttribute('data-le-area-' + area.getAttribute('data-le-area'));
    if (!url) return;
    e.preventDefault();
    if (window.confirm('Bu değişiklik tüm sitede uygulanacaktır. ' + (area.getAttribute('data-le-area-label') || '') + ' ayarlarına gidilsin mi?')) window.location.href = url;
  }, true);
  document.addEventListener('mousemove', function (e) {
    if (e.target.closest('[data-le]')) return;
    var area = e.target.closest('[data-le-area]');
    if (!area) return;
    tag.innerHTML = '✎ Düzenle<small>' + esc(area.getAttribute('data-le-area-label') || '') + '</small>';
    tag.hidden = false;
    tag.style.left = Math.min(window.innerWidth - tag.offsetWidth - 8, e.clientX + 14) + 'px';
    tag.style.top = Math.max(4, e.clientY - tag.offsetHeight - 10) + 'px';
  });

  /* ---------- tıkla → modal ---------- */
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-le]') : null;
    if (!el || modal.contains(el)) return;
    e.preventDefault(); e.stopPropagation();
    open(el);
  }, true);

  function open(el, droppedFile) {
    var target = (el.getAttribute('data-le') || '').split(':');
    if (target.length < 3) return;
    if (current && current.el !== el) restore();
    current = { el: el, originalSrc: el.getAttribute('src'), originalSrcset: el.getAttribute('srcset'), target: target, chosen: null };
    var kind = target[0];
    form.setAttribute('action', form.getAttribute('data-action-' + kind) + (kind === 'website' || kind === 'section' ? '' : '/' + target[1]));
    $('[data-le-id]', form).value = target[1];
    $('[data-le-field]', form).value = target[2];
    $('[data-le-index]', form).value = target[3] !== undefined ? target[3] : '';
    $('[data-le-media-id]', form).value = '';
    $('[data-le-action]', form).value = 'replace';
    fileInput.value = '';
    titleEl.textContent = el.getAttribute('data-le-label') || 'Görsel';
    scopeEl.textContent = 'Hedef: ' + target.join(' › ');
    globalNote.hidden = kind !== 'website';
    altInput.value = ''; altInput.placeholder = el.getAttribute('data-le-alt') || 'Görseli tarif eden kısa metin';
    captionInput.value = '';
    preview.src = el.tagName === 'IMG' ? (el.currentSrc || el.src) : '';
    preview.alt = el.getAttribute('alt') || '';
    var currentId = el.getAttribute('data-le-media') || '';
    tiles.forEach(function (t) { t.classList.toggle('is-selected', currentId !== '' && t.getAttribute('data-id') === currentId); t.hidden = false; });
    $('[data-le-search]', modal).value = '';
    setDirty(false);
    modal.hidden = false;
    if (droppedFile) useFile(droppedFile);
  }

  function setDirty(on) { saveBtn.disabled = !on; undoBtn.disabled = !on; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* ---------- sayfada anında değişim ---------- */
  function applyToPage(src, alt) {
    if (!current) return;
    var el = current.el;
    if (el.tagName === 'IMG') {
      el.removeAttribute('srcset'); el.removeAttribute('sizes');
      el.src = src;
      if (alt !== undefined && alt !== '') el.alt = alt;
    } else {
      // Yer tutucu kutu (görsel yok): önizleme için img'ye çevrilmez, arka plan olarak gösterilir.
      el.style.backgroundImage = 'url("' + src + '")'; el.style.backgroundSize = 'cover'; el.style.backgroundPosition = 'center'; el.textContent = '';
    }
    preview.src = src;
  }
  function restore() {
    if (!current) return;
    var el = current.el;
    if (el.tagName === 'IMG') {
      if (current.originalSrc !== null) el.src = current.originalSrc;
      if (current.originalSrcset) el.setAttribute('srcset', current.originalSrcset);
    } else { el.style.backgroundImage = ''; }
    el.style.opacity = '';
  }

  /* ---------- kütüphaneden seç ---------- */
  tiles.forEach(function (t) {
    t.addEventListener('click', function () {
      tiles.forEach(function (x) { x.classList.remove('is-selected'); });
      t.classList.add('is-selected');
      $('[data-le-media-id]', form).value = t.getAttribute('data-id');
      $('[data-le-action]', form).value = 'replace';
      fileInput.value = '';
      if (current) current.chosen = 'media';
      if (altInput.value === '' && t.getAttribute('data-alt')) altInput.value = t.getAttribute('data-alt');
      applyToPage(t.getAttribute('data-url'), altInput.value || t.getAttribute('data-alt'));
      if (current) current.el.style.opacity = '';
      setDirty(true);
    });
  });
  $('[data-le-search]', modal).addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    tiles.forEach(function (t) { t.hidden = q !== '' && ((t.getAttribute('data-alt') || '') + ' ' + (t.getAttribute('data-name') || '')).toLowerCase().indexOf(q) === -1; });
  });

  /* ---------- yükle / sürükle-bırak ---------- */
  function useFile(file) {
    if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) return;
    var dt = new DataTransfer(); dt.items.add(file); fileInput.files = dt.files;
    $('[data-le-media-id]', form).value = '';
    $('[data-le-action]', form).value = 'replace';
    tiles.forEach(function (x) { x.classList.remove('is-selected'); });
    if (current) current.chosen = 'file';
    var reader = new FileReader();
    reader.onload = function () { applyToPage(String(reader.result), altInput.value); setDirty(true); };
    reader.readAsDataURL(file);
  }
  fileInput.addEventListener('change', function () { useFile(fileInput.files[0]); });
  var drop = $('[data-le-drop]', modal);
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) { useFile(e.dataTransfer.files[0]); });

  // Sayfadaki görselin üzerine doğrudan bırakma: onay → modal + dosya hazır.
  document.addEventListener('dragover', function (e) {
    var el = e.target.closest ? e.target.closest('[data-le]') : null;
    if (!el || modal.contains(el)) return;
    e.preventDefault(); el.classList.add('le-dropping');
  });
  document.addEventListener('dragleave', function (e) { var el = e.target.closest ? e.target.closest('[data-le]') : null; if (el) el.classList.remove('le-dropping'); });
  document.addEventListener('drop', function (e) {
    var el = e.target.closest ? e.target.closest('[data-le]') : null;
    if (!el || modal.contains(el)) return;
    e.preventDefault(); el.classList.remove('le-dropping');
    var file = e.dataTransfer.files && e.dataTransfer.files[0];
    if (!file) return;
    if (window.confirm('Bu görseli değiştir?\n' + (el.getAttribute('data-le-label') || ''))) open(el, file);
  });

  /* ---------- kaldır / geri al / iptal / kaydet ---------- */
  $('[data-le-remove]', modal).addEventListener('click', function () {
    if (!current) return;
    $('[data-le-action]', form).value = 'remove';
    $('[data-le-media-id]', form).value = ''; fileInput.value = '';
    current.chosen = 'remove'; current.el.style.opacity = '.25';
    setDirty(true);
  });
  undoBtn.addEventListener('click', function () { restore(); if (current) current.chosen = null; $('[data-le-media-id]', form).value = ''; fileInput.value = ''; $('[data-le-action]', form).value = 'replace'; tiles.forEach(function (x) { x.classList.remove('is-selected'); }); preview.src = current ? (current.originalSrc || '') : ''; setDirty(false); });
  function close() { restore(); modal.hidden = true; current = null; }
  $('[data-le-cancel]', modal).addEventListener('click', close);
  $('[data-le-close]', modal).addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
  form.addEventListener('submit', function (e) {
    if (!current || !current.chosen) { e.preventDefault(); return; }
    if (current.chosen === 'remove' && !window.confirm('Görsel bu alandan kaldırılsın mı? (Kütüphaneden silinmez.)')) { e.preventDefault(); return; }
    saveBtn.disabled = true; saveBtn.textContent = 'Kaydediliyor…';
  });
})();
