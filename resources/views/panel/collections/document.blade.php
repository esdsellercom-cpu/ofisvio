@extends('layouts.panel')

@section('title', $document->kindLabel().' · '.$document->number)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.collections.index') }}#belgeler">Tahsilat &amp; belgeler</a> · {{ $document->kindLabel() }}</p>
            <h1 class="h2 mono">{{ $document->number }} @if ($document->isCancelled())<span class="badge badge--danger">İptal</span>@else<span class="badge badge--ok">Geçerli</span>@endif</h1>
            <p>{{ $document->company?->legal_name }} @if ($document->invoice)· Fatura <a href="{{ route('panel.invoices.show', $document->invoice) }}" class="mono">{{ $document->invoice->number }}</a>@endif · {{ $document->created_at->format('d.m.Y H:i') }} · {{ $document->creator?->name ?? 'Sistem' }}</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.collections.documents.pdf', $document) }}" class="btn btn--brand">PDF indir</a>
            <a href="{{ route('panel.collections.documents.print', $document) }}" class="btn btn--ghost" target="_blank" rel="noopener">Yazdır</a>
            <a href="{{ route('panel.collections.templates.edit', $document->kind) }}" class="btn btn--ghost">Şablonu düzenle</a>
        </div>
    </div>

    @error('document')<div class="notice notice--error" role="alert" style="margin-bottom:16px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
    @if ($document->isCancelled())<div class="note c" style="margin-bottom:16px">Bu belge iptal edildi: {{ $document->cancel_reason }} ({{ $document->cancelled_at?->format('d.m.Y H:i') }}). Kayıt geçmişte kalır; yeni belge ilgili tahsilat/faturadan oluşturulur.</div>@endif

    <div class="grid g-2-1">
        <div class="card">
            <div class="card__head"><h3>Önizleme</h3><span class="sub">PDF ve yazdırma ile birebir</span></div>
            <div class="card__body" style="background:var(--surface-3);padding:18px">
                <div style="box-shadow:var(--shadow);border-radius:4px;overflow:hidden">{!! $html !!}</div>
            </div>
        </div>

        <div class="stack" style="gap:14px">
            @if ($canManage && ! $document->isCancelled())
                <form method="POST" action="{{ route('panel.collections.documents.update', $document) }}" class="card">
                    @csrf @method('PUT')
                    <div class="card__head"><h3>Düzenle</h3><span class="sub">Yalnız metin alanları; numara ve tutar değişmez</span></div>
                    <div class="card__body stack" style="gap:10px">
                        @error('customer_name')<div class="field-error">{{ $message }}</div>@enderror
                        <label class="field"><span class="label">Müşteri adı</span><input class="control" type="text" name="customer_name" value="{{ old('customer_name', $document->data['customer_name'] ?? '') }}" required maxlength="160"></label>
                        <label class="field"><span class="label">VKN / TCKN</span><input class="control mono" type="text" name="customer_tax_number" value="{{ old('customer_tax_number', $document->data['customer_tax_number'] ?? '') }}" maxlength="20"></label>
                        <label class="field"><span class="label">Müşteri e-postası</span><input class="control" type="email" name="customer_email" value="{{ old('customer_email', $document->data['customer_email'] ?? '') }}" maxlength="190"></label>
                        @if ($document->kind === 'receipt')
                            <label class="field"><span class="label">Hizmet / açıklama</span><input class="control" type="text" name="description" value="{{ old('description', $document->data['description'] ?? '') }}" maxlength="300"></label>
                        @else
                            <label class="field"><span class="label">Fatura açıklaması</span><input class="control" type="text" name="invoice_description" value="{{ old('invoice_description', $document->data['invoice_description'] ?? '') }}" maxlength="300"></label>
                        @endif
                        <label class="field"><span class="label">Belge tarihi</span><input class="control mono" type="text" name="date" value="{{ old('date', $document->data['date'] ?? '') }}" maxlength="20" placeholder="GG.AA.YYYY"></label>
                        <label class="field"><span class="label">Not</span><textarea class="control" name="note" maxlength="500" style="min-height:64px">{{ old('note', $document->data['note'] ?? '') }}</textarea></label>
                        <button type="submit" class="btn btn--brand">Kaydet ve önizle</button>
                    </div>
                </form>
                <details class="card">
                    <summary class="card__head" style="cursor:pointer"><h3>Belgeyi iptal et</h3></summary>
                    <form method="POST" action="{{ route('panel.collections.documents.cancel', $document) }}" class="card__body stack" style="gap:8px" onsubmit="return confirm('Belge iptal edilsin mi? Kayıt geçmişte kalır.')">@csrf
                        <input class="control" type="text" name="reason" required minlength="5" maxlength="200" placeholder="Gerekçe">
                        <button type="submit" class="btn btn--danger">İptal et</button>
                    </form>
                </details>
            @endif
            <div class="card">
                <div class="card__head"><h3>Belge bilgileri</h3></div>
                <div class="card__body"><dl class="dl">
                    <dt>Tür</dt><dd>{{ $document->kindLabel() }}</dd>
                    <dt>Numara</dt><dd class="mono">{{ $document->number }}</dd>
                    @if ($document->payment)<dt>Tahsilat</dt><dd>{{ money($document->payment->amount, $document->payment->currency) }} · {{ $document->payment->methodLabel() }} · {{ $document->payment->paid_on->format('d.m.Y') }}@if ($document->payment->isCancelled()) <span class="pill n flat">iptal</span>@endif</dd>@endif
                    @if ($document->invoice)<dt>Fatura</dt><dd class="mono">{{ $document->invoice->number }} · {{ $document->invoice->statusLabel() }}</dd>@endif
                    <dt>Oluşturan</dt><dd>{{ $document->creator?->name ?? 'Sistem' }} · {{ $document->created_at->format('d.m.Y H:i') }}</dd>
                </dl></div>
            </div>
        </div>
    </div>
@endsection
