@extends('layouts.panel')

@section('title', 'Blok kütüphanesi')

{{-- Blok kütüphanesi (faz 50): düzenleme YOK — hazır bileşenler + kayıtlı şablonlar; kategori, global/normal, kullanım,
     önizleme, kopyalama, ad/kategori, silme, "Tasarımda düzenle" → görsel editör. Metinler ve veri listeleri editörde. --}}
@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.content.index') }}">İçerik</a> · {{ $website->name }}</p>
            <h1 class="h2">Blok kütüphanesi</h1>
            <p class="small muted" style="margin:6px 0 0">Hazır bileşenler ve kayıtlı şablonlar. Düzenleme sayfa üzerinde yapılır: <a href="{{ route('panel.content.builder.index', ['website' => $website->id]) }}">Ana sayfa tasarımı</a>@if ($hasChanges) <span class="badge badge--warn">yayınlanmamış değişiklik</span>@endif</p>
        </div>
        <div class="panel-head__actions">
            <form method="GET" class="inline-form"><select class="control" name="kategori" data-autosubmit><option value="">Tüm kategoriler</option>@foreach ($categories as $k => $l)<option value="{{ $k }}" @selected($category === $k)>{{ $l }}</option>@endforeach</select></form>
            <a href="{{ route('panel.content.builder.index', ['website' => $website->id]) }}" class="btn btn--ghost">Tasarım editörü</a>
            @can('content.edit')<button type="button" class="btn btn--brand" data-modal-open="#modal-preset-new">+ Yeni blok şablonu</button>@endcan
        </div>
    </div>

    @error('builder')<div class="notice notice--error" role="alert" style="margin-bottom:16px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <div class="note small" style="margin-bottom:18px"><b>🌐 Global blok</b> bağlı olduğu her yerde aynıdır; editörde düzenlenince tüm kullanımlar (yayın dahil) güncellenir. <b>Normal blok</b> sayfaya kopyalanır, yalnız o sayfayı etkiler. Header ve footer zaten global alanlardır (editörde tıklayın).</div>

    @include('panel.content._block-catalog', ['mode' => 'library'])

    @can('content.edit')
    <dialog class="modal" id="modal-preset-new">
        <form method="POST" action="{{ route('panel.content.blocks.preset.store') }}" data-modal-form>@csrf
            <div class="modal__head"><h2>Yeni blok şablonu</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" required maxlength="80"></label>
                <label class="field"><span class="label">Bileşen</span><select class="control" name="type">@foreach ($library as $type => $def)<option value="{{ $type }}">{{ $def['label'] }} — {{ $def['description'] }}</option>@endforeach</select></label>
                <label class="field"><span class="label">Kategori</span><select class="control" name="category">@foreach ($categories as $k => $l)<option value="{{ $k }}" @selected($k === 'ozel')>{{ $l }}</option>@endforeach</select></label>
                <label class="checkbox-row"><input type="checkbox" name="is_global" value="1"><span><b>Global blok</b> — bağlı tüm kullanımlar birlikte güncellenir</span></label>
                <p class="small muted" style="margin:0">Şablon tipin varsayılan içeriğiyle oluşturulur ve editörde sayfaya eklenir; orada düzenleyip kaydedin.</p>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Oluştur ve editörde aç</button></div>
        </form>
    </dialog>
    <dialog class="modal" id="modal-preset-edit">
        <form method="POST" data-modal-form>@csrf @method('PUT')
            <div class="modal__head"><h2 data-modal-title data-default="Bloğu düzenle">Bloğu düzenle</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" required maxlength="80"></label>
                <label class="field"><span class="label">Kategori</span><select class="control" name="category">@foreach ($categories as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="checkbox-row"><input type="checkbox" name="is_global" value="1"><span><b>Global blok</b></span></label>
                <p class="small muted" style="margin:0">İçerik/tasarım değişikliği: <b>Tasarımda düzenle</b>.</p>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Kaydet</button></div>
        </form>
    </dialog>
    @endcan
@endsection
