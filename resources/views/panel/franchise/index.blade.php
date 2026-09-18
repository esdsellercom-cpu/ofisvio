@extends('layouts.panel')

@section('title', 'Franchise yönetimi')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Büyüme · franchise</p>
            <h1 class="h2">Franchise yönetimi</h1>
            <p>Vitrindeki <code class="mono">/franchise</code> formundan gelen başvurular. Değerlendirme: durum, sorumlu, iç not — hepsi denetim kaydında.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('site.franchise') }}" class="btn btn--ghost" target="_blank" rel="noopener">Başvuru formu</a>
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis">
            @foreach ($statuses as $k => $label)
                <a href="{{ route('panel.franchise.index', ['status' => $k]) }}" class="kpi {{ $k === 'new' && $counts[$k] > 0 ? 'watch' : ($k === 'positive' ? 'ok' : '') }}"><span class="k">{{ $label }}</span><span class="v">{{ $counts[$k] }}</span><span class="d">Başvuru</span></a>
            @endforeach
        </div>

        <div class="card">
            <div class="card__head">
                <nav class="tabbar" style="border:0" aria-label="Durum">
                    <a href="{{ route('panel.franchise.index') }}" @if (empty($filters['status'])) aria-current="page" @endif>Tümü</a>
                    @foreach ($statuses as $k => $label)<a href="{{ route('panel.franchise.index', ['status' => $k]) }}" @if (($filters['status'] ?? '') === $k) aria-current="page" @endif>{{ $label }}</a>@endforeach
                </nav>
                <form method="GET" class="r inline-form" style="gap:6px">
                    @if (! empty($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
                    <input class="control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ad, e-posta, şehir" style="width:180px">
                    <button type="submit" class="btn btn--ghost">Ara</button>
                </form>
            </div>
            @if ($rows->isEmpty())
                <div class="empty-state" style="border:0">Başvuru yok.</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Başvuru no</th><th>Ad Soyad</th><th>Firma</th><th>Telefon</th><th>E-posta</th><th>Şehir</th><th>Bütçe</th><th>Tarih</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $a)
                                <tr>
                                    <td class="mono small">{{ $a->number ?? '#'.$a->id }}</td>
                                    <td><b>{{ $a->name }}</b>@if ($a->assignee)<br><span class="mini">Sorumlu: {{ $a->assignee->name }}</span>@endif</td>
                                    <td class="small">{{ $a->company ?? '—' }}</td>
                                    <td class="small mono">{{ $a->phone ?? '—' }}</td>
                                    <td class="small">{{ $a->email }}</td>
                                    <td>{{ $a->city }}{{ $a->district ? ' / '.$a->district : '' }}</td>
                                    <td class="small">{{ $a->budgetLabel() }}</td>
                                    <td class="mono small">{{ $a->created_at->format('d.m.Y') }}</td>
                                    <td><span class="pill {{ \App\Models\FranchiseApplication::STATUS_TONE[$a->status] ?? 'n' }}">{{ $a->statusLabel() }}</span></td>
                                    <td class="num"><a href="{{ route('panel.franchise.show', $a) }}" class="btn btn--quiet">Aç</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rows->hasPages())<div class="card__body">{{ $rows->links() }}</div>@endif
            @endif
        </div>
    </div>
@endsection
