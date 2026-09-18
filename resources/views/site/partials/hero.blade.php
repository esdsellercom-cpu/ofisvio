<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap" style="padding-top:72px;display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:56px;align-items:end">
    <div style="min-width:0">
        <p class="eyebrow" style="margin-bottom:22px"{!! ofv($s, 'eyebrow', 'texts.hero_eyebrow') !!}>{{ $s['eyebrow'] ?? $texts['hero_eyebrow'] }}</p>
        <h1 class="h1"{!! empty($s['title']) ? '' : ofv($s, 'title') !!}>
            @if (! empty($s['title']))
                {{ $s['title'] }}
            @else
                @if (ofv_editor())<span data-ofv-global="texts.hero_title">{{ $texts['hero_title'] }}</span>@else{{ $texts['hero_title'] }}@endif<br>
                <span class="serif-accent"{!! ofv_global('texts.hero_accent') !!}>{{ $texts['hero_accent'] }}</span> @if (ofv_editor())<span data-ofv-global="texts.hero_title_after">{{ $texts['hero_title_after'] }}</span>@else{{ $texts['hero_title_after'] }}@endif
            @endif
        </h1>
        <p class="lede" style="margin:26px 0 0;max-width:50ch"{!! ofv($s, 'lede', 'texts.hero_lede') !!}>{{ $s['lede'] ?? $texts['hero_lede'] }}</p>
        @if ($heroCta)<p style="margin:22px 0 0"><a href="{{ $heroCta['href'] }}" class="btn btn--brand" @if ($heroCta['external']) target="_blank" rel="noopener" @endif>{{ $heroCta['label'] }}</a></p>@endif

        <div class="panel" style="margin-top:38px;border-radius:var(--r-lg);padding:20px">
            <div class="grid-auto" style="--min:150px;--gap:14px">
                @if ($singleLocation)
                    {{-- Tek lokasyon (faz 53): şehir seçimi yok; şube bilgisi veritabanından. --}}
                    <div class="field">
                        <span class="label">Lokasyon</span>
                        <a href="{{ route('site.location', $singleLocation->slug) }}" class="control" style="display:flex;align-items:center;gap:8px;text-decoration:none" data-single-location>
                            <span style="width:8px;height:8px;border-radius:99px;background:var(--brand);flex:none"></span>
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $singleLocation->city }}{{ $singleLocation->district ? ' · '.$singleLocation->district : '' }}</span>
                        </a>
                    </div>
                @else
                <label class="field">
                    <span class="label">Şehir / Bölge</span>
                    <select class="control" data-filter-region>
                        <option value="Tümü">Tüm bölgeler</option>
                        @foreach ($regions->keys() as $regionName)
                            <option value="{{ $regionName }}">{{ $regionName }}</option>
                        @endforeach
                    </select>
                </label>
                @endif
                <label class="field">
                    <span class="label">Çözüm</span>
                    <select class="control" data-filter-type>
                        <option value="Tümü">Hepsi</option>
                        {{-- Çözüm seçenekleri Hizmetler modülünden (aktif hizmet adları). --}}
                        @foreach ($leadOptions as $opt)
                            <option value="{{ $opt }}">{{ $opt }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="label">Kişi</span>
                    <select class="control" data-filter-team>
                        @foreach (config('ofisvio.team_sizes') as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <a href="#lokasyonlar" class="btn btn--ink" style="margin-top:22px" data-filter-apply>{{ $texts['cta_hero'] }}</a>
            </div>
            <p class="mono" style="margin:14px 0 0;font-size:13px;color:var(--ink-faint)"@if (! $singleLocation) data-match-line @endif>
                @if ($singleLocation){{ $singleLocation->name }}{{ $singleLocation->address_line ? ' · '.$singleLocation->address_line : '' }}@else{{ $locations->count() }} lokasyon · tüm bölgeler @endif
            </p>
        </div>
    </div>

    <div style="min-width:0">
        @if ($currentWebsite?->hero)
            <img src="{{ $currentWebsite->hero->url() }}" alt="{{ $currentWebsite->hero->alt ?? '' }}" style="width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:20px;border:1px solid var(--line);display:block"{!! ofv_editor() ? ' data-ofv-site-image="hero"' : '' !!}>
        @elseif ($singleLocation?->cover)
            {{-- Tek lokasyon: site görseli yoksa şubenin kapağı hero görselidir. --}}
            @include('site.partials.picture', ['media' => $singleLocation->cover, 'sizes' => '(max-width: 640px) 100vw, 560px', 'eager' => true, 'style' => 'width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:20px;border:1px solid var(--line);display:block'])
        @else
            {{-- Medya yoksa marka illüstrasyonu (faz 53); editörde tıklanınca site görseli yüklenir. --}}
            <div style="position:relative">
                @include('site.partials.illustration', ['key' => 'hero', 'eager' => true, 'alt' => \App\Site\Illustrations::alt('hero', $singleLocation->city ?? null), 'style' => 'width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:20px;border:1px solid var(--line);display:block'])
                @if (ofv_editor())<span class="shot__note" style="position:absolute;left:22px;bottom:22px" data-ofv-site-image="hero">site görseli yükle · 1200×1500</span>@endif
            </div>
        @endif
    </div>
</section>
