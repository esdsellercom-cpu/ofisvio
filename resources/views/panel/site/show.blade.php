@extends('layouts.panel')

@section('title', $content->title)

@php
    use App\Enums\ContentStatus;
    $s = $content->status;
    $tone = fn (ContentStatus $x) => match ($x) {
        ContentStatus::PUBLISHED => 'ok',
        ContentStatus::IN_REVIEW, ContentStatus::SCHEDULED => 'warn',
        ContentStatus::APPROVED => 'info',
        ContentStatus::ARCHIVED => 'danger',
        ContentStatus::DRAFT => 'muted',
    };
    $publicUrl = $content->website->baseUrl().$content->path();
    $r = fn (string $name) => route('panel.companies.site.'.$name, [$company, $content->id]);
@endphp

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.site.index', $company) }}">Web sitesi</a> · {{ $content->website->name }} · {{ $content->kind->label() }}</p>
            <h1 class="h2">{{ $content->title }}</h1>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $tone($s) }}">{{ $s->label() }}</span>
            @if ($content->requires_approval)
                <span class="badge badge--info" title="Yasal/vergi/KYC içeriği: Ofisvio onayı zorunlu">Onay gerekli</span>
            @endif
            @if ($s === ContentStatus::DRAFT && $can['edit'])
                <a href="{{ $r('edit') }}" class="btn btn--brand">Düzenle</a>
            @endif
            @if ($s->isLive())
                <a href="{{ $publicUrl }}" class="btn btn--ghost" target="_blank" rel="noopener">Sitede aç ↗</a>
            @endif
        </div>
    </div>

    @if ($content->review_note && $s === ContentStatus::DRAFT)
        <div class="notice notice--error" role="status" style="margin-bottom:22px">
            <span class="notice__dot" aria-hidden="true"></span>
            <div><strong>İnceleme notu:</strong> {{ $content->review_note }}</div>
        </div>
    @endif

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        <div class="panel">
            <p class="eyebrow">Önizleme</p>
            <dl class="dl" style="margin-bottom:18px">
                <dt>URL</dt><dd class="mono small">{{ $publicUrl }}</dd>
                @if ($content->scheduled_for)<dt>Yayın zamanı</dt><dd>{{ $content->scheduled_for->format('d.m.Y H:i') }}</dd>@endif
                @if ($content->published_at)<dt>Yayın</dt><dd>{{ $content->published_at->format('d.m.Y H:i') }}</dd>@endif
            </dl>
            @if ($content->excerpt)<p class="lede" style="font-size:16px">{{ $content->excerpt }}</p>@endif
            @if ($content->body)
                <div class="prose" style="font-size:15px;margin-top:14px">{!! $content->renderedBody() !!}</div>
            @else
                <div class="empty-state" style="margin-top:14px">Gövde boş. Boş içerik yayınlanamaz.</div>
            @endif
        </div>

        <div class="stack" style="gap:20px">
            <div class="panel stack" style="gap:12px">
                <p class="eyebrow" style="margin:0">Yayın akışı</p>
                <p class="small muted" style="margin:0">Taslak → inceleme → zamanlama. Zamanı gelince yayına girer. Onay gerektiren metinler Ofisvio onayından sonra yayınlanır.</p>

                @if ($s === ContentStatus::DRAFT && $can['edit'])
                    <form method="POST" action="{{ $r('submit') }}">@csrf
                        <button type="submit" class="btn btn--brand btn--block">İncelemeye gönder</button>
                    </form>
                @endif

                @if ($s === ContentStatus::IN_REVIEW)
                    @if ($content->requires_approval)
                        <p class="small muted" style="margin:0">Ofisvio onayı bekleniyor.</p>
                    @elseif ($can['schedule'])
                        <form method="POST" action="{{ $r('schedule') }}" class="inline-form">@csrf
                            <label class="field"><span class="label">Yayın zamanı</span>
                                <input class="control" type="datetime-local" name="scheduled_for" required>
                            </label>
                            <button type="submit" class="btn btn--brand">Zamanla</button>
                        </form>
                    @endif
                    @if ($can['review'])
                        <form method="POST" action="{{ $r('reject') }}" class="stack" style="gap:8px">@csrf
                            <input class="control" type="text" name="note" maxlength="2000" required placeholder="Geri gönderme gerekçesi (zorunlu)">
                            <button type="submit" class="btn btn--ghost btn--block">Taslağa geri gönder</button>
                        </form>
                    @endif
                @endif

                @if ($s === ContentStatus::APPROVED && $can['schedule'])
                    <form method="POST" action="{{ $r('schedule') }}" class="inline-form">@csrf
                        <label class="field"><span class="label">Yayın zamanı</span>
                            <input class="control" type="datetime-local" name="scheduled_for" required>
                        </label>
                        <button type="submit" class="btn btn--brand">Zamanla</button>
                    </form>
                @endif

                @if ($s === ContentStatus::SCHEDULED && ($can['schedule'] || $can['edit']))
                    <form method="POST" action="{{ $r('restore') }}">@csrf
                        <button type="submit" class="btn btn--ghost btn--block">Zamanlamayı iptal et (taslağa al)</button>
                    </form>
                @endif

                @if ($s === ContentStatus::PUBLISHED)
                    @if ($draft === null)
                        @if ($can['edit'])
                            <form method="POST" action="{{ $r('draft.open') }}" class="stack" style="gap:8px">@csrf
                                <p class="small muted" style="margin:0">Yayındaki sayfa yerinde kalır; kopyası zamanlanınca birleşir.</p>
                                <button type="submit" class="btn btn--brand btn--block">Çalışma taslağı aç</button>
                            </form>
                        @endif
                    @else
                        <div class="notice notice--info" role="status" style="margin:0">
                            <span class="notice__dot" aria-hidden="true"></span>
                            <div>
                                <strong>Çalışma taslağı:</strong> {{ $draft->status->label() }}
                                @if ($draft->scheduled_for)<span class="small muted">· birleşme {{ $draft->scheduled_for->format('d.m.Y H:i') }}</span>@endif
                                @if ($draft->title !== $content->title)<div class="small">Yeni başlık: {{ $draft->title }}</div>@endif
                                @if ($draft->review_note && $draft->status === ContentStatus::DRAFT)<div class="small"><strong>İnceleme notu:</strong> {{ $draft->review_note }}</div>@endif
                            </div>
                        </div>

                        @if ($draft->status === ContentStatus::DRAFT && $can['edit'])
                            <a href="{{ $r('draft.edit') }}" class="btn btn--brand btn--block">Taslağı düzenle</a>
                            <form method="POST" action="{{ $r('draft.submit') }}">@csrf
                                <button type="submit" class="btn btn--ghost btn--block">Taslağı incelemeye gönder</button>
                            </form>
                        @endif

                        @if ($draft->status === ContentStatus::IN_REVIEW)
                            @if ($content->requires_approval)
                                <p class="small muted" style="margin:0">Ofisvio onayı bekleniyor.</p>
                            @elseif ($can['schedule'])
                                <form method="POST" action="{{ $r('draft.schedule') }}" class="inline-form">@csrf
                                    <label class="field"><span class="label">Birleştirme zamanı</span>
                                        <input class="control" type="datetime-local" name="scheduled_for" required>
                                    </label>
                                    <button type="submit" class="btn btn--brand">Zamanla</button>
                                </form>
                            @endif
                            @if ($can['review'])
                                <form method="POST" action="{{ $r('draft.reject') }}" class="stack" style="gap:8px">@csrf
                                    <input class="control" type="text" name="note" maxlength="2000" required placeholder="Geri gönderme gerekçesi (zorunlu)">
                                    <button type="submit" class="btn btn--ghost btn--block">Taslağı geri gönder</button>
                                </form>
                            @endif
                        @endif

                        @if ($draft->status === ContentStatus::APPROVED && $can['schedule'])
                            <form method="POST" action="{{ $r('draft.schedule') }}" class="inline-form">@csrf
                                <label class="field"><span class="label">Birleştirme zamanı</span>
                                    <input class="control" type="datetime-local" name="scheduled_for" required>
                                </label>
                                <button type="submit" class="btn btn--brand">Zamanla</button>
                            </form>
                        @endif

                        @if ($draft->status === ContentStatus::SCHEDULED && ($can['schedule'] || $can['edit']))
                            <form method="POST" action="{{ $r('draft.restore') }}">@csrf
                                <button type="submit" class="btn btn--ghost btn--block">Zamanlamayı iptal et (taslağa al)</button>
                            </form>
                        @endif

                        @if ($can['edit'])
                            <form method="POST" action="{{ $r('draft.discard') }}">@csrf @method('DELETE')
                                <button type="submit" class="btn btn--ghost btn--block" style="color:var(--danger);border-color:#E9C4BC">Taslağı sil</button>
                            </form>
                        @endif
                    @endif
                @endif

                @if ($s === ContentStatus::ARCHIVED && $can['edit'])
                    <form method="POST" action="{{ $r('restore') }}">@csrf
                        <button type="submit" class="btn btn--ghost btn--block">Taslağa al</button>
                    </form>
                @endif
            </div>

            @include('panel.content.partials.link-suggestions')

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
