@extends('errors.layout')
@section('code', '404')
@section('title', 'Sayfa bulunamadı')
@section('message', 'Adres yanlış olabilir ya da içerik yayından kaldırılmış olabilir.')
@section('extra')
    {{-- Akıllı 404 (faz 54): benzerlik önerileri yalnız eşik üstü ve yalnız mevcut içerik; rastgele yönlendirme yok. --}}
    @if (! empty($notFoundSuggestions ?? []))
        <div style="margin-top:28px;text-align:left" data-not-found-suggestions>
            <p class="label" style="margin-bottom:10px">Belki aradığınız</p>
            <ul class="stack" style="gap:8px;margin:0;padding:0;list-style:none">
                @foreach ($notFoundSuggestions as $s)
                    <li><a href="{{ $s['path'] }}" style="font-weight:600">{{ $s['title'] }}</a> <span class="small muted mono">{{ $s['path'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
