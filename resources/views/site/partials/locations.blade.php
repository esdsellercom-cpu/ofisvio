@if ($singleLocation)
{{-- Tek lokasyon modu (faz 53): "Lokasyonlarımız" listesi / bölge sekmesi / boş kart yok; şube tüm verisiyle öne çıkar.
     Bütün alanlar veritabanından (Location); boş alan basılmaz — uydurma bilgi yok. --}}
@php($loc = $singleLocation)
@php($locServices = $loc->services->where('is_active', true))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section" data-single-location-spotlight>
    <div class="section-head" style="margin-bottom:28px">
        <div style="min-width:0">
            <p class="eyebrow">03 — Lokasyon</p>
            <h2 class="h2"{!! ofv($s, 'title', 'texts.locations_title') !!}>{{ $s['title'] ?? $texts['locations_title'] }}</h2>
        </div>
        <span class="mono" style="font-size:13px;color:var(--ink-muted)">{{ $loc->city }}{{ $loc->district ? ' · '.$loc->district : '' }}</span>
    </div>

    <article class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));overflow:hidden">
        <div style="position:relative;min-width:0">
            @if ($loc->cover)
                @include('site.partials.picture', ['media' => $loc->cover, 'sizes' => '(max-width: 640px) 100vw, 50vw', 'style' => 'width:100%;height:100%;min-height:280px;aspect-ratio:16/11;object-fit:cover;display:block'])
            @else
                @include('site.partials.illustration', ['key' => 'location', 'alt' => \App\Site\Illustrations::alt('location', $loc->city, $loc->name.' ofis binası'), 'style' => 'width:100%;height:100%;min-height:280px;aspect-ratio:16/11;object-fit:cover;display:block'])
            @endif
            @if ($loc->badge)<span class="mono" style="position:absolute;top:14px;left:14px;font-size:10.5px;letter-spacing:.08em;color:#3C3A32;background:var(--surface);border-radius:5px;padding:5px 8px">{{ $loc->badge }}</span>@endif
        </div>
        <div class="card__body" style="padding:28px;gap:14px">
            <div class="label">{{ $loc->region ?: $loc->city }}</div>
            <h3 class="h2" style="font-size:clamp(24px,2.6vw,32px)"><a href="{{ route('site.location', $loc->slug) }}">{{ $loc->name }}</a></h3>
            <dl class="stack" style="gap:10px;margin:0;font-size:15px">
                @if ($loc->address_line)
                    <div><dt class="label" style="margin-bottom:2px">Adres</dt><dd style="margin:0">{{ $loc->address_line }}<br><span class="muted">{{ trim(($loc->district ? $loc->district.', ' : '').$loc->city.($loc->postal_code ? ' '.$loc->postal_code : '')) }}</span></dd></div>
                @endif
                @if ($loc->phone)
                    <div><dt class="label" style="margin-bottom:2px">Telefon</dt><dd style="margin:0"><a href="tel:{{ preg_replace('/\s+/', '', $loc->phone) }}" class="mono">{{ $loc->phone }}</a></dd></div>
                @endif
                @if (! empty($loc->opening_hours))
                    <div><dt class="label" style="margin-bottom:2px">Çalışma saatleri</dt><dd style="margin:0"><ul style="margin:0;padding:0;list-style:none;display:grid;gap:2px">@foreach ($loc->opening_hours as $hours)<li class="mono" style="font-size:13.5px">{{ $hours }}</li>@endforeach</ul></dd></div>
                @endif
            </dl>
            @if ($locServices->isNotEmpty())
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
                    @foreach ($locServices as $service)
                        <a href="{{ $service->path() }}" class="tag">{{ $service->name }}</a>
                    @endforeach
                </div>
            @endif
            <div class="card__foot" style="margin-top:8px;flex-wrap:wrap;gap:12px">
                <span class="mono" style="font-size:13px;color:var(--brand)">{{ $loc->price_from }}</span>
                <span style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
                    @if ($loc->hasCoordinates())<a href="https://www.google.com/maps?q={{ $loc->latitude }},{{ $loc->longitude }}" target="_blank" rel="noopener" style="font-size:14px;font-weight:600">Yol tarifi ↗</a>@endif
                    <a href="{{ route('site.location', $loc->slug) }}" style="font-size:14px;font-weight:600">Şubeyi gör →</a>
                    <a href="#teklif" class="btn btn--brand" style="padding:8px 14px;font-size:14px">Teklif al</a>
                </span>
            </div>
        </div>
    </article>
</section>
@else
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <div class="section-head" style="margin-bottom:28px">
        <div style="min-width:0">
            <p class="eyebrow">03 — Lokasyonlar</p>
            <h2 class="h2"{!! ofv($s, 'title', 'texts.locations_title') !!}>{{ $s['title'] ?? $texts['locations_title'] }}</h2>
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
                    <div style="position:relative">
                        @include('site.partials.illustration', ['key' => 'location', 'alt' => \App\Site\Illustrations::alt('location', $loc->city, $loc->name.' ofis binası'), 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block'])
                        @if ($loc->badge)<span class="mono" style="position:absolute;top:14px;left:14px;font-size:10.5px;letter-spacing:.08em;color:#3C3A32;background:var(--surface);border-radius:5px;padding:5px 8px">{{ $loc->badge }}</span>@endif
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
@endif
