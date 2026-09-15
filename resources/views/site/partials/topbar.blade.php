@php($brand = config('ofisvio.brand'))
<div class="topbar">
    <div class="wrap" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;padding-block:9px">
        <span>@isset($locations){{ $locations->count() }} lokasyon · @endisset Tek sözleşmeyle hepsine erişim</span>
        <span class="mono" style="display:flex;gap:18px;align-items:center;font-size:12px">
            <a href="{{ $brand['phone_href'] }}">{{ $brand['phone'] }}</a>
            <span style="opacity:.45">|</span>
            <a href="#teklif">Yerinde tur planla</a>
        </span>
    </div>
</div>
