<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <div class="section-head" style="margin-bottom:28px">
        <div style="min-width:0">
            <p class="eyebrow">03 — Lokasyonlar</p>
            <h2 class="h2">{{ $s['title'] ?? $texts['locations_title'] }}</h2>
        </div>
        <span class="mono" style="font-size:13px;color:var(--ink-muted)" data-match-line>
            {{ $locations->count() }} lokasyon · tüm bölgeler
        </span>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:26px" role="group" aria-label="Bölge filtresi">
        <button type="button" class="chip" data-region-tab="Tümü" aria-pressed="true">Tüm lokasyonlar</button>
        @foreach ($regions->keys() as $regionName)
            <button type="button" class="chip" data-region-tab="{{ $regionName }}" aria-pressed="false">{{ $regionName }}</button>
        @endforeach
    </div>

    <div class="grid-auto" data-locations>
        @foreach ($locations as $loc)
            <article class="card"
                     data-location
                     data-region="{{ $loc->region }}"
                     data-tags="{{ implode('|', $loc->serviceNames()) }}">
                @if ($loc->cover)
                    <div style="position:relative">
                        @include('site.partials.picture', ['media' => $loc->cover, 'sizes' => '(max-width: 640px) 100vw, 320px', 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block'])
                        <span class="mono" style="position:absolute;top:14px;left:14px;font-size:10.5px;letter-spacing:.08em;color:#3C3A32;background:var(--surface);border-radius:5px;padding:5px 8px">{{ $loc->badge }}</span>
                    </div>
                @else
                    <div class="shot" style="aspect-ratio:16/10;align-items:flex-start;justify-content:flex-start;padding:14px">
                        <span class="mono" style="font-size:10.5px;letter-spacing:.08em;color:#3C3A32;background:var(--surface);border-radius:5px;padding:5px 8px">{{ $loc->badge }}</span>
                    </div>
                @endif
                <div class="card__body" style="padding:20px 20px 22px;gap:8px">
                    <div class="label">{{ $loc->region }}</div>
                    <h3 class="h3"><a href="{{ route('site.location', $loc->slug) }}">{{ $loc->name }}</a></h3>
                    <p class="body-muted" style="margin:0;font-size:14.5px;flex:1">{{ $loc->address_line }}</p>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
                        @foreach ($loc->services->where('is_active', true) as $service)
                            <a href="{{ $service->path() }}" class="tag">{{ $service->name }}</a>
                        @endforeach
                    </div>
                    <div class="card__foot" style="margin-top:12px">
                        <span class="mono" style="font-size:13px;color:var(--brand)">{{ $loc->price_from }}</span>
                        <a href="{{ route('site.location', $loc->slug) }}" style="font-size:14px;font-weight:600">Şubeyi gör →</a>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <div class="empty-state" style="margin-top:20px" data-locations-empty hidden>
        Bu filtreye uygun lokasyon yok. Filtreyi genişletin ya da
        <a href="#teklif" style="color:var(--brand);font-weight:600">bize sorun</a>.
    </div>
</section>
