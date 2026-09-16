@if (! empty($blocks['solutions']))
<section id="cozumler" class="wrap section">
    <div class="section-head">
        <div style="min-width:0">
            <p class="eyebrow">01 — Çözümler</p>
            <h2 class="h2" style="max-width:24ch">{{ $texts['solutions_title'] }}</h2>
        </div>
        <p class="body-muted" style="margin:0;max-width:34ch;font-size:16px">
            Hepsi aynı altyapıyı paylaşır: resepsiyon, fiber, evrak ve kargo karşılama, şubeler arası geçiş hakkı dahildir.
        </p>
    </div>

    <div class="grid-auto">
        @foreach ($blocks['solutions'] as $item)
            <a href="#teklif" class="card card--link" data-solution-pick="{{ $item['title'] }}">
                <div class="shot" style="aspect-ratio:4/3">
                    <span class="shot__note">{{ $item['key'] }} · 800×600</span>
                </div>
                <div class="card__body">
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px">
                        <h3 class="h3">{{ $item['title'] }}</h3>
                        @if ($item['flagship'])
                            {{-- Amiral ürün işareti: backend'in modellediği süreç (KYC → adres
                                 tahsisi) tam olarak bu ürün içindir. --}}
                            <span class="label" style="color:var(--brand);flex:none">amiral ürün</span>
                        @else
                            <span style="width:7px;height:7px;border-radius:99px;background:var(--brand);flex:none"></span>
                        @endif
                    </div>
                    <p class="body-muted" style="margin:0;flex:1">{{ $item['desc'] }}</p>
                    <div class="card__foot">
                        <span class="mono" style="font-size:13px;color:var(--brand)">{{ $item['price'] }}</span>
                        <span style="font-size:14px;font-weight:600">Teklif al →</span>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</section>
@endif
