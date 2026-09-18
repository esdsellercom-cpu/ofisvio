@extends('layouts.panel')

@section('title', $member ? 'Üye düzenle — '.$member->user->name : 'Yeni üye')

{{-- Üye formu (faz 51): kişi bilgileri (profil) + firma seçimi + ilk sözleşme; firma vergi/ünvan bilgisi şirket kaydında. --}}
@php($p = $member?->profile)
@php($names = $member ? [$p?->first_name ?? \Illuminate\Support\Str::beforeLast($member->user->name, ' '), $p?->last_name ?? \Illuminate\Support\Str::afterLast($member->user->name, ' ')] : ['', ''])
@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.members.index') }}">Üyeler</a>@if ($member) · <a href="{{ route('panel.members.show', $member) }}">{{ $member->user->name }}</a>@endif</p>
            <h1 class="h2">{{ $member ? 'Üye bilgilerini düzenle' : 'Yeni üye' }}</h1>
        </div>
    </div>

    <form method="POST" action="{{ $member ? route('panel.members.update', [$member->company_id, $member->id]) : route('panel.members.store', $selectedCompany) }}" enctype="multipart/form-data" class="stack" style="gap:16px" id="member-form">
        @csrf @if ($member) @method('PUT') @endif
        <div class="grid g-2-1" style="align-items:start">
            <div class="stack" style="gap:16px">
                <div class="panel stack" style="gap:12px">
                    <p class="eyebrow" style="margin:0">Kişi</p>
                    <div class="grid-auto" style="--min:200px;--gap:10px">
                        <label class="field"><span class="label">Ad</span><input class="control" type="text" name="first_name" value="{{ old('first_name', $names[0]) }}" required maxlength="80" @error('first_name') aria-invalid="true" @enderror></label>
                        <label class="field"><span class="label">Soyad</span><input class="control" type="text" name="last_name" value="{{ old('last_name', $names[1]) }}" required maxlength="80"></label>
                        <label class="field"><span class="label">E-posta</span>@if ($member)<input class="control" type="email" value="{{ $member->user->email }}" disabled><span class="small muted">Giriş e-postası hesaba aittir; değiştirme kullanıcı yönetiminden.</span>@else<input class="control" type="email" name="email" value="{{ old('email') }}" required maxlength="190" @error('email') aria-invalid="true" @enderror>@endif</label>
                        <label class="field"><span class="label">Telefon</span><input class="control" type="tel" name="phone" value="{{ old('phone', $p?->phone) }}" maxlength="40"></label>
                        <label class="field"><span class="label">Ünvan</span><input class="control" type="text" name="title" value="{{ old('title', $p?->title) }}" maxlength="80" placeholder="Genel Müdür, Muhasebe…"></label>
                        <label class="field"><span class="label">TC kimlik / vergi no</span><input class="control mono" type="text" name="identity_number" value="{{ old('identity_number', $p?->identity_number) }}" maxlength="20" pattern="[0-9]*" inputmode="numeric"></label>
                    </div>
                    @error('email')<p class="field-error">{{ $message }}</p>@enderror
                    @error('first_name')<p class="field-error">{{ $message }}</p>@enderror
                    <label class="field"><span class="label">Adres</span><input class="control" type="text" name="address" value="{{ old('address', $p?->address) }}" maxlength="300"></label>
                    <div class="grid-auto" style="--min:200px;--gap:10px">
                        <label class="field"><span class="label">Şehir</span><input class="control" type="text" name="city" value="{{ old('city', $p?->city) }}" maxlength="80"></label>
                        <label class="field"><span class="label">Ülke</span><input class="control" type="text" name="country" value="{{ old('country', $p?->country ?? 'Türkiye') }}" maxlength="80"></label>
                    </div>
                </div>

                <div class="panel stack" style="gap:12px">
                    <p class="eyebrow" style="margin:0">Firma &amp; üyelik</p>
                    <div class="grid-auto" style="--min:200px;--gap:10px">
                        <label class="field"><span class="label">Firma</span>
                            @if ($member)
                                <input class="control" type="text" value="{{ $member->company->legal_name }}" disabled>
                            @else
                                <select class="control" name="company_select" onchange="document.getElementById('member-form').action = this.options[this.selectedIndex].getAttribute('data-action')">
                                    @foreach ($companies as $c)<option value="{{ $c->id }}" data-action="{{ route('panel.members.store', $c) }}" @selected($c->id === $selectedCompany)>{{ $c->legal_name }}@if ($c->tax_number) · VKN {{ $c->tax_number }}@endif</option>@endforeach
                                </select>
                                <span class="small muted">Firma vergi no / ünvanı şirket kaydından gelir. Yeni firma: <a href="{{ route('panel.companies.index') }}">Şirketler</a>.</span>
                            @endif
                        </label>
                        @unless ($member)
                            <label class="field"><span class="label">Şirket rolü</span><select class="control" name="role">@foreach ($roles as $r)<option value="{{ $r }}" @selected(old('role', 'employee') === $r)>{{ __('roles.'.$r) }}</option>@endforeach</select></label>
                        @endunless
                        <label class="field"><span class="label">Üyelik tipi</span><select class="control" name="membership_type"><option value="">—</option>@foreach ($membershipTypes as $k => $l)<option value="{{ $k }}" @selected(old('membership_type', $p?->membership_type) === $k)>{{ $l }}</option>@endforeach</select></label>
                        <label class="field"><span class="label">Üyelik başlangıcı</span><input class="control" type="date" name="member_since" value="{{ old('member_since', $p?->member_since?->format('Y-m-d')) }}"></label>
                        <label class="field"><span class="label">Durum</span><select class="control" name="status"><option value="active" @selected(old('status', $member?->status ?? 'active') === 'active')>Aktif</option><option value="suspended" @selected(old('status', $member?->status) === 'suspended')>Pasif (askıda)</option></select></label>
                    </div>
                    @unless ($member)
                        <p class="eyebrow" style="margin:6px 0 0">İlk sözleşme (isteğe bağlı)</p>
                        <div class="grid-auto" style="--min:180px;--gap:10px">
                            <label class="field"><span class="label">Sözleşme türü</span><select class="control" name="contract_type">@foreach ($contractTypes as $k => $l)<option value="{{ $k }}" @selected(old('contract_type', 'uyelik') === $k)>{{ $l }}</option>@endforeach</select></label>
                            <label class="field"><span class="label">Sözleşme başlangıcı</span><input class="control" type="date" name="contract_starts_on" value="{{ old('contract_starts_on') }}"></label>
                            <label class="field"><span class="label">Sözleşme bitişi</span><input class="control" type="date" name="contract_ends_on" value="{{ old('contract_ends_on') }}"></label>
                        </div>
                        <span class="small muted">Başlangıç girilirse SOZ-… numaralı sözleşme açılır; dosya ve düzenleme profildeki Sözleşmeler sekmesinden.</span>
                    @endunless
                </div>
            </div>

            <div class="stack" style="gap:16px">
                <div class="panel stack" style="gap:10px">
                    <p class="eyebrow" style="margin:0">Profil görseli</p>
                    @if ($p?->avatar)<img src="{{ $p->avatar->urlFor(400) }}" alt="" style="width:120px;height:120px;border-radius:50%;object-fit:cover">@endif
                    <input class="control" type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                    <span class="small muted">JPG/PNG/WebP, 10 MB (otomatik küçültülür). Medya kütüphanesine güvenli yükleme zincirinden geçer.</span>
                </div>
                <div class="panel stack" style="gap:10px">
                    <p class="eyebrow" style="margin:0">Not</p>
                    <textarea class="control" name="note" rows="6" maxlength="2000">{{ old('note', $p?->note) }}</textarea>
                </div>
                @unless ($member)<div class="note small">Üye oluşturulunca kullanıcı hesabı açılır ve şifre belirleme bağlantısı e-postaya gider; tüm bilgiler tek profilden yönetilir.</div>@endunless
                <div style="display:flex;gap:8px;justify-content:flex-end"><a href="{{ $member ? route('panel.members.show', $member) : route('panel.members.index') }}" class="btn btn--ghost">Vazgeç</a><button type="submit" class="btn btn--brand">{{ $member ? 'Kaydet' : 'Üyeyi oluştur' }}</button></div>
            </div>
        </div>
    </form>
@endsection
