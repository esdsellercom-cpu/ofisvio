@php($sent = session('lead_sent') === 'booking')
@if (! empty($blocks['room_types']))
<section id="toplanti" class="dark-band" style="margin-top:96px">
    <div class="wrap" style="padding-block:78px;display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:52px;align-items:start">
        <div style="min-width:0">
            <p class="eyebrow">04 — Toplantı &amp; Etkinlik</p>
            <h2 class="h2" style="max-width:22ch">{{ $texts['meeting_title'] }}</h2>
            <p class="lede" style="margin:24px 0 0;max-width:46ch;font-size:17.5px">{{ $texts['meeting_lede'] }}</p>
            <div class="row-list" style="margin-top:34px">
                @foreach ($blocks['room_types'] as $room)
                    <div class="row-list__item">
                        <div style="min-width:0">
                            <div style="font-size:15.5px;font-weight:600;color:var(--dark-ink)">{{ $room['title'] }}</div>
                            <div style="margin-top:5px;font-size:13.5px;color:#A8A196">{{ $room['meta'] }}</div>
                        </div>
                        <div class="mono" style="font-size:13.5px;color:var(--brand-light);flex:none">{{ $room['price'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card" style="border-radius:20px;padding:26px;color:var(--ink);display:block" data-booking>
            @if ($sent)
                <div style="padding:28px 8px;text-align:center">
                    <span style="width:40px;height:40px;border-radius:99px;background:var(--brand);display:block;margin:0 auto 18px"></span>
                    <div style="font-size:19px;font-weight:600;letter-spacing:-.02em">Ön talebiniz alındı</div>
                    <p class="body-muted" style="margin:10px auto 0;max-width:34ch">{{ session('lead_summary') }}</p>
                    <a href="#toplanti" class="btn btn--ghost" style="margin-top:22px">Yeni talep</a>
                </div>
            @else
                <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px">
                    <h3 class="h3">{{ $texts['booking_widget_title'] }}</h3>
                    @if ($texts['booking_widget_badge'] !== '')<span class="label">{{ $texts['booking_widget_badge'] }}</span>@endif
                </div>

                {{-- DÜRÜSTLÜK: Tasarımda bu araç "rezervasyon oluşturuldu" diyordu.
                     Rezervasyon modülü (booking.create) henüz yazılmadı; onaylanmış
                     bir rezervasyon göstermek kullanıcıya gelmediği bir odayı
                     ayırttığını sandırırdı. Bu yüzden ÖN TALEP kaydı oluşturur. --}}
                <form method="POST" action="{{ route('site.leads.store') }}" data-guard style="margin-top:20px">
                    @csrf
                    <input type="hidden" name="kind" value="booking">
                    <input type="hidden" name="solution" value="Toplantı Odası">
                    <input type="hidden" name="requested_date" data-booking-date value="{{ $bookingDays[0]['date'] }}">
                    <input type="hidden" name="requested_slot" data-booking-slot value="{{ $bookingSlots[1] ?? $bookingSlots[0] ?? '' }}">
                    <div class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                    <label class="field" style="margin-top:4px">
                        <span class="label">Lokasyon</span>
                        <select class="control" name="location_id" data-booking-loc>
                            @foreach ($locations as $loc)
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

                    <div style="margin-top:18px">
                        <div class="label" style="margin-bottom:9px">Saat</div>
                        <div class="grid-auto" style="--min:86px;--gap:8px">
                            {{-- Saatler rezervasyona açık odalardan türetilir (BookingService::publicSlots); oda yoksa saat sorulmaz. --}}
                            @forelse ($bookingSlots as $i => $slot)
                                <button type="button" class="chip chip--square mono" style="font-size:14px;padding:11px 8px" data-booking-slot-btn="{{ $slot }}" aria-pressed="{{ $i === (isset($bookingSlots[1]) ? 1 : 0) ? 'true' : 'false' }}">{{ $slot }}</button>
                            @empty
                                <span class="small muted">Saat, teyit sırasında birlikte belirlenir.</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid-auto" style="--min:150px;--gap:12px;margin-top:18px">
                        <label class="field">
                            <span class="label">Ad Soyad</span>
                            <input class="control" type="text" name="name" required maxlength="120" value="{{ old('name') }}">
                        </label>
                        <label class="field">
                            <span class="label">E-posta</span>
                            <input class="control" type="email" name="email" required maxlength="190" value="{{ old('email') }}">
                        </label>
                    </div>

                    <label class="checkbox-row" style="margin-top:14px">
                        <input type="checkbox" name="kvkk" value="1" required>
                        <span><a href="{{ $kvkkUrl }}" style="color:var(--brand);font-weight:600">KVKK</a> aydınlatma metnini okudum, iletişim kurulmasını onaylıyorum.</span>
                    </label>

                    @if ($errors->any() && old('kind') === 'booking')
                        <p class="field-error" style="margin-top:10px">{{ $errors->first() }}</p>
                    @endif

                    <button type="submit" class="btn btn--brand btn--block" style="margin-top:20px">Ön talep gönder</button>
                    <p class="mono" style="margin:12px 0 0;font-size:13px;line-height:1.5;color:var(--ink-soft)" data-booking-summary></p>
                </form>
            @endif
        </div>
    </div>
</section>
@endif
