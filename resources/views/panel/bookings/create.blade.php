@extends('layouts.panel')

@section('title', 'Oda rezerve et — '.$company->legal_name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a> · <a href="{{ $indexUrl }}">Rezervasyonlar</a></p>
            <h1 class="h2">Oda rezerve et</h1>
        </div>
    </div>

    @if ($rooms->isEmpty())
        <div class="notice" role="status"><span class="notice__dot" aria-hidden="true"></span><div>Şu anda rezervasyona açık oda yok.</div></div>
    @else
        @php($selectedStart = old('start', collect($slots)->first(fn ($s) => ! $s['taken'] && ! $s['past'])['start'] ?? ''))
        <div class="panel" data-booking>
            <p class="eyebrow">1 · Oda ve gün · {{ $badge }}</p>
            @include('panel.bookings.partials.slot-picker', ['pickerUrl' => route('panel.companies.bookings.create', $company)])

            @if ($room)
                <form method="POST" action="{{ $formAction }}" class="stack" style="gap:14px;border-top:1px solid var(--line);padding-top:18px">
                    @csrf
                    <p class="eyebrow" style="margin:0">2 · Süre ve not</p>
                    <input type="hidden" name="room_id" value="{{ $room->id }}">
                    <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                    <input type="hidden" name="start" data-booking-slot value="{{ $selectedStart }}">
                    <div class="grid-auto" style="--min:200px;--gap:12px">
                        <label class="field"><span class="label">Süre</span>
                            <select class="control" name="hours" required>
                                @for ($m = $room->slot_minutes; $m <= $room->max_hours * 60; $m += $room->slot_minutes)
                                    <option value="{{ $m / 60 }}" @selected((string) old('hours', '1') === (string) ($m / 60))>{{ rtrim(rtrim(money($m / 60, 1, ',', ''), '0'), ',') }} saat · {{ number_format($room->hourly_rate * $m / 60) }}</option>
                                @endfor
                            </select>
                        </label>
                        <label class="field"><span class="label">Not (isteğe bağlı)</span>
                            <input class="control" type="text" name="note" maxlength="300" value="{{ old('note') }}" placeholder="Örn. projektör, 6 kişi">
                        </label>
                    </div>
                    @error('start')<p class="field-error">{{ $message }}</p>@enderror
                    @error('room_id')<p class="field-error">{{ $message }}</p>@enderror
                    @error('hours')<p class="field-error">{{ $message }}</p>@enderror
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn--brand" @disabled($selectedStart === '')>Rezerve et</button>
                        <a href="{{ $indexUrl }}" class="btn btn--ghost">Vazgeç</a>
                    </div>
                    <p class="small muted" style="margin:0">Fiyatlar KDV hariçtir; onaylı rezervasyon anında oluşur (çakışma kontrolü sunucuda).</p>
                </form>
            @endif
        </div>
    @endif
@endsection
