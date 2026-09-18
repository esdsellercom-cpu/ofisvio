@if ($brand['announcement'] ?? null)
    {{-- Duyuru şeridi: site ayarı (metin/bağlantı/bitiş) — global bileşen --}}
    <div class="topbar" style="background:var(--brand);color:var(--surface)">
        <div class="wrap" style="padding-block:8px;font-size:13px;text-align:center">
            @if ($brand['announcement']['href'] !== '')<a href="{{ $brand['announcement']['href'] }}" style="color:inherit;font-weight:600">{{ $brand['announcement']['text'] }}</a>@else{{ $brand['announcement']['text'] }}@endif
        </div>
    </div>
@endif
<div class="topbar"{!! ofv_editor() ? ' data-ofv-global-area="topbar"' : '' !!}>
    <div class="wrap" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;padding-block:9px">
        {{-- Lokasyon sayısı metni: texts.topbar_count ({count} = yayındaki şube sayısı; boş = gizli). Sayı yalnız ana sayfada bilinir;
             diğer sayfalarda {count} içeren metin basılmaz. Telefon site ayarıdır; editörde satır içi düzenlenir (website.manage). --}}
        @php($countText = (string) ($texts['topbar_count'] ?? ''))
        @php($hasCount = isset($locations) && ! ($singleLocation ?? null))
        @php($showCount = $countText !== '' && ($hasCount || ! str_contains($countText, '{count}')))
        @php($countAttrs = ofv_global('texts.topbar_count').($hasCount && ofv_editor() ? ' data-ofv-count="'.$locations->count().'"' : '').($showCount ? '' : ' hidden'))
        <span>
            @if ($showCount || ofv_editor())
                <span{!! $countAttrs !!}>{{ $hasCount ? str_replace('{count}', (string) $locations->count(), $countText) : $countText }}</span>@if ($showCount) · @endif
            @endif
            <span{!! ofv_global('texts.topbar') !!}>{{ $texts['topbar'] }}</span>
        </span>
        <span class="mono" style="display:flex;gap:18px;align-items:center;font-size:12px">
            @if ($brand['phone'] || ofv_editor())<a href="{{ $brand['phone_href'] ?: '#' }}"{!! ofv_editor() ? ' data-ofv-site-field="contact_phone" title="Telefon (site ayarı)"' : '' !!}>{{ $brand['phone'] ?: (ofv_editor() ? 'Telefon ekle' : '') }}</a>
            <span style="opacity:.45">|</span>@endif
            @if ($brand['whatsapp_href'] ?? '')<a href="{{ $brand['whatsapp_href'] }}" target="_blank" rel="noopener">WhatsApp</a>
            <span style="opacity:.45">|</span>@endif
            <a href="#teklif"{!! ofv_global('texts.cta_topbar') !!}>{{ $texts['cta_topbar'] }}</a>
        </span>
    </div>
</div>
