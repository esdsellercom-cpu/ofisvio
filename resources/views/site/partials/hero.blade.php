<section class="wrap" style="padding-top:72px;display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:56px;align-items:end">
    <div style="min-width:0">
        <p class="eyebrow" style="margin-bottom:22px">{{ $texts['hero_eyebrow'] }}</p>
        <h1 class="h1">
            {{ $texts['hero_title'] }}<br>
            <span class="serif-accent">{{ $texts['hero_accent'] }}</span> {{ $texts['hero_title_after'] }}
        </h1>
        <p class="lede" style="margin:26px 0 0;max-width:50ch">{{ $texts['hero_lede'] }}</p>

        <div class="panel" style="margin-top:38px;border-radius:var(--r-lg);padding:20px">
            <div class="grid-auto" style="--min:150px;--gap:14px">
                <label class="field">
                    <span class="label">Şehir / Bölge</span>
                    <select class="control" data-filter-region>
                        <option value="Tümü">Tüm bölgeler</option>
                        @foreach ($regions->keys() as $regionName)
                            <option value="{{ $regionName }}">{{ $regionName }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="label">Çözüm</span>
                    <select class="control" data-filter-type>
                        <option value="Tümü">Hepsi</option>
                        {{-- Çözüm seçenekleri vitrin bloklarından (solutions) + gerçek oda varsa toplantı odası. --}}
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
            <p class="mono" style="margin:14px 0 0;font-size:13px;color:var(--ink-faint)" data-match-line>
                {{ $locations->count() }} lokasyon · tüm bölgeler
            </p>
        </div>
    </div>

    <div style="min-width:0">
        @if ($currentWebsite?->hero)
            <img src="{{ $currentWebsite->hero->url() }}" alt="{{ $currentWebsite->hero->alt ?? '' }}" style="width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:20px;border:1px solid var(--line);display:block">
        @else
            <div class="shot" style="aspect-ratio:4/5;border-radius:20px;border:1px solid var(--line);align-items:flex-end;padding:22px">
                <span class="shot__note">lokasyon ana görseli · 1200×1500</span>
            </div>
        @endif
    </div>
</section>
