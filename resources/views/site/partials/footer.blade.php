{{-- Global footer (faz 61a): yapılandırma $footer (SiteChromeService; panel: /panel/ayarlar/footer). Sütunlar boşsa
     footer_columns bloğu (görsel editör). Yasal bağlantılar seçili sayfalardan; seçim yoksa yayındaki sayfalar. --}}
@php($columns = $footer['columns'] !== [] ? $footer['columns'] : array_map(fn ($col) => ['title' => $col['title'], 'items' => \App\Services\SiteBlockService::normalizeFooterItems((array) $col['items'])], $blocks['footer_columns']))
@php($description = $footer['description'] !== '' ? $footer['description'] : $brand['tagline'])
<footer class="site-footer"{!! ofv_editor() ? ' data-ofv-global-area="footer"' : '' !!}{!! ofv_live() ? ' data-le-area="footer" data-le-area-label="Footer (tüm sitede)"' : '' !!}>
    <div class="wrap grid-auto" style="--min:190px;--gap:40px 32px;padding-block:64px 28px">
        <div style="min-width:0">
            <div style="display:flex;align-items:baseline;gap:9px;margin-bottom:18px">
                @if ($footer['logo'])
                    <img src="{{ $footer['logo']->urlFor(400) }}" alt="{{ $footer['logo']->alt ?: $brand['name'] }}" style="height:32px;width:auto;display:block" loading="lazy" decoding="async">
                @else
                    <span style="width:13px;height:13px;background:var(--brand-light);border-radius:3px;display:block" aria-hidden="true"></span>
                    <span class="brand__name" style="color:var(--dark-ink)">{{ $brand['name'] }}</span>
                @endif
            </div>
            @if ($description !== '')<p style="margin:0;font-size:14.5px;line-height:1.6;max-width:30ch"{!! ofv_editor() && $footer['description'] === '' ? ' data-ofv-brand="tagline"' : '' !!}>{{ $description }}</p>@endif
            <div class="stack mono" style="margin-top:22px;gap:6px;font-size:13.5px">
                @if ($footer['show_contact'] && $brand['phone'])<a href="{{ $brand['phone_href'] }}" style="color:var(--dark-ink)">{{ $brand['phone'] }}</a>@endif
                @if ($footer['show_contact'] && $brand['email'])<a href="mailto:{{ $brand['email'] }}" style="color:var(--dark-ink)">{{ $brand['email'] }}</a>@endif
                @if ($footer['show_address'] && $brand['address'])<span>{{ $brand['address'] }}</span>@endif
                @if ($footer['show_contact'] && $brand['whatsapp_href'])<a href="{{ $brand['whatsapp_href'] }}" target="_blank" rel="noopener" style="color:var(--dark-ink)">WhatsApp {{ $brand['whatsapp'] }}</a>@endif
                @if ($footer['show_hours'])@foreach ($brand['hours'] as $line)<span style="color:var(--dark-ink-soft)">{{ $line }}</span>@endforeach @endif
            </div>
            @if ($footer['social'] !== [])
                <div style="display:flex;gap:10px;margin-top:18px">
                    @foreach ($footer['social'] as $social)<a href="{{ $social['url'] }}" target="_blank" rel="noopener" class="site-footer__social">@include('site.partials.social-icon', ['network' => $social['network']])</a>@endforeach
                </div>
            @endif
            @if ($footer['cta']['label'] !== '' && $footer['cta']['href'] !== '')
                <a href="{{ $footer['cta']['href'] }}" class="btn btn--brand btn--pill" style="margin-top:20px">{{ $footer['cta']['label'] }}</a>
            @endif
        </div>

        @foreach ($columns as $col)@if ($loop->first && ofv_editor() && $footer['columns'] === [])<div hidden data-ofv-footer-columns></div>@endif
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">{{ $col['title'] }}</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    @foreach ($col['items'] as $item)
                        @if (($item['href'] ?? '') !== '')<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@else<span>{{ $item['label'] }}</span>@endif
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($footer['show_location'] && ($singleLocation ?? null))
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">Lokasyon</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    <a href="{{ route('site.location', $singleLocation->slug) }}">{{ $singleLocation->name }}</a>
                    @if ($singleLocation->address_line)<span style="color:var(--dark-ink-mute)">{{ $singleLocation->address_line }}</span>@endif
                    <span style="color:var(--dark-ink-mute)">{{ trim(($singleLocation->district ? $singleLocation->district.', ' : '').$singleLocation->city) }}</span>
                    @if ($singleLocation->phone)<a href="tel:{{ preg_replace('/\s+/', '', $singleLocation->phone) }}" class="mono">{{ $singleLocation->phone }}</a>@endif
                </div>
            </div>
        @elseif ($footer['show_location'] && isset($regions))
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">Bölgeler</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    @foreach ($regions->keys() as $regionName)<a href="#lokasyonlar">{{ $regionName }}</a>@endforeach
                </div>
            </div>
        @endif

        @if ($footer['newsletter']['enabled'])
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">{{ $footer['newsletter']['title'] !== '' ? $footer['newsletter']['title'] : 'Bülten' }}</div>
                @if ($footer['newsletter']['text'] !== '')<p style="margin:0 0 10px;font-size:14px;line-height:1.55">{{ $footer['newsletter']['text'] }}</p>@endif
                {{-- Bülten kaydı: lead (kind=newsletter) — KVKK rıza kanıtı capture'da. Bot tuzağı: website alanı. --}}
                <form method="POST" action="{{ route('site.newsletter') }}" class="stack" style="gap:8px">
                    @csrf
                    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
                    <input class="control" type="email" name="email" required maxlength="190" placeholder="e-posta adresiniz" aria-label="E-posta">
                    <label class="checkbox-row small" style="color:var(--dark-ink-soft)"><input type="checkbox" name="consent" value="1" required> <span>Bülten e-postası için <a href="{{ $kvkkUrl ?? '/' }}">aydınlatma metnini</a> okudum, onaylıyorum.</span></label>
                    <button type="submit" class="btn btn--brand btn--pill">Abone ol</button>
                    @if (session('newsletter_sent'))<span class="small" style="color:var(--brand-light)">Teşekkürler, kaydınız alındı.</span>@endif
                </form>
            </div>
        @endif
    </div>

    <div class="wrap" style="padding-block:22px 40px;border-top:1px solid var(--dark-line);display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;font-size:13px;color:var(--dark-ink-mute)">
        <span>{{ $footer['copyright'] !== '' ? $footer['copyright'] : '© '.date('Y').' '.$brand['legal_name'] }}@if ($footer['bottom_text'] !== '') · {{ $footer['bottom_text'] }}@endif{{ '' }}@if (developer_credit() !== '') · Geliştirme: {{ developer_credit() }}@endif</span>
        <span style="display:flex;gap:18px;flex-wrap:wrap">
            {{-- CMS: seçili yasal sayfalar ya da yayındaki sayfalar (SiteFooterComposer). Yayında sayfa yoksa bağlantı basılmaz. --}}
            @foreach ($legalPages ?? [] as $page)
                <a href="{{ $page->parent_slug ? url($page->path()) : route('site.page', $page->slug) }}" style="color:var(--dark-ink-mute)">{{ $page->title }}</a>
            @endforeach
        </span>
    </div>
</footer>
