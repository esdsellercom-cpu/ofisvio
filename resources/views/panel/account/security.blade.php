@extends('layouts.panel')

@section('title', 'Güvenlik')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.account') }}">Hesap</a></p>
            <h1 class="h2">İki adımlı doğrulama</h1>
        </div>
        <div class="panel-head__actions">
            @if ($confirmed)
                <span class="badge badge--ok">Etkin</span>
            @elseif ($enabled)
                <span class="badge badge--warn">Doğrulama bekliyor</span>
            @else
                <span class="badge badge--muted">Kapalı</span>
            @endif
        </div>
    </div>

    @if (! $enabled)
        {{-- 1) Kapalı: etkinleştir --}}
        <div class="panel" style="max-width:560px">
            <p class="body-muted" style="margin:0 0 18px">
                Girişte şifrenizin yanında telefonunuzdaki doğrulayıcı uygulamadan (Google Authenticator, 1Password,
                Microsoft Authenticator…) 6 haneli bir kod istenir. Uygulamaya erişimi kaybederseniz kurtarma kodlarıyla girersiniz.
            </p>
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <button type="submit" class="btn btn--brand">2FA'yı etkinleştir</button>
            </form>
        </div>
    @elseif (! $confirmed)
        {{-- 2) Kuruldu, doğrulanmadı: QR + kod --}}
        <div class="grid-auto" style="--min:300px;--gap:20px;align-items:start">
            <div class="panel">
                <p class="eyebrow">1 · Uygulamaya ekleyin</p>
                <p class="body-muted" style="margin:0 0 16px">Doğrulayıcı uygulamanızla kodu okutun ya da anahtarı elle girin. <strong>Bu yeni bir anahtardır:</strong> uygulamanızda daha önceki bir Ofisvio kaydı varsa onu silin; eski kayıt geçersizdir.</p>
                <div style="background:#fff;border:1px solid var(--line);border-radius:var(--r-md);padding:16px;display:inline-block">{!! $qrCode !!}</div>
                <p class="small muted" style="margin:14px 0 4px">Anahtar</p>
                <code class="mono" style="font-size:14px;letter-spacing:.08em;overflow-wrap:anywhere">{{ $secretKey }}</code>
            </div>
            <div class="panel">
                <p class="eyebrow">2 · Doğrulayın</p>
                <p class="body-muted" style="margin:0 0 16px">Uygulamanın ürettiği 6 haneli kodu girin. Bu adım tamamlanmadan 2FA devrede değildir.</p>
                <form method="POST" action="{{ route('two-factor.confirm') }}" class="stack" style="gap:14px">
                    @csrf
                    <label class="field">
                        <span class="label">Doğrulama kodu</span>
                        <input class="control mono" type="text" name="code" inputmode="numeric" pattern="[0-9 -]*" autocomplete="one-time-code" required autofocus
                               style="letter-spacing:.3em;font-size:20px" @error('code', 'confirmTwoFactorAuthentication') aria-invalid="true" @enderror>
                    </label>
                    @if ($errors->confirmTwoFactorAuthentication->any())
                        <div class="field-error">{{ $errors->confirmTwoFactorAuthentication->first() }}</div>
                    @endif
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn--brand">Doğrula ve etkinleştir</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('two-factor.disable') }}" style="margin-top:12px">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn--ghost">Vazgeç</button>
                </form>
            </div>
        </div>
    @else
        {{-- 3) Etkin: kurtarma kodları + kapatma --}}
        <div class="grid-auto" style="--min:300px;--gap:20px;align-items:start">
            <div class="panel">
                <p class="eyebrow">Kurtarma kodları</p>
                <p class="body-muted" style="margin:0 0 14px">
                    Her kod bir kez kullanılır. Doğrulayıcı uygulamanıza erişemezseniz girişte bunlardan birini kullanın.
                    Güvenli bir yerde saklayın; bu sayfayı yazdırmayın.
                </p>
                <div class="mono" style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px 18px;font-size:14px;background:var(--surface-sunk);border-radius:var(--r-md);padding:14px 16px">
                    @foreach ($recoveryCodes as $code)
                        <span>{{ $code }}</span>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" style="margin-top:14px">
                    @csrf
                    <button type="submit" class="btn btn--ghost">Yeni kodlar üret</button>
                </form>
            </div>
            <div class="panel">
                <p class="eyebrow">Kapatma</p>
                <p class="body-muted" style="margin:0 0 14px">
                    2FA'yı kapatmak hesabınızı yalnızca şifreyle korur. Personel hesaplarında kapatılmamalıdır.
                </p>
                <form method="POST" action="{{ route('two-factor.disable') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn--ghost" style="color:var(--danger);border-color:#E9C4BC">2FA'yı kapat</button>
                </form>
            </div>
        </div>
    @endif
@endsection
