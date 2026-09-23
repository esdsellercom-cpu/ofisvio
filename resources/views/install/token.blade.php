@extends('install.layout')

@section('title', 'Kurulum anahtarı')

@section('steps')
    @include('install.steps', ['current' => 'token'])
@endsection

@section('content')
    <p>Kurulum ekranını yalnız sunucudaki dosyalara erişebilen kişi açabilir. Sunucuda şu dosyayı oluşturun, içine
        <strong>en az 32 karakterlik</strong> rastgele bir metin yazın ve aynı metni buraya yapıştırın:</p>
    <p class="mono" style="padding:10px 12px;background:rgba(0,0,0,.05);border-radius:8px">storage/app/install/challenge.txt</p>
    <p class="setup-note">Dosyayı hosting panelinizin dosya yöneticisiyle ya da FTP ile oluşturabilirsiniz;
        <span class="mono">storage/app/install</span> klasörü yoksa siz açın. Dosyayı oluşturduktan sonra bu adımı 1 saat
        içinde tamamlayın; süre dolarsa dosyayı yeniden kaydetmeniz yeterli.</p>

    <form method="POST" action="{{ route('install.verify') }}">
        @csrf
        <label class="setup-field">
            <span>Kurulum anahtarı</span>
            <input type="password" name="token" autocomplete="off" autofocus required minlength="32" maxlength="255">
        </label>
        <div class="setup-actions">
            <button type="submit" class="btn btn--brand btn--pill">Doğrula</button>
        </div>
    </form>
@endsection
