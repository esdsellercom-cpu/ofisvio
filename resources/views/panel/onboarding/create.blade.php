@extends('layouts.panel')

@section('title', 'Yeni müşteri organizasyonu')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Personel</p>
            <h1 class="h2">Yeni müşteri organizasyonu</h1>
        </div>
    </div>

    <div class="panel" style="max-width:560px">
        <p class="body-muted" style="margin:0 0 20px">
            Organizasyon müşterinin çatısıdır; şirketler onun altında açılır. Sahip için hesap oluşturulur ve
            e-postasına şifre belirleme bağlantısı gönderilir. E-posta zaten kayıtlıysa mevcut hesap sahip yapılır.
        </p>

        <form method="POST" action="{{ route('panel.onboarding.store') }}" class="stack" style="gap:16px">
            @csrf

            <label class="field">
                <span class="label">Organizasyon adı</span>
                <input class="control" type="text" name="organization_name" value="{{ old('organization_name') }}"
                       required minlength="2" maxlength="190" autofocus placeholder="Örnek Holding"
                       @error('organization_name') aria-invalid="true" @enderror>
            </label>

            <div class="grid-auto" style="--min:200px;--gap:14px">
                <label class="field">
                    <span class="label">Sahip adı</span>
                    <input class="control" type="text" name="owner_name" value="{{ old('owner_name') }}"
                           required minlength="2" maxlength="120"
                           @error('owner_name') aria-invalid="true" @enderror>
                </label>
                <label class="field">
                    <span class="label">Sahip e-postası</span>
                    <input class="control" type="email" name="owner_email" value="{{ old('owner_email') }}"
                           required maxlength="190" inputmode="email"
                           @error('owner_email') aria-invalid="true" @enderror>
                </label>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
                <button type="submit" class="btn btn--brand">Organizasyonu aç ve davet gönder</button>
                <a href="{{ route('panel.context.select') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </form>
    </div>
@endsection
