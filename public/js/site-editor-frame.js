/* Ofisvio görsel editör — ÇERÇEVE betiği (faz 49). Yalnız imzalı ?editor=1 önizlemede yüklenir; canlı vitrine gitmez.
   Görev: bölüm/alan/görsel işaretlerini (data-ofv-*) etkileşimli yapmak — hover/seçim çerçevesi, hızlı araç çubuğu,
   inline metin düzenleme, bırakma göstergeleri, dosya bırakma. Durum ve karar üst pencerede (site-editor.js);
   iletişim aynı kökende doğrudan window.parent.OfisvioEditor üzerinden. HTTP çağrısı yok. */
(function () {
    'use strict';

    var parentApi = null;
    try { parentApi = window.parent && window.parent.OfisvioEditor ? window.parent.OfisvioEditor : null; } catch (e) { parentApi = null; }
    if (!parentApi) return;

    var doc = document;
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); };

    /* ---------- Stil: yalnız editör çerçevesinde ---------- */
    var style = doc.createElement('style');
    style.textContent = [
        '[data-ofv-section]{position:relative;outline:1px dashed transparent;outline-offset:-1px;transition:outline-color .12s}',
        '[data-ofv-section].ofv-hover{outline-color:rgba(37,99,235,.55)}',
        '[data-ofv-section].ofv-selected{outline:2px solid #2563eb;outline-offset:-2px}',
        '[data-ofv-section].ofv-invisible{opacity:.35}',
        '[data-ofv-section].ofv-invisible::after{content:"gizli";position:absolute;top:8px;right:8px;background:#111;color:#fff;font:600 11px/1 sans-serif;padding:4px 7px;border-radius:999px}',
        '[data-ofv-field],[data-ofv-global],[data-ofv-brand]{cursor:text;outline:1px dashed transparent;outline-offset:2px;border-radius:2px}',
        '[data-ofv-field]:hover,[data-ofv-global]:hover{outline-color:rgba(37,99,235,.5)}',
        '[data-ofv-field].ofv-editing,[data-ofv-global].ofv-editing{outline:2px solid #2563eb;background:rgba(37,99,235,.06)}',
        '[data-ofv-image],[data-ofv-site-image],[data-ofv-md]{cursor:pointer;outline:1px dashed transparent;outline-offset:2px}',
        '[data-ofv-image]:hover,[data-ofv-site-image]:hover,[data-ofv-md]:hover{outline-color:rgba(37,99,235,.5)}',
        '[data-ofv-image].ofv-dropover{outline:3px solid #16a34a!important}',
        '[data-ofv-global-area]{outline:1px dashed transparent;outline-offset:-1px}[data-ofv-global-area]:hover{outline-color:rgba(37,99,235,.35)}',
        '[data-ofv-item]{cursor:text;outline:1px dashed transparent;outline-offset:2px}[data-ofv-item]:hover{outline-color:rgba(37,99,235,.45)}',
        '[data-ofv-global-area].ofv-selected{outline:2px solid #2563eb;outline-offset:-2px}',
        '.ofv-toolbar{position:absolute;top:6px;left:6px;z-index:2147483000;display:flex;gap:2px;background:#111827;color:#fff;border-radius:8px;padding:3px;font:12px/1 sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25)}',
        '.ofv-toolbar span{padding:5px 8px;opacity:.7;font-weight:600}',
        '.ofv-toolbar button{border:0;background:transparent;color:#fff;padding:5px 8px;border-radius:5px;cursor:pointer;font:inherit}',
        '.ofv-toolbar button:hover{background:#2563eb}.ofv-toolbar button[disabled]{opacity:.35;cursor:not-allowed}',
        '.ofv-dropline{height:0;position:relative}.ofv-dropline::before{content:"";position:absolute;left:0;right:0;top:-2px;height:4px;background:#2563eb;border-radius:2px;box-shadow:0 0 0 3px rgba(37,99,235,.2)}',
        '.ofv-textbar{position:absolute;z-index:2147483001;display:flex;gap:2px;background:#111827;color:#fff;border-radius:8px;padding:3px;font:12px/1 sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25)}',
        '.ofv-textbar button{border:0;background:transparent;color:#fff;padding:5px 7px;border-radius:5px;cursor:pointer;font:inherit;min-width:26px}.ofv-textbar button:hover{background:#2563eb}',
        '.ofv-textbar select{background:#1f2937;color:#fff;border:0;border-radius:5px;font:inherit;padding:3px 4px}',
        'html.ofv-dragging [data-ofv-section]{outline-color:rgba(37,99,235,.35)}',
        '.ofv-empty{margin:24px auto;max-width:720px;border:2px dashed #cbd5e1;border-radius:14px;padding:40px;text-align:center;color:#64748b;font:15px/1.5 sans-serif}'
    ].join('');
    doc.head.appendChild(style);
    doc.documentElement.setAttribute('data-ofv-frame', '1');

    var container = doc.querySelector('[data-ofv-sections]');
    if (container && !container.children.length) { var e = doc.createElement('div'); e.className = 'ofv-empty'; e.textContent = 'Sayfa boş — soldaki bloklardan sürükleyip bırakın.'; container.appendChild(e); }

    /* ---------- Hızlı araç çubuğu (hover) ---------- */
    var toolbar = doc.createElement('div');
    toolbar.className = 'ofv-toolbar';
    toolbar.innerHTML = '<span data-ofv-label></span><button type="button" data-act="edit" title="Düzenle">Düzenle</button><button type="button" data-act="up" title="Yukarı taşı">↑</button><button type="button" data-act="down" title="Aşağı taşı">↓</button><button type="button" data-act="duplicate" title="Kopyala">⧉</button><button type="button" data-act="toggle" title="Gizle/Göster">◉</button><button type="button" data-act="settings" title="Ayarlar">⚙</button><button type="button" data-act="delete" title="Sil">×</button>';
    toolbar.hidden = true;
    doc.body.appendChild(toolbar);
    var hovered = null;

    function showToolbar(sec) {
        hovered = sec;
        var locked = sec.hasAttribute('data-ofv-locked');
        toolbar.querySelector('[data-ofv-label]').textContent = parentApi.labelFor(sec.getAttribute('data-ofv-section'), sec.getAttribute('data-ofv-type'));
        $$('button', toolbar).forEach(function (b) { b.disabled = locked && b.getAttribute('data-act') !== 'edit' && b.getAttribute('data-act') !== 'settings'; });
        var r = sec.getBoundingClientRect();
        toolbar.style.top = (r.top + window.scrollY + 6) + 'px';
        toolbar.style.left = (r.left + window.scrollX + 6) + 'px';
        toolbar.hidden = false;
    }
    doc.addEventListener('mouseover', function (e) {
        var sec = e.target.closest ? e.target.closest('[data-ofv-section]') : null;
        $$('[data-ofv-section].ofv-hover').forEach(function (s) { if (s !== sec) s.classList.remove('ofv-hover'); });
        if (sec) { sec.classList.add('ofv-hover'); if (hovered !== sec) showToolbar(sec); }
    });
    doc.addEventListener('mouseleave', function () { toolbar.hidden = true; hovered = null; });
    toolbar.addEventListener('mouseenter', function () { if (hovered) hovered.classList.add('ofv-hover'); });
    toolbar.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b || !hovered) return;
        e.preventDefault(); e.stopPropagation();
        parentApi.sectionAction(hovered.getAttribute('data-ofv-section'), b.getAttribute('data-act'));
    });

    /* ---------- Seçim ---------- */
    doc.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest('.ofv-toolbar, .ofv-textbar')) return;
        // Bağlantılar/form gönderimi editörde devre dışı (sayfadan kopma yok)
        var a = t.closest('a, button[type="submit"], input[type="submit"]');
        if (a && !t.closest('[contenteditable="true"]')) e.preventDefault();

        var field = t.closest('[data-ofv-field], [data-ofv-global], [data-ofv-site-field], [data-ofv-item]');
        var img = t.closest('[data-ofv-image], [data-ofv-site-image]');
        var md = t.closest('[data-ofv-md]');
        var sec = t.closest('[data-ofv-section]');
        var area = t.closest('[data-ofv-global-area]');

        $$('.ofv-selected').forEach(function (s) { s.classList.remove('ofv-selected'); });
        if (sec) sec.classList.add('ofv-selected'); else if (area) area.classList.add('ofv-selected');

        if (field) { startEdit(field); parentApi.select({ kind: 'field', section: sec ? sec.getAttribute('data-ofv-section') : null, field: field.getAttribute('data-ofv-field') || (field.getAttribute('data-ofv-item') || '').split(':')[0] || null, global: field.getAttribute('data-ofv-global'), site: field.getAttribute('data-ofv-site-field'), area: area ? area.getAttribute('data-ofv-global-area') : null }); return; }
        stopEdit();
        if (img) { parentApi.select({ kind: 'image', section: sec ? sec.getAttribute('data-ofv-section') : null, field: img.getAttribute('data-ofv-image'), site: img.getAttribute('data-ofv-site-image'), mediaId: img.getAttribute('data-ofv-media-id') }); return; }
        if (md) { parentApi.select({ kind: 'markdown', section: sec.getAttribute('data-ofv-section'), field: md.getAttribute('data-ofv-md') }); return; }
        if (sec) { parentApi.select({ kind: 'section', section: sec.getAttribute('data-ofv-section') }); return; }
        if (area) { parentApi.select({ kind: 'global', area: area.getAttribute('data-ofv-global-area') }); return; }
        parentApi.select(null);
    }, true);
    doc.addEventListener('submit', function (e) { e.preventDefault(); }, true);

    /* ---------- Inline metin düzenleme ---------- */
    var editing = null;
    var textbar = doc.createElement('div');
    textbar.className = 'ofv-textbar';
    textbar.innerHTML = '<button type="button" data-ts="weight" data-val="700" title="Kalın"><b>B</b></button><button type="button" data-ts="italic" title="İtalik"><i>I</i></button><button type="button" data-ts="align" data-val="left" title="Sola">≡</button><button type="button" data-ts="align" data-val="center" title="Ortala">☰</button><button type="button" data-ts="align" data-val="right" title="Sağa">≡</button><select data-ts="size" title="Boyut"><option value="">Boyut</option><option>14</option><option>16</option><option>18</option><option>22</option><option>28</option><option>36</option><option>44</option><option>56</option></select><input type="color" data-ts="color" title="Renk" style="width:26px;height:24px;border:0;background:none;padding:0"><button type="button" data-ts="link" title="Bağlantı / CTA (sağ panel)">🔗</button><button type="button" data-ts="more" title="Satır yüksekliği, harf aralığı, yazı tipi (sağ panel)">…</button>';
    textbar.hidden = true;
    doc.body.appendChild(textbar);
    textbar.addEventListener('mousedown', function (e) { e.preventDefault(); });
    textbar.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b || !editing) return;
        var key = b.getAttribute('data-ts');
        if (key === 'link' || key === 'more') { parentApi.focusStyle(); return; }
        var val = key === 'italic' ? 'toggle' : b.getAttribute('data-val');
        parentApi.fieldStyle(currentRef(), key, val);
    });
    textbar.addEventListener('change', function (e) {
        var el = e.target; if (!editing) return;
        parentApi.fieldStyle(currentRef(), el.getAttribute('data-ts'), el.value);
        if (el.tagName === 'SELECT') el.value = '';
    });
    function currentRef() {
        var sec = editing.closest('[data-ofv-section]');
        return { section: sec ? sec.getAttribute('data-ofv-section') : null, field: editing.getAttribute('data-ofv-field'), global: editing.getAttribute('data-ofv-global') };
    }
    function startEdit(el) {
        if (editing === el) return;
        stopEdit();
        editing = el;
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('spellcheck', 'false');
        el.classList.add('ofv-editing');
        el.focus();
        var r = el.getBoundingClientRect();
        textbar.style.top = Math.max(4, r.top + window.scrollY - 38) + 'px';
        textbar.style.left = (r.left + window.scrollX) + 'px';
        textbar.hidden = !el.hasAttribute('data-ofv-field') && !el.hasAttribute('data-ofv-global') && !el.hasAttribute('data-ofv-site-field') && !el.hasAttribute('data-ofv-item');
        if (el.hasAttribute('data-ofv-site-field') || el.hasAttribute('data-ofv-item')) { $$('[data-ts]', textbar).forEach(function (b) { b.hidden = true; }); }
        else if (el.hasAttribute('data-ofv-global') && !el.hasAttribute('data-ofv-field')) { $$('[data-ts]', textbar).forEach(function (b) { b.hidden = b.getAttribute('data-ts') !== 'link'; }); } else { $$('[data-ts]', textbar).forEach(function (b) { b.hidden = false; }); }
    }
    function stopEdit() {
        if (!editing) return;
        editing.removeAttribute('contenteditable');
        editing.classList.remove('ofv-editing');
        editing = null;
        textbar.hidden = true;
    }
    doc.addEventListener('input', function (e) {
        var el = e.target.closest ? e.target.closest('[contenteditable="true"]') : null;
        if (!el || el !== editing) return;
        var sec = el.closest('[data-ofv-section]');
        // innerText CSS text-transform'u (eyebrow: uppercase) uygular; dönüştürülmüş öğede ham metin (textContent) alınır.
        var raw = window.getComputedStyle(el).textTransform !== 'none' ? el.textContent : el.innerText;
        parentApi.fieldInput({ section: sec ? sec.getAttribute('data-ofv-section') : null, field: el.getAttribute('data-ofv-field'), global: el.getAttribute('data-ofv-global'), site: el.getAttribute('data-ofv-site-field'), item: el.getAttribute('data-ofv-item'), value: raw.replace(/\n{2,}/g, '\n').trim() });
    });
    doc.addEventListener('keydown', function (e) {
        if (!editing) return;
        if (e.key === 'Escape') { e.preventDefault(); stopEdit(); }
        if (e.key === 'Enter' && !e.shiftKey && editing.tagName !== 'P' && editing.tagName !== 'DIV') { e.preventDefault(); editing.blur(); stopEdit(); }
    });
    doc.addEventListener('paste', function (e) {
        if (!editing) return;
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain');
        doc.execCommand('insertText', false, text);
    });

    /* ---------- Sürükle-bırak: palet/katman → bırakma çizgisi; dosya → görsel ---------- */
    var dropline = doc.createElement('div'); dropline.className = 'ofv-dropline';
    var dropIndex = null;
    function sections() { return $$('[data-ofv-sections] > [data-ofv-section]'); }
    function placeDropline(y) {
        var list = sections(), idx = list.length;
        for (var i = 0; i < list.length; i++) { var r = list[i].getBoundingClientRect(); if (y < r.top + r.height / 2) { idx = i; break; } }
        dropIndex = idx;
        if (!container) return;
        if (idx >= list.length) container.appendChild(dropline); else container.insertBefore(dropline, list[idx]);
    }
    doc.addEventListener('dragover', function (e) {
        var types = e.dataTransfer ? Array.prototype.slice.call(e.dataTransfer.types || []) : [];
        var img = e.target.closest ? e.target.closest('[data-ofv-image], [data-ofv-site-image]') : null;
        if (types.indexOf('Files') !== -1) {
            $$('.ofv-dropover').forEach(function (x) { if (x !== img) x.classList.remove('ofv-dropover'); });
            if (img) { img.classList.add('ofv-dropover'); e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; }
            return;
        }
        if (types.indexOf('text/ofv') !== -1 || types.indexOf('text/ofv-move') !== -1) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; doc.documentElement.classList.add('ofv-dragging'); placeDropline(e.clientY); }
    });
    doc.addEventListener('dragleave', function (e) { if (e.target === doc.documentElement || e.target === doc.body) { if (dropline.parentNode) dropline.parentNode.removeChild(dropline); } });
    doc.addEventListener('drop', function (e) {
        var dt = e.dataTransfer; if (!dt) return;
        doc.documentElement.classList.remove('ofv-dragging');
        var img = e.target.closest ? e.target.closest('[data-ofv-image], [data-ofv-site-image]') : null;
        if (dt.files && dt.files.length && img) {
            e.preventDefault();
            img.classList.remove('ofv-dropover');
            var sec = img.closest('[data-ofv-section]');
            parentApi.imageDrop({ section: sec ? sec.getAttribute('data-ofv-section') : null, field: img.getAttribute('data-ofv-image'), site: img.getAttribute('data-ofv-site-image'), mediaId: img.getAttribute('data-ofv-media-id'), file: dt.files[0] });
            return;
        }
        var add = dt.getData('text/ofv'), move = dt.getData('text/ofv-move');
        if (dropline.parentNode) dropline.parentNode.removeChild(dropline);
        if (add) { e.preventDefault(); parentApi.addAt(add, dropIndex); }
        else if (move) { e.preventDefault(); parentApi.moveTo(move, dropIndex); }
        dropIndex = null;
    });
    doc.addEventListener('dragend', function () { doc.documentElement.classList.remove('ofv-dragging'); if (dropline.parentNode) dropline.parentNode.removeChild(dropline); });

    /* ---------- Bölüm sürükleme (çerçeve içinden taşıma) ---------- */
    sections().forEach(function (sec) { sec.setAttribute('draggable', 'true'); });
    doc.addEventListener('dragstart', function (e) {
        var sec = e.target.closest ? e.target.closest('[data-ofv-section]') : null;
        if (!sec || editing) { if (editing) e.preventDefault(); return; }
        if (sec.hasAttribute('data-ofv-locked')) { e.preventDefault(); return; }
        e.dataTransfer.setData('text/ofv-move', sec.getAttribute('data-ofv-section'));
        e.dataTransfer.effectAllowed = 'move';
    });

    /* ---------- SEO gerçekleri (sayfadan) ---------- */
    function seoFacts() {
        var h1 = $$('main h1').length, hs = $$('main h2, main h3, main h4').map(function (h) { return parseInt(h.tagName.substring(1), 10); });
        var jump = false, prev = 1; hs.forEach(function (l) { if (l > prev + 1) jump = true; prev = l; });
        var imgs = $$('main img'), noAlt = imgs.filter(function (i) { return !(i.getAttribute('alt') || '').trim(); }).length;
        var links = $$('main a[href]').filter(function (a) { var h = a.getAttribute('href') || ''; return h.indexOf('/') === 0 && h.indexOf('//') !== 0; }).length;
        var words = ((doc.querySelector('main') || doc.body).innerText.match(/\p{L}+/gu) || []).length;
        var desc = doc.querySelector('meta[name="description"]');
        var schema = $$('script[type="application/ld+json"]').map(function (s) { try { var j = JSON.parse(s.textContent); return (j['@graph'] ? j['@graph'] : [j]).map(function (n) { return n['@type']; }); } catch (e) { return []; } }).reduce(function (a, b) { return a.concat(b); }, []);
        return { title: doc.title, description: desc ? desc.getAttribute('content') : '', h1: h1, headings: hs.length, jump: jump, images: imgs.length, noAlt: noAlt, internalLinks: links, words: words, schema: schema, canonical: (doc.querySelector('link[rel="canonical"]') || {}).href || '', robots: (doc.querySelector('meta[name="robots"]') || { getAttribute: function () { return ''; } }).getAttribute('content') || '' };
    }

    /* ---------- Üst pencerenin çağırdığı API ---------- */
    window.OfisvioFrame = {
        sections: sections,
        container: function () { return container; },
        templates: function () { var m = {}; $$('template[data-ofv-template]').forEach(function (t) { m[t.getAttribute('data-ofv-template')] = t; }); return m; },
        stopEdit: stopEdit,
        seoFacts: seoFacts,
        select: function (id) { $$('.ofv-selected').forEach(function (s) { s.classList.remove('ofv-selected'); }); var sec = doc.querySelector('[data-ofv-section="' + id + '"]'); if (sec) { sec.classList.add('ofv-selected'); sec.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } },
        clearEmpty: function () { var e = doc.querySelector('.ofv-empty'); if (e) e.parentNode.removeChild(e); },
        makeDraggable: function (sec) { sec.setAttribute('draggable', 'true'); }
    };
    parentApi.frameReady(window);
})();
