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

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
                <button type="submit" class="btn btn--brand">{{ $website ? 'Kaydet' : 'Siteyi aç' }}</button>
                <a href="{{ route('panel.websites.index') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </form>
    </div>
@endsection
