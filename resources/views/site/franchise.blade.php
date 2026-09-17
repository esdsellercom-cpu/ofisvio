@extends('layouts.site')

@section('title', 'Franchise başvurusu — '.config('ofisvio.brand.name'))
@section('description', 'Kendi şehrinizde Ofisvio şubesi açmak için franchise başvurusu yapın; ekibimiz sizinle iletişime geçer.')

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <div class="grid-auto" style="--min:300px;--gap:40px;align-items:start">
            <div>
                <p class="eyebrow">Franchise</p>
                <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">Kendi şehrinizde {{ config('ofisvio.brand.name') }} şubesi</h1>
                <p class="lede" style="margin-top:18px;max-width:56ch">Başvurunuzu bırakın; ekibimiz şehir, lokasyon ve yatırım planınızı sizinle birlikte değerlendirir. Formdaki bilgiler yalnız değerlendirme için kullanılır.</p>
            </div>

            <div class="panel" id="basvuru">
                <p class="eyebrow">Başvuru formu</p>
                @if (session('franchise_sent'))
                    <div class="notice"><span class="notice__dot"></span><div>Başvurunuz alındı. Ekibimiz en kısa sürede sizinle iletişime geçecek.</div></div>
                @else
                    @if ($errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot"></span><div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
                    <form method="POST" action="{{ route('site.franchise.store') }}" class="stack" style="gap:12px;margin-top:12px">
                        @csrf
                        <div class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                        <label class="field"><span class="label">Ad soyad</span><input class="control" type="text" name="name" value="{{ old('name') }}" required maxlength="120"></label>
                        <div class="grid-auto" style="--min:160px;--gap:12px">
                            <label class="field"><span class="label">E-posta</span><input class="control" type="email" name="email" value="{{ old('email') }}" required maxlength="190"></label>
                            <label class="field"><span class="label">Telefon</span><input class="control" type="tel" name="phone" value="{{ old('phone') }}" maxlength="32"></label>
                            <label class="field"><span class="label">Şehir</span><input class="control" type="text" name="city" value="{{ old('city') }}" required maxlength="80"></label>
                            <label class="field"><span class="label">İlçe</span><input class="control" type="text" name="district" value="{{ old('district') }}" maxlength="80"></label>
                        </div>
                        <label class="field"><span class="label">Planladığınız yatırım bütçesi</span><input class="control" type="text" name="budget" value="{{ old('budget') }}" maxlength="60" placeholder="Örn. 2–3 milyon ₺"></label>
                        <label class="field"><span class="label">Deneyiminiz</span><textarea class="control" name="experience" maxlength="2000" style="min-height:80px">{{ old('experience') }}</textarea></label>
                        <label class="field"><span class="label">Mesaj</span><textarea class="control" name="message" maxlength="2000" style="min-height:80px">{{ old('message') }}</textarea></label>
                        <label class="checkbox-row"><input type="checkbox" name="kvkk" value="1" required @checked(old('kvkk'))><span><a href="{{ $kvkkUrl }}" style="color:var(--brand);font-weight:600">KVKK</a> aydınlatma metnini okudum, iletişim kurulmasını onaylıyorum.</span></label>
                        <button type="submit" class="btn btn--brand btn--block">Başvuruyu gönder</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
