@extends('layouts.panel')

@section('title', 'Schema Manager — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">Schema Manager</h1>
            <p>Her sayfanın gerçek JSON-LD çıktısı (vitrindeki üreticinin aynısı) ve schema.org zorunlu/önerilen alan doğrulaması. Şema yalnız var olan veriden üretilir: fiyat, puan, yorum, adres ya da kişi uydurulmaz.</p>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $settings['schema.enabled'] ? 'ok' : 'danger' }}">JSON-LD {{ $settings['schema.enabled'] ? 'açık' : 'kapalı' }}</span>
            <a href="{{ route('panel.seo.settings.show', [$website, 'sema']) }}" class="btn btn--brand btn--pill">Tür seçimi & özel JSON-LD</a>
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px">
        <p class="eyebrow">Etkin türler</p>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            @foreach ((array) $settings['schema.types'] as $type)<span class="badge badge--info">{{ $type }}</span>@endforeach
        </div>
        <p class="small muted" style="margin:10px 0 0">Kaynak: Organization/WebSite (site ayarı) · LocalBusiness (lokasyon künyesi) · Service (hizmet kataloğu) · Article/WebPage/BreadcrumbList (içerik) · FAQPage (içerik "## Soru?" başlıkları, GEO SSS, hizmet SSS) · Event (etkinlik). Site geneli özel JSON-LD: {{ trim((string) $settings['schema.custom_sitewide']) !== '' ? 'tanımlı' : 'yok' }} · yola bağlı: {{ count((array) $settings['schema.custom_by_path']) }}.</p>
    </div>

    <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start">
        <div class="panel">
            <p class="eyebrow">Sayfa seç ({{ count($pages) }})</p>
            <form method="GET" action="{{ route('panel.seo.schema', $website) }}" class="inline-form" style="margin-bottom:10px">
                <input class="control mono" type="text" name="yol" value="{{ $path }}" placeholder="/cozum/sanal-ofis" style="flex:1">
                <button type="submit" class="btn btn--brand btn--pill">İncele</button>
            </form>
            @if ($notFound)<div class="note w">Bu yol için yayında sayfa bulunamadı.</div>@endif
            <ul class="mono small" style="margin:0;padding-left:18px;max-height:520px;overflow:auto">
                @foreach ($pages as $page)
                    <li><a href="{{ route('panel.seo.schema', [$website, 'yol' => $page['path']]) }}" @if ($page['path'] === $path) style="font-weight:700" @endif>{{ $page['path'] }}</a> <span class="muted">{{ $page['label'] }}</span></li>
                @endforeach
            </ul>
        </div>

        <div class="stack" style="gap:18px">
            @if ($inspection !== null)
                <div class="panel">
                    <p class="eyebrow">Head — {{ $inspection['path'] }}</p>
                    <dl class="dl">
                        <dt>Başlık</dt><dd>{{ $inspection['head']['title'] ?? '—' }}</dd>
                        <dt>Açıklama</dt><dd>{{ $inspection['head']['description'] ?? '—' }}</dd>
                        <dt>Canonical</dt><dd class="mono">{{ $inspection['head']['canonical'] ?? '—' }}</dd>
                        <dt>Robots</dt><dd class="mono">{{ $inspection['head']['robots'] ?? '—' }}</dd>
                        <dt>og:image</dt><dd class="mono">{{ $inspection['head']['og_image'] ?? '—' }}</dd>
                    </dl>
                </div>
                <div class="panel">
                    <p class="eyebrow">Doğrulama</p>
                    @if ($inspection['validation']['errors'] === [] && $inspection['validation']['warnings'] === [])
                        <span class="badge badge--ok">Hata ve uyarı yok</span>
                    @endif
                    @foreach ($inspection['validation']['errors'] as $e)<div class="note c" style="margin-top:6px">Hata: {{ $e }}</div>@endforeach
                    @foreach ($inspection['validation']['warnings'] as $w)<div class="note w" style="margin-top:6px">Uyarı: {{ $w }}</div>@endforeach
                    <p class="small muted" style="margin:10px 0 0">Türler: {{ implode(', ', $inspection['validation']['types']) ?: '—' }}. Dış doğrulama için Google Rich Results Test / Schema Markup Validator'a bu JSON'u yapıştırın.</p>
                </div>
                <div class="panel">
                    <p class="eyebrow">JSON-LD önizleme</p>
                    @if ($inspection['json'] === '')
                        <p class="body-muted" style="margin:0">Bu sayfa JSON-LD basmıyor.</p>
                    @else
                        <pre class="mono small" style="margin:0;white-space:pre-wrap;word-break:break-word;max-height:560px;overflow:auto;background:var(--surface-2);padding:12px;border-radius:var(--r-sm)">{{ $inspection['json'] }}</pre>
                    @endif
                </div>
            @else
                <div class="panel"><p class="body-muted" style="margin:0">Soldan bir sayfa seçin ya da yol yazın.</p></div>
            @endif
        </div>
    </div>
@endsection
