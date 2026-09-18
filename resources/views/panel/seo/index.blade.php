@extends('layouts.panel')

@section('title', 'SEO')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">SEO Command Center · v1</p>
            <h1 class="h2">SEO</h1>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
        Her site kendi başlık son eki, varsayılan açıklama, dil ve indeksleme bayrağını taşır. <strong>İndeksleme
        kapatmak tüm siteyi arama motorlarından çıkarır</strong> (robots.txt "Disallow: /", her sayfa noindex, boş
        sitemap) — bu yüzden ayar değişikliği JIT erişimi ister. Sayfa başına <code>noindex</code> içerik formundadır.
    </p>

    <div class="stack" style="gap:20px">
        @foreach ($rows as $row)
            @php($site = $row['website'])
            <div class="panel">
                <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
                    <div>
                        <strong style="font-size:17px">{{ $site->name }}</strong>
                        <span class="small muted mono" style="display:block">{{ $site->baseUrl() }}</span>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        @if ($site->robots_index)
                            <span class="badge badge--ok">İndekslenir</span>
                        @else
                            <span class="badge badge--danger">noindex — tüm site</span>
                        @endif
                        @if ($canAudit)
                            <a href="{{ route('panel.seo.audit', $site) }}" class="btn btn--ghost btn--pill">Denetim @if ($row['issues'] > 0)<span class="badge badge--warn" style="margin-left:6px">{{ $row['issues'] }}</span>@endif</a>
                        @endif
                        <a href="{{ route('panel.seo.redirects.index', $site) }}" class="btn btn--ghost btn--pill">Yönlendirmeler & 404</a>
                        <a href="{{ route('panel.seo.settings.show', $site) }}" class="btn btn--brand btn--pill">Gelişmiş ayarlar</a>
                        <a href="{{ $site->baseUrl() }}/sitemap.xml" class="btn btn--ghost btn--pill" target="_blank" rel="noopener">sitemap.xml ↗</a>
                    </div>
                </div>

                @if ($row['grant'])
                    <form method="POST" action="{{ route('panel.seo.settings', $site) }}" class="grid-auto" style="--min:220px;--gap:14px">
                        @csrf @method('PUT')
                        <label class="field"><span class="label">Başlık son eki</span>
                            <input class="control" type="text" name="seo_title_suffix" value="{{ old('seo_title_suffix', $site->seo_title_suffix) }}" maxlength="80" placeholder="— {{ $site->name }}">
                        </label>
                        <label class="field"><span class="label">Dil (og:locale)</span>
                            <input class="control mono" type="text" name="seo_locale" value="{{ old('seo_locale', $site->seo_locale) }}" required pattern="[a-z]{2}_[A-Z]{2}">
                        </label>
                        <label class="field" style="grid-column:1/-1"><span class="label">Varsayılan meta açıklama (≤ 160)</span>
                            <textarea class="control" name="seo_default_description" maxlength="160" style="min-height:64px">{{ old('seo_default_description', $site->seo_default_description) }}</textarea>
                        </label>
                        <label class="checkbox-row" style="grid-column:1/-1">
                            <input type="checkbox" name="robots_index" value="1" @checked(old('robots_index', $site->robots_index))>
                            <span><strong>Arama motorları indekslesin</strong> — kapatırsanız site tamamen deindekslenir.</span>
                        </label>
                        <div style="grid-column:1/-1"><button type="submit" class="btn btn--brand">Ayarları kaydet</button></div>
                    </form>
                @else
                    <dl class="dl">
                        <dt>Son ek</dt><dd>{{ $site->seo_title_suffix ?: '— '.$site->name.' (varsayılan)' }}</dd>
                        <dt>Açıklama</dt><dd>{{ $site->seo_default_description ?: '—' }}</dd>
                        <dt>Dil</dt><dd class="mono">{{ $site->seo_locale }}</dd>
                    </dl>
                    @if ($canRequestJit)
                        <details style="margin-top:14px">
                            <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Ayarları değiştirmek için JIT erişimi iste</summary>
                            <form method="POST" action="{{ route('panel.seo.jit', $site) }}" class="stack" style="gap:10px;margin-top:10px;max-width:480px">
                                @csrf
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
        @endforeach
    </div>
@endsection
