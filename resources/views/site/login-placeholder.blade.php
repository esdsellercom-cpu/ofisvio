@extends('layouts.site')

@section('title', 'Giriş — '.config('ofisvio.brand.name'))

@section('content')
    <section class="wrap section" style="min-height:52vh">
        <div class="panel" style="max-width:46ch;margin-inline:auto;text-align:center;padding:40px 28px">
            <p class="eyebrow" style="margin-bottom:18px">Panel</p>
            <h1 class="h2" style="font-size:clamp(24px,3vw,32px)">Giriş henüz açık değil</h1>
            <p class="body-muted" style="margin:16px 0 0">
                Yetkilendirme altyapısı hazır; giriş ekranı ve organizasyon seçimi
                sıradaki adımda bağlanacak. Bu arada teklif formundan bize ulaşabilirsiniz.
            </p>
            <a href="{{ route('site.home') }}#teklif" class="btn btn--brand" style="margin-top:24px">Teklif al</a>
        </div>
    </section>
@endsection
