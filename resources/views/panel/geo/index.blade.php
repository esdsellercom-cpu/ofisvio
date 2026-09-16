@extends('layouts.panel')

@section('title', 'GEO')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">GEO / Entity · v1</p>
            <h1 class="h2">Lokasyon varlıkları</h1>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
        Her şube arama motorlarına <code>LocalBusiness</code> olarak tanıtılır; koordinat, telefon ve çalışma saatleri
        eksikse şema o alan olmadan basılır (uydurma değer yok) ve burada bulgu olarak görünür. Organizasyon
        kimliği (<code>sameAs</code>) tüm siteyi etkiler — değişikliği JIT ister.
    </p>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Şube</th><th>Şehir</th><th>Koordinat</th><th>Telefon</th><th>Saatler</th><th>Bulgu</th><th></th></tr></thead>
            <tbody>
                @foreach ($locations as $loc)
                    <tr>
                        <td>{{ $loc->name }}<a href="{{ $website->baseUrl().$loc->path() }}" target="_blank" rel="noopener" class="small mono muted" style="display:block;font-weight:400">{{ $loc->path() }} ↗</a></td>
                        <td>{{ $loc->city }}</td>
                        <td class="mono small">{{ $loc->hasCoordinates() ? $loc->latitude.', '.$loc->longitude : '—' }}</td>
                        <td class="mono small">{{ $loc->phone ?: '—' }}</td>
                        <td class="small">{{ empty($loc->opening_hours) ? '—' : implode(' · ', $loc->opening_hours) }}</td>
                        <td>
                            @if (isset($issues[$loc->id]))
                                <span class="badge badge--warn" title="{{ implode(' ', $issues[$loc->id]) }}">{{ count($issues[$loc->id]) }}</span>
                            @else
                                <span class="badge badge--ok">Tam</span>
                            @endif
                        </td>
                        <td>
                            <div class="row-actions">
                                @if ($canEdit)
                                    <a href="{{ route('panel.geo.edit', $loc) }}" class="btn btn--ghost btn--pill">Düzenle</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel" style="margin-top:20px">
        <p class="eyebrow">Organizasyon varlığı — {{ $website->name }}</p>
        @if ($grant)
            <form method="POST" action="{{ route('panel.geo.entity', $website) }}" class="stack" style="gap:14px;max-width:560px">
                @csrf @method('PUT')
                <label class="field"><span class="label">Yasal unvan (legalName)</span>
                    <input class="control" type="text" name="legal_name" value="{{ old('legal_name', $website->legal_name) }}" maxlength="190">
                </label>
                <label class="field"><span class="label">sameAs — her satıra bir profil adresi (https://…)</span>
                    <textarea class="control mono" name="same_as" style="min-height:96px" placeholder="https://www.linkedin.com/company/…&#10;https://www.instagram.com/…">{{ old('same_as', implode("\n", $website->same_as ?? [])) }}</textarea>
                </label>
                <div><button type="submit" class="btn btn--brand">Varlığı kaydet</button></div>
            </form>
        @else
            <dl class="dl">
                <dt>legalName</dt><dd>{{ $website->legal_name ?: '—' }}</dd>
                <dt>sameAs</dt><dd class="mono small">{{ empty($website->same_as) ? '—' : implode(' · ', $website->same_as) }}</dd>
            </dl>
            @if ($canRequestJit)
                <details style="margin-top:14px">
                    <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Varlığı değiştirmek için JIT erişimi iste</summary>
                    <form method="POST" action="{{ route('panel.geo.jit', $website) }}" class="stack" style="gap:10px;margin-top:10px;max-width:480px">
                        @csrf
                        <label class="field"><span class="label">Gerekçe</span>
                            <textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:64px"></textarea>
                        </label>
                        <div class="inline-form">
                            <label class="field" style="flex:0 1 140px"><span class="label">Süre (dk)</span>
                                <input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required>
                            </label>
                            <button type="submit" class="btn btn--brand">Erişim aç</button>
                        </div>
                    </form>
                </details>
            @endif
        @endif
    </div>
@endsection
