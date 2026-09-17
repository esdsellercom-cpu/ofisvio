{{-- Hızlı tahsis modalı (faz 46): alan → şirket → üye/üyelik → süre → demirbaşlar. Seçenekler JS ile lokasyon/şirkete göre süzülür. --}}
@php($auto = $openModal === 'assign')
<dialog class="modal" id="modal-assign" @if ($auto) data-modal-auto @endif>
    <form method="POST" action="{{ route('panel.spaces.inventory.assign') }}" data-modal-form>
        @csrf
        <input type="hidden" name="_method" value="POST">
        <input type="hidden" name="_modal" value="assign">
        <input type="hidden" name="_tab" value="{{ $tab }}">
        <div class="modal__head"><h2 data-modal-title data-default="Hızlı tahsis">Hızlı tahsis</h2><button type="button" class="btn btn--quiet" data-modal-close aria-label="Kapat">✕</button></div>
        <div class="modal__body stack" style="gap:12px">
            @if ($auto && $errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $errors->first() }}</div></div>@endif
            <div class="grid-auto" style="--min:200px;--gap:10px">
                <label class="field"><span class="label">Envanter (müsait)</span>
                    <select class="control" name="space_id" required>
                        <option value="">Seçin…</option>
                        @foreach ($assignableSpaces as $sp)<option value="{{ $sp->id }}" data-key="{{ $sp->location_id }}" @selected((string) old('space_id') === (string) $sp->id)>{{ $sp->name }} · {{ $sp->kindLabel() }} · {{ $sp->location->name }}@if ($sp->kind === 'desk_flex') ({{ $sp->occupied() }}/{{ $sp->slots() }})@endif</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Şirket</span>
                    <select class="control" name="company_id" required>
                        <option value="">Seçin…</option>
                        @foreach ($companies as $co)<option value="{{ $co->id }}" @selected((string) old('company_id') === (string) $co->id)>{{ $co->legal_name }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Üye (isteğe bağlı)</span>
                    <select class="control" name="user_id" data-filter-by="company_id">
                        <option value="">— şirket geneli —</option>
                        @foreach ($members as $m)<option value="{{ $m->user_id }}" data-filter="{{ $m->company_id }}" @selected((string) old('user_id') === (string) $m->user_id)>{{ $m->user->name }} · {{ __('roles.'.$m->role->name) }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Üyelik (isteğe bağlı)</span>
                    <select class="control" name="subscription_id" data-filter-by="company_id">
                        <option value="">— yok —</option>
                        @foreach ($subscriptions as $sub)<option value="{{ $sub->id }}" data-filter="{{ $sub->company_id }}" @selected((string) old('subscription_id') === (string) $sub->id)>{{ $sub->plan?->name }} · {{ $sub->ends_on?->format('d.m.Y') }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Başlangıç</span><input class="control mono" type="date" name="starts_on" value="{{ old('starts_on', now()->toDateString()) }}" required></label>
                <label class="field"><span class="label">Bitiş (boş = süresiz)</span><input class="control mono" type="date" name="ends_on" value="{{ old('ends_on') }}"></label>
            </div>
            <label class="field"><span class="label">Tahsis edilen demirbaşlar (aynı lokasyon, müsait)</span>
                <select class="control" name="asset_ids[]" multiple size="5" data-filter-by="space_id">
                    @foreach ($assignableAssets as $a)<option value="{{ $a->id }}" data-filter="{{ $a->location_id }}" @selected(in_array((string) $a->id, array_map('strval', (array) old('asset_ids', [])), true))>{{ $a->name }}@if ($a->code) · {{ $a->code }}@endif · {{ $a->categoryLabel() }}</option>@endforeach
                </select>
                <span class="small muted">Ctrl/⌘ ile birden çok seçin; yeni demirbaş "+ Demirbaş ekle" ile.</span>
            </label>
            <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note') }}" maxlength="300"></label>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Tahsis et</button></div>
    </form>
</dialog>
