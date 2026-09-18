@if ($singleLocation)
{{-- TEK LOKASYON TASARIMI (faz 55): yayında + aktif tam bir şube varsa (SiteBlockService::singleLocation) liste/şehir seçici/boş kart
     yerine görsel ağırlıklı tek şube bloğu. İkinci şube yayına girince otomatik olarak aşağıdaki çoklu tasarıma döner.
     Tüm alanlar veritabanından (Location); boş alan basılmaz, uydurma bilgi yok. Alt başlık editörde düzenlenir (texts.locations_title). --}}
@php($loc = $singleLocation)
@php($locServices = $loc->services->where('is_active', true))
@php($locSummary = trim(strip_tags((string) (preg_split('~</p>~i', $loc->renderedDescription())[0] ?? ''))))
@php($locSummary = mb_strlen($locSummary) > 260 ? rtrim(mb_substr($locSummary, 0, 257)).'…' : $locSummary)
@php($directions = $loc->directionsUrl())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section" data-single-location-spotlight>
    <div class="section-head" style="margin-bottom:26px">
        <div style="min-width:0">
            <p class="eyebrow">03 — Lokasyon · {{ $loc->city }}</p>
            <h2 class="h2" style="max-width:26ch"{!! ofv($s, 'title', 'texts.locations_title') !!}>{{ $s['title'] ?? $texts['locations_title'] }}</h2>
        </div>
        @if ($loc->badge)<span class="mono" style="font-size:12px;letter-spacing:.06em;color:var(--ink-muted)">{{ $loc->badge }}</span>@endif
    </div>

    <article class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));overflow:hidden;border-radius:var(--r-lg)">
        <figure style="position:relative;margin:0;min-width:0;background:var(--surface-sunk)">
            @if ($loc->cover)
                @include('site.partials.picture', ['media' => $loc->cover, 'sizes' => '(max-width: 720px) 100vw, 56vw', 'style' => 'width:100%;height:100%;min-height:420px;aspect-ratio:5/4;object-fit:cover;display:block'])
            @else
                {{-- Medya yoksa marka illüstrasyonu; kapak Lokasyonlar › galeriden seçilince (cover_media_id) burası gerçek görsel olur. --}}
                @include('site.partials.illustration', ['key' => 'location', 'alt' => \App\Site\Illustrations::alt('location', $loc->city, $loc->name.' — '.($locServices->pluck('name')->take(3)->implode(', ') ?: 'ofis ve çalışma alanı')), 'style' => 'width:100%;height:100%;min-height:420px;aspect-ratio:5/4;object-fit:cover;display:block'])
                @if (ofv_editor())<span class="shot__note" style="position:absolute;left:16px;bottom:16px">Şube görseli: Lokasyonlar › {{ $loc->name }} › galeri kapağı</span>@endif
            @endif
            <span class="mono" style="position:absolute;top:16px;left:16px;font-size:11px;letter-spacing:.08em;color:#3C3A32;background:var(--surface);border-radius:6px;padding:6px 10px">{{ mb_strtoupper($loc->city) }}{{ $loc->district ? ' · '.$loc->district : '' }}</span>
        </figure>

        <div class="card__body" style="padding:clamp(24px,4vw,44px);gap:18px;justify-content:center">
            <div>
                <p class="label" style="margin-bottom:10px">{{ $loc->name }}</p>
                <h3 class="h1" style="font-size:clamp(40px,5vw,64px);line-height:.95;letter-spacing:-.03em;margin:0">{{ mb_strtoupper($loc->city) }}</h3>
            </div>

            {{-- Şehir tanıtım metni (faz 58b): editörde satır içi düzenlenir; boş bırakılırsa gizlenir. --}}
            @php($blurb = (string) ($s['blurb'] ?? ($texts['locations_blurb'] ?? '')))
            @if ($blurb !== '' || ofv_editor())
                <p style="margin:0;max-width:54ch;font-size:16.5px;line-height:1.6;color:var(--ink-soft)"{!! ofv($s, 'blurb', 'texts.locations_blurb') !!}>{{ $blurb }}</p>
            @endif

            @if ($locSummary !== '')
                <p class="body-muted" style="margin:0;max-width:52ch;font-size:16px">{{ $locSummary }}</p>
            @endif

            <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px 24px;margin:0;font-size:15px">
                @if ($loc->address_line)
                    <div><dt class="label" style="margin-bottom:4px">Adres</dt><dd style="margin:0"><span>{{ $loc->address_line }}</span><br><span class="muted">{{ trim(($loc->district ? $loc->district.', ' : '').$loc->city.($loc->postal_code ? ' '.$loc->postal_code : '')) }}</span></dd></div>
                @endif
                @if ($loc->transport)
                    <div><dt class="label" style="margin-bottom:4px">Ulaşım</dt><dd style="margin:0">{{ $loc->transport }}</dd></div>
                @endif
                @if (! empty($loc->opening_hours))
                    <div><dt class="label" style="margin-bottom:4px">Çalışma saatleri</dt><dd style="margin:0"><ul style="margin:0;padding:0;list-style:none;display:grid;gap:2px">@foreach ($loc->opening_hours as $hours)<li class="mono" style="font-size:13.5px">{{ $hours }}</li>@endforeach</ul></dd></div>
                @endif
                @if ($loc->phone)
                    <div><dt class="label" style="margin-bottom:4px">Telefon</dt><dd style="margin:0"><a href="tel:{{ preg_replace('/\s+/', '', $loc->phone) }}" class="mono" style="font-weight:600">{{ $loc->phone }}</a></dd></div>
                @endif
            </dl>

            @if ($locServices->isNotEmpty())
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                    @foreach ($locServices as $service)
                        <a href="{{ $service->path() }}" class="tag">{{ $service->name }}</a>
                    @endforeach
                </div>
            @endif

            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:4px">
                <a href="{{ route('site.location', $loc->slug) }}" class="btn btn--brand btn--pill">Lokasyonu İncele</a>
                @if ($directions)<a href="{{ $directions }}" target="_blank" rel="noopener" class="btn btn--ghost btn--pill" data-directions>Yol Tarifi Al ↗</a>@endif
                @if ($loc->price_from)<span class="mono" style="font-size:13px;color:var(--brand);margin-left:auto">{{ $loc->price_from }}</span>@endif
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
