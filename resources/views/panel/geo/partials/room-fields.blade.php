{{-- Oda alanları: $r (Room|null), $kinds. old() yalnız hata dönüşünde ve o form için anlamlıdır;
     birden çok form aynı sayfada olduğundan old() kullanılmaz, kayıt değeri basılır. --}}
<div class="grid-auto" style="--min:150px;--gap:12px">
    <label class="field"><span class="label">Ad</span>
        <input class="control" type="text" name="name" value="{{ $r?->name }}" required minlength="2" maxlength="80" placeholder="Toplantı 1">
    </label>
    <label class="field"><span class="label">Tür</span>
        <select class="control" name="kind">
            @foreach ($kinds as $k => $label)
                <option value="{{ $k }}" @selected(($r?->kind ?? 'meeting') === $k)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="field"><span class="label">Kapasite</span>
        <input class="control mono" type="number" name="capacity" value="{{ $r?->capacity ?? 4 }}" min="1" max="500" required>
    </label>
    <label class="field"><span class="label">Saatlik ücret (₺, KDV hariç)</span>
        <input class="control mono" type="number" step="0.01" name="hourly_rate" value="{{ \App\Support\Money::major($r?->hourly_rate ?? 0) }}" min="0" max="100000" required>
    </label>
    <label class="field"><span class="label">Açılış</span>
        <input class="control mono" type="time" name="open_from" value="{{ $r?->open_from ?? '09:00' }}" required>
    </label>
    <label class="field"><span class="label">Kapanış</span>
        <input class="control mono" type="time" name="open_until" value="{{ $r?->open_until ?? '18:00' }}" required>
    </label>
    <label class="field"><span class="label">Slot (dk)</span>
        <select class="control" name="slot_minutes">
            @foreach ([15, 30, 45, 60, 90, 120] as $m)
                <option value="{{ $m }}" @selected(($r?->slot_minutes ?? 60) === $m)>{{ $m }}</option>
            @endforeach
        </select>
    </label>
    <label class="field"><span class="label">En fazla (saat)</span>
        <input class="control mono" type="number" name="max_hours" value="{{ $r?->max_hours ?? 8 }}" min="1" max="24" required>
    </label>
    <label class="field"><span class="label">Sıra</span>
        <input class="control mono" type="number" name="sort_order" value="{{ $r?->sort_order ?? 0 }}" min="0" max="999">
    </label>
</div>
<label class="field"><span class="label">Açıklama (isteğe bağlı)</span>
    <input class="control" type="text" name="description" value="{{ $r?->description }}" maxlength="300" placeholder="Projektör, beyaz tahta, 55&quot; ekran">
</label>
@include('panel.geo.partials.ops-fields', ['m' => $r])
<label class="checkbox-row"><input type="checkbox" name="is_active" value="1" @checked($r?->is_active ?? true)><span><strong>Rezervasyona açık</strong> — pasif oda vitrin saatlerinde ve müşteri panelinde görünmez.</span></label>
@error('open_until')<p class="field-error">{{ $message }}</p>@enderror
@error('name')<p class="field-error">{{ $message }}</p>@enderror
