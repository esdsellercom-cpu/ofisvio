@extends('layouts.site')

@section('content')
    {{-- Ana sayfa = yayınlanmış bölüm listesi (sayfa kurucu). Her bölüm kendi veri kaynağını okur;
         sıra, görünürlük, çapa, zamanlama, cihaz süzgeci ve tasarım (SectionStyle) panelden.
         Editör çerçevesi (faz 49, yalnız imzalı ?editor=1): bölüm id/tip/kilit işaretleri + şablonlar; canlıya gitmez. --}}
    @if (($preview ?? false) && ! ($editor ?? false))
        <div class="wrap" style="padding-top:16px"><div class="notice" role="status"><span class="notice__dot" aria-hidden="true"></span><div><strong>Önizleme</strong> — {{ ($revisionPreview ?? null) ? 'revizyon '.$revisionPreview.' görünümü' : 'yayınlanmamış taslak' }}; bu bağlantı {{ \App\Services\SiteBuilderService::PREVIEW_MINUTES }} dakika geçerlidir ve indekslenmez.</div></div></div>
    @endif
    <div id="site-sections"{!! ofv_editor() ? ' data-ofv-sections' : '' !!}>
    @foreach ($sections as $section)
        @include('site.section-wrapper', ['section' => $section])
    @endforeach
    </div>
    @if ($editor ?? false)
        @include('site.partials.editor-frame', ['templates' => $editorTemplates])
    @endif
@endsection
