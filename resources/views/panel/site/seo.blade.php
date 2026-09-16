@extends('layouts.panel')

@section('title', 'SEO — '.$company->legal_name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.site.index', $company) }}">Web sitesi</a> · {{ $activeOrganization->name }}</p>
            <h1 class="h2">SEO</h1>
        </div>
    </div>

    @if ($rows->isEmpty())
        <div class="empty-state">Organizasyonunuz için henüz bir web sitesi açılmamış.</div>
    @endif

    @foreach ($rows as $row)
        @php($site = $row['website'])
        <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start;margin-bottom:24px">
            <div class="panel stack" style="gap:14px">
                <p class="eyebrow" style="margin:0">{{ $site->name }}@if ($site->domain) · {{ $site->domain }}@endif</p>
                <p class="small muted" style="margin:0">
                    Başlık: <code>&lt;sayfa başlığı&gt; {{ $site->seo_title_suffix ?: '— '.$site->name }}</code> ·
                    Dil: <code>{{ $site->seo_locale ?: 'tr_TR' }}</code> ·
                    Arama motorları: @if ($site->robots_index)<span class="badge badge--ok">indeksliyor</span>@else<span class="badge badge--danger">noindex</span>@endif
                </p>

                @if ($canEdit)
                    <form method="POST" action="{{ route('panel.companies.site.seo.update', [$company, $site->id]) }}" class="stack" style="gap:12px">
                        @csrf @method('PUT')
                        <label class="field"><span class="label">Başlık son eki (≤ 80) — ör. "— {{ $site->name }}"</span>
                            <input class="control" type="text" name="seo_title_suffix" value="{{ old('seo_title_suffix', $site->seo_title_suffix) }}" maxlength="80">
                        </label>
                        <label class="field"><span class="label">Varsayılan meta açıklama (≤ 160) — özeti olmayan sayfalarda</span>
                            <textarea class="control" name="seo_default_description" maxlength="160" style="min-height:64px">{{ old('seo_default_description', $site->seo_default_description) }}</textarea>
                        </label>
                        <label class="field" style="max-width:160px"><span class="label">Dil (og:locale)</span>
                            <input class="control mono" type="text" name="seo_locale" value="{{ old('seo_locale', $site->seo_locale ?: 'tr_TR') }}" pattern="[a-z]{2}_[A-Z]{2}" required @error('seo_locale') aria-invalid="true" @enderror>
                        </label>
                        <div><button type="submit" class="btn btn--brand">Kaydet</button></div>
                    </form>
                @endif

                @if ($canPublish)
                    <form method="POST" action="{{ route('panel.companies.site.seo.indexing', [$company, $site->id]) }}" class="stack" style="gap:8px;border-top:1px solid var(--line);padding-top:14px">
                        @csrf @method('PUT')
                        <input type="hidden" name="robots_index" value="{{ $site->robots_index ? 0 : 1 }}">
                        <p class="small muted" style="margin:0">
                            @if ($site->robots_index)
                                Kapatırsanız tüm site arama sonuçlarından düşer (robots.txt Disallow + noindex). Yalnız yayın öncesi hazırlıkta kullanın.
                            @else
                                Site şu an arama motorlarına kapalı. Açınca sitemap yayınlanır ve sayfalar indekslenebilir.
                            @endif
                        </p>
                        <div>
                            <button type="submit" class="btn {{ $site->robots_index ? 'btn--ghost' : 'btn--brand' }}" @if ($site->robots_index) style="color:var(--danger);border-color:#E9C4BC" @endif>
                                {{ $site->robots_index ? 'İndekslemeye kapat' : 'İndekslemeye aç' }}
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            <div class="stack" style="gap:20px">
                <div class="panel">
                    <p class="eyebrow">İçerik denetimi
                        @if ($row['findings'] === [])<span class="badge badge--ok" style="margin-left:8px">Sorun yok</span>@else<span class="badge badge--warn" style="margin-left:8px">{{ count($row['findings']) }} sayfada bulgu</span>@endif
                    </p>
                    @if ($row['findings'] !== [])
                        <table class="data">
                            <thead><tr><th>Sayfa</th><th>Bulgular</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($row['findings'] as $f)
                                    <tr>
                                        <td>{{ $f['content']->title }}</td>
                                        <td><ul style="margin:0;padding-left:18px;font-size:14px">@foreach ($f['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul></td>
                                        <td><div class="row-actions"><a href="{{ route('panel.companies.site.show', [$company, $f['content']->id]) }}" class="btn btn--ghost btn--pill">Aç</a></div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="body-muted small" style="margin:0">Yayındaki sayfalar başlık, meta açıklama ve gövde uzunluğu kurallarını karşılıyor.</p>
                    @endif
                </div>

                <div class="panel">
                    <p class="eyebrow">Sitemap ({{ count($row['sitemap']) }} URL) · <a href="{{ $site->baseUrl() }}/sitemap.xml" target="_blank" rel="noopener" class="mono">/sitemap.xml ↗</a></p>
                    @if ($row['sitemap'] === [])
                        <p class="body-muted small" style="margin:0">Sitemap boş — site indekslemeye kapalı ya da yayında sayfa yok.</p>
                    @else
                        <ul class="mono small" style="margin:0;padding-left:18px">
                            @foreach ($row['sitemap'] as $entry)<li>{{ $entry['loc'] }}</li>@endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
@endsection
