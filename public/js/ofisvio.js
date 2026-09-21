/* Ofisvio — vitrin etkileşimleri
 *
 * Bağımlılık yok. Alpine/Vue eklemek npm zinciri gerektirirdi; bu kadarlık
 * etkileşim için sade JS yeterli ve site derleme adımı olmadan çalışıyor.
 *
 * İlke: JavaScript KAPALIYKEN de sayfa kullanılabilir olmalı. Filtreler
 * yalnızca görünürlük daraltır (tüm lokasyonlar sunucudan basılır), formlar
 * gerçek POST yapar. JS sadece deneyimi iyileştirir.
 */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- gezinme ---------- */
  function initNav() {
    var toggles = $$('[data-nav-toggle]');
    var panel = $('[data-nav-panel]');
    if (!panel || !toggles.length) return;

    function setOpen(open) {
      panel.hidden = !open;
      toggles.forEach(function (t) { t.setAttribute('aria-expanded', String(open)); });
    }

    toggles.forEach(function (t) {
      t.addEventListener('click', function () {
        setOpen(panel.hidden);
      });
    });

    $$('[data-nav-panel] a').forEach(function (a) {
      a.addEventListener('click', function () { setOpen(false); });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { setOpen(false); toggles[0].focus(); }
    });

    setOpen(false);
  }

  /* ---------- hero süzgeci → teklif formu (lokasyon sayısından bağımsız) ----------
     Tek lokasyon: bölge alanı yok, şube gizli alanda (data-hero-location) — kullanıcıya seçim yaptırılmaz.
     Çoklu: bölgede tek şube varsa teklif formunun lokasyonu da o olur. Çözüm/Kişi her iki modda forma akar. */
  function initHeroFilter() {
    var panel = $('[data-hero-filter]');
    if (!panel) return;
    var typeSelect = $('[data-filter-type]', panel);
    var teamSelect = $('[data-filter-team]', panel);
    var regionSelect = $('[data-filter-region]', panel);
    var formSolution = $('[data-form-solution]');
    var formTeam = $('[data-form-team]');
    var formLocation = $('[data-form-location]');
    var single = panel.getAttribute('data-hero-location');

    if (single && formLocation) formLocation.value = single;
    if (teamSelect && formTeam) teamSelect.addEventListener('change', function () { formTeam.value = teamSelect.value; });
    if (typeSelect && formSolution) typeSelect.addEventListener('change', function () { if (typeSelect.value !== 'Tümü') formSolution.value = typeSelect.value; });
    if (regionSelect && formLocation && formLocation.tagName === 'SELECT') {
      regionSelect.addEventListener('change', function () {
        var opt = regionSelect.options[regionSelect.selectedIndex];
        var ids = (opt && opt.getAttribute('data-location-ids') || '').split(',').filter(Boolean);
        formLocation.value = ids.length === 1 ? ids[0] : '';
      });
    }
  }

  /* ---------- lokasyon filtresi (yalnız çoklu lokasyon: kart listesi varsa) ---------- */
  function initLocations() {
    var grid = $('[data-locations]');
    if (!grid) return;

    var cards = $$('[data-location]', grid);
    var tabs = $$('[data-region-tab]');
    var empty = $('[data-locations-empty]');
    var count = $('[data-match-line]');
    var regionSelect = $('[data-filter-region]');
    var typeSelect = $('[data-filter-type]');
    var teamSelect = $('[data-filter-team]');
    var applyBtn = $('[data-filter-apply]');

    var state = { region: 'Tümü', type: 'Tümü' };

    function apply() {
      var visible = 0;

      cards.forEach(function (card) {
        var region = card.getAttribute('data-region') || '';
        var tags = (card.getAttribute('data-tags') || '').split('|');
        var okRegion = state.region === 'Tümü' || region === state.region;
        var okType = state.type === 'Tümü' || tags.indexOf(state.type) > -1;
        var show = okRegion && okType;
        card.hidden = !show;
        if (show) visible++;
      });

      if (empty) empty.hidden = visible > 0;

      if (count) {
        var parts = [visible + ' lokasyon'];
        parts.push(state.region === 'Tümü' ? 'tüm bölgeler' : state.region);
        if (state.type !== 'Tümü') parts.push(state.type);
        count.textContent = parts.join(' · ');
      }

      tabs.forEach(function (tab) {
        tab.setAttribute('aria-pressed', String(tab.getAttribute('data-region-tab') === state.region));
      });
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        state.region = tab.getAttribute('data-region-tab');
        if (regionSelect) regionSelect.value = state.region;
        apply();
      });
    });

    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        if (regionSelect) state.region = regionSelect.value;
        if (typeSelect) state.type = typeSelect.value;
        apply();
      });
    }

    // Ekip büyüklüğü/çözüm forma aktarımı initHeroFilter'da (her iki modda).
    apply();
  }

  /* ---------- rezervasyon seçici ---------- */
  function initBooking() {
    var root = $('[data-booking]');
    if (!root) return;

    var dateInput = $('[data-booking-date]', root);
    var slotInput = $('[data-booking-slot]', root);
    var summary = $('[data-booking-summary]', root);
    var dayBtns = $$('[data-booking-day]', root);
    var slotBtns = $$('[data-booking-slot-btn]', root);
    var locSelect = $('[data-booking-loc]', root);

    function press(list, activeEl) {
      list.forEach(function (b) { b.setAttribute('aria-pressed', String(b === activeEl)); });
    }

    // Gizli alan sunucuya ISO tarih gonderir; ozet metninde ise kullaniciya
    // dugmenin uzerindeki insan okunur etiket gosterilir ("Bugün 15.09").
    var dayLabel = '';

    function refreshSummary() {
      if (!summary) return;
      var loc = locSelect ? locSelect.options[locSelect.selectedIndex].text : '';
      var slot = slotInput ? slotInput.value : '';
      summary.textContent = loc + ' · ' + dayLabel + ' ' + slot +
        ' · 1 saat · ön talep, uygunluk teyidi e-posta ile gelir';
    }

    dayBtns.forEach(function (btn) {
      if (btn.getAttribute('aria-pressed') === 'true') {
        dayLabel = btn.getAttribute('data-day-label') || '';
      }
      btn.addEventListener('click', function () {
        if (dateInput) dateInput.value = btn.getAttribute('data-booking-day');
        dayLabel = btn.getAttribute('data-day-label') || '';
        press(dayBtns, btn);
        refreshSummary();
      });
    });

    slotBtns.forEach(function (btn) {
      if (btn.disabled) return;
      btn.addEventListener('click', function () {
        if (slotInput) slotInput.value = btn.getAttribute('data-booking-slot-btn');
        press(slotBtns, btn);
        refreshSummary();
      });
    });

    if (locSelect) locSelect.addEventListener('change', refreshSummary);

    refreshSummary();
  }

  /* ---------- çözüm kartından forma aktarım ---------- */
  function initSolutionPrefill() {
    $$('[data-solution-pick]').forEach(function (el) {
      el.addEventListener('click', function () {
        var select = $('[data-form-solution]');
        if (select) select.value = el.getAttribute('data-solution-pick');
      });
    });
  }

  /* ---------- gönderim sırasında çift tıklamayı engelle ---------- */
  /* ---------- harita (tıklayınca yükle) ---------- */
  function initMap() {
    $$('[data-map-load]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = document.getElementById(btn.getAttribute('data-map-target'));
        if (!target) return;
        var frame = document.createElement('iframe');
        frame.src = btn.getAttribute('data-map-src');
        frame.title = 'Harita';
        frame.loading = 'lazy';
        frame.referrerPolicy = 'no-referrer';
        frame.style.cssText = 'width:100%;height:100%;border:0';
        target.innerHTML = '';
        target.appendChild(frame);
        target.hidden = false;
        btn.hidden = true;
      });
    });
  }

  function initForms() {
    $$('form[data-guard]').forEach(function (form) {
      form.addEventListener('submit', function () {
        var btn = form.querySelector('[type="submit"]');
        if (!btn) return;
        // disabled bir düğme değer göndermez; bu yüzden sadece görsel kilit.
        setTimeout(function () {
          btn.setAttribute('aria-busy', 'true');
          btn.style.pointerEvents = 'none';
          btn.style.opacity = '.72';
        }, 0);
      });
    });
  }

  /* ---------- surukle-birak siralama (sayfa kurucu, lokasyon gorselleri) ---------- */
  // Kok: [data-sortable] (form DEGIL; ic ice form olmasin diye). Kokun data-sortable-form
  // niteligi, gizli "order" alanini tasiyan formun id'sidir. Birakinca alan guncellenir ve
  // form gonderilir; sunucu siralamayi dogrular ve yazar (JS yalniz UX).
  function initSortable() {
    $$("[data-sortable]").forEach(function (root) {
      var form = document.getElementById(root.getAttribute("data-sortable-form") || "");
      var list = $("[data-sortable-list]", root);
      var order = form ? $("[data-sortable-order]", form) : null;
      if (!form || !list || !order) return;
      var dragging = null;

      $$("[data-sortable-item]", list).forEach(function (item) {
        item.addEventListener("dragstart", function (e) {
          dragging = item;
          item.style.opacity = ".4";
          if (e.dataTransfer) { e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", item.getAttribute("data-sortable-item")); }
        });
        item.addEventListener("dragend", function () { item.style.opacity = ""; dragging = null; });
        item.addEventListener("dragover", function (e) {
          if (!dragging || dragging === item) return;
          e.preventDefault();
          var rect = item.getBoundingClientRect();
          var after = (e.clientY - rect.top) > rect.height / 2;
          list.insertBefore(dragging, after ? item.nextSibling : item);
        });
      });

      list.addEventListener("drop", function (e) {
        e.preventDefault();
        var ids = $$("[data-sortable-item]", list).map(function (el) { return el.getAttribute("data-sortable-item"); });
        if (ids.join(",") === order.value) return;
        order.value = ids.join(",");
        form.submit();
      });
    });
  }
  // CSP nonce (audit F-11): satır içi onchange yok; data-autosubmit değişince formu gönderir.
  function initDeclarative() {
    document.addEventListener('change', function (e) {
      var el = e.target;
      if (el && el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) { el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit(); }
    });
  }

  function boot() {
    initDeclarative();
    initNav();
    initHeroFilter();
    initLocations();
    initBooking();
    initSolutionPrefill();
    initMap();
    initForms();
    initSortable();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
