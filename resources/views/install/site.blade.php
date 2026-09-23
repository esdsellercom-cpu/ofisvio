@extends('install.layout')

@section('title', 'Site ve e-posta')

@section('steps')
    @include('install.steps', ['current' => 'site'])
@endsection

@section('content')
    <form method="POST" action="{{ route('install.site.store') }}">
        @csrf
        <label class="setup-field"><span>Site adresi</span><input type="url" name="url" value="{{ old('url', $suggestedUrl) }}" required maxlength="190"></label>
        <p class="setup-note" style="margin:-8px 0 14px">https:// ile başlamalıdır; SSL sertifikanızı kurulumdan önce etkinleştirin.</p>

        <label class="setup-field"><span>Saat dilimi</span><input type="text" name="timezone" value="{{ old('timezone', 'Europe/Istanbul') }}" required maxlength="60"></label>

        <label class="setup-field"><span>Ters proxy / CDN (isteğe bağlı)</span><input type="text" name="trusted_proxies" value="{{ old('trusted_proxies') }}" maxlength="190" placeholder="*"></label>
        <p class="setup-note" style="margin:-8px 0 14px">Cloudflare ya da nginx arkasındaysanız <span class="mono">*</span> yazın; yoksa boş bırakın.</p>

        <label class="setup-field"><span>Kurulum kimliği (isteğe bağlı)</span><input type="text" name="installation_id" value="{{ old('installation_id') }}" maxlength="60" pattern="[A-Za-z0-9_-]+"></label>

        <label class="setup-field">
            <span>Belge taraması (KYC)</span>
            <select name="kyc_scanner">
                <option value="disabled" @selected(old("kyc_scanner", "disabled") === "disabled")>Sunucumda ClamAV yok — belge yükleme kapalı kalsın</option>
                <option value="clamav" @selected(old("kyc_scanner") === "clamav")>ClamAV kurulu (önerilir)</option>
            </select>
        </label>
        <p class="setup-note" style="margin:-8px 0 14px">Kimlik/şirket belgeleri virüs taramasından geçmeden kabul edilmez.
            ClamAV yoksa sistem çalışır, yalnız belge yükleme ekranı reddeder; sonradan kurunca bu ayarı değiştirebilirsiniz.</p>
        <label class="setup-field"><span>ClamAV adresi</span><input type="text" name="clamav_address" value="{{ old("clamav_address", "tcp://127.0.0.1:3310") }}" maxlength="120"></label>

        <label class="setup-field">
            <span>E-posta gönderimi</span>
            <select name="mail_mailer">
                <option value="smtp" @selected(old('mail_mailer', 'smtp') === 'smtp')>SMTP (önerilir — davet ve şifre sıfırlama e-postaları)</option>
                <option value="log" @selected(old('mail_mailer') === 'log')>Şimdilik kapalı (panelden sonra ayarlanır)</option>
            </select>
        </label>
        <label class="setup-field"><span>SMTP sunucusu</span><input type="text" name="mail_host" value="{{ old('mail_host') }}" maxlength="190"></label>
        <label class="setup-field"><span>SMTP portu</span><input type="text" name="mail_port" value="{{ old('mail_port', '587') }}" inputmode="numeric" maxlength="5"></label>
        <label class="setup-field"><span>SMTP kullanıcı</span><input type="text" name="mail_username" value="{{ old('mail_username') }}" maxlength="190"></label>
        <label class="setup-field"><span>SMTP şifre</span><input type="password" name="mail_password" autocomplete="off" maxlength="190"></label>
        <label class="setup-field"><span>Gönderen adresi</span><input type="email" name="mail_from" value="{{ old('mail_from') }}" maxlength="190"></label>

        <div class="setup-actions">
            <button type="submit" class="btn btn--brand btn--pill">Kaydet ve devam</button>
        </div>
    </form>
@endsection
