{{-- Adım şeridi: yalnız görsel yön; gerçek kapı sunucudadır (EnsureInstallable + durum dosyası). --}}
<ol class="setup-steps">
    @foreach (['token' => 'Anahtar', 'requirements' => 'Gereksinimler', 'database' => 'Veritabanı', 'site' => 'Site', 'setup' => 'Kurulum', 'admin' => 'Yönetici', 'finish' => 'Bitir'] as $key => $label)
        <li @if ($key === ($current ?? '')) aria-current="step" @endif>{{ $label }}</li>
    @endforeach
</ol>
