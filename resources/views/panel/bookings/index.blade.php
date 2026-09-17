@extends('layouts.panel')

@section('title', 'Rezervasyonlar — '.$company->legal_name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a></p>
            <h1 class="h2">Rezervasyonlar</h1>
        </div>
        <div class="panel-head__actions">
            @can('booking.create', $company)
                <a href="{{ route('panel.companies.bookings.create', $company) }}" class="btn btn--brand">Oda rezerve et</a>
            @endcan
        </div>
    </div>

    @error('booking')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>No</th><th>Tarih</th><th>Oda</th><th>Lokasyon</th><th class="num">Süre</th><th class="num">Tutar</th><th>Durum</th><th>Açan</th><th></th></tr></thead>
            <tbody>
                @forelse ($bookings as $b)
                    <tr>
                        <td class="mono small">{{ $b->reference }}</td>
                        <td class="mono small">{{ $b->starts_at->format('d.m.Y H:i') }}–{{ $b->ends_at->format('H:i') }}</td>
                        <td>{{ $b->room->name }}<span class="small muted" style="display:block">{{ $b->room->kindLabel() }}</span></td>
                        <td>{{ $b->location->name }}</td>
                        <td class="num mono">{{ rtrim(rtrim(number_format($b->hours(), 2, ',', ''), '0'), ',') }} sa</td>
                        <td class="num mono">{{ money($b->total_amount) }}</td>
                        <td>
                            <span class="badge badge--{{ $b->status->badge() }}">{{ $b->statusLabel() }}</span>
                            @if ($b->note)<span class="small muted" style="display:block">{{ $b->note }}</span>@endif
                            @if ($b->cancel_reason)<span class="small muted" style="display:block">İptal: {{ $b->cancel_reason }}</span>@endif
                        </td>
                        <td class="small">{{ $b->booker?->name }}</td>
                        <td>
                            @if ($b->isUpcoming())
                                @can('booking.cancel', $company)
                                    <form method="POST" action="{{ route('panel.companies.bookings.cancel', [$company, $b]) }}" class="inline-form" onsubmit="return confirm('Rezervasyon iptal edilsin mi?')">
                                        @csrf
                                        <input class="control" type="text" name="reason" placeholder="Neden (isteğe bağlı)" maxlength="200" style="min-width:160px">
                                        <button type="submit" class="btn btn--ghost btn--pill">İptal et</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="muted">Henüz rezervasyon yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px">{{ $bookings->links() }}</div>
    <p class="small muted" style="margin-top:12px">İptal, başlangıçtan en az {{ $policy['cancel_notice_hours'] }} saat önce yapılabilir; sonrası için resepsiyonla görüşün. {{ $policy['auto_confirm'] ? 'Talepler anında onaylanır.' : 'Talepler yönetici onayından sonra kesinleşir.' }}</p>
@endsection
