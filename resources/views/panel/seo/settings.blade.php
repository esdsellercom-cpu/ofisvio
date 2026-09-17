@extends('layouts.panel')

@section('title', 'SEO & GEO gelişmiş')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.index') }}">SEO & GEO</a> · {{ $website->name }}</p>
            <h1 class="h2">Gelişmiş ayarlar</h1>
            <p>Yalın ayarlar (başlık son eki, varsayılan açıklama, dil, indeksleme bayrağı) SEO listesinde kalır; buradakiler onun üstüne biner. Boş bırakılan alan hiçbir şeyi değiştirmez.</p>
        </div>
        <div class="panel-head__actions">
            @if ($website->robots_index)<span class="badge badge--ok">İndekslenir</span>@else<span class="badge badge--danger">noindex — tüm site</span>@endif
            <a href="{{ $website->baseUrl() }}/robots.txt" class="btn btn--ghost btn--pill" target="_blank" rel="noopener">robots.txt ↗</a>
            @if ($all['geo.llms_enabled'] && $all['crawl.ai_crawlers_allowed'])<a href="{{ $website->baseUrl() }}/llms.txt" class="btn btn--ghost btn--pill" target="_blank" rel="noopener">llms.txt ↗</a>@endif
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <nav class="tabbar" aria-label="Gelişmiş SEO sekmeleri">
            @foreach ($tabs as $tabKey => $tabDef)
                <a href="{{ route('panel.seo.settings.show', [$website, $tabKey]) }}" @if ($tabKey === $tab) aria-current="page" @endif>{{ $tabDef['label'] }}@if ($tabDef['mode'] !== 'edit') <span class="small muted" title="JIT erişimi ister">🔒</span>@endif</a>
            @endforeach
        </nav>

        <p class="body-muted" style="margin:0;max-width:80ch">{{ $tabs[$tab]['lead'] }}</p>

        @if (! $canEdit)
            <div class="note w">
                @if ($mode === 'edit')
                    Bu sekmeyi düzenlemek için <code>seo.edit</code> izni gerekir; salt okunur görüyorsunuz.
                @else
                    Bu sekme kritik ayar taşır ({{ $mode === 'integration' ? 'seo.integrations' : ($mode === 'entity' ? 'geo.settings' : 'seo.settings') }} + JIT). Salt okunur görüyorsunuz.
                    @if ($canRequestJit)
                        <details style="margin-top:10px">
                            <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Düzenlemek için JIT erişimi iste</summary>
                            <form method="POST" action="{{ route('panel.seo.jit', [$website, $jitScope]) }}" class="stack" style="gap:10px;margin-top:10px;max-width:480px">
                                @csrf
                                <input type="hidden" name="sekme" value="{{ $tab }}">
                                <label class="field"><span class="label">Gerekçe</span>
                                    <textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:64px"></textarea>
                                </label>
                                <div class="inline-form">
                                    <label class="field" style="flex:0 1 140px"><span class="label">Süre (dk)</span>
                                        <input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required>
                                    </label>
                                    <button type="submit" class="btn btn--brand">Erişim aç</button>
                                </div>
                            </form>
                        </details>
                    @endif
                @endif
            </div>
        @endif

        @if ($tab === 'geo')
            <div class="note">AI tarayıcı erişimi: <strong>{{ $all['crawl.ai_crawlers_allowed'] ? 'açık' : 'kapalı' }}</strong>@if ($all['crawl.ai_disallow_paths'] !== []) · kapalı yollar: <span class="mono">{{ implode(', ', $all['crawl.ai_disallow_paths']) }}</span>@endif — bot listesi ve erişim <a href="{{ route('panel.seo.settings.show', [$website, 'tarama']) }}">Tarama & indeksleme</a> sekmesinde (JIT). Makine tarafından okunur şirket bilgisi: llms.txt + her sayfadaki Organization JSON-LD (<a href="{{ route('panel.seo.settings.show', [$website, 'varlik']) }}">Entity</a>).</div>
        @elseif ($tab === 'varlik')
            <div class="note">Marka adı: <strong>{{ $website->name }}</strong> · Tüzel ad: {{ $website->legal_name ?: '—' }} · Telefon: {{ $website->contact_phone ?: '—' }} · E-posta: {{ $website->contact_email ?: '—' }} · Adres: {{ $website->address ?: '—' }} · sameAs: {{ implode(', ', array_filter((array) ($website->same_as ?? []))) ?: '—' }}. Bunlar <a href="{{ route('panel.websites.index') }}">Websiteler</a> (iletişim) ve <a href="{{ route('panel.geo.index') }}">GEO</a> (tüzel ad, sosyal profiller) ekranlarında düzenlenir.</div>
        @elseif ($tab === 'yerel')
            <div class="note">Şube künyeleri (adres, ilçe, posta kodu, telefon, koordinat, çalışma saatleri, hizmetler) <a href="{{ route('panel.geo.index') }}">Lokasyonlar & alanlar</a> ekranında; her şube sayfası LocalBusiness şeması taşır.</div>
        @elseif ($tab === 'dil')
            <div class="note">Varsayılan dil (og:locale): <span class="mono">{{ $website->seo_locale ?: 'tr_TR' }}</span> — SEO listesindeki yalın ayardan. Çok dilli içerik (dil başına sayfa) yol haritasında; hreflang bugün ayrı alan adı/alt alan adı kurulumları içindir.</div>
        @endif

        <form method="POST" action="{{ $action }}" class="panel">
            @csrf @method('PUT')
            <div class="grid-auto" style="--min:260px;--gap:16px">
                @foreach ($definitions as $key => $def)
                    @include('panel.seo.partials.field', ['key' => $key, 'def' => $def, 'value' => $values[\App\Services\SeoSettingsService::field($key)] ?? $def['default'], 'disabled' => ! $canEdit])
                @endforeach
            </div>
            @if ($canEdit)
                <div style="margin-top:18px"><button type="submit" class="btn btn--brand">{{ $tabs[$tab]['label'] }} ayarlarını kaydet</button></div>
            @endif
        </form>

        @if ($robotsPreview !== null)
            <div class="card">
                <div class="card__head"><h3>robots.txt önizleme</h3><span class="sub">kayıtlı ayarlarla</span></div>
                <div class="card__body"><pre class="mono small" style="margin:0;white-space:pre-wrap">{{ $robotsPreview }}</pre></div>
            </div>
        @endif

        @if ($tab === 'geo')
            <div class="card">
                <div class="card__head"><h3>llms.txt önizleme</h3><span class="sub">{{ $llmsPreview === null ? 'servis edilmiyor' : mb_strlen($llmsPreview).' karakter' }}</span></div>
                <div class="card__body">
                    @if ($llmsPreview === null)
                        <p class="small muted" style="margin:0">llms.txt kapalı, AI erişimi kapalı ya da özel metin boş.</p>
                    @else
                        <pre class="mono small" style="margin:0;white-space:pre-wrap;max-height:420px;overflow:auto">{{ $llmsPreview }}</pre>
                    @endif
                </div>
            </div>
        @endif

        @if ($tab === 'teknik')
            @php($total = array_sum(array_map(fn ($r) => count($r['items']), $report)))
            <div class="card">
                <div class="card__head"><h3>Teknik denetim</h3>@if ($total === 0)<span class="badge badge--ok">Sorun yok</span>@else<span class="badge badge--warn">{{ $total }} bulgu</span>@endif</div>
                <div class="card__body stack" style="gap:14px">
                    @foreach ($report as $section)
                        <div>
                            <p class="eyebrow" style="margin:0 0 4px">{{ $section['label'] }} @if ($section['items'] === [])<span class="pill a flat">temiz</span>@else<span class="pill c flat">{{ count($section['items']) }}</span>@endif</p>
                            @if ($section['items'] !== [])
                                <ul class="small" style="margin:0;padding-left:18px">@foreach ($section['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                            @endif
                        </div>
                    @endforeach
                    <p class="small muted" style="margin:0">Core Web Vitals ölçümü ve WebP/AVIF dönüşümü dış araç/işleme altyapısı ister; medya kütüphanesi görselleri boyutlandırır, sayfa ölçümleri Performans ekranında (baseline).</p>
                </div>
            </div>
        @endif

        @if ($tab === 'guvenlik')
            <div class="card">
                <div class="card__head"><h3>Uygulama geneli başlıklar</h3><span class="sub">SecurityHeaders middleware — salt okunur</span></div>
                <div class="card__body"><dl class="dl">@foreach ($security as $row)<dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd>@endforeach
                    <dt>Bot erişimi</dt><dd>AI tarayıcıları: {{ $all['crawl.ai_crawlers_allowed'] ? 'açık' : 'kapalı' }} ({{ count($all['crawl.ai_bots']) }} bot) · özel robots satırları: {{ trim((string) $all['crawl.robots_extra']) === '' ? 'yok' : 'var' }} — <a href="{{ route('panel.seo.settings.show', [$website, 'tarama']) }}">Tarama</a></dd>
                </dl></div>
            </div>
        @endif

        @if ($tab === 'dogrulama' && $all['indexing.indexnow_key'] !== '')
            <div class="note">IndexNow anahtar dosyası: <a href="{{ $website->baseUrl() }}/{{ $all['indexing.indexnow_key'] }}.txt" target="_blank" rel="noopener" class="mono">/{{ $all['indexing.indexnow_key'] }}.txt</a> · sağlayıcı: <a href="{{ route('panel.integrations.index') }}">Entegrasyonlar</a> (INDEXNOW_ENABLED).</div>
        @endif
    </div>
@endsection
