@extends('layouts.panel')

@section('title', 'Yeni fatura')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.invoices.index') }}">Faturalar</a> / yeni</p>
            <h1 class="h2">Yeni fatura</h1>
            <p>Taslak olarak kaydedilir; "yayınla" işaretliyse numara alır ve tahsilata açılır. KDV ve vade varsayılanları Ayarlar › Finans'tan gelir.</p>
        </div>
    </div>

    <div class="card" style="max-width:760px">
        <div class="card__body">
            <form method="GET" class="inline-form" style="margin-bottom:8px">
                <label class="field" style="flex:1 1 260px"><span class="label">Şirket</span>
                    <select class="control" name="sirket" onchange="this.form.requestSubmit()">
                        <option value="">Seçin</option>
                        @foreach ($companies as $c)<option value="{{ $c->id }}" @selected($companyId === $c->id)>{{ $c->legal_name }}</option>@endforeach
                    </select>
                </label>
                <noscript><button type="submit" class="btn btn--ghost">Şirketi seç</button></noscript>
            </form>

            @if ($companyId > 0)
                <form method="POST" action="{{ route('panel.invoices.store') }}" class="stack" style="gap:12px">
                    @csrf
                    <input type="hidden" name="company_id" value="{{ $companyId }}">
                    @error('company_id')<span class="field-error">{{ $message }}</span>@enderror
                    <label class="field"><span class="label">Açıklama</span><input class="control" type="text" name="description" value="{{ old('description') }}" required maxlength="300" placeholder="Örn. Sanal Ofis Standart — Ekim 2026"></label>
                    <div class="grid g3">
                        <label class="field"><span class="label">Ara toplam (₺)</span><input class="control" type="number" name="subtotal" value="{{ old('subtotal', 0) }}" min="0" required @error('subtotal') aria-invalid="true" @enderror>@error('subtotal')<span class="field-error">{{ $message }}</span>@enderror</label>
                        <label class="field"><span class="label">KDV (%)</span><input class="control" type="number" name="tax_rate" value="{{ old('tax_rate', $taxRate) }}" min="0" max="100"></label>
                        <label class="field"><span class="label">Vade</span><input class="control" type="date" name="due_on" value="{{ old('due_on', now()->addDays($dueDays)->toDateString()) }}"></label>
                    </div>
                    <label class="field"><span class="label">Bağlı üyelik (isteğe bağlı)</span>
                        <select class="control" name="subscription_id">
                            <option value="">—</option>
                            @foreach ($subscriptions as $s)<option value="{{ $s->id }}" @selected((int) old('subscription_id') === $s->id)>{{ $s->plan->name }} · {{ $s->starts_on->format('d.m.Y') }}–{{ $s->ends_on->format('d.m.Y') }} · {{ $s->statusLabel() }}</option>@endforeach
                        </select>
                    </label>
                    <label class="field"><span class="label">Not (iç)</span><textarea class="control" name="note" maxlength="1000">{{ old('note') }}</textarea></label>
                    <label class="checkbox-row"><input type="checkbox" name="issue" value="1" @checked(old('issue', true))><span>Kaydedince yayınla (numara ver, tahsilata aç)</span></label>
                    <div><button type="submit" class="btn btn--brand">Kaydet</button> <a href="{{ route('panel.invoices.index') }}" class="btn btn--ghost">Vazgeç</a></div>
                </form>
            @else
                <p class="small muted" style="margin:0">Önce şirketi seçin.</p>
            @endif
        </div>
    </div>
@endsection
