@extends('layouts.panel')

@section('title', 'Rezervasyon '.$b->reference)

@section('content')
    @php($S = \App\Enums\BookingStatus::class)
    <div class="panel-head">
        <div>
            <p class="eyebrow">@can('booking.view')<a href="{{ route('panel.bookings.index') }}">Rezervasyonlar</a> · @endcan<a href="{{ route('panel.bookings.location', [$location, 'gun' => $b->starts_at->toDateString()]) }}">{{ $location->name }} masası</a></p>
            <h1 class="h2 mono">{{ $b->reference }} <span class="badge badge--{{ $b->status->badge() }}" style="vertical-align:middle;font-size:13px">{{ $b->statusLabel() }}</span>@if ($b->overridden) <span class="badge badge--warn" style="vertical-align:middle;font-size:13px">JIT</span>@endif</h1>
        </div>
    </div>

    @foreach (['booking', 'start', 'room_id', 'reason', 'note', 'internal_note'] as $key)
        @error($key)<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
    @endforeach

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        <div class="stack" style="gap:20px">
            <div class="panel">
                <p class="eyebrow">Rezervasyon</p>
                <dl class="stack" style="gap:8px;margin:0;font-size:15px">
                    @foreach ([
                        'Müşteri' => $b->customerLabel(),
                        'Kişi' => $b->contactName(),
                        'Telefon' => $b->customer_phone ?? '—',
                        'E-posta' => $b->contactEmail() ?? '—',
                        'Oda' => $b->room->name.' · '.$b->room->kindLabel().' · '.$b->room->capacity.' kişi',
                        'Lokasyon' => $b->location->name,
                        'Tarih' => $b->starts_at->format('d.m.Y'),
                        'Saat' => $b->starts_at->format('H:i').' – '.$b->ends_at->format('H:i').' ('.rtrim(rtrim(number_format($b->hours(), 2, ',', ''), '0'), ',').' sa)',
                        'Kişi sayısı' => $b->participant_count,
                        'Tutar' => money($b->subtotal()).($b->discount_amount > 0 ? ' − indirim '.money($b->discount_amount).($b->discount_reason ? ' ('.$b->discount_reason.')' : '').' = '.money($b->total_amount) : '').' + KDV %'.$b->tax_rate.' '.money($b->tax_amount).' = '.money($b->grandTotal()),
                        'Ödeme' => $b->paymentLabel().($b->paid_at ? ' · '.$b->paid_at->format('d.m.Y') : '').' — tahsilat fatura üzerinden (Finans)',
                        'Olanaklar' => implode(', ', $b->room->amenityList()) ?: '—',
                        'Kaynak' => ($sources[$b->source] ?? $b->source).($b->booker ? ' · '.$b->booker->name : ''),
                        'Onay' => $b->approval_required ? ($b->approved_at ? 'Onaylandı '.$b->approved_at->format('d.m.Y H:i').($b->approver ? ' · '.$b->approver->name : '') : 'Bekliyor'.($b->expires_at ? ' · son: '.$b->expires_at->format('d.m.Y H:i') : '')) : 'Otomatik',
                        'Müşteri notu' => $b->note ?? '—',
                        'Rıza' => $b->consented_at ? $b->consented_at->format('d.m.Y H:i').' · '.$b->consent_ip : '—',
                    ] as $label => $value)
                        <div style="display:flex;gap:12px"><dt class="label" style="min-width:120px;flex:none">{{ $label }}</dt><dd style="margin:0">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </div>

            <div class="panel">
                <p class="eyebrow">Durum geçmişi</p>
                <table class="data">
                    <thead><tr><th>Zaman</th><th>Geçiş</th><th>Yapan</th><th>Gerekçe</th></tr></thead>
                    <tbody>
                        @foreach ($b->history as $h)
                            <tr>
                                <td class="small mono">{{ $h->created_at->format('d.m.Y H:i:s') }}</td>
                                <td class="small">{{ $h->from_status?->label() ?? '—' }} → <strong>{{ $h->to_status->label() }}</strong></td>
                                <td class="small">{{ $h->actor?->name ?? 'Sistem' }}</td>
                                <td class="small">{{ $h->reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <p class="eyebrow">Bildirimler</p>
                <table class="data">
                    <thead><tr><th>Zaman</th><th>Olay</th><th>Kanal</th><th>Alıcı</th><th>Durum</th></tr></thead>
                    <tbody>
                        @forelse ($notificationLogs as $l)
                            <tr>
                                <td class="small mono">{{ $l->created_at?->format('d.m H:i') }}</td>
                                <td class="small">{{ $l->event }}</td>
                                <td class="small">{{ \App\Notifications\NotificationEvents::CHANNELS[$l->channel] ?? $l->channel }}</td>
                                <td class="small mono">{{ $l->maskedRecipient() }}</td>
                                <td><span class="badge badge--{{ in_array($l->status, ['sent', 'delivered'], true) ? 'ok' : ($l->status === 'failed' ? 'danger' : 'muted') }}">{{ \App\Models\NotificationLog::STATUSES[$l->status] ?? $l->status }}</span>@if ($l->error) <span class="small muted">{{ $l->error }}</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">Bildirim yok (kural tanımlı değil ya da alıcı yok).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <p class="eyebrow">Denetim izi</p>
                <table class="data">
                    <thead><tr><th>Zaman</th><th>Eylem</th><th>Yapan</th><th>Değişiklik</th></tr></thead>
                    <tbody>
                        @foreach ($auditTrail as $a)
                            <tr>
                                <td class="small mono">{{ $a->created_at->format('d.m.Y H:i:s') }}</td>
                                <td class="small mono">{{ $a->action }}</td>
                                <td class="small">{{ $a->actor?->name ?? 'Sistem' }}<span class="muted mono" style="display:block">{{ $a->ip }}</span></td>
                                <td class="small mono">{{ $a->after ? json_encode($a->after, JSON_UNESCAPED_UNICODE) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="stack" style="gap:16px">
            @if ($canApprove && $b->isPending())
                <div class="panel stack" style="gap:10px">
                    <p class="eyebrow" style="margin:0">Onay</p>
                    <form method="POST" action="{{ route('panel.bookings.approve', [$location, $b->id]) }}" class="stack" style="gap:8px">@csrf
                        <input class="control" type="text" name="note" maxlength="300" placeholder="Not (isteğe bağlı)">
                        <button type="submit" class="btn btn--brand btn--block">Onayla</button>
                    </form>
                    <form method="POST" action="{{ route('panel.bookings.reject', [$location, $b->id]) }}" class="stack" style="gap:8px">@csrf
                        <input class="control" type="text" name="reason" required minlength="5" maxlength="200" placeholder="Red gerekçesi (zorunlu)">
                        <button type="submit" class="btn btn--ghost btn--block" style="color:var(--danger)">Reddet</button>
                    </form>
                </div>
            @endif

            @if ($canManage && $b->isActive())
                <div class="panel stack" style="gap:10px">
                    <p class="eyebrow" style="margin:0">Operasyon</p>
                    @if ($b->status === $S::CONFIRMED)
                        <form method="POST" action="{{ route('panel.bookings.checkin', [$location, $b->id]) }}">@csrf<button type="submit" class="btn btn--brand btn--block">Check-in</button></form>
                        <form method="POST" action="{{ route('panel.bookings.noshow', [$location, $b->id]) }}" class="stack" style="gap:8px">@csrf
                            <input class="control" type="text" name="note" maxlength="200" placeholder="Not">
                            <button type="submit" class="btn btn--ghost btn--block">Gelmedi</button>
                        </form>
                    @endif
                    @if (in_array($b->status, [$S::CONFIRMED, $S::CHECKED_IN], true))
                        <form method="POST" action="{{ route('panel.bookings.complete', [$location, $b->id]) }}">@csrf<button type="submit" class="btn btn--ghost btn--block">Tamamlandı</button></form>
                    @endif
                    @if ($b->isActive() && ! in_array($b->payment_status, ['paid', 'partial'], true))
                        <details>
                            <summary class="small" style="cursor:pointer">İndirim uygula</summary>
                            <form method="POST" action="{{ route('panel.bookings.discount', [$location, $b->id]) }}" class="stack" style="gap:8px;margin-top:8px">@csrf @method('PUT')
                                <input class="control mono" type="number" name="discount" value="{{ \App\Support\Money::major($b->discount_amount) }}" min="0" step="0.01" placeholder="₺ (brüt {{ money($b->subtotal()) }})" required>
                                <input class="control" type="text" name="reason" value="{{ $b->discount_reason }}" minlength="3" maxlength="200" placeholder="Gerekçe (üye indirimi, iyi niyet…)" required>
                                <button type="submit" class="btn btn--ghost btn--block">İndirimi kaydet</button>
                            </form>
                        </details>
                    @endif
                    <details>
                        <summary class="small" style="cursor:pointer">Yeniden planla / oda değiştir</summary>
                        <form method="POST" action="{{ route('panel.bookings.reschedule', [$location, $b->id]) }}" class="stack" style="gap:8px;margin-top:8px">@csrf @method('PUT')
                            <select class="control" name="room_id">@foreach ($rooms as $r)<option value="{{ $r->id }}" @selected($r->id === $b->room_id)>{{ $r->name }}</option>@endforeach</select>
                            <div class="grid-auto" style="--min:100px;--gap:8px">
                                <input class="control mono" type="date" name="date" value="{{ $b->starts_at->toDateString() }}" required>
                                <input class="control mono" type="time" name="start" value="{{ $b->starts_at->format('H:i') }}" step="1800" required>
                                <input class="control mono" type="number" name="hours" value="{{ $b->hours() }}" min="0.5" max="24" step="0.5" required>
                            </div>
                            <button type="submit" class="btn btn--ghost btn--block">Planı güncelle</button>
                        </form>
                    </details>
                </div>
            @endif

            @if ($canManage)
                <form method="POST" action="{{ route('panel.bookings.note', [$location, $b->id]) }}" class="panel stack" style="gap:8px">@csrf @method('PUT')
                    <p class="eyebrow" style="margin:0">İç not (müşteri görmez)</p>
                    <textarea class="control" name="internal_note" maxlength="500" style="min-height:70px">{{ old('internal_note', $b->internal_note) }}</textarea>
                    <button type="submit" class="btn btn--ghost btn--block">Notu kaydet</button>
                </form>
            @elseif ($b->internal_note)
                <div class="panel"><p class="eyebrow">İç not</p><p class="small" style="margin:0">{{ $b->internal_note }}</p></div>
            @endif

            @if ($hasOverride && $b->isActive())
                <form method="POST" action="{{ route('panel.bookings.location.cancel', [$location, $b->id]) }}" class="panel stack" style="gap:8px;border-color:#E9C4BC" data-confirm="Rezervasyon iptal edilsin mi?">@csrf
                    <p class="eyebrow" style="margin:0;color:var(--danger)">İptal (JIT)</p>
                    <input class="control" type="text" name="reason" required minlength="5" maxlength="200" placeholder="Gerekçe (zorunlu)">
                    <button type="submit" class="btn btn--ghost btn--block" style="color:var(--danger)">İptal et</button>
                </form>
            @endif

            @if (! $canApprove && ! $canManage && ! $hasOverride)
                <p class="small muted">Salt okunur (booking.approve / booking.manage / JIT yok).</p>
            @endif
        </div>
    </div>
@endsection
