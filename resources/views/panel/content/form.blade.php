@extends('layouts.panel')

@section('title', $content ? 'Düzenle — '.$content->title : 'Yeni '.$kind->label())

{{-- $draft (faz 18): yayındaki içeriğin çalışma taslağı; alanlar taslaktan, form draft.update'e gider. --}}
@php($draft = $draft ?? null)
@php($src = $draft ?? $content)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.content.index', ['website' => $website->id]) }}">İçerik</a> · {{ $website->name }} · {{ $kind->label() }}</p>
            <h1 class="h2">{{ $draft ? 'Çalışma taslağını düzenle' : ($content ? 'Taslağı düzenle' : 'Yeni '.mb_strtolower($kind->label())) }}</h1>
            @if ($draft)<p class="small muted" style="margin:6px 0 0">Yayındaki metin değişmez; taslak akıştan geçip yayınlanınca birleşir.</p>@endif
        </div>
    </div>

    <form method="POST" action="{{ $draft ? route('panel.content.draft.update', $content) : ($content ? route('panel.content.update', $content) : route('panel.content.store')) }}"
          class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        @csrf
        @if ($content) @method('PUT') @else <input type="hidden" name="kind" value="{{ $kind->value }}"><input type="hidden" name="website_id" value="{{ $website->id }}"> @endif

        <div class="panel stack" style="gap:14px">
            <label class="field">
                <span class="label">Başlık</span>
                <input class="control" type="text" name="title" value="{{ old('title', $src?->title) }}" required minlength="3" maxlength="190" autofocus
                       @error('title') aria-invalid="true" @enderror>
            </label>

            <label class="field">
                <span class="label">Slug (boş bırakılırsa başlıktan üretilir)</span>
                <input class="control mono" type="text" name="slug" value="{{ old('slug', $src?->slug) }}" maxlength="190" pattern="[a-z0-9-]*"
                       placeholder="{{ $kind->value === 'post' ? 'blog/ornek-yazi' : 'ornek-sayfa' }}" @error('slug') aria-invalid="true" @enderror>
            </label>

            <label class="field">
                <span class="label">Özet</span>
                <textarea class="control" name="excerpt" maxlength="500" style="min-height:72px" @error('excerpt') aria-invalid="true" @enderror>{{ old('excerpt', $src?->excerpt) }}</textarea>
            </label>

            <label class="field">
                <span class="label">Gövde (Markdown)</span>
                <textarea class="control mono" name="body" style="min-height:420px;font-size:14px;line-height:1.6" @error('body') aria-invalid="true" @enderror>{{ old('body', $src?->body) }}</textarea>
                <span class="small muted">Başlık için <code>## Başlık</code>, liste için <code>- madde</code>, bağlantı için <code>[metin](https://…)</code>. Ham HTML yayında süzülür.</span>
            </label>
        </div>

        <div class="stack" style="gap:20px">
            <div class="panel stack" style="gap:14px">
                <p class="eyebrow" style="margin:0">Yayın</p>
                @if ($kind->value === 'post')
                    <label class="field">
                        <span class="label">Kategori</span>
                        <input class="control" type="text" name="category" value="{{ old('category', $src?->category) }}" maxlength="80" placeholder="Sanal Ofis, Mevzuat, Hibrit Çalışma…">
                    </label>
                @endif
                @if ($draft)
                    <p class="small muted" style="margin:0">Onay bayrağı yayındaki içeriğe aittir{{ $content?->requires_approval ? ' (onay gerekli)' : '' }}.</p>
                @else
                <label class="checkbox-row">
                    <input type="checkbox" name="requires_approval" value="1" @checked(old('requires_approval', $content?->requires_approval)) @disabled($content?->requires_approval)>
                    <span>
                        <strong>Onay gerekli</strong> — yasal, vergi ya da KYC ile ilgili metin. Yayın öncesi ayrıca onaylanmalı.
                        @if ($content?->requires_approval)<span class="muted">(Bir kez işaretlenen geri alınamaz.)</span>@endif
                    </span>
                </label>
                @if ($content?->requires_approval)
                    <input type="hidden" name="requires_approval" value="1">
                @endif
                @endif
            </div>

            <div class="panel stack" style="gap:14px">
                <p class="eyebrow" style="margin:0">SEO</p>
                <label class="field">
                    <span class="label">Meta başlık (≤ 70)</span>
                    <input class="control" type="text" name="meta_title" value="{{ old('meta_title', $src?->meta_title) }}" maxlength="70">
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="noindex" value="1" @checked(old('noindex', $src?->noindex))>
                    <span><strong>noindex</strong> — arama motorları bu sayfayı listelemez (teşekkür sayfası, kampanya kopyası vb.).</span>
                </label>
                <label class="field">
                    <span class="label">Meta açıklama (≤ 160)</span>
                    <textarea class="control" name="meta_description" maxlength="160" style="min-height:64px">{{ old('meta_description', $src?->meta_description) }}</textarea>
                </label>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn--brand">{{ $content ? 'Kaydet' : 'Taslak oluştur' }}</button>
                <a href="{{ $content ? route('panel.content.show', $content) : route('panel.content.index') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </div>
    </form>
@endsection
