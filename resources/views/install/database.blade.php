@extends('install.layout')

@section('title', 'Veritabanı')

@section('steps')
    @include('install.steps', ['current' => 'database'])
@endsection

@section('content')
    <p>Hosting panelinizde bir veritabanı ve kullanıcı oluşturun, kullanıcıya bu veritabanında tüm yetkileri verin.
        Bilgiler önce denenir; yalnız bağlantı kurulabilirse kaydedilir.</p>

    <form method="POST" action="{{ route('install.database.store') }}">
        @csrf
        <label class="setup-field">
            <span>Sürücü</span>
            <select name="connection">
                <option value="mysql" @selected(old('connection', 'mysql') === 'mysql')>MySQL / MariaDB</option>
                <option value="pgsql" @selected(old('connection') === 'pgsql')>PostgreSQL</option>
                <option value="sqlite" @selected(old('connection') === 'sqlite')>SQLite (yalnız deneme kurulumu)</option>
            </select>
        </label>
        <label class="setup-field"><span>Sunucu</span><input type="text" name="host" value="{{ old('host', '127.0.0.1') }}" maxlength="190"></label>
        <label class="setup-field"><span>Port</span><input type="text" name="port" value="{{ old('port', '3306') }}" inputmode="numeric" maxlength="5"></label>
        <label class="setup-field"><span>Veritabanı adı</span><input type="text" name="database" value="{{ old('database') }}" required maxlength="190"></label>
        <label class="setup-field"><span>Kullanıcı</span><input type="text" name="username" value="{{ old('username') }}" maxlength="190"></label>
        <label class="setup-field"><span>Şifre</span><input type="password" name="password" autocomplete="off" maxlength="190"></label>
        <div class="setup-actions">
            <button type="submit" class="btn btn--brand btn--pill">Bağlantıyı dene ve kaydet</button>
        </div>
    </form>
@endsection
