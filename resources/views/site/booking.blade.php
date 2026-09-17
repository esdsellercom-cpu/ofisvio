@extends('layouts.site')

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <p class="eyebrow">Toplantı odası rezervasyonu · {{ $badge }}</p>
        <h1 class="h2">Uygun saatler</h1>
        <p class="body-muted" style="margin:14px 0 0;max-width:60ch">Lokasyon ve gün seçin; odaların uygunluğu canlı hesaplanır. Talebiniz kaydedilir, {{ $policy['auto_confirm'] ? 'anında onaylanır' : 'yönetici onayından sonra e-posta ile teyit edilir' }}.</p>

        <form method="GET" action="{{ route('site.booking.index') }}" class="inline-form" style="margin-top:26px">
            <label class="field" style="flex:1 1 220px"><span class="label">Lokasyon</span>
                <select class="control" name="lokasyon" onchange="this.form.requestSubmit()">
                    @foreach ($locations as $loc)
                        <option value="{{ $loc->id }}" @selected($location && $loc->id === $location->id)>{{ $loc->name }} · {{ $loc->city }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field" style="flex:0 1 180px"><span class="label">Gün</span>
                <input class="control" type="date" name="gun" value="{{ $day->toDateString() }}" min="{{ now()->toDateString() }}" max="{{ now()->addDays($policy['max_advance_days'])->toDateString() }}" onchange="this.form.requestSubmit()">
            </label>
            <noscript><button type="submit" class="btn btn--ghost">Göster</button></noscript>
        </form>

        @if ($rooms->isEmpty())
            <div class="notice" role="status" style="margin-top:26px"><span class="notice__dot" aria-hidden="true"></span><div>Bu lokasyonda şu anda rezervasyona açık oda yok.</div></div>
        @else
            @php($selectedRoom = $rooms->firstWhere('id', $selectedRoom) ?? $rooms->first())
            @php($selectedStart = old('start', ''))
            <div style="margin-top:30px;display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:32px;align-items:start" data-booking>
                <div class="stack" style="gap:18px">
                    @foreach ($rooms as $room)
                        @php($isSel = $room->id === $selectedRoom->id)
                        <div class="card" style="padding:20px;display:block;border-color:{{ $isSel ? 'var(--brand)' : 'var(--line)' }}">
                            <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap">
                                <div>
                                    <strong style="font-size:16px">{{ $room->name }}</strong>
                                    <span class="small muted" style="display:block">{{ $room->kindLabel() }} · {{ $room->capacity }} kişi · {{ $room->open_from }}–{{ $room->open_until }} @if ($room->description)· {{ $room->description }}@endif</span>
                                    @if ($room->amenityList() !== [])<span class="small muted" style="display:block">{{ implode(' · ', $room->amenityList()) }}</span>@endif
                                </div>
                                <span class="mono" style="color:var(--brand);font-weight:600">{{ money($room->hourly_rate) }}/saat</span>
                            </div>
                            @if ($isSel)
                                <div class="grid-auto" style="--min:78px;--gap:8px;margin-top:14px">
                                    @foreach ($grid[$room->id] as $s)
                                        <button type="button" class="chip chip--square mono" style="font-size:14px;padding:10px 6px" data-booking-slot-btn="{{ $s['start'] }}" @disabled($s['taken'] || $s['past']) aria-pressed="{{ $selectedStart === $s['start'] ? 'true' : 'false' }}" title="{{ $s['taken'] ? 'Dolu' : ($s['past'] ? 'Çok yakın' : 'Boş') }}">{{ $s['start'] }}</button>
                                    @endforeach
                                </div>
                            @else
                                <a href="{{ route('site.booking.index', ['lokasyon' => $location->id, 'gun' => $day->toDateString(), 'oda' => $room->id]) }}" class="btn btn--ghost btn--pill" style="margin-top:12px">Bu odanın saatlerini gör</a>
                            @endif
                        </div>
                    @endforeach
                </div>

                <form method="POST" action="{{ route('site.booking.store') }}" class="card stack" style="padding:24px;display:flex;gap:14px" data-guard>
                    @csrf
                    <input type="hidden" name="room_id" value="{{ $selectedRoom->id }}">
                    <input type="hidden" name="location_id" value="{{ $location->id }}">
                    <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                    <input type="hidden" name="start" data-booking-slot value="{{ $selectedStart }}">
                    <div class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <p class="eyebrow" style="margin:0">{{ $selectedRoom->name }} · {{ $day->format('d.m.Y') }}</p>
                    <p class="small muted" style="margin:0">Soldan bir saat seçin, ardından bilgilerinizi girin.</p>
                    <div class="grid-auto" style="--min:140px;--gap:12px">
                        <label class="field"><span class="label">Süre</span>
                            <select class="control" name="hours" required>
                                @for ($m = $selectedRoom->slot_minutes; $m <= $selectedRoom->max_hours * 60; $m += $selectedRoom->slot_minutes)
                                    <option value="{{ $m / 60 }}" @selected((string) old('hours', '1') === (string) ($m / 60))>{{ rtrim(rtrim(money($m / 60, 1, ',', ''), '0'), ',') }} saat · {{ number_format($selectedRoom->hourly_rate * $m / 60) }}</option>
                                @endfor
                            </select>
                        </label>
                        <label class="field"><span class="label">Kişi sayısı</span>
                            <input class="control mono" type="number" name="participants" min="1" max="{{ $selectedRoom->capacity }}" value="{{ old('participants', 2) }}" required>
                        </label>
                    </div>
                    <div class="grid-auto" style="--min:150px;--gap:12px">
                        <label class="field"><span class="label">Ad Soyad</span><input class="control" type="text" name="name" required minlength="2" maxlength="120" value="{{ old('name') }}"></label>
                        <label class="field"><span class="label">Firma (isteğe bağlı)</span><input class="control" type="text" name="company_name" maxlength="160" value="{{ old('company_name') }}"></label>
                        <label class="field"><span class="label">E-posta</span><input class="control" type="email" name="email" required maxlength="190" value="{{ old('email') }}"></label>
                        <label class="field"><span class="label">Telefon (+90…)</span><input class="control mono" type="tel" name="phone" required placeholder="+905001234567" value="{{ old('phone') }}"></label>
                    </div>
                    <label class="field"><span class="label">Not (isteğe bağlı)</span><input class="control" type="text" name="note" maxlength="300" value="{{ old('note') }}" placeholder="Örn. projektör, ikram"></label>
                    <label class="checkbox-row">
                        <input type="checkbox" name="kvkk" value="1" required @checked(old('kvkk'))>
                        <span><a href="{{ $kvkkUrl }}" style="color:var(--brand);font-weight:600">KVKK</a> aydınlatma metnini okudum, iletişim kurulmasını onaylıyorum.</span>
                    </label>
                    @if ($errors->any())
                        <p class="field-error">{{ $errors->first() }}</p>
                    @endif
                    <button type="submit" class="btn btn--brand btn--block">Talep gönder</button>
                    <p class="small muted" style="margin:0">Fiyatlar KDV hariçtir. {{ $policy['auto_confirm'] ? 'Talep anında onaylanır.' : 'Talep onaylanana kadar saati tutar; onay taahhüdü: '.$badge.'.' }}</p>
                </form>
            </div>
        @endif
    </section>
@endsection
