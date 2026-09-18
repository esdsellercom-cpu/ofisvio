@extends('layouts.panel')

@section('title', 'Programatik SEO — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">Programatik SEO — hizmet × şehir sayfaları</h1>
            <p>Adres kalıbı <span class="mono">/{hizmet}/{sehir}</span> (örn. <span class="mono">/sanal-ofis/konya</span>). Her sayfa tek tek oluşturulur; toplu üretim düğmesi bilinçli olarak yoktur. Yayın kapısı: benzersiz giriş metni ≥ {{ $minIntro }} karakter, hizmet açıklamasına ve diğer şehir sayfalarına benzerlik &lt; %{{ $maxSimilarity }}, tekil başlık, meta açıklama. Kapya/zayıf sayfa yayınlanmaz; yayındayken bozulursa taslağa düşer.</p>
        </div>
        <div class="panel-head__actions">
            @if ($canEdit && $website->is_default)<a href="{{ route('panel.seo.landing.create', $website) }}" class="btn btn--brand btn--pill">+ Yeni sayfa</a>@endif
        </div>
    </div>

    @if (! $website->is_default)
        <div class="note w">Programatik sayfalar yalnız Ofisvio vitrininde (hizmet ve şube kataloğu oradadır).</div>
    @elseif ($pages->isEmpty())
        <div class="panel"><p class="body-muted" style="margin:0">Henüz sayfa yok. Bir hizmet ve şube seçip o şehre özgü, gerçek bilgiye dayanan bir giriş metni yazın.</p></div>
    @else
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Sayfa</th><th>Adres</th><th>Durum</th><th>Kalite</th><th>İndeks</th><th></th></tr></thead>
                <tbody>
                    @foreach ($pages as $p)
                        @php($q = $p->quality ?? ['ok' => false, 'score' => 0, 'issues' => ['Denetlenmedi']])
                        <tr>
                            <td><strong>{{ $p->title }}</strong><span class="small muted" style="display:block">{{ $p->service->name }} · {{ $p->location->name }} ({{ $p->location->city }})</span></td>
                            <td class="mono small">@if ($p->status === 'published')<a href="{{ $website->baseUrl().$p->path() }}" target="_blank" rel="noopener">{{ $p->path() }}</a>@else{{ $p->path() }}@endif</td>
                            <td><span class="badge badge--{{ $p->status === 'published' ? 'ok' : 'muted' }}">{{ $p->status === 'published' ? 'Yayında' : 'Taslak' }}</span></td>
                            <td><span class="badge badge--{{ $q['ok'] ? 'ok' : 'warn' }}">{{ $q['score'] }}/100</span>@if (! $q['ok'])<span class="small muted" style="display:block;max-width:260px">{{ implode(' · ', $q['issues']) }}</span>@endif</td>
                            <td class="small">{{ $p->is_indexable ? 'index' : 'noindex' }}</td>
                            <td>
                                <div class="row-actions">
                                    @if ($canEdit)<a href="{{ route('panel.seo.landing.edit', [$website, $p]) }}" class="btn btn--ghost btn--pill">Düzenle</a>@endif
                                    @if ($canPublish)
                                        @if ($p->status === 'published')
                                            <form method="POST" action="{{ route('panel.seo.landing.unpublish', [$website, $p]) }}">@csrf<button type="submit" class="btn btn--ghost btn--pill">Yayından kaldır</button></form>
                                        @else
                                            <form method="POST" action="{{ route('panel.seo.landing.publish', [$website, $p]) }}">@csrf<button type="submit" class="btn btn--brand btn--pill" @disabled(! $q['ok'])>Yayınla</button></form>
                                        @endif
                                        <a href="{{ route('panel.seo.landing.delete', [$website, $p]) }}" class="btn btn--ghost btn--pill" style="color:var(--danger)">Sil</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
