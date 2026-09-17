{{-- Şube künye alanları (geo.edit). $loc: Location|null --}}
<div class="grid-auto" style="--min:200px;--gap:12px">
    <label class="field"><span class="label">Şube adı</span>
        <input class="control" type="text" name="name" value="{{ old('name', $loc?->name) }}" required minlength="2" maxlength="120" @error('name') aria-invalid="true" @enderror>
        @error('name')<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span class="label">Şehir</span>
        <input class="control" type="text" name="city" value="{{ old('city', $loc?->city) }}" maxlength="64" placeholder="İstanbul">
    </label>
    <label class="field"><span class="label">Bölge (vitrin süzgeci)</span>
        <input class="control" type="text" name="region" value="{{ old('region', $loc?->region) }}" maxlength="64" placeholder="İstanbul Avrupa">
    </label>
</div>
<label class="field"><span class="label">Adres satırı</span>
    <input class="control" type="text" name="address_line" value="{{ old('address_line', $loc?->address_line) }}" maxlength="255">
</label>
<div class="grid-auto" style="--min:200px;--gap:12px">
    <label class="field"><span class="label">Rozet (kısa vurgu)</span>
        <input class="control" type="text" name="badge" value="{{ old('badge', $loc?->badge) }}" maxlength="120" placeholder="amiral kat · 14. kat terası">
    </label>
    <label class="field"><span class="label">Fiyat metni</span>
        <input class="control mono" type="text" name="price_from" value="{{ old('price_from', $loc?->price_from) }}" maxlength="48" placeholder="Masa ₺4.900/ay">
    </label>
    <label class="field"><span class="label">Sıra</span>
        <input class="control mono" type="number" name="sort_order" value="{{ old('sort_order', $loc?->sort_order ?? 0) }}" min="0" max="9999">
    </label>
</div>
{{-- Hizmetler: Hizmetler modülündeki gerçek kayıtlardan SEÇİM; burada yeni hizmet oluşturulmaz. --}}
<fieldset style="border:1px solid var(--line);border-radius:var(--r-md);padding:12px 14px">
    <legend class="label">Sunulan hizmetler</legend>
    @php($selected = collect(old('services', $loc?->services?->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v)->all())
    <div class="grid-auto" style="--min:180px;--gap:8px">
        @forelse ($allServices as $service)
            <label class="checkbox-row"><input type="checkbox" name="services[]" value="{{ $service->id }}" @checked(in_array($service->id, $selected, true))><span>{{ $service->name }}@unless ($service->is_active) <span class="small muted">(pasif)</span>@endunless</span></label>
        @empty
            <span class="small muted">Henüz hizmet yok.</span>
        @endforelse
    </div>
    <p class="small muted" style="margin:8px 0 0">Yeni hizmet gerekiyorsa <a href="{{ route('panel.services.index') }}">Hizmetler</a> modülünden oluşturun.</p>
</fieldset>
