/* Ofisvio görsel site editörü — ÜST PENCERE (faz 49). Bağımlılıksız. Durum (bölümler + global metinler) bellekte,
   gerçek vitrin aynı kökenli imzalı iframe'de; DOM doğrudan güncellenir (sunucu çağrısı yok). Kaydet = tek form
   gönderimi (payload JSON + bırakılan görseller). Undo/redo anlık görüntü yığını. Tarayıcı depolaması kullanılmaz. */
(function () {
    'use strict';

    var root = document.querySelector('[data-ve]');
    if (!root) return;
    var cfg = JSON.parse(root.getAttribute('data-config'));
    var $ = function (sel, r) { return (r || root).querySelector(sel); };
    var $$ = function (sel, r) { return Array.prototype.slice.call((r || root).querySelectorAll(sel)); };
    var LIB = cfg.library, TEXT_KEYS = cfg.textKeys, MEDIA = cfg.media || [], PRESETS = {};
    (cfg.presets || []).forEach(function (p) { PRESETS[p.id] = p; });
    var DEVICE_KEY = { desktop: 'style', tablet: 'style_tablet', mobile: 'style_mobile' };
    var AREA_TEXTS = { header: ['nav_solutions', 'nav_journey', 'nav_locations', 'nav_meeting', 'nav_pricing', 'cta_header', 'topbar', 'cta_topbar'], topbar: ['topbar', 'cta_topbar'], footer: [] };

    /* ---------- Durum ---------- */
    var state = { sections: JSON.parse(JSON.stringify(cfg.sections || [])), texts: Object.assign({}, cfg.texts || {}), footerColumns: cfg.footerColumns || '', heroMedia: '', blocks: Object.assign({}, cfg.dataBlocks || {}) };
    var DATA_SECTIONS = cfg.dataBlockSections || {}, DATA_META = cfg.dataBlockMeta || {};
    var uploads = {}; // token → File
    var history = [], future = [];
    var dirty = false, newSeq = 0, selection = null, device = cfg.device || 'desktop', frameWin = null, stale = {};
    var frame = $('[data-frame]'), frameWrap = $('[data-frame-wrap]'), rightBody = $('[data-right-body]'), rightHead = $('[data-right-head]'), rightTabs = $('[data-right-tabs]');
    var rightTab = 'content';

    function snapshot() { return JSON.stringify({ sections: state.sections, texts: state.texts, footerColumns: state.footerColumns, heroMedia: state.heroMedia, blocks: state.blocks }); }
    var lastSnapshot = snapshot(); // son onaylı durum; commit öncesi hali yığına gider
    function commit() { history.push(lastSnapshot); lastSnapshot = snapshot(); if (history.length > 80) history.shift(); future = []; setDirty(true); updateUndo(); }
    function updateUndo() { $('[data-undo]').disabled = history.length === 0; $('[data-redo]').disabled = future.length === 0; }
    function setDirty(v) { dirty = v; $('[data-dirty-badge]').hidden = !v; }
    function toast(msg) { var t = $('[data-toast]'); t.textContent = msg; t.hidden = false; clearTimeout(t._t); t._t = setTimeout(function () { t.hidden = true; }, 2200); }
    function sec(id) { for (var i = 0; i < state.sections.length; i++) if (String(state.sections[i].id) === String(id)) return state.sections[i]; return null; }
    function secIndex(id) { for (var i = 0; i < state.sections.length; i++) if (String(state.sections[i].id) === String(id)) return i; return -1; }
    function label(id, type) { var s = sec(id); var p = s && s.preset_id ? PRESETS[s.preset_id] : null; return (s && s.label) || (p && p.global ? p.name : null) || (LIB[type || (s && s.type)] || { label: type }).label; }
    function linkedGlobal(s) { var p = s && s.preset_id ? PRESETS[s.preset_id] : null; return p && p.global ? p : null; }
    function fdoc() { return frameWin ? frameWin.document : null; }
    function fnode(id) { var d = fdoc(); return d ? d.querySelector('[data-ofv-section="' + id + '"]') : null; }
    function api() { return frameWin && frameWin.OfisvioFrame ? frameWin.OfisvioFrame : null; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    /* ---------- Çerçeveye stil uygulama (SectionStyle aynası) ---------- */
    function decl(st) {
        var d = {};
        ['pt', 'pb', 'mt', 'mb', 'px', 'minh'].forEach(function (k) { if (st[k] !== undefined && st[k] !== '') d['--sec-' + k] = parseInt(st[k], 10) + 'px'; });
        if (st.max_width) d['--sec-maxw'] = parseInt(st.max_width, 10) + 'px';
        if (st.radius) d['--sec-radius'] = parseInt(st.radius, 10) + 'px';
        if (st.align) d['--sec-align'] = st.align;
        if (st.cols) d['--sec-cols'] = String(st.cols);
        if (st.zindex) d['--sec-z'] = String(st.zindex);
        var BG = { surface: 'var(--surface)', warm: 'var(--surface-warm, #f6f4ef)', dark: 'var(--dark, #14201b)', brand: 'var(--brand)' };
        if (st.bg === 'custom' && st.bg_color) d['--sec-bg'] = st.bg_color; else if (st.bg && BG[st.bg]) { d['--sec-bg'] = BG[st.bg]; if (!st.color) d['--sec-color'] = st.bg === 'dark' ? 'var(--dark-ink, #f4f1ea)' : (st.bg === 'brand' ? '#fff' : 'inherit'); }
        if (st.color) d['--sec-color'] = st.color;
        return d;
    }
    function applySectionStyle(s) {
        var el = fnode(s.id); if (!el) return;
        var st = s.settings.style || {}, d = decl(st), css = [];
        Object.keys(d).forEach(function (k) { css.push(k + ':' + d[k]); });
        el.setAttribute('style', css.join(';'));
        var cls = ['site-section'];
        ['bg', 'shadow', 'border'].forEach(function (k) { if (st[k] && st[k] !== 'none') cls.push('sec-' + k + '-' + st[k]); });
        if (st.hidden) cls.push('sec-hidden');
        if (s.hide_on_mobile) cls.push('hide-mobile');
        if (s.hide_on_desktop) cls.push('hide-desktop');
        if (!s.is_visible) cls.push('ofv-invisible');
        if (el.classList.contains('ofv-selected')) cls.push('ofv-selected');
        el.className = cls.join(' ');
        var sel = '#' + el.id, media = '';
        [['style_tablet', '(max-width: 1024px)'], ['style_mobile', '(max-width: 640px)']].forEach(function (p) {
            var dd = decl(s.settings[p[0]] || {}), parts = [];
            Object.keys(dd).forEach(function (k) { parts.push(k + ':' + dd[k] + ' !important'); });
            if (parts.length) media += '@media ' + p[1] + '{' + sel + '{' + parts.join(';') + '}}';
        });
        var tag = el.querySelector(':scope > style');
        if (media) { if (!tag) { tag = fdoc().createElement('style'); el.insertBefore(tag, el.firstChild); } tag.textContent = media; } else if (tag) tag.remove();
        [['style', 'data-cols'], ['style_tablet', 'data-cols-t'], ['style_mobile', 'data-cols-m']].forEach(function (p) { var c = (s.settings[p[0]] || {}).cols; if (c) el.setAttribute(p[1], c); else el.removeAttribute(p[1]); });
    }
    function fieldStyleCss(fs) {
        var css = [];
        if (fs.size) css.push('font-size:' + fs.size + 'px');
        if (fs.color) css.push('color:' + fs.color);
        if (fs.weight) css.push('font-weight:' + fs.weight);
        if (fs.italic) css.push('font-style:italic');
        if (fs.align) css.push('text-align:' + fs.align);
        if (fs.lh) css.push('line-height:' + fs.lh);
        if (fs.ls !== undefined && fs.ls !== '') css.push('letter-spacing:' + fs.ls + 'px');
        if (fs.font) css.push('font-family:var(--font-' + fs.font + ')');
        return css.join(';');
    }
    function applyFieldStyle(s, field) {
        var el = fnode(s.id); if (!el) return;
        var target = el.querySelector('[data-ofv-field="' + field + '"]'); if (!target) return;
        var base = target.getAttribute('data-ofv-basestyle');
        if (base === null) { base = target.getAttribute('style') || ''; target.setAttribute('data-ofv-basestyle', base); }
        target.setAttribute('style', (base ? base + ';' : '') + fieldStyleCss((s.settings.field_styles || {})[field] || {}));
    }
    function applyText(s, field, value) {
        var el = fnode(s.id); if (!el) return;
        var t = el.querySelector('[data-ofv-field="' + field + '"]');
        if (t && !t.classList.contains('ofv-editing')) t.textContent = value;
    }
    function applyGlobalText(key, value) {
        var d = fdoc(); if (!d) return;
        $$('[data-ofv-global="texts.' + key + '"]', d).forEach(function (t) { if (!t.classList.contains('ofv-editing')) t.textContent = value; });
    }
    function markStale(id) { stale[id] = true; var el = fnode(id); if (el) el.setAttribute('data-ofv-stale', '1'); renderLayers(); }

    /* ---------- Çerçeve hazır ---------- */
    window.OfisvioEditor = {
        frameReady: function (win) {
            frameWin = win;
            var d = win.document;
            // Sunucudan gelen görünüm ile durum aynı; yalnız yeni (kaydedilmemiş) bölümler yeniden basılır (sürüm geçmişi dönüşü).
            resyncFrame();
            if (cfg.selected && sec(cfg.selected)) select({ kind: 'section', section: String(cfg.selected) });
            renderLayers();
            if (!selection) renderPageInfo();
            d.addEventListener('scroll', function () { /* araç çubuğu çerçeve içinde konumlanır */ });
        },
        labelFor: label,
        select: function (sel) { select(sel); },
        sectionAction: function (id, act) { sectionAction(id, act); },
        fieldInput: function (ref) {
            if (ref.field && ref.section) { var s = sec(ref.section); if (!s) return; if (s.settings[ref.field] === ref.value) return; s.settings[ref.field] = ref.value; commitDebounced(); if (rightTab === 'content') syncPanelInput(ref.field, ref.value); }
            else if (ref.global && ref.global.indexOf('texts.') === 0) { var k = ref.global.substring(6); if (state.texts[k] === ref.value) return; state.texts[k] = ref.value; applyGlobalText(k, ref.value); commitDebounced(); syncPanelInput('texts.' + k, ref.value); }
        },
        fieldStyle: function (ref, key, val) {
            if (!ref.section || !ref.field) { toast('Global metinlerde biçim yok; bölüm metinlerinde kullanın.'); return; }
            var s = sec(ref.section); if (!s) return;
            s.settings.field_styles = s.settings.field_styles || {};
            var fs = s.settings.field_styles[ref.field] = s.settings.field_styles[ref.field] || {};
            if (key === 'italic') fs.italic = !fs.italic; else if (key === 'weight') fs.weight = fs.weight === val ? '' : val; else fs[key] = val;
            applyFieldStyle(s, ref.field); commit(); if (rightTab === 'design') renderRight();
        },
        focusStyle: function () { rightTab = 'design'; renderRight(); },
        imageDrop: function (ref) { imageDrop(ref); },
        addAt: function (what, index) { addSection(what, index); },
        moveTo: function (id, index) { moveSection(id, index); }
    };
    var commitTimer = null;
    function commitDebounced() { clearTimeout(commitTimer); commitTimer = setTimeout(commit, 400); }

    /* ---------- Bölüm işlemleri ---------- */
    function addSection(what, index) {
        var type, settings, presetId = null;
        if (what.indexOf('preset:') === 0) { presetId = parseInt(what.substring(7), 10); var p = PRESETS[presetId]; if (!p) return; type = p.type; settings = JSON.parse(JSON.stringify(p.settings || {})); }
        else { type = what; settings = {}; }
        var def = LIB[type]; if (!def) return;
        if (def.unique && state.sections.some(function (s) { return s.type === type; })) { toast(def.label + ' sayfada zaten var (tek olabilir).'); return; }
        if (state.sections.length >= 40) { toast('En fazla 40 bölüm.'); return; }
        var a = api(); if (!a) return;
        var tpls = a.templates(), tpl = tpls[presetId ? 'preset:' + presetId : type]; if (!tpl) { toast('Şablon bulunamadı.'); return; }
        var id = 'n' + (++newSeq);
        var frag = fdoc().importNode(tpl.content, true), el = frag.querySelector('[data-ofv-section]');
        el.setAttribute('data-ofv-section', id); el.id = 'sec-' + id;
        var row = { id: id, type: type, anchor: null, is_visible: true, hide_on_mobile: false, hide_on_desktop: false, locked: false, label: null, preset_id: presetId && PRESETS[presetId].global ? presetId : null, settings: Object.keys(settings).length ? settings : JSON.parse(JSON.stringify(defaultsFor(type))), publish_from: null, publish_until: null };
        if (index === null || index === undefined || index >= state.sections.length) { state.sections.push(row); a.container().appendChild(frag); }
        else { var before = fnode(state.sections[index].id); state.sections.splice(index, 0, row); a.container().insertBefore(frag, before); }
        a.clearEmpty(); a.makeDraggable(el); applySectionStyle(row);
        commit(); renderLayers(); select({ kind: 'section', section: id }); el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        toast((row.preset_id ? '🌐 Global blok bağlandı' : def.label + ' eklendi') + ' (taslak; kaydedin).');
    }
    function defaultsFor(type) { return (cfg.defaults || {})[type] || {}; }
    function moveSection(id, index) {
        var from = secIndex(id); if (from === -1) return;
        var s = sec(id); if (s.locked) { toast('Kilitli bölüm taşınamaz.'); return; }
        var row = state.sections.splice(from, 1)[0];
        if (index > from) index--;
        if (index >= state.sections.length) state.sections.push(row); else state.sections.splice(index, 0, row);
        reorderFrame(); commit(); renderLayers(); select({ kind: 'section', section: id });
    }
    function reorderFrame() {
        var a = api(); if (!a) return;
        var c = a.container();
        state.sections.forEach(function (s) { var el = fnode(s.id); if (el) c.appendChild(el); });
    }
    function sectionAction(id, act) {
        var s = sec(id); if (!s) return;
        var i = secIndex(id);
        if (s.locked && act !== 'edit' && act !== 'settings' && act !== 'lock') { toast('Bölüm kilitli.'); return; }
        switch (act) {
            case 'edit': select({ kind: 'section', section: id }); rightTab = 'content'; renderRight(); break;
            case 'settings': select({ kind: 'section', section: id }); rightTab = 'design'; renderRight(); break;
            case 'up': if (i > 0) moveSection(id, i - 1); break;
            case 'down': if (i < state.sections.length - 1) moveSection(id, i + 2); break;
            case 'toggle': s.is_visible = !s.is_visible; applySectionStyle(s); commit(); renderLayers(); if (selection && selection.section === id) renderRight(); break;
            case 'lock': s.locked = !s.locked; var el = fnode(id); if (el) { if (s.locked) el.setAttribute('data-ofv-locked', '1'); else el.removeAttribute('data-ofv-locked'); } commit(); renderLayers(); renderRight(); break;
            case 'duplicate':
                if ((LIB[s.type] || {}).unique) { toast('Tekil bölüm kopyalanamaz.'); return; }
                var copy = JSON.parse(JSON.stringify(s)); copy.id = 'n' + (++newSeq); copy.anchor = null; copy.label = s.label ? s.label + ' (kopya)' : null;
                var src = fnode(id), clone = src.cloneNode(true); clone.setAttribute('data-ofv-section', copy.id); clone.id = 'sec-' + copy.id; clone.classList.remove('ofv-selected', 'ofv-hover');
                src.parentNode.insertBefore(clone, src.nextSibling); state.sections.splice(i + 1, 0, copy);
                commit(); renderLayers(); select({ kind: 'section', section: copy.id }); break;
            case 'delete':
                if (!window.confirm(label(id) + ' bölümü silinsin mi? (Kaydedince kalıcı olur)')) return;
                var node = fnode(id); if (node) node.remove(); state.sections.splice(i, 1);
                commit(); renderLayers(); select(null); break;
        }
    }

    /* ---------- Seçim & sağ panel ---------- */
    function select(sel) {
        selection = sel;
        if (api() && !(sel && sel.kind === 'field')) api().stopEdit();
        if (sel && sel.section && api()) api().select(sel.section);
        if (sel && sel.kind === 'image') rightTab = 'content';
        if (sel && sel.section && rightTab === 'seo') rightTab = 'content'; // SEO sekmesi sayfa düzeyidir
        if (sel && sel.kind === 'field') rightTab = 'content';
        renderRight(); renderLayers();
    }
    $$('[data-right-tabs] button').forEach(function (b) { b.addEventListener('click', function () { rightTab = b.getAttribute('data-tab'); renderRight(); }); });
    function setRightTabs(show) { rightTabs.hidden = !show; $$('[data-right-tabs] button').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-tab') === rightTab ? 'true' : 'false'); }); }

    function renderRight() {
        if (!selection) { renderPageInfo(); return; }
        if (selection.kind === 'global') { renderGlobalPanel(selection.area); return; }
        var s = sec(selection.section);
        if (!s && selection.kind === 'image' && selection.site) { renderSiteImagePanel(); return; }
        if (!s && selection.area) { renderGlobalPanel(selection.area); return; }
        if (!s) { renderPageInfo(); return; }
        var def = LIB[s.type];
        var g = linkedGlobal(s);
        rightHead.innerHTML = '<b>' + esc(label(s.id)) + '</b><span class="small muted">' + esc(def.label) + ' · ' + esc(def.source) + (s.locked ? ' · 🔒 kilitli' : '') + '</span>' + (g ? '<span class="small" style="margin-top:4px"><span class="pill g flat">🌐 Global blok: ' + esc(g.name) + '</span> değişiklik tüm kullanımlara yansır · <button type="button" class="btn btn--quiet" data-detach>Bağı kopar</button></span>' : '');
        var det = rightHead.querySelector('[data-detach]');
        if (det) det.addEventListener('click', function () { if (!window.confirm('Bağ koparılsın mı? Bu bölüm kendi kopyasıyla devam eder; global blok değişince artık güncellenmez.')) return; s.preset_id = null; commit(); renderRight(); renderLayers(); });
        setRightTabs(true);
        var html = '';
        if (rightTab === 'content') html = contentPanel(s, def);
        else if (rightTab === 'design') html = designPanel(s);
        else if (rightTab === 'visibility') html = visibilityPanel(s);
        else html = seoPanel();
        rightBody.innerHTML = html;
        bindPanel(s);
    }
    function fieldControl(key, f, val) {
        var id = 'f-' + key;
        var h = '<div class="ve-field"><span class="label">' + esc(f.label) + '</span>';
        switch (f.type) {
            case 'textarea': case 'markdown': h += '<textarea class="control mono" data-set="' + key + '" rows="' + (f.type === 'markdown' ? 8 : 3) + '">' + esc(val || '') + '</textarea>' + (f.type === 'markdown' ? '<span class="small muted">Markdown + bloklar (:::hero…). Kaydedince yeniden çizilir.</span>' : ''); break;
            case 'lines': h += '<textarea class="control mono" data-set="' + key + '" rows="6">' + esc(Array.isArray(val) ? val.join('\n') : (val || '')) + '</textarea><span class="small muted">' + esc(f.hint || 'Her satır bir madde; kaydedince yeniden çizilir.') + '</span>'; break;
            case 'select': h += '<select class="control" data-set="' + key + '">'; Object.keys(f.options || {}).forEach(function (o) { h += '<option value="' + esc(o) + '"' + (String(val) === o ? ' selected' : '') + '>' + esc(f.options[o]) + '</option>'; }); h += '</select>'; break;
            case 'number': h += '<input class="control" type="number" min="0" max="2000" data-set="' + key + '" value="' + esc(val || '') + '">'; break;
            case 'cta': var c = val || {}; h += '<div class="ve-row"><select class="control" data-set="' + key + '.action">'; Object.keys(cfg.ctaActions).forEach(function (o) { h += '<option value="' + o + '"' + ((c.action || 'none') === o ? ' selected' : '') + '>' + esc(cfg.ctaActions[o]) + '</option>'; }); h += '</select><input class="control" type="text" placeholder="Hedef (#bolum, yol, https)" data-set="' + key + '.target" value="' + esc(c.target || '') + '"></div><input class="control" type="text" placeholder="Düğme metni" maxlength="60" data-set="' + key + '.label" value="' + esc(c.label || '') + '" style="margin-top:6px">'; break;
            case 'media': h += mediaPicker(key, val ? [val] : [], false); break;
            case 'media_list': h += mediaPicker(key, Array.isArray(val) ? val : [], true); break;
            default: h += '<input class="control" type="text" maxlength="2000" data-set="' + key + '" value="' + esc(val || '') + '">';
        }
        return h + '</div>';
    }
    function mediaPicker(key, selected, multi) {
        var h = '<div class="ve-drop" data-drop-target="' + key + '">Bilgisayarınızdan görsel bırakın ya da seçin: <label class="btn btn--quiet" style="cursor:pointer">Dosya seç<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-file-pick="' + key + '"' + (multi ? ' multiple' : '') + '></label></div>';
        h += '<div class="ve-media-grid" data-media-grid="' + key + '" data-multi="' + (multi ? 1 : 0) + '" style="margin-top:6px">';
        MEDIA.forEach(function (m) { var on = selected.some(function (v) { return String(v) === String(m.id); }); h += '<button type="button" class="' + (on ? 'is-selected' : '') + '" data-media-id="' + m.id + '" title="' + esc(m.alt || m.name) + '"><img src="' + esc(m.thumb) + '" alt=""></button>'; });
        Object.keys(uploads).forEach(function (t) { var on = selected.some(function (v) { return v === 'upload:' + t; }); h += '<button type="button" class="' + (on ? 'is-selected' : '') + '" data-media-id="upload:' + t + '" title="' + esc(uploads[t].name) + ' (yüklenecek)"><img src="' + esc(uploads[t]._url) + '" alt=""></button>'; });
        h += '</div>' + (multi ? '<span class="small muted">Birden çok seçilebilir; sıra seçim sırası.</span>' : '') + '<span class="small muted" style="display:block">Alt metin/açıklama medya kütüphanesinden düzenlenir.</span>';
        return h;
    }
    function contentPanel(s, def) {
        var h = '';
        if (selection.kind === 'image' || selection.kind === 'markdown' || selection.kind === 'field') h += '<div class="note small" style="margin-bottom:10px">Seçili: <b>' + esc(selection.field || '') + '</b>' + (selection.kind === 'field' ? ' — sayfada doğrudan yazabilirsiniz; biçim için Tasarım sekmesi.' : '') + '</div>';
        var keys = Object.keys(def.fields);
        if (!keys.length) h += '<p class="small muted">Bu bölümün alanı yok; verisi gerçek kayıtlardan gelir (' + esc(def.source) + ').</p>';
        keys.forEach(function (k) { var f = def.fields[k]; var g = def.texts && def.texts[k] && !s.settings[k] ? '<span class="small muted" style="display:block;margin:-4px 0 6px">Boş: global metin "' + esc(state.texts[def.texts[k]] || '') + '" kullanılır.</span>' : ''; h += fieldControl(k, f, s.settings[k]) + g; });
        // Vitrin veri listeleri (dahil olanlar, planlar…): site geneli veri, bu bölümde düzenlenir; taslak → yayın.
        (DATA_SECTIONS[s.type] || []).forEach(function (key) {
            var meta = DATA_META[key] || { label: key, fields: [] };
            h += '<div class="ve-group" style="margin-top:10px"><p class="eyebrow">🌐 ' + esc(meta.label) + ' <span class="muted" style="font-weight:400">(site verisi)</span></p><textarea class="control mono" rows="' + (key === 'pricing_note' ? 2 : 6) + '" data-block="' + key + '">' + esc(state.blocks[key] || '') + '</textarea><span class="small muted">' + (key === 'pricing_note' ? 'Üyelik tablosunun yanındaki kısa not.' : 'Her satır bir kayıt: <code>' + esc((meta.fields || []).join(' | ')) + '</code>. Boş = kod varsayılanı.') + ' Kaydedince önizlemede, yayınlayınca sitede.</span></div>';
        });
        h += '<div class="ve-group" style="margin-top:12px"><p class="eyebrow">Etiket &amp; çapa</p><div class="ve-row"><input class="control" type="text" placeholder="Katman adı" maxlength="80" data-meta="label" value="' + esc(s.label || '') + '"><input class="control mono" type="text" placeholder="çapa (#…)" maxlength="40" pattern="[a-z0-9-]*" data-meta="anchor" value="' + esc(s.anchor || '') + '"></div></div>';
        h += '<div style="display:flex;gap:6px;flex-wrap:wrap"><button type="button" class="btn btn--ghost" data-preset-save>Blok olarak kaydet</button>' + (def.unique ? '' : '<button type="button" class="btn btn--ghost" data-act="duplicate">Kopyala</button>') + '<button type="button" class="btn btn--ghost" data-act="delete" style="color:var(--danger)">Sil</button></div>';
        return h;
    }
    function styleControls(st, prefix) {
        var v = function (k) { return st[k] === undefined ? '' : st[k]; };
        var seg = function (k, opts) { var h = '<div class="ve-seg" data-seg="' + prefix + k + '">'; opts.forEach(function (o) { h += '<button type="button" data-val="' + o[0] + '" aria-pressed="' + (String(v(k)) === o[0] ? 'true' : 'false') + '">' + o[1] + '</button>'; }); return h + '</div>'; };
        var num = function (k, lab, min, max, step) { return '<label class="ve-field"><span class="label">' + lab + '</span><input class="control" type="number" min="' + min + '" max="' + max + '" step="' + (step || 1) + '" data-style="' + prefix + k + '" value="' + esc(v(k)) + '" placeholder="—"></label>'; };
        var h = '<div class="ve-group"><p class="eyebrow">Arka plan &amp; renk</p><div class="ve-row"><select class="control" data-style="' + prefix + 'bg"><option value="">Varsayılan</option><option value="surface"' + (v('bg') === 'surface' ? ' selected' : '') + '>Yüzey</option><option value="warm"' + (v('bg') === 'warm' ? ' selected' : '') + '>Sıcak</option><option value="dark"' + (v('bg') === 'dark' ? ' selected' : '') + '>Koyu</option><option value="brand"' + (v('bg') === 'brand' ? ' selected' : '') + '>Marka</option><option value="custom"' + (v('bg') === 'custom' ? ' selected' : '') + '>Özel renk</option></select><input class="control" type="color" data-style="' + prefix + 'bg_color" value="' + esc(v('bg_color') || '#ffffff') + '" title="Özel arka plan"></div><label class="ve-field" style="margin-top:6px"><span class="label">Metin rengi</span><div class="ve-row"><input class="control" type="color" data-style="' + prefix + 'color" value="' + esc(v('color') || '#000000') + '"><button type="button" class="btn btn--quiet" data-style-clear="' + prefix + 'color">Sıfırla</button></div></label></div>';
        h += '<div class="ve-group"><p class="eyebrow">Boşluklar (px)</p><div class="ve-row">' + num('pt', 'Üst padding', 0, 240) + num('pb', 'Alt padding', 0, 240) + '</div><div class="ve-row">' + num('mt', 'Üst margin', -120, 240) + num('mb', 'Alt margin', -120, 240) + '</div><div class="ve-row">' + num('px', 'Yatay padding', 0, 120) + num('minh', 'Min yükseklik', 0, 1200) + '</div></div>';
        h += '<div class="ve-group"><p class="eyebrow">Yerleşim</p><div class="ve-field"><span class="label">Hizalama</span>' + seg('align', [['left', 'Sol'], ['center', 'Orta'], ['right', 'Sağ']]) + '</div><div class="ve-field"><span class="label">Kolon (grid bölümler)</span>' + seg('cols', [['1', '1'], ['2', '2'], ['3', '3'], ['4', '4']]) + '</div><div class="ve-row">' + num('max_width', 'Maks. genişlik', 320, 1800, 10) + num('zindex', 'Z-index', 0, 20) + '</div></div>';
        h += '<div class="ve-group"><p class="eyebrow">Kenar &amp; gölge</p><div class="ve-row">' + num('radius', 'Köşe yarıçapı', 0, 64) + '<label class="ve-field"><span class="label">Gölge</span><select class="control" data-style="' + prefix + 'shadow"><option value="">Yok</option><option value="sm"' + (v('shadow') === 'sm' ? ' selected' : '') + '>Hafif</option><option value="md"' + (v('shadow') === 'md' ? ' selected' : '') + '>Orta</option><option value="lg"' + (v('shadow') === 'lg' ? ' selected' : '') + '>Belirgin</option></select></label></div><label class="ve-field"><span class="label">Kenarlık</span><select class="control" data-style="' + prefix + 'border"><option value="">Yok</option><option value="line"' + (v('border') === 'line' ? ' selected' : '') + '>Çizgi (üst/alt)</option><option value="brand"' + (v('border') === 'brand' ? ' selected' : '') + '>Marka şeridi</option></select></label><label class="ve-check"><input type="checkbox" data-style="' + prefix + 'hidden"' + (v('hidden') ? ' checked' : '') + '> Bu cihazda gizle</label></div>';
        return h;
    }
    function designPanel(s) {
        var key = DEVICE_KEY[device];
        var h = '<div class="note small" style="margin-bottom:10px">Cihaz: <b>' + { desktop: 'Masaüstü (temel)', tablet: 'Tablet (≤1024px, üstüne yazar)', mobile: 'Mobil (≤640px, üstüne yazar)' }[device] + '</b> — üstteki cihaz düğmeleriyle değiştirin. Boş alan = temelden gelir.</div>';
        h += styleControls(s.settings[key] || {}, '');
        // Metin biçimi
        var fields = Object.keys(LIB[s.type].fields).filter(function (k) { return ['text', 'textarea'].indexOf(LIB[s.type].fields[k].type) !== -1; });
        if (fields.length) {
            var cur = selection && selection.field && fields.indexOf(selection.field) !== -1 ? selection.field : fields[0];
            var fs = (s.settings.field_styles || {})[cur] || {};
            h += '<div class="ve-group"><p class="eyebrow">Metin biçimi</p><select class="control" data-fs-field style="margin-bottom:6px">'; fields.forEach(function (f) { h += '<option value="' + f + '"' + (f === cur ? ' selected' : '') + '>' + esc(LIB[s.type].fields[f].label) + '</option>'; }); h += '</select>';
            h += '<div class="ve-row3"><label class="ve-field"><span class="label">Boyut (px)</span><input class="control" type="number" min="10" max="120" data-fs="size" value="' + esc(fs.size || '') + '"></label><label class="ve-field"><span class="label">Ağırlık</span><select class="control" data-fs="weight"><option value="">—</option>' + ['400', '500', '600', '700', '800'].map(function (w) { return '<option' + (fs.weight === w ? ' selected' : '') + '>' + w + '</option>'; }).join('') + '</select></label><label class="ve-field"><span class="label">Yazı tipi</span><select class="control" data-fs="font"><option value="">—</option><option value="sans"' + (fs.font === 'sans' ? ' selected' : '') + '>Sans</option><option value="serif"' + (fs.font === 'serif' ? ' selected' : '') + '>Serif</option><option value="mono"' + (fs.font === 'mono' ? ' selected' : '') + '>Mono</option></select></label></div>';
            h += '<div class="ve-row3"><label class="ve-field"><span class="label">Renk</span><input class="control" type="color" data-fs="color" value="' + esc(fs.color || '#000000') + '"></label><label class="ve-field"><span class="label">Satır yük.</span><input class="control" type="number" min="0.9" max="2.6" step="0.05" data-fs="lh" value="' + esc(fs.lh || '') + '"></label><label class="ve-field"><span class="label">Harf aralığı</span><input class="control" type="number" min="-3" max="12" step="0.5" data-fs="ls" value="' + esc(fs.ls === undefined ? '' : fs.ls) + '"></label></div>';
            h += '<div style="display:flex;gap:6px;align-items:center"><div class="ve-seg" data-fs-seg="align">' + [['left', 'Sol'], ['center', 'Orta'], ['right', 'Sağ']].map(function (o) { return '<button type="button" data-val="' + o[0] + '" aria-pressed="' + (fs.align === o[0] ? 'true' : 'false') + '">' + o[1] + '</button>'; }).join('') + '</div><label class="ve-check" style="margin:0"><input type="checkbox" data-fs="italic"' + (fs.italic ? ' checked' : '') + '> İtalik</label><button type="button" class="btn btn--quiet" data-fs-clear>Sıfırla</button></div></div>';
        }
        return h;
    }
    function visibilityPanel(s) {
        return '<label class="ve-check"><input type="checkbox" data-meta="is_visible"' + (s.is_visible ? ' checked' : '') + '> <b>Görünür</b> (sayfada basılır)</label><label class="ve-check"><input type="checkbox" data-meta="hide_on_mobile"' + (s.hide_on_mobile ? ' checked' : '') + '> Mobilde gizle</label><label class="ve-check"><input type="checkbox" data-meta="hide_on_desktop"' + (s.hide_on_desktop ? ' checked' : '') + '> Masaüstünde gizle</label><label class="ve-check"><input type="checkbox" data-meta="locked"' + (s.locked ? ' checked' : '') + '> 🔒 Kilitli (taşıma/silme kapalı)</label><div class="ve-group" style="margin-top:10px"><p class="eyebrow">Zamanlama</p><label class="ve-field"><span class="label">Yayın başlangıcı</span><input class="control" type="datetime-local" data-meta="publish_from" value="' + esc(s.publish_from || '') + '"></label><label class="ve-field"><span class="label">Yayın bitişi</span><input class="control" type="datetime-local" data-meta="publish_until" value="' + esc(s.publish_until || '') + '"></label></div>';
    }
    function seoPanel() {
        var a = api(); if (!a) return '';
        var f = a.seoFacts(), checks = [];
        var add = function (ok, lvl, msg) { checks.push('<li><span class="pill ' + (ok ? 'g' : (lvl === 'error' ? 'c' : 'w')) + ' flat">' + (ok ? '✓' : '!') + '</span> ' + esc(msg) + '</li>'); };
        var tl = (f.title || '').length, dl = (f.description || '').length;
        add(tl >= 30 && tl <= 70, 'error', 'Başlık ' + tl + ' karakter (30–70).');
        add(dl >= 50 && dl <= 160, 'error', 'Meta açıklama ' + dl + ' karakter (50–160).');
        add(f.h1 === 1, 'error', f.h1 + ' adet H1 (tam 1 olmalı).');
        add(f.headings >= 2 && !f.jump, 'warn', f.headings + ' alt başlık' + (f.jump ? ', düzey atlıyor' : ', düzeyler sıralı') + '.');
        add(f.internalLinks >= 3, 'warn', f.internalLinks + ' iç bağlantı.');
        add(f.noAlt === 0, 'warn', f.images + ' görsel, ' + f.noAlt + ' alt metinsiz.');
        add(f.words >= 300, 'warn', f.words + ' kelime.');
        add(!!f.canonical, 'info', f.canonical ? 'Canonical: ' + f.canonical : 'Canonical yok.');
        add(f.robots.indexOf('noindex') === -1 || true, 'info', 'Robots (önizleme her zaman noindex): canlıda site ayarından.');
        add(f.schema.length > 0, 'info', 'Şema: ' + (f.schema.join(', ') || 'yok'));
        var score = Math.round(checks.filter(function (c) { return c.indexOf('pill g') !== -1; }).length / checks.length * 100);
        return '<div class="kpi" style="box-shadow:none;padding:0 0 8px"><span class="k">Sayfa SEO skoru (çerçeveden)</span><span class="v">' + score + '</span><span class="d">' + (score >= 80 ? 'İyi' : score >= 50 ? 'Orta' : 'Zayıf') + '</span></div><ul class="ve-seo" style="list-style:none;margin:0;padding:0;font-size:12.5px">' + checks.join('') + '</ul><p class="small muted" style="margin-top:10px">Başlık/açıklama/canonical/robots/OG/şema ayarları: <a href="/panel/seo">SEO &amp; GEO</a> (site) · sayfa bazlı SEO/GEO/şema: Sayfalar › Stüdyo. GEO özeti ve SSS: site ayarları › GEO.</p>';
    }
    function renderPageInfo() {
        rightHead.innerHTML = '<b>Ana sayfa</b><span class="small muted">' + state.sections.length + ' bölüm · bir öğeye tıklayın ya da soldan blok sürükleyin</span>';
        setRightTabs(true);
        if (rightTab === 'content' || rightTab === 'visibility') rightTab = 'seo';
        rightBody.innerHTML = rightTab === 'design' ? '<p class="small muted">Tasarım ayarları bölüm bazlıdır; bir bölüm seçin. Site geneli tema/renk: <a href="/panel/websiteler">Site ayarları</a>.</p>' : seoPanel();
        $$('[data-right-tabs] button').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-tab') === rightTab ? 'true' : 'false'); });
    }
    function renderGlobalPanel(area) {
        rightHead.innerHTML = '<b>' + (area === 'footer' ? 'Footer' : 'Header & üst şerit') + '</b><span class="small muted">Global — tüm sayfalarda; yayınlanınca canlıya geçer</span>';
        setRightTabs(false);
        var h = '';
        if (area === 'footer') {
            h += '<div class="ve-field"><span class="label">Footer sütunları (her satır: Başlık | Madde = /yol, Madde = #bolum, …)</span><textarea class="control mono" rows="8" data-global="footer_columns">' + esc(state.footerColumns) + '</textarea><span class="small muted">Kaydedince önizlemede, yayınlayınca sitede güncellenir.</span></div>';
            h += '<p class="small muted">Marka adı, slogan, telefon, e-posta, adres, çalışma saatleri: <a href="/panel/websiteler">Site ayarları</a> (website.manage). Alt bilgideki yasal sayfalar yayındaki sayfalardan gelir.</p>';
        } else {
            h += '<p class="small muted" style="margin:0 0 8px">Menü etiketleri bölüm çapalarına bağlıdır; gizli bölümün bağlantısı basılmaz.</p>';
            AREA_TEXTS.header.forEach(function (k) { h += '<label class="ve-field"><span class="label">' + esc(TEXT_KEYS[k] || k) + '</span><input class="control" type="text" maxlength="500" data-global="texts.' + k + '" value="' + esc(state.texts[k] || '') + '"></label>'; });
            h += '<details style="margin-top:8px"><summary class="small" style="cursor:pointer;font-weight:600">Diğer site metinleri (bölüm başlıkları, CTA etiketleri, teklif vaatleri, WhatsApp mesajı)</summary><div style="margin-top:8px">';
            Object.keys(TEXT_KEYS).forEach(function (k) { if (AREA_TEXTS.header.indexOf(k) !== -1) return; h += '<label class="ve-field"><span class="label">' + esc(TEXT_KEYS[k]) + '</span><input class="control" type="text" maxlength="500" data-global="texts.' + k + '" value="' + esc(state.texts[k] || '') + '"></label>'; });
            h += '</div></details><p class="small muted">Logo/marka adı ve duyuru şeridi: <a href="/panel/websiteler">Site ayarları</a>. Hazır bileşenler ve kayıtlı bloklar: <a href="/panel/icerik/bloklar">Blok kütüphanesi</a>.</p>';
        }
        rightBody.innerHTML = h;
        $$('[data-global]', rightBody).forEach(function (inp) {
            inp.addEventListener('input', function () {
                var k = inp.getAttribute('data-global');
                if (k === 'footer_columns') { state.footerColumns = inp.value; markStaleGlobal(); }
                else { var key = k.substring(6); state.texts[key] = inp.value; applyGlobalText(key, inp.value); }
                commitDebounced();
            });
        });
    }
    function markStaleGlobal() { var d = fdoc(); if (d) { var f = d.querySelector('[data-ofv-footer-columns]'); if (f) f.setAttribute('data-stale', '1'); } }
    function renderSiteImagePanel() {
        rightHead.innerHTML = '<b>Site ana görseli (hero)</b><span class="small muted">Site ayarı — tüm sayfalarda aynı</span>';
        setRightTabs(false);
        rightBody.innerHTML = '<p class="small muted">Ana görsel <b>hero_media_id</b> site ayarıdır; buraya bırakılan/seçilen görsel kaydedince site ayarına yazılır (website.manage yetkisi yoksa yalnız önizlenir).</p>' + mediaPicker('__hero', state.heroMedia ? [state.heroMedia] : [], false);
        bindMediaGrids(null);
    }
    function syncPanelInput(key, value) { var el = rightBody.querySelector('[data-set="' + key + '"], [data-global="' + key + '"]'); if (el && el.value !== value && document.activeElement !== el) el.value = value; }

    function bindPanel(s) {
        $$('[data-set]', rightBody).forEach(function (inp) {
            inp.addEventListener('input', function () { onSet(s, inp.getAttribute('data-set'), inp.value, inp); });
            if (inp.tagName === 'SELECT') inp.addEventListener('change', function () { onSet(s, inp.getAttribute('data-set'), inp.value, inp); });
        });
        $$('[data-block]', rightBody).forEach(function (ta) { ta.addEventListener('input', function () { state.blocks[ta.getAttribute('data-block')] = ta.value; markStale(s.id); commitDebounced(); }); });
        $$('[data-meta]', rightBody).forEach(function (inp) {
            var ev = inp.type === 'checkbox' ? 'change' : 'input';
            inp.addEventListener(ev, function () {
                var k = inp.getAttribute('data-meta');
                if (inp.type === 'checkbox') { s[k] = inp.checked; if (k === 'locked') sectionAction(s.id, 'lock'); else { applySectionStyle(s); commit(); renderLayers(); } }
                else { s[k] = inp.value || null; commitDebounced(); renderLayers(); }
            });
        });
        $$('[data-style]', rightBody).forEach(function (inp) {
            var ev = inp.type === 'checkbox' || inp.tagName === 'SELECT' ? 'change' : 'input';
            inp.addEventListener(ev, function () {
                var key = DEVICE_KEY[device], st = s.settings[key] = s.settings[key] || {};
                var k = inp.getAttribute('data-style');
                if (inp.type === 'checkbox') { if (inp.checked) st[k] = true; else delete st[k]; }
                else if (inp.value === '') delete st[k]; else st[k] = inp.value;
                if (k === 'bg' && inp.value !== 'custom') delete st.bg_color;
                if (k === 'bg_color') st.bg = 'custom';
                applySectionStyle(s); commitDebounced();
            });
        });
        $$('[data-style-clear]', rightBody).forEach(function (b) { b.addEventListener('click', function () { var st = s.settings[DEVICE_KEY[device]] || {}; delete st[b.getAttribute('data-style-clear')]; applySectionStyle(s); commit(); renderRight(); }); });
        $$('[data-seg]', rightBody).forEach(function (seg) {
            seg.addEventListener('click', function (e) {
                var b = e.target.closest('button'); if (!b) return;
                var key = DEVICE_KEY[device], st = s.settings[key] = s.settings[key] || {}, k = seg.getAttribute('data-seg');
                if (String(st[k]) === b.getAttribute('data-val')) delete st[k]; else st[k] = b.getAttribute('data-val');
                $$('button', seg).forEach(function (x) { x.setAttribute('aria-pressed', String(st[k]) === x.getAttribute('data-val') ? 'true' : 'false'); });
                applySectionStyle(s); commit();
            });
        });
        // Metin biçimi
        var fsField = rightBody.querySelector('[data-fs-field]');
        if (fsField) {
            fsField.addEventListener('change', function () { selection = { kind: 'field', section: s.id, field: fsField.value }; renderRight(); });
            var fsOf = function () { s.settings.field_styles = s.settings.field_styles || {}; return s.settings.field_styles[fsField.value] = s.settings.field_styles[fsField.value] || {}; };
            $$('[data-fs]', rightBody).forEach(function (inp) {
                inp.addEventListener(inp.type === 'checkbox' || inp.tagName === 'SELECT' ? 'change' : 'input', function () {
                    var fs = fsOf(), k = inp.getAttribute('data-fs');
                    if (inp.type === 'checkbox') { if (inp.checked) fs[k] = true; else delete fs[k]; } else if (inp.value === '') delete fs[k]; else fs[k] = inp.value;
                    applyFieldStyle(s, fsField.value); commitDebounced();
                });
            });
            var seg = rightBody.querySelector('[data-fs-seg]');
            if (seg) seg.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; var fs = fsOf(); fs.align = fs.align === b.getAttribute('data-val') ? undefined : b.getAttribute('data-val'); if (!fs.align) delete fs.align; $$('button', seg).forEach(function (x) { x.setAttribute('aria-pressed', fs.align === x.getAttribute('data-val') ? 'true' : 'false'); }); applyFieldStyle(s, fsField.value); commit(); });
            var clr = rightBody.querySelector('[data-fs-clear]');
            if (clr) clr.addEventListener('click', function () { if (s.settings.field_styles) delete s.settings.field_styles[fsField.value]; applyFieldStyle(s, fsField.value); commit(); renderRight(); });
        }
        $$('[data-act]', rightBody).forEach(function (b) { b.addEventListener('click', function () { sectionAction(s.id, b.getAttribute('data-act')); }); });
        var ps = rightBody.querySelector('[data-preset-save]');
        if (ps) ps.addEventListener('click', function () {
            var name = window.prompt('Blok adı (kütüphanede görünecek):', label(s.id));
            if (!name) return;
            if (dirty && !window.confirm('Kayıtlı blok ayrı kaydedilir ve sayfa yenilenir; kaydedilmemiş editör değişiklikleri kaybolur. Önce Kaydet\'e basmak ister misiniz? Devam = kaydetmeden ilerle.')) return;
            var f = $('[data-preset-form]'); f.querySelector('[name="name"]').value = name; f.querySelector('[name="type"]').value = s.type; f.querySelector('[name="settings"]').value = JSON.stringify(s.settings); dirty = false; f.submit();
        });
        bindMediaGrids(s);
    }
    function onSet(s, key, value, inp) {
        var def = LIB[s.type], parts = key.split('.');
        if (parts.length === 2) { s.settings[parts[0]] = s.settings[parts[0]] || { action: 'none', target: '', label: '' }; s.settings[parts[0]][parts[1]] = value; markStale(s.id); commitDebounced(); return; }
        var f = def.fields[key]; if (!f) return;
        if (f.type === 'lines') s.settings[key] = value.split('\n'); else s.settings[key] = value;
        if (f.type === 'text' || f.type === 'textarea') applyText(s, key, value);
        else if (key === 'height' && s.type === 'spacer') { var el = fnode(s.id); var sp = el && el.querySelector('[data-ofv-spacer]'); if (sp) sp.style.height = Math.min(400, parseInt(value || '0', 10)) + 'px'; }
        else markStale(s.id);
        commitDebounced();
    }
    function bindMediaGrids(s) {
        $$('[data-media-grid]', rightBody).forEach(function (grid) {
            var key = grid.getAttribute('data-media-grid'), multi = grid.getAttribute('data-multi') === '1';
            grid.addEventListener('click', function (e) {
                var b = e.target.closest('button[data-media-id]'); if (!b) return;
                var id = b.getAttribute('data-media-id'); id = /^\d+$/.test(id) ? parseInt(id, 10) : id;
                setMedia(s, key, id, multi, grid);
            });
        });
        $$('[data-drop-target]', rightBody).forEach(function (dz) {
            var key = dz.getAttribute('data-drop-target');
            dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('is-over'); });
            dz.addEventListener('dragleave', function () { dz.classList.remove('is-over'); });
            dz.addEventListener('drop', function (e) { e.preventDefault(); dz.classList.remove('is-over'); Array.prototype.forEach.call(e.dataTransfer.files, function (file) { registerUpload(file, function (token) { setMedia(s, key, 'upload:' + token, key !== '__hero' && LIB[s.type].fields[key].type === 'media_list'); }); }); });
            var pick = dz.querySelector('[data-file-pick]');
            if (pick) pick.addEventListener('change', function () { Array.prototype.forEach.call(pick.files, function (file) { registerUpload(file, function (token) { setMedia(s, key, 'upload:' + token, key !== '__hero' && LIB[s.type].fields[key].type === 'media_list'); }); }); });
        });
    }
    function registerUpload(file, cb) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { toast('Yalnız JPG, PNG, WebP.'); return; }
        if (file.size > 5 * 1024 * 1024) { toast('Görsel 5 MB’ı aşamaz.'); return; }
        if (Object.keys(uploads).length >= 20) { toast('Tek kayıtta en fazla 20 görsel.'); return; }
        var token = 'u' + Date.now().toString(36) + Math.floor(Math.random() * 1e4).toString(36);
        var r = new FileReader();
        r.onload = function () { file._url = r.result; uploads[token] = file; cb(token); };
        r.readAsDataURL(file);
    }
    function mediaUrl(id) { if (typeof id === 'string' && id.indexOf('upload:') === 0) return (uploads[id.substring(7)] || {})._url || ''; for (var i = 0; i < MEDIA.length; i++) if (String(MEDIA[i].id) === String(id)) return MEDIA[i].url; return ''; }
    function mediaAlt(id) { for (var i = 0; i < MEDIA.length; i++) if (String(MEDIA[i].id) === String(id)) return MEDIA[i].alt; return ''; }
    function setMedia(s, key, id, multi) {
        if (key === '__hero') { state.heroMedia = id; var d = fdoc(); if (d) { var img = d.querySelector('[data-ofv-site-image="hero"]'); if (img && img.tagName === 'IMG') img.src = mediaUrl(id); } commit(); renderRight(); return; }
        if (multi) { var list = Array.isArray(s.settings[key]) ? s.settings[key].slice() : []; var at = list.map(String).indexOf(String(id)); if (at === -1) list.push(id); else list.splice(at, 1); s.settings[key] = list; renderGallery(s, key); }
        else { s.settings[key] = s.settings[key] === id ? '' : id; renderImage(s, key); }
        commit(); renderRight();
    }
    function renderImage(s, key) {
        var el = fnode(s.id); if (!el) return;
        var target = el.querySelector('[data-ofv-image="' + key + '"]'), url = mediaUrl(s.settings[key]);
        if (!target) return;
        if (target.tagName === 'IMG') { if (url) { target.src = url; target.alt = mediaAlt(s.settings[key]); } else markStale(s.id); }
        else if (url) { var img = fdoc().createElement('img'); img.src = url; img.alt = mediaAlt(s.settings[key]); img.setAttribute('data-ofv-image', key); img.setAttribute('style', 'width:100%;object-fit:cover;border-radius:var(--sec-radius, var(--r-lg));display:block'); target.parentNode.replaceChild(img, target); }
    }
    function renderGallery(s, key) {
        var el = fnode(s.id); if (!el) return;
        var grid = el.querySelector('[data-ofv-gallery]'); if (!grid) { markStale(s.id); return; }
        var ratio = s.settings.ratio || '4/3', ids = s.settings[key] || [], d = fdoc();
        grid.innerHTML = '';
        if (!ids.length) { var ph = d.createElement('div'); ph.className = 'shot'; ph.setAttribute('data-ofv-image', 'media[]'); ph.setAttribute('style', 'aspect-ratio:4/3;border:1px dashed var(--line);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--ink-faint)'); ph.textContent = 'Görsel ekleyin'; grid.appendChild(ph); return; }
        ids.forEach(function (id) { var fig = d.createElement('figure'); fig.style.margin = '0'; var img = d.createElement('img'); img.src = mediaUrl(id); img.alt = mediaAlt(id); img.setAttribute('data-ofv-image', 'media[]'); img.setAttribute('data-ofv-media-id', id); img.setAttribute('style', 'width:100%;aspect-ratio:' + ratio + ';object-fit:cover;border-radius:var(--sec-radius, 12px);display:block'); fig.appendChild(img); grid.appendChild(fig); });
    }
    function imageDrop(ref) {
        registerUpload(ref.file, function (token) {
            if (ref.site === 'hero') { setMedia(null, '__hero', 'upload:' + token, false); selection = { kind: 'image', site: 'hero' }; renderRight(); return; }
            var s = sec(ref.section); if (!s) return;
            var key = (ref.field || '').replace('[]', '');
            if (ref.field && ref.field.slice(-2) === '[]') {
                var list = Array.isArray(s.settings[key]) ? s.settings[key].slice() : [];
                if (ref.mediaId) { var at = list.map(String).indexOf(String(ref.mediaId)); if (at !== -1) list[at] = 'upload:' + token; else list.push('upload:' + token); } else list.push('upload:' + token);
                s.settings[key] = list; renderGallery(s, key);
            } else { s.settings[key] = 'upload:' + token; renderImage(s, key); }
            commit(); select({ kind: 'image', section: s.id, field: ref.field });
            toast('Görsel bırakıldı; kaydedince kütüphaneye yüklenir.');
        });
    }

    /* ---------- Katmanlar ---------- */
    function renderLayers() {
        var ol = $('[data-layers]'); if (!ol) return;
        var h = '<li class="ve-layer ve-layer--global" data-layer-global="header"><span class="ve-layer__grip">▤</span><span class="ve-layer__name">Header &amp; üst şerit</span></li>';
        state.sections.forEach(function (s) {
            var def = LIB[s.type] || { label: s.type, fields: {} }, on = selection && String(selection.section) === String(s.id);
            h += '<li class="ve-layer' + (on ? ' is-selected' : '') + (s.is_visible ? '' : ' is-hidden') + '" draggable="' + (s.locked ? 'false' : 'true') + '" data-layer="' + s.id + '"><span class="ve-layer__grip" title="Sürükle">⋮⋮</span><span class="ve-layer__name" title="' + esc(def.label) + '">' + (linkedGlobal(s) ? '🌐 ' : '') + esc(label(s.id)) + (stale[s.id] ? ' <span class="pill w flat" title="Kaydedince yeniden çizilir">↻</span>' : '') + '</span><span class="ve-layer__acts"><button type="button" data-act="toggle" title="Göster/gizle">' + (s.is_visible ? '◉' : '○') + '</button><button type="button" data-act="lock" title="Kilitle">' + (s.locked ? '🔒' : '🔓') + '</button>' + (def.unique ? '' : '<button type="button" data-act="duplicate" title="Kopyala">⧉</button>') + '<button type="button" data-act="delete" title="Sil">×</button></span></li>';
            if (on) Object.keys(def.fields).forEach(function (k) { if (['text', 'textarea', 'media', 'media_list', 'cta', 'markdown'].indexOf(def.fields[k].type) !== -1) h += '<li class="ve-layer ve-layer--child" data-layer-field="' + esc(k) + '" data-layer-sec="' + s.id + '"><span class="ve-layer__name">' + esc(def.fields[k].label) + '</span></li>'; });
        });
        h += '<li class="ve-layer ve-layer--global" data-layer-global="footer"><span class="ve-layer__grip">▤</span><span class="ve-layer__name">Footer</span></li>';
        ol.innerHTML = h;
    }
    var layersEl = $('[data-layers]');
    if (layersEl) {
        layersEl.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-act]'), li = e.target.closest('li');
            if (!li) return;
            if (b) { sectionAction(li.getAttribute('data-layer'), b.getAttribute('data-act')); return; }
            if (li.hasAttribute('data-layer-global')) { select({ kind: 'global', area: li.getAttribute('data-layer-global') }); var d = fdoc(); if (d) { var ar = d.querySelector('[data-ofv-global-area="' + li.getAttribute('data-layer-global') + '"]'); if (ar) ar.scrollIntoView({ behavior: 'smooth', block: 'start' }); } return; }
            if (li.hasAttribute('data-layer-field')) { var s = sec(li.getAttribute('data-layer-sec')); var f = li.getAttribute('data-layer-field'); var t = LIB[s.type].fields[f].type; select({ kind: t === 'media' || t === 'media_list' ? 'image' : (t === 'markdown' ? 'markdown' : 'field'), section: s.id, field: f }); return; }
            select({ kind: 'section', section: li.getAttribute('data-layer') });
        });
        layersEl.addEventListener('dragstart', function (e) { var li = e.target.closest('li[data-layer]'); if (!li) return; e.dataTransfer.setData('text/ofv-move', li.getAttribute('data-layer')); e.dataTransfer.effectAllowed = 'move'; });
        layersEl.addEventListener('dragover', function (e) { var li = e.target.closest('li[data-layer]'); if (!li) return; e.preventDefault(); $$('.is-dragover', layersEl).forEach(function (x) { x.classList.remove('is-dragover'); }); li.classList.add('is-dragover'); });
        layersEl.addEventListener('dragleave', function (e) { var li = e.target.closest('li'); if (li) li.classList.remove('is-dragover'); });
        layersEl.addEventListener('drop', function (e) {
            var li = e.target.closest('li[data-layer]'); $$('.is-dragover', layersEl).forEach(function (x) { x.classList.remove('is-dragover'); });
            var move = e.dataTransfer.getData('text/ofv-move'), add = e.dataTransfer.getData('text/ofv');
            if (!li) return; e.preventDefault();
            var idx = secIndex(li.getAttribute('data-layer'));
            if (move) moveSection(move, idx); else if (add) addSection(add, idx);
        });
    }

    /* ---------- Palet ---------- */
    $$('[data-left-panel] [data-add-type]').forEach(function (chip) {
        chip.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/ofv', chip.getAttribute('data-add-type')); e.dataTransfer.effectAllowed = 'copy'; });
        chip.addEventListener('click', function (e) { if (e.target.closest('form')) return; var at = selection && selection.section ? secIndex(selection.section) + 1 : state.sections.length; addSection(chip.getAttribute('data-add-type'), at); });
    });
    function refreshPalette() { $$('[data-add-type]').forEach(function (c) { var t = c.getAttribute('data-add-type'); var def = LIB[t]; c.classList.toggle('is-disabled', !!(def && def.unique && state.sections.some(function (s) { return s.type === t; }))); }); }

    /* ---------- Sol sekmeler, cihaz, undo/redo, kaydet ---------- */
    $$('[data-left-tabs] button').forEach(function (b) { b.addEventListener('click', function () { $$('[data-left-tabs] button').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); }); $$('[data-left-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-left-panel') !== b.getAttribute('data-tab'); }); if (b.getAttribute('data-tab') === 'layers') renderLayers(); }); });
    $$('[data-device]').forEach(function (b) { b.addEventListener('click', function () { device = b.getAttribute('data-device'); $$('[data-device]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); }); frameWrap.setAttribute('data-device', device); if (selection && rightTab === 'design') renderRight(); }); });
    function restore(json) {
        var st = JSON.parse(json); state.sections = st.sections; state.texts = st.texts; state.footerColumns = st.footerColumns; state.heroMedia = st.heroMedia; state.blocks = st.blocks || {};
        resyncFrame(); renderLayers(); renderRight(); setDirty(true); updateUndo(); lastSnapshot = snapshot();
    }
    $('[data-undo]').addEventListener('click', function () { if (!history.length) return; future.push(snapshot()); restore(history.pop()); });
    $('[data-redo]').addEventListener('click', function () { if (!future.length) return; history.push(snapshot()); restore(future.pop()); });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); $('[data-undo]').click(); }
        if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) { e.preventDefault(); $('[data-redo]').click(); }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save('stay'); }
    });
    // Aynı kısayollar çerçevede
    var origReady = window.OfisvioEditor.frameReady;
    window.OfisvioEditor.frameReady = function (win) { origReady(win); win.document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && ['z', 'y', 's'].indexOf(e.key.toLowerCase()) !== -1 && !e.target.closest('[contenteditable="true"]')) { e.preventDefault(); if (e.key.toLowerCase() === 's') save('stay'); else if (e.key.toLowerCase() === 'z' && !e.shiftKey) $('[data-undo]').click(); else $('[data-redo]').click(); } }); refreshPalette(); };

    /** Durumu çerçeveye yeniden bindirir (undo/redo, sürüm dönüşü): sıra, eksik bölümler, metinler, stiller. */
    function resyncFrame() {
        var a = api(); if (!a) return;
        var c = a.container(), present = {};
        a.sections().forEach(function (el) { present[el.getAttribute('data-ofv-section')] = el; });
        var tpls = a.templates();
        state.sections.forEach(function (s) {
            var el = present[s.id];
            if (!el) {
                var tpl = tpls[s.type]; if (!tpl) return;
                var frag = fdoc().importNode(tpl.content, true); el = frag.querySelector('[data-ofv-section]'); el.setAttribute('data-ofv-section', s.id); el.id = 'sec-' + s.id; c.appendChild(el); a.makeDraggable(el); markStale(s.id);
            }
            c.appendChild(el); delete present[s.id];
            var def = LIB[s.type];
            Object.keys(def.fields).forEach(function (k) {
                var f = def.fields[k];
                if (f.type === 'text' || f.type === 'textarea') {
                    // Boş alan: global metin (texts) basılır; bölüm değeri varsa o.
                    if (s.settings[k]) applyText(s, k, s.settings[k]); else if (def.texts && def.texts[k]) applyText(s, k, state.texts[def.texts[k]] || '');
                    applyFieldStyle(s, k);
                }
                if (f.type === 'media') renderImage(s, k);
                if (f.type === 'media_list') renderGallery(s, k);
            });
            if (s.locked) el.setAttribute('data-ofv-locked', '1'); else el.removeAttribute('data-ofv-locked');
            applySectionStyle(s);
        });
        Object.keys(present).forEach(function (id) { present[id].remove(); });
        Object.keys(state.texts).forEach(function (k) { applyGlobalText(k, state.texts[k]); });
        if (state.sections.length) a.clearEmpty();
    }

    function save(then) {
        if (api()) api().stopEdit();
        var payload = { sections: state.sections.map(function (s) { var r = JSON.parse(JSON.stringify(s)); if (String(r.id).indexOf('n') === 0) r.id = null; return r; }), globals: { texts: state.texts, footer_columns: state.footerColumns, hero_media: state.heroMedia || '', blocks: state.blocks } };
        var form = $('[data-save-form]');
        $('[data-payload]').value = JSON.stringify(payload);
        $('[data-save-then]').value = then;
        var slot = $('[data-upload-slot]'); slot.innerHTML = '';
        Object.keys(uploads).forEach(function (token) { var inp = document.createElement('input'); inp.type = 'file'; inp.name = 'uploads[' + token + ']'; var dt = new DataTransfer(); dt.items.add(uploads[token]); inp.files = dt.files; slot.appendChild(inp); });
        setDirty(false);
        form.submit();
    }
    $('[data-save]').addEventListener('click', function () { save('stay'); });
    $('[data-save-preview]').addEventListener('click', function () { save('preview'); });
    var pubForm = $('[data-publish-form]');
    if (pubForm) pubForm.addEventListener('submit', function (e) {
        if (dirty) { e.preventDefault(); if (window.confirm('Kaydedilmemiş değişiklikler var. Önce kaydedilsin mi? (Kaydettikten sonra Yayınla\'ya yeniden basın.)')) save('stay'); return; }
        var note = window.prompt('Yayın notu (isteğe bağlı):', ''); if (note === null) { e.preventDefault(); return; } pubForm.querySelector('[name="note"]').value = note.substring(0, 200);
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    /* ---------- Sürüm önizleme ---------- */
    $$('[data-preview-revision]').forEach(function (b) {
        b.addEventListener('click', function () {
            var n = b.getAttribute('data-preview-revision');
            if (n === '') { if (frame.src === cfg.frameUrl) return; frame.src = cfg.frameUrl; toast('Taslak görünümü.'); return; }
            if (dirty && !window.confirm('Kaydedilmemiş değişiklikler var; sürüm görünümüne geçince çerçeve yenilenir (durum bellekte kalır, dönünce yeniden uygulanır). Devam?')) return;
            frameWin = null; frame.src = cfg.revisionPreviewBase + '&revision=' + n; toast('Versiyon ' + n + ' görünümü (salt okunur). Taslağa dönmek için "Taslağa dön".');
        });
    });

    /* ---------- Sayfalar sekmesi ---------- */
    $$('[data-open-global]').forEach(function (b) { b.addEventListener('click', function () { select({ kind: 'global', area: b.getAttribute('data-open-global') }); }); });
    var np = $('[data-newpage-mode]');
    if (np) np.addEventListener('change', function () { $$('[data-newpage-for]').forEach(function (el) { el.hidden = el.getAttribute('data-newpage-for') !== np.value; }); });

    /* ---------- ✦ AI tasarım yardımcısı (kural tabanlı; mevcut bileşen sistemiyle öneri → onay → uygula) ---------- */
    var TYPE_WORDS = { hero: ['hero', 'giriş', 'kapak'], solutions: ['hizmet', 'çözüm', 'servis'], blog: ['blog', 'yazı'], faq: ['sss', 'soru'], testimonials: ['referans', 'yorum', 'görüş'], gallery: ['galeri', 'foto'], pricing: ['fiyat', 'üyelik', 'plan'], lead_form: ['iletişim', 'form', 'teklif'], map: ['harita'], cta_banner: ['cta', 'çağrı', 'şerit'], features: ['özellik', 'kart', 'avantaj'], locations: ['lokasyon', 'şube'], stats: ['istatistik', 'rakam', 'sayı'], journey: ['nasıl çalışır', 'adım'], amenities: ['dahil', 'olanak'], columns: ['kolon', 'sütun'], divider: ['ayırıcı', 'çizgi'], spacer: ['boşluk'], image: ['görsel', 'resim', 'fotoğraf'], heading: ['başlık'], rich_text: ['metin', 'paragraf'], buttons: ['buton', 'düğme'], meeting: ['toplantı', 'oda'], content: ['içerik bloğu'] };
    function findType(cmd) { var best = null; if (/(\d|bir|iki|üç|dört)\s*kolon/.test(cmd)) cmd = cmd.replace(/kolon/g, ''); Object.keys(TYPE_WORDS).forEach(function (t) { TYPE_WORDS[t].forEach(function (w) { if (cmd.indexOf(w) !== -1 && (!best || w.length > best.w.length)) best = { t: t, w: w }; }); }); return best ? best.t : null; }
    function targetSection(cmd) {
        if (/\b(bu|seçili|şu)\b/.test(cmd) && selection && selection.section) return sec(selection.section);
        var t = findType(cmd); if (t) { for (var i = 0; i < state.sections.length; i++) if (state.sections[i].type === t) return state.sections[i]; }
        return selection && selection.section ? sec(selection.section) : null;
    }
    function aiPlan(raw) {
        var cmd = raw.toLowerCase().replace(/i̇/g, 'i'), steps = [], target = targetSection(cmd), t = findType(cmd), n = (cmd.match(/(\d+)/) || [])[1];
        var dev = /mobil/.test(cmd) ? 'mobile' : (/tablet/.test(cmd) ? 'tablet' : 'desktop');
        var wantAdd = /ekle|oluştur|koy|yerleştir/.test(cmd);
        if (wantAdd && t && (!target || target.type !== t || !(LIB[t].unique))) {
            if (LIB[t].unique && state.sections.some(function (s) { return s.type === t; })) steps.push({ desc: LIB[t].label + ' zaten sayfada — seçilecek.', run: function () { var s = state.sections.filter(function (x) { return x.type === t; })[0]; select({ kind: 'section', section: s.id }); } });
            else steps.push({ desc: LIB[t].label + ' bölümü ' + (selection && selection.section ? 'seçili bölümün altına' : 'sayfa sonuna') + ' eklenecek' + (n && t === 'features' ? ' (' + n + ' kart)' : '') + '.', run: function () { var at = selection && selection.section ? secIndex(selection.section) + 1 : state.sections.length; addSection(t, at); if (n && t === 'features') { var s = state.sections[at]; var items = []; for (var i = 0; i < Math.min(6, parseInt(n, 10)); i++) items.push('✓ | Kart ' + (i + 1) + ' | Açıklama'); s.settings.items = items; markStale(s.id); commit(); } } });
            if (t === 'solutions' && n) steps.push({ desc: 'Hizmet kartları gerçek Hizmetler modülünden gelir; ' + n + ' kart için Hizmetler ekranında ' + n + ' aktif hizmet olmalı (uydurma kart üretilmez).', run: null });
        }
        if (!target && !steps.length) return { steps: [], note: 'Hedef bölüm bulunamadı: bir bölüm seçin ya da adını yazın (hero, SSS, referanslar, galeri…).' };
        if (target) {
            var stKey = DEVICE_KEY[dev];
            var setStyle = function (k, v, desc) { steps.push({ desc: desc, run: function () { target.settings[stKey] = target.settings[stKey] || {}; target.settings[stKey][k] = v; applySectionStyle(target); } }); };
            if (/modern|ferah|geniş|havalı|büyüt/.test(cmd)) { setStyle('pt', 96, label(target.id) + ': üst/alt boşluk artırılacak (96px).'); setStyle('pb', 96, ''); setStyle('radius', 24, 'Köşeler yumuşatılacak (24px).'); setStyle('shadow', 'md', 'Orta gölge.'); if (['hero', 'cta_banner'].indexOf(target.type) !== -1) setStyle('bg', 'warm', 'Sıcak arka plan.'); }
            if (/koyu|karanlık|siyah/.test(cmd)) setStyle('bg', 'dark', label(target.id) + ': koyu arka plan.');
            if (/açık|beyaz|aydınlık/.test(cmd) && !/açıkla/.test(cmd)) setStyle('bg', 'surface', label(target.id) + ': açık arka plan.');
            if (/marka rengi|yeşil|brand/.test(cmd)) setStyle('bg', 'brand', label(target.id) + ': marka rengi arka plan.');
            if (/ortala|ortaya/.test(cmd)) setStyle('align', 'center', label(target.id) + ': ortalanacak.');
            if (/sıkı|daralt|küçült|dar/.test(cmd)) { setStyle('pt', 32, label(target.id) + ': boşluklar azalacak (32px).'); setStyle('pb', 32, ''); }
            var colm = cmd.match(/(\d|bir|iki|üç|dört)\s*kolon/); if (colm) { var map = { bir: 1, iki: 2, üç: 3, dört: 4 }; var cols = map[colm[1]] || parseInt(colm[1], 10); setStyle('cols', cols, label(target.id) + ': ' + { desktop: 'masaüstünde', tablet: 'tablette', mobile: 'mobilde' }[dev] + ' ' + cols + ' kolon.'); }
            if (/gizle|kaldır/.test(cmd) && !wantAdd) steps.push({ desc: label(target.id) + ' gizlenecek.', run: function () { target.is_visible = false; applySectionStyle(target); } });
            if (/göster|aç/.test(cmd) && !target.is_visible) steps.push({ desc: label(target.id) + ' gösterilecek.', run: function () { target.is_visible = true; applySectionStyle(target); } });
            if (/sil/.test(cmd) && !wantAdd) steps.push({ desc: label(target.id) + ' silinecek.', run: function () { var i = secIndex(target.id); var node = fnode(target.id); if (node) node.remove(); state.sections.splice(i, 1); select(null); } });
            if (/görsel(i|ini)? değiştir|resmi değiştir/.test(cmd)) steps.push({ desc: 'Görsel seçici açılacak (medya kütüphanesi / bırak).', run: function () { var f = Object.keys(LIB[target.type].fields).filter(function (k) { return LIB[target.type].fields[k].type === 'media'; })[0]; select(f ? { kind: 'image', section: target.id, field: f } : { kind: 'image', site: 'hero' }); } });
            var tm = cmd.match(/başlı(k|ğı)[:\s]+["“']?([^"”']{3,80})["”']?$/); if (tm && LIB[target.type].fields.title) steps.push({ desc: 'Başlık → "' + tm[2] + '"', run: function () { target.settings.title = tm[2]; applyText(target, 'title', tm[2]); } });
            if (/yukarı|üste/.test(cmd)) steps.push({ desc: label(target.id) + ' bir üste taşınacak.', run: function () { var i = secIndex(target.id); if (i > 0) moveSection(target.id, i - 1); } });
            if (/aşağı|alta/.test(cmd) && !/altına/.test(cmd)) steps.push({ desc: label(target.id) + ' bir alta taşınacak.', run: function () { var i = secIndex(target.id); moveSection(target.id, i + 2); } });
        }
        if (!steps.length) return { steps: [], note: 'Anlaşılmadı. Örnekler için "Örnekler"e basın.' };
        return { steps: steps, note: null };
    }
    var aiOut = $('[data-ai-out]');
    function runAi() {
        var raw = $('[data-ai-input]').value.trim(); if (!raw) return;
        var plan = aiPlan(raw);
        aiOut.hidden = false;
        if (!plan.steps.length) { aiOut.innerHTML = '<b>✦</b> ' + esc(plan.note); return; }
        aiOut.innerHTML = '<b>✦ Öneri</b><ul>' + plan.steps.map(function (s) { return s.desc ? '<li>' + esc(s.desc) + '</li>' : ''; }).join('') + '</ul><div style="display:flex;gap:6px"><button type="button" class="btn btn--brand" data-ai-apply>Uygula (önizle)</button><button type="button" class="btn btn--quiet" data-ai-cancel>Vazgeç</button></div><p class="small muted" style="margin:6px 0 0">Uygulanınca çerçevede görünür; beğenmezseniz Geri al. Kaydetmeden yayına çıkmaz.</p>';
        aiOut.querySelector('[data-ai-apply]').addEventListener('click', function () { plan.steps.forEach(function (s) { if (s.run) s.run(); }); commit(); renderLayers(); renderRight(); aiOut.innerHTML = '<b>✦</b> Uygulandı — Kaydet ile taslağa yazın, Önizle ile gerçek görünümü açın.'; });
        aiOut.querySelector('[data-ai-cancel]').addEventListener('click', function () { aiOut.hidden = true; });
    }
    var aiRun = $('[data-ai-run]');
    if (aiRun) { aiRun.addEventListener('click', runAi); $('[data-ai-input]').addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) runAi(); }); $('[data-ai-help]').addEventListener('click', function () { aiOut.hidden = false; aiOut.innerHTML = '<b>✦ Örnek komutlar</b><ul><li>Hero bölümünü daha modern yap</li><li>Bu bölüme 3 kart ekle (özellikler)</li><li>SSS ekle · Referanslar ekle · Galeri ekle · Harita ekle</li><li>Bu bölümü mobilde iki kolona çevir · Tablette 2 kolon</li><li>Referanslar bölümünü koyu yap · Ortala · Daralt</li><li>Bu görseli değiştir</li><li>Hizmetler başlığı: Çözümlerimiz</li><li>SSS bölümünü gizle · Yukarı taşı</li></ul>'; }); }

    /* ---------- Blok kütüphanesi penceresi (+ Blok ekle) ve kütüphaneden gelen ?ekle= ---------- */
    var libModal = document.getElementById('modal-block-library');
    if (libModal) {
        libModal.addEventListener('click', function (e) {
            var b = e.target.closest('[data-modal-add]'); if (!b || b.disabled) return;
            var at = selection && selection.section ? secIndex(selection.section) + 1 : state.sections.length;
            addSection(b.getAttribute('data-add-type'), at); libModal.close();
        });
        $$('[data-add-type]', libModal).forEach(function (chip) { chip.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/ofv', chip.getAttribute('data-add-type')); e.dataTransfer.effectAllowed = 'copy'; libModal.close(); }); });
    }
    var pendingAdd = cfg.add || '';
    var readyBefore = window.OfisvioEditor.frameReady;
    window.OfisvioEditor.frameReady = function (win) { readyBefore(win); if (pendingAdd) { var what = pendingAdd; pendingAdd = ''; addSection(what, state.sections.length); window.history.replaceState(null, '', location.pathname + location.search.replace(/[?&]ekle=[^&]*/, '').replace(/^&/, '?')); } };

    /* ---------- Başlangıç ---------- */
    updateUndo();
    renderLayers();
    renderPageInfo();
})();
