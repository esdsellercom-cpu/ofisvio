{{-- Çözümler: HİZMET kayıtlarından (faz 4). Aktif hizmet yoksa bölüm basılmaz. Kart → hizmet sayfası. --}}
@if ($services->isNotEmpty())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <div class="section-head">
        <div style="min-width:0">
            <p class="eyebrow">01 — Çözümler</p>
            <h2 class="h2" style="max-width:24ch"{!! ofv($s, 'title', 'texts.solutions_title') !!}>{{ $s['title'] ?? $texts['solutions_title'] }}</h2>
        </div>
        @if (($s['lede'] ?? $texts['solutions_lede']) !== '')
            <p class="body-muted" style="margin:0;max-width:34ch;font-size:16px"{!! ofv($s, 'lede', 'texts.solutions_lede') !!}>{{ $s['lede'] ?? $texts['solutions_lede'] }}</p>
        @endif
    </div>

    <div class="grid-auto sec-grid">
        @foreach ($services as $service)
            <a href="{{ $service->path() }}" class="card card--link" data-solution-pick="{{ $service->name }}">
                @if ($service->cover)
                    @include('site.partials.picture', ['media' => $service->cover, 'sizes' => '(max-width: 640px) 100vw, 320px', 'style' => 'width:100%;aspect-ratio:4/3;object-fit:cover;display:block'])
                @else
                    @php($illKey = \App\Site\Illustrations::forService($service->slug, $service->name))
                    @include('site.partials.illustration', ['key' => $illKey, 'alt' => \App\Site\Illustrations::alt($illKey, $singleLocation->city ?? null, $service->name), 'style' => 'width:100%;aspect-ratio:4/3;object-fit:cover;display:block'])
                @endif
                <div class="card__body">
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px">
                        <h3 class="h3">{{ $service->name }}</h3>
                        @if ($service->is_flagship)
                            <span class="label" style="color:var(--brand);flex:none">amiral ürün</span>
                        @else
                            <span style="width:7px;height:7px;border-radius:99px;background:var(--brand);flex:none"></span>
                        @endif
                    </div>
                    <p class="body-muted" style="margin:0;flex:1">{{ $service->summary }}</p>
                    <div class="card__foot">
                        <span class="mono" style="font-size:13px;color:var(--brand)">{{ $service->price_text ?? ($service->booking_kind ? 'saatlik · rezervasyon' : '') }}</span>
                        <span style="font-size:14px;font-weight:600">{{ $texts['cta_solution'] }}</span>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</section>
@endif