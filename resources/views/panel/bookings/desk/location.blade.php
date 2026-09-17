@extends('layouts.panel')

@section('title', 'Rezervasyon masası — '.$location->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">@can('booking.view')<a href="{{ route('panel.bookings.index') }}">Rezervasyonlar</a> · @endcan{{ $location->city }}</p>
            <h1 class="h2">{{ $location->name }} masası</h1>
        </div>
        <div class="panel-head__actions">
            @can('geo.edit')
                <a href="{{ route('panel.geo.rooms.index', $location) }}" class="btn btn--ghost">Odalar</a>
            @endcan
        </div>
    </div>

    @error('booking')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <form method="GET" class="inline-form" style="margin-bottom:18px">
        <label class="field" style="flex:0 1 180px"><span class="label">Gün</span>
            <input class="control" type="date" name="gun" value="{{ $day->toDateString() }}" onchange="this.form.requestSubmit()">
        </label>
        <a href="{{ route('panel.bookings.location', [$location, 'gun' => $day->copy()->subDay()->toDateString()]) }}" class="btn btn--ghost">‹ Önceki</a>
        <a href="{{ route('panel.bookings.location', [$location, 'gun' => now()->toDateString()]) }}" class="btn btn--ghost">Bugün</a>
        <a href="{{ route('panel.bookings.location', [$location, 'gun' => $day->copy()->addDay()->toDateString()]) }}" class="btn btn--ghost">Sonraki ›</a>
    </form>

    <div class="panel" style="margin-bottom:20px">
        <p class="eyebrow">Doluluk · {{ $day->format('d.m.Y') }} · politika: {{ $policy['auto_confirm'] ? 'otomatik onay' : 'yönetici onayı' }}, en az {{ $policy['min_advance_hours'] }} sa önce, tampon {{ $policy['buffer_minutes'] }} dk</p>
        @forelse ($grid as $roomId => $slots)
            @php($room = $rooms->firstWhere('id', $roomId))
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
                <strong style="min-width:160px">{{ $room->name }}</strong>
                @foreach ($slots as $s)
                    <span class="badge badge--{{ $s['taken'] ? 'danger' : ($s['past'] ? 'muted' : 'ok') }} mono" title="{{ $s['taken'] ? 'Dolu' : ($s['past'] ? 'Geçti' : 'Boş') }}">{{ $s['start'] }}</span>
                @endforeach
            </div>
        @empty
            <p class="muted" style="margin:0">Bu lokasyonda aktif oda yok.</p>
        @endforelse
    </div>

    <div class="table-wrap" style="margin-bottom:20px">
        <table class="data">
            <thead><tr><th>Saat</th><th>No</th><th>Oda</th><th>Müşteri</th><th>Not</th><th>Durum</th><th></th></tr></thead>
            <tbody>
                @forelse ($rows as $b)
                    <tr>
                        <td class="mono small">{{ $b->starts_at->format('H:i') }}–{{ $b->ends_at->format('H:i') }}</td>
                        <td class="mono small">{{ $b->reference }}</td>
                        <td>{{ $b->room->name }}</td>
                        <td>{{ $b->customerLabel() }}<span class="small muted" style="display:block">{{ $b->contactName() }}</span></td>
                        <td class="small">{{ $b->note ?: '—' }}</td>
                        <td><span class="badge badge--{{ $b->status->badge() }}">{{ $b->statusLabel() }}</span>@if ($b->overridden) <span class="badge badge--warn">JIT</span>@endif</td>
                        <td><a href="{{ route('panel.bookings.show', [$location, $b->id]) }}" class="btn btn--ghost btn--pill">Aç</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">Bu gün için rezervasyon yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($canCreate && $rooms->where('is_active', true)->isNotEmpty())
        <div class="panel" style="margin-bottom:20px">
            <p class="eyebrow">Masadan rezervasyon</p>
            <form method="POST" action="{{ route('panel.bookings.location.store', $location) }}" class="stack" style="gap:12px">
                @csrf
                <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                <div class="grid-auto" style="--min:200px;--gap:12px">
                    <label class="field"><span class="label">Şirket</span>
                        <select class="control" name="company_id" required>
                            <option value="">Seçin</option>
                            @foreach ($companies as $c)
                                <option value="{{ $c->id }}" @selected((string) old('company_id') === (string) $c->id)>{{ $c->legal_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field"><span class="label">Oda</span>
                        <select class="control" name="room_id" required>
                            @foreach ($rooms->where('is_active', true) as $r)
                                <option value="{{ $r->id }}" @selected((string) old('room_id') === (string) $r->id)>{{ $r->name }} · {{ $r->open_from }}–{{ $r->open_until }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field"><span class="label">Başlangıç</span>
                        <input class="control mono" type="time" name="start" value="{{ old('start', '10:00') }}" step="1800" required>
                    </label>
                    <label class="field"><span class="label">Süre (saat)</span>
                        <input class="control mono" type="number" name="hours" value="{{ old('hours', '1') }}" min="0.5" max="24" step="0.5" required>
                    </label>
                    <label class="field"><span class="label">Kişi</span>
                        <input class="control mono" type="number" name="participants" value="{{ old('participants', 1) }}" min="1" max="500">
                    </label>
                    <label class="field"><span class="label">Not</span>
                        <input class="control" type="text" name="note" maxlength="300" value="{{ old('note') }}">
                    </label>
                </div>
                @if ($hasOverride)
                    <label class="checkbox-row"><input type="checkbox" name="override" value="1" @checked(old('override'))><span><strong>Kural dışı (JIT)</strong> — açık saat, ufuk ve pasif oda kuralları atlanır; çakışma yine reddedilir.</span></label>
                @endif
                @error('start')<p class="field-error">{{ $message }}</p>@enderror
                @error('room_id')<p class="field-error">{{ $message }}</p>@enderror
                @error('company_id')<p class="field-error">{{ $message }}</p>@enderror
                <div><button type="submit" class="btn btn--brand">Rezerve et</button></div>
            </form>
        </div>
    @endif

    @if ($canRequestOverride)
        <div class="panel" id="jit">
            <p class="eyebrow">Kural dışı erişim (JIT)</p>
            <p class="body-muted" style="margin:0 0 12px">Matris: <code>booking.admin_override</code> JIT ister. Açık saat dışı/ufuk ötesi rezervasyon ve masadan iptal için gerekçeli, süreli erişim; denetim kaydına yazılır.</p>
            <form method="POST" action="{{ route('panel.bookings.location.jit', $location) }}" class="stack" style="gap:10px">
                @csrf
                <label class="field"><span class="label">Gerekçe</span>
                    <textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:64px"></textarea>
                </label>
                @error('reason')<p class="field-error">{{ $message }}</p>@enderror
                <div class="inline-form">
                    <label class="field" style="flex:0 1 140px"><span class="label">Süre (dk)</span>
                        <input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required>
                    </label>
                    <button type="submit" class="btn btn--brand">Erişim aç</button>
                </div>
            </form>
        </div>
    @endif
@endsection
