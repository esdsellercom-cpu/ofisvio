{{-- Oda + gün seçimi (GET, sayfa yeniler) ve slot çipleri. Çipler ofisvio.js'teki
     rezervasyon seçicisiyle (data-booking kökü) POST formundaki gizli `start` alanını doldurur.
     Kullanım: [data-booking] kökü içinde, POST formundan ÖNCE include edilir.
     $pickerUrl · $rooms · $room · $day · $slots · $horizonDays --}}
<form method="GET" action="{{ $pickerUrl }}" class="inline-form" style="margin-bottom:16px">
    <label class="field" style="flex:1 1 220px"><span class="label">Oda</span>
        <select class="control" name="oda" onchange="this.form.requestSubmit()">
            @foreach ($rooms as $r)
                <option value="{{ $r->id }}" @selected($room && $r->id === $room->id)>@if ($rooms->pluck('location_id')->unique()->count() > 1){{ $r->location->name }} · @endif{{ $r->name }} ({{ $r->capacity }} kişi, {{ money($r->hourly_rate) }}/sa)</option>
            @endforeach
        </select>
    </label>
    <label class="field" style="flex:0 1 180px"><span class="label">Gün</span>
        <input class="control" type="date" name="gun" value="{{ $day->toDateString() }}" min="{{ now()->toDateString() }}" max="{{ now()->addDays($horizonDays)->toDateString() }}" onchange="this.form.requestSubmit()">
    </label>
    <noscript><button type="submit" class="btn btn--ghost">Göster</button></noscript>
</form>

@if ($room)
    <p class="small muted" style="margin:0 0 8px">{{ $room->name }} · {{ $room->kindLabel() }} · {{ $room->open_from }}–{{ $room->open_until }} · slot {{ $room->slot_minutes }} dk · en fazla {{ $room->max_hours }} sa @if ($room->description)· {{ $room->description }}@endif</p>
    <div class="grid-auto" style="--min:86px;--gap:8px;margin-bottom:16px">
        @forelse ($slots as $s)
            <button type="button" class="chip chip--square mono" style="font-size:14px;padding:11px 8px" data-booking-slot-btn="{{ $s['start'] }}" @disabled($s['taken'] || $s['past']) aria-pressed="{{ $selectedStart === $s['start'] ? 'true' : 'false' }}" title="{{ $s['taken'] ? 'Dolu' : ($s['past'] ? 'Geçti' : 'Boş') }}">{{ $s['start'] }}</button>
        @empty
            <span class="muted">Bu oda için slot tanımlı değil.</span>
        @endforelse
    </div>
@endif
