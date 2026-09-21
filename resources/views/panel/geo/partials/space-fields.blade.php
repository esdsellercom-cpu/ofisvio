{{-- Masa/ofis alanları: $s (Space|null), $kinds. Birden çok form aynı sayfada olduğundan old() değil kayıt değeri basılır. --}}
<div class="grid-auto" style="--min:150px;--gap:12px">
    <label class="field"><span class="label">Ad / kod</span>
        <input class="control" type="text" name="name" value="{{ $s?->name }}" required maxlength="60" placeholder="A-12">
    </label>
    <label class="field"><span class="label">Tür</span>
        <select class="control" name="kind">
            @foreach ($kinds as $k => $label)
                <option value="{{ $k }}" @selected(($s?->kind ?? 'desk_fixed') === $k)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="field"><span class="label">Kat</span><input class="control" type="text" name="floor" value="{{ $s?->floor }}" maxlength="30" placeholder="2"></label>
    <label class="field"><span class="label">Bölge</span><input class="control" type="text" name="zone" value="{{ $s?->zone }}" maxlength="60" placeholder="Pencere kenarı"></label>
    <label class="field"><span class="label">Kapasite (kişi / eşzamanlı üye)</span><input class="control" type="number" name="capacity" value="{{ $s?->capacity ?? 1 }}" min="1" max="500"></label>
    <label class="field"><span class="label">Aylık ücret ({{ money_symbol() }}, KDV hariç)</span><input class="control mono" type="number" step="0.01" name="monthly_price" value="{{ \App\Support\Money::major($s?->monthly_price ?? 0) }}" min="0"></label>
    <label class="field"><span class="label">Sıra</span><input class="control" type="number" name="sort_order" value="{{ $s?->sort_order ?? 0 }}" min="0" max="999"></label>
    <label class="field"><span class="label">Not</span><input class="control" type="text" name="notes" value="{{ $s?->notes }}" maxlength="300"></label>
</div>
@include('panel.geo.partials.ops-fields', ['m' => $s])
<label class="checkbox-row"><input type="checkbox" name="is_active" value="1" @checked($s?->is_active ?? true)><span>Aktif (tahsis edilebilir, doluluğa sayılır)</span></label>
