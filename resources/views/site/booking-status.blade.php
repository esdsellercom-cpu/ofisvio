@extends('layouts.site')

@section('content')
    <section class="wrap section" style="padding-top:64px;max-width:720px">
        <p class="eyebrow">Rezervasyon · {{ $b->reference }}</p>
        <h1 class="h2">{{ session('booking_created') ? 'Talebiniz alındı' : 'Rezervasyon durumu' }}</h1>

        <div class="card" style="padding:24px;display:block;margin-top:24px">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
                <strong style="font-size:18px">{{ $b->room->name }} · {{ $b->location->name }}</strong>
                <span class="badge badge--{{ $b->status->badge() }}">{{ $b->statusLabel() }}</span>
            </div>
            <dl class="stack" style="margin:18px 0 0;gap:8px;font-size:15px">
                <div style="display:flex;gap:12px"><dt class="label" style="min-width:110px">Tarih</dt><dd style="margin:0" class="mono">{{ $b->starts_at->format('d.m.Y') }} · {{ $b->starts_at->format('H:i') }}–{{ $b->ends_at->format('H:i') }}</dd></div>
                <div style="display:flex;gap:12px"><dt class="label" style="min-width:110px">Kişi</dt><dd style="margin:0">{{ $b->participant_count }}</dd></div>
                <div style="display:flex;gap:12px"><dt class="label" style="min-width:110px">Tutar</dt><dd style="margin:0" class="mono">{{ money($b->total_amount) }} <span class="small muted">(KDV hariç)</span></dd></div>
                <div style="display:flex;gap:12px"><dt class="label" style="min-width:110px">İletişim</dt><dd style="margin:0">{{ $b->customer_name }} · {{ $b->customer_email }}</dd></div>
            </dl>
            <p class="body-muted" style="margin:18px 0 0">
                @if ($b->isPending())
                    Talebiniz yönetici onayı bekliyor ({{ $badge }}). Sonuç e-posta ile bildirilir; bu sayfayı yer imlerine ekleyerek durumu takip edebilirsiniz.
                @elseif ($b->status === \App\Enums\BookingStatus::CONFIRMED)
                    Rezervasyonunuz onaylandı. Belirtilen saatte {{ $b->location->name }} resepsiyonuna gelmeniz yeterlidir.
                @elseif ($b->status === \App\Enums\BookingStatus::REJECTED)
                    Talebiniz onaylanamadı.@if ($b->rejected_reason) Gerekçe: {{ $b->rejected_reason }}@endif
                @else
                    Durum: {{ $b->statusLabel() }}.
                @endif
            </p>
        </div>
        <p style="margin-top:20px"><a href="{{ route('site.booking.index', ['lokasyon' => $b->location_id]) }}" class="btn btn--ghost">Yeni talep</a></p>
    </section>
@endsection
