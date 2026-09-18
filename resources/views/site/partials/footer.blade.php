<footer class="site-footer"{!! ofv_editor() ? ' data-ofv-global-area="footer"' : '' !!}>
    <div class="wrap grid-auto" style="--min:190px;--gap:40px 32px;padding-block:64px 28px">
        <div style="min-width:0">
            <div style="display:flex;align-items:baseline;gap:9px;margin-bottom:18px">
                <span style="width:13px;height:13px;background:var(--brand-light);border-radius:3px;display:block" aria-hidden="true"></span>
                <span class="brand__name" style="color:var(--dark-ink)">{{ $brand['name'] }}</span>
            </div>
            <p style="margin:0;font-size:14.5px;line-height:1.6;max-width:30ch"{!! ofv_editor() ? ' data-ofv-brand="tagline"' : '' !!}>{{ $brand['tagline'] }}</p>
            <div class="stack mono" style="margin-top:22px;gap:6px;font-size:13.5px">
                @if ($brand['phone'])<a href="{{ $brand['phone_href'] }}" style="color:var(--dark-ink)">{{ $brand['phone'] }}</a>@endif
                @if ($brand['email'])<a href="mailto:{{ $brand['email'] }}" style="color:var(--dark-ink)">{{ $brand['email'] }}</a>@endif
                @if ($brand['address'])<span>{{ $brand['address'] }}</span>@endif
                @if ($brand['whatsapp_href'])<a href="{{ $brand['whatsapp_href'] }}" target="_blank" rel="noopener" style="color:var(--dark-ink)">WhatsApp {{ $brand['whatsapp'] }}</a>@endif
                @foreach ($brand['hours'] as $line)<span style="color:var(--dark-ink-soft)">{{ $line }}</span>@endforeach
            </div>
        </div>

        @foreach ($blocks['footer_columns'] as $col)@if ($loop->first && ofv_editor())<div hidden data-ofv-footer-columns></div>@endif
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">{{ $col['title'] }}</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    @foreach (\App\Services\SiteBlockService::normalizeFooterItems((array) $col['items']) as $item)
                        @if ($item['href'] !== '')<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@else<span>{{ $item['label'] }}</span>@endif
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($singleLocation ?? null)
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">Lokasyon</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    <a href="{{ route('site.location', $singleLocation->slug) }}">{{ $singleLocation->name }}</a>
                    @if ($singleLocation->address_line)<span style="color:var(--dark-ink-mute)">{{ $singleLocation->address_line }}</span>@endif
                    <span style="color:var(--dark-ink-mute)">{{ trim(($singleLocation->district ? $singleLocation->district.', ' : '').$singleLocation->city) }}</span>
                    @if ($singleLocation->phone)<a href="tel:{{ preg_replace('/\s+/', '', $singleLocation->phone) }}" class="mono">{{ $singleLocation->phone }}</a>@endif
                </div>
            </div>
        @elseif (isset($regions))
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">Bölgeler</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    @foreach ($regions->keys() as $regionName)
                        <a href="#lokasyonlar">{{ $regionName }}</a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="wrap" style="padding-block:22px 40px;border-top:1px solid var(--dark-line);display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;font-size:13px;color:var(--dark-ink-mute)">
        <span>© {{ date('Y') }} {{ $brand['legal_name'] }}</span>
        <span style="display:flex;gap:18px;flex-wrap:wrap">
            {{-- CMS: yayındaki sayfalar (SiteFooterComposer). Yayında sayfa yoksa bağlantı basılmaz. --}}
            @foreach ($legalPages ?? [] as $page)
                <a href="{{ route('site.page', $page->slug) }}" style="color:var(--dark-ink-mute)">{{ $page->title }}</a>
            @endforeach
        </span>
    </div>
</footer>
