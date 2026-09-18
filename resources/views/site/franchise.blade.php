@extends('layouts.site')

{{-- Franchise / İş ortaklığı başvurusu (faz 39e → faz 53): head verisi SiteLayoutComposer (site.franchise). Sayfa JSON-LD'si
     (WebPage + BreadcrumbList + FAQPage) aşağıda; lokasyon/şehir uydurulmaz — yalnız süreç bilgisi. --}}
@php($brandName = $currentWebsite->name ?? config('ofisvio.brand.name'))
@php($faq = [
    ['q' => 'Franchise başvurusu nasıl değerlendirilir?', 'a' => 'Başvurunuz ekibimize başvuru numarasıyla düşer; şehir, hedef lokasyon, yatırım bütçesi ve işletme deneyiminiz incelenir, ardından sizinle bir ön görüşme planlanır.'],
    ['q' => 'Başvuru bir taahhüt oluşturur mu?', 'a' => 'Hayır. Başvuru yalnız değerlendirme ve ön görüşme içindir; sözleşme koşulları görüşmelerde birlikte belirlenir.'],
    ['q' => 'Hangi bilgileri paylaşmam gerekir?', 'a' => 'Ad, soyad, telefon, e-posta, şehir/ilçe, planladığınız yatırım bütçesi aralığı ve varsa işletme deneyiminiz yeterlidir; firma adı ve mesaj isteğe bağlıdır.'],
    ['q' => 'Bilgilerim nasıl kullanılır?', 'a' => 'Formdaki bilgiler yalnız franchise değerlendirmesi için saklanır ve KVKK aydınlatma metnine uygun işlenir; pazarlama listesine eklenmezsiniz.'],
])
@php($base = rtrim($currentWebsite?->baseUrl() ?? url('/'), '/'))

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <nav aria-label="Breadcrumb" class="small muted" style="margin-bottom:18px"><a href="/">Ana sayfa</a> <span aria-hidden="true">›</span> <span aria-current="page">Franchise</span></nav>
        <div class="grid-auto" style="--min:300px;--gap:40px;align-items:start">
            <div>
                <p class="eyebrow">Franchise / İş ortaklığı</p>
                <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">Markamızı birlikte büyütmek ister misiniz?</h1>
                <p class="lede" style="margin-top:18px;max-width:56ch">Kendi şehrinizde {{ $brandName }} iş ortağı olarak sanal ofis, hazır ofis ve coworking merkezi açmak için başvurunuzu bırakın. Ekibimiz şehir, lokasyon ve yatırım planınızı sizinle birlikte değerlendirir; formdaki bilgiler yalnız değerlendirme için kullanılır.</p>
                <div style="margin-top:28px">
                    @include('site.partials.illustration', ['key' => 'franchise', 'eager' => true, 'alt' => \App\Site\Illustrations::alt('franchise'), 'style' => 'width:100%;max-width:520px;height:auto;aspect-ratio:4/3;object-fit:contain;border-radius:var(--r-lg);border:1px solid var(--line);display:block'])
                </div>
                <h2 class="h3" style="margin-top:32px">Başvuru süreci</h2>
                <ol class="stack" style="gap:8px;margin:12px 0 0;padding-left:20px;font-size:15px">
                    <li>Formu doldurun; başvurunuz numaralandırılarak ekibimize düşer.</li>
                    <li>Bilgileriniz incelenir, sizinle ön görüşme planlanır.</li>
                    <li>Görüşme sonucuna göre birlikte yol haritası çıkarılır.</li>
                </ol>
            </div>

            <div class="panel" id="basvuru">
                <p class="eyebrow">Franchise başvuru formu</p>
                @if (session('franchise_sent'))
                    <div class="notice" data-franchise-sent><span class="notice__dot"></span><div>Başvurunuz alındı. Ekibimiz en kısa sürede sizinle iletişime geçecek.</div></div>
                @else
                    @if ($errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot"></span><div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
                    <form method="POST" action="{{ route('site.franchise.store') }}" class="stack" style="gap:12px;margin-top:12px" data-franchise-form>
                        @csrf
                        <div class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                        <div class="grid-auto" style="--min:160px;--gap:12px">
                            <label class="field"><span class="label">Ad</span><input class="control" type="text" name="first_name" value="{{ old('first_name') }}" required maxlength="80" autocomplete="given-name"></label>
                            <label class="field"><span class="label">Soyad</span><input class="control" type="text" name="last_name" value="{{ old('last_name') }}" required maxlength="80" autocomplete="family-name"></label>
                        </div>
                        <label class="field"><span class="label">Firma (isteğe bağlı)</span><input class="control" type="text" name="company" value="{{ old('company') }}" maxlength="120" autocomplete="organization"></label>
                        <div class="grid-auto" style="--min:160px;--gap:12px">
                            <label class="field"><span class="label">Telefon</span><input class="control" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="32" autocomplete="tel"></label>
                            <label class="field"><span class="label">E-posta</span><input class="control" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email"></label>
                            <label class="field"><span class="label">Şehir</span><input class="control" type="text" name="city" value="{{ old('city') }}" required maxlength="80" autocomplete="address-level1"></label>
                            <label class="field"><span class="label">İlçe</span><input class="control" type="text" name="district" value="{{ old('district') }}" maxlength="80" autocomplete="address-level2"></label>
                        </div>
                        <label class="field"><span class="label">Planladığınız yatırım bütçesi</span>
                            <select class="control" name="budget">
                                <option value="">Seçin</option>
                                @foreach (\App\Models\FranchiseApplication::BUDGETS as $key => $label)
                                    <option value="{{ $key }}" @selected(old('budget') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field"><span class="label">İşletme deneyiminiz</span><textarea class="control" name="experience" maxlength="2000" style="min-height:80px" placeholder="Sektör, süre, yönettiğiniz işletme/ekip…">{{ old('experience') }}</textarea></label>
                        <label class="field"><span class="label">Mesaj</span><textarea class="control" name="message" maxlength="2000" style="min-height:80px">{{ old('message') }}</textarea></label>
                        <label class="checkbox-row"><input type="checkbox" name="kvkk" value="1" required @checked(old('kvkk'))><span><a href="{{ $kvkkUrl }}" style="color:var(--brand);font-weight:600">KVKK</a> aydınlatma metnini okudum, iletişim kurulmasını onaylıyorum.</span></label>
                        <button type="submit" class="btn btn--brand btn--block">Başvuruyu gönder</button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    <section class="wrap section" style="padding-top:0">
        <h2 class="h2" style="max-width:24ch;margin-bottom:24px">Sık sorulanlar</h2>
        <div class="stack" style="gap:10px;max-width:76ch">
            @foreach ($faq as $item)
                <details class="card" style="padding:16px 18px;display:block">
                    <summary style="cursor:pointer;font-weight:600">{{ $item['q'] }}</summary>
                    <p class="body-muted" style="margin:10px 0 0">{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </section>

    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'WebPage', '@id' => $base.'/franchise#webpage', 'url' => $base.'/franchise', 'name' => 'Franchise ve iş ortaklığı başvurusu', 'description' => 'Markamızı birlikte büyütmek ister misiniz? Franchise / iş ortaklığı başvurunuzu bırakın.', 'inLanguage' => 'tr-TR', 'isPartOf' => ['@type' => 'WebSite', 'name' => $brandName, 'url' => $base.'/']],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana sayfa', 'item' => $base.'/'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'Franchise', 'item' => $base.'/franchise']]],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $p) => ['@type' => 'Question', 'name' => $p['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p['a']]], $faq)],
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endsection
