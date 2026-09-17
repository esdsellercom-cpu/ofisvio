/* Ofisvio CMS stüdyo (faz 48) — bağımlılıksız. Sekmeler, Markdown araç çubuğu, blok ekleme, canlı SEO analizi
   (App\Content\SeoAnalyzer'ın aynası), iç bağlantı önerisi, görsel ekleme/kapak/kırpma, kaydedilmemiş değişiklik uyarısı,
   önizleme cihaz seçici. Tarayıcı depolaması kullanılmaz; HTTP çağrısı yapılmaz — medya işlemleri normal form gönderimidir. */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* ---------- Sekmeler (URL çapası ile; doğrulama hatasında hatalı alanın sekmesi açılır) ---------- */
    function initTabs(form) {
        var nav = $('[data-cms-tabs]', form);
        if (!nav) return;
        var links = $$('[data-tab]', nav);
        var panels = $$('[data-tab-panel]', form);
        function show(name) {
            links.forEach(function (a) { a.getAttribute('data-tab') === name ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current'); });
            panels.forEach(function (p) { p.hidden = p.getAttribute('data-tab-panel') !== name; });
        }
        links.forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-tab')); history.replaceState(null, '', a.getAttribute('href')); });
        });
        var invalid = $('[aria-invalid="true"]', form);
        var panel = invalid ? invalid.closest('[data-tab-panel]') : null;
        var initial = panel ? panel.getAttribute('data-tab-panel') : (location.hash && $('[data-tab-panel="' + location.hash.replace('#sekme-', '') + '"]', form) ? location.hash.replace('#sekme-', '') : 'icerik');
        show(initial);
        form.cmsShowTab = show;
    }

    /* ---------- Editör yardımcıları ---------- */
    function replaceSelection(ta, before, after, placeholder) {
        var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
        var sel = v.substring(s, e) || placeholder || '';
        var text = before + sel + (after || '');
        ta.value = v.substring(0, s) + text + v.substring(e);
        ta.focus();
        ta.selectionStart = s + before.length;
        ta.selectionEnd = s + before.length + sel.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function insertBlock(ta, text) {
        var s = ta.selectionStart, v = ta.value;
        var pre = s > 0 && v[s - 1] !== '\n' ? '\n\n' : (s > 1 && v[s - 2] !== '\n' && s > 0 ? '\n' : '');
        var post = v[s] && v[s] !== '\n' ? '\n\n' : '\n';
        ta.value = v.substring(0, s) + pre + text + post + v.substring(s);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = s + pre.length + text.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function prefixLines(ta, prefix, numbered) {
        var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
        var ls = v.lastIndexOf('\n', s - 1) + 1;
        var le = v.indexOf('\n', e); if (le === -1) le = v.length;
        var lines = v.substring(ls, le).split('\n');
        var out = lines.map(function (l, i) { return (numbered ? (i + 1) + '. ' : prefix) + l.replace(/^(#{1,6}\s+|[-*]\s+|\d+\.\s+|>\s+)/, ''); }).join('\n');
        ta.value = v.substring(0, ls) + out + v.substring(le);
        ta.focus();
        ta.selectionStart = ls; ta.selectionEnd = ls + out.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    var BLOCK_TEMPLATES = {
        hero: ':::hero\ntitle: Başlık\ntext: Kısa açıklama\nbutton: Teklif al\nlink: /iletisim\n:::',
        cta: ':::cta\ntitle: Hemen başlayın\ntext: Bugün ofisinizi kurun.\nbutton: İletişime geçin\nlink: /iletisim\n:::',
        features: ':::features\ntitle: Neden biz?\n- Yasal adres | Resmî yazışma adresi | /hizmetler\n- Posta yönetimi | Bildirim ve tarama\n- Toplantı odası | Saatlik rezervasyon\n:::',
        faq: ':::faq\n- Sanal ofis nedir? | Şirketinizin yasal adresi olarak kullanabileceğiniz ofis hizmetidir.\n- Kaç günde kurulur? | Evraklar tamamlanınca aynı gün.\n:::',
        stats: ':::stats\n- 12 | Lokasyon\n- 3.000+ | Şirket\n- 7/24 | Destek\n:::',
        gallery: ':::gallery\n- /media/ornek.jpg | Açıklama\n:::',
        contact: ':::contact\ntitle: Bize ulaşın\ntext: Formu doldurun, sizi arayalım.\nbutton: İletişim\nlink: /iletisim\n:::',
        box: ':::box\ntitle: Bilgi\ntext: Vurgulanacak kısa metin.\n:::',
        testimonials: ':::testimonials\n- Müşteri adı, Şirket | Görüşü buraya\n:::'
    };

    function initToolbar(form, ta) {
        var tb = $('[data-cms-toolbar]', form);
        if (!tb || !ta) return;
        tb.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-md]');
            if (!b) return;
            e.preventDefault();
            var url, label;
            switch (b.getAttribute('data-md')) {
                case 'bold': replaceSelection(ta, '**', '**', 'kalın metin'); break;
                case 'italic': replaceSelection(ta, '_', '_', 'italik metin'); break;
                case 'ul': prefixLines(ta, '- '); break;
                case 'ol': prefixLines(ta, '', true); break;
                case 'quote': prefixLines(ta, '> '); break;
                case 'link':
                    url = window.prompt('Bağlantı adresi (https://… ya da /sayfa):', 'https://');
                    if (url) replaceSelection(ta, '[', '](' + url + ')', 'bağlantı metni');
                    break;
                case 'internal': openLinkSuggest(form, ta, true); break;
                case 'image':
                    if (form.cmsShowTab) form.cmsShowTab('gorseller');
                    break;
                case 'youtube':
                    url = window.prompt('YouTube video adresi ya da ID:', '');
                    if (url) { var m = url.match(/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/); insertBlock(ta, '[youtube:' + (m ? m[1] : url.trim()) + ']'); }
                    break;
                case 'embed':
                    url = window.prompt('Gömme adresi (YouTube, Vimeo, Google Haritalar):', 'https://');
                    if (url) insertBlock(ta, '[embed:' + url.trim() + ']');
                    break;
                case 'table': insertBlock(ta, '| Başlık | Başlık |\n|---|---|\n| Hücre | Hücre |\n| Hücre | Hücre |'); break;
                case 'button':
                    label = window.prompt('Buton metni:', 'Teklif al');
                    url = label ? window.prompt('Buton adresi:', '/iletisim') : null;
                    if (label && url) insertBlock(ta, '[button:' + label + '](' + url + ')');
                    break;
                case 'code': replaceSelection(ta, '\n```\n', '\n```\n', 'kod'); break;
                case 'hr': insertBlock(ta, '---'); break;
            }
        });
        tb.addEventListener('change', function (e) {
            var s = e.target;
            if (s.getAttribute('data-md') === 'heading' && s.value) { prefixLines(ta, '#'.repeat(parseInt(s.value, 10)) + ' '); s.value = ''; }
            if (s.getAttribute('data-md') === 'block' && s.value) { insertBlock(ta, BLOCK_TEMPLATES[s.value] || (':::' + s.value + '\n:::')); s.value = ''; }
        });
    }

    /* ---------- İç bağlantı önerisi (yazarken "[[" ya da araç çubuğu) ---------- */
    var targets = [];
    function openLinkSuggest(form, ta, manual) {
        var box = $('[data-link-suggest]', form);
        if (!box) return;
        var q = '';
        if (!manual) {
            var upto = ta.value.substring(0, ta.selectionStart);
            var m = upto.match(/\[\[([^\]\n]{0,60})$/);
            if (!m) { box.hidden = true; return; }
            q = m[1].toLowerCase();
        }
        var hits = targets.filter(function (t) { return !q || t.title.toLowerCase().indexOf(q) !== -1 || t.path.indexOf(q) !== -1; }).slice(0, 8);
        if (!hits.length) { box.hidden = true; return; }
        box.innerHTML = '';
        var head = document.createElement('div'); head.className = 'cms-suggest__head'; head.textContent = manual ? 'Sayfa seçin (seçili metin bağlantı olur)' : 'İç bağlantı önerisi — [[ ile arayın'; box.appendChild(head);
        hits.forEach(function (t) {
            var b = document.createElement('button'); b.type = 'button';
            b.innerHTML = '<b></b> <span class="mono muted"></span>';
            b.firstChild.textContent = t.title; b.lastChild.textContent = t.path;
            b.addEventListener('click', function () {
                if (manual) {
                    replaceSelection(ta, '[', '](' + t.path + ')', t.title);
                } else {
                    var s = ta.selectionStart, v = ta.value, start = v.lastIndexOf('[[', s);
                    ta.value = v.substring(0, start) + '[' + t.title + '](' + t.path + ')' + v.substring(s);
                    ta.selectionStart = ta.selectionEnd = start + t.title.length + t.path.length + 4;
                    ta.focus();
                    ta.dispatchEvent(new Event('input', { bubbles: true }));
                }
                box.hidden = true;
            });
            box.appendChild(b);
        });
        box.hidden = false;
    }
    function initLinkSuggest(form, ta) {
        try { targets = JSON.parse(form.getAttribute('data-link-targets') || '[]'); } catch (e) { targets = []; }
        if (!ta) return;
        ta.addEventListener('input', function () { openLinkSuggest(form, ta, false); });
        ta.addEventListener('keydown', function (e) { if (e.key === 'Escape') { var b = $('[data-link-suggest]', form); if (b) b.hidden = true; } });
        $$('[data-insert-link]', form).forEach(function (b) {
            b.addEventListener('click', function () {
                insertBlock(ta, '[' + b.getAttribute('data-insert-text') + '](' + b.getAttribute('data-insert-link') + ')');
                if (form.cmsShowTab) form.cmsShowTab('icerik');
            });
        });
    }

    /* ---------- Canlı SEO analizi (SeoAnalyzer aynası; skor kayıtta sunucuda hesaplanır) ---------- */
    function countMatches(re, s) { var m = s.match(re); return m ? m.length : 0; }
    function analyze(f) {
        var checks = [];
        var add = function (key, ok, level, okMsg, badMsg) { checks.push({ key: key, ok: ok, level: level, message: ok ? okMsg : badMsg }); };
        var title = (f.meta_title || f.title || '').trim();
        var description = (f.meta_description || f.excerpt || '').trim();
        var body = f.body || '';
        var keyword = (f.focus_keyword || '').trim().toLowerCase();
        var text = body.replace(/<[^>]*>/g, '').toLowerCase();
        var words = Math.max(1, countMatches(/\p{L}+/gu, text));
        var tl = title.length, dl = description.length;
        add('title', tl >= 30 && tl <= 70, 'error', 'Başlık ' + tl + ' karakter (30–70).', tl === 0 ? 'SEO başlığı yok.' : 'Başlık ' + tl + ' karakter; 30–70 arası önerilir.');
        add('description', dl >= 50 && dl <= 160, 'error', 'Meta açıklama ' + dl + ' karakter (50–160).', dl === 0 ? 'Meta açıklama yok (özet de boş).' : 'Meta açıklama ' + dl + ' karakter; 50–160 arası önerilir.');
        var h1 = countMatches(/^#\s+/gm, body);
        add('h1', h1 === 0, 'warn', 'Gövdede H1 yok; sayfa başlığı H1 (doğru).', 'Gövdede ' + h1 + ' adet H1 (#) var; sayfa başlığı zaten H1 — ## ile başlayın.');
        var levels = [], hm, re = /^(#{2,6})\s+\S/gm;
        while ((hm = re.exec(body)) !== null) levels.push(hm[1].length);
        var jump = false, prev = 1;
        levels.forEach(function (l) { if (l > prev + 1) jump = true; prev = l; });
        add('headings', levels.length >= 1 && !jump, 'warn', levels.length + ' alt başlık, düzeyler sıralı.', levels.length === 0 ? 'Alt başlık (##) yok; içeriği bölümleyin.' : 'Başlık düzeyleri atlıyor (örn. ## sonra ####).');
        var internal = countMatches(/\]\((\/[^)\s]*)\)/g, body);
        add('internal_links', internal >= 1, 'warn', internal + ' iç bağlantı.', 'İç bağlantı yok; ilgili sayfalara en az bir bağlantı verin.');
        var im, images = 0, noAlt = 0, ire = /!\[([^\]]*)\]\(/g;
        while ((im = ire.exec(body)) !== null) { images++; if (!im[1].trim()) noAlt++; }
        add('images', images >= 1 || !!f.cover, 'info', images + ' görsel' + (f.cover ? ' + kapak' : '') + '.', 'Görsel yok; en az bir görsel ya da kapak ekleyin.');
        add('alt', noAlt === 0, 'warn', 'Tüm görsellerde alt metin var.', noAlt + ' görselin alt metni boş.');
        if (keyword) {
            var count = text.split(keyword).length - 1, density = count / words * 100;
            add('keyword_title', title.toLowerCase().indexOf(keyword) !== -1, 'error', 'Odak kelime başlıkta.', 'Odak kelime SEO başlığında geçmiyor.');
            add('keyword_description', description.toLowerCase().indexOf(keyword) !== -1, 'warn', 'Odak kelime meta açıklamada.', 'Odak kelime meta açıklamada geçmiyor.');
            add('keyword_slug', (f.slug || '').indexOf(keyword.replace(/ /g, '-')) !== -1, 'info', "Odak kelime slug'da.", "Odak kelime slug'da yok.");
            add('keyword_intro', text.substring(0, 400).indexOf(keyword) !== -1, 'warn', 'Odak kelime giriş paragrafında.', 'Odak kelime ilk 400 karakterde geçmiyor.');
            add('keyword_density', count >= 1 && density <= 3, 'warn', 'Odak kelime ' + count + ' kez (%' + density.toFixed(1) + ' yoğunluk).', count === 0 ? 'Odak kelime gövdede hiç geçmiyor.' : 'Odak kelime ' + count + ' kez (%' + density.toFixed(1) + ') — aşırı tekrar (>%3).');
        } else {
            add('keyword', false, 'warn', '', 'Odak anahtar kelime tanımlı değil.');
        }
        add('length', words >= 300, 'warn', words + ' kelime.', 'Gövde ' + words + ' kelime; en az 300 önerilir.');
        var canonical = (f.canonical_url || '').trim();
        add('canonical', canonical === '' || canonical.indexOf('https://') === 0 || canonical.indexOf('/') === 0, 'info', canonical === '' ? 'Canonical otomatik (sayfanın kendi adresi).' : 'Canonical: ' + canonical, 'Canonical adres https:// ya da / ile başlamalı.');
        add('schema', f.schema_types.length > 0, 'info', 'Şema: ' + f.schema_types.join(', '), 'Şema türü seçilmedi (varsayılan WebPage/Article + BreadcrumbList basılır).');
        add('og', (f.og_title || '').trim() !== '' || tl > 0, 'info', 'Open Graph başlığı hazır.', 'OG başlığı yok.');
        var weights = { error: 3, warn: 2, info: 1 }, max = 0, got = 0;
        checks.forEach(function (c) { max += weights[c.level]; got += c.ok ? weights[c.level] : 0; });
        return { score: max ? Math.round(got / max * 100) : 0, checks: checks };
    }
    function scoreLabel(s) { return s >= 80 ? 'İyi' : (s >= 50 ? 'Orta' : 'Zayıf'); }
    function pillClass(c) { return c.ok ? 'g' : (c.level === 'error' ? 'c' : (c.level === 'warn' ? 'w' : 'n')); }
    function renderChecks(ul, checks, onlyBad) {
        if (!ul) return;
        ul.innerHTML = '';
        checks.forEach(function (c) {
            if (onlyBad && c.ok) return;
            var li = document.createElement('li');
            var pill = document.createElement('span'); pill.className = 'pill ' + pillClass(c) + ' flat'; pill.textContent = c.ok ? '✓' : '!';
            li.appendChild(pill); li.appendChild(document.createTextNode(' ' + c.message));
            ul.appendChild(li);
        });
        if (onlyBad && !ul.children.length) { var ok = document.createElement('li'); ok.className = 'muted'; ok.textContent = 'Tüm kontroller geçti.'; ul.appendChild(ok); }
    }
    function initSeoLive(form) {
        var fields = $$('[data-seo-field]', form);
        if (!fields.length) return;
        function collect() {
            var f = { schema_types: [] };
            fields.forEach(function (el) {
                var k = el.getAttribute('data-seo-field');
                if (k === 'schema_types') { if (el.checked) f.schema_types.push(el.value); return; }
                f[k] = el.value;
            });
            var cover = $('[name="cover_media_id"]', form);
            f.cover = cover ? cover.value : '';
            return f;
        }
        function run() {
            var f = collect(), r = analyze(f);
            var score = $('[data-seo-score]', form); if (score) { score.textContent = r.score; score.className = 'pill ' + (r.score >= 80 ? 'g' : (r.score >= 50 ? 'w' : 'c')) + ' flat'; }
            var big = $('[data-seo-score-big]', form); if (big) big.textContent = r.score;
            var lab = $('[data-seo-score-label]', form); if (lab) lab.textContent = scoreLabel(r.score);
            renderChecks($('[data-seo-checks]', form), r.checks, true);
            renderChecks($('[data-seo-checks-full]', form), r.checks, false);
            var st = $('[data-serp-title]', form); if (st) st.textContent = (f.meta_title || f.title || 'Başlık');
            var sd = $('[data-serp-desc]', form); if (sd) sd.textContent = (f.meta_description || f.excerpt || 'Meta açıklama…');
            var su = $('[data-serp-url]', form); if (su) su.textContent = su.textContent.replace(/\/[^\/]*$/, '/' + (f.slug || ''));
            var sp = $('[data-slug-preview]', form); if (sp) sp.textContent = f.slug || 'slug';
            $$('[data-count-for]', form).forEach(function (c) { var el = $('[name="' + c.getAttribute('data-count-for') + '"]', form); if (el) c.textContent = '· ' + el.value.length + '/' + (el.getAttribute('maxlength') || ''); });
        }
        var timer = null;
        form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 250); });
        form.addEventListener('change', run);
        run();
    }

    /* ---------- Kapak seçimi önizlemesi ---------- */
    function initCover(form) {
        var sel = $('[data-cover-select]', form), img = $('[data-cover-preview]', form);
        if (!sel || !img) return;
        function sync() { var o = sel.options[sel.selectedIndex]; var u = o ? o.getAttribute('data-url') : ''; if (u) { img.src = u; img.style.display = ''; } else { img.style.display = 'none'; } }
        sel.addEventListener('change', sync);
        $$('[data-make-cover]', form).forEach(function (b) {
            b.addEventListener('click', function () {
                var id = b.closest('[data-media-id]').getAttribute('data-media-id');
                sel.value = id; sync(); sel.dispatchEvent(new Event('change', { bubbles: true }));
                b.textContent = 'Kapak ✓';
            });
        });
    }

    /* ---------- Görsel ekleme (Markdown + hizalama) ---------- */
    function initImageInsert(form, ta) {
        $$('[data-insert-image]', form).forEach(function (b) {
            b.addEventListener('click', function () {
                var card = b.closest('[data-media-id]');
                var alt = card.getAttribute('data-media-alt') || window.prompt('Alt metin (SEO için zorunlu):', '') || '';
                var align = window.prompt('Hizalama: left / right / center / full', 'center') || 'center';
                var width = align === 'full' ? '' : (window.prompt('Genişlik (örn. 50%, boş = tam):', align === 'center' ? '100%' : '45%') || '');
                var title = card.getAttribute('data-media-title');
                var attrs = '{' + align + (width ? ' width=' + width : '') + '}';
                insertBlock(ta, '![' + alt + '](' + card.getAttribute('data-media-url') + (title ? ' "' + title.replace(/"/g, '') + '"' : '') + ')' + attrs);
                if (form.cmsShowTab) form.cmsShowTab('icerik');
            });
        });
    }

    /* ---------- Medya formları (yükleme / meta / kırpma): ayrı gizli formlar, normal POST ---------- */
    function confirmLeave(form) {
        return !form.cmsDirty || window.confirm('Kaydedilmemiş içerik değişiklikleri var; medya işlemi sayfayı yeniler. Devam edilsin mi?');
    }
    function initMediaForms(form) {
        var up = $('[data-media-upload]', form), upForm = $('[data-media-upload-form]');
        if (up && upForm) {
            $('[data-upload-submit]', up).addEventListener('click', function () {
                var file = $('[data-upload-file]', up);
                if (!file.files.length) { window.alert('Önce bir dosya seçin.'); return; }
                if (!confirmLeave(form)) return;
                upForm.action = up.getAttribute('data-action');
                var dt = new DataTransfer(); dt.items.add(file.files[0]);
                $('[name="file"]', upForm).files = dt.files;
                $('[name="alt"]', upForm).value = $('[data-upload-alt]', up).value;
                $('[name="title"]', upForm).value = $('[data-upload-title]', up).value;
                $('[name="caption"]', upForm).value = $('[data-upload-caption]', up).value;
                $('[name="seo_name"]', upForm).value = $('[data-upload-seoname]', up).value;
                form.cmsDirty = false;
                upForm.submit();
            });
        }
        var metaForm = $('[data-media-meta-form]');
        $$('[data-meta-save]', form).forEach(function (b) {
            b.addEventListener('click', function () {
                if (!metaForm || !confirmLeave(form)) return;
                var box = b.closest('[data-media-meta]');
                metaForm.action = b.getAttribute('data-action');
                $('[name="alt"]', metaForm).value = $('[data-meta-alt]', box).value;
                $('[name="title"]', metaForm).value = $('[data-meta-title]', box).value;
                $('[name="caption"]', metaForm).value = $('[data-meta-caption]', box).value;
                form.cmsDirty = false;
                metaForm.submit();
            });
        });
        initCrop(form);
    }

    /* ---------- Kırpma: canvas üzerinde seçim → base64 → sunucu (karantina zinciri) ---------- */
    function initCrop(form) {
        var panel = $('[data-crop-panel]', form), cropForm = $('[data-media-crop-form]');
        if (!panel || !cropForm) return;
        var canvas = $('[data-crop-canvas]', panel), ctx = canvas.getContext('2d');
        var img = null, mediaId = null, sel = null, drag = null, scale = 1;
        function draw() {
            if (!img) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            if (sel) {
                ctx.fillStyle = 'rgba(0,0,0,.45)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, sel.x / scale, sel.y / scale, sel.w / scale, sel.h / scale, sel.x, sel.y, sel.w, sel.h);
                ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.strokeRect(sel.x + 1, sel.y + 1, sel.w - 2, sel.h - 2);
            }
        }
        function pos(e) { var r = canvas.getBoundingClientRect(); return { x: (e.clientX - r.left) * canvas.width / r.width, y: (e.clientY - r.top) * canvas.height / r.height }; }
        function normalize(a, b) {
            var ratio = parseFloat($('[data-crop-ratio]', panel).value || '0');
            var x = Math.min(a.x, b.x), y = Math.min(a.y, b.y), w = Math.abs(b.x - a.x), h = Math.abs(b.y - a.y);
            if (ratio > 0) { h = w / ratio; if (b.y < a.y) y = a.y - h; }
            x = Math.max(0, x); y = Math.max(0, y); w = Math.min(w, canvas.width - x); h = Math.min(h, canvas.height - y);
            return { x: x, y: y, w: w, h: h };
        }
        canvas.addEventListener('mousedown', function (e) { drag = pos(e); sel = null; draw(); });
        canvas.addEventListener('mousemove', function (e) { if (!drag) return; sel = normalize(drag, pos(e)); draw(); });
        window.addEventListener('mouseup', function () { drag = null; });
        $$('[data-crop-open]', form).forEach(function (b) {
            b.addEventListener('click', function () {
                var card = b.closest('[data-media-id]');
                mediaId = card.getAttribute('data-media-id');
                var src = new Image();
                src.onload = function () {
                    img = src;
                    var maxW = 800; scale = Math.min(1, maxW / src.naturalWidth);
                    canvas.width = Math.round(src.naturalWidth * scale); canvas.height = Math.round(src.naturalHeight * scale);
                    sel = null; panel.hidden = false; $('[data-crop-label]', panel).textContent = '#' + mediaId + ' · ' + src.naturalWidth + '×' + src.naturalHeight;
                    draw(); panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                };
                src.onerror = function () { window.alert('Görsel yüklenemedi.'); };
                src.src = card.getAttribute('data-media-url');
            });
        });
        $('[data-crop-submit]', panel).addEventListener('click', function () {
            if (!img || !sel || sel.w < 10 || sel.h < 10) { window.alert('Tuvalde bir alan seçin.'); return; }
            if (!confirmLeave(form)) return;
            var outW = parseInt($('[data-crop-width]', panel).value, 10) || 1200;
            var sx = sel.x / scale, sy = sel.y / scale, sw = sel.w / scale, sh = sel.h / scale;
            outW = Math.min(outW, Math.round(sw));
            var out = document.createElement('canvas'); out.width = outW; out.height = Math.round(sh * outW / sw);
            out.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, out.width, out.height);
            var action = form.getAttribute('data-crop-action') || '';
            cropForm.action = action.replace('__ID__', mediaId);
            $('[name="image"]', cropForm).value = out.toDataURL('image/jpeg', 0.9);
            form.cmsDirty = false;
            cropForm.submit();
        });
    }

    /* ---------- GEO: önerileri doldur ---------- */
    function initGeo(form) {
        var fill = function (all) {
            $$('[data-geo-field]', form).forEach(function (ta) {
                var s = ta.getAttribute('data-suggestion') || '';
                if (s && (all || !ta.value.trim())) { ta.value = s; ta.dispatchEvent(new Event('input', { bubbles: true })); }
            });
        };
        var b1 = $('[data-geo-fill]', form), b2 = $('[data-geo-fill-all]', form);
        if (b1) b1.addEventListener('click', function () { fill(false); });
        if (b2) b2.addEventListener('click', function () { if (window.confirm('Doldurulmuş GEO alanları önerilerle değiştirilecek. Devam?')) fill(true); });
    }

    /* ---------- Gönderim: then (save/preview/publish) + kaydedilmemiş değişiklik uyarısı ---------- */
    function initSubmit(form) {
        var then = $('[data-then]', form);
        $$('[data-then-value]', form).forEach(function (b) {
            b.addEventListener('click', function () { if (then) then.value = b.getAttribute('data-then-value'); });
        });
        form.cmsDirty = false;
        var note = $('[data-dirty-note]', form);
        form.addEventListener('input', function (e) {
            if (e.target.closest('[data-media-meta], [data-media-upload]')) return;
            form.cmsDirty = true; if (note) note.hidden = false;
        });
        form.addEventListener('submit', function () { form.cmsDirty = false; });
        window.addEventListener('beforeunload', function (e) { if (form.cmsDirty) { e.preventDefault(); e.returnValue = ''; } });
    }

    /* ---------- Önizleme cihaz seçici ---------- */
    function initPreview() {
        var bar = $('[data-preview-devices]'), wrap = $('[data-preview-frame-wrap]');
        if (!bar || !wrap) return;
        bar.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-width]'); if (!b) return;
            $$('button', bar).forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
            wrap.style.width = b.getAttribute('data-width');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPreview();
        var form = $('[data-cms-form]');
        if (!form) return;
        var ta = $('#cms-body', form);
        initTabs(form);
        initToolbar(form, ta);
        initLinkSuggest(form, ta);
        initSeoLive(form);
        initCover(form);
        initImageInsert(form, ta);
        initMediaForms(form);
        initGeo(form);
        initSubmit(form);
    });
})();
