@extends('layouts.panel')

@section('title', $website ? 'Düzenle — '.$website->name : 'Yeni site')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.websites.index') }}">Websiteler</a></p>
            <h1 class="h2">{{ $website ? $website->name : 'Yeni site' }}</h1>
        </div>
    </div>

    <div class="panel" style="max-width:560px">
        <form method="POST" action="{{ $website ? route('panel.websites.update', $website) : route('panel.websites.store') }}" class="stack" style="gap:14px">
            @csrf
            @if ($website) @method('PUT') @endif

            <label class="field">
                <span class="label">Site adı</span>
                <input class="control" type="text" name="name" value="{{ old('name', $website?->name) }}" required minlength="2" maxlength="120" @error('name') aria-invalid="true" @enderror>
            </label>

            @unless ($website)
                <label class="field">
                    <span class="label">Slug (boşsa addan üretilir)</span>
                    <input class="control mono" type="text" name="slug" value="{{ old('slug') }}" maxlength="120" pattern="[a-z0-9-]*" @error('slug') aria-invalid="true" @enderror>
                </label>
            @endunless

            <label class="field">
                <span class="label">Alan adı</span>
                <input class="control mono" type="text" name="domain" value="{{ old('domain', $website?->domain) }}" maxlength="253" placeholder="ornek.com" @error('domain') aria-invalid="true" @enderror>
                <span class="small muted">Bu alan adından gelen istekler bu siteyi gösterir. DNS/SSL yönlendirmesi ayrıca yapılır.</span>
            </label>

            @if (! $website?->is_default)
                <label class="field">
                    <span class="label">Organizasyon (müşteri sitesi)</span>
                    <select class="control" name="organization_id" @error('organization_id') aria-invalid="true" @enderror>
                        <option value="">— Ofisvio (organizasyonsuz)</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}" @selected((int) old('organization_id', $website?->organization_id) === $org->id)>{{ $org->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="field">
                <span class="label">Tema</span>
                <select class="control" name="theme" @error('theme') aria-invalid="true" @enderror>
                    @foreach (config('ofisvio.themes') as $key => $label)
                        <option value="{{ $key }}" @selected(old('theme', $website?->theme ?? 'kum') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
                <button type="submit" class="btn btn--brand">{{ $website ? 'Kaydet' : 'Siteyi aç' }}</button>
                <a href="{{ route('panel.websites.index') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </form>
    </div>
    @if ($website)
        <div class="panel" style="max-width:640px;margin-top:20px">
            <p class="eyebrow">Site genel ayarları — iletişim ve kimlik</p>
            <p class="small muted" style="margin:0 0 14px">Vitrinde üst şerit, footer ve iletişim bölümlerinde kullanılır. Boş bırakılan alan{{ $website->is_default ? ' kod varsayılanına (config) düşer' : ' gösterilmez' }}.</p>
            <form method="POST" action="{{ route('panel.websites.settings', $website) }}" class="stack" style="gap:12px">
                @csrf @method('PUT')
                @include('panel.websites.partials.settings-fields', ['site' => $website])
                <div><button type="submit" class="btn btn--brand">Ayarları kaydet</button></div>
            </form>
        </div>
    @endif
    @if ($website && ! $website->is_default)
        <div class="panel" style="max-width:640px;margin-top:20px">
            <p class="eyebrow">Tehlikeli bölge</p>
            @error('name')<p class="small" style="color:var(--danger)">{{ $message }}</p>@enderror
            <form method="POST" action="{{ route('panel.websites.destroy', $website) }}" onsubmit="return confirm('Site silinsin mi? Yalnız içeriksiz site silinir; alan adı boşa çıkar.')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn--ghost" style="color:var(--danger);border-color:#E9C4BC">Siteyi sil</button>
                <span class="small muted" style="margin-left:10px">İçeriği olan site silinemez (önce içerikleri silin).</span>
            </form>
        </div>
    @endif
@endsection
