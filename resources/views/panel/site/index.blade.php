@extends('layouts.panel')

@section('title', 'Web sitesi — '.$company->legal_name)

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
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a> · {{ $activeOrganization->name }}</p>
            <h1 class="h2">Web sitesi</h1>
        </div>
        <div class="panel-head__actions">
            @foreach ($websites as $site)
                <a href="{{ $site->baseUrl() }}" class="btn btn--ghost" target="_blank" rel="noopener">{{ $site->domain ?: $site->name }} ↗</a>
            @endforeach
        </div>
    </div>

    @if ($websites->isEmpty())
        <div class="empty-state">
            Organizasyonunuz için henüz bir web sitesi açılmamış. Site ve ilk sayfalar Ofisvio ekibince açılır; sonra buradan düzenlersiniz.
        </div>
    @else
        <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
            Sayfalarınızı burada düzenler, incelemeye gönderir ve <strong>zamanlarsınız</strong>; zamanı gelince yayına girer.
            Yayındaki bir sayfayı değiştirmek için çalışma taslağı açın — yayındaki metin, taslak birleşene kadar değişmez.
            Yeni sayfa ve doğrudan yayın Ofisvio ekibindedir.
        </p>

        @if ($items->isEmpty())
            <div class="empty-state">Sitede henüz içerik yok.</div>
        @else
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Başlık</th><th>Tür</th><th>Durum</th><th>Çalışma taslağı</th><th>Güncelleme</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>
                                    <a href="{{ route('panel.companies.site.show', [$company, $item->id]) }}">{{ $item->title }}</a>
                                    <span class="small muted mono" style="display:block;font-weight:400">{{ $item->website->domain ?: $item->website->name }}{{ $item->path() }}</span>
                                </td>
                                <td>{{ $item->kind->label() }}</td>
                                <td>
                                    <span class="badge badge--{{ $tone($item->status) }}">{{ $item->status->label() }}</span>
                                    @if ($item->status === ContentStatus::SCHEDULED && $item->scheduled_for)
                                        <span class="small muted" style="display:block">{{ $item->scheduled_for->format('d.m.Y H:i') }}</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if ($item->draft)
                                        <span class="badge badge--{{ $tone($item->draft->status) }}">{{ $item->draft->status->label() }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small">{{ $item->updated_at?->format('d.m.Y H:i') }}</td>
                                <td><div class="row-actions"><a href="{{ route('panel.companies.site.show', [$company, $item->id]) }}" class="btn btn--ghost btn--pill">Aç</a></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
