@extends('layouts.panel')

@section('title', 'İçerik takvimi — '.$month->translatedFormat('F Y'))

@php
    use App\Enums\ContentStatus;
    use App\Models\ContentDraft;
    // Takvimde ve gecikmiş listesinde Content ya da zamanlanmış ContentDraft (çalışma taslağı) bulunur.
    $target = fn ($x) => $x instanceof ContentDraft ? $x->content : $x;
    $prev = $month->subMonth()->format('Y-m');
    $next = $month->addMonth()->format('Y-m');
    // Izgara pazartesiden başlar; ay öncesi/sonrası boş hücrelerle tamamlanır.
    $firstCell = $month->startOfMonth()->startOfWeek();
    $lastCell = $month->endOfMonth()->endOfWeek();
    $overdueKeys = $pipeline['overdue']->map(fn ($x) => ($x instanceof ContentDraft ? 'd' : 'c').$x->id)->all();
    $inMonth = collect($days)->flatten(1);
@endphp

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.content.index', ['website' => $website->id]) }}">İçerik</a> · {{ $website->name }}</p>
            <h1 class="h2">Takvim — {{ $month->translatedFormat('F Y') }}</h1>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.content.calendar', ['website' => $website->id, 'ay' => $prev]) }}" class="btn btn--ghost btn--pill" rel="prev">‹ {{ $month->subMonth()->translatedFormat('M') }}</a>
            <a href="{{ route('panel.content.calendar', ['website' => $website->id]) }}" class="btn btn--ghost btn--pill">Bugün</a>
            <a href="{{ route('panel.content.calendar', ['website' => $website->id, 'ay' => $next]) }}" class="btn btn--ghost btn--pill" rel="next">{{ $month->addMonth()->translatedFormat('M') }} ›</a>
        </div>
    </div>

    @if ($websites->count() > 1)
        <form method="GET" class="inline-form" style="margin-bottom:18px">
            <input type="hidden" name="ay" value="{{ $month->format('Y-m') }}">
            <label class="field" style="flex:0 1 220px">
                <span class="label">Site</span>
                <select class="control" name="website" onchange="this.form.submit()">
                    @foreach ($websites as $site)
                        <option value="{{ $site->id }}" @selected($site->id === $website->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
            </label>
        </form>
    @endif

    @if ($pipeline['overdue']->isNotEmpty())
        <div class="notice notice--error" role="alert" style="margin-bottom:22px">
            <span class="notice__dot" aria-hidden="true"></span>
            <div>
                <strong>Gecikmiş zamanlama:</strong> {{ $pipeline['overdue']->count() }} içeriğin yayın zamanı geçti ama yayınlanmadı.
                Zamanlayıcı çalışmıyor olabilir (<code>php artisan schedule:run</code> / <code>content:publish-scheduled</code>).
                @foreach ($pipeline['overdue'] as $c)
                    <div class="small"><a href="{{ route('panel.content.show', $target($c)) }}">{{ $c->title }}</a>{{ $c instanceof ContentDraft ? ' (çalışma taslağı)' : '' }} · {{ $c->scheduled_for?->format('d.m.Y H:i') }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start;grid-template-columns:minmax(0,3fr) minmax(280px,1fr)">
        <div class="panel" style="padding:14px">
            <div class="calendar" role="grid" aria-label="{{ $month->translatedFormat('F Y') }}">
                @foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $dow)
                    <div class="calendar__dow label" role="columnheader">{{ $dow }}</div>
                @endforeach
                @for ($cell = $firstCell; $cell->lte($lastCell); $cell = $cell->addDay())
                    @php($key = $cell->format('Y-m-d'))
                    @php($items = $days[$key] ?? collect())
                    <div class="calendar__day{{ $cell->month !== $month->month ? ' is-outside' : '' }}{{ $key === $today ? ' is-today' : '' }}" role="gridcell" aria-label="{{ $cell->translatedFormat('j F') }}">
                        <div class="calendar__num mono small">{{ $cell->day }}</div>
                        @foreach ($items as $item)
                            @php($isDraft = $item instanceof ContentDraft)
                            @php($isOverdue = in_array(($isDraft ? 'd' : 'c').$item->id, $overdueKeys, true))
                            <a href="{{ route('panel.content.show', $target($item)) }}" class="calendar__item badge badge--{{ $isOverdue ? 'danger' : ($item->status === ContentStatus::SCHEDULED ? 'warn' : 'ok') }}" title="{{ $item->title }} · {{ $item->status->label() }}{{ $isDraft ? ' · çalışma taslağı (birleşme)' : '' }}{{ $isOverdue ? ' · gecikmiş' : '' }}">
                                <span class="mono">{{ ($item->status === ContentStatus::SCHEDULED ? $item->scheduled_for : $item->published_at)?->format('H:i') }}</span>
                                {{ $isDraft ? '↻ ' : '' }}{{ $item->title }}
                            </a>
                        @endforeach
                    </div>
                @endfor
            </div>
            <p class="small muted" style="margin:12px 0 0">
                <span class="badge badge--ok">yayında</span> <span class="badge badge--warn">zamanlandı</span> <span class="badge badge--danger">gecikmiş</span>
                · Bu ay {{ $inMonth->count() }} kayıt.
            </p>
        </div>

        <div class="stack" style="gap:20px">
            <div class="panel">
                <p class="eyebrow">İş hattı</p>
                <dl class="dl">
                    <dt>Taslak</dt><dd>{{ $pipeline['draft']->count() }}</dd>
                    <dt>İncelemede</dt><dd>{{ $pipeline['in_review']->count() }}</dd>
                    <dt>Onaylı, yayın bekliyor</dt><dd>{{ $pipeline['approved']->count() }}</dd>
                    <dt>Çalışma taslağı</dt><dd>{{ $pipeline['working']->count() }}</dd>
                </dl>
            </div>

            @foreach ([['in_review', 'İncelemede'], ['approved', 'Onaylı — yayın bekliyor'], ['draft', 'Taslaklar']] as [$bucket, $label])
                @if ($pipeline[$bucket]->isNotEmpty())
                    <div class="panel">
                        <p class="eyebrow">{{ $label }}</p>
                        <ul class="stack" style="gap:10px;margin:0;padding:0;list-style:none">
                            @foreach ($pipeline[$bucket]->take(8) as $c)
                                <li>
                                    <a href="{{ route('panel.content.show', $c) }}" style="font-weight:600">{{ $c->title }}</a>
                                    <div class="small muted">{{ $c->kind->label() }} · {{ $c->author?->name ?? '—' }} · {{ $c->updated_at?->diffForHumans() }}</div>
                                </li>
                            @endforeach
                            @if ($pipeline[$bucket]->count() > 8)
                                <li class="small"><a href="{{ route('panel.content.index', ['website' => $website->id, 'status' => strtoupper($bucket)]) }}">Tümü ({{ $pipeline[$bucket]->count() }}) →</a></li>
                            @endif
                        </ul>
                    </div>
                @endif
            @endforeach

            @if ($pipeline['working']->isNotEmpty())
                <div class="panel">
                    <p class="eyebrow">Çalışma taslakları (yayındaki metin üzerinde)</p>
                    <ul class="stack" style="gap:10px;margin:0;padding:0;list-style:none">
                        @foreach ($pipeline['working'] as $d)
                            <li>
                                <a href="{{ route('panel.content.show', $d->content) }}" style="font-weight:600">{{ $d->title }}</a>
                                <div class="small muted">{{ $d->status->label() }}@if ($d->scheduled_for) · birleşme {{ $d->scheduled_for->format('d.m.Y H:i') }}@endif · {{ $d->author?->name ?? '—' }} · {{ $d->updated_at?->diffForHumans() }}</div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
