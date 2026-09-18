@extends('layouts.panel')

@section('title', 'Keyword Intelligence — '.$website->name)

@section('content')
    @php($c = $analysis['counts'])
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">Keyword Intelligence</h1>
            <p>Anahtar kelime → sayfa / hizmet / lokasyon eşlemesi, arama niyeti ve konu kümeleri. Analizler deterministiktir: kanibalizasyon (aynı birincil kelime birden fazla sayfada), içerik boşluğu (hedefi olmayan kelime), sayfa üstü kontrol, eşleşmemiş sayfalar. Hacim, sıralama ve ilgili sorgular yalnız bağlı Search Console'dan gelir; burada üretilmez.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.seo.links', $website) }}" class="btn btn--ghost btn--pill">İç bağlantı motoru</a>
            <a href="{{ route('panel.seo.entities', $website) }}" class="btn btn--ghost btn--pill">Konu → varlık</a>
        </div>
    </div>

    <div class="kpis kpis--6" style="margin-bottom:18px">
        <div class="kpi"><span class="k">Kelime</span><span class="v">{{ $c['total'] }}</span><span class="d">{{ $c['primary'] }} birincil · {{ count($analysis['implicit']) }} örtük (odak)</span></div>
        <div class="kpi"><span class="k">Küme</span><span class="v">{{ $c['clusters'] }}</span><span class="d">konu kümesi</span></div>
        <div class="kpi {{ $c['cannibal'] > 0 ? 'alert' : 'ok' }}"><span class="k">Kanibalizasyon</span><span class="v">{{ $c['cannibal'] }}</span><span class="d">aynı kelime → çok sayfa</span></div>
        <div class="kpi {{ $c['gaps'] > 0 ? 'watch' : 'ok' }}"><span class="k">İçerik boşluğu</span><span class="v">{{ $c['gaps'] }}</span><span class="d">hedefsiz / ölü hedef</span></div>
        <div class="kpi {{ $c['unmapped'] > 0 ? 'watch' : 'ok' }}"><span class="k">Kelimesiz sayfa</span><span class="v">{{ $c['unmapped'] }}</span><span class="d">eşlenmemiş sayfa</span></div>
        <div class="kpi"><span class="k">Search Console</span><span class="v" style="font-size:14px">{{ $integrations['search_console']['connected'] ? 'bağlı' : 'bağlı değil' }}</span><span class="d">ilgili sorgular oradan</span></div>
    </div>

    @if ($canEdit)
        <form method="POST" action="{{ route('panel.seo.keywords.store', $website) }}" class="panel" style="margin-bottom:18px">
            @csrf
            <p class="eyebrow">Kelime ekle</p>
            <div class="grid-auto" style="--min:170px;--gap:10px">
                <label class="field"><span class="label">Anahtar kelime</span><input class="control" type="text" name="keyword" value="{{ old('keyword') }}" required minlength="2" maxlength="120" placeholder="konya sanal ofis"></label>
                <label class="field"><span class="label">Rol</span><select class="control" name="role">@foreach ($roles as $k => $l)<option value="{{ $k }}" @selected(old('role') === $k)>{{ $l }}</option>@endforeach</select></label>
                <label class="field"><span class="label">Niyet</span><select class="control" name="intent">@foreach ($intents as $k => $l)<option value="{{ $k }}" @selected(old('intent') === $k)>{{ $l }}</option>@endforeach</select></label>
                <label class="field"><span class="label">Konu kümesi</span><input class="control" type="text" name="cluster" value="{{ old('cluster') }}" maxlength="80" placeholder="sanal-ofis"></label>
                <label class="field" style="grid-column:span 2"><span class="label">Hedef sayfa</span>
                    <select class="control" name="target"><option value="">— henüz yok (içerik boşluğu) —</option>@foreach ($targets as $t)<option value="{{ $t['value'] }}" @selected(old('target') === $t['value'])>{{ $t['label'] }}</option>@endforeach</select>
                </label>
                <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note') }}" maxlength="300"></label>
            </div>
            <div style="margin-top:10px"><button type="submit" class="btn btn--brand">Ekle</button></div>
        </form>
    @endif

    <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start;margin-bottom:18px">
        <div class="panel">
            <p class="eyebrow">Kanibalizasyon</p>
            @if ($analysis['cannibalization'] === [])<span class="badge badge--ok">Aynı birincil kelimeyi hedefleyen birden fazla sayfa yok</span>@else
                <ul style="margin:0;padding-left:18px">@foreach ($analysis['cannibalization'] as $row)<li><strong>{{ $row['keyword'] }}</strong> → <span class="mono small">{{ implode(' · ', $row['paths']) }}</span> <span class="small muted">— tek sayfada toplayın, diğerlerini ikincil yapın ya da canonical verin.</span></li>@endforeach</ul>
            @endif
        </div>
        <div class="panel">
            <p class="eyebrow">İçerik boşluğu</p>
            @if ($analysis['gaps'] === [])<span class="badge badge--ok">Her kelimenin yayında bir hedefi var</span>@else
                <ul style="margin:0;padding-left:18px">@foreach ($analysis['gaps'] as $row)<li><strong>{{ $row['model']->keyword }}</strong> <span class="small muted">{{ $row['model']->target_path === null ? '— hedef yok: bu kelime için sayfa yazın (AI içerik hattı konu olarak alır)' : '— hedef yayında değil: '.$row['model']->target_path }}</span></li>@endforeach</ul>
            @endif
        </div>
        <div class="panel">
            <p class="eyebrow">Konu kümeleri</p>
            @if ($analysis['clusters'] === [])<p class="body-muted small" style="margin:0">Küme yok; kelime eklerken küme adı verin.</p>@else
                <ul style="margin:0;padding-left:18px">@foreach ($analysis['clusters'] as $slug => $cluster)<li><strong>{{ $slug }}</strong> <span class="small muted">{{ count($cluster['keywords']) }} kelime · hedefler: {{ implode(', ', $cluster['targets']) }}</span> @if ($cluster['linked_topic'])<span class="badge badge--ok">varlığa bağlı</span>@else<span class="badge badge--muted">varlığa bağlı değil</span>@endif</li>@endforeach</ul>
            @endif
        </div>
        <div class="panel">
            <p class="eyebrow">Kelimesiz sayfalar ({{ count($analysis['unmapped']) }})</p>
            @if ($analysis['unmapped'] === [])<span class="badge badge--ok">Her sayfanın bir anahtar kelimesi var</span>@else
                <ul class="mono small" style="margin:0;padding-left:18px;max-height:220px;overflow:auto">@foreach ($analysis['unmapped'] as $p)<li>{{ $p['path'] }} <span class="muted">{{ $p['label'] }}</span></li>@endforeach</ul>
            @endif
        </div>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Kelime</th><th>Rol · niyet</th><th>Küme</th><th>Hedef</th><th>Sayfa üstü</th><th></th></tr></thead>
            <tbody>
                @foreach ($analysis['rows'] as $row)
                    @php($k = $row['model'])
                    <tr>
                        <td><strong>{{ $k->keyword }}</strong>@if ($k->note)<span class="small muted" style="display:block">{{ $k->note }}</span>@endif</td>
                        <td class="small">{{ $roles[$k->role] }} · {{ $intents[$k->intent] }}</td>
                        <td class="small mono">{{ $k->cluster ?? '—' }}</td>
                        <td class="small">@if ($k->target_path)<span class="mono">{{ $k->target_path }}</span> @if ($row['dead'])<span class="badge badge--danger">yayında değil</span>@endif @else<span class="badge badge--warn">hedef yok</span>@endif</td>
                        <td class="small">
                            @if ($row['on_page'] === null)<span class="muted">—</span>@else
                                <span class="badge badge--{{ $row['on_page']['title'] ? 'ok' : 'warn' }}">başlık</span>
                                <span class="badge badge--{{ $row['on_page']['description'] ? 'ok' : 'warn' }}">açıklama</span>
                                <span class="badge badge--{{ $row['on_page']['body'] > 0 ? 'ok' : 'warn' }}">gövde ×{{ $row['on_page']['body'] }}</span>
                            @endif
                        </td>
                        <td>@if ($canEdit)<form method="POST" action="{{ route('panel.seo.keywords.destroy', [$website, $k->id]) }}">@csrf @method('DELETE')<button type="submit" class="btn btn--ghost btn--pill">Sil</button></form>@endif</td>
                    </tr>
                @endforeach
                @foreach ($analysis['implicit'] as $row)
                    <tr>
                        <td><strong>{{ $row['keyword'] }}</strong><span class="small muted" style="display:block">örtük — içerik stüdyosu odak kelimesi</span></td>
                        <td class="small">Birincil · —</td>
                        <td class="small mono">—</td>
                        <td class="small mono">{{ $row['content']->path() }}</td>
                        <td class="small"><span class="muted">stüdyoda ölçülür</span></td>
                        <td><a href="{{ route('panel.content.show', $row['content']) }}" class="btn btn--ghost btn--pill">İçerik</a></td>
                    </tr>
                @endforeach
                @if ($analysis['rows'] === [] && $analysis['implicit'] === [])
                    <tr><td colspan="6" class="body-muted">Henüz anahtar kelime yok.</td></tr>
                @endif
            </tbody>
        </table>
    </div>
@endsection
