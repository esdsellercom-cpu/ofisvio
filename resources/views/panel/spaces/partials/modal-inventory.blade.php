{{-- Envanter ekle/düzenle modalı (faz 46): tek form; tür seçimine göre alan (space.manage) ya da oda (geo.edit) rotasına gider. --}}
@php($auto = $openModal === 'inventory')
<dialog class="modal" id="modal-inventory" @if ($auto) data-modal-auto data-modal-auto-action="{{ old('_action') }}" data-modal-auto-method="{{ old('_method', 'POST') }}" @endif>
    <form method="POST" action="{{ route('panel.spaces.inventory.space.store') }}" data-modal-form data-space-action="{{ route('panel.spaces.inventory.space.store') }}" data-room-action="{{ route('panel.spaces.inventory.room.store') }}">
        @csrf
        <input type="hidden" name="_method" value="POST">
        <input type="hidden" name="_modal" value="inventory">
        <input type="hidden" name="_tab" value="{{ $tab }}">
        <input type="hidden" name="_action" value="{{ old('_action') }}">
        <div class="modal__head"><h2 data-modal-title data-default="Envanter ekle">Envanter ekle</h2><button type="button" class="btn btn--quiet" data-modal-close aria-label="Kapat">✕</button></div>
        <div class="modal__body stack" style="gap:12px">
            @if ($auto && $errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $errors->first() }}</div></div>@endif
            <div class="grid-auto" style="--min:170px;--gap:10px">
                <label class="field"><span class="label">Envanter tipi</span>
                    <select class="control" name="kind" data-group-source required>
                        @if ($canManage)
                            <optgroup label="Aylık tahsis (masa / ofis)">
                                @foreach ($spaceKinds as $k => $label)<option value="{{ $k }}" data-group="space" @selected(old('kind') === $k)>{{ $label }}</option>@endforeach
                            </optgroup>
                        @endif
                        @if ($canRooms)
                            <optgroup label="Saatlik oda">
                                @foreach ($roomKinds as $k => $label)<option value="{{ $k }}" data-group="room" @selected(old('kind') === $k)>{{ $label }}</option>@endforeach
                            </optgroup>
                        @endif
                    </select>
                </label>
                <label class="field"><span class="label">Lokasyon</span>
                    <select class="control" name="location_id" required>
                        <option value="">Seçin…</option>
                        @foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected((string) old('location_id') === (string) $loc->id)>{{ $loc->name }} · {{ $loc->city }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Envanter adı</span><input class="control" type="text" name="name" value="{{ old('name') }}" required maxlength="60" placeholder="Masa A-104"></label>
                <label class="field"><span class="label">Kod</span><input class="control mono" type="text" name="code" value="{{ old('code') }}" maxlength="40" placeholder="A-104"></label>
            </div>

            {{-- Masa / ofis alanları --}}
            <div class="grid-auto" style="--min:150px;--gap:10px" data-when="kind:{{ implode(',', array_keys($spaceKinds)) }}">
                <label class="field"><span class="label">Kat</span><input class="control" type="text" name="floor" value="{{ old('floor') }}" maxlength="30" placeholder="2"></label>
                <label class="field"><span class="label">Alan / bölüm</span><input class="control" type="text" name="zone" value="{{ old('zone') }}" maxlength="60" placeholder="Pencere kenarı"></label>
                <label class="field"><span class="label">Kapasite</span><input class="control mono" type="number" name="capacity" value="{{ old('capacity', 1) }}" min="1" max="500"></label>
                <label class="field"><span class="label">Aylık ücret ({{ money_symbol() }})</span><input class="control mono" type="number" step="0.01" name="monthly_price" value="{{ old('monthly_price', 0) }}" min="0"></label>
                <label class="field"><span class="label">Durum</span>
                    <select class="control" name="status">
                        <option value="active" @selected(old('status', 'active') === 'active')>Aktif</option>
                        <option value="maintenance" @selected(old('status') === 'maintenance')>Bakımda</option>
                        <option value="inactive" @selected(old('status') === 'inactive')>Pasif</option>
                    </select>
                </label>
                <label class="field"><span class="label">Sıra</span><input class="control mono" type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="999"></label>
                <div class="grid-auto" style="--min:150px;--gap:10px;grid-column:1/-1" data-when="status:maintenance">
                    <label class="field"><span class="label">Bakım bitişi</span><input class="control mono" type="date" name="maintenance_until" value="{{ old('maintenance_until') }}"></label>
                    <label class="field"><span class="label">Bakım notu</span><input class="control" type="text" name="maintenance_note" value="{{ old('maintenance_note') }}" maxlength="200"></label>
                </div>
                <label class="field" style="grid-column:1/-1"><span class="label">Açıklama / not</span><input class="control" type="text" name="notes" value="{{ old('notes') }}" maxlength="300"></label>
            </div>

            {{-- Oda alanları --}}
            <div class="grid-auto" style="--min:150px;--gap:10px" data-when="kind:{{ implode(',', array_keys($roomKinds)) }}">
                <label class="field"><span class="label">Kapasite (kişi)</span><input class="control mono" type="number" name="capacity" value="{{ old('capacity', 4) }}" min="1" max="500"></label>
                <label class="field"><span class="label">Saatlik ücret ({{ money_symbol() }})</span><input class="control mono" type="number" step="0.01" name="hourly_rate" value="{{ old('hourly_rate', 0) }}" min="0"></label>
                <label class="field"><span class="label">Açılış</span><input class="control mono" type="time" name="open_from" value="{{ old('open_from', '09:00') }}"></label>
                <label class="field"><span class="label">Kapanış</span><input class="control mono" type="time" name="open_until" value="{{ old('open_until', '18:00') }}"></label>
                <label class="field"><span class="label">Slot (dk)</span>
                    <select class="control" name="slot_minutes">@foreach ([15, 30, 45, 60, 90, 120] as $m)<option value="{{ $m }}" @selected((int) old('slot_minutes', 60) === $m)>{{ $m }}</option>@endforeach</select>
                </label>
                <label class="field"><span class="label">En fazla (saat)</span><input class="control mono" type="number" name="max_hours" value="{{ old('max_hours', 8) }}" min="1" max="24"></label>
                <label class="field"><span class="label">Bakım bitişi</span><input class="control mono" type="date" name="maintenance_until" value="{{ old('maintenance_until') }}"></label>
                <label class="field"><span class="label">Bakım notu</span><input class="control" type="text" name="maintenance_note" value="{{ old('maintenance_note') }}" maxlength="200"></label>
                <label class="field"><span class="label">Sıra</span><input class="control mono" type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="999"></label>
                <label class="field" style="grid-column:1/-1"><span class="label">Açıklama</span><input class="control" type="text" name="description" value="{{ old('description') }}" maxlength="300"></label>
                <label class="checkbox-row" style="grid-column:1/-1"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))><span>Rezervasyona açık (pasif oda vitrinde görünmez)</span></label>
            </div>

            <div class="grid-auto" style="--min:200px;--gap:10px">
                <label class="field"><span class="label">Olanaklar (virgülle)</span><input class="control" type="text" name="amenities" value="{{ old('amenities') }}" maxlength="1000" placeholder="Monitör, Kilitli dolap, Klima"></label>
                <label class="field"><span class="label">Görsel (lokasyon galerisi)</span>
                    <select class="control" name="cover_media_id" data-filter-by="location_id">
                        <option value="">— yok —</option>
                        @foreach ($gallery as $link)<option value="{{ $link->media_id }}" data-filter="{{ $link->location_id }}" @selected((string) old('cover_media_id') === (string) $link->media_id)>{{ $link->media->alt ?: $link->media->original_name }} ({{ $link->category }})</option>@endforeach
                    </select>
                </label>
            </div>
            <p class="small muted" style="margin:0">Yeni görsel yüklemek için <a href="{{ route('panel.geo.index') }}">lokasyon görselleri</a>; buradaki seçim yalnız o lokasyonun galerisinden.</p>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Kaydet</button></div>
    </form>
</dialog>
