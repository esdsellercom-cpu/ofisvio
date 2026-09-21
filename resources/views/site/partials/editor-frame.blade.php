{{-- Görsel editör çerçevesi (faz 49): YALNIZ imzalı ?editor=1 önizlemede. Şablonlar: her eklenebilir tip/kayıtlı blok
     varsayılan ayarla gerçek bileşen olarak çizilir; editör bunları <template>'ten klonlar (sunucu çağrısı yok). --}}
<div hidden data-ofv-templates>
    @foreach ($templates as $tpl)
        <template data-ofv-template="{{ $tpl['preset'] ? 'preset:'.$tpl['preset'] : $tpl['type'] }}">
            @include('site.section-wrapper', ['section' => $tpl])
        </template>
    @endforeach
</div>
<script nonce="{{ csp_nonce() }}" src="{{ asset_v('js/site-editor-frame.js') }}" defer></script>
