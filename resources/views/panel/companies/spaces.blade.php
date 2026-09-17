@extends('layouts.panel')

@section('title', 'Alanlar — '.$company->legal_name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a> / alanlar</p>
            <h1 class="h2">Masalar &amp; ofisler</h1>
            <p>Şirketinize tahsisli masa ve ofisler. Değişiklik için Ofisvio ekibiyle iletişime geçin.</p>
        </div>
    </div>

    <div class="card">
        @if ($assignments->isEmpty())
            <div class="empty-state" style="border:0">Henüz tahsisli alan yok.</div>
        @else
            <div class="tw">
                <table class="t">
                    <thead><tr><th>Alan</th><th>Lokasyon</th><th>Üye</th><th>Dönem</th><th>Durum</th></tr></thead>
                    <tbody>
                        @foreach ($assignments as $a)
                            <tr>
                                <td><b>{{ $a->space->name }}</b> <span class="tag">{{ $a->space->kindLabel() }}</span>{{ $a->space->floor ? ' · Kat '.$a->space->floor : '' }}</td>
                                <td>{{ $a->space->location->name }}</td>
                                <td class="small">{{ $a->user?->name ?? '—' }}</td>
                                <td class="mono small">{{ $a->starts_on->format('d.m.Y') }} – {{ $a->ends_on?->format('d.m.Y') ?? 'süresiz' }}</td>
                                <td>@if ($a->isActive())<span class="pill g">Aktif</span>@else<span class="pill n">Sona erdi</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
