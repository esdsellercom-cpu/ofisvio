<div class="topbar">
    <div class="wrap" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;padding-block:9px">
        <span>@isset($locations){{ $locations->count() }} lokasyon · @endisset{{ $texts['topbar'] }}</span>
        <span class="mono" style="display:flex;gap:18px;align-items:center;font-size:12px">
            @if ($brand['phone'])<a href="{{ $brand['phone_href'] }}">{{ $brand['phone'] }}</a>
            <span style="opacity:.45">|</span>@endif
            @if ($brand['whatsapp_href'] ?? '')<a href="{{ $brand['whatsapp_href'] }}" target="_blank" rel="noopener">WhatsApp</a>
            <span style="opacity:.45">|</span>@endif
            <a href="#teklif">{{ $texts['cta_topbar'] }}</a>
        </span>
    </div>
</div>
