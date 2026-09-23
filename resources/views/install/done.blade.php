@extends('install.layout')

@section('title', 'Kurulum tamamlandı')

@section('content')
    <p class="setup-ok">Kurulum tamamlandı ve kurulum adresi kapatıldı.</p>

    @foreach ($notes as $note)
        <p class="setup-warn setup-note">{{ $note }}</p>
    @endforeach

    <h2 class="h4" style="margin-top:18px">Sistem denetimi</h2>
    @foreach ($doctor as $row)
        <div class="setup-row">
            <span>{{ $row['name'] }}</span>
            <span class="{{ $row['level'] === 'ok' ? 'setup-ok' : ($row['level'] === 'warn' ? 'setup-warn' : 'setup-bad') }}">
                {{ $row['level'] === 'ok' ? 'uygun' : ($row['level'] === 'warn' ? 'uyarı' : 'hata') }}
                @if (($row['note'] ?? '') !== '')<span class="setup-note">— {{ $row['note'] }}</span>@endif
            </span>
        </div>
    @endforeach

    <h2 class="h4" style="margin-top:22px">Sırada ne var</h2>
    <ol class="setup-note" style="padding-left:18px;line-height:1.7">
        <li>Panele girin ve iki adımlı doğrulamayı kurun: <a href="{{ url('/panel') }}">{{ url('/panel') }}</a></li>
        <li>Zamanlanmış görev (cron) tanımlayın: <span class="mono">* * * * * cd {{ base_path() }} && php artisan schedule:run</span></li>
        <li>Kuyruk işçisini başlatın (bildirim, webhook teslimi): <span class="mono">php artisan queue:work --tries=5 --max-time=3600</span></li>
        <li>Ayarlar › Header/Footer ekranından KVKK, gizlilik ve çerez sayfalarını seçip yayınlayın.</li>
        <li>İlk yedeği alın: <span class="mono">php artisan ofisvio:backup</span></li>
    </ol>
@endsection
