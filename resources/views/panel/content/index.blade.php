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
            <p class="eyebrow">CMS · {{ $website->name }}@if ($website->domain) · {{ $website->domain }}@endif</p>
            <h1 class="h2">{{ $kind?->value === 'page' ? 'Sayfalar' : ($kind?->value === 'post' ? 'Yazılar' : 'İçerik') }}</h1>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.content.calendar', ['website' => $website->id]) }}" class="btn btn--ghost">Takvim</a>
            @can('content.edit')
                <a href="{{ route('panel.content.menu', ['website' => $website->id]) }}" class="btn btn--ghost">Menü</a>
            @endcan
            @if ($website->is_default)
                @can('content.publish')
                    <a href="{{ route('panel.content.blocks') }}" class="btn btn--ghost">Ana sayfa</a>
                @endcan
            @endif
            @can('content.create')
                <a href="{{ route('panel.content.create', ['kind' => 'post', 'website' => $website->id]) }}" class="btn btn--brand">Yeni yazı</a>
                <a href="{{ route('panel.content.create', ['kind' => 'page', 'website' => $website->id]) }}" class="btn btn--ghost">Yeni sayfa</a>
            @endcan
        </div>
    </div>

    <form method="GET" class="inline-form" style="margin-bottom:18px">
        @if ($websites->count() > 1)
            <label class="field" style="flex:0 1 220px">
                <span class="label">Site</span>
                <select class="control" name="website" onchange="this.form.submit()">
                    @foreach ($websites as $site)
                        <option value="{{ $site->id }}" @selected($site->id === $website->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <input type="hidden" name="website" value="{{ $website->id }}">
        @endif
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
        <label class="field" style="flex:1 1 220px">
            <span class="label">Ara (başlık, slug)</span>
            <input class="control" type="search" name="q" value="{{ $q }}" maxlength="120">
        </label>
        <button type="submit" class="btn btn--ghost">Süz</button>
    </form>

    @if ($items->isEmpty())
        <div class="empty-state">Bu süzgeçle eşleşen içerik yok.</div>
    @else
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th style="width:56px">Görsel</th><th>Başlık</th><th>Tür</th><th>Durum</th><th>Yazar</th><th>Güncelleme</th><th>SEO</th><th>GEO</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>@if ($item->cover_url)<img src="{{ $item->cover_url }}" alt="" loading="lazy" style="width:48px;height:36px;object-fit:cover;border-radius:6px;display:block">@else<span class="muted small">—</span>@endif</td>
                            <td>
                                <a href="{{ route('panel.content.show', $item) }}">{{ $item->title }}</a>
                                <span class="small muted mono" style="display:block;font-weight:400">{{ $item->path() }}</span>
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
                            {{-- SEO skoru kayıtta hesaplanır (SeoAnalyzer); GEO = özet + soru/SSS alanı dolu (faz 48) --}}
                            <td>@if ($item->seo_score !== null)<span class="pill {{ $item->seo_score >= 80 ? 'g' : ($item->seo_score >= 50 ? 'w' : 'c') }} flat" title="{{ \App\Content\SeoAnalyzer::scoreLabel($item->seo_score) }}">{{ $item->seo_score }}</span>@else<span class="muted small">—</span>@endif</td>
                            <td>@if ($item->geoReady())<span class="pill g flat">hazır</span>@else<span class="pill n flat" title="GEO sekmesinde özet ve sorular doldurulmalı">eksik</span>@endif</td>
                            <td><div class="row-actions"><a href="{{ route('panel.content.show', $item) }}" class="btn btn--ghost btn--pill">Aç</a>@if ($item->status === \App\Enums\ContentStatus::DRAFT && auth()->user()->can('content.edit')) <a href="{{ route('panel.content.edit', $item) }}" class="btn btn--ghost btn--pill">Düzenle</a>@elseif ($item->status->isLive() && auth()->user()->can('content.edit')) <a href="{{ route('panel.content.draft.edit', $item) }}" class="btn btn--ghost btn--pill">Taslak</a>@endif</div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px">{{ $items->links() }}</div>
    @endif
@endsection
