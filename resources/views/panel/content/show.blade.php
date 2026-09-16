@extends('layouts.panel')

@section('title', $content->title)

@php
    use App\Enums\ContentStatus;
    $s = $content->status;
    $tone = match ($s) {
        ContentStatus::PUBLISHED => 'ok',
        ContentStatus::IN_REVIEW, ContentStatus::SCHEDULED => 'warn',
        ContentStatus::APPROVED => 'info',
        ContentStatus::ARCHIVED => 'danger',
        ContentStatus::DRAFT => 'muted',
    };
    // Müşteri sitesi: kendi alan adı; Ofisvio vitrini: uygulama adresi.
    $path = ($content->kind->value === 'post' ? '/blog/' : '/').$content->slug;
    $publicUrl = $content->website->domain ? 'https://'.$content->website->domain.$path : url($path);
@endphp

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.content.index', ['website' => $content->website_id]) }}">İçerik</a> · {{ $content->website->name }} · {{ $content->kind->label() }}</p>
            <h1 class="h2">{{ $content->title }}</h1>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $tone }}">{{ $s->label() }}</span>
            @if ($content->requires_approval)
                <span class="badge badge--info" title="Yasal/vergi/KYC içeriği: onay zorunlu">Onay gerekli</span>
            @endif
            @if ($s === ContentStatus::DRAFT && $can['edit'])
                <a href="{{ route('panel.content.edit', $content) }}" class="btn btn--brand">Düzenle</a>
            @endif
            @if ($s->isLive())
                <a href="{{ $publicUrl }}" class="btn btn--ghost" target="_blank" rel="noopener">Sitede aç ↗</a>
            @endif
        </div>
    </div>

    @if ($content->review_note && in_array($s, [ContentStatus::DRAFT, ContentStatus::ARCHIVED], true))
        <div class="notice notice--error" role="status" style="margin-bottom:22px">
            <span class="notice__dot" aria-hidden="true"></span>
            <div><strong>İnceleme notu:</strong> {{ $content->review_note }}</div>
        </div>
    @endif

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        {{-- Önizleme --}}
        <div class="panel" style="grid-column:span 1">
            <p class="eyebrow">Önizleme</p>
            <dl class="dl" style="margin-bottom:18px">
                <dt>URL</dt><dd class="mono small">{{ $publicUrl }}</dd>
                @if ($content->category)<dt>Kategori</dt><dd>{{ $content->category }}</dd>@endif
                @if ($content->reading_minutes)<dt>Okuma</dt><dd>{{ $content->reading_minutes }} dk</dd>@endif
                <dt>Yazar</dt><dd>{{ $content->author?->name ?? '—' }}</dd>
                @if ($content->scheduled_for)<dt>Zamanlandı</dt><dd>{{ $content->scheduled_for->format('d.m.Y H:i') }}</dd>@endif
                @if ($content->published_at)<dt>Yayın</dt><dd>{{ $content->published_at->format('d.m.Y H:i') }}</dd>@endif
            </dl>
            @if ($content->excerpt)
                <p class="lede" style="font-size:16px">{{ $content->excerpt }}</p>
            @endif
            @if ($content->body)
                <div class="prose" style="font-size:15px;margin-top:14px">{!! $content->renderedBody() !!}</div>
            @else
                <div class="empty-state" style="margin-top:14px">Gövde boş. Boş içerik yayınlanamaz.</div>
            @endif
        </div>

        {{-- Akış --}}
        <div class="stack" style="gap:20px">
            <div class="panel stack" style="gap:12px">
                <p class="eyebrow" style="margin:0">Yayın akışı</p>
                <ol class="steps">
                    @foreach ([ContentStatus::DRAFT, ContentStatus::IN_REVIEW, ContentStatus::APPROVED, ContentStatus::PUBLISHED] as $i => $step)
                        @continue($step === ContentStatus::APPROVED && ! $content->requires_approval)
                        @php($order = [ContentStatus::DRAFT->value => 0, ContentStatus::IN_REVIEW->value => 1, ContentStatus::APPROVED->value => 2, ContentStatus::SCHEDULED->value => 3, ContentStatus::PUBLISHED->value => 3, ContentStatus::ARCHIVED->value => -1])
                        <li class="{{ $order[$s->value] > $order[$step->value] ? 'is-done' : ($s === $step || ($step === ContentStatus::PUBLISHED && $s === ContentStatus::SCHEDULED) ? 'is-current' : '') }}">
                            <span>{{ $step->label() }}</span>
                        </li>
                    @endforeach
                </ol>

                {{-- DRAFT --}}
                @if ($s === ContentStatus::DRAFT && $can['edit'])
                    <form method="POST" action="{{ route('panel.content.submit', $content) }}">@csrf
                        <button type="submit" class="btn btn--brand btn--block">İncelemeye gönder</button>
                    </form>
                @endif

                {{-- IN_REVIEW --}}
                @if ($s === ContentStatus::IN_REVIEW)
                    @if ($content->requires_approval && $can['approve'])
                        <form method="POST" action="{{ route('panel.content.approve', $content) }}" class="stack" style="gap:8px">@csrf
                            <input class="control" type="text" name="note" maxlength="2000" placeholder="Onay notu (isteğe bağlı)">
                            <button type="submit" class="btn btn--brand btn--block">Onayla</button>
                        </form>
                    @elseif (! $content->requires_approval && $can['publish'])
                        <form method="POST" action="{{ route('panel.content.publish', $content) }}">@csrf
                            <button type="submit" class="btn btn--brand btn--block">Yayınla</button>
                        </form>
                    @endif
                    @if ($can['review'])
                        <form method="POST" action="{{ route('panel.content.reject', $content) }}" class="stack" style="gap:8px">@csrf
                            <input class="control" type="text" name="note" maxlength="2000" required placeholder="Geri gönderme gerekçesi (zorunlu)">
                            <button type="submit" class="btn btn--ghost btn--block">Taslağa geri gönder</button>
                        </form>
                    @endif
                @endif

                {{-- APPROVED --}}
                @if ($s === ContentStatus::APPROVED && $can['publish'])
                    <form method="POST" action="{{ route('panel.content.publish', $content) }}">@csrf
                        <button type="submit" class="btn btn--brand btn--block">Yayınla</button>
                    </form>
                @endif

                {{-- Zamanlama: IN_REVIEW (onaysız) ya da APPROVED --}}
                @if ($can['schedule'] && (($s === ContentStatus::IN_REVIEW && ! $content->requires_approval) || $s === ContentStatus::APPROVED))
                    <form method="POST" action="{{ route('panel.content.schedule', $content) }}" class="inline-form">@csrf
                        <label class="field"><span class="label">Yayın zamanı</span>
                            <input class="control" type="datetime-local" name="scheduled_for" required>
                        </label>
                        <button type="submit" class="btn btn--ghost">Zamanla</button>
                    </form>
                @endif

                {{-- SCHEDULED --}}
                @if ($s === ContentStatus::SCHEDULED)
                    @if ($can['publish'])
                        <form method="POST" action="{{ route('panel.content.publish', $content) }}">@csrf
                            <button type="submit" class="btn btn--brand btn--block">Şimdi yayınla</button>
                        </form>
                    @endif
                    @if ($can['schedule'])
                        <form method="POST" action="{{ route('panel.content.restore', $content) }}">@csrf
                            <button type="submit" class="btn btn--ghost btn--block">Zamanlamayı iptal et (taslağa al)</button>
                        </form>
                    @endif
                @endif

                {{-- PUBLISHED --}}
                @if ($s === ContentStatus::PUBLISHED && $can['publish'])
                    <form method="POST" action="{{ route('panel.content.unpublish', $content) }}" class="stack" style="gap:8px">@csrf
                        <p class="small muted" style="margin:0">Düzenlemek için yayından kaldırın; metin akışı yeniden geçer.</p>
                        <button type="submit" class="btn btn--ghost btn--block">Yayından kaldır (taslağa al)</button>
                    </form>
                @endif

                {{-- ARCHIVED --}}
                @if ($s === ContentStatus::ARCHIVED && $can['edit'])
                    <form method="POST" action="{{ route('panel.content.restore', $content) }}">@csrf
                        <button type="submit" class="btn btn--ghost btn--block">Taslağa al</button>
                    </form>
                @endif

                @if ($s !== ContentStatus::ARCHIVED && $can['archive'])
                    <form method="POST" action="{{ route('panel.content.archive', $content) }}" class="stack" style="gap:8px">@csrf
                        <input class="control" type="text" name="note" maxlength="2000" placeholder="Arşiv notu (isteğe bağlı)">
                        <button type="submit" class="btn btn--ghost btn--block" style="color:var(--danger);border-color:#E9C4BC">Arşivle</button>
                    </form>
                @endif
            </div>

            <div class="panel">
                <p class="eyebrow">Revizyonlar</p>
                <table class="data">
                    <thead><tr><th>#</th><th>Tarih</th><th>Düzenleyen</th><th>Başlık</th></tr></thead>
                    <tbody>
                        @foreach ($revisions as $rev)
                            <tr>
                                <td class="mono">{{ $rev->number }}</td>
                                <td class="small">{{ $rev->created_at?->format('d.m.Y H:i') }}</td>
                                <td class="small">{{ $rev->editor?->name ?? '—' }}</td>
                                <td class="small">{{ $rev->title }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
