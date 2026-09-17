{{-- İçerik bloğu: CMS stüdyo gövdesi (BodyRenderer — bloklar, kısa kodlar, görsel öznitelikleri; ham HTML süzülür) --}}
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section--tight sec-inner">
    <div class="prose"{!! ofv_editor() ? ' data-ofv-md="body"' : '' !!}>{!! app(\App\Content\BodyRenderer::class)->render((string) ($s['body'] ?? '')) !!}</div>
</section>
