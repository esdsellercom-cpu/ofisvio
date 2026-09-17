{{-- Oda kartı (faz 46): $r (Room), $canRooms; odalar saatlik rezervasyonla çalışır, tahsis edilmez. --}}
@php($st = ['active' => 'available', 'maintenance' => 'maintenance', 'inactive' => 'inactive'][$r->operationalStatus()])
<article class="inv-card is-{{ $st }}">
    @if ($r->cover)
        <img class="inv-card__cover" src="{{ $r->cover->urlFor(640) }}" alt="{{ $r->cover->alt ?? $r->name }}" loading="lazy">
    @endif
    <div class="inv-card__head">
        <div>
            <div class="inv-card__title">{{ $r->name }}@if ($r->code)<span class="code">{{ $r->code }}</span>@endif</div>
            <div class="inv-card__sub">{{ $r->kindLabel() }} · {{ $r->location->name }} · saatlik oda</div>
        </div>
        <span class="pill {{ match ($st) {'available' => 'g', 'maintenance' => 'w', default => 'n'} }}">{{ $st === 'available' ? 'Rezervasyona açık' : $r->operationalLabel() }}</span>
    </div>
    <dl>
        <dt>Kapasite</dt><dd>{{ $r->capacity }} kişi</dd>
        <dt>Açık saat</dt><dd class="mono">{{ $r->open_from }}–{{ $r->open_until }} · {{ $r->slot_minutes }} dk slot</dd>
        <dt>Ücret</dt><dd>{{ money($r->hourly_rate) }}/saat</dd>
        @if ($st === 'maintenance')<dt>Bakım</dt><dd>{{ $r->maintenance_until?->format('d.m.Y') }} — {{ $r->maintenance_note }}</dd>@endif
        @if ($r->amenityList() !== [])<dt>Olanak</dt><dd class="small">{{ implode(', ', $r->amenityList()) }}</dd>@endif
        <dt>Güncelleme</dt><dd class="mono small">{{ $r->updated_at?->format('d.m.Y H:i') }}</dd>
    </dl>
    <div class="inv-card__foot">
        @can('booking.view')<a href="{{ route('panel.bookings.calendar', $r->location) }}" class="btn btn--quiet">Takvim</a>@endcan
        @if ($canRooms)
            <button type="button" class="btn btn--quiet" data-modal-open="#modal-inventory" data-title="Odayı düzenle · {{ $r->name }}" data-method="PUT" data-action="{{ route('panel.spaces.inventory.room.update', $r->id) }}" data-fill="{{ json_encode(['kind' => $r->kind, 'location_id' => $r->location_id, 'name' => $r->name, 'code' => $r->code, 'capacity' => $r->capacity, 'hourly_rate' => \App\Support\Money::major($r->hourly_rate), 'open_from' => $r->open_from, 'open_until' => $r->open_until, 'slot_minutes' => $r->slot_minutes, 'max_hours' => $r->max_hours, 'room_active' => $r->is_active, 'maintenance_until' => $r->maintenance_until?->toDateString(), 'maintenance_note' => $r->maintenance_note, 'sort_order' => $r->sort_order, 'description' => $r->description, 'amenities' => implode(', ', $r->amenityList()), 'cover_media_id' => $r->cover_media_id]) }}">Düzenle</button>
        @endif
    </div>
</article>
