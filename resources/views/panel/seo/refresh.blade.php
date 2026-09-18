@extends('layouts.panel')

@section('title', 'İçerik yenileme — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">İçerik yenileme adayları</h1>
            <p>Deterministik sinyaller: eski içerik (yayın > 12 ay, güncelleme > 6 ay), gövdede eski yıl vurgusu, kırık iç bağlantı, Search Console tıklama düşüşü (bağlıysa), erişilemeyen dış kaynak (isteğe bağlı yoklama). AI ile yenileme yayındaki metni değil <strong>çalışma taslağını</strong> üretir; siz birleştirene kadar yayın değişmez.</p>
        </div>
        <div class="panel-head__actions">
            @if ($canDetect)
                <form method="POST" action="{{ route('panel.seo.refresh.detect', $website) }}" style="display:flex;gap:8px;align-items:center">@csrf<label class="checkbox-row small"><input type="checkbox" name="external" value="1"><span>dış kaynakları yokla</span></label><button type="submit" class="btn btn--brand btn--pill">Adayları tara</button></form>
            @endif
        </div>
    </div>

    <nav class="tabbar" aria-label="Durum" style="margin-bottom:14px">
        @foreach (['open' => 'Açık', 'planned' => 'Planlandı', 'done' => 'Tamamlandı', 'ignored' => 'Yok sayıldı', 'all' => 'Hepsi'] as $k => $l)<a href="{{ route('panel.seo.refresh', [$website, 'durum' => $k]) }}" @if ($status === $k) aria-current="page" @endif>{{ $l }}</a>@endforeach
    </nav>

    @if ($candidates->isEmpty())
        <div class="panel"><p class="body-muted" style="margin:0">Bu durumda aday yok. "Adayları tara" ile yeniden hesaplayın; zamanlayıcı haftalık çalışır.</p></div>
    @else
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>İçerik</th><th>Puan</th><th>Gerekçeler</th><th>Durum</th><th></th></tr></thead>
                <tbody>
                    @foreach ($candidates as $c)
                        <tr>
                            <td>@if ($c->content)<a href="{{ route('panel.content.show', $c->content) }}"><strong>{{ $c->content->title }}</strong></a><span class="mono small muted" style="display:block">{{ $c->content->path() }} · yayın {{ $c->content->published_at?->format('d.m.Y') ?? '—' }}</span>@else —@endif</td>
                            <td><span class="badge badge--{{ $c->score >= 5 ? 'danger' : ($c->score >= 3 ? 'warn' : 'info') }}">{{ $c->score }}</span></td>
                            <td class="small"><ul style="margin:0;padding-left:16px">@foreach ($c->reasons as $r)<li>{{ $r['label'] ?? $r }}</li>@endforeach</ul></td>
                            <td><span class="badge badge--{{ $c->status === 'open' ? 'warn' : ($c->status === 'done' ? 'ok' : 'muted') }}">{{ $statuses[$c->status] }}</span>@if ($c->ai_job_id)<a class="small" style="display:block" href="{{ route('panel.seo.ai.show', [$website, $c->ai_job_id]) }}">AI işi #{{ $c->ai_job_id }}</a>@endif</td>
                            <td>
                                <div class="row-actions">
                                    @if ($canGenerate && $c->content && $c->status !== 'done')<a href="{{ route('panel.seo.ai.index', [$website, 'kaynak' => $c->content_id]) }}" class="btn btn--brand btn--pill">AI ile yenile</a>@endif
                                    @if ($canDecide)
                                        <form method="POST" action="{{ route('panel.seo.refresh.decide', [$website, $c]) }}" style="display:flex;gap:4px">@csrf
                                            @if ($c->status !== 'planned')<button type="submit" name="status" value="planned" class="btn btn--ghost btn--pill">Planla</button>@endif
                                            @if ($c->status !== 'done')<button type="submit" name="status" value="done" class="btn btn--ghost btn--pill">Tamamlandı</button>@endif
                                            @if ($c->status !== 'ignored')<button type="submit" name="status" value="ignored" class="btn btn--ghost btn--pill">Yok say</button>@else<button type="submit" name="status" value="open" class="btn btn--ghost btn--pill">Yeniden aç</button>@endif
                                        </form>
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
