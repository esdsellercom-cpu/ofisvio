{{-- Toplantı & etkinlik: oda listesi GERÇEK oda kayıtlarından (rooms), rozet onay
     politikasından (ayar), araç gerçek rezervasyon akışına (/rezervasyon) gider.
     Rezervasyona açık oda yoksa bölüm basılmaz (uydurma kart yok). --}}
@if ($bookableRooms->isNotEmpty())
<section id="toplanti" class="dark-band" style="margin-top:96px">
    <div class="wrap" style="padding-block:78px;display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:52px;align-items:start">
        <div style="min-width:0">
            <p class="eyebrow">04 — Toplantı &amp; Etkinlik</p>
            <h2 class="h2" style="max-width:22ch">{{ $texts['meeting_title'] }}</h2>
            <p class="lede" style="margin:24px 0 0;max-width:46ch;font-size:17.5px">{{ $texts['meeting_lede'] }}</p>
            <div class="row-list" style="margin-top:34px">
                @foreach ($bookableRooms->take(6) as $room)
                    <div class="row-list__item">
                        <div style="min-width:0">
                            <div style="font-size:15.5px;font-weight:600;color:var(--dark-ink)">{{ $room->name }} <span style="font-weight:400;color:#A8A196">· {{ $room->location->name }}</span></div>
                            <div style="margin-top:5px;font-size:13.5px;color:#A8A196">{{ $room->kindLabel() }} · {{ $room->capacity }} kişi · {{ $room->open_from }}–{{ $room->open_until }} @if ($room->description)· {{ $room->description }}@endif</div>
                        </div>
                        <div class="mono" style="font-size:13.5px;color:var(--brand-light);flex:none">{{ number_format($room->hourly_rate, 0, ',', '.') }} ₺/saat</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card" style="border-radius:20px;padding:26px;color:var(--ink);display:block" data-booking>
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px">
                <h3 class="h3">{{ $texts['booking_widget_title'] }}</h3>
                <span class="label">{{ $bookingBadge }}</span>
            </div>
            <form method="GET" action="{{ route('site.booking.index') }}" style="margin-top:20px">
                <input type="hidden" name="gun" data-booking-date value="{{ $bookingDays[0]['date'] }}">

                <label class="field" style="margin-top:4px">
                    <span class="label">Lokasyon</span>
                    <select class="control" name="lokasyon" data-booking-loc>
                        @foreach ($bookableRooms->pluck('location')->unique('id') as $loc)
                            <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                        @endforeach
                    </select>
                </label>

                <div style="margin-top:18px">
                    <div class="label" style="margin-bottom:9px">Gün</div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @foreach ($bookingDays as $i => $day)
                            <button type="button" class="chip chip--square" data-booking-day="{{ $day['date'] }}" data-day-label="{{ $day['label'] }} {{ $day['short'] }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}">
                                <span style="display:block">{{ $day['label'] }}</span>
                                <span class="mono" style="display:block;font-size:11px;opacity:.65;margin-top:3px">{{ $day['short'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <button type="submit" class="btn btn--brand btn--block" style="margin-top:20px">Uygun saatleri gör</button>
                <p class="mono" style="margin:12px 0 0;font-size:13px;line-height:1.5;color:var(--ink-soft)">Uygunluk canlı hesaplanır; talebiniz {{ $bookingBadge }} ile sonuçlanır.</p>
            </form>
        </div>
    </div>
</section>
@endif
