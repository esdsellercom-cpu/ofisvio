@extends('layouts.panel')

@section('title', 'Yeni şirket')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }}</p>
            <h1 class="h2">Yeni şirket</h1>
        </div>
    </div>

    <div class="panel" style="max-width:560px">
        <p class="body-muted" style="margin:0 0 20px">
            Şirket "Kayıt alındı" durumunda açılır. Ardından KYC belgelerini yükleyerek
            adres tahsis sürecini başlatırsınız.
        </p>

        <form method="POST" action="{{ route('panel.companies.store') }}" class="stack" style="gap:16px">
            @csrf

            <label class="field">
                <span class="label">Şirket unvanı</span>
                <input class="control" type="text" name="legal_name" value="{{ old('legal_name') }}"
                       required minlength="3" maxlength="190" autofocus
                       placeholder="Örnek Yazılım Ltd. Şti."
                       @error('legal_name') aria-invalid="true" @enderror>
            </label>

            <label class="field">
                <span class="label">Vergi numarası (VKN / TCKN)</span>
                <input class="control" type="text" name="tax_number" value="{{ old('tax_number') }}"
                       inputmode="numeric" pattern="[0-9]{10,11}" maxlength="11"
                       placeholder="10 ya da 11 hane"
                       @error('tax_number') aria-invalid="true" @enderror>
                <span class="small muted">Şimdi boş bırakabilirsiniz; vergi levhası yüklendiğinde doğrulanır.</span>
            </label>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
                <button type="submit" class="btn btn--brand">Şirketi aç</button>
                <a href="{{ route('panel.companies.index') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </form>
    </div>
@endsection
