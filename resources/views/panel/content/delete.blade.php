@extends('layouts.panel')

@section('title', 'Silme onayı')

{{-- Silmeden önce yönlendirme (faz 54): içerik / hizmet / lokasyon için ortak. $suggestions RedirectService::suggest;
     seçim redirect_mode: suggest (önerilen hedef) · custom (farklı URL) · none (yönlendirme oluşturma). --}}
@section('content')
    @php($best = $suggestions[0] ?? null)
    <div class="panel-head">
        <div>
            <p class="eyebrow">Silme onayı</p>
            <h1 class="h2">{{ $label }}</h1>
            <p class="body-muted" style="margin:6px 0 0">Adres: <span class="mono">{{ $path }}</span>@if ($wasLive) · yayında/yayınlanmıştı — eski bağlantılar boşa düşmesin diye yönlendirme seçin.@else · hiç yayınlanmadı; yönlendirme çoğunlukla gereksizdir.@endif</p>
        </div>
    </div>

    @if ($errors->any())<div class="alert alert--danger" role="alert">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ $action }}" class="panel" style="max-width:760px" data-delete-redirect>
        @csrf @method('DELETE')
        <p class="label" style="margin-bottom:12px">Bu URL için yönlendirme oluşturulsun mu?</p>

        <div class="stack" style="gap:10px">
            @if ($best !== null)
                <label class="checkbox-row" style="align-items:flex-start">
                    <input type="radio" name="redirect_mode" value="suggest" checked>
                    <span><strong>Yönlendir</strong> → <span class="mono">{{ $best['path'] }}</span> <span class="badge {{ $best['score'] >= 85 ? 'badge--ok' : ($best['score'] >= 60 ? 'badge--warn' : '') }}">%{{ $best['score'] }} benzer</span><br><span class="small muted">{{ $best['title'] }} · {{ implode(' · ', $best['reasons']) }}</span></span>
                </label>
                <input type="hidden" name="redirect_to" value="{{ $best['path'] }}">
            @endif
            <label class="checkbox-row" style="align-items:flex-start">
                <input type="radio" name="redirect_mode" value="custom" @checked($best === null)>
                <span><strong>Farklı URL seç</strong><br><input class="control mono" type="text" name="redirect_custom" value="{{ old('redirect_custom') }}" placeholder="/blog/yeni-yazi ya da https://…" style="margin-top:6px;max-width:420px"></span>
            </label>
            <label class="checkbox-row" style="align-items:flex-start">
                <input type="radio" name="redirect_mode" value="none">
                <span><strong>Yönlendirme oluşturma</strong><br><span class="small muted">Adres URL geçmişine yazılır; sonradan gelen 404'lerde sistem benzer içerik arar ve öneri üretir.</span></span>
            </label>
        </div>

        @if (count($suggestions) > 1)
            <details style="margin-top:16px">
                <summary class="small" style="cursor:pointer;font-weight:600">Diğer benzer içerikler</summary>
                <table class="table" style="margin-top:8px">
                    <thead><tr><th>Skor</th><th>İçerik</th><th>Adres</th><th>Neden</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($suggestions, 1) as $s)
                            <tr><td class="mono">%{{ $s['score'] }}</td><td>{{ $s['title'] }}</td><td class="mono small">{{ $s['path'] }}</td><td class="small muted">{{ implode(' · ', $s['reasons']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="small muted">Birini kullanmak için "Farklı URL seç" alanına adresi yazın.</p>
            </details>
        @endif

        <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
            <button type="submit" class="btn btn--brand" style="background:var(--danger);border-color:var(--danger)">Sil ve seçimi uygula</button>
            <a href="{{ $cancel }}" class="btn btn--ghost">Vazgeç</a>
        </div>
    </form>
@endsection
