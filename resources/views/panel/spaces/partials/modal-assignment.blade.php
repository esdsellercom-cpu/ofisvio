{{-- Tahsis düzenleme modalı (faz 46): bitiş, üye, not, demirbaş kümesi. Şirket sabittir (gizli company_id yalnız üye süzgeci için). --}}
@php($auto = $openModal === 'assignment')
<dialog class="modal" id="modal-assignment" @if ($auto) data-modal-auto data-modal-auto-action="{{ old('_action') }}" data-modal-auto-method="PUT" @endif>
    <form method="POST" action="" data-modal-form>
        @csrf
        <input type="hidden" name="_method" value="PUT">
        <input type="hidden" name="_modal" value="assignment">
        <input type="hidden" name="_tab" value="{{ $tab }}">
        <input type="hidden" name="_action" value="{{ old('_action') }}">
        <input type="hidden" name="company_id" value="{{ old('company_id') }}">
        <input type="hidden" name="space_location" value="{{ old('space_location') }}">
        <div class="modal__head"><h2 data-modal-title data-default="Tahsisi düzenle">Tahsisi düzenle</h2><button type="button" class="btn btn--quiet" data-modal-close aria-label="Kapat">✕</button></div>
        <div class="modal__body stack" style="gap:12px">
            @if ($auto && $errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $errors->first() }}</div></div>@endif
            <div class="grid-auto" style="--min:200px;--gap:10px">
                <label class="field"><span class="label">Üye</span>
                    <select class="control" name="user_id" data-filter-by="company_id">
                        <option value="">— şirket geneli —</option>
                        @foreach ($members as $m)<option value="{{ $m->user_id }}" data-filter="{{ $m->company_id }}" @selected((string) old('user_id') === (string) $m->user_id)>{{ $m->user->name }} · {{ __('roles.'.$m->role->name) }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Bitiş (boş = süresiz)</span><input class="control mono" type="date" name="ends_on" value="{{ old('ends_on') }}"></label>
            </div>
            <label class="field"><span class="label">Demirbaşlar (seçili olanlar tahsiste kalır; çıkarılan serbest kalır)</span>
                <select class="control" name="asset_ids[]" multiple size="6" data-filter-by="space_location">
                    @php($assetOptions = $assignableAssets->concat($assignments->flatMap(fn ($row) => $row->assets ?? collect()))->concat($spaces->flatMap(fn ($sp) => $sp->activeAssignments->flatMap(fn ($row) => $row->assets)))->unique('id')->sortBy('name'))
                    @foreach ($assetOptions as $a)
                        <option value="{{ $a->id }}" data-filter="{{ $a->location_id }}" @selected(in_array((string) $a->id, array_map('strval', (array) old('asset_ids', [])), true))>{{ $a->name }}@if ($a->code) · {{ $a->code }}@endif · {{ $a->categoryLabel() }}@if ($a->status === 'assigned') · tahsisli @endif</option>
                    @endforeach
                </select>
            </label>
            <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note') }}" maxlength="300"></label>
            <p class="small muted" style="margin:0">Alanı ya da şirketi değiştirmek için tahsisi sonlandırıp yeni tahsis açın; geçmiş kayıt korunur.</p>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Kaydet</button></div>
    </form>
</dialog>
