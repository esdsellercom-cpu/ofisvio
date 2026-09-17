{{-- Demirbaş ekle/düzenle modalı (faz 46). Alan seçimi lokasyona göre süzülür. --}}
@php($auto = $openModal === 'asset')
<dialog class="modal" id="modal-asset" @if ($auto) data-modal-auto data-modal-auto-action="{{ old('_action') }}" data-modal-auto-method="{{ old('_method', 'POST') }}" @endif>
    <form method="POST" action="{{ route('panel.spaces.inventory.asset.store') }}" data-modal-form>
        @csrf
        <input type="hidden" name="_method" value="POST">
        <input type="hidden" name="_modal" value="asset">
        <input type="hidden" name="_tab" value="{{ $tab }}">
        <input type="hidden" name="_action" value="{{ old('_action') }}">
        <div class="modal__head"><h2 data-modal-title data-default="Demirbaş ekle">Demirbaş ekle</h2><button type="button" class="btn btn--quiet" data-modal-close aria-label="Kapat">✕</button></div>
        <div class="modal__body stack" style="gap:12px">
            @if ($auto && $errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $errors->first() }}</div></div>@endif
            <div class="grid-auto" style="--min:180px;--gap:10px">
                <label class="field"><span class="label">Demirbaş adı</span><input class="control" type="text" name="name" value="{{ old('name') }}" required minlength="2" maxlength="120" placeholder="Ofis koltuğu"></label>
                <label class="field"><span class="label">Kod</span><input class="control mono" type="text" name="code" value="{{ old('code') }}" maxlength="40" placeholder="DMB-0001"></label>
                <label class="field"><span class="label">Kategori</span>
                    <select class="control" name="category">@foreach ($assetCategories as $k => $label)<option value="{{ $k }}" @selected(old('category', 'furniture') === $k)>{{ $label }}</option>@endforeach</select>
                </label>
                <label class="field"><span class="label">Seri no</span><input class="control mono" type="text" name="serial" value="{{ old('serial') }}" maxlength="80"></label>
                <label class="field"><span class="label">Lokasyon</span>
                    <select class="control" name="location_id" required>
                        <option value="">Seçin…</option>
                        @foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected((string) old('location_id') === (string) $loc->id)>{{ $loc->name }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Yerleşik olduğu alan (isteğe bağlı)</span>
                    <select class="control" name="space_id" data-filter-by="location_id">
                        <option value="">— yok —</option>
                        @foreach ($allSpaces as $sp)<option value="{{ $sp->id }}" data-filter="{{ $sp->location_id }}" @selected((string) old('space_id') === (string) $sp->id)>{{ $sp->name }} · {{ $sp->kindLabel() }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Durum</span>
                    <select class="control" name="status">
                        @foreach (['available' => 'Müsait', 'maintenance' => 'Bakımda', 'retired' => 'Hurda / kullanım dışı'] as $k => $label)<option value="{{ $k }}" @selected(old('status', 'available') === $k)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="field" style="grid-column:1/-1"><span class="label">Not</span><input class="control" type="text" name="notes" value="{{ old('notes') }}" maxlength="300"></label>
            </div>
            <p class="small muted" style="margin:0">"Tahsisli" durumu yalnız tahsisle verilir: Hızlı tahsis ya da Tahsisi değiştir ekranından demirbaş seçin.</p>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Kaydet</button></div>
    </form>
</dialog>
