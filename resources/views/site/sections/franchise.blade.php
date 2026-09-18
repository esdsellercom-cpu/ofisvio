{{-- Franchise / İş ortaklığı (faz 53): davet + öne çıkanlar + başvuru CTA'sı (/franchise). Ayarsız bölüm varsayılanı basar;
     ticari rakam yok. Editörde metinler satır içi düzenlenir. --}}
@php($d = \App\Site\SectionLibrary::FRANCHISE_DEFAULTS)
@php($cta = app(\App\Services\SiteBuilderService::class)->cta($s['cta'] ?? $d['cta'], $currentWebsite))
@php($points = collect((array) ($s['points'] ?? $d['points']))->map(fn ($p) => trim((string) $p))->filter(fn ($p) => $p !== '' || ofv_editor()))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section" data-franchise-section>
    <div class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));overflow:hidden;background:var(--brand-wash, #EAF3ED)">
        <div class="card__body" style="padding:36px 32px;gap:16px;justify-content:center">
            <p class="eyebrow"{!! ofv($s, 'eyebrow') !!}>{{ $s['eyebrow'] ?? $d['eyebrow'] }}</p>
            <h2 class="h2" style="max-width:22ch"{!! ofv($s, 'title') !!}>{{ $s['title'] ?? $d['title'] }}</h2>
            <p class="lede" style="margin:0;max-width:52ch;font-size:16.5px"{!! ofv($s, 'lede') !!}>{{ $s['lede'] ?? $d['lede'] }}</p>
            @if ($points->isNotEmpty())
                <ul class="stack" style="gap:8px;margin:6px 0 0;padding:0;list-style:none">
                    @foreach ($points as $i => $point)
                        <li style="display:flex;gap:10px;align-items:flex-start;font-size:15px"><span style="width:8px;height:8px;border-radius:99px;background:var(--brand);flex:none;margin-top:7px"></span><span{!! ofv_item('points', $i, 0) !!}>{{ $point }}</span></li>
                    @endforeach
                </ul>
            @endif
            @if ($cta)
                <p style="margin:10px 0 0;display:flex;gap:14px;flex-wrap:wrap;align-items:center">
                    <a href="{{ $cta['href'] }}" class="btn btn--brand btn--pill" @if ($cta['external']) target="_blank" rel="noopener" @endif>{{ $cta['label'] }}</a>
                    <span class="small muted">Başvurular ekibimizce değerlendirilir; başvuru bir taahhüt oluşturmaz.</span>
                </p>
            @endif
        </div>
        <div style="min-width:0;padding:24px;display:flex;align-items:center">
            @include('site.partials.illustration', ['key' => 'franchise', 'alt' => \App\Site\Illustrations::alt('franchise'), 'style' => 'width:100%;height:auto;aspect-ratio:4/3;object-fit:contain;border-radius:var(--r-md);display:block'])
        </div>
    </div>
</section>
