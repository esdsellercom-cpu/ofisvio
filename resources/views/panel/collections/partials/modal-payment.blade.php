{{-- Manuel tahsilat modalı (faz 47): müşteri → fatura (şirkete göre süzülür; kalan tutar/hizmet/para birimi otomatik) → yöntem → tarih. --}}
@php($auto = $openModal === 'payment')
@php($companiesForPayment = $openInvoices->pluck('company')->filter()->unique('id')->sortBy('legal_name')->values())
<dialog class="modal" id="modal-payment" @if ($auto) data-modal-auto @endif>
    <form method="POST" action="{{ route('panel.collections.payments.store') }}" data-modal-form>
        @csrf
        <input type="hidden" name="_method" value="POST">
        <input type="hidden" name="_modal" value="payment">
        <div class="modal__head"><h2 data-modal-title data-default="Manuel tahsilat">Manuel tahsilat</h2><button type="button" class="btn btn--quiet" data-modal-close aria-label="Kapat">✕</button></div>
        <div class="modal__body stack" style="gap:12px">
            @if ($auto && $errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $errors->first() }}</div></div>@endif
            <p class="small muted" style="margin:0">Nakit, POS, havale ya da diğer yolla alınan ödeme faturaya ve müşteri hesabına anında yansır. Yalnız tahsilat bekleyen faturalar listelenir.</p>
            <div class="grid-auto" style="--min:200px;--gap:10px">
                <label class="field"><span class="label">Müşteri</span>
                    <select class="control" name="company_id">
                        <option value="">Tüm müşteriler</option>
                        @foreach ($companiesForPayment as $co)<option value="{{ $co->id }}" @selected((string) old('company_id') === (string) $co->id)>{{ $co->legal_name }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Fatura</span>
                    <select class="control" name="invoice_id" required data-filter-by="company_id">
                        <option value="">Seçin…</option>
                        @foreach ($openInvoices as $inv)<option value="{{ $inv->id }}" data-filter="{{ $inv->company_id }}" data-amount="{{ \App\Support\Money::major($inv->outstanding()) }}" data-description="{{ $inv->description }}" data-currency="{{ $inv->currency }}" @selected((string) old('invoice_id') === (string) $inv->id)>{{ $inv->number }} · {{ $inv->company?->legal_name }} · kalan {{ money($inv->outstanding(), $inv->currency) }}</option>@endforeach
                    </select>
                </label>
                <label class="field" style="grid-column:1/-1"><span class="label">Hizmet / açıklama</span><input class="control" type="text" name="description" value="{{ old('description') }}" maxlength="200" data-sync="invoice_id:description" placeholder="Fatura açıklaması otomatik gelir"></label>
                <label class="field"><span class="label">Tutar</span><input class="control mono" type="text" name="amount" value="{{ old('amount') }}" required inputmode="decimal" data-sync="invoice_id:amount" placeholder="0,00"></label>
                <label class="field"><span class="label">Para birimi</span><input class="control mono" type="text" name="currency" value="{{ old('currency', $currency) }}" readonly data-sync="invoice_id:currency"><span class="small muted">Faturanın para birimi; değiştirilemez.</span></label>
                <label class="field"><span class="label">Ödeme yöntemi</span>
                    <select class="control" name="method" required>
                        @foreach ($methods as $k => $label)<option value="{{ $k }}" @selected(old('method', 'cash') === $k)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Tarih</span><input class="control mono" type="date" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}" required max="{{ now()->toDateString() }}"></label>
                <label class="field"><span class="label">Açıklama / referans</span><input class="control" type="text" name="reference" value="{{ old('reference') }}" maxlength="80" placeholder="Dekont no, POS slip no…"></label>
                <label class="field" style="grid-column:1/-1"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note') }}" maxlength="300"></label>
            </div>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button>
            <button type="submit" class="btn btn--ghost">Kaydet</button>
            <button type="submit" class="btn btn--brand" name="then" value="receipt">Kaydet ve makbuz oluştur</button>
        </div>
    </form>
</dialog>
