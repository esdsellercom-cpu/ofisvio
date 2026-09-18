/* Ofisvio — Header/Footer ayar formu (faz 61a): tekrarlı satırlar (+ ekle / sil), sürükle-bırak sıralama, alt öğeler.
   Bağımlılık yok, HTTP çağrısı yok; form sıradan POST ile gider. İndeksler gönderimden önce yeniden numaralanır. */
(function () {
  'use strict';
  var root = document.querySelector('[data-chrome-form]');
  if (!root) return;

  function reindex(list) {
    var base = list.getAttribute('data-name');
    Array.prototype.forEach.call(list.children, function (row, i) {
      if (!row.matches('[data-row]')) return;
      row.querySelectorAll('[name]').forEach(function (input) {
        var suffix = input.getAttribute('data-field');
        if (!suffix) return;
        var childList = input.closest('[data-list]');
        if (childList && childList !== list) return; // alt liste kendi reindex'inde
        input.name = base + '[' + i + ']' + suffix;
      });
      row.querySelectorAll('[data-list]').forEach(function (child) {
        child.setAttribute('data-name', base + '[' + i + ']' + child.getAttribute('data-child'));
        reindex(child);
      });
    });
  }

  function reindexAll() { root.querySelectorAll('[data-list]:not([data-child-list])').forEach(reindex); }

  root.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add]');
    if (add) {
      e.preventDefault();
      var list = root.querySelector('[data-list="' + add.getAttribute('data-add') + '"]') || add.closest('[data-row]').querySelector('[data-list]');
      if (add.hasAttribute('data-add-child')) list = add.closest('[data-row]').querySelector('[data-list][data-child]');
      var tpl = document.getElementById(list.getAttribute('data-template'));
      if (!tpl) return;
      var frag = tpl.content.cloneNode(true);
      list.appendChild(frag);
      reindexAll();
      var first = list.lastElementChild && list.lastElementChild.querySelector('input,textarea,select');
      if (first) first.focus();
      return;
    }
    var remove = e.target.closest('[data-remove]');
    if (remove) {
      e.preventDefault();
      var row = remove.closest('[data-row]');
      if (row && window.confirm('Bu satır kaldırılsın mı?')) { row.remove(); reindexAll(); }
    }
  });

  // Sürükle-bırak: satır tutamağı; HTML5 DnD.
  var dragging = null;
  root.addEventListener('dragstart', function (e) {
    var handle = e.target.closest('[data-handle]');
    if (!handle) return;
    dragging = handle.closest('[data-row]');
    dragging.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
  });
  root.addEventListener('dragover', function (e) {
    if (!dragging) return;
    var over = e.target.closest('[data-row]');
    if (!over || over === dragging || over.parentNode !== dragging.parentNode) return;
    e.preventDefault();
    var rect = over.getBoundingClientRect();
    var before = (e.clientY - rect.top) < rect.height / 2;
    over.parentNode.insertBefore(dragging, before ? over : over.nextSibling);
  });
  root.addEventListener('dragend', function () {
    if (dragging) { dragging.classList.remove('is-dragging'); dragging = null; reindexAll(); }
  });
  root.querySelectorAll('[data-handle]').forEach(function (h) { h.setAttribute('draggable', 'true'); });
  new MutationObserver(function () { root.querySelectorAll('[data-handle]:not([draggable])').forEach(function (h) { h.setAttribute('draggable', 'true'); }); }).observe(root, { childList: true, subtree: true });

  root.addEventListener('submit', reindexAll);
  reindexAll();
})();
