@extends('layouts.panel')

@section('title', 'Yeni üyelik')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.subscriptions.index') }}">Üyelikler</a> / yeni</p>
            <h1 class="h2">Yeni üyelik</h1>
            <p>Şirketi bir pakete bağlar; tutar paketin bugünkü fiyatından anlık görüntü olarak yazılır. Askıdaki ya da fesih sürecindeki şirket listede yer almaz.</p>
        </div>
    </div>

    @if ($plans->isEmpty())
        <div class="empty-state">Aktif paket yok. Önce <a href="{{ route('panel.plans.create') }}" style="color:var(--accent-2);font-weight:600">bir paket tanımlayın</a>.</div>
    @else
        <div class="card" style="max-width:720px">
            <div class="card__body">
                <form method="POST" action="{{ route('panel.subscriptions.store') }}" class="stack" style="gap:12px">
                    @csrf
                    <div class="grid g2">
                        <label class="field"><span class="label">Şirket</span>
                            <select class="control" name="company_id" required @error('company_id') aria-invalid="true" @enderror>
                                <option value="">Seçin</option>
                                @foreach ($companies as $c)<option value="{{ $c->id }}" @selected((int) old('company_id') === $c->id)>{{ $c->legal_name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="field"><span class="label">Paket</span>
                            <select class="control" name="plan_id" required @error('plan_id') aria-invalid="true" @enderror>
                                <option value="">Seçin</option>
                                @foreach ($plans as $p)<option value="{{ $p->id }}" @selected((int) old('plan_id') === $p->id)>{{ $p->name }} — {{ number_format($p->price, 0, ',', '.') }} ₺ / {{ $p->periodLabel() }}</option>@endforeach
                            </select>
                            @error('plan_id')<span class="field-error">{{ $message }}</span>@enderror
                        </label>
                        <label class="field"><span class="label">Başlangıç</span><input class="control" type="date" name="starts_on" value="{{ old('starts_on', now()->toDateString()) }}" required></label>
                        <label class="field"><span class="label">Süre (ay)</span><input class="control" type="number" name="months" value="{{ old('months', 12) }}" min="1" max="36" required></label>
                        <label class="field"><span class="label">Lokasyon (isteğe bağlı)</span>
                            <select class="control" name="location_id">
                                <option value="">—</option>
                                @foreach ($locations as $l)<option value="{{ $l->id }}" @selected((int) old('location_id') === $l->id)>{{ $l->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="checkbox-row" style="align-self:end"><input type="checkbox" name="auto_renew" value="1" @checked(old('auto_renew', true))><span>Dönem sonunda yenilenecek (hatırlatma)</span></label>
                    </div>
                    <label class="field"><span class="label">Not (iç)</span><textarea class="control" name="note" maxlength="1000">{{ old('note') }}</textarea></label>
                    <div><button type="submit" class="btn btn--brand">Üyeliği aç</button> <a href="{{ route('panel.subscriptions.index') }}" class="btn btn--ghost">Vazgeç</a></div>
                </form>
            </div>
        </div>
    @endif
@endsection
