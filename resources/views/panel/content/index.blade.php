@extends('layouts.panel')

@section('title', 'İçerik')

@php
    use App\Enums\ContentStatus;
    $tone = fn (ContentStatus $s) => match ($s) {
        ContentStatus::PUBLISHED => 'ok',
        ContentStatus::IN_REVIEW, ContentStatus::SCHEDULED => 'warn',
        ContentStatus::APPROVED => 'info',
        ContentStatus::ARCHIVED => 'danger',
        ContentStatus::DRAFT => 'muted',
    };
@endphp

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">CMS · Ofisvio vitrini</p>
            <h1 class="h2">İçerik</h1>
        </div>
        <div class="panel-head__actions">
            @can('content.create')
                <a href="{{ route('panel.content.create', ['kind' => 'post']) }}" class="btn btn--brand">Yeni yazı</a>
                <a href="{{ route('panel.content.create', ['kind' => 'page']) }}" class="btn btn--ghost">Yeni sayfa</a>
            @endcan
        </div>
    </div>

    <form method="GET" class="inline-form" style="margin-bottom:18px">
        <label class="field" style="flex:0 1 180px">
            <span class="label">Tür</span>
            <select class="control" name="kind" onchange="this.form.submit()">
                <option value="">Hepsi</option>
                @foreach ($kinds as $k)
                    <option value="{{ $k->value }}" @selected($kind === $k)>{{ $k->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="field" style="flex:0 1 200px">
            <span class="label">Durum</span>
            <select class="control" name="status" onchange="this.form.submit()">
                <option value="">Hepsi</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected($status === $s)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </label>
        <noscript><button type="submit" class="btn btn--ghost">Süz</button></noscript>
    </form>

    @if ($items->isEmpty())
        <div class="empty-state">Bu süzgeçle eşleşen içerik yok.</div>
    @else
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Başlık</th><th>Tür</th><th>Durum</th><th>Yazar</th><th>Güncelleme</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>
                                <a href="{{ route('panel.content.show', $item) }}">{{ $item->title }}</a>
                                <span class="small muted mono" style="display:block;font-weight:400">/{{ $item->kind->value === 'post' ? 'blog/' : '' }}{{ $item->slug }}</span>
                            </td>
                            <td>{{ $item->kind->label() }}</td>
                            <td>
                                <span class="badge badge--{{ $tone($item->status) }}">{{ $item->status->label() }}</span>
                                @if ($item->requires_approval)
                                    <span class="badge badge--info" style="margin-left:6px" title="Yasal/vergi/KYC içeriği: onay zorunlu">Onaylı</span>
                                @endif
                            </td>
                            <td class="small">{{ $item->author?->name ?? '—' }}</td>
                            <td class="small">{{ $item->updated_at?->format('d.m.Y H:i') }}</td>
                            <td><div class="row-actions"><a href="{{ route('panel.content.show', $item) }}" class="btn btn--ghost btn--pill">Aç</a></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
