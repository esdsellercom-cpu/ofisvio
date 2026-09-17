@extends('layouts.panel')

@section('title', $plan ? 'Paketi düzenle' : 'Yeni paket')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.plans.index') }}">Paketler</a> / {{ $plan ? $plan->name : 'yeni' }}</p>
            <h1 class="h2">{{ $plan ? 'Paketi düzenle' : 'Yeni paket' }}</h1>
        </div>
    </div>

    <div class="card" style="max-width:720px">
        <div class="card__body">
            <form method="POST" action="{{ $plan ? route('panel.plans.update', $plan) : route('panel.plans.store') }}" class="stack" style="gap:12px">
                @csrf
                @if ($plan) @method('PUT') @endif
                <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" value="{{ old('name', $plan?->name) }}" required maxlength="80" @error('name') aria-invalid="true" @enderror>@error('name')<span class="field-error">{{ $message }}</span>@enderror</label>
                <label class="field"><span class="label">Özet</span><input class="control" type="text" name="summary" value="{{ old('summary', $plan?->summary) }}" maxlength="300"></label>
                <div class="grid g3">
                    <label class="field"><span class="label">Fiyat (₺)</span><input class="control" type="number" step="0.01" name="price" value="{{ old('price', \App\Support\Money::major($plan?->price ?? 0)) }}" min="0" required></label>
                    <label class="field"><span class="label">Dönem</span>
                        <select class="control" name="period">
                            @foreach ($periods as $k => $label)<option value="{{ $k }}" @selected(old('period', $plan?->period ?? 'monthly') === $k)>{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="field"><span class="label">Sıra</span><input class="control" type="number" name="sort_order" value="{{ old('sort_order', $plan?->sort_order ?? 0) }}" min="0" max="999"></label>
                </div>
                <label class="field"><span class="label">Hizmet (katalog bağı, isteğe bağlı)</span>
                    <select class="control" name="service_id">
                        <option value="">—</option>
                        @foreach ($services as $s)<option value="{{ $s->id }}" @selected((int) old('service_id', $plan?->service_id) === $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Alan türü (paket bir masa/ofis içeriyorsa)</span>
                    <select class="control" name="space_kind">
                        <option value="">— içermiyor (sanal ofis, hizmet) —</option>
                        @foreach ($spaceKinds as $k => $label)<option value="{{ $k }}" @selected(old('space_kind', $plan?->space_kind) === $k)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Dahil olanlar (satır başına bir madde)</span><textarea class="control" name="features" maxlength="3000" style="min-height:120px">{{ old('features', $plan?->features) }}</textarea></label>
                <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan?->is_active ?? true))><span>Aktif (yeni üyelik açılabilir)</span></label>
                <div><button type="submit" class="btn btn--brand">Kaydet</button> <a href="{{ route('panel.plans.index') }}" class="btn btn--ghost">Vazgeç</a></div>
            </form>
        </div>
    </div>
@endsection
